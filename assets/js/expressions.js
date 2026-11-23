/* MAM Academy — "One song, three expressions" · engraving + interface
 * ---------------------------------------------------------------------------
 * Mounts on every [data-expressions] element (the full showcase at /notation and
 * the compact one on /programmes). Pick a song, or sing or play one in, choose an
 * expression, and see and hear it arranged:
 *   Classical    — grand staff (treble + bass), key and time signature, Roman numerals
 *   Contemporary — a five-line lead sheet, treble clef, chord symbols above
 *   African      — the MAM 6-line staff (six lines, five spaces, doh on the bottom
 *                  line, solfa under each note) bracketed with one-line bell,
 *                  shekere and dùndún staves, in 12/8 with a bar per bell cycle.
 * Engraved in SVG from expressions-engine.js: note values with dots, beams in the
 * beat groups, flags, rests, ties across bar lines, ledger lines, accidentals. The
 * sounding notehead lights up during playback (expressions-audio.js).
 */
(function () {
  'use strict';
  var E = window.MAM_EXPR, A = window.MAM_EXPR_AUDIO;
  if (!E) return;
  var NS = 'http://www.w3.org/2000/svg';
  var INK = '#211A13', MUTED = '#574B40', RULE = '#3E342B', CALL = '#C27A12', RESP = '#211A13', GOLD = '#8A5208';

  function el(name, attrs, parent) {
    var e = document.createElementNS(NS, name);
    if (attrs) for (var k in attrs) if (attrs[k] != null) e.setAttribute(k, String(attrs[k]));
    if (parent) parent.appendChild(e);
    return e;
  }
  function txt(parent, x, y, s, attrs) { var t = el('text', Object.assign({ x: x, y: y }, attrs || {}), parent); t.textContent = s; return t; }

  /* ── Engraving constants ────────────────────────────────────────────────── */
  var SP = 9;                    // staff space (px)
  var HALF = SP / 2;
  var STEM = SP * 3.4;
  var MID = { treble: 4, bass: 4, six: 5, perc: 0 };

  // Clefs drawn as paths, scaled to SP (no music font needed).
  function trebleClef(g, x, yBottom) {
    var s = SP / 10, y = function (v) { return yBottom - v * s; }, X = function (v) { return x + v * s; };
    el('path', { d: 'M' + X(11) + ' ' + y(-16) + ' C ' + X(4) + ' ' + y(-20) + ' ' + X(0) + ' ' + y(-12) + ' ' + X(5) + ' ' + y(-8) +
      ' M' + X(11) + ' ' + y(-16) + ' C ' + X(14) + ' ' + y(4) + ' ' + X(13) + ' ' + y(30) + ' ' + X(13) + ' ' + y(48) +
      ' C ' + X(13) + ' ' + y(58) + ' ' + X(20) + ' ' + y(56) + ' ' + X(19) + ' ' + y(46) + ' C ' + X(17) + ' ' + y(34) + ' ' + X(-2) + ' ' + y(26) + ' ' + X(-1) + ' ' + y(12) +
      ' C ' + X(0) + ' ' + y(0) + ' ' + X(20) + ' ' + y(-2) + ' ' + X(22) + ' ' + y(12) + ' C ' + X(23) + ' ' + y(24) + ' ' + X(6) + ' ' + y(26) + ' ' + X(6) + ' ' + y(14) +
      ' C ' + X(6) + ' ' + y(7) + ' ' + X(14) + ' ' + y(6) + ' ' + X(15) + ' ' + y(12),
      fill: 'none', stroke: INK, 'stroke-width': SP * 0.22, 'stroke-linecap': 'round' }, g);
    el('circle', { cx: X(5.5), cy: y(-9.5), r: SP * 0.32, fill: INK }, g);
  }
  function bassClef(g, x, yBottom) {
    var yF = yBottom - 6 * HALF; // F line (4th)
    el('path', { d: 'M' + (x + 1) + ' ' + (yF + 1) + ' C ' + (x + 1) + ' ' + (yF - SP * 1.4) + ' ' + (x + SP * 2.6) + ' ' + (yF - SP * 1.3) + ' ' + (x + SP * 2.5) + ' ' + (yF + SP * 0.3) +
      ' C ' + (x + SP * 2.4) + ' ' + (yF + SP * 2) + ' ' + (x + SP * 0.6) + ' ' + (yF + SP * 3.2) + ' ' + (x - SP * 0.2) + ' ' + (yF + SP * 3.5),
      fill: 'none', stroke: INK, 'stroke-width': SP * 0.26, 'stroke-linecap': 'round' }, g);
    el('circle', { cx: x + SP * 0.45, cy: yF + SP * 0.15, r: SP * 0.38, fill: INK }, g);
    el('circle', { cx: x + SP * 3.1, cy: yF - SP * 0.5, r: SP * 0.22, fill: INK }, g);
    el('circle', { cx: x + SP * 3.1, cy: yF + SP * 0.5, r: SP * 0.22, fill: INK }, g);
  }
  // The MAM clef: a stylised "d" whose bowl sits on the bottom line — the doh line.
  function mamClef(g, x, yBottom) {
    el('ellipse', { cx: x + SP * 0.9, cy: yBottom - SP * 0.05, rx: SP * 0.95, ry: SP * 0.75, fill: 'none', stroke: INK, 'stroke-width': SP * 0.3 }, g);
    el('line', { x1: x + SP * 1.85, y1: yBottom + SP * 0.6, x2: x + SP * 1.85, y2: yBottom - 10 * HALF - SP * 0.4, stroke: INK, 'stroke-width': SP * 0.3, 'stroke-linecap': 'round' }, g);
    el('path', { d: 'M' + (x + SP * 1.85) + ' ' + (yBottom - 10 * HALF - SP * 0.4) + ' q ' + SP * 0.9 + ' ' + SP * 0.1 + ' ' + SP * 1.1 + ' ' + SP * 0.9, fill: 'none', stroke: INK, 'stroke-width': SP * 0.22, 'stroke-linecap': 'round' }, g);
  }
  function percClef(g, x, y) {
    el('rect', { x: x, y: y - SP * 1.1, width: SP * 0.35, height: SP * 2.2, fill: INK }, g);
    el('rect', { x: x + SP * 0.75, y: y - SP * 1.1, width: SP * 0.35, height: SP * 2.2, fill: INK }, g);
  }
  function timeSig(g, x, yBottom, ts, kind) {
    var top = kind === 'perc' ? yBottom - SP * 0.2 : yBottom - (kind === 'six' ? 7 : 6) * HALF, bot = kind === 'perc' ? yBottom + SP * 1.7 : yBottom - (kind === 'six' ? 3 : 2) * HALF;
    var fs = SP * 2.1;
    txt(g, x, top + fs * 0.35, String(ts[0]), { 'font-family': 'Cormorant Garamond, Georgia, serif', 'font-weight': 700, 'font-size': fs, fill: INK, 'text-anchor': 'middle' });
    txt(g, x, bot + fs * 0.35, String(ts[1]), { 'font-family': 'Cormorant Garamond, Georgia, serif', 'font-weight': 700, 'font-size': fs, fill: INK, 'text-anchor': 'middle' });
  }
  var KS_STEPS = { treble: { sharp: [8, 5, 9, 6, 3, 7, 4], flat: [4, 7, 3, 6, 2, 5, 1] }, bass: { sharp: [6, 3, 7, 4, 1, 5, 2], flat: [2, 5, 1, 4, 0, 3, -1] } };
  function keySig(g, x, yBottom, ks, kind) {
    var n = Math.abs(ks.count); if (!n) return 0;
    var list = KS_STEPS[kind][ks.count > 0 ? 'sharp' : 'flat'];
    for (var i = 0; i < n; i++) accidental(g, x + i * SP * 0.95, yBottom - list[i] * HALF, ks.count > 0 ? 1 : -1);
    return n * SP * 0.95 + SP * 0.4;
  }
  function accidental(g, x, y, a) {
    var s = a > 0 ? '♯' : (a < 0 ? '♭' : '♮');
    txt(g, x, y + SP * (a < 0 ? 0.35 : 0.55), s, { 'font-family': 'DejaVu Sans, Segoe UI Symbol, Apple Symbols, serif', 'font-size': SP * 2.1, fill: INK, 'text-anchor': 'middle' });
  }

  /* ── Notes and rests ────────────────────────────────────────────────────── */
  function head(g, x, y, base, kind, color, cls, ref) {
    var a = { class: cls, 'data-ref': ref };
    if (kind === 'x') {
      var gx = el('g', a, g), r = SP * 0.5;
      el('line', { x1: x - r, y1: y - r, x2: x + r, y2: y + r, stroke: color, 'stroke-width': SP * 0.2, 'stroke-linecap': 'round' }, gx);
      el('line', { x1: x - r, y1: y + r, x2: x + r, y2: y - r, stroke: color, 'stroke-width': SP * 0.2, 'stroke-linecap': 'round' }, gx);
      return gx;
    }
    if (kind === 'slash') return el('path', Object.assign(a, { d: 'M' + (x - SP * 0.7) + ' ' + (y + SP * 0.55) + ' l ' + SP * 0.45 + ' 0 l ' + SP * 0.95 + ' ' + -SP * 1.1 + ' l ' + -SP * 0.45 + ' 0 z', fill: color }), g);
    var hollow = base === 'h' || base === 'w';
    var e = el('ellipse', Object.assign(a, { cx: x, cy: y, rx: SP * (base === 'w' ? 0.78 : 0.64), ry: SP * 0.46, transform: base === 'w' ? null : 'rotate(-20 ' + x + ' ' + y + ')', fill: hollow ? 'none' : color, stroke: color, 'stroke-width': hollow ? SP * 0.2 : 0 }), g);
    return e;
  }
  function restGlyph(g, x, yMid, base, dots) {
    var p;
    if (base === 'w') el('rect', { x: x - SP * 0.6, y: yMid - SP, width: SP * 1.2, height: SP * 0.5, fill: INK }, g);
    else if (base === 'h') el('rect', { x: x - SP * 0.6, y: yMid - SP * 0.5, width: SP * 1.2, height: SP * 0.5, fill: INK }, g);
    else if (base === 'q') {
      p = 'M' + (x - SP * 0.2) + ' ' + (yMid - SP * 1.5) + ' l ' + SP * 0.7 + ' ' + SP * 0.9 + ' l ' + -SP * 0.55 + ' ' + SP * 0.7 + ' l ' + SP * 0.6 + ' ' + SP * 0.8 +
        ' c ' + -SP * 0.6 + ' ' + -SP * 0.3 + ' ' + -SP * 1.0 + ' ' + SP * 0.2 + ' ' + -SP * 0.4 + ' ' + SP * 0.9 + ' c ' + -SP * 1.0 + ' ' + -SP * 0.5 + ' ' + -SP * 0.8 + ' ' + -SP * 1.4 + ' ' + SP * 0.1 + ' ' + -SP * 1.3 +
        ' l ' + -SP * 0.6 + ' ' + -SP * 0.7 + ' l ' + SP * 0.5 + ' ' + -SP * 0.65 + ' z';
      el('path', { d: p, fill: INK }, g);
    } else {
      el('circle', { cx: x - SP * 0.25, cy: yMid - SP * 0.45, r: SP * 0.28, fill: INK }, g);
      el('path', { d: 'M' + (x - SP * 0.35) + ' ' + (yMid - SP * 0.25) + ' q ' + SP * 0.7 + ' ' + SP * 0.15 + ' ' + SP * 0.95 + ' ' + -SP * 0.55 + ' l ' + -SP * 0.75 + ' ' + SP * 2.0, fill: 'none', stroke: INK, 'stroke-width': SP * 0.17, 'stroke-linecap': 'round' }, g);
    }
    if (dots) el('circle', { cx: x + SP * 1.0, cy: yMid - SP * 0.4, r: SP * 0.18, fill: INK }, g);
  }
  function flag(g, x, yTip, up, color) {
    var d = up ? 'M' + x + ' ' + yTip + ' c ' + SP * 0.1 + ' ' + SP * 1.0 + ' ' + SP * 1.3 + ' ' + SP * 1.3 + ' ' + SP * 1.0 + ' ' + SP * 2.6
               : 'M' + x + ' ' + yTip + ' c ' + SP * 0.1 + ' ' + -SP * 1.0 + ' ' + SP * 1.3 + ' ' + -SP * 1.3 + ' ' + SP * 1.0 + ' ' + -SP * 2.6;
    el('path', { d: d, fill: 'none', stroke: color, 'stroke-width': SP * 0.22, 'stroke-linecap': 'round' }, g);
  }

  /* ── Staff placement ────────────────────────────────────────────────────── */
  function stepOf(tok, kind, key) {
    if (kind === 'six') return tok.pos;
    if (kind === 'perc') return tok.step ? tok.step * 2 : 0;
    var sp = E.spell(tok.midi, key);
    return sp.step - (kind === 'treble' ? 30 : 18);
  }

  /* ── One score ──────────────────────────────────────────────────────────── */
  // staves: [{kind, measures (from E.notate), key?, label?, color?(tok)→hex, lyrics?:bool, ts}]
  function engrave(host, spec) {
    host.textContent = '';
    var W = Math.max(300, host.clientWidth || 760), barTicks = spec.barTicks, ana = spec.anacrusis || 0;
    var unit = spec.compound ? (W < 520 ? 13 : 17) : (W < 520 ? 16 : 22), barPad = SP * 2.2;
    var nMeasures = spec.staves[0].measures.length;
    function mWidth(i) { var m = spec.staves[0].measures[i], ticks = (i === 0 && ana) ? ana : barTicks; return barPad + ticks * unit + SP; }
    var clefW = SP * 4, tsW = SP * 3.2, sysW = W - 6;
    function lead(si) { return 2 + clefW + (spec.keyWidth || 0) + (si === 0 ? tsW : SP * 0.6); }
    // Systems: as many bars as fit, then justified to the line (the last line only
    // a little, so a short last line does not stretch thin).
    var systems = [], cur = [], used = lead(0);
    for (var i = 0; i < nMeasures; i++) {
      var w = mWidth(i);
      if (cur.length && used + w > sysW) { systems.push(cur); cur = []; used = lead(systems.length); }
      cur.push(i); used += w;
    }
    if (cur.length) systems.push(cur);
    var stretch = systems.map(function (sys, si) {
      var natural = sys.reduce(function (a, mi) { return a + mWidth(mi); }, 0), room = sysW - lead(si);
      var k = room / natural;
      return si === systems.length - 1 && systems.length > 1 ? Math.min(k, 1.25) : k;
    });
    var staffGap = spec.staffGap || SP * 9, topPad = spec.topPad || SP * 4.5;
    var heights = spec.staves.map(function (s) { return s.kind === 'perc' ? SP * 2.2 : (s.kind === 'six' ? 10 * HALF : 8 * HALF); });
    var extraBelow = spec.staves.map(function (s) { return s.lyrics ? SP * 3.2 : (s.below || 0); });
    var sysH = topPad + heights.reduce(function (a, h, k) { return a + h + (k < heights.length - 1 ? staffGap + extraBelow[k] : extraBelow[k]); }, 0) + SP * 3;
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + Math.ceil(sysH * systems.length), width: '100%', role: 'img', 'aria-label': spec.label, class: 'xs-svg' }, host);
    var heads = [], ties = [];
    systems.forEach(function (sys, si) {
      var g = el('g', { transform: 'translate(0,' + (si * sysH) + ')' }, svg);
      var y = topPad, staffY = [];
      spec.staves.forEach(function (s, k) { staffY.push(y + heights[k]); y += heights[k] + staffGap + extraBelow[k]; });
      var left = 2, xStart = lead(si), K = stretch[si], right = Math.min(W - 3, xStart + sys.reduce(function (a, mi) { return a + mWidth(mi) * K; }, 0));
      // Staff lines, clefs, key and time signatures.
      spec.staves.forEach(function (s, k) {
        var yb = staffY[k];
        var nl = s.kind === 'perc' ? 1 : (s.kind === 'six' ? 6 : 5);
        for (var l = 0; l < nl; l++) el('line', { x1: left, y1: (s.kind === 'perc' ? yb - SP * 1.1 : yb - l * SP), x2: right, y2: (s.kind === 'perc' ? yb - SP * 1.1 : yb - l * SP), stroke: RULE, 'stroke-width': 0.9 }, g);
        if (s.kind === 'treble') trebleClef(g, left + SP * 0.8, yb);
        else if (s.kind === 'bass') bassClef(g, left + SP * 0.8, yb);
        else if (s.kind === 'six') mamClef(g, left + SP * 0.5, yb);
        else percClef(g, left + SP * 1.0, yb - SP * 1.1);
        if (s.kind === 'treble' || s.kind === 'bass') keySig(g, left + clefW + SP * 0.4, yb, s.key ? E.keySignature(s.key) : { count: 0 }, s.kind);
        if (si === 0) timeSig(g, xStart - tsW * 0.5, s.kind === 'perc' ? yb - SP * 1.1 - SP * 0.9 : yb, spec.ts, s.kind);
        if (s.title) txt(g, left + SP * 2.6, yb - SP * 2.4, s.title, { 'font-size': 12, fill: MUTED, 'font-family': 'Inter, sans-serif', 'font-weight': 600 });
        if (s.kind === 'six') {
          txt(g, left + SP * 4.1, yb - 11 * HALF - SP * 0.9, 'doh = ' + spec.doh, { 'font-size': 12, fill: GOLD, 'font-family': 'Inter, sans-serif', 'font-weight': 600 });
        }
      });
      // System bracket / brace.
      var yTop = staffY[0] - heights[0], yBot = staffY[staffY.length - 1] + (spec.staves[spec.staves.length - 1].kind === 'perc' ? -SP * 1.1 : 0);
      if (spec.staves.length > 1) {
        el('line', { x1: left, y1: yTop, x2: left, y2: yBot, stroke: INK, 'stroke-width': 2 }, g);
        if (spec.brace) el('path', { d: 'M' + (left - 1) + ' ' + yTop + ' q -7 ' + (yBot - yTop) * 0.25 + ' -3 ' + (yBot - yTop) * 0.5 + ' q -4 ' + (yBot - yTop) * 0.25 + ' 3 ' + (yBot - yTop) * 0.5, fill: 'none', stroke: INK, 'stroke-width': 1.6 }, g);
      }
      // Measures.
      var x = xStart;
      sys.forEach(function (mi, j) {
        var mw = mWidth(mi) * K, mStart = spec.staves[0].measures[mi].start;
        spec.staves.forEach(function (s, k) {
          var m = s.measures[mi]; if (!m) return;
          drawMeasure(g, s, m, staffY[k], x + barPad * 0.6, unit * K, mStart, heads, ties, si, spec);
        });
        x += mw;
        var lastBar = mi === nMeasures - 1;
        el('line', { x1: x, y1: yTop, x2: x, y2: yBot, stroke: INK, 'stroke-width': lastBar ? 1 : 1 }, g);
        if (lastBar) el('rect', { x: x + 2.5, y: yTop, width: 3.5, height: yBot - yTop, fill: INK }, g);
      });
      // Overlays per measure (chord symbols, Roman numerals).
      if (spec.overlay) spec.overlay(g, sys, function (tick) {
        var xx = xStart, mi;
        for (var q = 0; q < sys.length; q++) { mi = sys[q]; var ms = spec.staves[0].measures[mi].start, me = ms + ((mi === 0 && ana) ? ana : barTicks); if (tick >= ms && tick < me) return xx + barPad * 0.6 + (tick - ms) * unit * stretch[systems.indexOf(sys)]; xx += mWidth(mi) * stretch[systems.indexOf(sys)]; }
        return null;
      }, staffY, heights);
    });
    // Ties: from each head with tieNext to the next head of the same staff.
    ties.forEach(function (t) {
      var a = t.from, b = t.to; if (!b) return;
      var up = !a.up, sameSys = a.sys === b.sys;
      var x1 = a.x + SP * 0.6, x2 = sameSys ? b.x - SP * 0.6 : a.x + SP * 3, yy = a.y + (up ? -SP * 0.7 : SP * 0.7) + a.sys * sysH, dy = up ? -SP * 0.9 : SP * 0.9;
      el('path', { d: 'M' + x1 + ' ' + yy + ' Q ' + (x1 + x2) / 2 + ' ' + (yy + dy) + ' ' + x2 + ' ' + yy, fill: 'none', stroke: a.color, 'stroke-width': 1.3 }, svg);
    });
    return { svg: svg, heads: heads };
  }

  function drawMeasure(g, s, m, yb, x0, unit, mStart, heads, ties, si, spec) {
    var key = s.key, mid = MID[s.kind], altered = {};
    function yAt(step) { return s.kind === 'perc' ? yb - SP * 1.1 - step * HALF : yb - step * HALF; }
    var placed = [];
    m.tokens.forEach(function (t) {
      var x = x0 + (t.start - mStart) * unit + SP * 0.4;
      if (t.rest) {
        var ym = s.kind === 'perc' ? yb - SP * 1.1 + SP * 0.6 : yAt(mid) + SP * 0.4;
        if (t.base === 'w' && s.kind !== 'perc') ym = yAt(mid + 2) + SP * 0.5;
        restGlyph(g, x, ym, t.base, t.dots);
        return;
      }
      var mids = t.midis || [t.midi];
      var steps = mids.map(function (mm) { var tt = Object.assign({}, t, { midi: mm }); return stepOf(tt, s.kind, key); });
      var color = s.color ? s.color(t) : INK, cls = 'xn';
      var up = s.kind === 'perc' ? true : (steps.reduce(function (a, b) { return a + b; }, 0) / steps.length) < mid;
      var info = { x: x, steps: steps, up: up, color: color, t: t };
      steps.forEach(function (st, k) {
        var y = yAt(st);
        // Ledger lines.
        var lo = s.kind === 'six' ? 0 : 0, hi = s.kind === 'six' ? 10 : 8;
        if (s.kind !== 'perc') {
          for (var l = (st < lo ? lo - 2 : null); l != null && l >= st; l -= 2) el('line', { x1: x - SP, y1: yAt(l), x2: x + SP, y2: yAt(l), stroke: RULE, 'stroke-width': 1 }, g);
          for (l = (st > hi ? hi + 2 : null); l != null && l <= st; l += 2) el('line', { x1: x - SP, y1: yAt(l), x2: x + SP, y2: yAt(l), stroke: RULE, 'stroke-width': 1 }, g);
        }
        // Accidentals (five-line staves) and the raised degree on the 6-line staff.
        if (s.kind === 'treble' || s.kind === 'bass') {
          var sp = E.spell(mids[k], key), want = altered[sp.step] != null ? altered[sp.step] : sp.keyAcc;
          if (sp.acc !== want && !t.tiePrev) { accidental(g, x - SP * 1.5, y, sp.acc); altered[sp.step] = sp.acc; }
        }
        if (s.kind === 'six' && t.sharp && !t.tiePrev) accidental(g, x - SP * 1.5, y, 1);
        var h = head(g, x, y, t.base, s.kind === 'perc' ? (t.head === 'x' ? 'x' : (t.head === 'slash' ? 'slash' : 'normal')) : 'normal', color, cls, t.ref);
        if (t.ref != null) heads.push({ el: h, ref: t.ref });
        if (t.dots) el('circle', { cx: x + SP * 1.15, cy: y - (st % 2 === 0 && s.kind !== 'perc' ? HALF : 0), r: SP * 0.2, fill: color }, g);
      });
      if (t.base !== 'w') {
        var ext = up ? Math.min.apply(null, steps) : Math.max.apply(null, steps);
        var far = up ? Math.max.apply(null, steps) : Math.min.apply(null, steps);
        var sx = up ? x + SP * 0.6 : x - SP * 0.6, y1 = yAt(ext), y2 = yAt(far) + (up ? -STEM : STEM);
        info.stemX = sx; info.stemY1 = y1; info.stemTip = y2;
        info.stemEl = el('line', { x1: sx, y1: y1, x2: sx, y2: y2, stroke: color, 'stroke-width': 1.2 }, g);
      }
      placed.push(info);
      if (s.lyrics && t.solfa) txt(g, x, yb + SP * 2.4, t.solfa, { 'text-anchor': 'middle', 'font-size': 13, 'font-family': 'Inter, sans-serif', 'font-weight': 600, fill: t.part === 'call' ? CALL : MUTED });
      // Glide mark between consecutive dùndún strokes.
      if (s.kind === 'perc' && s.glide && placed.length > 1) {
        var pv = placed[placed.length - 2];
        if (t.start - pv.t.start === 1) el('line', { x1: pv.x + SP * 0.7, y1: yAt(pv.steps[0]), x2: x - SP * 0.7, y2: yAt(steps[0]), stroke: MUTED, 'stroke-width': 1, 'stroke-dasharray': '2 2' }, g);
      }
      if (t.tieNext || t.tiePrev) {
        var rec = { x: x, y: yAt(steps[0]), up: up, color: color, sys: si, staff: s };
        if (t.tiePrev && s._pendingTie) { s._pendingTie.to = rec; }
        if (t.tieNext) { var tie = { from: rec, to: null }; ties.push(tie); s._pendingTie = tie; } else s._pendingTie = null;
      }
    });
    // Beams: group by beam id; others get flags.
    var groups = {};
    placed.forEach(function (p) { if (p.t.beam) (groups[p.t.beam] = groups[p.t.beam] || []).push(p); else if (p.t.base === '8' && p.stemX != null) flag(g, p.stemX, p.stemTip, p.up, p.color); });
    Object.keys(groups).forEach(function (k) {
      var gr = groups[k], upCount = gr.filter(function (p) { return p.up; }).length, up = upCount * 2 >= gr.length;
      gr.forEach(function (p) {
        if (p.up !== up) {
          var ext = up ? Math.min.apply(null, p.steps) : Math.max.apply(null, p.steps);
          p.up = up; p.stemX = up ? p.x + SP * 0.6 : p.x - SP * 0.6; p.stemY1 = yAt(ext);
        }
      });
      var a = gr[0], b = gr[gr.length - 1];
      var tipA = up ? Math.min.apply(null, a.steps.map(yAt)) - STEM : Math.max.apply(null, a.steps.map(yAt)) + STEM;
      var tipB = up ? Math.min.apply(null, b.steps.map(yAt)) - STEM : Math.max.apply(null, b.steps.map(yAt)) + STEM;
      var slope = Math.max(-0.18, Math.min(0.18, (tipB - tipA) / Math.max(1, b.stemX - a.stemX)));
      // Keep every stem at least 2.6 spaces long under the beam.
      var off = 0;
      gr.forEach(function (p) {
        var line = tipA + slope * (p.stemX - a.stemX), headY = up ? Math.min.apply(null, p.steps.map(yAt)) : Math.max.apply(null, p.steps.map(yAt));
        var need = up ? (headY - SP * 2.6) - line : line - (headY + SP * 2.6);
        if (need < 0) off = up ? Math.min(off, need) : Math.max(off, -need);
      });
      gr.forEach(function (p) {
        var yy = tipA + off + slope * (p.stemX - a.stemX);
        if (p.stemEl) { p.stemEl.setAttribute('x1', p.stemX); p.stemEl.setAttribute('x2', p.stemX); p.stemEl.setAttribute('y1', p.stemY1); p.stemEl.setAttribute('y2', yy); }
      });
      var ya = tipA + off, yb2 = tipA + off + slope * (b.stemX - a.stemX), th = SP * 0.5 * (up ? 1 : -1);
      el('path', { d: 'M' + a.stemX + ' ' + ya + ' L ' + b.stemX + ' ' + yb2 + ' L ' + b.stemX + ' ' + (yb2 + th) + ' L ' + a.stemX + ' ' + (ya + th) + ' Z', fill: a.color }, g);
    });
  }

  /* ── The three scores ───────────────────────────────────────────────────── */
  function scoreClassical(host, arr) {
    var sc = arr.score, o = { barTicks: sc.barTicks, anacrusis: sc.anacrusis, endTick: sc.endTick };
    var rh = E.notate(sc.rh, o), lh = E.notate(sc.lh, o), ks = E.keySignature(arr.key);
    return engrave(host, {
      barTicks: sc.barTicks, anacrusis: sc.anacrusis, ts: sc.timeSig, brace: true, keyWidth: Math.abs(ks.count) * SP * 0.95 + SP * 0.6,
      label: arr.caption, staffGap: SP * 6,
      staves: [{ kind: 'treble', measures: rh, key: arr.key }, { kind: 'bass', measures: lh, key: arr.key, below: SP * 3 }],
      overlay: function (g, sys, xAt, staffY) {
        sc.roman.forEach(function (r) {
          var x = xAt(r.tick); if (x == null) return;
          var t = txt(g, x, staffY[1] + SP * 3.6, r.text, { 'font-family': 'Cormorant Garamond, Georgia, serif', 'font-size': 17, 'font-weight': 600, fill: INK });
          if (r.figure) { var f = el('tspan', { dy: -6, 'font-size': 11 }, t); f.textContent = r.figure.replace('6/4', '⁶₄').replace('6/5', '⁶₅'); }
        });
      }
    });
  }
  function scoreContemporary(host, arr) {
    var sc = arr.score, o = { barTicks: sc.barTicks, anacrusis: sc.anacrusis, endTick: sc.endTick };
    var mel = E.notate(sc.melody, o), ks = E.keySignature(arr.key);
    return engrave(host, {
      barTicks: sc.barTicks, anacrusis: sc.anacrusis, ts: sc.timeSig, keyWidth: Math.abs(ks.count) * SP * 0.95 + SP * 0.6,
      label: arr.caption, topPad: SP * 6,
      staves: [{ kind: 'treble', measures: mel, key: arr.key }],
      overlay: function (g, sys, xAt, staffY, heights) {
        var lastEnd = -1e9, row = 0;
        sc.chords.forEach(function (c) {
          var x = xAt(c.tick); if (x == null) return;
          var w = c.text.length * 8.4;
          row = (x < lastEnd + 6 && row === 0) ? 1 : 0;   // a symbol that would collide moves up a row
          txt(g, x, staffY[0] - heights[0] - SP * 2.6 - row * 17, c.text, { 'font-family': 'Inter, sans-serif', 'font-size': 15, 'font-weight': 600, fill: INK });
          if (row === 0) lastEnd = x + w;
        });
      }
    });
  }
  function scoreAfrican(host, arr) {
    var sc = arr.score, o = { barTicks: 12, compound: true, endTick: sc.endTick };
    var voice = E.notate(sc.voice, o), bell = E.notate(sc.bell, o), shek = E.notate(sc.shekere, o), drum = E.notate(sc.drum, o);
    return engrave(host, {
      barTicks: 12, compound: true, ts: [12, 8], label: arr.caption, doh: arr.dohName, staffGap: SP * 3.2, topPad: SP * 5,
      staves: [
        { kind: 'six', measures: voice, lyrics: true, color: function (t) { return t.part === 'call' ? CALL : RESP; } },
        { kind: 'perc', measures: bell, title: 'Bell' },
        { kind: 'perc', measures: shek, title: 'Shekere' },
        { kind: 'perc', measures: drum, title: 'Dùndún', glide: true }
      ]
    });
  }

  /* ── Interface ──────────────────────────────────────────────────────────── */
  var STYLES = [
    { id: 'african', name: 'African', sub: '12/8 bell cycle · call and response · MAM 6-line' },
    { id: 'contemporary', name: 'Contemporary', sub: 'Reharmonised · groove · lead sheet' },
    { id: 'classical', name: 'Classical', sub: 'Four-part harmony · piano · grand staff' }
  ];
  function mount(root) {
    var compact = root.hasAttribute('data-compact');
    var state = { song: E.SONGS[0], style: root.getAttribute('data-style') || 'african', arr: null, heads: [], captured: null };
    root.innerHTML =
      '<div class="xs-top">' +
        '<div class="xs-songs" role="group" aria-label="Choose a song">' +
          E.SONGS.map(function (s, i) { return '<button type="button" class="xs-chip" data-song="' + i + '" aria-pressed="' + (i === 0) + '">' + s.title + '</button>'; }).join('') +
          '<button type="button" class="xs-chip xs-mine" data-song="mine" aria-pressed="false" hidden>Your melody</button>' +
          '<button type="button" class="xs-chip xs-rec" data-rec aria-pressed="false"><span class="xs-dot" aria-hidden="true"></span> <span data-rec-label>Sing or play it in</span></button>' +
        '</div>' +
        '<p class="xs-rec-msg" data-rec-msg role="status" hidden></p>' +
      '</div>' +
      '<div class="xs-styles" role="group" aria-label="Choose an expression">' +
        STYLES.map(function (s) { return '<button type="button" class="xs-style" data-style="' + s.id + '" aria-pressed="' + (s.id === state.style) + '"><span class="xs-style-name">' + s.name + '</span>' + (compact ? '' : '<span class="xs-style-sub">' + s.sub + '</span>') + '</button>'; }).join('') +
      '</div>' +
      '<div class="xs-stage">' +
        '<div class="xs-bar"><button type="button" class="xs-play" data-play aria-pressed="false"><span class="xs-play-ico" aria-hidden="true"></span><span data-play-label>Play</span></button>' +
        '<p class="xs-caption" data-caption aria-live="polite"></p></div>' +
        '<div class="xs-score" data-score></div>' +
        (compact ? '' : '<p class="xs-key" data-key></p>') +
      '</div>';
    var scoreBox = root.querySelector('[data-score]'), cap = root.querySelector('[data-caption]'), playBtn = root.querySelector('[data-play]');
    var keyBox = root.querySelector('[data-key]');
    function render() {
      stopPlay();
      var song = state.song, style = state.style;
      try { state.arr = E.arrange(song, style); } catch (e) { cap.textContent = 'That melody could not be arranged. Try another.'; return; }
      cap.textContent = state.arr.caption;
      var r = style === 'classical' ? scoreClassical(scoreBox, state.arr) : (style === 'contemporary' ? scoreContemporary(scoreBox, state.arr) : scoreAfrican(scoreBox, state.arr));
      state.heads = r.heads;
      if (keyBox) keyBox.innerHTML = style === 'african'
        ? 'Read it on the <strong>MAM 6-line staff</strong>: six lines and five spaces, doh always on the bottom line (d m s t r\' f\' on the lines, r f l d\' m\' in the spaces). <span class="xs-call">Orange</span> is the lead\'s call, dark is the chorus\'s response; the bell, shekere and dùndún are written on the staves below.'
        : (style === 'classical' ? 'Grand staff: the melody in the treble, the left hand in the bass, with the harmony named in Roman numerals beneath.' : 'Lead sheet: the melody on a five-line staff, the chords named above it.');
    }
    function setPressed(sel, attr, val) { root.querySelectorAll(sel).forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute(attr) === String(val))); }); }
    function light(ref) { state.heads.forEach(function (h) { var on = ref != null && h.ref === ref; if (h.el.classList) h.el.classList.toggle('on', on); else h.el.setAttribute('class', on ? 'xn on' : 'xn'); }); }
    function stopPlay() { if (A && A.isPlaying()) A.stop(); playBtn.setAttribute('aria-pressed', 'false'); root.querySelector('[data-play-label]').textContent = 'Play'; light(null); }
    playBtn.addEventListener('click', function () {
      if (!A) return;
      if (A.isPlaying()) { stopPlay(); return; }
      var ok = A.play(state.arr, { onNote: light, onEnd: function () { playBtn.setAttribute('aria-pressed', 'false'); root.querySelector('[data-play-label]').textContent = 'Play'; light(null); } });
      if (ok) { playBtn.setAttribute('aria-pressed', 'true'); root.querySelector('[data-play-label]').textContent = 'Stop'; }
      else cap.textContent = 'Your browser cannot play audio here.';
    });
    root.querySelectorAll('[data-style]').forEach(function (b) { if (b.tagName === 'BUTTON') b.addEventListener('click', function () { state.style = b.getAttribute('data-style'); setPressed('.xs-style', 'data-style', state.style); render(); }); });
    root.querySelectorAll('[data-song]').forEach(function (b) {
      b.addEventListener('click', function () {
        var v = b.getAttribute('data-song');
        state.song = v === 'mine' ? state.captured : E.SONGS[Number(v)];
        root.querySelectorAll('[data-song]').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
        render();
      });
    });
    setupRecorder(root, state, render);
    var rt = null; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(function () { if (!A || !A.isPlaying()) render(); }, 200); });
    render();
  }

  /* ── Sing or play it in (reuses notation.js's pitch detector) ───────────── */
  function setupRecorder(root, state, render) {
    var btn = root.querySelector('[data-rec]'), label = root.querySelector('[data-rec-label]'), msg = root.querySelector('[data-rec-msg]'), mine = root.querySelector('.xs-mine');
    var N = window.MAM_NOTATION, rec = null;
    function say(s) { msg.hidden = !s; msg.textContent = s || ''; }
    btn.addEventListener('click', function () {
      if (rec) { finish(); return; }
      if (!N || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { say('Recording is not available in this browser. Pick a song instead.'); return; }
      say('Allow the microphone, then sing or play a short tune (up to 12 seconds). Press again to finish.');
      navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false } }).then(function (stream) {
        var AC = window.AudioContext || window.webkitAudioContext, ac = new AC(), src = ac.createMediaStreamSource(stream), an = ac.createAnalyser();
        an.fftSize = 2048; src.connect(an);
        var buf = new Float32Array(an.fftSize), frames = [], t0 = performance.now();
        rec = { stream: stream, ac: ac, frames: frames, timer: setInterval(function () {
          an.getFloatTimeDomainData(buf);
          var rms = 0; for (var i = 0; i < buf.length; i++) rms += buf[i] * buf[i]; rms = Math.sqrt(rms / buf.length);
          var p = N.detectPitchAC(buf, ac.sampleRate);
          frames.push({ t: performance.now() - t0, midi: p && p.clarity > 0.85 ? N.freqToMidi(p.hz) : null, rms: rms });
          if (performance.now() - t0 > 12000) finish();
        }, 20) };
        btn.setAttribute('aria-pressed', 'true'); label.textContent = 'Finish';
        say('Listening… sing or play now. Press Finish when you are done.');
      }).catch(function () { say('The microphone was not allowed, so nothing was recorded. You can still pick a song.'); });
    });
    function finish() {
      if (!rec) return;
      clearInterval(rec.timer); rec.stream.getTracks().forEach(function (t) { t.stop(); }); try { rec.ac.close(); } catch (e) {}
      var frames = rec.frames; rec = null;
      btn.setAttribute('aria-pressed', 'false'); label.textContent = 'Sing or play it in';
      var tempo = N.estimateTempo ? N.estimateTempo(frames, { fallback: 96 }) : 96;
      var notes = N.framesToNotes(frames, tempo).filter(function (n, i, a) { return n.midi != null || (i > 0 && i < a.length - 1); });
      if (notes.filter(function (n) { return n.midi != null; }).length < 4) { say('We did not catch a clear melody. Try again a little louder, one note at a time.'); return; }
      state.captured = { id: 'mine', title: 'Your melody', tempo: Math.round(tempo), timeSig: [4, 4], notes: notes };
      mine.hidden = false;
      root.querySelectorAll('[data-song]').forEach(function (x) { x.setAttribute('aria-pressed', String(x === mine)); });
      state.song = state.captured;
      say('Here is your melody, arranged. Switch expressions to hear it each way.');
      render();
    }
  }

  function boot() { document.querySelectorAll('[data-expressions]').forEach(mount); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
