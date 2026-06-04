/*! MAM Academy — Practice Studio · Drone
 * ---------------------------------------------------------------------------
 * A warm, sustained reference pitch for intonation / improvisation / play-along.
 * Two detuned saws + a sub sine (optionally + the perfect fifth) through a gently
 * moving low-pass — pleasant, not a harsh test tone. Fades in/out (no clicks) and
 * retunes smoothly as you change notes. Shares the studio's AudioContext when
 * present, else lazily creates its own. Fully guarded — never throws.
 */
(function () {
  'use strict';

  function init() {
    var panel = document.querySelector('.tool-panel[data-tool="drone"]');
    if (!panel) return;
    var $ = function (id) { return document.getElementById(id); };
    var keysEl = $('droneKeys'), octSel = $('droneOctSel'), fifthEl = $('droneFifth'),
        volEl = $('droneVol'), playBtn = $('dronePlay'), noteEl = $('droneNote'),
        octEl = $('droneOct'), freqEl = $('droneFreq');
    if (!keysEl || !playBtn) return;

    var NAMES = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
    var note = 9, octave = 3, playing = false;            // default A3 (440-ish reference)
    var ownCtx = null, ac = null, master = null, filter = null, lfo = null, lfoGain = null, oscs = [];

    function audio() {
      try {
        if (window.MAM && typeof window.MAM.audio === 'function') return window.MAM.audio();
        if (window.MAM_TOOLS && typeof window.MAM_TOOLS.audio === 'function') return window.MAM_TOOLS.audio();
        if (window.Tone && Tone.context && Tone.context.rawContext) {
          if (Tone.context.state === 'suspended') { try { Tone.start(); } catch (e) {} }
          return Tone.context.rawContext;
        }
      } catch (e) {}
      if (!ownCtx) ownCtx = new (window.AudioContext || window.webkitAudioContext)();
      if (ownCtx.state === 'suspended') { try { ownCtx.resume(); } catch (e) {} }
      return ownCtx;
    }
    function rootFreq() { var midi = (octave + 1) * 12 + note; return 440 * Math.pow(2, (midi - 69) / 12); }
    function targetVol() { return Math.max(0, Math.min(1, (parseInt(volEl.value, 10) || 0) / 100)) * 0.5; }

    keysEl.innerHTML = NAMES.map(function (n, i) {
      return '<button type="button" class="drone-key' + (/#/.test(n) ? ' sharp' : '') + (i === note ? ' on' : '') + '" data-note="' + i + '">' + n.replace('#', '♯') + '</button>';
    }).join('');
    function paintLabel() {
      noteEl.textContent = NAMES[note].replace('#', '♯');
      octEl.textContent = octave;
      freqEl.textContent = rootFreq().toFixed(1) + ' Hz';
    }
    paintLabel();

    // Voice spec: each partial as a ratio of the root, with detune + level.
    function specs() {
      var s = [
        { r: 1, det: -6, type: 'sawtooth', g: 0.5 },
        { r: 1, det: 6, type: 'sawtooth', g: 0.5 },
        { r: 0.5, det: 0, type: 'sine', g: 0.6 }      // sub for weight
      ];
      if (fifthEl.checked) s.push(
        { r: 1.5, det: -6, type: 'sawtooth', g: 0.32 },
        { r: 1.5, det: 6, type: 'sawtooth', g: 0.32 }
      );
      return s;
    }
    function buildOscs() {
      var rf = rootFreq();
      oscs = specs().map(function (sp) {
        var o = ac.createOscillator(); o.type = sp.type; o.frequency.value = rf * sp.r; o.detune.value = sp.det;
        var g = ac.createGain(); g.gain.value = sp.g;
        o.connect(g).connect(filter); o.start();
        return { o: o, r: sp.r };
      });
    }
    function retune() {
      var rf = rootFreq();
      oscs.forEach(function (v) { try { v.o.frequency.setTargetAtTime(rf * v.r, ac.currentTime, 0.03); } catch (e) { try { v.o.frequency.value = rf * v.r; } catch (e2) {} } });
    }

    function start() {
      ac = audio(); if (!ac) return;
      filter = ac.createBiquadFilter(); filter.type = 'lowpass'; filter.frequency.value = 1600; filter.Q.value = 0.6;
      master = ac.createGain(); master.gain.value = 0.0001;
      filter.connect(master).connect(ac.destination);
      // Slow filter movement so the drone breathes rather than sitting dead-still.
      lfo = ac.createOscillator(); lfo.type = 'sine'; lfo.frequency.value = 0.15;
      lfoGain = ac.createGain(); lfoGain.gain.value = 180;
      lfo.connect(lfoGain).connect(filter.frequency); lfo.start();
      buildOscs();
      var t = ac.currentTime;
      master.gain.setValueAtTime(0.0001, t);
      master.gain.exponentialRampToValueAtTime(Math.max(0.0002, targetVol()), t + 0.3);
      playing = true; setBtn(true);
    }
    function stop() {
      if (!playing) return;
      var t = ac.currentTime, dying = oscs.slice(), L = lfo, M = master;
      try {
        M.gain.cancelScheduledValues(t);
        M.gain.setValueAtTime(Math.max(0.0002, M.gain.value || 0.0002), t);
        M.gain.exponentialRampToValueAtTime(0.0001, t + 0.25);
      } catch (e) {}
      setTimeout(function () {
        dying.forEach(function (v) { try { v.o.stop(); } catch (e) {} });
        try { L.stop(); } catch (e) {}
        try { M.disconnect(); } catch (e) {}
      }, 320);
      oscs = []; playing = false; setBtn(false);
    }
    function rebuild() {        // perfect-fifth toggled mid-drone
      if (!playing) return;
      oscs.forEach(function (v) { try { v.o.stop(); } catch (e) {} });
      buildOscs();
    }
    function setBtn(on) {
      playBtn.classList.toggle('playing', on);
      var s = playBtn.querySelector('span'), i = playBtn.querySelector('i');
      if (s) s.textContent = on ? 'Stop drone' : 'Start drone';
      if (i) i.className = on ? 'fa-solid fa-stop' : 'fa-solid fa-play';
    }

    playBtn.addEventListener('click', function () { try { playing ? stop() : start(); } catch (e) { if (window.console && console.warn) console.warn('[drone]', e); } });
    keysEl.addEventListener('click', function (e) {
      var b = e.target.closest('.drone-key'); if (!b) return;
      note = parseInt(b.getAttribute('data-note'), 10) || 0;
      keysEl.querySelectorAll('.drone-key').forEach(function (x) { x.classList.toggle('on', x === b); });
      paintLabel(); if (playing) retune();
    });
    octSel.addEventListener('change', function () { octave = parseInt(octSel.value, 10) || 3; paintLabel(); if (playing) retune(); });
    fifthEl.addEventListener('change', rebuild);
    volEl.addEventListener('input', function () { if (playing && master) { try { master.gain.setTargetAtTime(Math.max(0.0002, targetVol()), ac.currentTime, 0.05); } catch (e) {} } });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
