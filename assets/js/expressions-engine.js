/* MAM Academy — "One song, three expressions" · arrangement engine (window.MAM_EXPR)
 * ---------------------------------------------------------------------------
 * Takes one melody and arranges it three ways, the way a musician would, not by
 * swapping a sound: it reads the tune first (key, beat, phrases), then makes the
 * decisions each tradition makes.
 *
 *   analyse()       key by Krumhansl–Schmuckler profiles (major and minor), the
 *                   bar and strong-beat grid, and phrases (4-bar units, 2 for a
 *                   short tune) with the kind of close each phrase wants.
 *   harmonise()     one chord per bar chosen by dynamic programming over the
 *                   diatonic chords: how well each chord holds the melody's
 *                   strong-beat notes, plus the pull of functional harmony
 *                   (ii→V→I, IV→V, never V→IV). Phrase ends are cadences:
 *                   the last is authentic (V–I), inner ones may close on V.
 *   classical()     four-part voicing with voice-leading (smallest motion, no
 *                   parallel fifths or octaves, the leading tone rises), a
 *                   cadential 6/4 at the final close, Alberti broken chords in
 *                   the phrase and block chords at the cadences, a ritardando
 *                   into the last bar. Roman numerals for the score.
 *   contemporary()  reharmonised: added ninths and thirteenths, sus4 that
 *                   resolves to the dominant, ii–V into each cadence, a passing
 *                   diminished chord where the melody allows it, a tritone
 *                   substitute on the final cadence only when no melody note
 *                   clashes with it. The melody anticipates the downbeat; drums
 *                   with ghost notes, bass with chromatic approach notes, rootless
 *                   keyboard voicings comping on the off-beats. Chord symbols.
 *   african()       re-timed onto the 12-pulse bell cycle (4/4 → 12/8, 3/4 → 6/8
 *                   pairs), the melody's own notes kept, every one, where they
 *                   carry weight, phrases sung as call and response with the
 *                   chorus in parallel thirds and fourths, the standard 7-stroke
 *                   bell, shekere, a dùndún that answers the call's contour in the
 *                   gaps, and a highlife guitar-and-bass ostinato on I, IV and V.
 *                   Written in the MAM 6-line staff: six lines and five spaces,
 *                   doh on the bottom line (d r m f s l t d' r' m' f').
 *
 * Pure and DOM-free so it runs under Node (assets/js/expressions-engine.selftest.cjs,
 * which CI executes). Times come out in seconds for expressions-audio.js and in
 * beats or pulses for the renderers in expressions.js.
 */
