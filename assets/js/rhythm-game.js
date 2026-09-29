/* ============================================================
   MAM Academy — Rhythm Trainer  ·  window.MAM_RHYTHM
   ------------------------------------------------------------
   Student-only, Studio-gated rhythm-reading trainer. Professional, not "just a
   game": endless PROCEDURAL levels that auto-advance and never repeat, graded
   to the Trinity College London standard (Distinction/Merit/Pass bands, mark
   out of 100), with a points + badge reward system.

   Notation   Real engraved music-font glyphs (Noto Music) from the Unicode
              Musical Symbols block — value-coloured cards (quarters pink,
              eighths blue, sixteenth figures gold, dotted magenta, triplets
              mint, long notes green/indigo).
   Procedural Each level is generated as 4 bars of 4/4 (every bar sums to 4
              beats) from a difficulty-scaled figure pool: faster tempo and
              harder figures (sixteenths → composites → dotted/syncopation →
              triplets) as you climb. A per-session salt means no two playthroughs
              feel the same; generation is seeded so a built board is reproducible.
   Accuracy   AudioContext-clock timing, output-latency compensation, global
              nearest-onset matching across card boundaries, per-tap Perfect/
              Early/Late feedback with the ms offset + counting syllable.
   Grading    Trinity bands: Distinction ≥ 87, Merit ≥ 75, Pass ≥ 60. A pass
              auto-advances to the next (harder) level — endless; each level
              still ENDS with a graded result.
   Rewards    Points (mark × difficulty multiplier + band bonus + on-time bonus),
              a running total, a Distinction streak multiplier, and unlockable
              badges — all persisted per signed-in student.
   Audio      Each run plays through its own gain bus and tracks every scheduled
              source, so Stop is instant.
   Input      TAP (board / Space) or PLAY (mic): clap, tap, sing or play any
              instrument — spectral-flux onset detection scores your TIMING, not
              the notes. echoCancellation/NS/AGC are requested AND the engine
              stays silent through the scored bars (only the count-in sounds), so
              the app's own audio can never affect the rhythmic analysis. Both
              inputs score through one Trinity engine; mic mode emits no confirm
              tone (it would feed back).
   ============================================================ */
