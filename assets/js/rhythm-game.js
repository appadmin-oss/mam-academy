/* ============================================================
   MAM Academy — Rhythm Tap  ·  window.MAM_RHYTHM
   ------------------------------------------------------------
   Student-portal-exclusive rhythm-reading game (portal → Games tab),
   modelled on the "Tap with me" reference clip (Pianogoddess Academy):
   a cream board of 4 bars × 4 beats, cards colour-coded BY NOTE VALUE
   (quarters pink, eighths blue, sixteenth figures gold, dotted figures
   magenta, triplets mint, long notes green/indigo), wide two-beat cards
   (half note, dotted-quarter + eighth), colours persisting as the beat
   sweeps the board, and a steady click pulse underneath.

   Notation   Real engraved music-font glyphs (Noto Music, falling back
              to the system music-symbol fonts) from the Unicode Musical
              Symbols block — no hand-drawn shapes.
   Accuracy   Everything runs on the AudioContext clock. Taps are
              compensated for the device's reported output latency and
              matched to the nearest expected onset ACROSS card
              boundaries, so an early tap on a downbeat is never punished
              twice. Per-tap feedback reports Perfect / Early / Late with
              the offset in milliseconds and the counting syllable it
              belongs to.
   Thinking   The 4-beat count-in asks the student to audiate ("think the
              rhythm") before tapping; a live counting line spells each
              card aloud ("1 e & a"); after a run a coach insight analyses
              rush/drag tendency and the figure that needs work, closing
              with a reflective question — and a Next-level step appears
              once the level is solid (≥2 stars).
   Audio      Each run plays through its own gain bus and tracks every
              scheduled source, so Stop is instant — nothing keeps
              clicking after the board stops.
   Scores     Logged via window.MAM_STUDIO.logScore so personal bests
              persist per signed-in student.
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
    dotted:     '\uD834\uDD60\uD834\uDD6D\u2009\uD834\uDD61',  /* 𝅘𝅥𝅮𝅭 𝅘𝅥𝅯 dotted eighth + sixteenth */
    sed:        '\uD834\uDD61\u2009\uD834\uDD60\uD834\uDD6D',  /* 𝅘𝅥𝅯 𝅘𝅥𝅮𝅭 sixteenth + dotted eighth */
    restEighth: '\uD834\uDD3E\u2009\uD834\uDD60',  /* 𝄾 𝅘𝅥𝅮 eighth rest + eighth */
    restSixt:   '\uD834\uDD3E\u2009\u266C',  /* 𝄾 ♬ eighth rest + two sixteenths */
    restQuarter:'\uD834\uDD3D',  /* 𝄽  quarter rest */
    triplet:    '\uD834\uDD60\uD834\uDD60\uD834\uDD60',  /* 𝅘𝅥𝅮𝅘𝅥𝅮𝅘𝅥𝅮 eighth-note triplet */
    dqe:        '\uD834\uDD5F\uD834\uDD6D\u2009\uD834\uDD60',  /* 𝅘𝅥𝅭 𝅘𝅥𝅮 dotted quarter + eighth */
    bpmNote:    '\u2669'  /* ♩  metronome mark */
  };

  /* Patterns. `beats` = how many beats the card spans (wide cards = 2, as in
     the reference video). `onsets` are beat positions inside the card.
     `color` encodes the NOTE VALUE — the video's teaching device — so every
     quarter is pink, every eighth figure blue, every sixteenth figure gold,
     dotted figures magenta, triplets mint, long notes green/indigo.
     `count` is the figure spoken aloud; `syls` name each onset for feedback;
     `tip` is the coach line used when this figure scores lowest. */
  var PAT = {
    q:    { beats: 1, onsets: [0],                glyph: GL.quarter,     small: 0, color: '#F4577E', name: 'Quarter note',                  count: '1',
            syls: ['the downbeat'], tip: 'Land exactly with the click — one full, settled beat.' },
    ee:   { beats: 1, onsets: [0, 0.5],           glyph: GL.eighths,     small: 0, color: '#5B9BE8', name: 'Two eighth notes',              count: '1 &',
            syls: ['the downbeat', 'the “&”'], tip: 'Split the beat exactly in half — say “1 &” out loud.' },
    ssss: { beats: 1, onsets: [0, .25, .5, .75],  glyph: GL.sixteenths,  small: 1, color: '#F2B632', name: 'Four sixteenth notes',          count: '1 e & a',
            syls: ['the downbeat', 'the “e”', 'the “&”', 'the “a”'], tip: 'Four even taps per beat — “1 e & a”, hand relaxed.' },
    ess:  { beats: 1, onsets: [0, .5, .75],       glyph: GL.ess,         small: 1, color: '#F2B632', name: 'Eighth then two sixteenths',    count: '1 & a',
            syls: ['the downbeat', 'the “&”', 'the “a”'], tip: 'Long–short–short: the sixteenths share the back half — “1 & a”.' },
    sse:  { beats: 1, onsets: [0, .25, .5],       glyph: GL.sse,         small: 1, color: '#F2B632', name: 'Two sixteenths then an eighth', count: '1 e &',
            syls: ['the downbeat', 'the “e”', 'the “&”'], tip: 'Short–short–long: “1 e &” — land the eighth firmly and hold.' },
    des:  { beats: 1, onsets: [0, 0.75],          glyph: GL.dotted,      small: 1, color: '#C44DD6', name: 'Dotted eighth then sixteenth',  count: '1 … a',
            syls: ['the downbeat', 'the “a”'], tip: 'Long–short: hold the dot, the sixteenth lands on the late “a”.' },
    sed:  { beats: 1, onsets: [0, 0.25],          glyph: GL.sed,         small: 1, color: '#C44DD6', name: 'Sixteenth then dotted eighth',  count: '1 e',
            syls: ['the downbeat', 'the “e”'], tip: 'Short–long: snap the sixteenth on “e”, then hold through “& a”.' },
    res:  { beats: 1, onsets: [0.5],              glyph: GL.restEighth,  small: 1, color: '#5B9BE8', name: 'Eighth rest then eighth note',  count: '(1) &',
            syls: ['the “&”'], tip: 'Silence first — feel the downbeat, tap only the “&”.' },
    rss:  { beats: 1, onsets: [0.5, 0.75],        glyph: GL.restSixt,    small: 1, color: '#F2B632', name: 'Eighth rest then two sixteenths', count: '(1) & a',
            syls: ['the “&”', 'the “a”'], tip: 'Let the rest breathe, then two quick sixteenths on “& a”.' },
    qr:   { beats: 1, onsets: [],                 glyph: GL.restQuarter, small: 0, color: '#F4577E', name: 'Quarter rest — stay silent',    count: '(rest)',
            syls: [], tip: 'A rest is music too — stay perfectly still for the whole beat.' },
    trip: { beats: 1, onsets: [0, 1/3, 2/3],      glyph: GL.triplet,     small: 1, color: '#3ECFA0', name: 'Eighth-note triplet',           count: '1-la-li', triplet: true,
            syls: ['the downbeat', '“la” (2nd of the triplet)', '“li” (3rd of the triplet)'], tip: 'Three perfectly even notes in one beat — “1-la-li”, no swing.' },
    h:    { beats: 2, onsets: [0],                glyph: GL.half,        small: 0, color: '#42C58B', name: 'Half note — two beats',         count: '1 (hold 2)', wide: 1,
            syls: ['the downbeat'], tip: 'One tap, two beats — tap the downbeat, then hold the silence alive.' },
    dqe:  { beats: 2, onsets: [0, 1.5],           glyph: GL.dqe,         small: 1, color: '#7C7DF5', name: 'Dotted quarter then eighth',    count: '1 … (2)&', wide: 1,
            syls: ['the downbeat', 'the “&” of beat 2'], tip: 'The dotted quarter holds a beat and a half — the eighth lands on the “&” of beat 2.' }
  };

  /* Five levels, exactly like the reference: 4 bars of 4/4 (16 beats), every
     row summing to 4 beats, the long note closing levels 1–4. */
  var LEVELS = [
    { name: 'Level 1', focus: 'Quarters & eighths',    bpm: 100, cards: ['q','ee','q','ee',  'q','q','ee','q',   'qr','q','ee','q',   'q','ee','h'] },
    { name: 'Level 2', focus: 'Sixteenths & rests',    bpm: 100, cards: ['q','ee','ssss','ee','res','ee','res','q','ssss','q','ssss','qr','q','ee','h'] },
    { name: 'Level 3', focus: 'Composite figures',     bpm: 104, cards: ['ee','ssss','ess','q','rss','q','res','q', 'q','ee','sse','q',  'ess','q','h'] },
    { name: 'Level 4', focus: 'Dotted rhythms',        bpm: 104, cards: ['sse','sed','q','ee', 'des','ee','des','q','rss','ee','sse','q', 'res','ee','h'] },
    { name: 'Level 5', focus: 'Triplets & long notes', bpm: 108, cards: ['dqe','trip','q',     'ess','ee','dqe',    'trip','trip','ee','q','ee','q','ee','q'] }
  ];
  var HIT_TOL = 0.18;   // fraction of a beat that still counts as on-time
  var PERFECT = 0.07;   // fraction of a beat rated "Perfect"

  var root, dom = {}, level = LEVELS[0], state = 'idle', mode = 'play';
  var raf = null, t0 = 0, beat = 0.6, curCard = -1;
  var bus = null, srcs = [], onsets = [], strays = [], cardDone = [];
  var starts = [], beatCard = [], totalBeats = 0;

  function ac() { return (window.MAM && window.MAM.audio) ? window.MAM.audio() : (window.__rgAC || (window.__rgAC = new (window.AudioContext || window.webkitAudioContext)())); }
  /* The click the student hears arrives outputLatency seconds after its
     scheduled time, so their tap (read on the context clock) measures late
     by exactly that amount — subtract it for honest scoring. */
  function latency(a) { return a.outputLatency || a.baseLatency || 0; }

  /* ── Click / tone synthesis — every node goes through the run bus and is
        tracked, so Stop can silence + cancel the whole schedule. ── */
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

  /* ════════════════════ Board ════════════════════ */
  function noteHtml(p) {
    return '<span class="rg-note' + (p.small ? ' sm' : '') + '" aria-hidden="true">'
      + (p.triplet ? '<i class="rg-trip3">3</i>' : '') + p.glyph + '</span>';
  }
  function buildBoard() {
    // Beat timeline: starts[k] = the absolute beat card k begins on.
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
    dom.sub.textContent = 'Rhythm Tap · ' + level.focus;
    dom.bpm.innerHTML = '<span class="rg-mus">' + GL.bpmNote + '</span> = ' + level.bpm + ' BPM';
    dom.grid.innerHTML = cards;
    cardDone = level.cards.map(function () { return null; });
    onsets = []; strays = [];
    curCard = -1;
    countLine();
    paint();
  }
  function paint() {
    var els = dom.grid.querySelectorAll('.rg-card');
    els.forEach(function (el, i) {
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
  function cardAt(pos) {
    var b = Math.floor(pos);
    if (b < 0 || b >= totalBeats) return -1;
    return beatCard[b];
  }

  /* ════════════════════ Timeline + loop ════════════════════ */
  function startRun(playMode) {
    if (state !== 'idle') stopRun(true);
    mode = playMode ? 'play' : 'listen';
    buildBoard();
    state = mode;
    beat = 60 / level.bpm;
    var a = ac();
    bus = a.createGain(); bus.gain.value = 1; bus.connect(a.destination);
    var base = a.currentTime + 0.18;
    // 4-beat count-in
    for (var i = 0; i < 4; i++) click(base + i * beat, i === 0);
    t0 = base + 4 * beat; // first card downbeat
    // Expected onsets on an absolute beat timeline (card k starting at
    // starts[k]; onset o → starts[k] + o). Each carries its syllable.
    level.cards.forEach(function (id, k) {
      PAT[id].onsets.forEach(function (on, j) {
        onsets.push({ t: starts[k] + on, card: k, syl: PAT[id].syls[j] || 'the beat', hit: null });
      });
    });
    // The pulse ticks every beat in BOTH modes (as in the reference video),
    // accented on each bar line; Listen mode adds a blip on every onset so
    // the board plays its own rhythm.
    for (var b = 0; b < totalBeats; b++) click(t0 + b * beat, b % 4 === 0);
    if (mode === 'listen') {
      onsets.forEach(function (o, j) { blip(t0 + o.t * beat, 560 + (o.t % 1) * 260); });
    }
    setStatus(mode === 'listen'
      ? 'Listen — follow the colours and hear how each figure sits inside the beat.'
      : 'Count-in first: think the rhythm in your head, then tap what you read — eyes one card ahead.');
    dom.start.classList.toggle('rg-on', mode === 'play');
    if (dom.start) dom.start.blur();
    cancelAnimationFrame(raf); raf = requestAnimationFrame(loop);
  }

  function loop() {
    if (state === 'idle') return;
    // The portal re-renders tab content — if our board left the DOM, stop.
    if (!document.body.contains(root)) { stopRun(true); return; }
    raf = requestAnimationFrame(loop);
    var pos = (ac().currentTime - t0) / beat; // absolute position in beats
    // Count-in overlay: big 1·2·3·4 with an audiation prompt.
    if (pos < 0) {
      var n = Math.max(1, Math.min(4, Math.floor(pos) + 5));
      if (dom.count.getAttribute('data-n') !== String(n)) {
        dom.count.setAttribute('data-n', n);
        dom.count.innerHTML = '<b>' + n + '</b><span>' + (mode === 'play' ? 'think the rhythm…' : 'listen…') + '</span>';
      }
      dom.count.classList.add('on');
    } else if (dom.count.classList.contains('on')) {
      dom.count.classList.remove('on'); dom.count.removeAttribute('data-n');
    }
    var changed = false;
    // A card is only judged once its full tolerance window has passed, so an
    // early tap for the NEXT downbeat is never trapped in the wrong card.
    if (mode === 'play') {
      for (var k = 0; k < level.cards.length; k++) {
        if (cardDone[k] != null) continue;
        if (pos > starts[k] + PAT[level.cards[k]].beats + HIT_TOL) { finalizeCard(k); changed = true; } else break;
      }
    }
    var idx = cardAt(pos);
    if (pos >= totalBeats) idx = level.cards.length; // past the end — clear highlight
    if (idx !== curCard) {
      if (mode === 'listen' && curCard >= 0 && curCard < level.cards.length) {
        cardDone[curCard] = { acc: 1, color: PAT[level.cards[curCard]].color };
      }
      curCard = idx; changed = true; countLine();
    }
    if (changed) paint();
    if (pos >= totalBeats + HIT_TOL + 0.1) { endRun(); return; }
  }

  /* Score one card from globally matched onsets + stray taps inside it. */
  function finalizeCard(k) {
    var p = PAT[level.cards[k]];
    var E = onsets.filter(function (o) { return o.card === k; });
    var s0 = starts[k], s1 = starts[k] + p.beats;
    var extra = strays.filter(function (s) { return s >= s0 && s < s1; }).length;
    var acc;
    if (!E.length) acc = Math.max(0, 1 - extra * 0.5);             // rest card: don't tap
    else {
      var sum = 0;
      E.forEach(function (o) { if (o.hit) sum += o.hit.q; });
      acc = Math.max(0, (sum - extra * 0.4) / E.length);
    }
    cardDone[k] = { acc: acc, color: p.color };
  }

  function endRun() {
    if (mode === 'play') for (var k = 0; k < level.cards.length; k++) if (cardDone[k] == null) finalizeCard(k);
    if (mode === 'listen') for (var j = 0; j < level.cards.length; j++) if (cardDone[j] == null) cardDone[j] = { acc: 1, color: PAT[level.cards[j]].color };
    var played = mode;
    state = 'idle'; cancelAnimationFrame(raf); raf = null; curCard = -1;
    killAudio();
    dom.start.classList.remove('rg-on');
    dom.count.classList.remove('on');
    countLine(); paint();
    if (played === 'play') showResult();
    else setStatus('That’s the rhythm. Before you Play: think it through once in your head — can you say every card’s counts?');
  }
  function stopRun(silent) {
    state = 'idle'; cancelAnimationFrame(raf); raf = null; curCard = -1;
    killAudio();
    if (dom.start) dom.start.classList.remove('rg-on');
    if (dom.count) dom.count.classList.remove('on');
    countLine(); paint();
    if (!silent) setStatus('Stopped.');
  }

  /* ── Tap capture: latency-compensated, globally matched ── */
  function tap() {
    if (state !== 'play') return;
    var a = ac();
    var pos = (a.currentTime - latency(a) - t0) / beat;
    if (pos < -HIT_TOL) { fb('Count-in — think it first…', 'wait'); return; }
    if (pos > totalBeats + HIT_TOL) return;
    // Nearest unclaimed onset wins, wherever the card boundary falls.
    var best = null, bd = 1e9;
    for (var i = 0; i < onsets.length; i++) {
      if (onsets[i].hit) continue;
      var d = Math.abs(onsets[i].t - pos);
      if (d < bd) { bd = d; best = onsets[i]; }
    }
    if (best && bd <= HIT_TOL) {
      var err = pos - best.t; // signed: − early, + late
      best.hit = { err: err, q: 0.6 + 0.4 * (1 - Math.abs(err) / HIT_TOL) };
      var ms = Math.round(err * beat * 1000);
      if (Math.abs(err) <= PERFECT) fb('Perfect · ' + (ms >= 0 ? '+' : '−') + Math.abs(ms) + ' ms on ' + best.syl, 'perfect');
      else fb((err < 0 ? 'Early' : 'Late') + ' · ' + (ms >= 0 ? '+' : '−') + Math.abs(ms) + ' ms on ' + best.syl, 'good');
      // High-latency outputs (Bluetooth) can land a compensated tap in a card
      // the wall-clock loop already judged — re-score it so the hit counts.
      if (cardDone[best.card] != null) { finalizeCard(best.card); paint(); }
      pulse(best.card);
    } else {
      strays.push(pos);
      fb('Extra tap — read the card, don’t guess', 'miss');
      var sc = cardAt(Math.max(0, Math.min(totalBeats - 0.01, pos)));
      if (sc >= 0) pulse(sc);
    }
    blip(a.currentTime, 720);
  }

  /* Floating per-tap feedback chip (aria-live so screen readers hear it). */
  function fb(msg, cls) {
    if (!dom.fb) return;
    dom.fb.className = 'rg-fb';
    void dom.fb.offsetWidth; // restart the fade animation
    dom.fb.textContent = msg;
    dom.fb.className = 'rg-fb show ' + (cls || '');
  }

  /* ════════════════════ Results + coach insight ════════════════════ */
  function showResult() {
    var sum = 0; cardDone.forEach(function (d) { sum += d ? d.acc : 0; });
    var pct = Math.round(sum / level.cards.length * 100);
    var stars = pct >= 90 ? 3 : pct >= 70 ? 2 : pct >= 45 ? 1 : 0, st = '';
    for (var i = 0; i < 3; i++) st += '<span class="rg-star ' + (i < stars ? 'on' : '') + '">★</span>';

    // Timing analytics
    var hits = 0, missed = 0, errSum = 0;
    onsets.forEach(function (o) { if (o.hit) { hits++; errSum += o.hit.err; } else missed++; });
    var avgMs = hits ? Math.round(errSum / hits * beat * 1000) : 0;
    var extra = strays.length;

    // Weakest figure (lowest mean card accuracy, only if it clearly lags)
    var byId = {};
    level.cards.forEach(function (id, k) { (byId[id] = byId[id] || []).push(cardDone[k] ? cardDone[k].acc : 0); });
    var weak = null, wv = 0.75;
    Object.keys(byId).forEach(function (id) {
      var a = byId[id].reduce(function (s, v) { return s + v; }, 0) / byId[id].length;
      if (a < wv) { wv = a; weak = id; }
    });

    var insight = [];
    if (hits) {
      if (avgMs <= -15) insight.push('You tend to <b>rush</b> — on average ' + Math.abs(avgMs) + ' ms ahead of the beat.');
      else if (avgMs >= 15) insight.push('You tend to <b>drag</b> — on average ' + avgMs + ' ms behind the beat.');
      else insight.push('Your timing centre is solid — average offset just ' + (avgMs >= 0 ? '+' : '−') + Math.abs(avgMs) + ' ms.');
    }
    if (missed) insight.push(missed + ' onset' + (missed === 1 ? '' : 's') + ' went untapped.');
    if (extra) insight.push(extra + ' extra tap' + (extra === 1 ? '' : 's') + ' — usually guessing instead of reading.');
    if (weak) insight.push('<b>' + PAT[weak].name + '</b> needs work: ' + PAT[weak].tip);
    insight.push('<i>' + (avgMs <= -15
      ? 'Think: are you tapping what you read, or jumping at what you expect?'
      : (avgMs >= 15
        ? 'Think: are you waiting to hear the click before you move? Predict it — breathe with the count-in.'
        : 'Think the trickiest card through once in your head — can you say its counts aloud before the next run?')) + '</i>');

    // Personal best (per student, via the portal studio store)
    var rec = null;
    if (window.MAM_STUDIO && window.MAM_STUDIO.logScore) { try { rec = window.MAM_STUDIO.logScore('rhythm', pct, { level: level.name }); } catch (e) {} }
    var bestLine = rec
      ? (rec.newBest && rec.plays > 1 ? '<div class="rg-bestline new">New personal best! 🎉</div>'
        : '<div class="rg-bestline">Personal best: ' + rec.best + '%</div>')
      : '';

    // Level solid (≥2 stars) → surface the next step, like the video's chain.
    var levelIdx = LEVELS.indexOf(level);
    var nextBtn = (stars >= 2 && levelIdx >= 0 && levelIdx < LEVELS.length - 1)
      ? '<button class="rg-btn rg-primary rg-next" type="button">Next: ' + LEVELS[levelIdx + 1].name + ' · ' + LEVELS[levelIdx + 1].focus + ' →</button>'
      : '';

    dom.result.innerHTML = '<div class="rg-result-card"><div class="rg-stars">' + st + '</div>'
      + '<div class="rg-final">' + pct + '<small>%</small></div><div class="rg-final-l">' + level.name + ' · ' + level.focus + '</div>'
      + '<div class="rg-statrow">'
      +   '<span><b>' + hits + '/' + onsets.length + '</b>on time</span>'
      +   '<span><b>' + (avgMs >= 0 ? '+' : '−') + Math.abs(avgMs) + '</b>avg ms</span>'
      +   '<span><b>' + missed + '</b>missed</span>'
      +   '<span><b>' + extra + '</b>extra</span>'
      + '</div>'
      + '<div class="rg-insight"><div class="rg-insight-h"><i class="fa-solid fa-graduation-cap"></i> Coach’s insight</div>' + insight.map(function (l) { return '<p>' + l + '</p>'; }).join('') + '</div>'
      + bestLine
      + '<div class="rg-result-btns">' + nextBtn
      + '<button class="rg-btn rg-primary rg-again" type="button">Try again</button>'
      + '<button class="rg-btn rg-relisten" type="button"><i class="fa-solid fa-volume-high"></i> Listen again</button></div></div>';
    dom.result.classList.add('shown');
    var again = dom.result.querySelector('.rg-again');
    if (again) again.addEventListener('click', function () { dom.result.classList.remove('shown'); startRun(true); });
    var relisten = dom.result.querySelector('.rg-relisten');
    if (relisten) relisten.addEventListener('click', function () { dom.result.classList.remove('shown'); startRun(false); });
    var next = dom.result.querySelector('.rg-next');
    if (next) next.addEventListener('click', function () {
      dom.result.classList.remove('shown');
      level = LEVELS[levelIdx + 1];
      var sel = root.querySelector('#rgLevel'); if (sel) sel.value = String(levelIdx + 1);
      startRun(false); // hear the new level once before tapping it
    });
    refreshBest();
  }
  function setStatus(m) { if (dom.status) dom.status.textContent = m; }
  function refreshBest() {
    if (!dom.best) return;
    var s = (window.MAM_STUDIO && window.MAM_STUDIO.gameStats) ? window.MAM_STUDIO.gameStats('rhythm') : null;
    dom.best.textContent = s && s.best ? 'Personal best ' + s.best + '% · ' + s.plays + ' play' + (s.plays === 1 ? '' : 's') : '';
  }

  /* ════════════════════ UI ════════════════════ */
  function build() {
    root.innerHTML =
      '<div class="rg-board">'
      +  '<div class="rg-blob rg-blob-1"></div><div class="rg-blob rg-blob-2"></div>'
      +  '<div class="rg-head"><h3 class="rg-title" id="rgTitle">Level 1</h3><div class="rg-sub">Rhythm Tap</div></div>'
      +  '<div class="rg-grid" id="rgGrid"></div>'
      +  '<div class="rg-underbar"><div class="rg-countline" id="rgCountline"></div><div class="rg-bpm" id="rgBpm"></div></div>'
      +  '<div class="rg-fb" id="rgFb" role="status" aria-live="polite"></div>'
      +  '<div class="rg-count" id="rgCount" aria-hidden="true"></div>'
      +  '<div class="rg-result" id="rgResult"></div>'
      + '</div>'
      + '<div class="rg-controls">'
      +  '<label class="rg-field">Level<select id="rgLevel"></select></label>'
      +  '<button class="rg-btn" id="rgListen" type="button"><i class="fa-solid fa-volume-high"></i> Listen</button>'
      +  '<button class="rg-btn rg-primary" id="rgStart" type="button"><i class="fa-solid fa-hand-pointer"></i> Play</button>'
      +  '<button class="rg-btn" id="rgStop" type="button"><i class="fa-solid fa-stop"></i> Stop</button>'
      +  '<span class="rg-status" id="rgStatus">Pick a level, hit Listen to learn the rhythm, then Play to tap it in time.</span>'
      + '</div>'
      + '<div class="rg-foot"><span class="rg-tap-hint">Tap the board, click a card, or press <kbd>Space</kbd> on the beat.</span><span class="rg-best" id="rgBest"></span></div>';
    dom = {
      title: root.querySelector('#rgTitle'), sub: root.querySelector('.rg-sub'), grid: root.querySelector('#rgGrid'),
      bpm: root.querySelector('#rgBpm'), result: root.querySelector('#rgResult'), status: root.querySelector('#rgStatus'),
      start: root.querySelector('#rgStart'), board: root.querySelector('.rg-board'),
      count: root.querySelector('#rgCount'), countline: root.querySelector('#rgCountline'),
      fb: root.querySelector('#rgFb'), best: root.querySelector('#rgBest')
    };
    var sel = root.querySelector('#rgLevel');
    sel.innerHTML = LEVELS.map(function (l, i) { return '<option value="' + i + '">' + l.name + ' · ' + l.focus + '</option>'; }).join('');
    sel.addEventListener('change', function () { stopRun(true); level = LEVELS[parseInt(sel.value, 10)] || LEVELS[0]; dom.result.classList.remove('shown'); buildBoard(); });
    root.querySelector('#rgListen').addEventListener('click', function () { dom.result.classList.remove('shown'); startRun(false); });
    dom.start.addEventListener('click', function () { dom.result.classList.remove('shown'); startRun(true); });
    root.querySelector('#rgStop').addEventListener('click', function () { stopRun(); dom.result.classList.remove('shown'); });
    dom.board.addEventListener('pointerdown', function (e) { if (!e.target.closest('.rg-result')) tap(); });
    buildBoard();
    refreshBest();
  }

  /* Space = tap. Bound ONCE at module level (the portal re-renders the tab,
     so binding inside build() would stack duplicate listeners and register
     phantom double-taps). Only fires mid-run, so buttons keep their normal
     keyboard behaviour while idle. */
  document.addEventListener('keydown', function (e) {
    if (e.code !== 'Space' || e.repeat) return;
    if (state !== 'play' || !root || !document.body.contains(root)) return;
    var ae = document.activeElement;
    if (ae && /INPUT|SELECT|TEXTAREA/.test(ae.tagName)) return;
    e.preventDefault(); tap();
  });

  /* ── Injected styles + the real music font (Noto Music). The game is a
        self-contained drop-in for the student portal, so it carries its own
        look: cream board, soft blobs, engraved value-coloured note cards. ── */
  function injectCSS() {
    if (!document.getElementById('rg-musicfont')) {
      var f = document.createElement('link');
      f.id = 'rg-musicfont'; f.rel = 'stylesheet';
      f.href = 'https://fonts.googleapis.com/css2?family=Noto+Music&display=swap';
      document.head.appendChild(f);
    }
    if (document.getElementById('rg-css')) return;
    var css = ''
      + '.rg-board{position:relative;overflow:hidden;background:#F6EBDD;border-radius:18px;padding:26px 18px 20px;text-align:center;box-shadow:inset 0 0 0 1px rgba(120,90,40,.08),0 18px 44px rgba(0,0,0,.35)}'
      + '.rg-blob{position:absolute;border-radius:50%;background:#EFE0CB;pointer-events:none}'
      + '.rg-blob-1{width:340px;height:340px;top:-160px;right:-120px}'
      + '.rg-blob-2{width:260px;height:260px;bottom:-130px;left:-90px}'
      + '.rg-head{position:relative}'
      + '.rg-title{font-family:var(--font-display,Impact,sans-serif);font-size:2.2rem;letter-spacing:.04em;color:#E03131;margin:0}'
      + '.rg-sub{font-size:.78rem;font-weight:800;color:#C2453A;letter-spacing:.04em;margin:2px 0 18px}'
      + '.rg-grid{position:relative;display:grid;grid-template-columns:repeat(4,minmax(58px,86px));gap:12px;justify-content:center}'
      + '.rg-card{position:relative;aspect-ratio:1;border:0;border-radius:16px;background:#B3ACA2;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:6%;transition:transform .12s ease,background .22s ease;-webkit-tap-highlight-color:transparent}'
      + '.rg-card.rg-wide{grid-column:span 2;aspect-ratio:auto;padding:3%}'
      + '.rg-note{position:relative;font-family:"Noto Music","Bravura Text","Segoe UI Symbol","Symbola",serif;font-size:2.5rem;line-height:1.1;color:#fff;display:block;user-select:none;pointer-events:none}'
      + '.rg-note.sm{font-size:1.6rem}'
      + '.rg-trip3{position:absolute;top:-.8em;left:50%;transform:translateX(-50%);font-family:Inter,system-ui,sans-serif;font-style:normal;font-weight:800;font-size:.46em;letter-spacing:0}'
      + '.rg-card.rg-cur{background:var(--rg-fill,#F2B632);transform:scale(1.07);box-shadow:0 8px 22px rgba(0,0,0,.18)}'
      + '.rg-card.rg-cur .rg-note,.rg-card.rg-ok .rg-note{color:#23232B}'
      + '.rg-card.rg-ok{background:var(--rg-fill,#42C58B)}'
      + '.rg-card.rg-part{opacity:.55}'
      + '.rg-card.rg-miss{background:#8E8478;opacity:.85}'
      + '.rg-card.rg-pulse{animation:rgPulse .18s ease}'
      + '@keyframes rgPulse{50%{transform:scale(.92)}}'
      + '.rg-underbar{position:relative;display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-top:16px;padding:0 6px}'
      + '.rg-countline{font-size:.92rem;font-weight:800;color:#C2453A;letter-spacing:.03em;min-height:1.3em;text-align:left}'
      + '.rg-bpm{color:#9A8C77;font-size:.92rem;font-variant-numeric:tabular-nums;white-space:nowrap}'
      + '.rg-mus{font-family:"Noto Music","Bravura Text","Segoe UI Symbol","Symbola",serif}'
      + '.rg-fb{position:absolute;left:50%;top:14px;transform:translateX(-50%);padding:7px 16px;border-radius:100px;font-size:.85rem;font-weight:800;letter-spacing:.02em;background:#2B2B33;color:#fff;opacity:0;pointer-events:none;z-index:2;white-space:nowrap;max-width:92%;overflow:hidden;text-overflow:ellipsis}'
      + '.rg-fb.show{animation:rgFb 1.5s ease forwards}'
      + '.rg-fb.perfect{background:#1E9E66;color:#fff}'
      + '.rg-fb.good{background:#C77F12;color:#fff}'
      + '.rg-fb.miss{background:#C9304F;color:#fff}'
      + '.rg-fb.wait{background:#3D6FB8;color:#fff}'
      + '@keyframes rgFb{0%{opacity:0;transform:translate(-50%,6px)}12%{opacity:1;transform:translate(-50%,0)}78%{opacity:1}100%{opacity:0}}'
      + '.rg-count{position:absolute;inset:0;display:none;flex-direction:column;align-items:center;justify-content:center;background:rgba(246,235,221,.78);backdrop-filter:blur(2px);border-radius:18px;z-index:3;pointer-events:none}'
      + '.rg-count.on{display:flex}'
      + '.rg-count b{font-family:var(--font-display,Impact,sans-serif);font-size:5rem;line-height:1;color:#E03131}'
      + '.rg-count span{font-size:.95rem;font-weight:800;color:#C2453A;letter-spacing:.05em;margin-top:6px}'
      + '.rg-result{position:absolute;inset:0;display:none;align-items:center;justify-content:center;background:rgba(45,32,18,.55);backdrop-filter:blur(5px);border-radius:18px;z-index:4;overflow:auto;padding:14px}'
      + '.rg-result.shown{display:flex}'
      + '.rg-result-card{background:#FFFDF8;border-radius:18px;padding:22px 26px;box-shadow:0 24px 60px rgba(0,0,0,.35);max-width:430px;width:100%;margin:auto}'
      + '.rg-stars{font-size:1.8rem;letter-spacing:5px}'
      + '.rg-star{color:#D9CDBC}.rg-star.on{color:#F2B632;text-shadow:0 0 12px rgba(242,182,50,.55)}'
      + '.rg-final{font-family:var(--font-display,Impact,sans-serif);font-size:3.4rem;line-height:1;color:#E03131}'
      + '.rg-final small{font-size:1.3rem;color:#9A8C77}'
      + '.rg-final-l{color:#6B5B45;margin:4px 0 12px;font-size:.92rem}'
      + '.rg-statrow{display:flex;justify-content:center;gap:18px;flex-wrap:wrap;margin:0 0 12px}'
      + '.rg-statrow span{display:flex;flex-direction:column;font-size:.66rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#9A8C77}'
      + '.rg-statrow b{font-size:1.15rem;color:#2B2B33;font-variant-numeric:tabular-nums;letter-spacing:0}'
      + '.rg-insight{text-align:left;background:#F6EBDD;border-radius:12px;padding:12px 14px;margin:0 0 12px}'
      + '.rg-insight-h{font-size:.7rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#C2453A;margin-bottom:6px}'
      + '.rg-insight p{margin:0 0 6px;font-size:.84rem;line-height:1.5;color:#4A3E2E}'
      + '.rg-insight p:last-child{margin-bottom:0}'
      + '.rg-bestline{font-size:.84rem;font-weight:700;color:#9A8C77;margin:0 0 12px}'
      + '.rg-bestline.new{color:#1E9E66}'
      + '.rg-result-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}'
      + '.rg-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:16px}'
      + '.rg-field{display:flex;flex-direction:column;gap:5px;font-size:.76rem;font-weight:700;color:var(--text-muted,#B9A88F)}'
      + '.rg-field select{padding:9px 12px;border-radius:10px;border:1.5px solid var(--border-strong,#7a6a52);background:var(--bg-raised,#352A1F);color:var(--text,#FCF8F3);min-height:42px}'
      + '.rg-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:11px;border:1px solid var(--border-strong,#7a6a52);background:var(--bg-raised,#352A1F);color:var(--text,#FCF8F3);font-weight:700;font-size:.9rem;cursor:pointer;min-height:44px}'
      + '.rg-result-card .rg-btn{border-color:#D9CDBC;background:#FFFDF8;color:#4A3E2E}'
      + '.rg-primary{background:linear-gradient(135deg,#E8961F,#F7A823);border-color:transparent;color:#1A120B}'
      + '.rg-result-card .rg-primary{background:linear-gradient(135deg,#E8961F,#F7A823);color:#1A120B;border-color:transparent}'
      + '.rg-primary.rg-on{background:linear-gradient(135deg,#F4423B,#FF6B6B);color:#fff}'
      + '.rg-status{flex:1 1 200px;min-width:0;font-size:.82rem;color:var(--text-muted,#B9A88F)}'
      + '.rg-foot{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:8px}'
      + '.rg-tap-hint{font-size:.78rem;color:var(--text-faint,#8d7f6a)}'
      + '.rg-tap-hint kbd{padding:1px 7px;border:1px solid var(--border,#5a4a35);border-radius:5px;font-size:.74rem}'
      + '.rg-best{font-size:.78rem;font-weight:700;color:var(--gold-bright,#FCC647)}'
      + '@media (max-width:480px){.rg-grid{grid-template-columns:repeat(4,minmax(52px,1fr));gap:9px}.rg-title{font-size:1.8rem}.rg-board{padding:20px 12px 16px}.rg-note{font-size:2rem}.rg-note.sm{font-size:1.3rem}.rg-count b{font-size:3.6rem}}';
    var st = document.createElement('style'); st.id = 'rg-css'; st.textContent = css;
    document.head.appendChild(st);
  }

  function init() {
    root = document.getElementById('rhythmGame');
    if (!root || root.__rg) return; root.__rg = 1;
    try { injectCSS(); build(); } catch (e) { if (window.console) console.error('[MAM rhythm]', e); }
  }
  window.MAM_RHYTHM = { init: init };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
