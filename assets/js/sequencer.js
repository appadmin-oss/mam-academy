/*! MAM Academy — Beat Maker (step sequencer)  ·  window.MAM_SEQ
 * ---------------------------------------------------------------------------
 * A 16-step drum sequencer that runs entirely in the browser. Design notes:
 *
 *  • Zero dependencies. Every drum voice is SYNTHESISED on the Web Audio API
 *    (oscillators + filtered noise), so there are no sample files to host,
 *    decode or cache. It loads instantly, works offline, uses no server
 *    resources, and keeps working even when the Tone.js CDN is blocked.
 *
 *  • Sample-accurate timing via the "Chris Wilson" look-ahead scheduler — the
 *    same pattern the metronome uses: a 25 ms setInterval queues the next
 *    ~120 ms of hits against AudioContext.currentTime, so the groove stays
 *    rock-solid even when the tab is backgrounded. Swing delays the off-16ths.
 *
 *  • The grid is drawn on a single <canvas> (DPR-aware) rather than ~128 DOM
 *    cells, so toggling/animating the playhead never triggers layout reflow.
 *
 *  • The working groove auto-saves to localStorage and restores on return.
 *
 *  • "Export WAV" renders the pattern through an OfflineAudioContext and
 *    encodes a 16-bit PCM WAV on the client — nothing is uploaded.
 *
 * The shared AudioContext is borrowed from MAM_TOOLS when present so the
 * sequencer mixes through the same output as the rest of the studio; if this
 * module loads alone it lazily creates its own (autoplay-policy safe).
 */