(function () {
  'use strict';

  /* ── Notation glyphs — real music-font codepoints (escaped: the Musical
        Symbols block lives outside the BMP) ── */
  var GL = {
    quarter:    '\uD834\uDD5F',  /* 𝅘𝅥  quarter note */
    half:       '\uD834\uDD5E',  /* 𝅗𝅥  half note */
    eighths:    '\u266B',  /* ♫  beamed eighth pair */
    sixteenths: '\u266C\u266C',  /* ♬♬ four sixteenths */
    ess:        '\uD834\uDD60\u2009\u266C',  /* 𝅘𝅥𝅮 ♬ eighth + two sixteenths */
    sse:        '\u266C\u2009\uD834\uDD60',  /* ♬ 𝅘𝅥𝅮 two sixteenths + eighth */
    dotted:     '\uD834\uDD60\uD834\uDD6D\u2009\uD834\uDD61',  /* dotted eighth + sixteenth */
    sed:        '\uD834\uDD61\u2009\uD834\uDD60\uD834\uDD6D',  /* sixteenth + dotted eighth */
    restEighth: '\uD834\uDD3E\u2009\uD834\uDD60',  /* 𝄾 eighth rest + eighth */
    restSixt:   '\uD834\uDD3E\u2009\u266C',  /* 𝄾 eighth rest + two sixteenths */
    restQuarter:'\uD834\uDD3D',  /* 𝄽  quarter rest */
    triplet:    '\uD834\uDD60\uD834\uDD60\uD834\uDD60',  /* eighth-note triplet */
    dqe:        '\uD834\uDD5F\uD834\uDD6D\u2009\uD834\uDD60',  /* dotted quarter + eighth */
    bpmNote:    '\u2669'  /* ♩  metronome mark */
  };

  /* Figure vocabulary. beats = grid width (wide = 2). onsets = positions within
     the card. tier drives how late in the difficulty curve a figure appears.
     w = base generation weight. */
  var PAT = {
    q:    { beats: 1, onsets: [0],               glyph: GL.quarter,     small: 0, color: '#F4577E', tier: 1, w: 10, name: 'Quarter note',                  count: '1',       syls: ['the downbeat'], tip: 'Land exactly with the click — one full, settled beat.' },
    ee:   { beats: 1, onsets: [0, 0.5],          glyph: GL.eighths,     small: 0, color: '#5B9BE8', tier: 1, w: 9,  name: 'Two eighth notes',              count: '1 &',     syls: ['the downbeat', 'the “&”'], tip: 'Split the beat exactly in half — say “1 &” out loud.' },
    qr:   { beats: 1, onsets: [],                glyph: GL.restQuarter, small: 0, color: '#F4577E', tier: 1, w: 5,  rest: 1, name: 'Quarter rest — stay silent', count: '(rest)', syls: [], tip: 'A rest is music too — stay perfectly still for the whole beat.' },
    h:    { beats: 2, onsets: [0],               glyph: GL.half,        small: 0, color: '#42C58B', tier: 1, w: 4,  wide: 1, name: 'Half note — two beats',  count: '1 (hold 2)', syls: ['the downbeat'], tip: 'One tap, two beats — tap the downbeat, then hold the silence alive.' },
    ssss: { beats: 1, onsets: [0, .25, .5, .75], glyph: GL.sixteenths,  small: 1, color: '#F2B632', tier: 2, w: 6,  name: 'Four sixteenth notes',          count: '1 e & a', syls: ['the downbeat', 'the “e”', 'the “&”', 'the “a”'], tip: 'Four even taps per beat — “1 e & a”, hand relaxed.' },
    res:  { beats: 1, onsets: [0.5],             glyph: GL.restEighth,  small: 1, color: '#5B9BE8', tier: 2, w: 5,  rest: 1, name: 'Eighth rest then eighth note', count: '(1) &', syls: ['the “&”'], tip: 'Silence first — feel the downbeat, tap only the “&”.' },
    ess:  { beats: 1, onsets: [0, .5, .75],      glyph: GL.ess,         small: 1, color: '#F2B632', tier: 3, w: 5,  name: 'Eighth then two sixteenths',    count: '1 & a',   syls: ['the downbeat', 'the “&”', 'the “a”'], tip: 'Long–short–short: the sixteenths share the back half — “1 & a”.' },
    sse:  { beats: 1, onsets: [0, .25, .5],      glyph: GL.sse,         small: 1, color: '#F2B632', tier: 3, w: 5,  name: 'Two sixteenths then an eighth', count: '1 e &',   syls: ['the downbeat', 'the “e”', 'the “&”'], tip: 'Short–short–long: “1 e &” — land the eighth firmly and hold.' },
    rss:  { beats: 1, onsets: [0.5, 0.75],       glyph: GL.restSixt,    small: 1, color: '#F2B632', tier: 3, w: 4,  rest: 1, name: 'Eighth rest then two sixteenths', count: '(1) & a', syls: ['the “&”', 'the “a”'], tip: 'Let the rest breathe, then two quick sixteenths on “& a”.' },
    des:  { beats: 1, onsets: [0, 0.75],         glyph: GL.dotted,      small: 1, color: '#C44DD6', tier: 4, w: 5,  name: 'Dotted eighth then sixteenth',  count: '1 … a',   syls: ['the downbeat', 'the “a”'], tip: 'Long–short: hold the dot, the sixteenth lands on the late “a”.' },
    sed:  { beats: 1, onsets: [0, 0.25],         glyph: GL.sed,         small: 1, color: '#C44DD6', tier: 4, w: 4,  name: 'Sixteenth then dotted eighth',  count: '1 e',     syls: ['the downbeat', 'the “e”'], tip: 'Short–long: snap the sixteenth on “e”, then hold through “& a”.' },
    dqe:  { beats: 2, onsets: [0, 1.5],          glyph: GL.dqe,         small: 1, color: '#7C7DF5', tier: 4, w: 3,  wide: 1, name: 'Dotted quarter then eighth', count: '1 … (2)&', syls: ['the downbeat', 'the “&” of beat 2'], tip: 'The dotted quarter holds a beat and a half — the eighth lands on the “&” of beat 2.' },
    trip: { beats: 1, onsets: [0, 1/3, 2/3],     glyph: GL.triplet,     small: 1, color: '#3ECFA0', tier: 5, w: 5,  triplet: 1, name: 'Eighth-note triplet',     count: '1-la-li', syls: ['the downbeat', '“la” (2nd of the triplet)', '“li” (3rd of the triplet)'], tip: 'Three perfectly even notes in one beat — “1-la-li”, no swing.' }
  };

  var HIT_TOL = 0.18;   // fraction of a beat that still counts as on-time
  var PERFECT = 0.07;   // fraction of a beat rated "Perfect"
  var BARS = 4, BEATS_PER_BAR = 4;

  /* ── Trinity College London grading bands (mark out of 100) ── */
  function trinity(mark) {
    if (mark >= 87) return { band: 'Distinction', stars: 3, pass: true, bonus: 60, color: '#FCC647' };
    if (mark >= 75) return { band: 'Merit',       stars: 2, pass: true, bonus: 30, color: '#42C58B' };
    if (mark >= 60) return { band: 'Pass',        stars: 1, pass: true, bonus: 12, color: '#5B9BE8' };
    return { band: 'Developing', stars: 0, pass: false, bonus: 0, color: '#C2453A' };
  }

  /* ── Seeded RNG (mulberry32) so a built board is reproducible in tests while
        the per-session salt keeps live play fresh. ── */
  function mulberry32(a) { return function () { a |= 0; a = a + 0x6D2B79F5 | 0; var t = Math.imul(a ^ a >>> 15, 1 | a); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; }; }
  var sessionSalt = (Math.random() * 0xFFFFFFFF) >>> 0;
  function clamp(n, lo, hi) { return Math.max(lo, Math.min(hi, n)); }

  /* ── Procedural level generator ── */
  function poolFor(D) {
    var p = ['q', 'ee', 'qr', 'h'];
    if (D >= 2) p.push('ssss', 'res');
    if (D >= 3) p.push('ess', 'sse', 'rss');
    if (D >= 4) p.push('des', 'sed', 'dqe');
    if (D >= 5) p.push('trip');
    return p;
  }
  function focusFor(D) {
    if (D <= 1) return 'Quarters & eighths';
    if (D === 2) return 'Sixteenths & rests';
    if (D === 3) return 'Composite sixteenths';
    if (D === 4) return 'Dotted figures & syncopation';
    if (D === 5) return 'Triplets';
    if (D <= 7) return 'Mixed figures';
    return 'Mastery — everything in play';
  }
  // Weight a candidate by difficulty (harder figures rise with D) with musical
  // guards: no figure 3× in a row, ≤1 wide per bar, never two rests in a row,
  // never a rest as the very first card of the level.
  function pickFigure(cands, D, rng, bar, isFirst) {
    var weights = cands.map(function (id) {
      var p = PAT[id], w = p.w;
      if (p.tier >= 2) w *= 1 + Math.max(0, D - p.tier + 1) * 0.18; // newer figures appear more as you climb
      // discourage triple-repeats
      var n = bar.length;
      if (n >= 2 && bar[n - 1] === id && bar[n - 2] === id) w *= 0.04;
      if (p.wide && bar.some(function (x) { return PAT[x].wide; })) w = 0; // ≤1 wide/bar
      if (p.rest) {
        if (isFirst && n === 0) w = 0;                               // no opening rest
        if (n && PAT[bar[n - 1]].rest) w = 0;                        // no back-to-back rests
      }
      return w;
    });
    var total = weights.reduce(function (a, b) { return a + b; }, 0);
    if (total <= 0) return cands[Math.floor(rng() * cands.length)] || 'q';
    var r = rng() * total;
    for (var i = 0; i < cands.length; i++) { r -= weights[i]; if (r <= 0) return cands[i]; }
    return cands[cands.length - 1];
  }
  function genLevel(D) {
    var rng = mulberry32((sessionSalt ^ Math.imul(D, 0x9E3779B1)) >>> 0);
    var bpm = Math.min(170, 88 + (D - 1) * 5);
    var pool = poolFor(D), cards = [], first = true;
    for (var bar = 0; bar < BARS; bar++) {
      var rem = BEATS_PER_BAR, barCards = [], guard = 0;
      while (rem > 0 && guard++ < 40) {
        var cands = pool.filter(function (id) { return PAT[id].beats <= rem; });
        var pick = pickFigure(cands, D, rng, barCards, first);
        barCards.push(pick); rem -= PAT[pick].beats; first = false;
      }
      cards = cards.concat(barCards);
    }
    return { difficulty: D, name: 'Level ' + D, focus: focusFor(D), bpm: bpm, cards: cards };
  }

  /* ── Per-student progression persistence ── */
  function ns() { var a = (window.MAM_AUTH && window.MAM_AUTH.cached && window.MAM_AUTH.cached()); return (a && a.user && a.user.email ? a.user.email : 'guest').toLowerCase().replace(/[^a-z0-9@._-]/g, '') || 'guest'; }
  function pkey() { return 'mam.rhythm.' + ns(); }
  function loadProg() {
    try { var v = JSON.parse(localStorage.getItem(pkey())); if (v && typeof v === 'object') return v; } catch (e) {}
    return { points: 0, maxDifficulty: 1, badges: [], distinctionStreak: 0, levelsCleared: 0 };
  }
  function saveProg(p) { try { localStorage.setItem(pkey(), JSON.stringify(p)); } catch (e) {} }

  var BADGES = [
    { id: 'first-pass',     icon: '🎯', name: 'On the board',     test: function (p, c) { return c.pass; } },
    { id: 'first-dist',     icon: '🌟', name: 'First Distinction', test: function (p, c) { return c.t.band === 'Distinction'; } },
    { id: 'streak-3',       icon: '🔥', name: 'Hot streak',        test: function (p) { return p.distinctionStreak >= 3; } },
    { id: 'sixteenth',      icon: '⚡', name: 'Sixteenth smith',   test: function (p, c) { return c.t.pass && c.has.ssss; } },
    { id: 'triplet',        icon: '🌀', name: 'Triplet tamer',     test: function (p, c) { return c.t.pass && c.has.trip; } },
    { id: 'dotted',         icon: '🎼', name: 'Dotted & synced',   test: function (p, c) { return c.t.pass && (c.has.des || c.has.sed || c.has.dqe); } },
    { id: 'level-10',       icon: '🏅', name: 'Double digits',     test: function (p) { return p.maxDifficulty >= 10; } },
    { id: 'points-1k',      icon: '💎', name: '1,000 club',        test: function (p) { return p.points >= 1000; } }
  ];

  var root, dom = {}, level = null, state = 'idle', mode = 'play';
  var raf = null, t0 = 0, beat = 0.6, curCard = -1, difficulty = 1;
  var bus = null, srcs = [], onsets = [], strays = [], cardDone = [];
  var starts = [], beatCard = [], totalBeats = 0;

  // ── Input mode: tap (default) or mic "Play" ──
  // In mic mode the student claps/taps/sings/plays any instrument; we detect
  // onset TIMING (not pitch) and score it through the same engine as taps.
  var inputMode = 'tap';
  var micStream = null, micSrc = null, micAnalyser = null, micFreqBuf = null, micTimeBuf = null, detector = null;
  var MIC_LATENCY = 0.03;   // analyser-frame + input-latency estimate (s)

  /* Spectral-flux onset detector with an adaptive threshold + refractory.
     Pure of Web Audio so it's unit-testable: feed it magnitude frames. */
  function makeOnsetDetector() {
    var prev = null, hist = [], last = -1e9;
    var REFRACTORY = 0.08;            // s — ignore re-triggers within 80 ms
    return {
      reset: function () { prev = null; hist = []; last = -1e9; },
      // mag: Float array of bin magnitudes; t: seconds. Returns true on an onset.
      process: function (mag, t) {
        var flux = 0, i;
        if (prev) for (i = 0; i < mag.length; i++) { var d = mag[i] - prev[i]; if (d > 0) flux += d; }
        if (!prev) prev = new Float32Array(mag.length);
        for (i = 0; i < mag.length; i++) prev[i] = mag[i];
        hist.push(flux); if (hist.length > 43) hist.shift();   // ~0.7 s window @60fps
        var m = 0, n = hist.length; for (i = 0; i < n; i++) m += hist[i]; m /= n;
        var v = 0; for (i = 0; i < n; i++) { var dd = hist[i] - m; v += dd * dd; } v = Math.sqrt(v / n);
        var thr = m + 1.8 * v + 1e-4;
        if (n > 8 && flux > thr && (t - last) > REFRACTORY) { last = t; return true; }
        return false;
      }
    };
  }

  function ac() { return (window.MAM && window.MAM.audio) ? window.MAM.audio() : (window.__rgAC || (window.__rgAC = new (window.AudioContext || window.webkitAudioContext)())); }
  function latency(a) { return a.outputLatency || a.baseLatency || 0; }
  function out(a) { return bus || a.destination; }
  function track(o) { srcs.push(o); return o; }
  function click(when, accent) {
    var a = ac(), o = track(a.createOscillator()), g = a.createGain();
    o.type = 'square'; o.frequency.value = accent ? 2000 : 1400;
    g.gain.setValueAtTime(accent ? 0.22 : 0.12, when);
    g.gain.exponentialRampToValueAtTime(0.0001, when + 0.05);
    o.connect(g).connect(out(a)); o.start(when); o.stop(when + 0.06);
  }
  function blip(when, freq) {
    var a = ac(), o = track(a.createOscillator()), g = a.createGain();
    o.type = 'triangle'; o.frequency.value = freq || 660;
    g.gain.setValueAtTime(0.0001, when); g.gain.exponentialRampToValueAtTime(0.2, when + 0.008);
    g.gain.exponentialRampToValueAtTime(0.0001, when + 0.14);
    o.connect(g).connect(out(a)); o.start(when); o.stop(when + 0.16);
  }
  function killAudio() {
    srcs.forEach(function (s) { try { s.stop(0); } catch (e) {} });
    srcs = [];
    if (bus) { try { bus.disconnect(); } catch (e) {} bus = null; }
  }

  /* ── Microphone capture for "Play" mode ──
     echoCancellation/noiseSuppression/autoGainControl are requested so the
     device cancels the app's own audio out of the mic; combined with the
     engine going SILENT during the scored bars (only the count-in clicks,
     which land before scoring), the app's sound can never affect accuracy.
     The analyser is a pure sink — never routed to destination, so there is
     no feedback loop. */
  function ensureMic() {
    if (micStream && micAnalyser) return Promise.resolve(true);
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return Promise.reject(new Error('no_mic_api'));
    return navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true }
    }).then(function (stream) {
      var a = ac();
      micStream = stream;
      micSrc = a.createMediaStreamSource(stream);
      micAnalyser = a.createAnalyser();
      micAnalyser.fftSize = 1024;
      micAnalyser.smoothingTimeConstant = 0;
      micFreqBuf = new Float32Array(micAnalyser.frequencyBinCount);
      micTimeBuf = new Float32Array(micAnalyser.fftSize);
      micSrc.connect(micAnalyser);   // sink only — never to destination
      return true;
    });
  }
  function stopMic() {
    if (micStream) { try { micStream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {} micStream = null; }
    if (micSrc) { try { micSrc.disconnect(); } catch (e) {} micSrc = null; }
    micAnalyser = null; micFreqBuf = micTimeBuf = null;
  }
  // Called each animation frame while playing in mic mode: detect an onset and
  // update the live input meter. dB → linear magnitudes feed the flux detector.
  function micFrame(pos) {
    if (!micAnalyser || !detector) return;
    micAnalyser.getFloatFrequencyData(micFreqBuf);
    var mag = micFreqBuf, lin = new Float32Array(mag.length);
    for (var i = 0; i < mag.length; i++) lin[i] = Math.pow(10, mag[i] / 20); // dBFS → amplitude
    // Live level meter (RMS of time-domain).
    micAnalyser.getFloatTimeDomainData(micTimeBuf);
    var rms = 0; for (var j = 0; j < micTimeBuf.length; j++) rms += micTimeBuf[j] * micTimeBuf[j];
    rms = Math.sqrt(rms / micTimeBuf.length);
    if (dom.meterFill) dom.meterFill.style.width = Math.min(100, Math.round(rms * 320)) + '%';
    // Only score within the playing window (count-in energy is excluded by pos<0).
    if (pos < -HIT_TOL) return;
    if (detector.process(lin, ac().currentTime)) {
      registerHit((ac().currentTime - MIC_LATENCY - t0) / beat, true);
    }
  }

  /* ════════════════════ Board ════════════════════ */
  function noteHtml(p) {
    return '<span class="rg-note' + (p.small ? ' sm' : '') + '" aria-hidden="true">'
      + (p.triplet ? '<i class="rg-trip3">3</i>' : '') + p.glyph + '</span>';
  }
  function buildBoard() {
    starts = []; beatCard = []; totalBeats = 0;
    level.cards.forEach(function (id, k) {
      starts[k] = totalBeats;
      for (var b = 0; b < PAT[id].beats; b++) beatCard[totalBeats + b] = k;
      totalBeats += PAT[id].beats;
    });
    var cards = level.cards.map(function (id, i) {
      var p = PAT[id];
      return '<button class="rg-card' + (p.wide ? ' rg-wide' : '') + '" data-i="' + i + '" type="button" aria-label="Card ' + (i + 1) + ': ' + p.name + ' — count “' + p.count + '”">'
        + noteHtml(p) + '</button>';
    }).join('');
    dom.title.textContent = level.name;
    dom.sub.textContent = 'Trinity-graded · ' + level.focus;
    dom.bpm.innerHTML = '<span class="rg-mus">' + GL.bpmNote + '</span> = ' + level.bpm + ' BPM';
    dom.grid.innerHTML = cards;
    cardDone = level.cards.map(function () { return null; });
    onsets = []; strays = [];
    curCard = -1;
    countLine();
    paint();
  }
  function paint() {
    dom.grid.querySelectorAll('.rg-card').forEach(function (el, i) {
      el.classList.toggle('rg-cur', i === curCard && state !== 'idle');
      el.classList.remove('rg-miss', 'rg-ok', 'rg-part');
      var d = cardDone[i];
      if (d != null) {
        if (d.acc >= 0.85) { el.style.setProperty('--rg-fill', d.color); el.classList.add('rg-ok'); }
        else if (d.acc >= 0.5) { el.style.setProperty('--rg-fill', d.color); el.classList.add('rg-ok', 'rg-part'); }
        else { el.classList.add('rg-miss'); el.style.removeProperty('--rg-fill'); }
      } else { el.style.removeProperty('--rg-fill'); }
    });
  }
  function pulse(k) {
    var el = dom.grid.querySelector('.rg-card[data-i="' + k + '"]');
    if (el) { el.classList.remove('rg-pulse'); void el.offsetWidth; el.classList.add('rg-pulse'); }
  }
  function countLine() {
    if (!dom.countline) return;
    var id = level.cards[curCard];
    dom.countline.textContent = (state !== 'idle' && id) ? 'Say it: “' + PAT[id].count + '”' : '';
  }
  function cardAt(pos) { var b = Math.floor(pos); if (b < 0 || b >= totalBeats) return -1; return beatCard[b]; }

  /* ════════════════════ Timeline + loop ════════════════════ */
  function startRun(playMode) {
    if (state !== 'idle') stopRun(true);
    mode = playMode ? 'play' : 'listen';
    var micPlay = (mode === 'play' && inputMode === 'mic');
    buildBoard();
    state = mode;
    beat = 60 / level.bpm;
    var a = ac();
    bus = a.createGain(); bus.gain.value = 1; bus.connect(a.destination);
    var base = a.currentTime + 0.18;
    for (var i = 0; i < 4; i++) click(base + i * beat, i === 0);   // count-in (always)
    t0 = base + 4 * beat;
    level.cards.forEach(function (id, k) {
      PAT[id].onsets.forEach(function (on, j) { onsets.push({ t: starts[k] + on, card: k, syl: PAT[id].syls[j] || 'the beat', hit: null }); });
    });
    // Mic "Play": stay SILENT through the scored bars (no per-beat clicks) so the
    // app's own sound can never reach the mic and skew the analysis. Tap/Listen
    // keep the pulse. Listen also sonifies every onset.
    if (!micPlay) for (var b = 0; b < totalBeats; b++) click(t0 + b * beat, b % 4 === 0);
    if (mode === 'listen') onsets.forEach(function (o) { blip(t0 + o.t * beat, 560 + (o.t % 1) * 260); });
    if (micPlay && detector) detector.reset();
    if (dom.meter) dom.meter.hidden = !micPlay;
    setStatus(mode === 'listen'
      ? 'Listen — follow the colours and hear how each figure sits inside the beat.'
      : (micPlay
        ? 'Count-in, then PLAY the rhythm — clap, tap, sing or play any instrument. We listen to your timing.'
        : 'Count-in first: think the rhythm, then tap what you read — eyes one card ahead.'));
    dom.start.classList.toggle('rg-on', mode === 'play');
    if (dom.start) dom.start.blur();
    cancelAnimationFrame(raf); raf = requestAnimationFrame(loop);
  }
  // Play handler: in mic mode, secure the microphone first (async), then run.
  function startPlay() {
    dom.result.classList.remove('shown');
    if (inputMode !== 'mic') { startRun(true); return; }
    setStatus('Requesting microphone…');
    ensureMic().then(function () { startRun(true); }).catch(function (err) {
      inputMode = 'tap'; syncModeUI();
      setStatus(err && err.name === 'NotAllowedError'
        ? 'Microphone blocked — switched to Tap. Allow mic access to play it instead.'
        : 'No microphone available — switched to Tap mode.');
    });
  }
  function loop() {
    if (state === 'idle') return;
    if (!document.body.contains(root)) { stopRun(true); return; }
    raf = requestAnimationFrame(loop);
    var pos = (ac().currentTime - t0) / beat;
    if (mode === 'play' && inputMode === 'mic') micFrame(pos);
    if (pos < 0) {
      var n = Math.max(1, Math.min(4, Math.floor(pos) + 5));
      if (dom.count.getAttribute('data-n') !== String(n)) {
        dom.count.setAttribute('data-n', n);
        dom.count.innerHTML = '<b>' + n + '</b><span>' + (mode === 'play' ? 'think the rhythm…' : 'listen…') + '</span>';
      }
      dom.count.classList.add('on');
    } else if (dom.count.classList.contains('on')) { dom.count.classList.remove('on'); dom.count.removeAttribute('data-n'); }
    var changed = false;
    if (mode === 'play') {
      for (var k = 0; k < level.cards.length; k++) {
        if (cardDone[k] != null) continue;
        if (pos > starts[k] + PAT[level.cards[k]].beats + HIT_TOL) { finalizeCard(k); changed = true; } else break;
      }
    }
    var idx = cardAt(pos);
    if (pos >= totalBeats) idx = level.cards.length;
    if (idx !== curCard) {
      if (mode === 'listen' && curCard >= 0 && curCard < level.cards.length) cardDone[curCard] = { acc: 1, color: PAT[level.cards[curCard]].color };
      curCard = idx; changed = true; countLine();
    }
    if (changed) paint();
    if (pos >= totalBeats + HIT_TOL + 0.1) { endRun(); return; }
  }
  function finalizeCard(k) {
    var p = PAT[level.cards[k]];
    var E = onsets.filter(function (o) { return o.card === k; });
    var s0 = starts[k], s1 = starts[k] + p.beats;
    var extra = strays.filter(function (s) { return s >= s0 && s < s1; }).length;
    var acc;
    if (!E.length) acc = Math.max(0, 1 - extra * 0.5);
    else { var sum = 0; E.forEach(function (o) { if (o.hit) sum += o.hit.q; }); acc = Math.max(0, (sum - extra * 0.4) / E.length); }
    cardDone[k] = { acc: acc, color: p.color };
  }
  function endRun() {
    if (mode === 'play') for (var k = 0; k < level.cards.length; k++) if (cardDone[k] == null) finalizeCard(k);
    if (mode === 'listen') for (var j = 0; j < level.cards.length; j++) if (cardDone[j] == null) cardDone[j] = { acc: 1, color: PAT[level.cards[j]].color };
    var played = mode;
    state = 'idle'; cancelAnimationFrame(raf); raf = null; curCard = -1;
    killAudio();
    dom.start.classList.remove('rg-on'); dom.count.classList.remove('on');
    if (dom.meter) dom.meter.hidden = true;
    countLine(); paint();
    if (played === 'play') showResult();
    else setStatus('That’s the rhythm. Hit Play and tap it — can you say every card’s counts first?');
  }
  function stopRun(silent) {
    state = 'idle'; cancelAnimationFrame(raf); raf = null; curCard = -1;
    killAudio();
    if (dom.start) dom.start.classList.remove('rg-on');
    if (dom.count) dom.count.classList.remove('on');
    if (dom.meter) dom.meter.hidden = true;
    if (!silent) stopMic();   // release the mic on an explicit stop (kept across auto-advance/retries)
    countLine(); paint();
    if (!silent) setStatus('Stopped.');
  }

  /* ── Hit registration (shared by tap + mic): latency-compensated, globally
        matched to the nearest unclaimed onset across card boundaries. ── */
  function registerHit(pos, fromMic) {
    if (pos < -HIT_TOL) { if (!fromMic) fb('Count-in — think it first…', 'wait'); return; }
    if (pos > totalBeats + HIT_TOL) return;
    var best = null, bd = 1e9;
    for (var i = 0; i < onsets.length; i++) {
      if (onsets[i].hit) continue;
      var d = Math.abs(onsets[i].t - pos);
      if (d < bd) { bd = d; best = onsets[i]; }
    }
    var src = fromMic ? ' (heard)' : '';
    if (best && bd <= HIT_TOL) {
      var err = pos - best.t;
      best.hit = { err: err, q: 0.6 + 0.4 * (1 - Math.abs(err) / HIT_TOL) };
      var ms = Math.round(err * beat * 1000);
      if (Math.abs(err) <= PERFECT) fb('Perfect · ' + (ms >= 0 ? '+' : '−') + Math.abs(ms) + ' ms on ' + best.syl + src, 'perfect');
      else fb((err < 0 ? 'Early' : 'Late') + ' · ' + (ms >= 0 ? '+' : '−') + Math.abs(ms) + ' ms on ' + best.syl + src, 'good');
      if (cardDone[best.card] != null) { finalizeCard(best.card); paint(); }
      pulse(best.card);
    } else {
      strays.push(pos);
      fb(fromMic ? 'Extra hit — only play what you read' : 'Extra tap — read the card, don’t guess', 'miss');
      var sc = cardAt(Math.max(0, Math.min(totalBeats - 0.01, pos)));
      if (sc >= 0) pulse(sc);
    }
    // A confirm blip ONLY for taps — in mic mode it would feed back and self-trigger.
    if (!fromMic) blip(ac().currentTime, 720);
  }
  function tap() {
    if (state !== 'play' || inputMode !== 'tap') return;   // in mic mode the board listens, not taps
    var a = ac();
    registerHit((a.currentTime - latency(a) - t0) / beat, false);
  }
  function fb(msg, cls) {
    if (!dom.fb) return;
    dom.fb.className = 'rg-fb'; void dom.fb.offsetWidth;
    dom.fb.textContent = msg; dom.fb.className = 'rg-fb show ' + (cls || '');
  }

  /* ════════════════════ Result · Trinity grade · rewards · endless ════════════════════ */
  function showResult() {
    var sum = 0; cardDone.forEach(function (d) { sum += d ? d.acc : 0; });
    var mark = Math.round(sum / level.cards.length * 100);   // 0..100
    var t = trinity(mark);

    // Timing analytics
    var hits = 0, missed = 0, errSum = 0;
    onsets.forEach(function (o) { if (o.hit) { hits++; errSum += o.hit.err; } else missed++; });
    var avgMs = hits ? Math.round(errSum / hits * beat * 1000) : 0;
    var extra = strays.length;

    // Which figure families appeared (for badges + weakest-figure coaching)
    var has = {}, byId = {};
    level.cards.forEach(function (id, k) { has[id] = true; (byId[id] = byId[id] || []).push(cardDone[k] ? cardDone[k].acc : 0); });
    var weak = null, wv = 0.75;
    Object.keys(byId).forEach(function (id) { var aAcc = byId[id].reduce(function (s, v) { return s + v; }, 0) / byId[id].length; if (aAcc < wv) { wv = aAcc; weak = id; } });

    // ── Points + progression + badges ──
    var prog = loadProg();
    var onTimeBonus = onsets.length ? Math.round((hits / onsets.length) * 20 * t.stars) : 0;
    var earned = t.pass ? Math.round(mark * (1 + (level.difficulty - 1) * 0.08)) + t.bonus + onTimeBonus : Math.round(mark * 0.3);
    prog.points += earned;
    prog.distinctionStreak = (t.band === 'Distinction') ? (prog.distinctionStreak + 1) : 0;
    if (t.pass) { prog.levelsCleared++; prog.maxDifficulty = Math.max(prog.maxDifficulty, level.difficulty + 1); }
    var ctx = { pass: t.pass, t: t, has: has };
    var newBadges = [];
    BADGES.forEach(function (b) {
      if (prog.badges.indexOf(b.id) < 0 && b.test(prog, ctx)) { prog.badges.push(b.id); newBadges.push(b); }
    });
    saveProg(prog);
    paintRewards(prog);

    // Persist the personal best via the studio store (cross-tool leaderboard).
    var rec = null;
    if (window.MAM_STUDIO && window.MAM_STUDIO.logScore) { try { rec = window.MAM_STUDIO.logScore('rhythm', mark, { level: level.name, difficulty: level.difficulty, band: t.band, minutes: 2 }); } catch (e) {} }

    // ── Coach insight ──
    var insight = [];
    if (hits) {
      if (avgMs <= -15) insight.push('You tend to <b>rush</b> — on average ' + Math.abs(avgMs) + ' ms ahead of the beat.');
      else if (avgMs >= 15) insight.push('You tend to <b>drag</b> — on average ' + avgMs + ' ms behind the beat.');
      else insight.push('Your timing centre is solid — average offset just ' + (avgMs >= 0 ? '+' : '−') + Math.abs(avgMs) + ' ms.');
    }
    if (missed) insight.push(missed + ' onset' + (missed === 1 ? '' : 's') + ' went untapped.');
    if (extra) insight.push(extra + ' extra tap' + (extra === 1 ? '' : 's') + ' — read the card, don’t guess.');
    if (weak) insight.push('<b>' + PAT[weak].name + '</b> needs work: ' + PAT[weak].tip);

    var stars = ''; for (var i = 0; i < 3; i++) stars += '<span class="rg-star ' + (i < t.stars ? 'on' : '') + '">★</span>';
    var badgesHtml = newBadges.length
      ? '<div class="rg-newbadges"><div class="rg-newbadges-h">🎉 Reward unlocked</div>' + newBadges.map(function (b) { return '<span class="rg-badge new">' + b.icon + ' ' + b.name + '</span>'; }).join('') + '</div>'
      : '';
    var streakHtml = prog.distinctionStreak >= 2 ? '<div class="rg-streak">🔥 ' + prog.distinctionStreak + '-Distinction streak</div>' : '';

    dom.result.innerHTML = '<div class="rg-result-card">'
      + '<div class="rg-stars">' + stars + '</div>'
      + '<div class="rg-band" style="color:' + t.color + '">' + t.band + '</div>'
      + '<div class="rg-final">' + mark + '<small>/100</small></div>'
      + '<div class="rg-final-l">' + level.name + ' · ' + level.focus + '</div>'
      + '<div class="rg-statrow">'
      +   '<span><b>' + hits + '/' + onsets.length + '</b>on time</span>'
      +   '<span><b>' + (avgMs >= 0 ? '+' : '−') + Math.abs(avgMs) + '</b>avg ms</span>'
      +   '<span><b>+' + earned + '</b>points</span>'
      +   '<span><b>' + prog.points + '</b>total</span>'
      + '</div>'
      + streakHtml + badgesHtml
      + '<div class="rg-insight"><div class="rg-insight-h"><i class="fa-solid fa-graduation-cap"></i> Coach’s insight</div>' + insight.map(function (l) { return '<p>' + l + '</p>'; }).join('') + '</div>'
      // AI practice coach (S3) — filled async via the mam:studio-feedback event
      // (signed-in students only; off unless an AI provider is configured).
      + '<div class="rg-insight" id="rgAiSlot" hidden></div>'
      + (rec && rec.newBest && rec.plays > 1 ? '<div class="rg-bestline new">New personal best: ' + rec.best + '/100 🎉</div>' : (rec ? '<div class="rg-bestline">Personal best: ' + rec.best + '/100</div>' : ''))
      + '<div class="rg-result-btns">'
      +   (t.pass
            ? '<button class="rg-btn rg-primary rg-continue" type="button">Continue → Level ' + (level.difficulty + 1) + ' <i class="fa-solid fa-arrow-right"></i></button>'
            : '<button class="rg-btn rg-primary rg-again" type="button"><i class="fa-solid fa-rotate-right"></i> Try this level again</button>')
      +   '<button class="rg-btn rg-relisten" type="button"><i class="fa-solid fa-volume-high"></i> Listen again</button>'
      + '</div></div>';
    dom.result.classList.add('shown');
    var cont = dom.result.querySelector('.rg-continue');
    if (cont) cont.addEventListener('click', function () { dom.result.classList.remove('shown'); difficulty = level.difficulty + 1; level = genLevel(difficulty); syncDifficultyUI(); startRun(false); });
    var again = dom.result.querySelector('.rg-again');
    if (again) again.addEventListener('click', function () { dom.result.classList.remove('shown'); level = genLevel(level.difficulty); buildBoard(); startRun(true); });
    var relisten = dom.result.querySelector('.rg-relisten');
    if (relisten) relisten.addEventListener('click', function () { dom.result.classList.remove('shown'); startRun(false); });
  }
  // Render the AI practice coach into the result card when it arrives (the
  // gateway is called by studio-gate.js on mam:game-complete). One-time listener.
  document.addEventListener('mam:studio-feedback', function (e) {
    if (!e.detail || e.detail.game !== 'rhythm' || !e.detail.text) return;
    var slot = document.getElementById('rgAiSlot'); if (!slot) return;
    slot.innerHTML = '<div class="rg-insight-h"><i class="fa-solid fa-wand-magic-sparkles"></i> AI practice coach</div>';
    var p = document.createElement('p'); p.textContent = e.detail.text; slot.appendChild(p);
    slot.hidden = false;
  });
  function setStatus(m) { if (dom.status) dom.status.textContent = m; }

  function paintRewards(prog) {
    if (!dom.rewards) return;
    prog = prog || loadProg();
    var earnedBadges = BADGES.filter(function (b) { return prog.badges.indexOf(b.id) >= 0; });
    dom.rewards.innerHTML =
      '<span class="rg-rw"><b>' + prog.points + '</b> pts</span>'
      + '<span class="rg-rw"><b>L' + prog.maxDifficulty + '</b> reached</span>'
      + '<span class="rg-rw"><b>' + prog.levelsCleared + '</b> cleared</span>'
      + (earnedBadges.length ? '<span class="rg-rw-badges">' + earnedBadges.map(function (b) { return '<span class="rg-badge" title="' + b.name + '">' + b.icon + '</span>'; }).join('') + '</span>' : '');
  }
  function syncDifficultyUI() {
    if (dom.title) dom.title.textContent = level.name;
    if (dom.sub) dom.sub.textContent = 'Trinity-graded · ' + level.focus;
    var sel = root.querySelector('#rgLevel'); if (sel) sel.value = String(Math.min(8, level.difficulty));
  }

  /* ════════════════════ UI ════════════════════ */
  function build() {
    root.innerHTML =
      '<div class="rg-board">'
      +  '<div class="rg-blob rg-blob-1"></div><div class="rg-blob rg-blob-2"></div>'
      +  '<div class="rg-head"><h3 class="rg-title" id="rgTitle">Level 1</h3><div class="rg-sub">Trinity-graded</div></div>'
      +  '<div class="rg-grid" id="rgGrid"></div>'
      +  '<div class="rg-underbar"><div class="rg-countline" id="rgCountline"></div><div class="rg-bpm" id="rgBpm"></div></div>'
      +  '<div class="rg-meter" id="rgMeter" hidden><span class="rg-meter-label"><i class="fa-solid fa-microphone"></i> Listening</span><div class="rg-meter-track"><div class="rg-meter-fill" id="rgMeterFill"></div></div></div>'
      +  '<div class="rg-fb" id="rgFb" role="status" aria-live="polite"></div>'
      +  '<div class="rg-count" id="rgCount" aria-hidden="true"></div>'
      +  '<div class="rg-result" id="rgResult"></div>'
      + '</div>'
      + '<div class="rg-rewards" id="rgRewards"></div>'
      + '<div class="rg-controls">'
      +  '<label class="rg-field">Start level<select id="rgLevel"></select></label>'
      +  '<div class="rg-mode" role="group" aria-label="Input mode"><button class="rg-mode-btn active" type="button" data-mode="tap">Tap</button><button class="rg-mode-btn" type="button" data-mode="mic">Play</button></div>'
      +  '<button class="rg-btn" id="rgListen" type="button"><i class="fa-solid fa-volume-high"></i> Listen</button>'
      +  '<button class="rg-btn rg-primary" id="rgStart" type="button"><i class="fa-solid fa-hand-pointer"></i> Play</button>'
      +  '<button class="rg-btn" id="rgStop" type="button"><i class="fa-solid fa-stop"></i> Stop</button>'
      +  '<span class="rg-status" id="rgStatus">Endless &amp; Trinity-graded — pass a level to auto-advance. Pick a start level, Listen, then Play.</span>'
      + '</div>'
      + '<div class="rg-foot"><span class="rg-tap-hint" id="rgHint">Tap the board, click a card, or press <kbd>Space</kbd> on the beat.</span></div>';
    dom = {
      title: root.querySelector('#rgTitle'), sub: root.querySelector('.rg-sub'), grid: root.querySelector('#rgGrid'),
      bpm: root.querySelector('#rgBpm'), result: root.querySelector('#rgResult'), status: root.querySelector('#rgStatus'),
      start: root.querySelector('#rgStart'), board: root.querySelector('.rg-board'),
      count: root.querySelector('#rgCount'), countline: root.querySelector('#rgCountline'),
      fb: root.querySelector('#rgFb'), rewards: root.querySelector('#rgRewards'),
      meter: root.querySelector('#rgMeter'), meterFill: root.querySelector('#rgMeterFill'), hint: root.querySelector('#rgHint'),
      modeBtns: Array.prototype.slice.call(root.querySelectorAll('.rg-mode-btn'))
    };
    var prog = loadProg();
    var sel = root.querySelector('#rgLevel');
    var maxStart = Math.max(1, Math.min(8, prog.maxDifficulty));
    var opts = ''; for (var d = 1; d <= maxStart; d++) opts += '<option value="' + d + '">Level ' + d + ' · ' + focusFor(d) + '</option>';
    sel.innerHTML = opts;
    difficulty = maxStart; sel.value = String(maxStart);
    sel.addEventListener('change', function () { stopRun(true); difficulty = parseInt(sel.value, 10) || 1; level = genLevel(difficulty); dom.result.classList.remove('shown'); buildBoard(); });
    dom.modeBtns.forEach(function (b) {
      b.addEventListener('click', function () { if (state !== 'idle') stopRun(true); inputMode = b.getAttribute('data-mode'); detector = inputMode === 'mic' ? makeOnsetDetector() : null; syncModeUI(); });
    });
    root.querySelector('#rgListen').addEventListener('click', function () { dom.result.classList.remove('shown'); startRun(false); });
    dom.start.addEventListener('click', startPlay);
    root.querySelector('#rgStop').addEventListener('click', function () { stopRun(); dom.result.classList.remove('shown'); });
    dom.board.addEventListener('pointerdown', function (e) { if (!e.target.closest('.rg-result')) tap(); });
    level = genLevel(difficulty);
    buildBoard();
    paintRewards(prog);
    syncModeUI();
  }
  function syncModeUI() {
    if (!dom.modeBtns) return;
    dom.modeBtns.forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-mode') === inputMode); });
    if (dom.hint) {
      dom.hint.innerHTML = inputMode === 'mic'
        ? '<i class="fa-solid fa-circle-info"></i> Play it any way — clap, tap your desk, sing, or play your instrument. We listen to your <strong>timing</strong>, not the notes.'
        : 'Tap the board, click a card, or press <kbd>Space</kbd> on the beat.';
    }
  }

  document.addEventListener('keydown', function (e) {
    if (e.code !== 'Space' || e.repeat) return;
    if (state !== 'play' || !root || !document.body.contains(root)) return;
    var ae = document.activeElement;
    if (ae && /INPUT|SELECT|TEXTAREA/.test(ae.tagName)) return;
    e.preventDefault(); tap();
  });

  function injectCSS() {
    if (!document.getElementById('rg-musicfont')) {
      var f = document.createElement('link');
      f.id = 'rg-musicfont'; f.rel = 'stylesheet';
      f.href = 'https://fonts.googleapis.com/css2?family=Noto+Music&display=swap';
      document.head.appendChild(f);
    }
    if (document.getElementById('rg-css')) return;
    var css = ''
      + '.rg-board{position:relative;overflow:hidden;background:#F6EBDD;border-radius:16px;padding:26px 18px 20px;text-align:center;box-shadow:inset 0 0 0 1px rgba(120,90,40,.08),0 18px 44px rgba(0,0,0,.35)}'
      + '.rg-blob{position:absolute;border-radius:50%;background:#EFE0CB;pointer-events:none}'
      + '.rg-blob-1{width:340px;height:340px;top:-160px;right:-120px}.rg-blob-2{width:260px;height:260px;bottom:-130px;left:-90px}'
      + '.rg-head{position:relative}'
      + '.rg-title{font-family:var(--font-display,Impact,sans-serif);font-size:2.2rem;letter-spacing:.04em;color:#E03131;margin:0}'
      + '.rg-sub{font-size:.78rem;font-weight:800;color:#C2453A;letter-spacing:.04em;margin:2px 0 18px}'
      + '.rg-grid{position:relative;display:grid;grid-template-columns:repeat(4,minmax(58px,86px));gap:12px;justify-content:center}'
      + '.rg-card{position:relative;aspect-ratio:1;border:0;border-radius:16px;background:#B3ACA2;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:6%;transition:transform .12s ease,background .22s ease;-webkit-tap-highlight-color:transparent}'
      + '.rg-card.rg-wide{grid-column:span 2;aspect-ratio:auto;padding:3%}'
      + '.rg-note{position:relative;font-family:"Noto Music","Bravura Text","Segoe UI Symbol","Symbola",serif;font-size:2.5rem;line-height:1.1;color:#fff;display:block;user-select:none;pointer-events:none}'
      + '.rg-note.sm{font-size:1.6rem}'
      + '.rg-trip3{position:absolute;top:-.8em;left:50%;transform:translateX(-50%);font-family:Inter,system-ui,sans-serif;font-style:normal;font-weight:800;font-size:.46em}'
      + '.rg-card.rg-cur{background:var(--rg-fill,#F2B632);transform:scale(1.07);box-shadow:0 8px 22px rgba(0,0,0,.18)}'
      + '.rg-card.rg-cur .rg-note,.rg-card.rg-ok .rg-note{color:#23232B}'
      + '.rg-card.rg-ok{background:var(--rg-fill,#42C58B)}.rg-card.rg-part{opacity:.55}'
      + '.rg-card.rg-miss{background:#8E8478;opacity:.85}.rg-card.rg-pulse{animation:rgPulse .18s ease}'
      + '@keyframes rgPulse{50%{transform:scale(.92)}}'
      + '.rg-underbar{position:relative;display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-top:16px;padding:0 6px}'
      + '.rg-countline{font-size:.92rem;font-weight:800;color:#C2453A;letter-spacing:.03em;min-height:1.3em;text-align:left}'
      + '.rg-bpm{color:#9A8C77;font-size:.92rem;font-variant-numeric:tabular-nums;white-space:nowrap}'
      + '.rg-mus{font-family:"Noto Music","Bravura Text","Segoe UI Symbol","Symbola",serif}'
      + '.rg-fb{position:absolute;left:50%;top:14px;transform:translateX(-50%);padding:7px 16px;border-radius:100px;font-size:.85rem;font-weight:800;background:#2B2B33;color:#fff;opacity:0;pointer-events:none;z-index:2;white-space:nowrap;max-width:92%;overflow:hidden;text-overflow:ellipsis}'
      + '.rg-fb.show{animation:rgFb 1.5s ease forwards}'
      + '.rg-fb.perfect{background:#1E9E66}.rg-fb.good{background:#C77F12}.rg-fb.miss{background:#C9304F}.rg-fb.wait{background:#3D6FB8}'
      + '@keyframes rgFb{0%{opacity:0;transform:translate(-50%,6px)}12%{opacity:1;transform:translate(-50%,0)}78%{opacity:1}100%{opacity:0}}'
      + '.rg-count{position:absolute;inset:0;display:none;flex-direction:column;align-items:center;justify-content:center;background:rgba(246,235,221,.78);backdrop-filter:blur(2px);border-radius:16px;z-index:3;pointer-events:none}'
      + '.rg-count.on{display:flex}.rg-count b{font-family:var(--font-display,Impact,sans-serif);font-size:5rem;line-height:1;color:#E03131}'
      + '.rg-count span{font-size:.95rem;font-weight:800;color:#C2453A;letter-spacing:.05em;margin-top:6px}'
      + '.rg-result{position:absolute;inset:0;display:none;align-items:center;justify-content:center;background:rgba(45,32,18,.55);backdrop-filter:blur(5px);border-radius:16px;z-index:4;overflow:auto;padding:14px}'
      + '.rg-result.shown{display:flex}'
      + '.rg-result-card{background:#FFFDF8;border-radius:16px;padding:22px 26px;box-shadow:0 24px 60px rgba(0,0,0,.35);max-width:440px;width:100%;margin:auto}'
      + '.rg-stars{font-size:1.8rem;letter-spacing:5px}.rg-star{color:#D9CDBC}.rg-star.on{color:#F2B632;text-shadow:0 0 12px rgba(242,182,50,.55)}'
      + '.rg-band{font-weight:800;letter-spacing:.08em;text-transform:uppercase;font-size:.9rem;margin-top:4px}'
      + '.rg-final{font-family:var(--font-display,Impact,sans-serif);font-size:3.2rem;line-height:1;color:#E03131}'
      + '.rg-final small{font-size:1.2rem;color:#9A8C77}'
      + '.rg-final-l{color:#6B5B45;margin:4px 0 12px;font-size:.92rem}'
      + '.rg-statrow{display:flex;justify-content:center;gap:16px;flex-wrap:wrap;margin:0 0 10px}'
      + '.rg-statrow span{display:flex;flex-direction:column;font-size:.64rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#9A8C77}'
      + '.rg-statrow b{font-size:1.1rem;color:#2B2B33;font-variant-numeric:tabular-nums;letter-spacing:0}'
      + '.rg-streak{font-size:.82rem;font-weight:800;color:#C77F12;margin:0 0 8px}'
      + '.rg-newbadges{background:#F6EBDD;border-radius:12px;padding:10px 12px;margin:0 0 10px}'
      + '.rg-newbadges-h{font-size:.7rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#1E9E66;margin-bottom:6px}'
      + '.rg-badge{display:inline-block;font-size:.8rem;font-weight:700;color:#4A3E2E;background:#fff;border:1px solid #E0D3BE;border-radius:100px;padding:3px 10px;margin:2px}'
      + '.rg-badge.new{border-color:#1E9E66;color:#1E9E66}'
      + '.rg-insight{text-align:left;background:#F6EBDD;border-radius:12px;padding:12px 14px;margin:0 0 12px}'
      + '.rg-insight-h{font-size:.7rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#C2453A;margin-bottom:6px}'
      + '.rg-insight p{margin:0 0 6px;font-size:.84rem;line-height:1.5;color:#4A3E2E}.rg-insight p:last-child{margin-bottom:0}'
      + '.rg-bestline{font-size:.84rem;font-weight:700;color:#9A8C77;margin:0 0 12px}.rg-bestline.new{color:#1E9E66}'
      + '.rg-result-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}'
      + '.rg-rewards{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:14px 2px 0;font-size:.78rem;color:var(--text-muted,#B9A88F)}'
      + '.rg-rw b{color:var(--gold-bright,#FCC647);font-variant-numeric:tabular-nums}'
      + '.rg-rw-badges{margin-left:auto;display:flex;gap:4px}.rg-rewards .rg-badge{background:var(--bg-raised,#2a1d10);border-color:var(--border,#5a4a35);color:var(--text,#fff);padding:2px 7px}'
      + '.rg-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px}'
      + '.rg-field{display:flex;flex-direction:column;gap:4px;font-size:.76rem;font-weight:700;color:var(--text-muted,#B9A88F)}'
      + '.rg-field select{padding:9px 12px;border-radius:10px;border:1.5px solid var(--border-strong,#7a6a52);background:var(--bg-raised,#352A1F);color:var(--text,#FCF8F3);min-height:42px}'
      + '.rg-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:12px;border:1px solid var(--border-strong,#7a6a52);background:var(--bg-raised,#352A1F);color:var(--text,#FCF8F3);font-weight:700;font-size:.9rem;cursor:pointer;min-height:44px}'
      + '.rg-result-card .rg-btn{border-color:#D9CDBC;background:#FFFDF8;color:#4A3E2E}'
      + '.rg-primary{background:linear-gradient(135deg,#E8961F,#F7A823);border-color:transparent;color:#1A120B}'
      + '.rg-result-card .rg-primary{background:linear-gradient(135deg,#E8961F,#F7A823);color:#1A120B;border-color:transparent}'
      + '.rg-primary.rg-on{background:linear-gradient(135deg,#F4423B,#FF6B6B);color:#fff}'
      + '.rg-status{flex:1 1 200px;min-width:0;font-size:.82rem;color:var(--text-muted,#B9A88F)}'
      + '.rg-foot{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:8px}'
      + '.rg-tap-hint{font-size:.78rem;color:var(--text-faint,#8d7f6a)}'
      + '.rg-tap-hint kbd{padding:1px 7px;border:1px solid var(--border,#5a4a35);border-radius:4px;font-size:.74rem}'
      + '.rg-mode{display:inline-flex;gap:4px;padding:3px;border-radius:100px;background:var(--bg-raised,#352A1F);border:1px solid var(--border-strong,#7a6a52)}'
      + '.rg-mode-btn{padding:8px 16px;border:0;border-radius:100px;background:transparent;color:var(--text-muted,#B9A88F);font-weight:800;font-size:.84rem;cursor:pointer;min-height:38px}'
      + '.rg-mode-btn.active{background:linear-gradient(135deg,#E8961F,#F7A823);color:#1A120B}'
      + '.rg-meter{position:relative;display:flex;align-items:center;gap:10px;margin-top:12px;padding:0 6px}'
      + '.rg-meter-label{font-size:.74rem;font-weight:800;letter-spacing:.04em;color:#C2453A;white-space:nowrap}'
      + '.rg-meter-track{flex:1;height:8px;border-radius:8px;background:#E0D3BE;overflow:hidden}'
      + '.rg-meter-fill{height:100%;width:0;background:linear-gradient(90deg,#42C58B,#F2B632,#F4577E);transition:width .06s linear}'
      + '@media (max-width:480px){.rg-grid{grid-template-columns:repeat(4,minmax(52px,1fr));gap:9px}.rg-title{font-size:1.8rem}.rg-board{padding:20px 12px 16px}.rg-note{font-size:2rem}.rg-note.sm{font-size:1.3rem}.rg-count b{font-size:3.6rem}}';
    var st = document.createElement('style'); st.id = 'rg-css'; st.textContent = css;
    document.head.appendChild(st);
  }

  function init() {
    root = document.getElementById('rhythmGame');
    if (!root || root.__rg) return; root.__rg = 1;
    try { injectCSS(); build(); } catch (e) { if (window.console) console.error('[MAM rhythm]', e); }
    // Leaving the Rhythm tab stops the run AND releases the microphone.
    document.querySelectorAll('.tools-tabs button').forEach(function (b) {
      b.addEventListener('click', function () { if (b.getAttribute('data-tool') !== 'rhythm') { if (state !== 'idle') stopRun(true); stopMic(); } });
    });
    // Releasing the page (tab hidden / unload) must free the mic too.
    document.addEventListener('visibilitychange', function () { if (document.hidden && micStream) { if (state !== 'idle') stopRun(true); stopMic(); } });
  }
  window.MAM_RHYTHM = {
    init: init,
    // Test hook: the current built board's timing schedule (deterministic per build).
    __peek: function () {
      return {
        bpm: level && level.bpm, difficulty: level && level.difficulty, totalBeats: totalBeats,
        starts: starts.slice(),
        cards: level ? level.cards.map(function (id) { return { id: id, beats: PAT[id].beats, onsets: PAT[id].onsets.slice() }; }) : []
      };
    },
    __trinity: trinity, __genLevel: genLevel, __detector: makeOnsetDetector
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
