/* MAM Academy — "One song, three expressions" · playback (window.MAM_EXPR_AUDIO)
 * ---------------------------------------------------------------------------
 * Plays an arrangement from expressions-engine.js. Every instrument is built on
 * the Web Audio API so the page needs nothing from a CDN to make sound:
 *   piano (struck, decaying partials; the sampled Salamander grand replaces it
 *   when Tone.js is on the page and its samples have loaded), electric piano
 *   (two-operator FM with a tine), bass, highlife guitar (plucked, bright then
 *   dark), voice and chorus (a sawtooth through vowel formants with delayed
 *   vibrato), soft lead, strings pad, drum kit (kick, snare, hats, crash),
 *   agogo bell (two inharmonic bells), shekere (shaped noise) and dùndún
 *   (membrane with a pitch glide).
 * Everything runs through a small room reverb and a bus compressor. Nothing
 * makes a sound until play() is called from a click (autoplay rules).
 */
(function () {
  'use strict';
  var ctx = null, master = null, comp = null, verb = null, verbSend = null, sampler = null, toneTried = false;

  function midiHz(m) { return 440 * Math.pow(2, (m - 69) / 12); }
  function context() {
    if (ctx) return ctx;
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return null;
    ctx = new AC({ latencyHint: 'playback' });
    comp = ctx.createDynamicsCompressor();
    comp.threshold.value = -16; comp.knee.value = 10; comp.ratio.value = 3.2; comp.attack.value = 0.008; comp.release.value = 0.2;
    master = ctx.createGain(); master.gain.value = 0.82;
    verb = ctx.createConvolver(); verb.buffer = impulse(2.1, 2.6);
    verbSend = ctx.createGain(); verbSend.gain.value = 0.22;
    verbSend.connect(verb); verb.connect(comp); comp.connect(master); master.connect(ctx.destination);
    return ctx;
  }
  function impulse(sec, decay) {
    var len = Math.floor(ctx.sampleRate * sec), b = ctx.createBuffer(2, len, ctx.sampleRate);
    for (var c = 0; c < 2; c++) { var d = b.getChannelData(c); for (var i = 0; i < len; i++) d[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / len, decay); }
    return b;
  }
  var noiseBuf = null;
  function noise() {
    if (noiseBuf) return noiseBuf;
    var len = ctx.sampleRate, b = ctx.createBuffer(1, len, ctx.sampleRate), d = b.getChannelData(0);
    for (var i = 0; i < len; i++) d[i] = Math.random() * 2 - 1;
    return (noiseBuf = b);
  }
  // Load Tone.js once, after a gesture, for the sampled piano (the Studio loads
  // the same build the same way). Optional: the synthesised piano covers it.
  function tryTone() {
    if (toneTried) return; toneTried = true;
    function makeSampler() {
      try {
        if (window.Tone.setContext) window.Tone.setContext(ctx);
        sampler = new window.Tone.Sampler({
          urls: { A1: 'A1.mp3', C2: 'C2.mp3', 'F#2': 'Fs2.mp3', A2: 'A2.mp3', C3: 'C3.mp3', 'F#3': 'Fs3.mp3', A3: 'A3.mp3', C4: 'C4.mp3', 'F#4': 'Fs4.mp3', A4: 'A4.mp3', C5: 'C5.mp3', 'F#5': 'Fs5.mp3', A5: 'A5.mp3', C6: 'C6.mp3' },
          baseUrl: 'https://tonejs.github.io/audio/salamander/', release: 1.2, volume: -7
        });
        sampler.connect(comp);
        var send = ctx.createGain(); send.gain.value = 0.5; sampler.connect(send); send.connect(verbSend);
      } catch (e) { sampler = null; }
    }
    if (window.Tone) { makeSampler(); return; }
    var s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/tone@15.0.4/build/Tone.js'; s.async = true; s.crossOrigin = 'anonymous';
    s.onload = makeSampler; s.onerror = function () {};
    document.head.appendChild(s);
  }

  /* ── Voices ─────────────────────────────────────────────────────────────── */
  function env(g, t, a, peak, d, sus, rel, end) {
    g.gain.setValueAtTime(0.0001, t);
    g.gain.linearRampToValueAtTime(peak, t + a);
    g.gain.setTargetAtTime(peak * sus, t + a, d);
    g.gain.setTargetAtTime(0.0001, end, rel);
  }
  function out(node, dry, wet, bus) { var g = ctx.createGain(); g.gain.value = dry; node.connect(g); g.connect(bus); var w = ctx.createGain(); w.gain.value = wet; node.connect(w); w.connect(verbSend); }
  function osc(type, hz, t, stop) { var o = ctx.createOscillator(); o.type = type; o.frequency.setValueAtTime(hz, t); o.start(t); o.stop(stop); return o; }

  var V = {
    piano: function (e, t, bus) {
      if (sampler && sampler.loaded) { try { sampler.triggerAttackRelease(midiHz(e.midi), Math.max(0.1, e.dur), t, Math.min(1, e.vel * 1.25)); return; } catch (x) {} }
      var f = midiHz(e.midi), decay = 1.6 * Math.sqrt(262 / f) + 0.4, g = ctx.createGain(), end = t + Math.max(e.dur, 0.12);
      [[1, 0.55], [2, 0.24], [3, 0.12], [4, 0.06], [5, 0.03]].forEach(function (p, i) {
        var o = osc('sine', f * p[0] * (1 + 0.0004 * p[0] * p[0]), t, end + 1.5), pg = ctx.createGain();
        pg.gain.setValueAtTime(0.0001, t); pg.gain.linearRampToValueAtTime(p[1], t + 0.004);
        pg.gain.setTargetAtTime(0.0001, t + 0.004, decay / (1 + i * 0.7)); pg.gain.setTargetAtTime(0.0001, end, 0.18);
        o.connect(pg); pg.connect(g);
      });
      var n = ctx.createBufferSource(), nf = ctx.createBiquadFilter(), ng = ctx.createGain();
      n.buffer = noise(); nf.type = 'bandpass'; nf.frequency.value = Math.min(6000, f * 6); nf.Q.value = 1.2;
      ng.gain.setValueAtTime(0.08, t); ng.gain.exponentialRampToValueAtTime(0.0001, t + 0.04);
      n.connect(nf); nf.connect(ng); ng.connect(g); n.start(t); n.stop(t + 0.06);
      g.gain.value = e.vel * 0.7; out(g, 1, 0.45, bus);
    },
    epiano: function (e, t, bus) {
      var f = midiHz(e.midi), end = t + Math.max(e.dur, 0.1), car = osc('sine', f, t, end + 1.2), mod = osc('sine', f, t, end + 1.2);
      var mg = ctx.createGain(); mg.gain.setValueAtTime(f * 2.2, t); mg.gain.setTargetAtTime(f * 0.25, t, 0.25); mod.connect(mg); mg.connect(car.frequency);
      var tine = osc('sine', f * 14, t, t + 0.3), tg = ctx.createGain(); tg.gain.setValueAtTime(0.05, t); tg.gain.exponentialRampToValueAtTime(0.0001, t + 0.15); tine.connect(tg);
      var g = ctx.createGain(); env(g, t, 0.004, e.vel * 0.5, 0.9, 0.35, 0.25, end);
      car.connect(g); tg.connect(g);
      var trem = osc('sine', 4.6, t, end + 1.2), tgn = ctx.createGain(); tgn.gain.value = 0.08; trem.connect(tgn); tgn.connect(g.gain);
      out(g, 1, 0.3, bus);
    },
    bass: function (e, t, bus) {
      var f = midiHz(e.midi), end = t + Math.max(e.dur, 0.08), o1 = osc('sine', f, t, end + 0.4), o2 = osc('sawtooth', f, t, end + 0.4);
      var lp = ctx.createBiquadFilter(); lp.type = 'lowpass'; lp.Q.value = 2; lp.frequency.setValueAtTime(1100, t); lp.frequency.setTargetAtTime(380, t, 0.08);
      var m2 = ctx.createGain(); m2.gain.value = 0.25; o2.connect(m2); m2.connect(lp); o1.connect(lp);
      var g = ctx.createGain(); env(g, t, 0.006, e.vel * 0.7, 0.35, 0.6, 0.06, end);
      lp.connect(g); out(g, 1, 0.05, bus);
    },
    guitar: function (e, t, bus) {
      var f = midiHz(e.midi), end = t + Math.max(e.dur, 0.3), o = osc('sawtooth', f, t, end + 0.6), o2 = osc('triangle', f * 2.002, t, end + 0.6);
      var lp = ctx.createBiquadFilter(); lp.type = 'lowpass'; lp.Q.value = 3; lp.frequency.setValueAtTime(5200, t); lp.frequency.exponentialRampToValueAtTime(700, t + 0.25);
      var g2 = ctx.createGain(); g2.gain.value = 0.3; o2.connect(g2); g2.connect(lp); o.connect(lp);
      var g = ctx.createGain(); g.gain.setValueAtTime(0.0001, t); g.gain.linearRampToValueAtTime(e.vel * 0.42, t + 0.003); g.gain.setTargetAtTime(0.0001, t + 0.003, 0.22);
      lp.connect(g); out(g, 1, 0.35, bus);
    },
    voice: function (e, t, bus, opt) { formantVoice(e, t, bus, opt || { vowel: [730, 1090, 2440], detune: [0], level: 0.5, vib: 0.006 }); },
    chorus: function (e, t, bus) { formantVoice(e, t, bus, { vowel: [570, 840, 2410], detune: [-9, 0, 8], level: 0.3, vib: 0.005 }); },
    lead: function (e, t, bus) {
      var f = midiHz(e.midi), end = t + Math.max(e.dur, 0.1), o = osc('triangle', f, t, end + 0.6), o2 = osc('sine', f * 2, t, end + 0.6);
      var vib = osc('sine', 5.2, t, end + 0.6), vg = ctx.createGain(); vg.gain.setValueAtTime(0, t); vg.gain.linearRampToValueAtTime(f * 0.005, t + 0.35); vib.connect(vg); vg.connect(o.frequency); vg.connect(o2.frequency);
      var lp = ctx.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 2600;
      var m2 = ctx.createGain(); m2.gain.value = 0.18; o2.connect(m2); m2.connect(lp); o.connect(lp);
      var g = ctx.createGain(); env(g, t, 0.03, e.vel * 0.42, 0.6, 0.8, 0.12, end);
      lp.connect(g); out(g, 1, 0.45, bus);
    },
    strings: function (e, t, bus) {
      var f = midiHz(e.midi), end = t + Math.max(e.dur, 0.4), g = ctx.createGain(), lp = ctx.createBiquadFilter();
      lp.type = 'lowpass'; lp.frequency.value = 1800;
      [-7, 6].forEach(function (c) { var o = osc('sawtooth', f, t, end + 1.2); o.detune.value = c; o.connect(lp); });
      env(g, t, 0.45, e.vel * 0.18, 1, 0.9, 0.5, end); lp.connect(g); out(g, 1, 0.6, bus);
    },
    kick: function (e, t, bus) {
      var o = osc('sine', 130, t, t + 0.5), g = ctx.createGain();
      o.frequency.exponentialRampToValueAtTime(42, t + 0.12);
      g.gain.setValueAtTime(e.vel * 0.95, t); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.42);
      o.connect(g); out(g, 1, 0.04, bus);
    },
    snare: function (e, t, bus) {
      var n = ctx.createBufferSource(), hp = ctx.createBiquadFilter(), g = ctx.createGain(); n.buffer = noise();
      hp.type = 'highpass'; hp.frequency.value = 1400; g.gain.setValueAtTime(e.vel * 0.55, t); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.18);
      n.connect(hp); hp.connect(g); n.start(t); n.stop(t + 0.22);
      var o = osc('triangle', 190, t, t + 0.15), og = ctx.createGain(); og.gain.setValueAtTime(e.vel * 0.4, t); og.gain.exponentialRampToValueAtTime(0.0001, t + 0.1); o.connect(og);
      og.connect(g); out(g, 1, 0.22, bus);
    },
    hat: function (e, t, bus) { metalNoise(e, t, bus, 0.04); },
    hatOpen: function (e, t, bus) { metalNoise(e, t, bus, 0.28); },
    crash: function (e, t, bus) { metalNoise(e, t, bus, 1.4, 5000); },
    bellHigh: function (e, t, bus) { bell(e, t, bus, 930); },
    bellLow: function (e, t, bus) { bell(e, t, bus, 690); },
    shekere: function (e, t, bus) { shaker(e, t, bus, 0.11, 5200); },
    shekereChk: function (e, t, bus) { shaker(e, t, bus, 0.05, 7600); },
    talkingDrum: function (e, t, bus) {
      var o = osc('sine', e.hz || 110, t, t + 0.5), g = ctx.createGain();
      o.frequency.setValueAtTime(e.hz || 110, t); o.frequency.exponentialRampToValueAtTime(e.hzTo || e.hz || 110, t + 0.13);
      g.gain.setValueAtTime(0.0001, t); g.gain.linearRampToValueAtTime(e.vel * 0.8, t + 0.004); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.38);
      var o2 = osc('sine', (e.hzTo || 110) * 1.58, t, t + 0.25), g2 = ctx.createGain(); g2.gain.setValueAtTime(e.vel * 0.15, t); g2.gain.exponentialRampToValueAtTime(0.0001, t + 0.12);
      o.connect(g); o2.connect(g2); g2.connect(g);
      var n = ctx.createBufferSource(), bp = ctx.createBiquadFilter(), ng = ctx.createGain(); n.buffer = noise(); bp.type = 'bandpass'; bp.frequency.value = 900;
      ng.gain.setValueAtTime(e.vel * 0.12, t); ng.gain.exponentialRampToValueAtTime(0.0001, t + 0.03); n.connect(bp); bp.connect(ng); ng.connect(g); n.start(t); n.stop(t + 0.05);
      out(g, 1, 0.25, bus);
    }
  };
  function formantVoice(e, t, bus, o) {
    var f = midiHz(e.midi), end = t + Math.max(e.dur, 0.12), g = ctx.createGain(), src = ctx.createGain();
    o.detune.forEach(function (c, i) {
      var s = osc('sawtooth', f, t + i * 0.012, end + 0.6); s.detune.value = c;
      var vib = osc('sine', 5.3 + i * 0.3, t, end + 0.6), vg = ctx.createGain();
      vg.gain.setValueAtTime(0, t); vg.gain.linearRampToValueAtTime(f * o.vib, t + 0.4); vib.connect(vg); vg.connect(s.frequency);
      s.connect(src);
    });
    o.vowel.forEach(function (fr, i) {
      var bp = ctx.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = fr; bp.Q.value = [9, 11, 14][i];
      var bg = ctx.createGain(); bg.gain.value = [1, 0.55, 0.22][i]; src.connect(bp); bp.connect(bg); bg.connect(g);
    });
    env(g, t, 0.06, e.vel * o.level * 2.2, 0.5, 0.85, 0.12, end);
    out(g, 1, 0.5, bus);
  }
  function metalNoise(e, t, bus, dec, hpHz) {
    var n = ctx.createBufferSource(), hp = ctx.createBiquadFilter(), g = ctx.createGain(); n.buffer = noise();
    hp.type = 'highpass'; hp.frequency.value = hpHz || 7200; g.gain.setValueAtTime(e.vel * 0.32, t); g.gain.exponentialRampToValueAtTime(0.0001, t + dec);
    n.connect(hp); hp.connect(g); n.start(t); n.stop(t + dec + 0.05); out(g, 1, 0.15, bus);
  }
  function bell(e, t, bus, f) {
    var g = ctx.createGain();
    [[1, 0.6, 0.35], [2.76, 0.28, 0.16], [5.4, 0.12, 0.08]].forEach(function (p) {
      var o = osc('sine', f * p[0], t, t + 0.6), pg = ctx.createGain(); pg.gain.setValueAtTime(p[1], t); pg.gain.exponentialRampToValueAtTime(0.0001, t + p[2]);
      o.connect(pg); pg.connect(g);
    });
    g.gain.value = e.vel * 0.5; out(g, 1, 0.2, bus);
  }
  function shaker(e, t, bus, dec, f) {
    var n = ctx.createBufferSource(), bp = ctx.createBiquadFilter(), g = ctx.createGain(); n.buffer = noise();
    bp.type = 'bandpass'; bp.frequency.value = f; bp.Q.value = 1.4;
    g.gain.setValueAtTime(0.0001, t); g.gain.linearRampToValueAtTime(e.vel * 0.6, t + dec * 0.35); g.gain.exponentialRampToValueAtTime(0.0001, t + dec);
    n.connect(bp); bp.connect(g); n.start(t); n.stop(t + dec + 0.03); out(g, 1, 0.1, bus);
  }

  /* ── Transport ──────────────────────────────────────────────────────────── */
  var playing = null;
  function stop() {
    if (!playing) return;
    var p = playing; playing = null;
    cancelAnimationFrame(p.raf); clearTimeout(p.timer);
    try { p.bus.gain.setTargetAtTime(0.0001, ctx.currentTime, 0.03); setTimeout(function () { try { p.bus.disconnect(); } catch (e) {} }, 300); } catch (e) {}
    if (p.onEnd) p.onEnd();
  }
  function play(arr, opts) {
    opts = opts || {};
    stop();
    if (!context()) return false;
    if (ctx.state === 'suspended') ctx.resume();
    tryTone();
    var bus = ctx.createGain(); bus.gain.value = 1; bus.connect(comp);
    var t0 = ctx.currentTime + 0.12;
    arr.events.forEach(function (e) { var f = V[e.inst]; if (f) { try { f(e, t0 + e.t, bus); } catch (x) {} } });
    var mel = arr.events.filter(function (e) { return e.ref != null; }), lastRef = null;
    var p = { bus: bus, onEnd: opts.onEnd, raf: 0, timer: 0 };
    playing = p;
    function tick() {
      if (playing !== p) return;
      var now = ctx.currentTime - t0, cur = null;
      for (var i = 0; i < mel.length; i++) { if (mel[i].t <= now + 0.02) cur = mel[i]; else break; }
      var ref = cur && now < cur.t + cur.dur + 0.15 ? cur.ref : null;
      if (ref !== lastRef) { lastRef = ref; if (opts.onNote) opts.onNote(ref); }
      if (opts.onTime) opts.onTime(now);
      p.raf = requestAnimationFrame(tick);
    }
    p.raf = requestAnimationFrame(tick);
    p.timer = setTimeout(function () { if (playing === p) { if (opts.onNote) opts.onNote(null); stop(); } }, (arr.length + 0.4) * 1000);
    return true;
  }
  // Render an arrangement offline: resolves to an AudioBuffer (null without
  // OfflineAudioContext). The sampled piano lives in the live context, so an
  // offline render uses the synthesised piano; reverb is off.
  function renderBuffer(arr, seconds, rate) {
    var OAC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    if (!OAC) return Promise.resolve(null);
    var sr = rate || 22050, len = Math.ceil(sr * Math.min(seconds || 6, arr.length));
    var saved = ctx, savedNoise = noiseBuf, savedComp = comp, savedVerb = verbSend, savedSampler = sampler;
    sampler = null;
    ctx = new OAC(2, len, sr); noiseBuf = null;
    comp = ctx.createDynamicsCompressor(); comp.connect(ctx.destination);
    verbSend = ctx.createGain(); verbSend.gain.value = 0; verbSend.connect(ctx.destination);
    var bus = ctx.createGain(); bus.connect(comp);
    arr.events.forEach(function (e) { if (e.t < len / sr) { var f = V[e.inst]; if (f) { try { f(e, e.t + 0.05, bus); } catch (x) {} } } });
    var restore = function () { ctx = saved; noiseBuf = savedNoise; comp = savedComp; verbSend = savedVerb; sampler = savedSampler; };
    return ctx.startRendering().then(function (buf) { restore(); return buf; }, function (e) { restore(); throw e; });
  }
  // For checks: the peak level of an offline render.
  function renderPeak(arr, seconds) {
    return renderBuffer(arr, seconds).then(function (buf) {
      if (!buf) return null;
      var peak = 0, d = buf.getChannelData(0); for (var i = 0; i < d.length; i++) peak = Math.max(peak, Math.abs(d[i]));
      return peak;
    });
  }
  window.MAM_EXPR_AUDIO = { play: play, stop: stop, isPlaying: function () { return !!playing; }, renderPeak: renderPeak, renderBuffer: renderBuffer, instruments: Object.keys(V) };
})();
