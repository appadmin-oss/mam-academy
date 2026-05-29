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
  var ctx = null;
  function audio() {
    if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  }

  // ── Tab switching ──────────────────────────────────────────────────
  $$('.tools-tabs button').forEach(function (b) {
    b.addEventListener('click', function () {
      var key = b.getAttribute('data-tool');
      $$('.tools-tabs button').forEach(function (x) { x.classList.toggle('active', x === b); x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
      $$('.tool-panel').forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-tool') === key); });
      // Stop sound-producing tools when switching away so they don't haunt the page.
      if (key !== 'metronome') stopMetronome();
      if (key !== 'tuner') stopTuner();
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

  function paintBeatDots() {
    beatsBox.innerHTML = '';
    for (var i = 0; i < metBeats; i++) {
      var d = document.createElement('div');
      d.className = 'met-beat' + (i === 0 ? ' beat-accent' : '');
      beatsBox.appendChild(d);
    }
  }
  paintBeatDots();

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

  function scheduler() {
    var a = audio();
    var lookahead = 0.1; // schedule up to 100ms ahead
    var spb = 60.0 / metBpm / metSub; // seconds per (sub)beat
    while (metNextTime < a.currentTime + lookahead) {
      var onMainBeat = (metBeatIndex % metSub) === 0;
      var mainBeatNum = Math.floor(metBeatIndex / metSub) % metBeats;
      var isAccent = (mainBeatNum === 0) && onMainBeat;
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
    metPlaying = true; metBeatIndex = 0; metNextTime = a.currentTime + 0.06;
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
    metBpm = parseInt(bpmRange.value, 10) || 100;
    bpmVal.textContent = metBpm;
  });
  $('#metBeatsCount').addEventListener('change', function (e) {
    metBeats = parseInt(e.target.value, 10) || 4; paintBeatDots(); metBeatIndex = 0;
  });
  $('#metSubdivision').addEventListener('change', function (e) { metSub = parseInt(e.target.value, 10) || 1; });
  $('#metVolume').addEventListener('input', function (e) { metVol = (parseInt(e.target.value, 10) || 0) / 100; });

  // Tap tempo: average the last 4 taps.
  var tapTimes = [];
  $('#metTap').addEventListener('click', function () {
    var now = performance.now();
    tapTimes.push(now);
    if (tapTimes.length > 4) tapTimes.shift();
    if (tapTimes.length >= 2) {
      var diffs = [];
      for (var i = 1; i < tapTimes.length; i++) diffs.push(tapTimes[i] - tapTimes[i - 1]);
      var avg = diffs.reduce(function (a, b) { return a + b; }, 0) / diffs.length;
      var bpm = Math.round(60000 / avg);
      if (bpm >= 30 && bpm <= 240) {
        metBpm = bpm; bpmRange.value = bpm; bpmVal.textContent = bpm;
      }
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

  function freqToNote(freq) {
    var n = 12 * Math.log2(freq / 440) + 69; // MIDI note number
    var midi = Math.round(n);
    var cents = Math.round((n - midi) * 100);
    return { name: NOTE_NAMES[midi % 12], octave: Math.floor(midi / 12) - 1, cents: cents };
  }
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
    var c = new Array(SIZE).fill(0);
    for (var i = 0; i < SIZE; i++) for (var j = 0; j < SIZE - i; j++) c[i] += buf[j] * buf[j + i];
    var d = 0; while (c[d] > c[d + 1]) d++;
    var maxVal = -1, maxPos = -1;
    for (var i = d; i < SIZE; i++) if (c[i] > maxVal) { maxVal = c[i]; maxPos = i; }
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
  function loopTuner() {
    if (!tunerListening) return;
    tunerAnalyser.getFloatTimeDomainData(tunerBuf);
    var f = detectPitch(tunerBuf, audio().sampleRate);
    if (f > 50 && f < 4500) {
      var n = freqToNote(f);
      $('#tunerNote').textContent = n.name;
      $('#tunerOctave').textContent = n.octave;
      $('#tunerFreq').textContent = f.toFixed(1) + ' Hz';
      // Map -50..+50 cents to 0..100% horizontal position.
      var pct = Math.max(0, Math.min(100, 50 + n.cents));
      var tick = $('#tunerTick');
      tick.style.left = pct + '%';
      tick.classList.remove('in-tune', 'flat', 'sharp');
      if (Math.abs(n.cents) < 6) tick.classList.add('in-tune');
      else if (n.cents < 0) tick.classList.add('flat');
      else tick.classList.add('sharp');
    } else {
      $('#tunerNote').textContent = '—';
      $('#tunerOctave').textContent = '';
      $('#tunerFreq').textContent = 'Listening…';
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
  function tickPomo() {
    pomoState.secs--;
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
      audio(); // warm up so chime is unblocked
      pomoState.intervalId = setInterval(tickPomo, 1000);
    } else clearInterval(pomoState.intervalId);
    paintPomoStart();
  });
  $('#pomoReset').addEventListener('click', function () {
    if (pomoState.intervalId) clearInterval(pomoState.intervalId);
    pomoState = { running: false, mode: 'focus', secs: pomoCfg().focus, block: 1, intervalId: null };
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

  function midiToFreq(m) { return 440 * Math.pow(2, (m - 69) / 12); }
  function playNotes(midis, mode) {
    var a = audio();
    var dur = 0.55, gap = 0.5;
    midis.forEach(function (m, i) {
      var t = a.currentTime + (mode === 'harmonic' ? 0 : i * gap);
      var o = a.createOscillator(), g = a.createGain();
      o.type = 'triangle';
      o.frequency.value = midiToFreq(m);
      g.gain.setValueAtTime(0.0001, t);
      g.gain.exponentialRampToValueAtTime(0.18, t + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
      o.connect(g).connect(a.destination);
      o.start(t); o.stop(t + dur + 0.05);
    });
  }
  function newEarRound() {
    var root = 57 + Math.floor(Math.random() * 12); // A3..G#4
    if (earMode === 'intervals') {
      var iv = INTERVALS[Math.floor(Math.random() * INTERVALS.length)];
      earScore.current = { kind: 'intervals', answer: iv.name, notes: [root, root + iv.semis], mode: 'melodic' };
    } else if (earMode === 'chords') {
      var c = CHORDS[Math.floor(Math.random() * CHORDS.length)];
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
    if (earMode === 'intervals') opts = INTERVALS.map(function (x) { return x.name; });
    else if (earMode === 'chords') opts = CHORDS.map(function (x) { return x.name; });
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
    earScore.total++;
    if (ans === earScore.current.answer) {
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
  paintEarOptions();

  // Expose for debugging.
  window.MAM_TOOLS = { audio: audio, metronome: { start: startMetronome, stop: stopMetronome }, tuner: { start: startTuner, stop: stopTuner } };
})();
