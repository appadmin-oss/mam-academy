/*
 * Home — "The shape of sound": a breathing waveform you can play. Ported from the
 * handoff's Main.dc.html component (setupField … enableSound), unchanged in what it
 * draws and plays; only the state plumbing is plain DOM instead of setState.
 *
 * Sound is opt-in (nothing plays without a press), the microphone is used only on
 * an explicit press and is analysed on this device only (never played back,
 * recorded or sent), the canvas pauses off-screen, and reduced motion draws one
 * still frame.
 */
(function () {
  'use strict';
  var cv = document.querySelector('canvas[data-field]');
  if (!cv || !cv.getContext) return;
  var ctx = cv.getContext('2d');
  var soundBtn = document.querySelector('[data-act="sound"]'), micBtn = document.querySelector('[data-act="mic"]');
  var readout = document.querySelector('[data-pitch]');
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  // "Stop movement" in the accessibility panel (assets/js/a11y.js) is the same request, made here.
  function still() { return reduce || document.documentElement.classList.contains('a11y-still'); }
  document.addEventListener('mam:a11y', function () { if (typeof kick === 'function') kick(); });
  var state = { sound: false, mic: false, micError: false, pitch: null };

  var seed = 11, rnd = function () { return ((seed = (seed * 16807) % 2147483647) / 2147483647); };
  var noise = Array.from({ length: 400 }, rnd);
  var ripples = [], noteTimes = [], px = null, boost = [], lastBucket = -1, lastMove = 0;
  var W = 0, H = 0, N = 0, dpr = 1, raf = 0, visible = true, t0 = performance.now();
  var ac = null, master = null, stream = null, src = null, analyser = null, tbuf, fbuf, live = null, pitchHz = 0, lastPitchAt = 0, lastPitchUI = 0;

  function size() {
    var r = cv.getBoundingClientRect(), d = Math.min(window.devicePixelRatio || 1, 2);
    cv.width = Math.round(r.width * d); cv.height = Math.round(r.height * d); dpr = d; W = r.width; H = r.height;
    N = Math.min(240, Math.max(60, Math.round(r.width / 6.5))); boost = new Array(N).fill(0); draw(0);
  }
  if (window.ResizeObserver) new ResizeObserver(size).observe(cv);
  size();

  // pentatonic across the width: low on the left, high on the right
  var scale = [0, 2, 4, 7, 9];
  function freqAt(x) { var deg = Math.round((x / W) * 14), midi = 50 + Math.floor(deg / 5) * 12 + scale[deg % 5]; return { freq: 440 * Math.pow(2, (midi - 69) / 12), lastSound: 0 }; }
  var voices = {};
  cv.addEventListener('pointermove', function (e) {
    var r = cv.getBoundingClientRect(); px = e.clientX - r.left; lastMove = performance.now();
    var bucket = Math.floor((px / W) * 15);
    if (bucket !== lastBucket) { lastBucket = bucket; if (state.sound) { var v = voices[bucket] || (voices[bucket] = freqAt(px)); note(v, 0.5); } }
    kick();
  });
  cv.addEventListener('pointerleave', function () { px = null; lastBucket = -1; });
  cv.addEventListener('pointerdown', function (e) {
    var r = cv.getBoundingClientRect(), x = e.clientX - r.left; ripple(x, 1);
    if (state.sound) { var v = freqAt(x); note(v, 1); note({ freq: v.freq * 1.5, lastSound: 0 }, 0.6); }
  });
  if (window.IntersectionObserver) new IntersectionObserver(function (en) { visible = en[0].isIntersecting; if (visible) kick(); }).observe(cv);

  function enableSound() {
    if (!ac) {
      var AC = window.AudioContext || window.webkitAudioContext; if (!AC) return false;
      ac = new AC(); master = ac.createGain(); master.gain.value = 0.7;
      var comp = ac.createDynamicsCompressor(); master.connect(comp); comp.connect(ac.destination);
    }
    if (ac.state === 'suspended') ac.resume(); return true;
  }
  function note(s, vol) {
    if (!ac) return; var now = performance.now();
    if (now - s.lastSound < 90) return; noteTimes = noteTimes.filter(function (t) { return now - t < 1000; }); if (noteTimes.length > 14) return;
    s.lastSound = now; noteTimes.push(now); vol = vol || 1;
    var t = ac.currentTime, g = ac.createGain(), f = ac.createBiquadFilter(), o1 = ac.createOscillator(), o2 = ac.createOscillator(), g2 = ac.createGain();
    o1.type = 'triangle'; o1.frequency.value = s.freq; o2.type = 'sine'; o2.frequency.value = s.freq * 2.003; g2.gain.value = 0.22;
    f.type = 'lowpass'; f.frequency.setValueAtTime(2800, t); f.frequency.exponentialRampToValueAtTime(700, t + 1.6);
    g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.17 * vol, t + 0.008); g.gain.exponentialRampToValueAtTime(0.0001, t + 2.2);
    o1.connect(f); o2.connect(g2); g2.connect(f); f.connect(g); g.connect(master);
    o1.start(t); o2.start(t); o1.stop(t + 2.3); o2.stop(t + 2.3);
  }
  function ripple(x, a) { ripples.push({ x: x, t: performance.now(), a: a }); if (ripples.length > 8) ripples.shift(); kick(); }

  var BTN = 'width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 7px; cursor: pointer; backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); ';
  var ON = BTN + 'background: #F2A93B; color: #211A13; border: 1px solid #F2A93B', OFF = BTN + 'background: rgba(252,248,243,.06); color: #FCF8F3; border: 1px solid rgba(252,248,243,.28)';
  function paintButtons() {
    if (soundBtn) {
      soundBtn.setAttribute('style', state.sound ? ON : OFF);
      soundBtn.setAttribute('aria-pressed', state.sound ? 'true' : 'false');
      soundBtn.setAttribute('aria-label', state.sound ? 'Turn sound off' : 'Turn sound on');
      var waves = soundBtn.querySelectorAll('path')[1];
      if (waves) waves.setAttribute('d', state.sound ? 'M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13' : 'M16 9l5 6M21 9l-5 6');
    }
    if (micBtn) {
      var label = state.mic ? 'Stop listening' : (state.micError ? 'Microphone unavailable — try again' : 'Sing to it: uses your microphone on this device only, nothing is recorded or sent');
      micBtn.setAttribute('style', state.mic ? ON : OFF);
      micBtn.setAttribute('aria-pressed', state.mic ? 'true' : 'false');
      micBtn.setAttribute('aria-label', label); micBtn.title = label;
    }
    if (readout) {
      readout.hidden = !state.mic;
      var p = state.pitch;
      readout.querySelector('[data-pitch-note]').textContent = p ? p.note : 'Hum';
      readout.querySelector('[data-pitch-oct]').textContent = p ? p.oct : '';
      readout.querySelector('[data-pitch-detail]').textContent = p
        ? p.hz + ' Hz · ' + (Math.abs(p.cents) <= 5 ? 'in tune' : Math.abs(p.cents) + '¢ ' + (p.cents > 0 ? 'sharp' : 'flat'))
        : 'or sing any note — we’ll show you which one';
    }
  }
  if (soundBtn) soundBtn.addEventListener('click', function () {
    var on = !state.sound; if (on && !enableSound()) return; state.sound = on; paintButtons();
    if (on && W) [0.2, 0.35, 0.5, 0.65, 0.8].forEach(function (f, n) { setTimeout(function () { ripple(W * f, 0.8); note(freqAt(W * f), 0.7); }, n * 160); });
  });
  if (micBtn) micBtn.addEventListener('click', function () {
    if (state.mic) { stopMic(); state.mic = false; state.pitch = null; paintButtons(); } else startMic();
  });

  function startMic() {
    if (!enableSound() || !navigator.mediaDevices) { state.micError = true; paintButtons(); return; }
    navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false } }).then(function (s) {
      stream = s; src = ac.createMediaStreamSource(stream);
      analyser = ac.createAnalyser(); analyser.fftSize = 2048; analyser.smoothingTimeConstant = 0.6;
      src.connect(analyser);                       // analysed only — never played back, recorded or sent
      tbuf = new Float32Array(analyser.fftSize); fbuf = new Uint8Array(analyser.frequencyBinCount);
      live = new Array(N || 200).fill(0); pitchHz = 0; lastPitchAt = 0; lastPitchUI = 0;
      state.mic = true; state.micError = false; state.pitch = null; paintButtons(); kick();
    }).catch(function () { stopMic(); state.mic = false; state.micError = true; paintButtons(); });
  }
  function stopMic() {
    if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); stream = null;
    if (src) { try { src.disconnect(); } catch (e) { /* already gone */ } } src = null; analyser = null;
  }
  function detectPitch(buf, sr) {                  // autocorrelation with parabolic refinement
    var n = buf.length, rms = 0, i, j; for (i = 0; i < n; i++) rms += buf[i] * buf[i]; rms = Math.sqrt(rms / n); if (rms < 0.012) return -1;
    var r1 = 0, r2 = n - 1, th = 0.2;
    for (i = 0; i < n / 2; i++) if (Math.abs(buf[i]) < th) { r1 = i; break; }
    for (i = 1; i < n / 2; i++) if (Math.abs(buf[n - i]) < th) { r2 = n - i; break; }
    var b = buf.slice(r1, r2); n = b.length; var c = new Float32Array(n);
    for (i = 0; i < n; i++) { var sum = 0; for (j = 0; j < n - i; j++) sum += b[j] * b[j + i]; c[i] = sum; }
    var dd = 0; while (dd < n - 1 && c[dd] > c[dd + 1]) dd++;
    var maxv = -1, maxp = -1; for (i = dd; i < n; i++) if (c[i] > maxv) { maxv = c[i]; maxp = i; }
    if (maxp <= 0 || maxp >= n - 1) return -1;
    var x1 = c[maxp - 1], x2 = c[maxp], x3 = c[maxp + 1], a = (x1 + x3 - 2 * x2) / 2, bb = (x3 - x1) / 2;
    var T0 = a ? maxp - bb / (2 * a) : maxp, f = sr / T0;
    return f > 60 && f < 1400 ? f : -1;
  }
  var NAMES = ['C', 'C♯', 'D', 'E♭', 'E', 'F', 'F♯', 'G', 'A♭', 'A', 'B♭', 'B'];
  function listen(now) {
    if (!analyser) return;
    analyser.getFloatTimeDomainData(tbuf); analyser.getByteFrequencyData(fbuf);
    var sr = ac.sampleRate, binHz = sr / analyser.fftSize, i;
    if (!live || live.length !== N) live = new Array(N).fill(0);
    for (i = 0; i < N; i++) {                      // log-spaced: 70 Hz on the left → 1.6 kHz on the right
      var fq = 70 * Math.pow(1600 / 70, i / (N - 1)), v = (fbuf[Math.min(fbuf.length - 1, Math.round(fq / binHz))] || 0) / 255;
      live[i] += (Math.pow(v, 1.6) - live[i]) * 0.35;
    }
    var f = detectPitch(tbuf, sr);
    if (f > 0) { pitchHz = pitchHz ? pitchHz * 0.7 + f * 0.3 : f; lastPitchAt = now; }
    else if (now - lastPitchAt > 600) pitchHz = 0;
    if (now - lastPitchUI > 140) {
      lastPitchUI = now;
      if (pitchHz) {
        var m = 69 + 12 * Math.log2(pitchHz / 440), r = Math.round(m), cents = Math.round((m - r) * 100);
        var p = { note: NAMES[((r % 12) + 12) % 12], oct: String(Math.floor(r / 12) - 1), hz: Math.round(pitchHz), cents: cents };
        var cur = state.pitch; if (!cur || cur.note !== p.note || cur.oct !== p.oct || cur.cents !== p.cents) { state.pitch = p; paintButtons(); }
      } else if (state.pitch) { state.pitch = null; paintButtons(); }
    }
  }
  function kick() { if (still() && !state.mic) { draw(0); return; } if (!raf) raf = requestAnimationFrame(frame); }
  function frame() {
    raf = 0; if (!visible) return;
    var now = performance.now();
    ripples = ripples.filter(function (r) { return now - r.t < 2400; });
    if (state.mic) listen(now);
    if (!state.mic && px == null && now - lastMove > 2500 && Math.random() < 0.006) ripple(W * (0.2 + Math.random() * 0.6), 0.45);   // the room breathes
    draw((now - t0) / 1000);
    if (still() && !state.mic) return;   // switched off mid-visit: this frame was the last
    raf = requestAnimationFrame(frame);
  }
  function draw(time) {
    if (!N) return;
    var c = ctx, now = performance.now(), base = H, i;
    c.setTransform(dpr, 0, 0, dpr, 0, 0); c.clearRect(0, 0, W, H);
    c.lineCap = 'round'; c.globalCompositeOperation = 'lighter';
    var grow = still() ? 1 : Math.min(1, time / 1.4), ease = 1 - Math.pow(1 - grow, 3);
    var pitchX = state.mic && pitchHz ? (Math.log(pitchHz / 70) / Math.log(1600 / 70)) * W : null;
    for (i = 0; i < N; i++) {
      var t = (i + 0.5) / N, x = t * W;
      // a song-like envelope that rises from the floor, tallest in the middle
      var env = Math.pow(Math.sin(Math.PI * t), 1.6) * (0.5 + 0.5 * noise[(i * 3) % 400]) * (0.8 + 0.2 * Math.sin(t * 11 + 1.3));
      if (state.mic && live) env = env * 0.18 + (live[i] || 0) * 1.05;
      var breathe = still() ? 1 : 0.84 + 0.16 * Math.sin(time * 1.7 + i * 0.21) * Math.sin(time * 0.6 + i * 0.05);
      var target = pitchX != null ? Math.exp(-Math.pow((x - pitchX) / 26, 2)) : (px == null ? 0 : Math.exp(-Math.pow((x - px) / 70, 2)));
      boost[i] += (target - boost[i]) * 0.14;
      var rip = 0;
      for (var k = 0; k < ripples.length; k++) {
        var r = ripples[k], age = (now - r.t) / 1000, front = age * 560;
        rip += r.a * Math.exp(-Math.pow((Math.abs(x - r.x) - front) / 46, 2)) * Math.exp(-age * 1.3);
      }
      var energy = Math.min(1.4, boost[i] * 0.9 + rip);
      var h = H * 0.8 * ease * (env * breathe + energy * 0.35);
      if (h < 2) continue;
      var a = Math.min(1, 0.2 + env * 0.35 + energy * 0.6);
      var g = c.createLinearGradient(0, base, 0, base - h);
      g.addColorStop(0, 'rgba(255,238,214,' + Math.min(1, a + 0.35).toFixed(3) + ')'); g.addColorStop(1, 'rgba(255,214,160,' + (a * 0.75).toFixed(3) + ')');
      c.strokeStyle = g; c.lineWidth = 1 + energy * 0.6;
      c.beginPath(); c.moveTo(x, base); c.lineTo(x, base - h); c.stroke();
      c.fillStyle = 'rgba(255,240,214,' + Math.min(1, a + 0.25).toFixed(3) + ')';
      c.beginPath(); c.arc(x, base - h, 1.2 + energy * 1.4, 0, Math.PI * 2); c.fill();
    }
    // warm light where the sound comes from
    var glow = c.createRadialGradient(W / 2, H, 0, W / 2, H, Math.max(W, H) * 0.45);
    glow.addColorStop(0, 'rgba(255,230,190,0.45)'); glow.addColorStop(0.3, 'rgba(242,169,59,0.12)'); glow.addColorStop(1, 'rgba(242,169,59,0)');
    c.fillStyle = glow; c.fillRect(0, 0, W, H);
    c.globalCompositeOperation = 'source-over';
  }
  kick();
})();
