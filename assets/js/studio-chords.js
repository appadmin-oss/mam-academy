/*! MAM Academy — Chord & Scale Explorer  ·  studio tool "chords"
 * ---------------------------------------------------------------------------
 * An interactive 2-octave piano that highlights and plays any scale or chord.
 * Pick a root + a scale/chord and the keyboard lights up; click a key to hear
 * it, or press Play to arpeggiate the scale / strum the chord. Pure DOM + Web
 * Audio (shares the studio's AudioContext), dependency-free, works offline.
 */
(function () {
  'use strict';
  var panel = document.querySelector('.tool-panel[data-tool="chords"]');
  if (!panel) return;
  var $ = function (s) { return panel.querySelector(s); };

  var NOTES = ['C', 'C♯', 'D', 'D♯', 'E', 'F', 'F♯', 'G', 'G♯', 'A', 'A♯', 'B'];
  var BLACK = { 1: 1, 3: 1, 6: 1, 8: 1, 10: 1 };

  var SCALES = {
    'Major (Ionian)':     [0, 2, 4, 5, 7, 9, 11],
    'Natural Minor':      [0, 2, 3, 5, 7, 8, 10],
    'Harmonic Minor':     [0, 2, 3, 5, 7, 8, 11],
    'Melodic Minor':      [0, 2, 3, 5, 7, 9, 11],
    'Dorian':             [0, 2, 3, 5, 7, 9, 10],
    'Phrygian':           [0, 1, 3, 5, 7, 8, 10],
    'Lydian':             [0, 2, 4, 6, 7, 9, 11],
    'Mixolydian':         [0, 2, 4, 5, 7, 9, 10],
    'Major Pentatonic':   [0, 2, 4, 7, 9],
    'Minor Pentatonic':   [0, 3, 5, 7, 10],
    'Blues':              [0, 3, 5, 6, 7, 10]
  };
  var CHORDS = {
    'Major':        [0, 4, 7],
    'Minor':        [0, 3, 7],
    'Diminished':   [0, 3, 6],
    'Augmented':    [0, 4, 8],
    'Sus2':         [0, 2, 7],
    'Sus4':         [0, 5, 7],
    'Major 6':      [0, 4, 7, 9],
    'Minor 6':      [0, 3, 7, 9],
    'Dominant 7':   [0, 4, 7, 10],
    'Major 7':      [0, 4, 7, 11],
    'Minor 7':      [0, 3, 7, 10],
    'Minor 7♭5': [0, 3, 6, 10],
    'Diminished 7': [0, 3, 6, 9],
    'Add9':         [0, 4, 7, 14]
  };
  var DEGREE = { 0: '1', 1: '♭2', 2: '2', 3: '♭3', 4: '3', 5: '4', 6: '♯4', 7: '5', 8: '♯5', 9: '6', 10: '♭7', 11: '7', 14: '9' };

  // ── audio ──
  var ac = null;
  function audio() {
    try {
      if (window.MAM && typeof window.MAM.audio === 'function') return (ac = window.MAM.audio());
      if (!ac) ac = new (window.AudioContext || window.webkitAudioContext)();
      if (ac.state === 'suspended') ac.resume();
      return ac;
    } catch (e) { return null; }
  }
  function mtof(m) { return 440 * Math.pow(2, (m - 69) / 12); }
  function playMidi(m, when, dur) {
    var a = audio(); if (!a) return;
    when = when || a.currentTime; dur = dur || 0.9;
    var o = a.createOscillator(), h = a.createOscillator(), g = a.createGain(), hg = a.createGain();
    o.type = 'triangle'; o.frequency.value = mtof(m);
    h.type = 'sine'; h.frequency.value = mtof(m) * 2; hg.gain.value = 0.35;
    g.gain.setValueAtTime(0.0001, when);
    g.gain.exponentialRampToValueAtTime(0.22, when + 0.012);
    g.gain.exponentialRampToValueAtTime(0.0001, when + dur);
    o.connect(g); h.connect(hg).connect(g); g.connect(a.destination);
    o.start(when); h.start(when); o.stop(when + dur + 0.05); h.stop(when + dur + 0.05);
  }

  // ── state ──
  var root = 0;            // pitch class 0..11
  var mode = 'scale';      // 'scale' | 'chord'
  var pickName = 'Major (Ionian)';
  var LOW = 48, HIGH = 72; // C3..C5
  var arpTimers = [];

  function currentIntervals() { return (mode === 'scale' ? SCALES[pickName] : CHORDS[pickName]) || [0]; }
  function pcSet() { var s = {}; currentIntervals().forEach(function (iv) { s[(root + iv) % 12] = 1; }); return s; }

  // ── keyboard ──
  function buildKeyboard() {
    var kb = $('#chKeys'); kb.innerHTML = '';
    var whites = [], blacks = [];
    for (var m = LOW; m <= HIGH; m++) { (BLACK[m % 12] ? blacks : whites).push(m); }
    var wrap = document.createElement('div'); wrap.className = 'ch-kb-inner';
    whites.forEach(function (m) {
      var k = document.createElement('button');
      k.type = 'button'; k.className = 'ch-key ch-white'; k.setAttribute('data-m', m);
      k.style.width = (100 / whites.length) + '%';
      k.innerHTML = '<span class="ch-key-label"></span>';
      wrap.appendChild(k);
    });
    blacks.forEach(function (m) {
      var below = 0; for (var i = 0; i < whites.length; i++) if (whites[i] < m) below++;
      var k = document.createElement('button');
      k.type = 'button'; k.className = 'ch-key ch-black'; k.setAttribute('data-m', m);
      k.style.left = (below / whites.length * 100) + '%';
      k.style.width = (100 / whites.length * 0.62) + '%';
      wrap.appendChild(k);
    });
    kb.appendChild(wrap);
    kb.addEventListener('pointerdown', function (e) {
      var k = e.target.closest('.ch-key'); if (!k) return;
      e.preventDefault();
      var m = +k.getAttribute('data-m'); playMidi(m);
      k.classList.add('ch-hit'); setTimeout(function () { k.classList.remove('ch-hit'); }, 140);
    });
  }
  function paintKeyboard() {
    var set = pcSet();
    Array.prototype.forEach.call(panel.querySelectorAll('.ch-key'), function (k) {
      var m = +k.getAttribute('data-m'), pc = m % 12, inSet = !!set[pc], isRoot = pc === root;
      k.classList.toggle('on', inSet);
      k.classList.toggle('root', inSet && isRoot);
      var lbl = k.querySelector('.ch-key-label');
      if (lbl) lbl.textContent = (inSet && !BLACK[pc]) ? NOTES[pc].replace('♯', '#') : '';
    });
  }

  function fillPicker() {
    var sel = $('#chPick'); sel.innerHTML = '';
    var src = mode === 'scale' ? SCALES : CHORDS;
    Object.keys(src).forEach(function (name) {
      var o = document.createElement('option'); o.value = name; o.textContent = name;
      if (name === pickName) o.selected = true;
      sel.appendChild(o);
    });
  }
  function info() {
    var iv = currentIntervals();
    var names = iv.map(function (i) { return NOTES[(root + i) % 12]; });
    var degs = iv.map(function (i) { return DEGREE[i] || '?'; });
    var symbol = mode === 'chord' ? (NOTES[root] + ' ' + pickName) : (NOTES[root] + ' ' + pickName);
    $('#chInfo').innerHTML =
      '<div class="ch-info-name">' + symbol + '</div>' +
      '<div class="ch-info-notes">' + names.map(function (n, i) { return '<span class="ch-chip' + (i === 0 ? ' root' : '') + '"><b>' + n + '</b><i>' + degs[i] + '</i></span>'; }).join('') + '</div>';
  }

  function render() { fillPicker(); paintKeyboard(); info(); }

  function clearArp() { arpTimers.forEach(clearTimeout); arpTimers = []; }
  function play() {
    clearArp();
    var a = audio(); if (!a) return;
    var iv = currentIntervals(), base = 60 + root;   // C4 register
    if (mode === 'chord') {
      iv.forEach(function (i, n) { playMidi(base + i, a.currentTime + n * 0.012, 1.6); });   // strum
      var ks = panel.querySelectorAll('.ch-key.on'); Array.prototype.forEach.call(ks, function (k) { k.classList.add('ch-hit'); setTimeout(function () { k.classList.remove('ch-hit'); }, 400); });
    } else {
      var seq = iv.slice(); seq.push(12);              // up to the octave
      seq.forEach(function (i, n) {
        arpTimers.push(setTimeout(function () {
          if (!panel.classList.contains('active')) return;
          playMidi(base + i, 0, 0.5);
          var key = panel.querySelector('.ch-key[data-m="' + (base + i) + '"]');
          if (key) { key.classList.add('ch-hit'); setTimeout(function () { key.classList.remove('ch-hit'); }, 200); }
        }, n * 180));
      });
    }
  }

  // ── wire ──
  $('#chRoot').addEventListener('change', function (e) { root = parseInt(e.target.value, 10) || 0; render(); });
  panel.querySelectorAll('[data-ch-mode]').forEach(function (b) {
    b.addEventListener('click', function () {
      mode = b.getAttribute('data-ch-mode');
      panel.querySelectorAll('[data-ch-mode]').forEach(function (x) { x.classList.toggle('active', x === b); });
      pickName = mode === 'scale' ? 'Major (Ionian)' : 'Major';
      render();
    });
  });
  $('#chPick').addEventListener('change', function (e) { pickName = e.target.value; render(); });
  $('#chPlay').addEventListener('click', play);
  // Stop arpeggios when leaving the tool.
  Array.prototype.forEach.call(document.querySelectorAll('.tools-tabs button'), function (b) {
    b.addEventListener('click', function () { if (b.getAttribute('data-tool') !== 'chords') clearArp(); });
  });

  buildKeyboard();
  render();
  window.MAM_CHORDS = { play: play, set: function (r, m, n) { if (r != null) root = r; if (m) mode = m; if (n) pickName = n; render(); } };
})();