(function (root, factory) {
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (typeof window !== 'undefined') window.MAM_EXPR = api;
})(this, function () {
  'use strict';

  var EPS = 1e-6;
  function near(a, b) { return Math.abs(a - b) < 1e-4; }
  function mod(n, m) { return ((n % m) + m) % m; }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }

  /* ── Pitch spelling ─────────────────────────────────────────────────────── */
  var SHARP_NAMES = ['C', 'C♯', 'D', 'D♯', 'E', 'F', 'F♯', 'G', 'G♯', 'A', 'A♯', 'B'];
  var FLAT_NAMES = ['C', 'D♭', 'D', 'E♭', 'E', 'F', 'G♭', 'G', 'A♭', 'A', 'B♭', 'B'];
  var FLAT_KEYS = { 5: 1, 10: 1, 3: 1, 8: 1, 1: 1 }; // F B♭ E♭ A♭ D♭ (major tonics)
  function prefersFlat(key) { var t = key.mode === 'minor' ? mod(key.tonic + 3, 12) : key.tonic; return !!FLAT_KEYS[t]; }
  function pcName(pc, flat) { return (flat ? FLAT_NAMES : SHARP_NAMES)[mod(pc, 12)]; }

  /* ── Song timeline ──────────────────────────────────────────────────────── */
  function barLen(song) { var ts = song.timeSig || [4, 4]; return ts[0] * 4 / ts[1]; }
  function timeline(song) {
    var L = barLen(song), t = song.pickup ? L - song.pickup : 0, ev = [];
    song.notes.forEach(function (n, i) { ev.push({ i: i, midi: n.midi, t: t, d: n.beats }); t += n.beats; });
    return { L: L, ev: ev, total: t, nBars: Math.max(1, Math.ceil(t / L - EPS)) };
  }

  /* ── Key: Krumhansl–Schmuckler ──────────────────────────────────────────── */
  var KS_MAJOR = [6.35, 2.23, 3.48, 2.33, 4.38, 4.09, 2.52, 5.19, 2.39, 3.66, 2.29, 2.88];
  var KS_MINOR = [6.33, 2.68, 3.52, 5.38, 2.60, 3.53, 2.54, 4.75, 3.98, 2.69, 3.34, 3.17];
  function corr(a, b) {
    var ma = 0, mb = 0, i; for (i = 0; i < 12; i++) { ma += a[i]; mb += b[i]; } ma /= 12; mb /= 12;
    var n = 0, da = 0, db = 0; for (i = 0; i < 12; i++) { n += (a[i] - ma) * (b[i] - mb); da += (a[i] - ma) * (a[i] - ma); db += (b[i] - mb) * (b[i] - mb); }
    return (da && db) ? n / Math.sqrt(da * db) : 0;
  }
  function detectKey(ev, L) {
    var h = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], last = null, first = null;
    ev.forEach(function (e) {
      if (e.midi == null) return;
      // Duration-weighted, with a little extra for strong beats.
      h[mod(e.midi, 12)] += e.d * (near(mod(e.t, L), 0) ? 1.5 : 1);
      if (first == null) first = e.midi; last = e.midi;
    });
    var best = { tonic: 0, mode: 'major', r: -2 };
    for (var t = 0; t < 12; t++) {
      var rot = h.slice(t).concat(h.slice(0, t));
      var rM = corr(rot, KS_MAJOR), rm = corr(rot, KS_MINOR);
      // Tunes end on their tonic far more often than not: a small nudge settles
      // relative-key ties the profile alone cannot (C major vs A minor).
      if (last != null && mod(last, 12) === t) { rM += 0.05; rm += 0.05; }
      if (rM > best.r) best = { tonic: t, mode: 'major', r: rM };
      if (rm > best.r) best = { tonic: t, mode: 'minor', r: rm };
    }
    return best;
  }

  /* ── Analysis ───────────────────────────────────────────────────────────── */
  function analyse(song) {
    var tl = timeline(song), L = tl.L;
    var key = detectKey(tl.ev, L);
    key.flat = prefersFlat(key);
    key.name = pcName(key.tonic, key.flat) + (key.mode === 'minor' ? ' minor' : ' major');
    var phraseBars = tl.nBars >= 8 ? 4 : 2, ends = [];
    for (var b = phraseBars - 1; b < tl.nBars; b += phraseBars) ends.push(b);
    if (ends[ends.length - 1] !== tl.nBars - 1) {
      // A short last phrase joins the one before rather than standing alone.
      if (ends.length && tl.nBars - 1 - ends[ends.length - 1] < phraseBars / 2) ends[ends.length - 1] = tl.nBars - 1;
      else ends.push(tl.nBars - 1);
    }
    var phrases = [], s = 0;
    ends.forEach(function (e, i) { phrases.push({ index: i, startBar: s, endBar: e, final: i === ends.length - 1 }); s = e + 1; });
    function strong(t) { var x = mod(t, L); return near(x, 0) || (L === 4 && near(x, 2)) || (L === 6 && near(x, 3)); }
    return { song: song, L: L, ev: tl.ev, total: tl.total, nBars: tl.nBars, key: key, phrases: phrases, strong: strong };
  }

  /* ── Diatonic chords ────────────────────────────────────────────────────── */
  var MAJOR_CHORDS = [
    { rn: 'I', root: 0, q: 'maj', fn: 'T' }, { rn: 'ii', root: 2, q: 'min', fn: 'S' }, { rn: 'iii', root: 4, q: 'min', fn: 'T' },
    { rn: 'IV', root: 5, q: 'maj', fn: 'S' }, { rn: 'V', root: 7, q: 'maj', fn: 'D' }, { rn: 'vi', root: 9, q: 'min', fn: 'T' }
  ];
  var MINOR_CHORDS = [
    { rn: 'i', root: 0, q: 'min', fn: 'T' }, { rn: 'iv', root: 5, q: 'min', fn: 'S' }, { rn: 'V', root: 7, q: 'maj', fn: 'D' },
    { rn: 'VI', root: 8, q: 'maj', fn: 'T' }, { rn: 'III', root: 3, q: 'maj', fn: 'T' }, { rn: 'VII', root: 10, q: 'maj', fn: 'D' }
  ];
  var QUAL = { maj: [0, 4, 7], min: [0, 3, 7], dim: [0, 3, 6] };
  function chordPcs(c) { var p = QUAL[c.q].map(function (x) { return mod(c.root + x, 12); }); if (c.seventh) p.push(mod(c.root + (c.q === 'maj' && c.fn === 'D' ? 10 : (c.q === 'maj' ? 11 : 10)), 12)); return p; }
  // Functional pull, from → to (rows/cols by rn). Unlisted pairs score 0.
  var PULL = {
    I: { IV: .6, V: .6, vi: .45, ii: .45, iii: .1, I: .15 },
    ii: { V: .95, IV: .1, ii: .1, I: -.4, vi: -.2 },
    iii: { vi: .6, IV: .4, ii: .2, I: .0 },
    IV: { V: .7, I: .45, ii: .3, IV: .15, vi: .1 },
    V: { I: .9, vi: .45, V: .2, IV: -.6, ii: -.6, iii: -.2 },
    vi: { ii: .6, IV: .55, V: .3, I: .1, iii: .1 },
    i: { iv: .6, V: .6, VI: .45, III: .3, VII: .2, i: .15 },
    iv: { V: .8, i: .4, VII: .3, iv: .1 },
    VI: { iv: .5, V: .4, III: .3, VII: .2 },
    III: { VI: .5, iv: .4, VII: .3 },
    VII: { III: .6, i: .3, V: .1 }
  };

  function melodyIn(an, t0, t1) {
    var out = [];
    an.ev.forEach(function (e) {
      if (e.midi == null) return;
      var a = Math.max(e.t, t0), b = Math.min(e.t + e.d, t1);
      if (b - a > EPS) out.push({ midi: e.midi, pc: mod(e.midi - an.key.tonic, 12), t: a, d: b - a, onset: e.t >= t0 - EPS, strong: an.strong(a) && e.t >= t0 - EPS, short: e.d <= 0.5 });
    });
    return out;
  }
  function fitScore(an, c, t0, t1) {
    // The dominant is heard with its seventh (V7 holds fa), so fa counts as a chord tone of V.
    var pcs = chordPcs(c.fn === 'D' && c.q === 'maj' ? Object.assign({}, c, { seventh: true }) : c), s = 0;
    if (c.rn === 'iii') s -= 0.5;   // the mediant is a colour, rarely the right first choice
    melodyIn(an, t0, t1).forEach(function (m) {
      var w = m.d * (m.strong ? 1.7 : 1);
      if (pcs.indexOf(m.pc) >= 0) s += w; else s -= (m.short && !m.strong ? 0.15 : 0.85) * w;
    });
    return s;
  }

  // Harmonic rhythm of a half bar in 4/4 (a bar in 3/4), chosen by Viterbi over the
  // diatonic set: melody fit + functional pull, a small cost for changing chord
  // inside a bar (one chord a bar is the default; two when the tune asks), and
  // cadences at phrase ends. Adjacent identical slots in a bar merge.
  function harmonise(an) {
    var set = an.key.mode === 'minor' ? MINOR_CHORDS : MAJOR_CHORDS, L = an.L, tonicRn = set[0].rn;
    var defs = L === 4 ? [[0, 2], [2, 2]] : (L === 3 ? [[0, 2], [2, 1]] : [[0, L]]), per = defs.length, slots = [];
    for (var b = 0; b < an.nBars; b++) defs.forEach(function (d, k) { slots.push({ bar: b, k: k, start: b * L + d[0], dur: d[1] }); });
    var endOf = {}, penult = {};
    an.phrases.forEach(function (p) { endOf[p.endBar] = p; if (p.endBar - 1 >= p.startBar) penult[p.endBar - 1] = p; });
    function q0(sl) { var q = penult[sl.bar]; return q && q.final && sl.k === per - 1; }
    function local(i, c) {
      var sl = slots[i], s = fitScore(an, c, sl.start, sl.start + sl.dur), lastK = sl.k === per - 1;
      if (i === 0 && c.rn === tonicRn) s += 1.2;
      var p = endOf[sl.bar];
      if (p && lastK) {
        if (p.final) s += (c.rn === tonicRn) ? 3 : -99;
        else if (c.rn === tonicRn) s += 0.6; else if (c.rn === 'V') s += 0.5; else s -= 1.5;
      }
      if (p && p.final && per === 2 && sl.k === 0 && c.rn === 'V') s += 0.4;   // V–I inside the last bar
      if (q0(sl) && c.rn === 'V') s += 0.2;
      var q = penult[sl.bar];
      if (q && q.final && lastK) { if (c.rn === 'V') s += 1.4; else if (c.fn === 'S') s += 0.3; else s -= 0.8; }
      return s;
    }
    var n = slots.length, score = [], back = [];
    for (var i = 0; i < n; i++) {
      score.push([]); back.push([]);
      for (var j = 0; j < set.length; j++) {
        var l = local(i, set[j]);
        if (i === 0) { score[i][j] = l; back[i][j] = -1; continue; }
        var best = -Infinity, bi = 0, sameBar = slots[i].bar === slots[i - 1].bar;
        for (var h = 0; h < set.length; h++) {
          var v = score[i - 1][h] + ((PULL[set[h].rn] || {})[set[j].rn] || 0);
          if (sameBar && h !== j) v -= (slots[i].dur < 2 ? 1.1 : 0.55);
          if (v > best) { best = v; bi = h; }
        }
        score[i][j] = best + l; back[i][j] = bi;
      }
    }
    var last = 0; for (j = 1; j < set.length; j++) if (score[n - 1][j] > score[n - 1][last]) last = j;
    var pick = new Array(n); pick[n - 1] = last;
    for (i = n - 1; i > 0; i--) pick[i - 1] = back[i][pick[i]];
    var out = [];
    pick.forEach(function (kk, idx) {
      var sl = slots[idx], prev = out[out.length - 1];
      if (prev && prev.bar === sl.bar && prev.rn === set[kk].rn) { prev.dur += sl.dur; return; }
      var c = clone(set[kk]); c.start = sl.start; c.dur = sl.dur; c.bar = sl.bar; out.push(c);
    });
    out.forEach(function (c, idx) {
      var p = endOf[c.bar], lastInBar = !out[idx + 1] || out[idx + 1].bar !== c.bar;
      c.cadence = (p && lastInBar) ? (p.final || c.rn !== 'V' ? 'authentic' : 'half') : null;
    });
    return out;
  }

  /* ── Time maps ──────────────────────────────────────────────────────────── */
  function beatClock(an, ritard) {
    var spb = 60 / (an.song.tempo || 90), r0 = an.total - an.L, k = 0.28;
    return function (b) {
      if (!ritard || b <= r0) return b * spb;
      var x = Math.min(b, an.total + an.L) - r0;
      return r0 * spb + spb * (an.L / k) * -Math.log(Math.max(0.05, 1 - k * x / an.L));
    };
  }
  function human(seed) { var s = seed || 7; return function (amt) { s = (s * 9301 + 49297) % 233280; return (s / 233280 - 0.5) * 2 * amt; }; }

  /* ── Classical ──────────────────────────────────────────────────────────── */
  function inRange(lo, hi, pcs) { var out = []; for (var m = lo; m <= hi; m++) if (pcs.indexOf(mod(m, 12)) >= 0) out.push(m); return out; }
  function parallel(a0, a1, b0, b1) {
    // Parallel perfect fifth or octave between two voices moving in the same direction.
    if (a0 === a1 && b0 === b1) return false;
    var i0 = mod(a0 - b0, 12), i1 = mod(a1 - b1, 12);
    return (i0 === i1) && (i0 === 0 || i0 === 7) && (a0 !== a1) && Math.sign(a1 - a0) === Math.sign(b1 - b0);
  }
  function sopranoAt(an, t) {
    var cur = null; an.ev.forEach(function (e) { if (e.midi != null && e.t <= t + EPS) cur = e.midi; });
    if (cur == null) for (var i = 0; i < an.ev.length; i++) if (an.ev[i].midi != null) { cur = an.ev[i].midi; break; }
    return cur;
  }
  function voice(an, chords) {
    var lt = mod(an.key.tonic + 11, 12), prev = null, out = [];
    chords.forEach(function (c) {
      var pcs = chordPcs(c).map(function (x) { return mod(x + an.key.tonic, 12); }), S = sopranoAt(an, c.start), root = mod(an.key.tonic + c.root, 12);
      var third = mod(root + (c.q === 'maj' ? 4 : 3), 12), fifth = mod(root + (c.q === 'dim' ? 6 : 7), 12);
      // Root position by default; first inversion is open to the search (at a cost)
      // so voice-leading can avoid parallels without doubling the leading tone.
      var bassOpts = c.inv === 2 ? inRange(36, 55, [fifth]) : inRange(36, 55, [root]).concat(c.cadential || c.fn === 'P' ? [] : inRange(36, 55, [third]));
      var best = null;
      bassOpts.forEach(function (B) {
        var invCost = (c.inv !== 2 && mod(B, 12) === third) ? 14 : 0;
        inRange(48, 67, pcs).forEach(function (T) {
          inRange(55, 74, pcs).forEach(function (A) {
            if (!(S > A && A >= T && T > B)) return;
            if (S - A > 12 || A - T > 12) return;
            var have = [mod(S, 12), mod(A, 12), mod(T, 12), mod(B, 12)];
            var cost = 0;
            if (have.indexOf(third) < 0) cost += 30;              // a triad needs its third
            if (!c.seventh && c.inv !== 2 && have.indexOf(fifth) < 0) cost += 3;
            if (have.filter(function (p) { return p === lt; }).length > 1) cost += 25; // never double the leading tone
            if (c.seventh && have.indexOf(mod(root + 10, 12)) < 0) cost += 12;
            if (prev) {
              cost += Math.abs(A - prev.A) + Math.abs(T - prev.T) + 0.5 * Math.abs(B - prev.B);
              var pairs = [[S, prev.S, B, prev.B], [S, prev.S, A, prev.A], [S, prev.S, T, prev.T], [A, prev.A, T, prev.T], [A, prev.A, B, prev.B], [T, prev.T, B, prev.B]];
              pairs.forEach(function (p, k) { if (parallel(p[1], p[0], p[3], p[2])) cost += (k === 0 ? 200 : 40); });
              // The leading tone, in an inner voice, rises to the tonic at V→I.
              [['A', A], ['T', T]].forEach(function (v) {
                if (mod(prev[v[0]], 12) === lt && prev.fn === 'D' && c.fn === 'T' && v[1] !== prev[v[0]] + 1) cost += 15;
              });
            } else cost += Math.abs(B - 43) * 0.3;
            cost += invCost;
            if (!best || cost < best.cost) best = { S: S, A: A, T: T, B: B, cost: cost, inv: invCost ? 1 : (c.inv || 0) };
          });
        });
      });
      if (!best) best = { S: S, A: S - 4, T: S - 9, B: bassOpts[0] || 43, cost: 999 };
      best.fn = c.fn; best.rn = c.rn; best.start = c.start; best.dur = c.dur;
      if (best.inv === 1 && c.inv !== 2) { c.inv = 1; c.figure = c.figure === '7' ? '6/5' : '6'; }
      out.push(best); prev = best;
    });
    // Outer-voice parallels the inner search could not avoid: try the other inversion.
    for (var i = 1; i < out.length; i++) {
      if (parallel(out[i - 1].S, out[i].S, out[i - 1].B, out[i].B) && chords[i].inv === 0 && !chords[i].cadential) {
        var c = chords[i], root = mod(an.key.tonic + c.root, 12), third = mod(root + (c.q === 'maj' ? 4 : 3), 12);
        var nb = inRange(36, 55, [third]).sort(function (a, b) { return Math.abs(a - out[i - 1].B) - Math.abs(b - out[i - 1].B); })[0];
        var ltPc = mod(an.key.tonic + 11, 12), dbl = mod(nb, 12) === ltPc && [out[i].S, out[i].A, out[i].T].some(function (m) { return mod(m, 12) === ltPc; });
        if (nb != null && nb < out[i].T && !dbl && !parallel(out[i - 1].S, out[i].S, out[i - 1].B, nb)) { out[i].B = nb; c.inv = 1; c.figure = '6'; }
      }
    }
    return out;
  }
  // A tune pitched low (a sung melody often is) moves up an octave as a whole for
  // the keyboard styles, as a pianist would; never note by note, which breaks the line.
  function octaveLift(song) {
    var ms = song.notes.filter(function (x) { return x.midi != null; }).map(function (x) { return x.midi; });
    var mean = ms.reduce(function (a, b) { return a + b; }, 0) / Math.max(1, ms.length);
    if (mean >= 62) return song;
    var c = clone(song); c.notes.forEach(function (x) { if (x.midi != null) x.midi += 12; }); return c;
  }
  function classical(song) {
    song = octaveLift(song);
    var an = analyse(song), L = an.L, chords = harmonise(an), seq = [];
    var fin = an.phrases[an.phrases.length - 1];
    var finalV = -1;
    for (var ci = chords.length - 1; ci > 0; ci--) if (chords[ci - 1].rn === 'V' && ci === chords.length - 1) { finalV = ci - 1; break; }
    chords.forEach(function (c, ci) {
      // Cadential 6/4 → V7 on the dominant before the final tonic.
      if (ci === finalV && c.dur >= 2) {
        var half = c.dur / 2;
        seq.push(Object.assign(clone(c), { rn: an.key.mode === 'minor' ? 'i' : 'I', root: 0, q: an.key.mode === 'minor' ? 'min' : 'maj', fn: 'D', inv: 2, figure: '6/4', cadential: true, dur: half }));
        seq.push(Object.assign(clone(c), { start: c.start + half, dur: c.dur - half, seventh: true, figure: '7', inv: 0 }));
      } else if (ci === finalV) { seq.push(Object.assign(clone(c), { seventh: true, figure: '7', inv: 0 })); }
      else { c.inv = 0; seq.push(c); }
    });
    var voices = voice(an, seq), clock = beatClock(an, true), rnd = human(11), events = [];
    an.ev.forEach(function (e, k) {
      if (e.midi == null) return;
      var t0 = clock(e.t), t1 = clock(e.t + e.d);
      events.push({ t: t0 + rnd(0.006), dur: Math.max(0.08, (t1 - t0) * 0.96), inst: 'piano', midi: e.midi, vel: 0.62 + rnd(0.05) + (an.strong(e.t) ? 0.06 : 0), role: 'melody', ref: k });
    });
    var cadBars = {}; an.phrases.forEach(function (p) { cadBars[p.endBar] = 1; cadBars[p.endBar - 1] = 1; });
    // Left hand, from the voiced chord folded into the bass staff: Alberti eighths
    // (low – high – middle – high) inside the phrase, a block chord at cadences.
    var lh = [];
    seq.forEach(function (c, i) {
      var v = voices[i], lo = v.B, pcs = [mod(v.T, 12), mod(v.A, 12)].concat(chordPcs(c).map(function (x) { return mod(x + an.key.tonic, 12); }));
      var above = [];
      for (var m = lo + 1; m <= lo + 12 && above.length < 3; m++) if (pcs.indexOf(mod(m, 12)) >= 0 && above.indexOf(m) < 0) above.push(m);
      var mid = above[0] != null ? above[0] : lo + 4, hi = above[1] != null ? above[1] : lo + 7;
      if (cadBars[c.bar] || c.cadential) lh.push({ start: c.start, dur: c.dur, midis: [lo, mid, hi] });
      else {
        var pat = [lo, hi, mid, hi];
        for (var s2 = 0; s2 < c.dur * 2 - EPS; s2++) lh.push({ start: c.start + s2 * 0.5, dur: 0.5, midis: [pat[s2 % 4]], alberti: s2 % 4 });
      }
    });
    // A quiet string pad under the final chord, for the close.
    var lastLh = lh[lh.length - 1];
    if (lastLh) lastLh.midis.concat([sopranoAt(an, lastLh.start) - 12]).forEach(function (m) { events.push({ t: clock(lastLh.start), dur: clock(lastLh.start + lastLh.dur) - clock(lastLh.start) + 0.8, inst: 'strings', midi: m + 12, vel: 0.5, role: 'pad' }); });
    lh.forEach(function (x) {
      x.midis.forEach(function (m, j) {
        var t0 = clock(x.start), t1 = clock(x.start + x.dur);
        events.push({ t: t0 + j * 0.01 + rnd(0.008), dur: (t1 - t0) * (x.alberti != null ? 1.6 : 0.98), inst: 'piano', midi: m, vel: (x.alberti != null ? (x.alberti === 0 ? 0.36 : 0.28) : 0.42) + rnd(0.03), role: 'lh' });
      });
    });
    var flat = an.key.flat;
    return {
      style: 'classical', key: an.key, analysis: an, chords: seq, voices: voices, events: sortEv(events),
      score: {
        barTicks: L * 2, timeSig: song.timeSig || [4, 4], anacrusis: (song.pickup || 0) * 2, endTick: Math.ceil(an.total / L - EPS) * L * 2,
        rh: an.ev.map(function (e) { return { start: e.t * 2, len: e.d * 2, midi: e.midi, ref: e.midi == null ? null : e.i }; }).filter(function (x) { return x.midi != null; }),
        lh: lh.map(function (x) { return { start: x.start * 2, len: x.dur * 2, midis: x.midis }; }),
        roman: seq.map(function (c) { return { tick: c.start * 2, text: c.rn, figure: c.figure || '' }; })
      },
      length: clock(an.total) + 1.6,
      roman: seq.map(function (c) { return { start: c.start, dur: c.dur, rn: c.rn + (c.figure && c.figure !== '6/4' && c.figure !== '7' ? c.figure : ''), figure: c.figure || '', name: pcName(an.key.tonic + c.root, flat) }; }),
      caption: 'Classical, in ' + an.key.name + ': four-part harmony (' + uniq(seq.map(function (c) { return c.rn; })).join(', ') + '), Alberti broken chords in the left hand, block chords at the cadences, a cadential 6/4 into V7–I and a ritardando at the close.'
    };
  }

  /* ── Contemporary ───────────────────────────────────────────────────────── */
  var DOM_TENS = { '13': [4, 9, 10, 2], '9sus4': [5, 10, 2, 7], '7': [4, 10, 2, 9], 'alt': [4, 10, 1, 8] };
  function symbolFor(c, an, melodyPcs) {
    var maj = an.key.mode !== 'minor';
    if (c.kind === 'dim7') return { sym: '°7', tones: [0, 3, 6, 9] };
    if (c.kind === 'tritone') return { sym: '9', tones: [4, 10, 2, 9] };
    if (c.kind === 'sus') return { sym: '9sus4', tones: DOM_TENS['9sus4'] };
    if (c.fn === 'D' && c.q === 'maj') return { sym: '13', tones: DOM_TENS['13'] };
    if (c.q === 'min') return c.rn === 'iii' ? { sym: 'm7', tones: [3, 7, 10, 14] } : { sym: 'm9', tones: [3, 7, 10, 14] };
    // Tonic major: 6/9 when the melody sits on the root (a major seventh under it rubs), else maj9.
    var rootPc = c.root;
    if (melodyPcs.indexOf(rootPc) >= 0) return { sym: '6/9', tones: [4, 9, 14, 7] };
    return { sym: maj ? 'maj9' : 'maj7', tones: [4, 7, 11, 14] };
  }
  function contemporaryChords(an) {
    var base = harmonise(an), L = an.L, out = [];
    var fin = an.phrases[an.phrases.length - 1];
    var endBars = {}; an.phrases.forEach(function (p) { endBars[p.endBar] = p; });
    base.forEach(function (c, i) {
      var next = base[i + 1];
      var into = next && endBars[next.bar] && (next.rn === 'I' || next.rn === 'i');
      if (into && (c.rn === 'V' || c.rn === 'IV' || c.rn === 'ii') && c.dur >= 2) {
        var h = c.dur / 2, finalCad = endBars[next.bar].final;
        out.push(Object.assign(clone(c), { rn: 'ii', root: 2, q: 'min', fn: 'S', dur: h, kind: null }));
        var dom = Object.assign(clone(c), { rn: 'V', root: 7, q: 'maj', fn: 'D', start: c.start + h, dur: c.dur - h, kind: null });
        if (finalCad) {
          // Tritone substitute (♭II7) only if no melody note in its span clashes.
          var ok = true, allowed = [1, 5, 8, 11, 3, 7, 10].map(function (x) { return x; });
          melodyIn(an, dom.start, dom.start + dom.dur).forEach(function (m) { if (m.strong || m.d >= 0.5) { if (allowed.indexOf(m.pc) < 0) ok = false; } });
          if (ok) { dom.kind = 'tritone'; dom.root = 1; dom.rn = '♭II7'; }
        }
        out.push(dom);
        return;
      }
      if (c.fn === 'D' && c.q === 'maj' && !c.cadence && c.dur >= 4) {
        out.push(Object.assign(clone(c), { dur: c.dur / 2, kind: 'sus' }));
        out.push(Object.assign(clone(c), { start: c.start + c.dur / 2, dur: c.dur / 2, kind: null }));
        return;
      }
      if ((c.rn === 'I') && next && next.rn === 'ii' && c.dur >= 2) {
        // Passing ♯i°7 on the last beat, if the melody there is a chord tone of it.
        var t0 = c.start + c.dur - 1, fits = true;
        melodyIn(an, t0, t0 + 1).forEach(function (m) { if ([1, 4, 7, 10].indexOf(m.pc) < 0 && m.d >= 0.5) fits = false; });
        if (fits) {
          out.push(Object.assign(clone(c), { dur: c.dur - 1 }));
          out.push(Object.assign(clone(c), { start: t0, dur: 1, rn: '♯i°7', root: 1, q: 'dim', fn: 'P', kind: 'dim7' }));
          return;
        }
      }
      out.push(c);
    });
    out.forEach(function (c) {
      var pcs = melodyIn(an, c.start, c.start + c.dur).map(function (m) { return m.pc; });
      var s = symbolFor(c, an, pcs);
      var flat = an.key.flat || c.kind === 'tritone';
      if (c.kind === 'dim7') flat = false;
      c.symbol = pcName(an.key.tonic + c.root, flat) + s.sym;
      c.tones = s.tones;
    });
    var merged = [];
    out.forEach(function (c) { var p = merged[merged.length - 1]; if (p && p.symbol === c.symbol && near(p.start + p.dur, c.start)) p.dur += c.dur; else merged.push(c); });
    return merged;
  }
  function contemporary(song) {
    song = octaveLift(song);
    var an = analyse(song), L = an.L, chords = contemporaryChords(an);
    var tempo = Math.round((song.tempo || 90) * 0.92), spb = 60 / tempo, rnd = human(23), events = [];
    function T(b) { return b * spb; }
    // Melody, with anticipations: a note on a downbeat, after a note of a beat
    // or more, arrives an eighth early (where the chord changes), as singers do.
    var mel = an.ev.map(function (e) { return { i: e.i, midi: e.midi, t: e.t, d: e.d }; }), pushed = 0;
    for (var k = 1; k < mel.length; k++) {
      var e = mel[k], p = mel[k - 1];
      if (e.midi == null || p.midi == null) continue;
      var onBar = near(mod(e.t, L), 0), chordChange = chords.some(function (c) { return near(c.start, e.t); });
      if (onBar && chordChange && p.d >= 1 && e.t > 0 && k < mel.length - 1 && (Math.round(e.t / L) % 2 === 1)) {
        p.d -= 0.5; e.t -= 0.5; e.d += 0.5; e.anticipated = true; pushed++;
      }
    }
    mel.forEach(function (e) {
      if (e.midi == null) return;
      events.push({ t: T(e.t) + rnd(0.01), dur: T(e.d) * 0.94, inst: 'lead', midi: e.midi, vel: 0.6 + rnd(0.06), role: 'melody', ref: e.i });
    });
    // Rootless keyboard voicings, voice-led around middle C, comping off the beat.
    var prevTop = 64;
    chords.forEach(function (c, idx) {
      var rootPc = mod(an.key.tonic + c.root, 12);
      var pcs = c.tones.map(function (x) { return mod(rootPc + x, 12); });
      var best = null;
      for (var base = 50; base <= 58; base++) {
        // Build upward from base: each tone the lowest instance at or above the previous.
        var v = [], cur = base - 1, okv = true;
        var order = pcs.slice();
        var rot = order.indexOf(mod(base, 12)) >= 0 ? order.indexOf(mod(base, 12)) : -1;
        if (rot < 0) continue;
        order = order.slice(rot).concat(order.slice(0, rot));
        order.forEach(function (pc) { var m = cur + 1; while (mod(m, 12) !== pc) m++; v.push(m); cur = m; });
        if (v[v.length - 1] > 72) okv = false;
        if (!okv) continue;
        var cost = Math.abs(v[v.length - 1] - prevTop) + Math.abs((v[0] + v[v.length - 1]) / 2 - 60) * 0.3;
        if (!best || cost < best.cost) best = { v: v, cost: cost };
      }
      if (!best) best = { v: pcs.map(function (pc) { return 55 + mod(pc - 7, 12); }).sort(function (a, b) { return a - b; }) };
      c.voicing = best.v; prevTop = best.v[best.v.length - 1];
      var hits = c.dur >= 4 ? [[0, 1.3], [1.5, 0.4], [2.5, 1.2]] : (c.dur >= 2 ? [[0, 1.2], [1.5, 0.4]] : [[0, c.dur * 0.9]]);
      if (L === 3 && c.dur >= 3) hits = [[0, 1.4], [2, 0.8]];
      hits.forEach(function (h) {
        if (h[0] >= c.dur - EPS) return;
        best.v.forEach(function (m, j) { events.push({ t: T(c.start + h[0]) + j * 0.008 + rnd(0.008), dur: T(h[1]), inst: 'epiano', midi: m, vel: 0.33 + rnd(0.04) + (h[0] === 0 ? 0.05 : 0), role: 'keys' }); });
      });
      // Bass: root, a fifth or octave on the "and" of 2 or on 3, then a chromatic approach into the next root.
      var bRoot = 36 + mod(rootPc - 0, 12); if (bRoot > 45) bRoot -= 12;
      var nxt = chords[idx + 1];
      events.push({ t: T(c.start) + rnd(0.006), dur: T(Math.min(1.4, c.dur * 0.7)), inst: 'bass', midi: bRoot, vel: 0.62, role: 'bass' });
      if (c.dur >= 2) events.push({ t: T(c.start + (L === 3 ? 2 : 2)) + rnd(0.006), dur: T(0.8), inst: 'bass', midi: bRoot + (c.kind === 'dim7' ? 6 : 7), vel: 0.5, role: 'bass' });
      if (nxt && c.dur >= 2) {
        var nRoot = 36 + mod(an.key.tonic + nxt.root, 12); if (nRoot > 45) nRoot -= 12;
        var appr = nRoot + (nRoot > bRoot ? -1 : 1);
        events.push({ t: T(c.start + c.dur - 0.5) + rnd(0.006), dur: T(0.45), inst: 'bass', midi: appr, vel: 0.46, role: 'bass' });
      }
    });
    // Drums: kick 1 and the "and" of 2 (or 1 in 3/4), snare 2 and 4 with ghost notes, eighth hats.
    for (var b = 0; b < an.nBars; b++) {
      var t0 = b * L, last = b === an.nBars - 1;
      var phraseStart = an.phrases.some(function (p) { return p.startBar === b; });
      if (phraseStart && b > 0) events.push({ t: T(t0), dur: 1.2, inst: 'crash', vel: 0.32, role: 'drums' });
      events.push({ t: T(t0), dur: 0.3, inst: 'kick', vel: 0.85, role: 'drums' });
      if (last) { events.push({ t: T(t0), dur: 2, inst: 'crash', vel: 0.4, role: 'drums' }); continue; }
      if (L === 4) {
        events.push({ t: T(t0 + 2.5), dur: 0.3, inst: 'kick', vel: 0.7, role: 'drums' });
        events.push({ t: T(t0 + 1), dur: 0.3, inst: 'snare', vel: 0.72, role: 'drums' });
        events.push({ t: T(t0 + 3), dur: 0.3, inst: 'snare', vel: 0.75, role: 'drums' });
        events.push({ t: T(t0 + 1.75), dur: 0.1, inst: 'snare', vel: 0.14, role: 'drums' });
        events.push({ t: T(t0 + 3.75), dur: 0.1, inst: 'snare', vel: 0.16, role: 'drums' });
      } else {
        events.push({ t: T(t0 + 2), dur: 0.3, inst: 'snare', vel: 0.7, role: 'drums' });
        events.push({ t: T(t0 + 1.5), dur: 0.3, inst: 'kick', vel: 0.55, role: 'drums' });
      }
      for (var h8 = 0; h8 < L * 2; h8++) {
        var open = L === 4 && h8 === 7 && b % 4 === 3;
        events.push({ t: T(t0 + h8 * 0.5) + rnd(0.005), dur: open ? 0.3 : 0.06, inst: open ? 'hatOpen' : 'hat', vel: (h8 % 2 ? 0.2 : 0.34) + rnd(0.04), role: 'drums' });
      }
    }
    return {
      style: 'contemporary', key: an.key, analysis: an, chords: chords, melody: mel, tempo: tempo, events: sortEv(events), length: T(an.total) + 1.8,
      score: {
        barTicks: L * 2, timeSig: song.timeSig || [4, 4], anacrusis: (song.pickup || 0) * 2, endTick: Math.ceil(an.total / L - EPS) * L * 2,
        melody: mel.filter(function (e) { return e.midi != null; }).map(function (e) { return { start: Math.round(e.t * 2), len: Math.round(e.d * 2), midi: e.midi, ref: e.i }; }),
        chords: chords.map(function (c) { return { tick: Math.round(c.start * 2), text: c.symbol }; })
      },
      caption: 'Contemporary, in ' + an.key.name + ' at ' + tempo + ' BPM: reharmonised as ' + uniq(chords.map(function (c) { return c.symbol; })).join(' · ') + '. ' + (pushed ? pushed + ' melody note' + (pushed > 1 ? 's anticipate' : ' anticipates') + ' the downbeat. ' : '') + 'Drums with ghost notes, bass with chromatic approach notes, rootless keyboard voicings.'
    };
  }

  /* ── African ────────────────────────────────────────────────────────────── */
  var DIATONIC_IDX = { 0: 0, 2: 1, 4: 2, 5: 3, 7: 4, 9: 5, 11: 6 };
  var SOLFA = ['d', 'r', 'm', 'f', 's', 'l', 't'];
  var BELL = [0, 2, 4, 5, 7, 9, 11];               // the standard 7-stroke pattern on 12 pulses
  var SHEKERE_SOFT = [0, 3, 6, 9], SHEKERE_ACC = [2, 5, 8, 11];
  // The 6-line staff: positions 0..10 bottom to top; even positions are lines.
  var SIX_LINE = ['d', 'r', 'm', 'f', 's', 'l', 't', "d'", "r'", "m'", "f'"];
  function sixLinePosition(midi, dohMidi) {
    var rel = midi - dohMidi, oct = Math.floor(rel / 12), pc = mod(rel, 12), sharp = false;
    var idx = DIATONIC_IDX[pc];
    if (idx == null) { idx = DIATONIC_IDX[pc - 1]; sharp = true; }   // chromatic: written on the lower degree, raised
    return { pos: oct * 7 + idx, sharp: sharp };
  }
  function solfaName(pos) {
    var oct = Math.floor(pos / 7), name = SOLFA[mod(pos, 7)];
    if (oct > 0) name += new Array(oct + 1).join("'");
    if (oct < 0) name += new Array(-oct + 1).join(',');
    return name;
  }
  function isLine(pos) { return mod(pos, 2) === 0; }

  function africanRetime(an) {
    var L = an.L, per = L === 3 ? 2 : 3;                // pulses per beat: 4/4 → 12/8, 3/4 → pairs of 6/8
    var fracMap3 = function (f) { return f < 0.125 ? 0 : (f < 0.375 ? 1 : (f < 0.875 ? 2 : 3)); };
    var fracMap2 = function (f) { return f < 0.25 ? 0 : (f < 0.75 ? 1 : 2); };
    var notes = [];
    an.ev.forEach(function (e) {
      var beat = Math.floor(e.t + EPS), f = e.t - beat;
      var p = beat * per + (per === 3 ? fracMap3(f) : fracMap2(f));
      notes.push({ i: e.i, midi: e.midi, pulse: p });
    });
    for (var k = 1; k < notes.length; k++) if (notes[k].pulse <= notes[k - 1].pulse) notes[k].pulse = notes[k - 1].pulse + 1;
    var endPulse = Math.ceil(an.total - EPS) * per;
    notes.forEach(function (n, k) { var nx = notes[k + 1]; n.len = (nx ? nx.pulse : Math.max(endPulse, n.pulse + 1)) - n.pulse; });
    return { notes: notes, per: per, endPulse: endPulse };
  }
  function african(song) {
    var an = analyse(song), L = an.L, chords = harmonise(an), rt = africanRetime(an), per = rt.per;
    var doTonic = an.key.mode === 'minor' ? mod(an.key.tonic + 3, 12) : an.key.tonic;   // la-based minor: doh is the relative major
    var melodic = rt.notes.filter(function (n) { return n.midi != null; });
    // The melody keeps every one of the song's own notes. An earlier "pentatonic
    // lean" moved fa and ti on the beat to the nearest pentatonic degree, which
    // turned Ode to Joy's m m f s into m m s s: the song stopped being the song.
    // The African character comes from the 12/8 re-feel, call and response, the
    // chorus harmony, the bell, shekere and dùndún, and the highlife band, never
    // from rewriting the tune.
    // Doh on the bottom line: the octave that keeps most notes on the staff.
    var bestDoh = null;
    for (var o = 3; o <= 5; o++) {
      var d = doTonic + 12 * o, inStaff = 0;
      melodic.forEach(function (n) { var p = sixLinePosition(n.midi, d).pos; if (p >= 0 && p <= 10) inStaff++; });
      if (!bestDoh || inStaff > bestDoh.n) bestDoh = { midi: d, n: inStaff };
    }
    var doh = bestDoh.midi;
    // Call and response by phrase: even phrases are the lead's call, odd the chorus.
    var cyclePulses = 12, barPulses = L * per;
    function phraseOf(pulse) { var bar = Math.floor(pulse / barPulses); for (var i = 0; i < an.phrases.length; i++) if (bar <= an.phrases[i].endBar) return an.phrases[i]; return an.phrases[an.phrases.length - 1]; }
    var LOWER = { 0: -3, 2: -5, 4: -4, 5: -3, 7: -3, 9: -5, 11: -4 };   // d→l, r→s(4th), m→d, f→r, s→m, l→m(4th), t→s
    var staff = [], events = [], rnd = human(37), tempo = song.tempo || 90;
    var pulseSec = 60 / tempo / per * (per === 2 ? 1 : 1);
    function P(p) { return p * pulseSec; }
    rt.notes.forEach(function (n) {
      if (n.midi == null) return;
      var ph = phraseOf(n.pulse), part = ph.index % 2 === 0 ? 'call' : 'response';
      var sl = sixLinePosition(n.midi, doh);
      staff.push({ ref: n.i, midi: n.midi, pos: sl.pos, sharp: sl.sharp, solfa: solfaName(sl.pos), pulse: n.pulse, len: n.len, part: part, leaned: !!n.leaned });
      events.push({ t: P(n.pulse) + rnd(0.012), dur: P(n.len) * 0.92, inst: part === 'call' ? 'voice' : 'chorus', midi: n.midi, vel: 0.62 + rnd(0.05), role: 'melody', ref: n.i });
      if (part === 'response') {
        var pc = mod(n.midi - doTonic, 12), drop = LOWER[pc] != null ? LOWER[pc] : -3;
        events.push({ t: P(n.pulse) + 0.018 + rnd(0.01), dur: P(n.len) * 0.9, inst: 'chorus', midi: n.midi + drop, vel: 0.44, role: 'harmony' });
      }
    });
    var cycles = Math.ceil(rt.endPulse / cyclePulses);
    var bell = [], shekere = [], drum = [];
    for (var c = 0; c < cycles; c++) {
      var base = c * cyclePulses;
      BELL.forEach(function (p, j) { bell.push(base + p); events.push({ t: P(base + p) + rnd(0.004), dur: 0.25, inst: j === 0 ? 'bellLow' : 'bellHigh', vel: (j === 0 ? 0.55 : 0.42) + rnd(0.04), role: 'bell' }); });
      SHEKERE_SOFT.forEach(function (p) { events.push({ t: P(base + p) + rnd(0.006), dur: 0.12, inst: 'shekere', vel: 0.16, role: 'shekere' }); });
      SHEKERE_ACC.forEach(function (p) { shekere.push(base + p); events.push({ t: P(base + p) + rnd(0.006), dur: 0.08, inst: 'shekereChk', vel: 0.32, role: 'shekere' }); });
      // Dùndún: answers in the cycle's last three pulses when the voice is holding
      // or resting, tracing the contour of that phrase's first notes.
      var busy = staff.some(function (s) { return s.pulse >= base + 9 && s.pulse < base + 12; });
      if (!busy) {
        var cyc = staff.filter(function (s) { return s.pulse >= base && s.pulse < base + 12; });
        var src = cyc.length ? cyc : staff.filter(function (s) { return s.pulse < base; }).slice(-3);
        var contour = src.slice(0, 3).map(function (s) { return s.midi; });
        while (contour.length < 3) contour.push(contour.length ? contour[contour.length - 1] : doh);
        var lo = Math.min.apply(null, contour), prevHz = null;
        contour.forEach(function (m, j) {
          var hz = 95 * Math.pow(2, (m - lo) / 18);
          drum.push(base + 9 + j);
          events.push({ t: P(base + 9 + j) + rnd(0.006), dur: 0.3, inst: 'talkingDrum', hz: prevHz || hz * 0.9, hzTo: hz, vel: 0.6 + (j === 0 ? 0.08 : 0), role: 'drum' });
          prevHz = hz;
        });
      }
    }
    // Highlife ostinato on I, IV and V: guitar arpeggio in the 12/8 grid, bass on 1, the 5th and an approach.
    var toHighlife = { I: 'I', ii: 'IV', iii: 'I', IV: 'IV', V: 'V', vi: 'I', i: 'I', iv: 'IV', VI: 'IV', III: 'I', VII: 'V' };
    var HL = { I: 0, IV: 5, V: 7 };
    var guitar = [0, 2, 3, 5, 6, 8, 9, 11], hlChords = [];
    chords.forEach(function (ch, idx) {
      var rn = toHighlife[ch.rn] || 'I', root = mod(doTonic + HL[rn], 12);
      var p0 = ch.bar * barPulses, p1 = p0 + barPulses;
      hlChords.push({ rn: rn, pulse: p0, len: barPulses, name: pcName(root, an.key.flat) });
      var tones = [root, mod(root + 4, 12), mod(root + 7, 12)].map(function (pc) { var m = 60; while (mod(m, 12) !== pc) m++; return m; });
      var arp = [tones[0] + 12, tones[1], tones[2], tones[1] + 12, tones[0] + 12, tones[2], tones[1], tones[2] + 12];
      guitar.forEach(function (g, j) {
        var pp = p0 + mod(g, barPulses);
        if (g >= barPulses) return;
        events.push({ t: P(pp) + rnd(0.006), dur: P(1.6), inst: 'guitar', midi: arp[j % arp.length] - 12, vel: (j % 2 ? 0.26 : 0.34) + rnd(0.03), role: 'guitar' });
      });
      var bassRoot = 36 + root; if (bassRoot > 45) bassRoot -= 12;
      events.push({ t: P(p0), dur: P(per * 1.5), inst: 'bass', midi: bassRoot, vel: 0.62, role: 'bass' });
      if (barPulses >= 12) events.push({ t: P(p0 + 6), dur: P(per), inst: 'bass', midi: bassRoot + 7, vel: 0.5, role: 'bass' });
      var nx = chords[idx + 1];
      if (nx) { var nr = 36 + mod(doTonic + HL[toHighlife[nx.rn] || 'I'], 12); if (nr > 45) nr -= 12; events.push({ t: P(p1 - 2), dur: P(1.5), inst: 'bass', midi: nr + (nr > bassRoot ? -2 : 2), vel: 0.44, role: 'bass' }); }
    });
    return {
      style: 'african', key: an.key, analysis: an, dohMidi: doh, dohName: pcName(doTonic, an.key.flat), staff: staff,
      bell: bell, shekere: shekere, drum: drum, cycles: cycles, cyclePulses: cyclePulses, pulsesPerBeat: per, chords: hlChords,
      events: sortEv(events), length: P(cycles * cyclePulses) + 1.4,
      score: {
        barTicks: 12, timeSig: [12, 8], endTick: cycles * 12,
        voice: staff.map(function (x) { return { start: x.pulse, len: x.len, midi: x.midi, pos: x.pos, sharp: x.sharp, solfa: x.solfa, part: x.part, ref: x.ref }; }),
        bell: bell.map(function (p) { return { start: p, len: 1, head: 'x' }; }),
        shekere: shekere.map(function (p) { return { start: p, len: 1, head: 'slash' }; }),
        drum: drum.map(function (p, j) { return { start: p, len: 1, head: 'normal', step: j % 3 === 0 ? 1 : (j % 3 === 1 ? 0 : -1) }; })
      },
      caption: 'African, doh = ' + pcName(doTonic, an.key.flat) + ': re-timed onto the 12-pulse bell cycle (' + (per === 3 ? '4/4 becomes 12/8' : '3/4 becomes pairs of 6/8') + '), sung as call and response with the chorus in parallel thirds and fourths, the standard bell pattern, shekere, a dùndún answering the call in the gaps, and a highlife guitar and bass on ' + uniq(hlChords.map(function (c) { return c.rn; })).join(', ') + '.'
    };
  }


  /* ── Rhythm → note values (engraving), pure ─────────────────────────────── */
  // Ticks are eighth notes. Simple time (4/4, 3/4) beams by the quarter and lets a
  // syncopated quarter sit inside a half bar; 12/8 groups in dotted quarters
  // (3+3+3+3). Values that cross a bar line or a beat they may not cross are
  // split and tied, so every token is a written value: 8 ♪, q ♩, q. , h, h., w, w.
  var VALUE = { 1: ['8', 0], 2: ['q', 0], 3: ['q', 1], 4: ['h', 0], 6: ['h', 1], 8: ['w', 0], 12: ['w', 1] };
  function fits(v, p, bar, compound, rest) {
    if (compound) {
      if (v === 12) return p === 0;
      if (v === 6) return p % 6 === 0;
      if (v === 3) return p % 3 === 0;
      if (v === 2) return p % 3 !== 2;
      return v === 1;
    }
    if (v === 8) return p === 0 && bar === 8;
    if (v === 6) return p === 0;
    if (v === 4) return bar === 8 ? p % 4 === 0 : (p % 2 === 0 && p + 4 <= bar);
    if (v === 3) return p % 2 === 0 && (bar !== 8 || Math.floor(p / 4) === Math.floor((p + 2) / 4));
    if (v === 2) return p % 2 === 0 || (!rest && bar === 8 && p % 4 === 1);   // rests never syncopate
    return v === 1;
  }
  function splitValues(p, len, bar, compound, rest) {
    var order = compound ? [12, 6, 3, 2, 1] : [8, 6, 4, 3, 2, 1], out = [];
    while (len > 0) {
      for (var i = 0; i < order.length; i++) { var v = order[i]; if (v <= len && p + v <= bar && fits(v, p, bar, compound, rest)) { out.push(v); p += v; len -= v; break; } }
    }
    return out;
  }
  function notate(evs, opts) {
    var bar = opts.barTicks, compound = !!opts.compound, ana = opts.anacrusis || 0;
    var start0 = ana ? bar - ana : 0, list = evs.slice().sort(function (a, b) { return a.start - b.start; });
    var items = [], cur = start0;
    list.forEach(function (e) {
      if (e.start > cur) items.push({ start: cur, len: e.start - cur, rest: true });
      var len = Math.max(1, Math.min(e.len, (e.start + e.len) - Math.max(e.start, cur)));
      items.push(Object.assign({}, e, { start: Math.max(e.start, cur), len: len }));
      cur = Math.max(cur, e.start) + len;
    });
    var end = Math.max(cur, opts.endTick || 0), endBar = Math.ceil(end / bar) * bar;
    if (cur < endBar) items.push({ start: cur, len: endBar - cur, rest: true });
    var measures = {};
    function measure(i) { if (!measures[i]) measures[i] = { index: i, start: i === 0 && ana ? start0 : i * bar, tokens: [] }; return measures[i]; }
    items.forEach(function (it) {
      var s = it.start, remain = it.len, pieces = [];
      while (remain > 0) {
        var b = Math.floor(s / bar), inBar = s - b * bar, room = bar - inBar, take = Math.min(remain, room);
        splitValues(inBar, take, bar, compound, !!it.rest).forEach(function (v) { pieces.push({ bar: b, start: s, ticks: v }); s += v; });
        remain -= take;
      }
      pieces.forEach(function (pc, k) {
        var tok = { start: pc.start, ticks: pc.ticks, base: VALUE[pc.ticks][0], dots: VALUE[pc.ticks][1], rest: !!it.rest };
        ['midi', 'midis', 'ref', 'part', 'head', 'pos', 'sharp', 'solfa', 'step'].forEach(function (f) { if (it[f] != null) tok[f] = it[f]; });
        if (!it.rest) { tok.tiePrev = k > 0; tok.tieNext = k < pieces.length - 1; if (k > 0) tok.solfa = null; }
        measure(pc.bar).tokens.push(tok);
      });
    });
    var out = Object.keys(measures).map(Number).sort(function (a, b) { return a - b; }).map(function (k) { return measures[k]; });
    // Beams: consecutive eighths (not rests) inside one beat group.
    var gid = 0;
    out.forEach(function (m) {
      var groupLen = compound ? 3 : 2, run = [];
      function flush() { if (run.length > 1) { gid++; run.forEach(function (t) { t.beam = gid; }); } run = []; }
      m.tokens.forEach(function (t) {
        var g = Math.floor((t.start - m.index * bar) / groupLen);
        if (t.ticks === 1 && !t.rest && (!run.length || run[0].g === g)) { t.g = g; run.push(t); }
        else { flush(); if (t.ticks === 1 && !t.rest) { t.g = g; run.push(t); } }
      });
      flush();
    });
    return out;
  }

  /* ── Spelling in a key (for the 5-line staves) ──────────────────────────── */
  var SIG = { 0: 0, 7: 1, 2: 2, 9: 3, 4: 4, 11: 5, 6: 6, 5: -1, 10: -2, 3: -3, 8: -4, 1: -5 };
  var SHARP_ORDER = [3, 0, 4, 1, 5, 2, 6], FLAT_ORDER = [6, 2, 5, 1, 4, 0, 3]; // letter indices C=0 … B=6
  var NATURAL = [0, 2, 4, 5, 7, 9, 11];
  function keySignature(key) {
    var t = key.mode === 'minor' ? mod(key.tonic + 3, 12) : key.tonic, n = SIG[t] || 0, acc = [0, 0, 0, 0, 0, 0, 0];
    for (var i = 0; i < Math.abs(n); i++) acc[(n > 0 ? SHARP_ORDER : FLAT_ORDER)[i]] = n > 0 ? 1 : -1;
    return { count: n, acc: acc };
  }
  function spell(midi, key) {
    var ks = keySignature(key), pc = mod(midi, 12), letter = -1, acc = 0;
    for (var l = 0; l < 7; l++) if (mod(NATURAL[l] + ks.acc[l], 12) === pc) { letter = l; acc = ks.acc[l]; break; }
    if (letter < 0) for (l = 0; l < 7; l++) if (NATURAL[l] === pc) { letter = l; acc = 0; break; }
    if (letter < 0) {
      if (ks.count < 0) { for (l = 0; l < 7; l++) if (NATURAL[l] === mod(pc + 1, 12)) { letter = l; acc = -1; } }
      else { for (l = 0; l < 7; l++) if (NATURAL[l] === mod(pc - 1, 12)) { letter = l; acc = 1; } }
    }
    var octave = Math.floor((midi - acc) / 12) - 1;
    return { letter: letter, acc: acc, octave: octave, step: octave * 7 + letter, keyAcc: ks.acc[letter] };   // E4 = 30 (bottom treble line), G2 = 18 (bottom bass line)
  }

  function sortEv(ev) { return ev.sort(function (a, b) { return a.t - b.t; }).map(function (e) { e.t = Math.max(0, e.t); return e; }); }
  function uniq(a) { var s = {}, o = []; a.forEach(function (x) { if (!s[x]) { s[x] = 1; o.push(x); } }); return o; }

  /* ── Songs: traditional and public domain ───────────────────────────────── */
  function n(m, b) { return { midi: m, beats: b }; }
  var SONGS = [
    { id: 'ode', title: 'Ode to Joy', note: 'Beethoven, 1824', tempo: 100, timeSig: [4, 4], notes: [
      n(64,1),n(64,1),n(65,1),n(67,1), n(67,1),n(65,1),n(64,1),n(62,1), n(60,1),n(60,1),n(62,1),n(64,1), n(64,1.5),n(62,.5),n(62,2),
      n(64,1),n(64,1),n(65,1),n(67,1), n(67,1),n(65,1),n(64,1),n(62,1), n(60,1),n(60,1),n(62,1),n(64,1), n(62,1.5),n(60,.5),n(60,2) ] },
    { id: 'grace', title: 'Amazing Grace', note: 'Traditional (New Britain)', tempo: 84, timeSig: [3, 4], pickup: 1, notes: [
      n(62,1), n(67,2),n(71,.5),n(67,.5), n(71,2),n(69,1), n(67,2),n(64,1), n(62,2),n(62,1), n(67,2),n(71,.5),n(67,.5), n(71,2),n(69,1), n(74,3), n(74,2),n(71,1),
      n(74,2),n(71,.5),n(67,.5), n(71,2),n(69,1), n(67,2),n(64,1), n(62,2),n(62,1), n(67,2),n(71,.5),n(67,.5), n(71,2),n(69,1), n(67,3) ] },
    { id: 'twinkle', title: 'Twinkle, Twinkle', note: 'Traditional (French)', tempo: 104, timeSig: [4, 4], notes: [
      n(60,1),n(60,1),n(67,1),n(67,1), n(69,1),n(69,1),n(67,2), n(65,1),n(65,1),n(64,1),n(64,1), n(62,1),n(62,1),n(60,2),
      n(67,1),n(67,1),n(65,1),n(65,1), n(64,1),n(64,1),n(62,2), n(67,1),n(67,1),n(65,1),n(65,1), n(64,1),n(64,1),n(62,2),
      n(60,1),n(60,1),n(67,1),n(67,1), n(69,1),n(69,1),n(67,2), n(65,1),n(65,1),n(64,1),n(64,1), n(62,1),n(62,1),n(60,2) ] },
    { id: 'jacques', title: 'Frère Jacques', note: 'Traditional (French)', tempo: 108, timeSig: [4, 4], notes: [
      n(60,1),n(62,1),n(64,1),n(60,1), n(60,1),n(62,1),n(64,1),n(60,1), n(64,1),n(65,1),n(67,2), n(64,1),n(65,1),n(67,2),
      n(67,.5),n(69,.5),n(67,.5),n(65,.5),n(64,1),n(60,1), n(67,.5),n(69,.5),n(67,.5),n(65,.5),n(64,1),n(60,1), n(60,1),n(55,1),n(60,2), n(60,1),n(55,1),n(60,2) ] }
  ];

  return {
    analyse: analyse, harmonise: function (song) { return harmonise(analyse(song)); },
    classical: classical, contemporary: contemporary, african: african,
    arrange: function (song, style) { return style === 'classical' ? classical(song) : (style === 'african' ? african(song) : contemporary(song)); },
    detectKey: function (song) { var t = timeline(song); return detectKey(t.ev, t.L); },
    sixLinePosition: sixLinePosition, solfaName: solfaName, isLine: isLine, SIX_LINE: SIX_LINE, BELL: BELL,
    parallel: parallel, pcName: pcName, SONGS: SONGS,
    notate: notate, splitValues: splitValues, spell: spell, keySignature: keySignature
  };
});
