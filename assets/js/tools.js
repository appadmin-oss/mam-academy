/*! MAM Practice Tools — metronome, chromatic tuner, Pomodoro, ear training.
 *
 * All four share a single Web Audio AudioContext (lazy-created on the user's
 * first interaction so we don't violate autoplay policies). State is kept
 * in module-local closures; nothing pollutes the global namespace except a
 * lightweight `MAM_TOOLS` object for debugging.
 *
 * The Pomodoro log persists to localStorage (today only); everything else
 * is in-memory. No network calls.
 */
(function () {
  'use strict';
  var $ = function (s, root) { return (root || document).querySelector(s); };
  var $$ = function (s, root) { return Array.prototype.slice.call((root || document).querySelectorAll(s)); };

  // ── Shared AudioContext (autoplay-policy safe) ─────────────────────
  // We let Tone.js own the AudioContext when it's available so its Transport
  // scheduling stays in lockstep with our raw WebAudio paths. Tone exposes
  // its underlying context via Tone.getContext().rawContext — same instance
  // we'd otherwise create. If Tone isn't loaded (CDN blocked, slow network),
  // we fall back to a plain AudioContext so nothing is gated on the library.
  var ctx = null;
  function audio() {
    // Use the shared studio context (window.MAM) so every tool — metronome,
    // tuner, ear-trainer, beat maker — runs on one engine.
    if (window.MAM && typeof window.MAM.audio === 'function') { ctx = window.MAM.audio(); return ctx; }
    if (window.Tone && Tone.context) {
      ctx = Tone.context.rawContext || Tone.getContext().rawContext || ctx;
      if (Tone.context.state === 'suspended') Tone.start();
    }
    if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  }
  function hasTone()   { return typeof window.Tone !== 'undefined'; }
  function hasPitchy() { return !!window.MAM_PITCHY; }

  // ── Tab switching ──────────────────────────────────────────────────
  var toolTabs = $$('.tools-tabs button');
  function setRoving(active) { toolTabs.forEach(function (t) { t.tabIndex = (t === active ? 0 : -1); }); }
  toolTabs.forEach(function (b) {
    b.addEventListener('click', function () {
      var key = b.getAttribute('data-tool');
      toolTabs.forEach(function (x) { x.classList.toggle('active', x === b); x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
      $$('.tool-panel').forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-tool') === key); });
      setRoving(b); // keep the roving tabindex on the selected tab
      if (window.MAM_TRACK) window.MAM_TRACK('tool_open', key); // S1 engagement telemetry
      // Stop sound-producing tools when switching away so they don't haunt the page.
      if (key !== 'metronome') stopMetronome();
      if (key !== 'tuner') stopTuner();
    });
  });

  // Accessible tablist: a focused tab moves with Arrow/Home/End (WAI-ARIA tabs
  // pattern) and only the active tab is in the tab order (roving tabindex), so
  // keyboard users aren't forced to step through all eight tabs.
  setRoving($('.tools-tabs button.active') || toolTabs[0]);
  var tablist = $('.tools-tabs');
  if (tablist) tablist.addEventListener('keydown', function (e) {
    var i = toolTabs.indexOf(document.activeElement);
    if (i < 0) return;
    var n = null, last = toolTabs.length - 1;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') n = toolTabs[(i + 1) % toolTabs.length];
    else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') n = toolTabs[(i - 1 + toolTabs.length) % toolTabs.length];
    else if (e.key === 'Home') n = toolTabs[0];
    else if (e.key === 'End') n = toolTabs[last];
    else return;
    e.preventDefault(); n.focus(); n.click();
  });

  // Deep links: /studio#tuner, /studio#pitchgame … open that tab directly
  // (the student portal links into specific tools this way).
  function openToolFromHash() {
    var key = (location.hash || '').replace('#', '');
    if (!key) return;
    var btn = $$('.tools-tabs button').filter(function (b) { return b.getAttribute('data-tool') === key; })[0];
    if (btn) btn.click();
  }
  openToolFromHash();
  window.addEventListener('hashchange', openToolFromHash);

  // "Tools at a glance" cards jump to their tab and scroll it into view.
  $$('[data-goto-tool]').forEach(function (card) {
    card.addEventListener('click', function () {
      var key = card.getAttribute('data-goto-tool');
      var btn = toolTabs.filter(function (b) { return b.getAttribute('data-tool') === key; })[0];
      if (!btn) return;
      btn.click();
      var shell = document.querySelector('.tools-tabs');
      if (shell && shell.scrollIntoView) shell.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  // ──────────────────────────────────────────────────────────────────
  // METRONOME — scheduled clicks via AudioBufferSourceNode, accent on 1.
  // Uses the "Chris Wilson scheduler" pattern: a setInterval lookahead
  // queues the next ~25ms of clicks against AudioContext.currentTime so
  // the rhythm stays sample-accurate even when the tab is backgrounded.
  // ──────────────────────────────────────────────────────────────────
  var metBpm = 100, metBeats = 4, metSub = 1, metVol = 0.6;
  var metPlaying = false, metInterval = null, metNextTime = 0, metBeatIndex = 0;
  var bpmVal = $('#metBpmVal'), bpmRange = $('#metBpmRange');
  var beatsBox = $('#metBeats');

  // Shared tempo: adopt the studio bus value and follow it so the Metronome
  // and the Beat Maker always agree on the tempo.
  function setMetBpm(v, fromBus) {
    metBpm = Math.max(30, Math.min(240, Math.round(v) || 100));
    bpmRange.value = metBpm; bpmVal.textContent = metBpm;
    if (!fromBus && window.MAM && window.MAM.tempo) window.MAM.tempo.set(metBpm);
  }
  if (window.MAM && window.MAM.tempo) {
    metBpm = Math.min(240, window.MAM.tempo.get());
    bpmRange.value = metBpm; bpmVal.textContent = metBpm;
    window.MAM.tempo.subscribe(function (v) { if (v !== metBpm) setMetBpm(v, true); });
  }

  // Per-beat accent map (editable): tap a beat dot to toggle its accent.
  // Beat 1 is accented by default. Persisted only in-memory for the session.
  var metAccents = [true], metBar = 0;
  function ensureAccents() {
    for (var i = 0; i < metBeats; i++) if (metAccents[i] === undefined) metAccents[i] = (i === 0);
    metAccents.length = metBeats;
  }
  function paintBeatDots() {
    ensureAccents();
    beatsBox.innerHTML = '';
    for (var i = 0; i < metBeats; i++) {
      var d = document.createElement('div');
      d.className = 'met-beat' + (metAccents[i] ? ' beat-accent' : '');
      d.setAttribute('data-beat', i);
      d.title = 'Click to toggle accent';
      beatsBox.appendChild(d);
    }
  }
  paintBeatDots();
  // Click a dot to toggle its accent (event-delegated so it survives repaints).
  beatsBox.addEventListener('click', function (e) {
    var dot = e.target.closest ? e.target.closest('.met-beat') : null;
    if (!dot) return;
    var n = parseInt(dot.getAttribute('data-beat'), 10);
    if (isNaN(n)) return;
    metAccents[n] = !metAccents[n];
    dot.classList.toggle('beat-accent', metAccents[n]);
  });

  function clickAt(when, accent) {
    var a = audio();
    var osc = a.createOscillator();
    var gain = a.createGain();
    osc.frequency.value = accent ? 1500 : 880;
    gain.gain.setValueAtTime(0.0001, when);
    gain.gain.exponentialRampToValueAtTime(metVol, when + 0.001);
    gain.gain.exponentialRampToValueAtTime(0.0001, when + 0.05);
    osc.connect(gain).connect(a.destination);
    osc.start(when); osc.stop(when + 0.06);
  }

  // Tempo trainer: nudge the BPM up by a step every N bars, up to a ceiling —
  // the classic way to build speed cleanly. Controls are optional; absent = off.
  function metTrainerOn()   { var e = $('#metTrainer'); return !!(e && e.checked); }
  function metTrainerStep() { return Math.max(1, parseInt(($('#metTrainerStep') || {}).value, 10) || 4); }
  function metTrainerBars() { return Math.max(1, parseInt(($('#metTrainerBars') || {}).value, 10) || 2); }
  function metTrainerMax()  { return Math.max(metBpm, Math.min(240, parseInt(($('#metTrainerMax') || {}).value, 10) || 180)); }

  function scheduler() {
    var a = audio();
    var lookahead = 0.1; // schedule up to 100ms ahead
    var spb = 60.0 / metBpm / metSub; // seconds per (sub)beat
    while (metNextTime < a.currentTime + lookahead) {
      var onMainBeat = (metBeatIndex % metSub) === 0;
      var mainBeatNum = Math.floor(metBeatIndex / metSub) % metBeats;
      var isAccent = onMainBeat && !!metAccents[mainBeatNum];
      // Bar boundary (downbeat after the first): advance the tempo trainer.
      if (onMainBeat && mainBeatNum === 0 && metBeatIndex > 0) {
        metBar++;
        if (metTrainerOn() && metBar % metTrainerBars() === 0) {
          var next = Math.min(metTrainerMax(), metBpm + metTrainerStep());
          if (next !== metBpm) { setMetBpm(next); var st = $('#metTrainerStatus'); if (st) st.textContent = 'Tempo trainer: ' + next + ' BPM'; }
        }
      }
      clickAt(metNextTime, isAccent);
      // Flash the corresponding dot on the next frame.
      if (onMainBeat) (function (n) {
        setTimeout(function () { lightBeat(n); }, Math.max(0, (metNextTime - a.currentTime) * 1000));
      })(mainBeatNum);
      metNextTime += spb;
      metBeatIndex++;
    }
  }
  function lightBeat(n) {
    var dots = beatsBox.children;
    for (var i = 0; i < dots.length; i++) dots[i].classList.remove('beat-active');
    if (dots[n]) {
      dots[n].classList.add('beat-active');
      setTimeout(function () { dots[n].classList.remove('beat-active'); }, 90);
    }
  }
  function startMetronome() {
    var a = audio();
    metPlaying = true; metBeatIndex = 0; metBar = 0; metNextTime = a.currentTime + 0.06;
    metInterval = setInterval(scheduler, 25);
    var btn = $('#metStart');
    btn.classList.add('playing');
    btn.querySelector('span').textContent = 'Stop';
    btn.querySelector('i').className = 'fa-solid fa-stop';
  }
  function stopMetronome() {
    metPlaying = false;
    if (metInterval) clearInterval(metInterval);
    metInterval = null;
    var btn = $('#metStart');
    if (btn) {
      btn.classList.remove('playing');
      btn.querySelector('span').textContent = 'Start';
      btn.querySelector('i').className = 'fa-solid fa-play';
    }
    $$('.met-beat').forEach(function (d) { d.classList.remove('beat-active'); });
  }
  $('#metStart').addEventListener('click', function () { metPlaying ? stopMetronome() : startMetronome(); });

  bpmRange.addEventListener('input', function () {
    setMetBpm(parseInt(bpmRange.value, 10) || 100);
  });
  $('#metBeatsCount').addEventListener('change', function (e) {
    metBeats = parseInt(e.target.value, 10) || 4; paintBeatDots(); metBeatIndex = 0;
  });
  $('#metSubdivision').addEventListener('change', function (e) { metSub = parseInt(e.target.value, 10) || 1; });
  $('#metVolume').addEventListener('input', function (e) { metVol = (parseInt(e.target.value, 10) || 0) / 100; });

  // Tap tempo: rolling average of the last few taps. A pause longer than ~2s
  // (≈ slower than 30 BPM) means the user is starting a NEW tempo, so we reset
  // the window — otherwise the stale gap is averaged in and the BPM is wrong.
  var tapTimes = [];
  $('#metTap').addEventListener('click', function () {
    var now = performance.now();
    if (tapTimes.length && now - tapTimes[tapTimes.length - 1] > 2000) tapTimes = [];
    tapTimes.push(now);
    if (tapTimes.length > 5) tapTimes.shift();
    if (tapTimes.length >= 2) {
      var diffs = [];
      for (var i = 1; i < tapTimes.length; i++) diffs.push(tapTimes[i] - tapTimes[i - 1]);
      var avg = diffs.reduce(function (a, b) { return a + b; }, 0) / diffs.length;
      var bpm = Math.round(60000 / avg);
      if (bpm >= 30 && bpm <= 240) setMetBpm(bpm);
    }
  });
  // Spacebar toggles when the metronome panel is active.
  document.addEventListener('keydown', function (e) {
    if (e.code === 'Space' && $('.tool-panel.active').getAttribute('data-tool') === 'metronome' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'SELECT') {
      e.preventDefault();
      metPlaying ? stopMetronome() : startMetronome();
    }
  });

  // ──────────────────────────────────────────────────────────────────
  // TUNER — autocorrelation on a mic stream. Reports the nearest A=440
  // note + cents offset. Pitch detection uses a normalised square-difference
  // function (NSDF / "McLeod pitch method"), which is robust for monophonic
  // instruments + voice without external libraries.
  // ──────────────────────────────────────────────────────────────────
  var NOTE_NAMES = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
  var tunerStream = null, tunerSrc = null, tunerAnalyser = null, tunerBuf = null, tunerRaf = null, tunerListening = false;
  var tunerA4 = 440;        // adjustable concert-pitch reference
  var tunerTranspose = 0;   // semitones added to the DISPLAYED note (transposing instruments)
  var tunerSmoothed = null; // smoothed cents for a calmer needle

  function freqToNote(freq) {
    var n = 12 * Math.log2(freq / tunerA4) + 69; // MIDI note number, relative to A4
    var raw = Math.round(n);
    var cents = Math.round((n - raw) * 100);
    var midi = raw + tunerTranspose; // shift the written note for transposing instruments
    return { name: NOTE_NAMES[((midi % 12) + 12) % 12], octave: Math.floor(midi / 12) - 1, cents: cents };
  }
  // A4 calibration, instrument transposition + a clean reference tone.
  (function initTunerExtras() {
    var a4El = $('#tunerA4'), refBtn = $('#tunerRef'), trEl = $('#tunerTranspose');
    if (a4El) a4El.addEventListener('input', function () {
      tunerA4 = Math.max(415, Math.min(466, parseInt(a4El.value, 10) || 440));
    });
    if (trEl) trEl.addEventListener('change', function () {
      tunerTranspose = parseInt(trEl.value, 10) || 0;
    });
    if (refBtn) refBtn.addEventListener('click', function () {
      var a = audio(), now = a.currentTime, o = a.createOscillator(), g = a.createGain();
      o.type = 'sine'; o.frequency.value = tunerA4;
      g.gain.setValueAtTime(0.0001, now);
      g.gain.exponentialRampToValueAtTime(0.25, now + 0.02);
      g.gain.setValueAtTime(0.25, now + 1.1);
      g.gain.exponentialRampToValueAtTime(0.0001, now + 1.5);
      o.connect(g).connect(a.destination); o.start(now); o.stop(now + 1.55);
    });
  })();
  // Autocorrelation with the classic "find the first peak after the first valley" heuristic.
  function detectPitch(buf, sampleRate) {
    var SIZE = buf.length;
    var rms = 0;
    for (var i = 0; i < SIZE; i++) rms += buf[i] * buf[i];
    rms = Math.sqrt(rms / SIZE);
    if (rms < 0.01) return -1;
    var r1 = 0, r2 = SIZE - 1, thres = 0.2;
    for (var i = 0; i < SIZE / 2; i++) if (Math.abs(buf[i]) < thres) { r1 = i; break; }
    for (var i = 1; i < SIZE / 2; i++) if (Math.abs(buf[SIZE - i]) < thres) { r2 = SIZE - i; break; }
    buf = buf.slice(r1, r2);
    SIZE = buf.length;
    // Only correlate up to the lag of the lowest pitch we report (~50 Hz). This
    // bounds the O(n²) autocorrelation to the musical range — a meaningful
    // speed-up on low-end devices when the Pitchy module is blocked — and stops
    // the peak search locking onto a spurious sub-harmonic past that lag.
    var maxLag = Math.min(SIZE - 1, Math.ceil(sampleRate / 50));
    var c = new Array(maxLag + 1).fill(0);
    for (var i = 0; i <= maxLag; i++) for (var j = 0; j < SIZE - i; j++) c[i] += buf[j] * buf[j + i];
    var d = 0; while (d < maxLag && c[d] > c[d + 1]) d++;
    var maxVal = -1, maxPos = -1;
    for (var i = d; i <= maxLag; i++) if (c[i] > maxVal) { maxVal = c[i]; maxPos = i; }
    var T0 = maxPos;
    var x1 = c[T0 - 1] || 0, x2 = c[T0] || 0, x3 = c[T0 + 1] || 0;
    var a = (x1 + x3 - 2 * x2) / 2;
    var b = (x3 - x1) / 2;
    if (a) T0 = T0 - b / (2 * a);
    return sampleRate / T0;
  }
  function startTuner() {
    if (tunerListening) return stopTuner();
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      $('#tunerStatus').textContent = "Your browser doesn't expose a microphone API. Try the latest Chrome or Safari.";
      return;
    }
    $('#tunerStatus').textContent = 'Asking for microphone permission…';
    navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false } })
      .then(function (stream) {
        tunerStream = stream;
        var a = audio();
        tunerSrc = a.createMediaStreamSource(stream);
        tunerAnalyser = a.createAnalyser();
        tunerAnalyser.fftSize = 2048;
        tunerBuf = new Float32Array(tunerAnalyser.fftSize);
        tunerSrc.connect(tunerAnalyser);
        tunerListening = true;
        var btn = $('#tunerStart');
        btn.classList.add('listening');
        btn.querySelector('span').textContent = 'Stop';
        btn.querySelector('i').className = 'fa-solid fa-stop';
        $('#tunerStatus').textContent = 'Listening… play a single note.';
        loopTuner();
      })
      .catch(function (e) {
        $('#tunerStatus').innerHTML = "Couldn't access the microphone: " + e.message + '. <br>Check permissions in your browser settings.';
      });
  }
  // Pitchy detector — lazily-constructed when the module loads. Provides
  // sub-cent accuracy vs the autocorrelation we ship as fallback.
  var pitchyDetector = null;
  function loopTuner() {
    if (!tunerListening) return;
    tunerAnalyser.getFloatTimeDomainData(tunerBuf);
    var f = -1;
    if (hasPitchy()) {
      if (!pitchyDetector) pitchyDetector = window.MAM_PITCHY.PitchDetector.forFloat32Array(tunerAnalyser.fftSize);
      var res = pitchyDetector.findPitch(tunerBuf, audio().sampleRate);
      // res = [pitchHz, clarity 0..1]. Only trust strong-clarity readings.
      if (res && res[1] > 0.85) f = res[0];
    } else {
      f = detectPitch(tunerBuf, audio().sampleRate);
    }
    if (f > 50 && f < 4500) {
      var n = freqToNote(f);
      $('#tunerNote').textContent = n.name;
      $('#tunerOctave').textContent = n.octave;
      $('#tunerFreq').textContent = f.toFixed(1) + ' Hz';
      // Exponential smoothing of the cents reading → a calmer, less jittery
      // needle. Snap (don't smooth) when the note itself changes.
      if (tunerSmoothed === null || Math.abs(n.cents - tunerSmoothed) > 45) tunerSmoothed = n.cents;
      else tunerSmoothed = tunerSmoothed * 0.7 + n.cents * 0.3;
      // Map -50..+50 cents to 0..100% horizontal position.
      var pct = Math.max(0, Math.min(100, 50 + tunerSmoothed));
      var tick = $('#tunerTick');
      tick.style.left = pct + '%';
      tick.classList.remove('in-tune', 'flat', 'sharp');
      if (Math.abs(tunerSmoothed) < 6) tick.classList.add('in-tune');
      else if (tunerSmoothed < 0) tick.classList.add('flat');
      else tick.classList.add('sharp');
    } else {
      $('#tunerNote').textContent = '—';
      $('#tunerOctave').textContent = '';
      $('#tunerFreq').textContent = 'Listening…';
      tunerSmoothed = null;
    }
    tunerRaf = requestAnimationFrame(loopTuner);
  }
  function stopTuner() {
    tunerListening = false;
    if (tunerRaf) cancelAnimationFrame(tunerRaf);
    if (tunerStream) tunerStream.getTracks().forEach(function (t) { t.stop(); });
    if (tunerSrc) try { tunerSrc.disconnect(); } catch (e) {}
    var btn = $('#tunerStart');
    if (btn) {
      btn.classList.remove('listening');
      btn.querySelector('span').textContent = 'Listen';
      btn.querySelector('i').className = 'fa-solid fa-microphone';
    }
    var s = $('#tunerStatus'); if (s) s.textContent = 'Stopped. Click "Listen" to start again.';
  }
  $('#tunerStart').addEventListener('click', startTuner);

  // ──────────────────────────────────────────────────────────────────
  // POMODORO — focus blocks + breaks. Persists today's log in localStorage.
  // ──────────────────────────────────────────────────────────────────
  var pomoState = { running: false, mode: 'focus', secs: 25 * 60, block: 1, intervalId: null };
  var POMO_KEY = 'mam.pomo.log';

  function pomoCfg() {
    return {
      focus: Math.max(5, parseInt($('#pomoFocus').value, 10) || 25) * 60,
      short: Math.max(2, parseInt($('#pomoShort').value, 10) || 5) * 60,
      long: Math.max(5, parseInt($('#pomoLong').value, 10) || 20) * 60,
      blocks: Math.max(2, parseInt($('#pomoBlocks').value, 10) || 4),
    };
  }
  function paintPomo() {
    var m = Math.floor(pomoState.secs / 60), s = pomoState.secs % 60;
    $('#pomoTime').textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    var mode = $('#pomoMode');
    mode.classList.remove('break', 'long-break');
    if (pomoState.mode === 'focus') mode.textContent = 'Focus · Block ' + pomoState.block + ' of ' + pomoCfg().blocks;
    else if (pomoState.mode === 'short') { mode.textContent = 'Short break'; mode.classList.add('break'); }
    else if (pomoState.mode === 'long') { mode.textContent = 'Long break — well earned'; mode.classList.add('long-break'); }
    paintPomoRing();
  }
  // Circular progress ring drawn on canvas (DPR-aware), coloured by phase.
  function paintPomoRing() {
    var cv = $('#pomoRing'); if (!cv || !cv.getContext) return;
    var cfg = pomoCfg();
    var total = pomoState.mode === 'focus' ? cfg.focus : (pomoState.mode === 'short' ? cfg.short : cfg.long);
    var frac = total > 0 ? Math.max(0, Math.min(1, pomoState.secs / total)) : 0;
    var dpr = window.devicePixelRatio || 1, size = 180;
    if (cv.width !== size * dpr) { cv.width = size * dpr; cv.height = size * dpr; cv.style.width = size + 'px'; cv.style.height = size + 'px'; }
    var g = cv.getContext('2d'); g.setTransform(dpr, 0, 0, dpr, 0, 0);
    g.clearRect(0, 0, size, size);
    var cx = size / 2, cy = size / 2, r = size / 2 - 12, lw = 12;
    g.lineWidth = lw; g.lineCap = 'round';
    g.strokeStyle = 'rgba(255,255,255,0.08)';
    g.beginPath(); g.arc(cx, cy, r, 0, Math.PI * 2); g.stroke();
    var col = pomoState.mode === 'focus' ? '#FCC647' : (pomoState.mode === 'short' ? '#7CC3F0' : '#A5B4FC');
    var start = -Math.PI / 2, end = start + Math.PI * 2 * frac;
    g.strokeStyle = col;
    g.beginPath(); g.arc(cx, cy, r, start, end); g.stroke();
  }
  function logPomoSession(kind, mins) {
    var raw = localStorage.getItem(POMO_KEY);
    var log = [];
    try { log = raw ? JSON.parse(raw) : []; } catch (e) { log = []; }
    var today = new Date().toISOString().slice(0, 10);
    log = log.filter(function (x) { return x.date === today; });
    log.unshift({ date: today, kind: kind, mins: mins, at: new Date().toISOString() });
    log = log.slice(0, 12);
    try { localStorage.setItem(POMO_KEY, JSON.stringify(log)); } catch (e) {}
    paintPomoLog();
  }
  function paintPomoLog() {
    var raw = localStorage.getItem(POMO_KEY);
    var log = [];
    try { log = raw ? JSON.parse(raw) : []; } catch (e) {}
    var today = new Date().toISOString().slice(0, 10);
    log = log.filter(function (x) { return x.date === today; });
    var focusMins = log.reduce(function (s, e) { return s + (e.kind === 'focus' ? (e.mins || 0) : 0); }, 0);
    var tot = $('#pomoTotal'); if (tot) tot.textContent = focusMins ? (focusMins + ' min focused today') : '';
    var ul = $('#pomoSessions');
    if (!log.length) { ul.innerHTML = '<li style="justify-content:center;color:var(--text-muted)">No sessions logged yet.</li>'; return; }
    ul.innerHTML = log.map(function (e) {
      var t = new Date(e.at);
      var hh = String(t.getHours()).padStart(2, '0') + ':' + String(t.getMinutes()).padStart(2, '0');
      var label = e.kind === 'focus' ? 'Focus' : (e.kind === 'short' ? 'Short break' : 'Long break');
      return '<li><span>' + label + ' · ' + e.mins + ' min</span><span class="when">' + hh + '</span></li>';
    }).join('');
  }
  paintPomoLog();
  // Auto-start the next phase by default; uncheck to pause at each boundary.
  function pomoAutoOn() { var e = $('#pomoAuto'); return !e || e.checked; }
  function pomoNotify(msg) {
    try { if (window.Notification && Notification.permission === 'granted') new Notification('MAM Practice Studio', { body: msg, silent: true }); } catch (e) {}
  }
  // Drift-free Pomodoro: tick based on real-world elapsed time, not setInterval
  // counting. setInterval can drift by minutes over a long session (tab
  // throttled, sleep, etc). Recomputing from a start timestamp keeps the
  // clock accurate to within one tick (~1 s) over any duration.
  function tickPomo() {
    if (pomoState.startedAt) {
      var elapsed = Math.round((Date.now() - pomoState.startedAt) / 1000);
      pomoState.secs = Math.max(0, pomoState.targetSecs - elapsed);
    } else {
      pomoState.secs--;
    }
    if (pomoState.secs < 0) {
      var cfg = pomoCfg();
      var completedKind = pomoState.mode;
      var completedMins = Math.round(({ focus: cfg.focus, short: cfg.short, long: cfg.long }[completedKind]) / 60);
      logPomoSession(completedKind, completedMins);
      chime();
      if (pomoState.mode === 'focus') {
        if (pomoState.block >= cfg.blocks) { pomoState.mode = 'long'; pomoState.secs = cfg.long; pomoState.block = 1; }
        else { pomoState.mode = 'short'; pomoState.secs = cfg.short; }
      } else { pomoState.mode = 'focus'; pomoState.secs = cfg.focus; if (completedKind === 'short') pomoState.block++; }
      pomoNotify(completedKind === 'focus' ? 'Focus block done — time for a break.' : 'Break over — back to focus. You’ve got this.');
      // Re-anchor wall clock for the new phase so the drift-free tick stays
      // accurate after a transition.
      pomoState.startedAt = Date.now();
      pomoState.targetSecs = pomoState.secs;
      // Auto-start the next phase, or pause at the boundary if the user opted out.
      if (!pomoAutoOn()) {
        pomoState.running = false;
        if (pomoState.intervalId) clearInterval(pomoState.intervalId);
        pomoState.intervalId = null; pomoState.startedAt = null;
      }
      paintPomoStart();
    }
    paintPomo();
  }
  function paintPomoStart() {
    var btn = $('#pomoStart');
    btn.classList.toggle('running', pomoState.running);
    btn.querySelector('span').textContent = pomoState.running ? 'Pause' : (pomoState.mode === 'focus' ? 'Start focus' : 'Start break');
    btn.querySelector('i').className = pomoState.running ? 'fa-solid fa-pause' : 'fa-solid fa-play';
  }
  $('#pomoStart').addEventListener('click', function () {
    pomoState.running = !pomoState.running;
    if (pomoState.running) {
      audio();
      // Ask once for permission so we can ping you when a phase ends.
      try { if (window.Notification && Notification.permission === 'default') Notification.requestPermission(); } catch (e) {}
      // Anchor the timer to real wall-clock time so it can't drift.
      pomoState.startedAt = Date.now();
      pomoState.targetSecs = pomoState.secs;
      pomoState.intervalId = setInterval(tickPomo, 500);
    } else {
      clearInterval(pomoState.intervalId);
      pomoState.startedAt = null;
    }
    paintPomoStart();
  });
  $('#pomoReset').addEventListener('click', function () {
    if (pomoState.intervalId) clearInterval(pomoState.intervalId);
    pomoState = { running: false, mode: 'focus', secs: pomoCfg().focus, block: 1, intervalId: null, startedAt: null, targetSecs: pomoCfg().focus };
    paintPomo(); paintPomoStart();
  });
  $$('#pomoFocus, #pomoShort, #pomoLong, #pomoBlocks').forEach(function (el) {
    el.addEventListener('change', function () {
      if (!pomoState.running) { pomoState.secs = pomoCfg().focus; paintPomo(); }
    });
  });
  $('#pomoClear').addEventListener('click', function () { localStorage.removeItem(POMO_KEY); paintPomoLog(); });
  function chime() {
    var a = audio();
    [880, 1320, 1760].forEach(function (f, i) {
      var o = a.createOscillator(), g = a.createGain();
      o.frequency.value = f;
      g.gain.setValueAtTime(0.0001, a.currentTime + i * 0.18);
      g.gain.exponentialRampToValueAtTime(0.35, a.currentTime + i * 0.18 + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, a.currentTime + i * 0.18 + 0.45);
      o.connect(g).connect(a.destination);
      o.start(a.currentTime + i * 0.18); o.stop(a.currentTime + i * 0.18 + 0.5);
    });
  }
  paintPomo();

  // ──────────────────────────────────────────────────────────────────
  // EAR TRAINING — intervals, chord quality, pitch direction.
  // Uses small sine + triangle voices through a shared gain so we get a
  // pleasant tone without an external sample library.
  // ──────────────────────────────────────────────────────────────────
  var INTERVALS = [
    { semis: 1,  name: 'Minor 2nd' }, { semis: 2,  name: 'Major 2nd' },
    { semis: 3,  name: 'Minor 3rd' }, { semis: 4,  name: 'Major 3rd' },
    { semis: 5,  name: 'Perfect 4th' }, { semis: 6,  name: 'Tritone' },
    { semis: 7,  name: 'Perfect 5th' }, { semis: 8,  name: 'Minor 6th' },
    { semis: 9,  name: 'Major 6th' }, { semis: 10, name: 'Minor 7th' },
    { semis: 11, name: 'Major 7th' }, { semis: 12, name: 'Octave' },
  ];
  var CHORDS = [
    { name: 'Major', semis: [0, 4, 7] },
    { name: 'Minor', semis: [0, 3, 7] },
    { name: 'Diminished', semis: [0, 3, 6] },
    { name: 'Augmented', semis: [0, 4, 8] },
    { name: 'Major 7', semis: [0, 4, 7, 11] },
    { name: 'Dominant 7', semis: [0, 4, 7, 10] },
    { name: 'Minor 7', semis: [0, 3, 7, 10] },
  ];
  var earMode = 'intervals';
  var earScore = { correct: 0, total: 0, streak: 0, current: null };

  // Difficulty restricts the pool (and the answer buttons). All-time best
  // streak + accuracy persist across sessions.
  var EAR_KEY = 'mam.ear.stats';
  var earBest = (function () { try { var r = localStorage.getItem(EAR_KEY); return r ? JSON.parse(r) : { best: 0, correct: 0, total: 0 }; } catch (e) { return { best: 0, correct: 0, total: 0 }; } })();
  function earDifficulty() { var e = $('#earDifficulty'); return e ? e.value : 'all'; }
  function activeIntervals() {
    var d = earDifficulty();
    if (d === 'easy')   return INTERVALS.filter(function (x) { return [3, 4, 5, 7, 12].indexOf(x.semis) >= 0; });
    if (d === 'medium') return INTERVALS.filter(function (x) { return [1, 2, 3, 4, 5, 7, 8, 9, 12].indexOf(x.semis) >= 0; });
    return INTERVALS;
  }
  function activeChords() {
    var d = earDifficulty();
    if (d === 'easy')   return CHORDS.filter(function (x) { return ['Major', 'Minor'].indexOf(x.name) >= 0; });
    if (d === 'medium') return CHORDS.filter(function (x) { return ['Major', 'Minor', 'Diminished', 'Augmented'].indexOf(x.name) >= 0; });
    return CHORDS;
  }
  function paintEarBest() {
    var el = $('#earBest'); if (!el) return;
    var acc = earBest.total ? Math.round(earBest.correct / earBest.total * 100) : 0;
    el.textContent = 'Best streak ' + (earBest.best || 0) + ' · ' + acc + '% all-time';
  }

  function midiToFreq(m) { return 440 * Math.pow(2, (m - 69) / 12); }
  // Real instrument sound: a SAMPLED acoustic grand piano (Tone.Sampler with
  // the Salamander samples on the Tone.js CDN) — a genuine, musical timbre
  // rather than a beep. Until the samples are warm (or if the CDN is blocked)
  // we use a piano-like FM synth; with no Tone at all, a richer additive
  // Web-Audio piano. Whatever the environment, the drills make a pleasant,
  // clearly-pitched note.
  var earPiano = null, earFallbackSynth = null;
  function ensureEarInstrument() {
    if (!hasTone()) return null;
    audio(); // unlock Tone's context (autoplay-policy gate)
    if (!earFallbackSynth) {
      earFallbackSynth = new Tone.PolySynth(Tone.FMSynth, {
        harmonicity: 2, modulationIndex: 6,
        oscillator: { type: 'sine' }, modulation: { type: 'triangle' },
        envelope: { attack: 0.006, decay: 1.1, sustain: 0.06, release: 0.9 },
        modulationEnvelope: { attack: 0.004, decay: 0.3, sustain: 0.02, release: 0.2 },
        volume: -11,
      }).toDestination();
    }
    if (!earPiano) {
      try {
        earPiano = new Tone.Sampler({
          urls: {
            A2: 'A2.mp3', C3: 'C3.mp3', 'D#3': 'Ds3.mp3', 'F#3': 'Fs3.mp3',
            A3: 'A3.mp3', C4: 'C4.mp3', 'D#4': 'Ds4.mp3', 'F#4': 'Fs4.mp3',
            A4: 'A4.mp3', C5: 'C5.mp3', 'D#5': 'Ds5.mp3', 'F#5': 'Fs5.mp3', A5: 'A5.mp3',
          },
          baseUrl: 'https://tonejs.github.io/audio/salamander/',
          release: 1, volume: -6,
        }).toDestination();
      } catch (e) { earPiano = null; }
    }
    return (earPiano && earPiano.loaded) ? earPiano : earFallbackSynth;
  }
  // Warm the piano as soon as Tone is ready so it's loaded before the first drill.
  window.addEventListener('mam:tone-ready', function () { try { ensureEarInstrument(); } catch (e) {} });

  // Richer Web-Audio piano fallback: three decaying partials + a soft
  // percussive attack — far more musical than a bare triangle.
  function pianoVoiceWA(a, midi, when, dur) {
    var f0 = midiToFreq(midi);
    [[1, 0.5], [2, 0.18], [3, 0.08]].forEach(function (p) {
      var o = a.createOscillator(), g = a.createGain();
      o.type = 'sine'; o.frequency.value = f0 * p[0] * (1 + 0.0008 * p[0]); // slight inharmonicity
      g.gain.setValueAtTime(0.0001, when);
      g.gain.exponentialRampToValueAtTime(p[1], when + 0.005);
      g.gain.exponentialRampToValueAtTime(0.0001, when + dur);
      o.connect(g).connect(a.destination);
      o.start(when); o.stop(when + dur + 0.05);
    });
  }
  function playNotes(midis, mode) {
    var inst = ensureEarInstrument();
    if (inst) {
      var now = Tone.now() + 0.05;
      var dur = mode === 'harmonic' ? 1.4 : 0.6, gap = 0.6;
      midis.forEach(function (m, i) {
        var t = now + (mode === 'harmonic' ? 0 : i * gap);
        inst.triggerAttackRelease(Tone.Frequency(m, 'midi').toFrequency(), dur, t);
      });
      return;
    }
    // No Tone at all → richer additive Web-Audio piano.
    var a = audio();
    var dur2 = mode === 'harmonic' ? 1.3 : 0.7, gap2 = 0.6;
    midis.forEach(function (m, i) {
      pianoVoiceWA(a, m, a.currentTime + (mode === 'harmonic' ? 0 : i * gap2), dur2);
    });
  }
  function newEarRound() {
    var root = 57 + Math.floor(Math.random() * 12); // A3..G#4
    if (earMode === 'intervals') {
      var ivs = activeIntervals(), iv = ivs[Math.floor(Math.random() * ivs.length)];
      var harm = $('#earHarmonic') && $('#earHarmonic').checked;
      earScore.current = { kind: 'intervals', answer: iv.name, notes: [root, root + iv.semis], mode: harm ? 'harmonic' : 'melodic' };
    } else if (earMode === 'chords') {
      var cs = activeChords(), c = cs[Math.floor(Math.random() * cs.length)];
      earScore.current = { kind: 'chords', answer: c.name, notes: c.semis.map(function (s) { return root + s; }), mode: 'harmonic' };
    } else {
      var step = (Math.random() < 0.5 ? 1 : -1) * (1 + Math.floor(Math.random() * 7));
      var ans = step > 0 ? 'Up' : 'Down';
      earScore.current = { kind: 'direction', answer: ans, notes: [root, root + step], mode: 'melodic' };
    }
    paintEarOptions();
    setTimeout(function () { playNotes(earScore.current.notes, earScore.current.mode); }, 120);
    $('#earFeedback').textContent = 'Pick the answer below.';
  }
  function paintEarOptions() {
    var opts = [];
    if (earMode === 'intervals') opts = activeIntervals().map(function (x) { return x.name; });
    else if (earMode === 'chords') opts = activeChords().map(function (x) { return x.name; });
    else opts = ['Up', 'Down'];
    var box = $('#earOptions');
    box.innerHTML = opts.map(function (o) { return '<button type="button" data-answer="' + o + '">' + o + '</button>'; }).join('');
    $$('button', box).forEach(function (b) {
      b.addEventListener('click', function () { check(b.getAttribute('data-answer'), b); });
    });
    $('#earPlay span').textContent = earMode === 'chords' ? 'Play chord' : (earMode === 'direction' ? 'Play notes' : 'Play interval');
  }
  function check(ans, btn) {
    if (!earScore.current) return;
    var ok = ans === earScore.current.answer;
    earScore.total++;
    if (ok) {
      earScore.correct++; earScore.streak++;
      btn.classList.add('correct');
      $('#earFeedback').textContent = '✓ Correct! ' + earScore.current.answer + '. New round in a moment…';
      setTimeout(newEarRound, 900);
    } else {
      earScore.streak = 0;
      btn.classList.add('wrong');
      var right = $$('#earOptions button').filter(function (b) { return b.getAttribute('data-answer') === earScore.current.answer; })[0];
      if (right) right.classList.add('correct');
      $('#earFeedback').textContent = '✗ It was ' + earScore.current.answer + '. Press Play to try again.';
    }
    $('#earCorrect').textContent = earScore.correct;
    $('#earTotal').textContent = earScore.total;
    $('#earStreak').textContent = earScore.streak;
    // Persist all-time best streak + accuracy.
    if (earScore.streak > (earBest.best || 0)) earBest.best = earScore.streak;
    earBest.correct = (earBest.correct || 0) + (ok ? 1 : 0);
    earBest.total = (earBest.total || 0) + 1;
    try { localStorage.setItem(EAR_KEY, JSON.stringify(earBest)); } catch (e) {}
    paintEarBest();
  }
  $('#earPlay').addEventListener('click', function () {
    if (earScore.current) playNotes(earScore.current.notes, earScore.current.mode);
    else newEarRound();
  });
  $$('.ear-mode button').forEach(function (b) {
    b.addEventListener('click', function () {
      $$('.ear-mode button').forEach(function (x) { x.classList.toggle('active', x === b); });
      earMode = b.getAttribute('data-ear-mode');
      earScore.current = null;
      paintEarOptions();
      $('#earFeedback').textContent = 'Press Play to start a new round.';
    });
  });
  if ($('#earDifficulty')) $('#earDifficulty').addEventListener('change', function () {
    earScore.current = null;
    paintEarOptions();
    $('#earFeedback').textContent = 'Difficulty set. Press Play for a new round.';
  });
  paintEarOptions();
  paintEarBest();

  // ──────────────────────────────────────────────────────────────────
  // SPEED & PITCH TRAINER (premium tool)
  //
  // Loads an audio file (kept entirely on-device — never uploaded), then
  // lets the user change SPEED and PITCH independently. This is the
  // headline feature of paid practice apps (Anytune, AmazingSlowDowner,
  // Capo) and we get it free via Tone.js:
  //
  //   GrainPlayer    — granular synthesis, time-stretches the buffer
  //                    without affecting pitch (controlled by .playbackRate)
  //   PitchShift     — phase-vocoder, shifts pitch ±12 semitones without
  //                    affecting playback speed
  //
  // Chained: source → PitchShift → destination. The decoupling of speed
  // from pitch is what makes this a 'premium' tool — a regular HTML5
  // <audio>.playbackRate change distorts pitch in lockstep.
  //
  // A/B loop, 10-second skips, accurate seeking, and three speed presets
  // round it out. Saves nothing to disk; loading a new file is the reset.
  // ──────────────────────────────────────────────────────────────────
  (function initSpeedPitch() {
    var dz = $('#spDrop'), input = $('#spFile'), player = $('#spPlayer');
    if (!dz || !input || !player) return;

    // ── State ────────────────────────────────────────────────
    var state = {
      buffer: null,        // decoded AudioBuffer
      grain: null,         // Tone.GrainPlayer
      shift: null,         // Tone.PitchShift
      vol: null,           // Tone.Volume (output stage)
      playing: false,
      startedAt: 0,        // Tone.context.now() when play started
      offsetAt: 0,         // playback offset at the moment of play
      speed: 1, pitch: 0, volume: 0.9,
      loopArmed: false, loopA: null, loopB: null,
      rafId: null,
    };

    function fmtTime(s) {
      if (!isFinite(s) || s < 0) s = 0;
      var m = Math.floor(s / 60), sec = Math.floor(s % 60);
      return m + ':' + (sec < 10 ? '0' + sec : sec);
    }

    // ── File picker + drag-drop ──────────────────────────────
    dz.addEventListener('click', function () { input.click(); });
    dz.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
    ['dragenter','dragover'].forEach(function (k) {
      dz.addEventListener(k, function (e) { e.preventDefault(); dz.classList.add('is-drag'); });
    });
    ['dragleave','drop'].forEach(function (k) {
      dz.addEventListener(k, function (e) { e.preventDefault(); dz.classList.remove('is-drag'); });
    });
    dz.addEventListener('drop', function (e) {
      var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) loadFile(f);
    });
    input.addEventListener('change', function () { if (input.files && input.files[0]) loadFile(input.files[0]); });

    function loadFile(file) {
      if (!/^audio\//.test(file.type) && !/\.(mp3|wav|m4a|flac|ogg|aac)$/i.test(file.name)) {
        return alert('Please choose an audio file (MP3, WAV, M4A, FLAC, OGG).');
      }
      if (file.size > 50 * 1024 * 1024) {
        return alert('That file is over 50 MB. Please pick something smaller.');
      }
      if (!hasTone()) {
        return alert("The audio engine is still loading. Try again in a second.");
      }

      dz.querySelector('#spDropTitle').textContent = 'Decoding ' + file.name + '…';
      // Tone.context.decodeAudioData accepts an ArrayBuffer
      file.arrayBuffer().then(function (ab) {
        var ctx = Tone.getContext().rawContext;
        return ctx.decodeAudioData(ab);
      }).then(function (buf) {
        teardown();
        state.buffer = buf;

        // Build the Tone graph: GrainPlayer → PitchShift → Volume → out
        state.grain = new Tone.GrainPlayer(buf).set({
          grainSize: 0.2, overlap: 0.05, detune: 0, playbackRate: state.speed,
        });
        state.shift = new Tone.PitchShift({ pitch: state.pitch, windowSize: 0.1, delayTime: 0, feedback: 0 });
        state.vol = new Tone.Volume(Tone.gainToDb(state.volume));
        state.grain.chain(state.shift, state.vol, Tone.getDestination());

        // Reveal player UI
        $('#spName').textContent = file.name.replace(/\.[^.]+$/, '');
        $('#spDuration').textContent = fmtTime(buf.duration) + ' · ' + Math.round(buf.sampleRate / 1000) + ' kHz · ' + (buf.numberOfChannels === 2 ? 'stereo' : 'mono');
        $('#spDurTime').textContent = fmtTime(buf.duration);
        $('#spCurTime').textContent = '0:00';
        $('#spProgress').style.width = '0%';
        player.hidden = false;
        dz.hidden = true;
      }).catch(function (e) {
        dz.querySelector('#spDropTitle').textContent = 'Drop an audio file, or click to choose';
        alert("Couldn't decode that file — try a different format. (" + (e && e.message || e) + ')');
      });
    }

    function teardown() {
      if (state.rafId) cancelAnimationFrame(state.rafId);
      state.rafId = null; state.playing = false;
      if (state.grain) { try { state.grain.stop(); state.grain.dispose(); } catch (e) {} }
      if (state.shift) { try { state.shift.dispose(); } catch (e) {} }
      if (state.vol)   { try { state.vol.dispose();   } catch (e) {} }
      state.grain = state.shift = state.vol = null;
    }

    function getOffset() {
      if (!state.buffer) return 0;
      if (!state.playing) return state.offsetAt;
      // Real-time current offset: where we started + (elapsed × playbackRate)
      var elapsed = Tone.now() - state.startedAt;
      var off = state.offsetAt + elapsed * state.speed;
      return Math.min(state.buffer.duration, Math.max(0, off));
    }

    function play(fromOffset) {
      if (!state.grain || !state.buffer) return;
      if (state.playing) stop();
      var off = (typeof fromOffset === 'number') ? fromOffset : state.offsetAt;
      state.grain.playbackRate = state.speed;
      state.shift.pitch = state.pitch;
      state.grain.start(undefined, off);
      state.startedAt = Tone.now();
      state.offsetAt = off;
      state.playing = true;
      $('#spPlayIcon').className = 'fa-solid fa-pause';
      tickProgress();
    }

    function pause() {
      if (!state.playing) return;
      state.offsetAt = getOffset();
      state.playing = false;
      try { state.grain.stop(); } catch (e) {}
      $('#spPlayIcon').className = 'fa-solid fa-play';
      if (state.rafId) cancelAnimationFrame(state.rafId);
    }

    function stop() { pause(); }

    function tickProgress() {
      var off = getOffset();
      var dur = state.buffer ? state.buffer.duration : 1;
      $('#spProgress').style.width = ((off / dur) * 100) + '%';
      $('#spCurTime').textContent = fmtTime(off);

      // A/B loop enforcement
      if (state.loopArmed && state.loopA != null && state.loopB != null && state.loopB > state.loopA) {
        if (off >= state.loopB) {
          // Jump back to A without a click
          state.offsetAt = state.loopA;
          state.startedAt = Tone.now();
          state.grain.stop();
          state.grain.start(undefined, state.loopA);
        }
      } else if (off >= dur - 0.05) {
        // End of track
        pause();
        state.offsetAt = 0;
        $('#spProgress').style.width = '0%';
        $('#spCurTime').textContent = '0:00';
        return;
      }
      if (state.playing) state.rafId = requestAnimationFrame(tickProgress);
    }

    // ── Transport ────────────────────────────────────────────
    $('#spPlay').addEventListener('click', function () {
      if (!state.buffer) return;
      audio(); // ensure context is running
      state.playing ? pause() : play();
    });
    $('#spRew').addEventListener('click', function () { seekBy(-10); });
    $('#spFwd').addEventListener('click', function () { seekBy(10); });
    function seekBy(delta) {
      if (!state.buffer) return;
      var to = Math.max(0, Math.min(state.buffer.duration - 0.1, getOffset() + delta));
      var wasPlaying = state.playing;
      pause();
      state.offsetAt = to;
      $('#spProgress').style.width = ((to / state.buffer.duration) * 100) + '%';
      $('#spCurTime').textContent = fmtTime(to);
      if (wasPlaying) play(to);
    }

    // Seek by clicking the timeline
    $('#spTimeline').addEventListener('click', function (e) {
      if (!state.buffer) return;
      var r = e.currentTarget.getBoundingClientRect();
      var pct = (e.clientX - r.left) / r.width;
      var to = Math.max(0, Math.min(state.buffer.duration, pct * state.buffer.duration));
      var wasPlaying = state.playing;
      pause();
      state.offsetAt = to;
      $('#spProgress').style.width = ((to / state.buffer.duration) * 100) + '%';
      $('#spCurTime').textContent = fmtTime(to);
      if (wasPlaying) play(to);
    });

    // ── Sliders ──────────────────────────────────────────────
    $('#spSpeed').addEventListener('input', function (e) {
      state.speed = parseFloat(e.target.value);
      $('#spSpeedVal').textContent = state.speed.toFixed(2) + '×';
      if (state.grain) {
        // Re-anchor offset so the on-the-fly speed change doesn't jump the playhead
        if (state.playing) {
          var cur = getOffset();
          state.offsetAt = cur;
          state.startedAt = Tone.now();
        }
        state.grain.playbackRate = state.speed;
      }
    });
    $('#spPitch').addEventListener('input', function (e) {
      state.pitch = parseInt(e.target.value, 10);
      var s = state.pitch === 0 ? '±0' : (state.pitch > 0 ? '+' + state.pitch : state.pitch);
      $('#spPitchVal').textContent = s + ' st';
      if (state.shift) state.shift.pitch = state.pitch;
    });
    $('#spVol').addEventListener('input', function (e) {
      var v = parseInt(e.target.value, 10) / 100;
      state.volume = v;
      $('#spVolVal').textContent = Math.round(v * 100) + '%';
      if (state.vol) state.vol.volume.value = Tone.gainToDb(Math.max(0.0001, v));
    });

    // ── Presets ─────────────────────────────────────────────
    $$('.sp-presets button[data-preset]').forEach(function (b) {
      b.addEventListener('click', function () {
        var v = b.getAttribute('data-preset');
        if (v === 'reset') {
          state.speed = 1; state.pitch = 0;
          $('#spSpeed').value = '1'; $('#spSpeedVal').textContent = '1.00×';
          $('#spPitch').value = '0'; $('#spPitchVal').textContent = '±0 st';
          if (state.grain) state.grain.playbackRate = 1;
          if (state.shift) state.shift.pitch = 0;
          // Also clear A/B loop
          state.loopArmed = false; state.loopA = state.loopB = null;
          $('#spLoopBand').hidden = true;
          $('#spLoopBtn').classList.remove('is-armed', 'is-active');
          $('#spLoopLabel').textContent = 'A–B';
        } else {
          var f = parseFloat(v);
          state.speed = f;
          $('#spSpeed').value = String(f);
          $('#spSpeedVal').textContent = f.toFixed(2) + '×';
          if (state.grain) {
            if (state.playing) { state.offsetAt = getOffset(); state.startedAt = Tone.now(); }
            state.grain.playbackRate = f;
          }
        }
      });
    });

    // ── A/B loop ─────────────────────────────────────────────
    // 1st click sets A at current position, 2nd sets B and arms the loop,
    // 3rd disables. Visual band on the timeline.
    $('#spLoopBtn').addEventListener('click', function () {
      if (!state.buffer) return;
      var cur = getOffset();
      if (state.loopA == null) {
        state.loopA = cur;
        $('#spLoopBtn').classList.add('is-armed');
        $('#spLoopLabel').textContent = 'B at ' + fmtTime(cur);
      } else if (state.loopB == null) {
        if (cur <= state.loopA) state.loopA = Math.max(0, cur - 0.5);  // ensure B>A
        state.loopB = cur;
        state.loopArmed = true;
        $('#spLoopBtn').classList.remove('is-armed');
        $('#spLoopBtn').classList.add('is-active');
        $('#spLoopLabel').textContent = 'Loop on';
        // Render the band
        var dur = state.buffer.duration;
        var band = $('#spLoopBand');
        band.style.left = ((state.loopA / dur) * 100) + '%';
        band.style.right = ((1 - state.loopB / dur) * 100) + '%';
        band.hidden = false;
      } else {
        state.loopArmed = false;
        state.loopA = state.loopB = null;
        $('#spLoopBand').hidden = true;
        $('#spLoopBtn').classList.remove('is-armed', 'is-active');
        $('#spLoopLabel').textContent = 'A–B';
      }
    });

    // ── Clear (load a different track) ───────────────────────
    $('#spClear').addEventListener('click', function () {
      teardown();
      state.buffer = null;
      state.offsetAt = 0; state.speed = 1; state.pitch = 0;
      state.loopA = state.loopB = null; state.loopArmed = false;
      player.hidden = true;
      dz.hidden = false;
      dz.querySelector('#spDropTitle').textContent = 'Drop an audio file, or click to choose';
      input.value = '';
      $('#spSpeed').value = '1'; $('#spSpeedVal').textContent = '1.00×';
      $('#spPitch').value = '0'; $('#spPitchVal').textContent = '±0 st';
      $('#spLoopBand').hidden = true;
      $('#spLoopBtn').classList.remove('is-armed', 'is-active');
      $('#spLoopLabel').textContent = 'A–B';
    });
  })();

  // Expose for debugging.
  window.MAM_TOOLS = { audio: audio, metronome: { start: startMetronome, stop: stopMetronome }, tuner: { start: startTuner, stop: stopTuner } };
})();