(function () {
  'use strict';

  var panel = document.querySelector('.tool-panel[data-tool="seq"]');
  if (!panel) return; // page doesn't include the Beat Maker

  var $ = function (s) { return panel.querySelector(s); };
  var $$ = function (s) { return Array.prototype.slice.call(panel.querySelectorAll(s)); };

  // ── Constants ──────────────────────────────────────────────────────
  var STEPS = 16;
  var ROW = 46;                 // px height of one track row (matches CSS)
  var SAVE_KEY = 'mam.seq.v1';

  // Eight synth voices, ordered top-to-bottom. `vol` is the default mix level.
  var TRACKS = [
    { name: 'Kick',       voice: 'kick',      vol: 0.95 },
    { name: 'Snare',      voice: 'snare',     vol: 0.80 },
    { name: 'Clap',       voice: 'clap',      vol: 0.70 },
    { name: 'Closed Hat', voice: 'hatClosed', vol: 0.55 },
    { name: 'Open Hat',   voice: 'hatOpen',   vol: 0.50 },
    { name: 'Low Tom',    voice: 'tom',       vol: 0.70 },
    { name: 'Rim',        voice: 'rim',       vol: 0.62 },
    { name: 'Shaker',     voice: 'shaker',    vol: 0.50 },
  ];

  // ── Mutable state ──────────────────────────────────────────────────
  var pattern = TRACKS.map(function () { return new Array(STEPS).fill(0); });
  var bpm = 100, swing = 0, masterVol = 0.85;
  var playing = false, timer = null, nextTime = 0, curStep = 0, uiStep = -1;

  // ── Shared AudioContext (autoplay-policy safe) ─────────────────────
  var ctx = null;
  function audio() {
    if (window.MAM_TOOLS && typeof window.MAM_TOOLS.audio === 'function') {
      ctx = window.MAM_TOOLS.audio();
      return ctx;
    }
    if (window.Tone && Tone.context) {
      ctx = Tone.context.rawContext || ctx;
      if (Tone.context.state === 'suspended') Tone.start();
    }
    if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  }

  // ── Output graph: per-track gain → master gain → destination ───────
  var masterGain = null, trackGain = [];
  function ensureGraph(ac) {
    if (masterGain && masterGain.context === ac) return;
    masterGain = ac.createGain();
    masterGain.gain.value = masterVol;
    masterGain.connect(ac.destination);
    trackGain = TRACKS.map(function (t) {
      var g = ac.createGain();
      g.gain.value = t.vol;
      g.connect(masterGain);
      return g;
    });
  }

  // ── Synthesised drum voices ────────────────────────────────────────
  // Each takes (audioContext, outputNode, when). They build their own short
  // envelope and connect to `out`, so the very same functions drive both the
  // live context and the OfflineAudioContext used for WAV export.
  function noiseBuf(ac) {
    if (ac.__mamNoise) return ac.__mamNoise;
    var len = Math.floor(ac.sampleRate * 1.2);
    var b = ac.createBuffer(1, len, ac.sampleRate);
    var d = b.getChannelData(0);
    for (var i = 0; i < len; i++) d[i] = Math.random() * 2 - 1;
    ac.__mamNoise = b;
    return b;
  }
  function env(g, when, peak, decay, hold) {
    g.gain.setValueAtTime(0.0001, when);
    g.gain.exponentialRampToValueAtTime(peak, when + (hold || 0.004));
    g.gain.exponentialRampToValueAtTime(0.0001, when + decay);
  }

  var voices = {
    kick: function (ac, out, when) {
      var o = ac.createOscillator(), g = ac.createGain();
      o.type = 'sine';
      o.frequency.setValueAtTime(160, when);
      o.frequency.exponentialRampToValueAtTime(50, when + 0.11);
      env(g, when, 0.95, 0.40, 0.005);
      o.connect(g).connect(out); o.start(when); o.stop(when + 0.42);
    },
    snare: function (ac, out, when) {
      var s = ac.createBufferSource(); s.buffer = noiseBuf(ac);
      var hp = ac.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 1200;
      var ng = ac.createGain(); env(ng, when, 0.65, 0.18);
      s.connect(hp).connect(ng).connect(out); s.start(when); s.stop(when + 0.2);
      var o = ac.createOscillator(), og = ac.createGain();
      o.type = 'triangle'; o.frequency.value = 180;
      env(og, when, 0.4, 0.1);
      o.connect(og).connect(out); o.start(when); o.stop(when + 0.12);
    },
    clap: function (ac, out, when) {
      var bp = ac.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = 1200; bp.Q.value = 0.9;
      bp.connect(out);
      [0, 0.011, 0.022, 0.04].forEach(function (k, i) {
        var s = ac.createBufferSource(); s.buffer = noiseBuf(ac);
        var g = ac.createGain();
        var peak = i === 3 ? 0.55 : 0.35;
        g.gain.setValueAtTime(0.0001, when + k);
        g.gain.exponentialRampToValueAtTime(peak, when + k + 0.002);
        g.gain.exponentialRampToValueAtTime(0.0001, when + k + (i === 3 ? 0.12 : 0.03));
        s.connect(g).connect(bp); s.start(when + k); s.stop(when + k + 0.14);
      });
    },
    hatClosed: function (ac, out, when) {
      var s = ac.createBufferSource(); s.buffer = noiseBuf(ac);
      var hp = ac.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 7500;
      var g = ac.createGain(); env(g, when, 0.4, 0.045, 0.002);
      s.connect(hp).connect(g).connect(out); s.start(when); s.stop(when + 0.06);
    },
    hatOpen: function (ac, out, when) {
      var s = ac.createBufferSource(); s.buffer = noiseBuf(ac);
      var hp = ac.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 7000;
      var g = ac.createGain(); env(g, when, 0.4, 0.32, 0.002);
      s.connect(hp).connect(g).connect(out); s.start(when); s.stop(when + 0.34);
    },
    tom: function (ac, out, when) {
      var o = ac.createOscillator(), g = ac.createGain();
      o.type = 'sine';
      o.frequency.setValueAtTime(170, when);
      o.frequency.exponentialRampToValueAtTime(90, when + 0.18);
      env(g, when, 0.6, 0.3, 0.005);
      o.connect(g).connect(out); o.start(when); o.stop(when + 0.32);
    },
    rim: function (ac, out, when) {
      var o = ac.createOscillator(), g = ac.createGain();
      o.type = 'square'; o.frequency.value = 1700;
      env(g, when, 0.42, 0.03, 0.002);
      o.connect(g).connect(out); o.start(when); o.stop(when + 0.04);
      var s = ac.createBufferSource(); s.buffer = noiseBuf(ac);
      var hp = ac.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 3000;
      var ng = ac.createGain(); env(ng, when, 0.25, 0.02, 0.001);
      s.connect(hp).connect(ng).connect(out); s.start(when); s.stop(when + 0.03);
    },
    shaker: function (ac, out, when) {
      var s = ac.createBufferSource(); s.buffer = noiseBuf(ac);
      var hp = ac.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 6500;
      var g = ac.createGain();
      g.gain.setValueAtTime(0.0001, when);
      g.gain.exponentialRampToValueAtTime(0.32, when + 0.012);
      g.gain.exponentialRampToValueAtTime(0.0001, when + 0.06);
      s.connect(hp).connect(g).connect(out); s.start(when); s.stop(when + 0.07);
    },
  };

  // ── Presets ────────────────────────────────────────────────────────
  // 'x' = hit, anything else = rest. Voices not listed stay empty.
  function P(str) {
    var a = new Array(STEPS).fill(0);
    for (var i = 0; i < STEPS && i < str.length; i++) a[i] = str[i] === 'x' ? 1 : 0;
    return a;
  }
  var PRESETS = {
    'Four on the floor': {
      kick:      'x...x...x...x...',
      clap:      '....x.......x...',
      hatClosed: 'x.x.x.x.x.x.x.x.',
      hatOpen:   '..x...x...x...x.',
    },
    'Boom bap': {
      kick:      'x.....x..x......',
      snare:     '....x.......x...',
      hatClosed: 'x.x.x.x.x.x.x.x.',
    },
    'Afrobeat': {
      kick:      'x..x..x...x..x..',
      rim:       '..x..x..x..x..x.',
      clap:      '....x.......x...',
      hatClosed: 'x.xx.x.xx.x.xx.x',
      shaker:    'x.x.x.x.x.x.x.x.',
    },
    'Highlife': {
      kick:      'x...x..x.x..x...',
      rim:       '..x.x..x..x.x..x',
      hatClosed: 'x.x.x.x.x.x.x.x.',
      shaker:    '..x...x...x...x.',
    },
    'One drop': {
      kick:      '........x.......',
      snare:     '........x.......',
      rim:       '..x...x...x...x.',
      hatClosed: 'x.x.x.x.x.x.x.x.',
    },
  };
  function voiceIndex(v) {
    for (var i = 0; i < TRACKS.length; i++) if (TRACKS[i].voice === v) return i;
    return -1;
  }
  function loadPreset(name) {
    var p = PRESETS[name]; if (!p) return;
    pattern = TRACKS.map(function () { return new Array(STEPS).fill(0); });
    Object.keys(p).forEach(function (voice) {
      var idx = voiceIndex(voice);
      if (idx >= 0) pattern[idx] = P(p[voice]);
    });
    draw(); save();
    status('Loaded the “' + name + '” groove. Hit play, then make it yours.');
  }

  // ── Canvas grid ────────────────────────────────────────────────────
  var cvs = $('#seqCanvas'), c = cvs.getContext('2d');
  var wrap = $('#seqCanvasWrap');
  var cssW = 0, cssH = TRACKS.length * ROW, cellW = 0;

  function roundRect(ctx2, x, y, w, h, r) {
    if (ctx2.roundRect) { ctx2.beginPath(); ctx2.roundRect(x, y, w, h, r); return; }
    ctx2.beginPath();
    ctx2.moveTo(x + r, y);
    ctx2.arcTo(x + w, y, x + w, y + h, r);
    ctx2.arcTo(x + w, y + h, x, y + h, r);
    ctx2.arcTo(x, y + h, x, y, r);
    ctx2.arcTo(x, y, x + w, y, r);
    ctx2.closePath();
  }

  function layout() {
    var wrapW = wrap.clientWidth || 0;
    if (!wrapW) return; // panel hidden — re-run when it becomes visible
    var minW = STEPS * 30; // keep cells finger-friendly; scroll if narrower
    cssW = Math.max(minW, wrapW);
    cssH = TRACKS.length * ROW;
    var dpr = window.devicePixelRatio || 1;
    cvs.style.width = cssW + 'px';
    cvs.style.height = cssH + 'px';
    cvs.width = Math.round(cssW * dpr);
    cvs.height = Math.round(cssH * dpr);
    c.setTransform(dpr, 0, 0, dpr, 0, 0);
    cellW = cssW / STEPS;
    draw();
  }

  function draw() {
    if (!cellW) return;
    c.clearRect(0, 0, cssW, cssH);
    for (var t = 0; t < TRACKS.length; t++) {
      var y = t * ROW;
      for (var s = 0; s < STEPS; s++) {
        var x = s * cellW;
        var group = Math.floor(s / 4);
        var on = pattern[t][s];
        var isCur = playing && s === uiStep;
        roundRect(c, x + 3, y + 3, cellW - 6, ROW - 6, 6);
        if (on) {
          c.fillStyle = isCur ? '#FFD56B' : (group % 2 ? '#E89A2C' : '#F0A93A');
          c.fill();
          // soft top sheen
          roundRect(c, x + 4, y + 4, cellW - 8, (ROW - 8) * 0.42, 5);
          c.fillStyle = 'rgba(255,255,255,0.22)'; c.fill();
        } else {
          c.fillStyle = isCur ? '#33271699' : (group % 2 ? '#1b140d' : '#221910');
          c.fill();
          c.strokeStyle = 'rgba(255,255,255,0.05)'; c.lineWidth = 1; c.stroke();
        }
      }
    }
    // beat-group separators every 4 steps
    c.strokeStyle = 'rgba(255,255,255,0.12)'; c.lineWidth = 1;
    for (var g = 1; g < STEPS / 4; g++) {
      var gx = Math.round(g * 4 * cellW) + 0.5;
      c.beginPath(); c.moveTo(gx, 0); c.lineTo(gx, cssH); c.stroke();
    }
  }

  // Tap toggles a cell; a horizontal drag is left to the browser so the grid
  // can be scrolled on narrow screens (the wrap is overflow-x:auto and the
  // canvas uses touch-action:pan-x). We therefore toggle on pointerup only
  // when the pointer barely moved, and bail on pointercancel (a scroll gesture).
  var down = null;
  cvs.addEventListener('pointerdown', function (e) { down = { x: e.clientX, y: e.clientY }; });
  cvs.addEventListener('pointercancel', function () { down = null; });
  cvs.addEventListener('pointerup', function (e) {
    if (!down) return;
    var moved = Math.abs(e.clientX - down.x) + Math.abs(e.clientY - down.y);
    down = null;
    if (moved > 10) return; // it was a scroll/drag, not a tap
    var r = cvs.getBoundingClientRect();
    var s = Math.floor((e.clientX - r.left) / cellW);
    var t = Math.floor((e.clientY - r.top) / ROW);
    if (s < 0 || s >= STEPS || t < 0 || t >= TRACKS.length) return;
    pattern[t][s] = pattern[t][s] ? 0 : 1;
    if (pattern[t][s]) { // audition the voice on enable
      var a = audio(); ensureGraph(a);
      voices[TRACKS[t].voice](a, trackGain[t], a.currentTime + 0.01);
    }
    draw(); save();
  });

  // ── Track controls (mute / solo / per-track volume) ────────────────
  function buildTracks() {
    var host = $('#seqTracks');
    host.innerHTML = '';
    TRACKS.forEach(function (t, i) {
      var row = document.createElement('div');
      row.className = 'seq-track-row';
      row.innerHTML =
        '<span class="seq-track-name">' + t.name + '</span>' +
        '<button type="button" class="seq-chip seq-mute" data-i="' + i + '" title="Mute" aria-pressed="false">M</button>' +
        '<button type="button" class="seq-chip seq-solo" data-i="' + i + '" title="Solo" aria-pressed="false">S</button>' +
        '<input type="range" class="seq-tvol" data-i="' + i + '" min="0" max="100" value="' + Math.round(t.vol * 100) + '" title="' + t.name + ' volume" aria-label="' + t.name + ' volume">';
      host.appendChild(row);
    });
    host.addEventListener('click', function (e) {
      var b = e.target.closest('.seq-chip'); if (!b) return;
      var i = +b.getAttribute('data-i');
      if (b.classList.contains('seq-mute')) {
        TRACKS[i].mute = !TRACKS[i].mute;
        b.classList.toggle('on', TRACKS[i].mute);
        b.setAttribute('aria-pressed', TRACKS[i].mute ? 'true' : 'false');
      } else {
        TRACKS[i].solo = !TRACKS[i].solo;
        b.classList.toggle('on', TRACKS[i].solo);
        b.setAttribute('aria-pressed', TRACKS[i].solo ? 'true' : 'false');
      }
      save();
    });
    host.addEventListener('input', function (e) {
      var sl = e.target.closest('.seq-tvol'); if (!sl) return;
      var i = +sl.getAttribute('data-i');
      TRACKS[i].vol = (+sl.value) / 100;
      if (trackGain[i]) trackGain[i].gain.value = TRACKS[i].vol;
      save();
    });
  }
  function syncTrackChips() {
    $$('.seq-mute').forEach(function (b) {
      var on = !!TRACKS[+b.getAttribute('data-i')].mute;
      b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    $$('.seq-solo').forEach(function (b) {
      var on = !!TRACKS[+b.getAttribute('data-i')].solo;
      b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    $$('.seq-tvol').forEach(function (sl) {
      sl.value = Math.round(TRACKS[+sl.getAttribute('data-i')].vol * 100);
    });
  }

  // ── Transport / scheduler ──────────────────────────────────────────
  function stepDur() { return 60 / bpm / 4; } // 16th notes

  function scheduleStep(step, time, sd) {
    var swung = time + (step % 2 ? swing * sd : 0);
    var anySolo = TRACKS.some(function (t) { return t.solo; });
    for (var t = 0; t < TRACKS.length; t++) {
      var aud = anySolo ? !!TRACKS[t].solo : !TRACKS[t].mute;
      if (aud && pattern[t][step]) voices[TRACKS[t].voice](ctx, trackGain[t], swung);
    }
    var delay = Math.max(0, (time - ctx.currentTime) * 1000);
    (function (st) { setTimeout(function () { if (playing) { uiStep = st; draw(); } }, delay); })(step);
  }
  function scheduler() {
    var sd = stepDur();
    while (nextTime < ctx.currentTime + 0.12) {
      scheduleStep(curStep, nextTime, sd);
      nextTime += sd;
      curStep = (curStep + 1) % STEPS;
    }
  }
  function play() {
    var a = audio(); ensureGraph(a);
    playing = true; curStep = 0; uiStep = -1;
    nextTime = a.currentTime + 0.08;
    timer = setInterval(scheduler, 25);
    paintPlay();
  }
  function stop() {
    playing = false;
    if (timer) clearInterval(timer);
    timer = null; uiStep = -1;
    paintPlay(); draw();
  }
  function paintPlay() {
    var btn = $('#seqPlay');
    btn.classList.toggle('playing', playing);
    btn.querySelector('span').textContent = playing ? 'Stop' : 'Play';
    btn.querySelector('i').className = playing ? 'fa-solid fa-stop' : 'fa-solid fa-play';
  }
  $('#seqPlay').addEventListener('click', function () { playing ? stop() : play(); });

  // tempo / swing / master
  $('#seqBpm').addEventListener('input', function (e) {
    bpm = parseInt(e.target.value, 10) || 100;
    $('#seqBpmVal').textContent = bpm;
    save();
  });
  $('#seqSwing').addEventListener('input', function (e) {
    swing = (parseInt(e.target.value, 10) || 0) / 100;
    $('#seqSwingVal').textContent = Math.round(swing * 100) + '%';
    save();
  });
  $('#seqMaster').addEventListener('input', function (e) {
    masterVol = (parseInt(e.target.value, 10) || 0) / 100;
    $('#seqMasterVal').textContent = Math.round(masterVol * 100) + '%';
    if (masterGain) masterGain.gain.value = masterVol;
    save();
  });

  // ── Toolbar actions ────────────────────────────────────────────────
  $('#seqClear').addEventListener('click', function () {
    pattern = TRACKS.map(function () { return new Array(STEPS).fill(0); });
    draw(); save(); status('Cleared. A blank canvas — start tapping cells.');
  });
  $('#seqRandom').addEventListener('click', function () {
    // Per-voice probabilities that tend to land on a usable groove.
    var prob = { kick: 0.28, snare: 0.12, clap: 0.12, hatClosed: 0.55, hatOpen: 0.12, tom: 0.1, rim: 0.18, shaker: 0.4 };
    TRACKS.forEach(function (t, i) {
      var p = prob[t.voice] || 0.2;
      for (var s = 0; s < STEPS; s++) pattern[i][s] = Math.random() < p ? 1 : 0;
    });
    // Anchor the kick on beat 1 so it always feels intentional.
    var ki = voiceIndex('kick'); if (ki >= 0) pattern[ki][0] = 1;
    draw(); save(); status('Rolled a fresh groove. Not feeling it? Roll again.');
  });
  $('#seqPresets').addEventListener('change', function (e) {
    if (e.target.value) { loadPreset(e.target.value); e.target.value = ''; }
  });
  $('#seqExport').addEventListener('click', exportWav);

  // ── WAV export (offline render → 16-bit PCM) ───────────────────────
  function exportWav() {
    var bars = 2, sd = stepDur();
    var totalSteps = bars * STEPS;
    var tail = 0.6;
    var sr = (ctx && ctx.sampleRate) || (audio().sampleRate) || 44100;
    var dur = totalSteps * sd + tail;
    var OAC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    if (!OAC) { status('Your browser can’t render WAV files offline — try the latest Chrome, Edge or Safari.'); return; }
    status('Rendering your beat…');

    var off = new OAC(2, Math.ceil(dur * sr), sr);
    var master = off.createGain(); master.gain.value = masterVol; master.connect(off.destination);
    var anySolo = TRACKS.some(function (t) { return t.solo; });
    TRACKS.forEach(function (t, i) {
      var aud = anySolo ? !!t.solo : !t.mute;
      if (!aud) return;
      var tg = off.createGain(); tg.gain.value = t.vol; tg.connect(master);
      for (var b = 0; b < bars; b++) {
        for (var s = 0; s < STEPS; s++) {
          if (!pattern[i][s]) continue;
          var gridT = (b * STEPS + s) * sd;
          var when = gridT + (s % 2 ? swing * sd : 0);
          voices[t.voice](off, tg, when);
        }
      }
    });

    off.startRendering().then(function (buf) {
      var blob = new Blob([encodeWav(buf)], { type: 'audio/wav' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url; a.download = 'mam-beat-' + bpm + 'bpm.wav';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
      status('Saved “mam-beat-' + bpm + 'bpm.wav” — a 2-bar loop, straight from your browser.');
    }).catch(function (e) {
      status('Couldn’t render the file: ' + (e && e.message || e));
    });
  }
  function encodeWav(audioBuffer) {
    var numCh = Math.min(2, audioBuffer.numberOfChannels);
    var len = audioBuffer.length, sr = audioBuffer.sampleRate;
    var blockAlign = numCh * 2;
    var dataLen = len * blockAlign;
    var ab = new ArrayBuffer(44 + dataLen);
    var view = new DataView(ab);
    function str(off, s) { for (var i = 0; i < s.length; i++) view.setUint8(off + i, s.charCodeAt(i)); }
    str(0, 'RIFF'); view.setUint32(4, 36 + dataLen, true); str(8, 'WAVE');
    str(12, 'fmt '); view.setUint32(16, 16, true); view.setUint16(20, 1, true);
    view.setUint16(22, numCh, true); view.setUint32(24, sr, true);
    view.setUint32(28, sr * blockAlign, true); view.setUint16(32, blockAlign, true);
    view.setUint16(34, 16, true);
    str(36, 'data'); view.setUint32(40, dataLen, true);
    var chans = [];
    for (var ch = 0; ch < numCh; ch++) chans.push(audioBuffer.getChannelData(ch));
    var offset = 44;
    for (var i = 0; i < len; i++) {
      for (var ch2 = 0; ch2 < numCh; ch2++) {
        var v = Math.max(-1, Math.min(1, chans[ch2][i]));
        view.setInt16(offset, v < 0 ? v * 0x8000 : v * 0x7FFF, true);
        offset += 2;
      }
    }
    return view;
  }

  // ── Persistence ────────────────────────────────────────────────────
  function save() {
    try {
      localStorage.setItem(SAVE_KEY, JSON.stringify({
        bpm: bpm, swing: swing, master: masterVol,
        tracks: TRACKS.map(function (t) { return { vol: t.vol, mute: !!t.mute, solo: !!t.solo }; }),
        pattern: pattern,
      }));
    } catch (e) {}
  }
  function restore() {
    var raw;
    try { raw = localStorage.getItem(SAVE_KEY); } catch (e) { raw = null; }
    if (!raw) return false;
    try {
      var d = JSON.parse(raw);
      if (typeof d.bpm === 'number') bpm = d.bpm;
      if (typeof d.swing === 'number') swing = d.swing;
      if (typeof d.master === 'number') masterVol = d.master;
      if (Array.isArray(d.tracks)) d.tracks.forEach(function (s, i) {
        if (!TRACKS[i] || !s) return;
        if (typeof s.vol === 'number') TRACKS[i].vol = s.vol;
        TRACKS[i].mute = !!s.mute; TRACKS[i].solo = !!s.solo;
      });
      if (Array.isArray(d.pattern)) d.pattern.forEach(function (rowArr, i) {
        if (pattern[i] && Array.isArray(rowArr)) for (var s = 0; s < STEPS; s++) pattern[i][s] = rowArr[s] ? 1 : 0;
      });
      return true;
    } catch (e) { return false; }
  }

  function status(msg) { var el = $('#seqStatus'); if (el) el.textContent = msg; }

  // ── Tab integration ────────────────────────────────────────────────
  // Stop the sequencer when the user navigates to another tool; (re)lay out
  // the canvas when the Beat Maker panel becomes visible (it has zero width
  // while display:none, so we can't size it until it's shown).
  Array.prototype.slice.call(document.querySelectorAll('.tools-tabs button')).forEach(function (b) {
    b.addEventListener('click', function () {
      var key = b.getAttribute('data-tool');
      if (key !== 'seq') { if (playing) stop(); }
      else { setTimeout(function () { layout(); }, 30); }
    });
  });
  // Spacebar toggles play while the Beat Maker is the active panel.
  document.addEventListener('keydown', function (e) {
    if (e.code !== 'Space') return;
    var active = document.querySelector('.tool-panel.active');
    if (!active || active.getAttribute('data-tool') !== 'seq') return;
    var tag = document.activeElement && document.activeElement.tagName;
    if (tag === 'INPUT' || tag === 'SELECT' || tag === 'BUTTON') return;
    e.preventDefault();
    playing ? stop() : play();
  });

  window.addEventListener('resize', function () { if (panel.classList.contains('active') || wrap.clientWidth) layout(); });

  // ── Init ───────────────────────────────────────────────────────────
  buildTracks();
  if (!restore()) loadPreset('Afrobeat'); // a welcoming default groove
  // reflect restored/initial transport values into the controls
  $('#seqBpm').value = bpm; $('#seqBpmVal').textContent = bpm;
  $('#seqSwing').value = Math.round(swing * 100); $('#seqSwingVal').textContent = Math.round(swing * 100) + '%';
  $('#seqMaster').value = Math.round(masterVol * 100); $('#seqMasterVal').textContent = Math.round(masterVol * 100) + '%';
  syncTrackChips();
  layout();
  // If the panel starts hidden, lay out once it first gains size.
  if (!wrap.clientWidth && 'ResizeObserver' in window) {
    var ro = new ResizeObserver(function () { if (wrap.clientWidth) { layout(); ro.disconnect(); } });
    ro.observe(wrap);
  }

  window.MAM_SEQ = { play: play, stop: stop, exportWav: exportWav, state: function () { return { bpm: bpm, swing: swing, pattern: pattern }; } };
})();
