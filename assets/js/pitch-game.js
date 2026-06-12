/* ============================================================
   MAM Academy — Pitch Game ("Sing & Play")  ·  window.MAM_PITCHGAME
   ------------------------------------------------------------
   A real-time intonation + timing practice game for the Practice Studio.

   Pipeline (matches the production architecture):
     mic → getUserMedia{noiseSuppression,echoCancellation,autoGainControl}
         → MediaStreamSource → AnalyserNode (time-domain, NOT FFT magnitude)
         → Pitchy (McLeod/YIN) findPitch → Hz → MIDI(float) → note + cents
         → per-note scoring (pitch 60% · timing 30% · consistency 10%)
         → karaoke canvas + live meter.

   Targets are scales / arpeggios / intervals — pedagogical, language-neutral,
   and original (no songs). A reference tone is synthesised so it "feels like
   real music" and gives the student a pitch to match.

   Self-contained: reuses window.MAM (shared AudioContext) + window.MAM_PITCHY
   (Pitchy 4.x, lazy-loaded by tools.php). Degrades gracefully with no mic /
   blocked permission / Pitchy unavailable.
   ============================================================ */
(function () {
  'use strict';

  var NOTE_NAMES = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
  var A4 = 440;
  var TOL = { beginner: 25, intermediate: 18, expert: 10 }; // ± cents in-tune window

  // ── Melodies: arrays of MIDI notes (60 = C4). Pedagogical patterns only. ──
  var N = { C4: 60, D4: 62, E4: 64, F4: 65, G4: 67, A4: 69, B4: 71, C5: 72, D5: 74, E5: 76 };
  var MELODIES = [
    { id: 'cmaj-up',   name: 'C Major Scale ↑',  notes: [N.C4, N.D4, N.E4, N.F4, N.G4, N.A4, N.B4, N.C5] },
    { id: 'cmaj-down', name: 'C Major Scale ↓',  notes: [N.C5, N.B4, N.A4, N.G4, N.F4, N.E4, N.D4, N.C4] },
    { id: 'arp',       name: 'Major Arpeggio',   notes: [N.C4, N.E4, N.G4, N.C5, N.G4, N.E4, N.C4] },
    { id: 'penta',     name: 'Pentatonic Steps', notes: [N.C4, N.D4, N.E4, N.G4, N.A4, N.C5] },
    { id: 'leaps',     name: 'Octave Leaps',     notes: [N.C4, N.C5, N.D4, N.D5, N.E4, N.E5] }
  ];

  // ── Note math ──────────────────────────────────────────────────────
  function midiToFreq(m) { return A4 * Math.pow(2, (m - 69) / 12); }
  function freqToMidi(f) { return 69 + 12 * Math.log2(f / A4); }
  function midiName(m) { var r = Math.round(m); return NOTE_NAMES[((r % 12) + 12) % 12] + (Math.floor(r / 12) - 1); }
  function clamp(n, lo, hi) { return Math.max(lo, Math.min(hi, n)); }

  // ── State ──────────────────────────────────────────────────────────
  var root, cvs, cx, hud, dom = {};
  var stream = null, ac = null, analyser = null, buf = null, detector = null, srcNode = null;
  var raf = null, running = false, refMuted = false;
  var melody = MELODIES[0], bpm = 90, tol = TOL.beginner;
  var timeline = [], t0 = 0, lenSec = 0, trail = [], reduceMotion = false;

  function audioCtx() {
    if (window.MAM && window.MAM.audio) return window.MAM.audio();
    ac = ac || new (window.AudioContext || window.webkitAudioContext)();
    if (ac.state === 'suspended') ac.resume();
    return ac;
  }

  // ── Build the timeline from the chosen melody + tempo ───────────────
  function buildTimeline() {
    var beat = 60 / bpm;              // seconds per beat
    var dur = beat;                    // one beat per note
    var lead = beat * 4;               // 4-beat count-in
    timeline = melody.notes.map(function (m, i) {
      return {
        midi: m, name: midiName(m),
        start: lead + i * dur, end: lead + i * dur + dur * 0.92,
        // accumulators
        frames: 0, inTune: 0, centsSum: 0, centsSqSum: 0, onset: null, played: false
      };
    });
    lenSec = lead + melody.notes.length * dur + beat; // tail
    var lo = Math.min.apply(null, melody.notes) - 2;
    var hi = Math.max.apply(null, melody.notes) + 2;
    timeline.range = { lo: lo, hi: hi };
  }

  // ── Reference tone (so the student hears the target) ────────────────
  function playTone(midi, when, dur) {
    if (refMuted) return;
    var a = audioCtx();
    var o = a.createOscillator(), g = a.createGain();
    o.type = 'triangle'; o.frequency.value = midiToFreq(midi);
    var t = when, atk = 0.02, rel = 0.12;
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(0.18, t + atk);
    g.gain.setValueAtTime(0.18, t + dur - rel);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g).connect(a.destination);
    o.start(t); o.stop(t + dur + 0.02);
  }
  function tick(when) {
    if (refMuted) return;
    var a = audioCtx(); var o = a.createOscillator(), g = a.createGain();
    o.type = 'square'; o.frequency.value = 1320;
    g.gain.setValueAtTime(0.12, when); g.gain.exponentialRampToValueAtTime(0.0001, when + 0.05);
    o.connect(g).connect(a.destination); o.start(when); o.stop(when + 0.06);
  }
  function scheduleReference() {
    var a = audioCtx(), base = a.currentTime + 0.12, beat = 60 / bpm;
    for (var i = 0; i < 4; i++) tick(base + i * beat);          // count-in
    timeline.forEach(function (n) { playTone(n.midi, base + n.start, (n.end - n.start)); });
    return base; // audio-clock start of the run (count-in begins here)
  }

  // ── Microphone + detector ──────────────────────────────────────────
  function ensureDetector() {
    if (detector) return detector;
    if (!window.MAM_PITCHY) return null;
    detector = window.MAM_PITCHY.PitchDetector.forFloat32Array(analyser.fftSize);
    detector.clarityThreshold = 0.9;
    return detector;
  }
  function startMic() {
    return navigator.mediaDevices.getUserMedia({
      audio: { noiseSuppression: true, echoCancellation: true, autoGainControl: true }
    }).then(function (s) {
      stream = s;
      var a = audioCtx();
      srcNode = a.createMediaStreamSource(s);
      analyser = a.createAnalyser();
      analyser.fftSize = 2048;
      buf = new Float32Array(analyser.fftSize);
      srcNode.connect(analyser);   // analyser is a sink — never routed to output (no feedback)
      detector = null; ensureDetector();
      return true;
    });
  }
  function stopMic() {
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    if (srcNode) { try { srcNode.disconnect(); } catch (e) {} srcNode = null; }
    analyser = null;
  }

  // ── Pitch read for the current frame ────────────────────────────────
  function readPitch() {
    if (!analyser || !window.MAM_PITCHY) return null;
    analyser.getFloatTimeDomainData(buf);
    var d = ensureDetector(); if (!d) return null;
    var out = d.findPitch(buf, audioCtx().sampleRate); // [hz, clarity]
    var hz = out[0], clarity = out[1];
    if (clarity < 0.9 || hz < 70 || hz > 1200) return null;
    var midi = freqToMidi(hz);
    return { hz: hz, midi: midi, clarity: clarity, name: midiName(midi) };
  }

  /* ════════════════════════ Game loop ════════════════════════ */
  function loop() {
    if (!running) return;
    raf = requestAnimationFrame(loop);
    var elapsed = audioCtx().currentTime - t0;
    var p = readPitch();

    // Active target note
    var active = null;
    for (var i = 0; i < timeline.length; i++) {
      var n = timeline[i];
      if (elapsed >= n.start && elapsed < n.end) { active = n; break; }
    }

    // Score the active note from the live pitch
    var liveState = 'idle', liveCents = null;
    if (active && p) {
      var cents = (p.midi - active.midi) * 100;
      liveCents = cents;
      active.frames++;
      active.centsSum += cents; active.centsSqSum += cents * cents;
      if (Math.abs(cents) <= tol) { active.inTune++; liveState = 'in'; }
      else liveState = cents > 0 ? 'sharp' : 'flat';
      if (active.onset === null && Math.abs(cents) <= 60) active.onset = elapsed; // first credible hit
      active.played = true;
    } else if (p) {
      liveState = 'sing';
    }

    // Trail of the singer's pitch
    if (p) { trail.push({ t: elapsed, midi: p.midi, state: liveState }); }
    else { trail.push({ t: elapsed, midi: null }); }
    while (trail.length > 240) trail.shift();

    draw(elapsed, active, p, liveState, liveCents);
    updateHud(active, p, liveState, liveCents);

    if (elapsed >= lenSec) finish();
  }

  /* ════════════════════════ Canvas render ════════════════════════ */
  function noteToY(midi, H) {
    var r = timeline.range, pad = 26;
    return pad + (1 - (midi - r.lo) / (r.hi - r.lo)) * (H - 2 * pad);
  }
  function colorFor(state) {
    return state === 'in' ? '#34D399' : state === 'sharp' ? '#F89B8A' : state === 'flat' ? '#7CC3F0' : '#E8961F';
  }
  function draw(elapsed, active, p, liveState) {
    if (!cx) return;
    var W = cvs.width / (window.devicePixelRatio || 1), H = cvs.height / (window.devicePixelRatio || 1);
    cx.clearRect(0, 0, W, H);
    var nowX = Math.min(140, W * 0.28), pxPerSec = Math.max(70, W / 6);

    // staff guide lines for each natural note in range
    var r = timeline.range;
    cx.font = '11px Inter, sans-serif';
    for (var m = Math.ceil(r.lo); m <= r.hi; m++) {
      var pc = ((m % 12) + 12) % 12, isNat = [0, 2, 4, 5, 7, 9, 11].indexOf(pc) >= 0;
      if (!isNat) continue;
      var y = noteToY(m, H);
      cx.strokeStyle = 'rgba(232,201,160,0.08)'; cx.lineWidth = 1;
      cx.beginPath(); cx.moveTo(0, y); cx.lineTo(W, y); cx.stroke();
      cx.fillStyle = 'rgba(232,201,160,0.34)'; cx.fillText(midiName(m), 4, y - 3);
    }
    // now-line
    cx.strokeStyle = 'rgba(252,198,71,0.55)'; cx.lineWidth = 2;
    cx.beginPath(); cx.moveTo(nowX, 0); cx.lineTo(nowX, H); cx.stroke();

    // target note bars
    timeline.forEach(function (n) {
      var x0 = nowX + (n.start - elapsed) * pxPerSec;
      var w = (n.end - n.start) * pxPerSec;
      if (x0 + w < -20 || x0 > W + 20) return;
      var y = noteToY(n.midi, H), h = 16;
      var isActive = elapsed >= n.start && elapsed < n.end;
      // base bar
      cx.fillStyle = isActive ? 'rgba(252,198,71,0.30)' : 'rgba(232,201,160,0.16)';
      roundRect(x0, y - h / 2, w, h, 7); cx.fill();
      cx.strokeStyle = isActive ? 'rgba(252,198,71,0.85)' : 'rgba(232,201,160,0.32)';
      cx.lineWidth = isActive ? 2 : 1; roundRect(x0, y - h / 2, w, h, 7); cx.stroke();
      // fill the part the singer nailed (green), proportional to in-tune frames
      if (n.frames > 0) {
        var got = clamp(n.inTune / n.frames, 0, 1);
        cx.fillStyle = 'rgba(52,211,153,0.55)';
        roundRect(x0, y - h / 2, w * got, h, 7); cx.fill();
      }
      cx.fillStyle = 'rgba(255,255,255,0.7)'; cx.font = '10px Inter, sans-serif';
      cx.fillText(n.name, x0 + 3, y - h / 2 - 4);
    });

    // singer trail
    cx.lineWidth = 3; cx.beginPath(); var started = false;
    trail.forEach(function (pt) {
      if (pt.midi === null) { started = false; return; }
      var x = nowX + (pt.t - elapsed) * pxPerSec, y = noteToY(pt.midi, H);
      if (!started) { cx.moveTo(x, y); started = true; } else cx.lineTo(x, y);
    });
    cx.strokeStyle = 'rgba(252,198,71,0.45)'; cx.stroke();

    // live singer dot at the now-line
    if (p) {
      var yy = noteToY(p.midi, H);
      cx.fillStyle = colorFor(liveState);
      cx.beginPath(); cx.arc(nowX, yy, 9, 0, Math.PI * 2); cx.fill();
      cx.globalAlpha = 0.25; cx.beginPath(); cx.arc(nowX, yy, 18, 0, Math.PI * 2); cx.fill(); cx.globalAlpha = 1;
    }
  }
  function roundRect(x, y, w, h, r) {
    r = Math.min(r, Math.abs(w) / 2, h / 2);
    cx.beginPath();
    cx.moveTo(x + r, y); cx.arcTo(x + w, y, x + w, y + h, r); cx.arcTo(x + w, y + h, x, y + h, r);
    cx.arcTo(x, y + h, x, y, r); cx.arcTo(x, y, x + w, y, r); cx.closePath();
  }

  /* ════════════════════════ HUD ════════════════════════ */
  function updateHud(active, p, state, cents) {
    if (!dom.target) return;
    dom.target.textContent = active ? active.name : '—';
    dom.you.textContent = p ? p.name : '—';
    dom.you.style.color = colorFor(state);
    var label = !p ? 'Sing or play a note' : !active ? 'Get ready…' :
      state === 'in' ? 'Perfect ✓' : state === 'sharp' ? 'A touch high ↓' : state === 'flat' ? 'A touch low ↑' : 'Hold the pitch';
    dom.verdict.textContent = label;
    dom.verdict.className = 'pg-verdict ' + (state === 'in' ? 'good' : (state === 'sharp' || state === 'flat') ? 'off' : '');
    if (cents != null && active) {
      var c = Math.round(cents);
      dom.cents.textContent = (c > 0 ? '+' : '') + c + ' ¢';
      var pct = clamp(50 + cents / 2, 2, 98); // -100..100 → 0..100
      dom.needle.style.left = pct + '%';
      dom.needle.className = 'pg-needle ' + (state === 'in' ? 'in' : state === 'sharp' ? 'sharp' : 'flat');
    } else { dom.cents.textContent = '—'; }
    // live running score
    var sc = scoreSoFar();
    dom.score.textContent = sc + '%';
  }

  function scoreSoFar() {
    var sum = 0, k = 0;
    timeline.forEach(function (n) { if (n.frames > 0) { sum += clamp(n.inTune / n.frames, 0, 1); k++; } });
    return k ? Math.round((sum / k) * 100) : 0;
  }

  /* ════════════════════════ Scoring ════════════════════════ */
  function finalScore() {
    var beat = 60 / bpm, rows = [], pSum = 0, tSum = 0, cSum = 0, scored = 0;
    timeline.forEach(function (n) {
      var pitch = 0, timing = 0, consistency = 0;
      if (n.frames > 0) {
        pitch = clamp(n.inTune / n.frames, 0, 1);
        // timing: how close the credible onset was to the note start (within a beat).
        timing = n.onset === null ? 0 : clamp(1 - Math.abs(n.onset - n.start) / (beat * 0.5), 0, 1);
        // consistency: tight cents spread → high. std-dev of cents over the note.
        var mean = n.centsSum / n.frames;
        var variance = Math.max(0, n.centsSqSum / n.frames - mean * mean);
        var sd = Math.sqrt(variance);
        consistency = clamp(1 - sd / 60, 0, 1);
      }
      var note = 0.6 * pitch + 0.3 * timing + 0.1 * consistency;
      pSum += pitch; tSum += timing; cSum += consistency; scored++;
      rows.push({ name: n.name, pitch: pitch, timing: timing, consistency: consistency, note: note, sung: n.frames > 0 });
    });
    var nTot = timeline.length || 1;
    return {
      final: Math.round((pSum * 0.6 + tSum * 0.3 + cSum * 0.1) / nTot * 100),
      pitch: Math.round(pSum / nTot * 100),
      timing: Math.round(tSum / nTot * 100),
      consistency: Math.round(cSum / nTot * 100),
      rows: rows
    };
  }

  /* ════════════════════════ Flow ════════════════════════ */
  function start() {
    if (running) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { setStatus('Microphone not supported in this browser.', true); return; }
    setStatus('Requesting microphone…');
    dom.start.disabled = true;
    startMic().then(function () {
      if (!window.MAM_PITCHY) { // wait briefly for the lazy Pitchy import
        return new Promise(function (res) {
          var done = false, t = setTimeout(function () { if (!done) { done = true; res(); } }, 2500);
          window.addEventListener('mam:pitchy-ready', function () { if (!done) { done = true; clearTimeout(t); res(); } }, { once: true });
        });
      }
    }).then(function () {
      buildTimeline(); trail = [];
      ensureDetector();
      var base = scheduleReference();
      t0 = base; running = true;
      dom.start.disabled = false;
      root.classList.add('pg-running');
      setStatus(window.MAM_PITCHY ? 'Listen to the count-in, then match each note.' : 'Pitch engine still loading — detection may be limited.', !window.MAM_PITCHY);
      cancelAnimationFrame(raf); raf = requestAnimationFrame(loop);
    }).catch(function (err) {
      dom.start.disabled = false;
      var msg = (err && err.name === 'NotAllowedError') ? 'Microphone blocked. Allow mic access in your browser and try again.'
        : 'Could not start the microphone (' + ((err && err.name) || 'error') + ').';
      setStatus(msg, true);
    });
  }
  function stop(silent) {
    running = false; cancelAnimationFrame(raf); raf = null;
    stopMic(); root.classList.remove('pg-running');
    if (!silent) setStatus('Stopped.');
  }
  function finish() {
    running = false; cancelAnimationFrame(raf); raf = null; stopMic();
    root.classList.remove('pg-running');
    var s = finalScore();
    var stars = s.final >= 90 ? 3 : s.final >= 70 ? 2 : s.final >= 45 ? 1 : 0;
    var starHtml = '★★★☆☆☆'.slice(3 - stars, 6 - stars); // not used; build below
    starHtml = '';
    for (var i = 0; i < 3; i++) starHtml += '<span class="pg-star ' + (i < stars ? 'on' : '') + '">★</span>';
    var bars = s.rows.map(function (r) {
      return '<div class="pg-rowline"><span>' + r.name + '</span>'
        + '<span class="pg-bar"><i style="width:' + Math.round(r.note * 100) + '%"></i></span>'
        + '<span>' + (r.sung ? Math.round(r.note * 100) + '%' : '—') + '</span></div>';
    }).join('');
    dom.results.innerHTML =
      '<div class="pg-result-card">'
      + '<div class="pg-stars">' + starHtml + '</div>'
      + '<div class="pg-final">' + s.final + '<small>%</small></div>'
      + '<div class="pg-final-label">' + melody.name + '</div>'
      + '<div class="pg-breakdown">'
      +   '<div><b>' + s.pitch + '%</b><span>Pitch</span></div>'
      +   '<div><b>' + s.timing + '%</b><span>Timing</span></div>'
      +   '<div><b>' + s.consistency + '%</b><span>Consistency</span></div>'
      + '</div>'
      + '<div class="pg-rows">' + bars + '</div>'
      + '<button class="pg-btn pg-again" type="button"><i class="fa-solid fa-rotate-right"></i> Try again</button>'
      + '</div>';
    dom.results.classList.add('shown');
    var again = dom.results.querySelector('.pg-again');
    if (again) again.addEventListener('click', function () { dom.results.classList.remove('shown'); start(); });
    if (window.MAM_STUDIO && window.MAM_STUDIO.logScore) { try { window.MAM_STUDIO.logScore('pitch', s.final); } catch (e) {} }
  }

  function setStatus(msg, warn) {
    if (!dom.status) return;
    dom.status.textContent = msg;
    dom.status.classList.toggle('warn', !!warn);
  }

  /* ════════════════════════ UI ════════════════════════ */
  function build() {
    root.innerHTML =
      '<div class="pg-controls">'
      +   '<label class="pg-field">Exercise<select id="pgMelody"></select></label>'
      +   '<label class="pg-field">Tempo <span id="pgBpmVal">' + bpm + '</span> BPM<input id="pgBpm" type="range" min="50" max="140" step="2" value="' + bpm + '"></label>'
      +   '<label class="pg-field">Difficulty<select id="pgDiff"><option value="beginner">Beginner · ±25¢</option><option value="intermediate">Intermediate · ±18¢</option><option value="expert">Expert · ±10¢</option></select></label>'
      +   '<label class="pg-check"><input type="checkbox" id="pgRef" checked> Reference tone</label>'
      + '</div>'
      + '<div class="pg-stage">'
      +   '<canvas id="pgCanvas"></canvas>'
      +   '<div class="pg-results" id="pgResults"></div>'
      + '</div>'
      + '<div class="pg-meter">'
      +   '<div class="pg-readout"><div class="pg-r"><span class="pg-r-l">Target</span><b id="pgTarget">—</b></div>'
      +     '<div class="pg-r"><span class="pg-r-l">You</span><b id="pgYou">—</b></div>'
      +     '<div class="pg-r"><span class="pg-r-l">Score</span><b id="pgScore">0%</b></div></div>'
      +   '<div class="pg-needle-track"><div class="pg-needle-center"></div><div class="pg-needle" id="pgNeedle"></div>'
      +     '<span class="pg-needle-lab pg-flat">flat</span><span class="pg-needle-lab pg-sharp">sharp</span></div>'
      +   '<div class="pg-verdict" id="pgVerdict">Press Start, then sing or play each note as it reaches the line.</div>'
      +   '<div class="pg-cents" id="pgCents">—</div>'
      + '</div>'
      + '<div class="pg-actions">'
      +   '<button class="pg-btn pg-primary" id="pgStart" type="button"><i class="fa-solid fa-microphone"></i> Start</button>'
      +   '<button class="pg-btn" id="pgListen" type="button"><i class="fa-solid fa-volume-high"></i> Listen</button>'
      +   '<button class="pg-btn" id="pgStop" type="button"><i class="fa-solid fa-stop"></i> Stop</button>'
      +   '<span class="pg-status" id="pgStatus">Your audio never leaves your device — nothing is recorded.</span>'
      + '</div>';

    var sel = root.querySelector('#pgMelody');
    sel.innerHTML = MELODIES.map(function (m) { return '<option value="' + m.id + '">' + m.name + '</option>'; }).join('');
    cvs = root.querySelector('#pgCanvas'); cx = cvs.getContext('2d');
    dom = {
      target: root.querySelector('#pgTarget'), you: root.querySelector('#pgYou'), score: root.querySelector('#pgScore'),
      needle: root.querySelector('#pgNeedle'), verdict: root.querySelector('#pgVerdict'), cents: root.querySelector('#pgCents'),
      status: root.querySelector('#pgStatus'), start: root.querySelector('#pgStart'), results: root.querySelector('#pgResults')
    };
    resize();
    window.addEventListener('resize', resize);

    sel.addEventListener('change', function () { melody = MELODIES.find(function (m) { return m.id === sel.value; }) || MELODIES[0]; });
    var bpmEl = root.querySelector('#pgBpm');
    bpmEl.addEventListener('input', function () { bpm = parseInt(bpmEl.value, 10) || 90; root.querySelector('#pgBpmVal').textContent = bpm; });
    root.querySelector('#pgDiff').addEventListener('change', function (e) { tol = TOL[e.target.value] || TOL.beginner; });
    root.querySelector('#pgRef').addEventListener('change', function (e) { refMuted = !e.target.checked; });
    dom.start.addEventListener('click', start);
    root.querySelector('#pgStop').addEventListener('click', function () { stop(); dom.results.classList.remove('shown'); });
    root.querySelector('#pgListen').addEventListener('click', function () { buildTimeline(); scheduleReference(); setStatus('Playing the reference melody…'); });
  }

  function resize() {
    if (!cvs) return;
    var dpr = window.devicePixelRatio || 1;
    var w = cvs.parentElement.clientWidth, h = clamp(Math.round(w * 0.5), 200, 340);
    cvs.style.width = w + 'px'; cvs.style.height = h + 'px';
    cvs.width = w * dpr; cvs.height = h * dpr; cx.setTransform(dpr, 0, 0, dpr, 0, 0);
    if (!running) { buildTimeline(); draw(0, null, null, 'idle'); }
  }

  function init() {
    root = document.getElementById('pitchGame');
    if (!root || root.__pg) return;
    root.__pg = 1;
    try {
      reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      build();
    } catch (e) { if (window.console) console.error('[MAM pitch-game]', e); }
    // Stop the mic when the user switches away from this tool.
    document.querySelectorAll('.tools-tabs button').forEach(function (b) {
      b.addEventListener('click', function () { if (b.getAttribute('data-tool') !== 'pitchgame' && running) stop(true); });
    });
  }

  window.MAM_PITCHGAME = { init: init };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
