/* ─────────────────────────────────────────────────────────────────────────
   MAM Academy — Notation Showcase (portal brief #14), milestone 1.

   An interactive engine that renders a melody in THREE notation systems and
   plays it back with the Web Audio API, highlighting each note as it sounds:

     • Classical   — a 5-line treble staff (noteheads, stems, ledger lines, ♯)
     • Contemporary — a graphic piano-roll (pitch = height, length = duration)
     • African      — a stylised 6-line tone grid with solfège tone markers

   Everything is self-drawn SVG + a small synth — no external libraries (the
   site's CSP blocks CDNs). The audio→notation transcription (mic/DSP) is a
   later milestone; this milestone drives the renderers from a note model and
   ships a few example melodies.

   Note model:  { title, key, tempo, timeSig:[num,den], notes:[{midi, beats}] }
   midi = MIDI number (60 = C4), or null for a rest;  beats: 4=whole … 0.5=eighth
   ───────────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  var NOTE_NAMES = ['C', 'C♯', 'D', 'D♯', 'E', 'F', 'F♯', 'G', 'G♯', 'A', 'A♯', 'B'];
  var SOLFEGE = ['Do', 'Di', 'Re', 'Ri', 'Mi', 'Fa', 'Fi', 'Sol', 'Si', 'La', 'Li', 'Ti'];
  // diatonic step (0..6) within an octave for each pitch class (sharps share
  // the step of their natural below).
  var PC_STEP = [0, 0, 1, 1, 2, 3, 3, 4, 4, 5, 5, 6];
  var IS_SHARP = [0, 1, 0, 1, 0, 0, 1, 0, 1, 0, 1, 0];
  var SVGNS = 'http://www.w3.org/2000/svg';

  function el(name, attrs, kids) {
    var e = document.createElementNS(SVGNS, name);
    if (attrs) for (var k in attrs) if (attrs.hasOwnProperty(k)) e.setAttribute(k, String(attrs[k]));
    if (kids) kids.forEach(function (c) { e.appendChild(c); });
    return e;
  }
  function midiToFreq(m) { return 440 * Math.pow(2, (m - 69) / 12); }
  function diatonicStep(m) { return Math.floor(m / 12) * 7 + PC_STEP[m % 12]; }
  function noteName(m) { return NOTE_NAMES[m % 12] + (Math.floor(m / 12) - 1); }
  function durClass(b) { return b >= 4 ? 'whole' : b >= 2 ? 'half' : b >= 1 ? 'quarter' : 'eighth'; }

  // ── Example melodies ──────────────────────────────────────────────────────
  var EXAMPLES = [
    {
      title: 'C major scale', key: 'C major', tempo: 96, timeSig: [4, 4],
      notes: [60, 62, 64, 65, 67, 69, 71, 72].map(function (m) { return { midi: m, beats: 1 }; })
    },
    {
      title: 'Twinkle, Twinkle', key: 'C major', tempo: 108, timeSig: [4, 4],
      notes: [[60, 1], [60, 1], [67, 1], [67, 1], [69, 1], [69, 1], [67, 2], [65, 1], [65, 1], [64, 1], [64, 1], [62, 1], [62, 1], [60, 2]]
        .map(function (n) { return { midi: n[0], beats: n[1] }; })
    },
    {
      title: 'Pentatonic call', key: 'A minor pentatonic', tempo: 120, timeSig: [4, 4],
      notes: [[69, 0.5], [72, 0.5], [74, 1], [72, 0.5], [69, 0.5], [67, 1], [69, 0.5], [72, 0.5], [76, 1], [74, 2]]
        .map(function (n) { return { midi: n[0], beats: n[1] }; })
    },
    {
      title: 'Bell ostinato (12/8 feel)', key: 'F major', tempo: 132, timeSig: [4, 4],
      notes: [[65, 0.5], [69, 0.5], [72, 0.5], [69, 0.5], [65, 0.5], [72, 0.5], [69, 0.5], [65, 0.5], [67, 1], [null, 1]]
        .map(function (n) { return { midi: n[0], beats: n[1] }; })
    }
  ];

  // ── Renderers ─────────────────────────────────────────────────────────────
  // Each returns an <svg> whose sounding notes carry data-note-index="i" so the
  // player can highlight them; rests are drawn but not indexed.
  var COL_W = 56, LEFT = 78, TOP = 40;

  function renderClassical(mel) {
    var lineGap = 12, staffTop = TOP + 24, midi;
    var W = LEFT + mel.notes.length * COL_W + 40, H = 220;
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'nt-svg', role: 'img', 'aria-label': 'Classical staff notation of ' + mel.title });
    // 5 staff lines (E G B D F bottom→top for treble).
    for (var i = 0; i < 5; i++) {
      var y = staffTop + i * lineGap;
      svg.appendChild(el('line', { x1: 20, y1: y, x2: W - 20, y2: y, class: 'nt-staffline' }));
    }
    // Treble clef (stylised G).
    var clef = el('text', { x: 30, y: staffTop + 4 * lineGap - 2, class: 'nt-clef' }); clef.textContent = '𝄞';
    svg.appendChild(clef);
    var refStep = diatonicStep(64); // bottom line = E4
    var bottomY = staffTop + 4 * lineGap;
    mel.notes.forEach(function (n, i) {
      var cx = LEFT + i * COL_W;
      if (n.midi == null) { var r = el('text', { x: cx - 4, y: staffTop + 2 * lineGap + 6, class: 'nt-rest' }); r.textContent = '𝄽'; svg.appendChild(r); return; }
      var step = diatonicStep(n.midi) - refStep;
      var cy = bottomY - step * (lineGap / 2);
      var g = el('g', { 'data-note-index': i, class: 'nt-note' });
      // Ledger lines above/below the staff.
      var topLineStep = 8; // top line F5 is 8 half-steps above bottom line
      for (var s = 10; s <= step; s += 2) g.appendChild(el('line', { x1: cx - 11, y1: bottomY - s * (lineGap / 2), x2: cx + 11, y2: bottomY - s * (lineGap / 2), class: 'nt-ledger' }));
      for (var s2 = -2; s2 >= step; s2 -= 2) g.appendChild(el('line', { x1: cx - 11, y1: bottomY - s2 * (lineGap / 2), x2: cx + 11, y2: bottomY - s2 * (lineGap / 2), class: 'nt-ledger' }));
      if (IS_SHARP[n.midi % 12]) { var acc = el('text', { x: cx - 22, y: cy + 5, class: 'nt-acc' }); acc.textContent = '♯'; g.appendChild(acc); }
      var open = n.beats >= 2;
      g.appendChild(el('ellipse', { cx: cx, cy: cy, rx: 7.4, ry: 5.4, transform: 'rotate(-18 ' + cx + ' ' + cy + ')', class: 'nt-head' + (open ? ' open' : '') }));
      if (n.beats < 4) { // stem (not for whole notes)
        var up = step < 4; var sx = up ? cx + 7 : cx - 7; var sy2 = up ? cy - 34 : cy + 34;
        g.appendChild(el('line', { x1: sx, y1: cy, x2: sx, y2: sy2, class: 'nt-stem' }));
        if (n.beats <= 0.5) { var fx = sx; g.appendChild(el('path', { d: 'M' + fx + ' ' + sy2 + ' q 10 6 6 20', class: 'nt-flag' })); }
      }
      svg.appendChild(g);
    });
    return svg;
  }

  function renderContemporary(mel) {
    // Graphic piano-roll: y = pitch, bar length = duration, hue = pitch class.
    var pitched = mel.notes.filter(function (n) { return n.midi != null; });
    var lo = Math.min.apply(null, pitched.map(function (n) { return n.midi; })) - 1;
    var hi = Math.max.apply(null, pitched.map(function (n) { return n.midi; })) + 1;
    var rowH = 14, rows = hi - lo + 1;
    var beatPx = 46, x = LEFT, W = LEFT, H = TOP + rows * rowH + 30;
    mel.notes.forEach(function (n) { W += n.beats * beatPx; });
    W += 40;
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'nt-svg', role: 'img', 'aria-label': 'Contemporary piano-roll notation of ' + mel.title });
    // Row guides + note-name gutter for C's.
    for (var m = lo; m <= hi; m++) {
      var y = TOP + (hi - m) * rowH;
      svg.appendChild(el('line', { x1: LEFT - 6, y1: y + rowH, x2: W - 20, y2: y + rowH, class: 'nt-roll-row' + (m % 12 === 0 ? ' c' : '') }));
      if (m % 12 === 0) { var lbl = el('text', { x: 20, y: y + rowH - 3, class: 'nt-roll-label' }); lbl.textContent = noteName(m); svg.appendChild(lbl); }
    }
    mel.notes.forEach(function (n, i) {
      var w = n.beats * beatPx - 4;
      if (n.midi != null) {
        var y = TOP + (hi - n.midi) * rowH;
        var hue = (n.midi % 12) * 30;
        var g = el('g', { 'data-note-index': i, class: 'nt-note' });
        g.appendChild(el('rect', { x: x, y: y + 2, width: Math.max(10, w), height: rowH - 4, rx: 4, class: 'nt-bar', fill: 'hsl(' + hue + ' 68% 55%)' }));
        svg.appendChild(g);
      }
      x += n.beats * beatPx;
    });
    return svg;
  }

  function renderAfrican(mel) {
    // Stylised 6-line tone grid: six lines, tone markers placed by pitch with a
    // solfège tone label — a modern, readable take for the MAM 6-line system.
    var lineGap = 16, gridTop = TOP + 10;
    var W = LEFT + mel.notes.length * COL_W + 40, H = TOP + 6 * lineGap + 60;
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'nt-svg', role: 'img', 'aria-label': 'African six-line notation of ' + mel.title });
    for (var i = 0; i < 6; i++) {
      var y = gridTop + i * lineGap;
      svg.appendChild(el('line', { x1: 20, y1: y, x2: W - 20, y2: y, class: 'nt-sixline' }));
    }
    var lbl = el('text', { x: 26, y: gridTop - 6, class: 'nt-6label' }); lbl.textContent = 'MAM 6-line'; svg.appendChild(lbl);
    // Map each note onto one of 6 tone rows by its scale degree from the melody low.
    var pitched = mel.notes.filter(function (n) { return n.midi != null; });
    var lo = Math.min.apply(null, pitched.map(function (n) { return n.midi; }));
    var bottomY = gridTop + 5 * lineGap;
    mel.notes.forEach(function (n, i) {
      var cx = LEFT + i * COL_W;
      if (n.midi == null) { var d = el('text', { x: cx - 4, y: bottomY + 26, class: 'nt-rest' }); d.textContent = '—'; svg.appendChild(d); return; }
      var deg = diatonicStep(n.midi) - diatonicStep(lo); // 0.. diatonic degrees
      var row = Math.max(0, Math.min(10, deg));           // clamp into 6 lines + spaces
      var cy = bottomY - row * (lineGap / 2);
      var hue = (n.midi % 12) * 30;
      var g = el('g', { 'data-note-index': i, class: 'nt-note' });
      g.appendChild(el('circle', { cx: cx, cy: cy, r: n.beats >= 2 ? 9 : 7, class: 'nt-tone', fill: 'hsl(' + hue + ' 62% 52%)' }));
      var t = el('text', { x: cx, y: cy + 3.2, class: 'nt-tone-label', 'text-anchor': 'middle' }); t.textContent = SOLFEGE[n.midi % 12]; g.appendChild(t);
      svg.appendChild(g);
    });
    return svg;
  }

  var RENDERERS = { classical: renderClassical, contemporary: renderContemporary, african: renderAfrican };

  // ── Player (Web Audio) ──────────────────────────────────────────────────────
  function Player() {
    this.ctx = null; this.timer = null; this.onIndex = null; this.onEnd = null;
  }
  Player.prototype.ensure = function () {
    if (!this.ctx) { var AC = window.AudioContext || window.webkitAudioContext; if (AC) this.ctx = new AC(); }
    if (this.ctx && this.ctx.state === 'suspended') this.ctx.resume();
    return this.ctx;
  };
  Player.prototype.stop = function () {
    if (this.timer) { clearTimeout(this.timer); this.timer = null; }
    if (this.onIndex) this.onIndex(-1);
  };
  Player.prototype.play = function (mel, tempo, onIndex, onEnd) {
    this.stop();
    this.onIndex = onIndex; this.onEnd = onEnd;
    var ctx = this.ensure();
    var beatMs = 60000 / tempo, self = this, t0 = (ctx ? ctx.currentTime : 0);
    var acc = 0; // seconds offset
    mel.notes.forEach(function (n) {
      var durS = (n.beats * beatMs) / 1000;
      if (n.midi != null && ctx) {
        var osc = ctx.createOscillator(), g = ctx.createGain();
        osc.type = 'triangle'; osc.frequency.value = midiToFreq(n.midi);
        var st = t0 + acc, en = st + Math.max(0.12, durS * 0.92);
        g.gain.setValueAtTime(0.0001, st);
        g.gain.exponentialRampToValueAtTime(0.22, st + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, en);
        osc.connect(g).connect(ctx.destination); osc.start(st); osc.stop(en + 0.02);
      }
      acc += durS;
    });
    // Highlight loop (independent of audio so it works even if muted/blocked).
    var i = 0, start = Date.now(), offsets = [], run = 0;
    mel.notes.forEach(function (n) { offsets.push(run); run += n.beats * beatMs; });
    function tick() {
      var elapsed = Date.now() - start, idx = -1;
      for (var k = 0; k < offsets.length; k++) { if (elapsed >= offsets[k]) idx = k; }
      if (idx >= 0 && mel.notes[idx].midi != null && self.onIndex) self.onIndex(idx);
      else if (idx >= 0 && self.onIndex) self.onIndex(-1);
      if (elapsed >= run) { self.timer = null; if (self.onIndex) self.onIndex(-1); if (self.onEnd) self.onEnd(); return; }
      self.timer = setTimeout(tick, 30);
    }
    tick();
  };

  // ── UI wiring ────────────────────────────────────────────────────────────
  function boot() {
    var root = document.getElementById('ntApp');
    if (!root) return;
    var state = { melIndex: 0, system: 'classical', tempo: EXAMPLES[0].tempo, playing: false };
    var player = new Player();

    var examplesHtml = EXAMPLES.map(function (m, i) {
      return '<button type="button" class="nt-ex" data-ex="' + i + '"><strong>' + esc(m.title) + '</strong><span>' + esc(m.key) + '</span></button>';
    }).join('');
    root.innerHTML =
      '<div class="nt-controls">'
      + '<div class="nt-systems" role="tablist" aria-label="Notation system">'
      +   '<button type="button" role="tab" class="nt-sys active" data-sys="classical">Classical</button>'
      +   '<button type="button" role="tab" class="nt-sys" data-sys="contemporary">Contemporary</button>'
      +   '<button type="button" role="tab" class="nt-sys" data-sys="african">African 6-line</button>'
      + '</div>'
      + '<div class="nt-transport">'
      +   '<button type="button" class="nt-play btn btn-primary" aria-label="Play"><i class="fa-solid fa-play" aria-hidden="true"></i> <span class="nt-play-label">Play</span></button>'
      +   '<label class="nt-tempo">Tempo <span class="nt-tempo-val">' + state.tempo + '</span> BPM'
      +     '<input type="range" min="50" max="180" value="' + state.tempo + '" class="nt-tempo-range" aria-label="Tempo in beats per minute"></label>'
      + '</div>'
      + '</div>'
      + '<div class="nt-stage"><div class="nt-title" id="ntTitle"></div><div class="nt-render" id="ntRender" tabindex="0"></div></div>'
      + '<div class="nt-examples"><span class="nt-ex-label">Try a melody:</span>' + examplesHtml + '</div>'
      + '<p class="nt-note">One melody, three ways to read it — play it back and watch each system light up in time. '
      + 'Live audio→notation capture is coming next; for now, explore these examples.</p>';

    var renderBox = root.querySelector('#ntRender');
    var titleBox = root.querySelector('#ntTitle');
    var playBtn = root.querySelector('.nt-play');
    var playLabel = root.querySelector('.nt-play-label');
    var playIcon = playBtn.querySelector('i');

    function draw() {
      var mel = EXAMPLES[state.melIndex];
      titleBox.textContent = mel.title + ' · ' + mel.key;
      renderBox.innerHTML = '';
      renderBox.appendChild(RENDERERS[state.system](mel));
    }
    function setIndex(i) {
      var prev = renderBox.querySelector('.nt-note.playing');
      if (prev) prev.classList.remove('playing');
      if (i >= 0) { var cur = renderBox.querySelector('[data-note-index="' + i + '"]'); if (cur) cur.classList.add('playing'); }
    }
    function stopUI() { state.playing = false; playIcon.className = 'fa-solid fa-play'; playLabel.textContent = 'Play'; setIndex(-1); }
    function startUI() { state.playing = true; playIcon.className = 'fa-solid fa-stop'; playLabel.textContent = 'Stop'; }

    playBtn.addEventListener('click', function () {
      if (state.playing) { player.stop(); stopUI(); return; }
      startUI();
      player.play(EXAMPLES[state.melIndex], state.tempo, setIndex, stopUI);
    });
    root.querySelectorAll('.nt-sys').forEach(function (b) {
      b.addEventListener('click', function () {
        root.querySelectorAll('.nt-sys').forEach(function (x) { x.classList.remove('active'); x.removeAttribute('aria-selected'); });
        b.classList.add('active'); b.setAttribute('aria-selected', 'true');
        state.system = b.getAttribute('data-sys'); draw();
        if (state.playing) { /* keep playing; highlight resumes on next tick */ }
      });
    });
    root.querySelectorAll('.nt-ex').forEach(function (b) {
      b.addEventListener('click', function () {
        player.stop(); stopUI();
        state.melIndex = parseInt(b.getAttribute('data-ex'), 10) || 0;
        state.tempo = EXAMPLES[state.melIndex].tempo;
        var range = root.querySelector('.nt-tempo-range'); range.value = state.tempo;
        root.querySelector('.nt-tempo-val').textContent = state.tempo;
        root.querySelectorAll('.nt-ex').forEach(function (x) { x.classList.remove('active'); });
        b.classList.add('active');
        draw();
      });
    });
    var range = root.querySelector('.nt-tempo-range');
    range.addEventListener('input', function () {
      state.tempo = parseInt(range.value, 10) || 96;
      root.querySelector('.nt-tempo-val').textContent = state.tempo;
    });

    root.querySelector('.nt-ex').classList.add('active');
    draw();
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
