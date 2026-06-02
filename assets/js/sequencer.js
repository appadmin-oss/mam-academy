/*! MAM Academy — Beat Maker (step sequencer)  ·  window.MAM_SEQ
 * ---------------------------------------------------------------------------
 * A 16-step drum sequencer, built to the studio's "Enterprise Web-Audio"
 * architecture: a Tone.js audio kernel, a Zustand state engine, and a Canvas
 * view — with graceful, dependency-free fallbacks so nothing ever hard-fails.
 *
 *   VIEW            STATE ENGINE            AUDIO KERNEL
 *   Canvas grid  ◄─► Zustand store    ◄─►   Tone.js Transport + Channels
 *   (DPR-aware)      (immutable)            (look-ahead clock, AudioWorklet
 *                                            context, built-in swing/solo)
 *
 *  • AUDIO KERNEL — Tone.js drives the clock (Tone.Transport, a sample-accurate
 *    look-ahead scheduler running on an AudioWorklet-backed context), the swing
 *    (Tone.Transport.swing), the per-track mixer (Tone.Channel: volume / mute /
 *    a shared solo bus), and the visual sync (Tone.Draw fires the playhead in
 *    lockstep with the audio events). If Tone is unavailable (CDN blocked) or
 *    its path ever throws, we transparently fall back to a hand-rolled
 *    Web-Audio look-ahead scheduler — the groove keeps playing either way.
 *
 *  • STATE ENGINE — a single Zustand (vanilla) store holds the whole sequencer
 *    state immutably (bpm, swing, master, pattern, per-track mix, playhead).
 *    The view and the kernel both subscribe to it, so the GUI and the audio
 *    clock can never drift out of sync. Zustand loads from a CDN with an
 *    API-identical built-in fallback, so a blocked CDN can't break it.
 *
 *  • WARM BUFFER CACHE — every voice (kick/snare/clap/hats/tom/rim/shaker) is
 *    pre-rendered ONCE into an AudioBuffer via OfflineAudioContext, so triggers
 *    are warm buffer playbacks rather than per-hit oscillator graphs. The
 *    voices are synthesised, so there are no sample files to host or decode —
 *    instant load, fully offline, zero server resources. Until a buffer is
 *    warm, triggers fall back to live synthesis, so the first bar is never
 *    silent.
 *
 *  • VIEW — the grid is one <canvas> (DPR-aware) rather than ~128 DOM cells,
 *    so toggling cells and animating the playhead never trigger layout reflow.
 *
 *  • "Export WAV" renders the pattern through an OfflineAudioContext and
 *    encodes a 16-bit PCM WAV on the client — nothing is uploaded. The working
 *    groove auto-saves to localStorage and restores on return.
 */
(function () {
  'use strict';

  var panel = document.querySelector('.tool-panel[data-tool="seq"]');
  if (!panel) return; // page doesn't include the Beat Maker

  var $ = function (s) { return panel.querySelector(s); };
  var $$ = function (s) { return Array.prototype.slice.call(panel.querySelectorAll(s)); };

  // ── Constants ──────────────────────────────────────────────────────
  var STEPS = 16;
  var ROW = 46;                 // px height of one track row (matches CSS)
  var SAVE_KEY = 'mam.seq.v1';
  var ZUSTAND_CDN = 'https://cdn.jsdelivr.net/npm/zustand@5.0.3/vanilla/+esm';

  // Eight synth voices, ordered top-to-bottom. `vol` is the default mix level.
  var TRACKS = [
    { name: 'Kick',       voice: 'kick',      vol: 0.95 },
    { name: 'Snare',      voice: 'snare',     vol: 0.80 },
    { name: 'Clap',       voice: 'clap',      vol: 0.70 },
    { name: 'Closed Hat', voice: 'hatClosed', vol: 0.55 },
    { name: 'Open Hat',   voice: 'hatOpen',   vol: 0.50 },
    { name: 'Low Tom',    voice: 'tom',       vol: 0.70 },
    { name: 'Rim',        voice: 'rim',       vol: 0.62 },
    { name: 'Shaker',     voice: 'shaker',    vol: 0.50 },
  ];

  // ── Shared AudioContext (autoplay-policy safe) ─────────────────────
  // Borrow Tone's context when present so we share one AudioWorklet timeline
  // with the rest of the studio; otherwise the shared MAM_TOOLS context, or a
  // fresh one as a last resort.
  var ctx = null;
  function audio() {
    // Prefer the shared studio context so every tool mixes through one engine.
    if (window.MAM && typeof window.MAM.audio === 'function') { ctx = window.MAM.audio(); return ctx; }
    if (window.Tone && Tone.context) {
      ctx = Tone.context.rawContext || ctx;
      if (Tone.context.state === 'suspended') { try { Tone.start(); } catch (e) {} }
    } else if (window.MAM_TOOLS && typeof window.MAM_TOOLS.audio === 'function') {
      ctx = window.MAM_TOOLS.audio();
    }
    if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  }

  // ── Synthesised drum voices (used to bake buffers + as live fallback) ──
  function noiseBuf(ac) {
    if (ac.__mamNoise) return ac.__mamNoise;
    var len = Math.floor(ac.sampleRate * 1.2);
    var b = ac.createBuffer(1, len, ac.sampleRate);
    var d = b.getChannelData(0);
    for (var i = 0; i < len; i++) d[i] = Math.random() * 2 - 1;
    ac.__mamNoise = b;
    return b;
  }
  function env(g, when, peak, decay, hold) {
    g.gain.setValueAtTime(0.0001, when);
    g.gain.exponentialRampToValueAtTime(peak, when + (hold || 0.004));
    g.gain.exponentialRampToValueAtTime(0.0001, when + decay);
  }
  var voices = {
    kick: function (ac, out, when) {
      var o = ac.createOscillator(), g = ac.createGain();
      o.type = 'sine';
      o.frequency.setValueAtTime(160, when);
      o.frequency.exponentialRampToValueAtTime(50, when + 0.11);
      env(g, when, 0.95, 0.40, 0.005);
      o.connect(g).connect(out); o.start(when); o.stop(when + 0.42);
    },
    snare: function (ac, out, when) {
      var s = ac.createBufferSource(); s.buffer = noiseBuf(ac);
      var hp = ac.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 1200;
      var ng = ac.createGain(); env(ng, when, 0.65, 0.18);
      s.connect(hp).connect(ng).connect(out); s.start(when); s.stop(when + 0.2);
      var o = ac.createOscillator(), og = ac.createGain();
      o.type = 'triangle'; o.frequency.value = 180;
      env(og, when, 0.4, 0.1);
      o.connect(og).connect(out); o.start(when); o.stop(when + 0.12);
    },
    clap: function (ac, out, when) {
      var bp = ac.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = 1200; bp.Q.value = 0.9;
      bp.connect(out);
      [0, 0.011, 0.022, 0.04].forEach(function (k, i) {
        var s = ac.createBufferSource(); s.buffer = noiseBuf(ac);
        var g = ac.createGain();
        var peak = i === 3 ? 0.55 : 0.35;
        g.gain.setValueAtTime(0.0001, when + k);
        g.gain.exponentialRampToValueAtTime(peak, when + k + 0.002);
        g.gain.exponentialRampToValueAtTime(0.0001, when + k + (i === 3 ? 0.12 : 0.03));
        s.connect(g).connect(bp); s.start(when + k); s.stop(when + k + 0.14);
      });
    },
    hatClosed: function (ac, out, when) {
      var s = ac.createBufferSource(); s.buffer = noiseBuf(ac);
      var hp = ac.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 7500;
      var g = ac.createGain(); env(g, when, 0.4, 0.045, 0.002);
      s.connect(hp).connect(g).connect(out); s.start(when); s.stop(when + 0.06);
    },
    hatOpen: function (ac, out, when) {
      var s = ac.createBufferSource(); s.buffer = noiseBuf(ac);
      var hp = ac.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 7000;
      var g = ac.createGain(); env(g, when, 0.4, 0.32, 0.002);
      s.connect(hp).connect(g).connect(out); s.start(when); s.stop(when + 0.34);
    },
    tom: function (ac, out, when) {
      var o = ac.createOscillator(), g = ac.createGain();
      o.type = 'sine';
      o.frequency.setValueAtTime(170, when);
      o.frequency.exponentialRampToValueAtTime(90, when + 0.18);
      env(g, when, 0.6, 0.3, 0.005);
      o.connect(g).connect(out); o.start(when); o.stop(when + 0.32);
    },
    rim: function (ac, out, when) {
      var o = ac.createOscillator(), g = ac.createGain();
      o.type = 'square'; o.frequency.value = 1700;
      env(g, when, 0.42, 0.03, 0.002);
      o.connect(g).connect(out); o.start(when); o.stop(when + 0.04);
      var s = ac.createBufferSource(); s.buffer = noiseBuf(ac);
      var hp = ac.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 3000;
      var ng = ac.createGain(); env(ng, when, 0.25, 0.02, 0.001);
      s.connect(hp).connect(ng).connect(out); s.start(when); s.stop(when + 0.03);
    },
    shaker: function (ac, out, when) {
      var s = ac.createBufferSource(); s.buffer = noiseBuf(ac);
      var hp = ac.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 6500;
      var g = ac.createGain();
      g.gain.setValueAtTime(0.0001, when);
      g.gain.exponentialRampToValueAtTime(0.32, when + 0.012);
      g.gain.exponentialRampToValueAtTime(0.0001, when + 0.06);
      s.connect(hp).connect(g).connect(out); s.start(when); s.stop(when + 0.07);
    },
  };

  // ── Warm buffer cache ──────────────────────────────────────────────
  // Pre-render each voice once into a mono AudioBuffer so triggering is a warm
  // buffer playback. Best-effort: if it hasn't finished baking, triggers fall
  // back to live synthesis so the groove is never silent.
  var buffers = {};
  var baking = false;
  function ensureBuffers(sampleRate) {
    if (baking || Object.keys(buffers).length) return;
    var OAC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    if (!OAC) return;
    baking = true;
    var names = {}; TRACKS.forEach(function (t) { names[t.voice] = 1; });
    Object.keys(names).forEach(function (v) {
      try {
        var dur = 0.8;
        var off = new OAC(1, Math.ceil(sampleRate * dur), sampleRate);
        voices[v](off, off.destination, 0);
        off.startRendering().then(function (buf) { buffers[v] = buf; }).catch(function () {});
      } catch (e) {}
    });
  }

  // ── State engine: Zustand store (with API-identical fallback) ──────
  function nativeCreateStore(initializer) {
    var state, listeners = [];
    function setState(partial, replace) {
      var next = typeof partial === 'function' ? partial(state) : partial;
      if (next == null) return;
      var prev = state;
      state = replace ? next : Object.assign({}, state, next);
      for (var i = 0; i < listeners.length; i++) listeners[i](state, prev);
    }
    function getState() { return state; }
    function subscribe(l) { listeners.push(l); return function () { var k = listeners.indexOf(l); if (k >= 0) listeners.splice(k, 1); }; }
    var api = { setState: setState, getState: getState, subscribe: subscribe, getInitialState: function () { return state; } };
    state = initializer(setState, getState, api);
    return api;
  }
  function loadCreateStore() {
    if (window.MAM_ZUSTAND) return Promise.resolve(window.MAM_ZUSTAND);
    try {
      var timeout = new Promise(function (_, rej) { setTimeout(function () { rej(new Error('timeout')); }, 2500); });
      return Promise.race([import(ZUSTAND_CDN), timeout])
        .then(function (mod) { return (mod && mod.createStore) ? mod.createStore : nativeCreateStore; })
        .catch(function () { return nativeCreateStore; });
    } catch (e) {
      return Promise.resolve(nativeCreateStore);
    }
  }

  // ── Pattern helpers + presets ──────────────────────────────────────
  function blankPattern() { return TRACKS.map(function () { return new Array(STEPS).fill(0); }); }
  function P(str) {
    var a = new Array(STEPS).fill(0);
    for (var i = 0; i < STEPS && i < str.length; i++) a[i] = str[i] === 'x' ? 1 : 0;
    return a;
  }
  var PRESETS = {
    'Four on the floor': { kick: 'x...x...x...x...', clap: '....x.......x...', hatClosed: 'x.x.x.x.x.x.x.x.', hatOpen: '..x...x...x...x.' },
    'Boom bap':          { kick: 'x.....x..x......', snare: '....x.......x...', hatClosed: 'x.x.x.x.x.x.x.x.' },
    'Afrobeat':          { kick: 'x..x..x...x..x..', rim: '..x..x..x..x..x.', clap: '....x.......x...', hatClosed: 'x.xx.x.xx.x.xx.x', shaker: 'x.x.x.x.x.x.x.x.' },
    'Highlife':          { kick: 'x...x..x.x..x...', rim: '..x.x..x..x.x..x', hatClosed: 'x.x.x.x.x.x.x.x.', shaker: '..x...x...x...x.' },
    'One drop':          { kick: '........x.......', snare: '........x.......', rim: '..x...x...x...x.', hatClosed: 'x.x.x.x.x.x.x.x.' },
  };
  function voiceIndex(v) { for (var i = 0; i < TRACKS.length; i++) if (TRACKS[i].voice === v) return i; return -1; }
  function patternFromPreset(name) {
    var p = PRESETS[name]; var pat = blankPattern();
    if (p) Object.keys(p).forEach(function (voice) { var idx = voiceIndex(voice); if (idx >= 0) pat[idx] = P(p[voice]); });
    return pat;
  }

  // ── Persistence ────────────────────────────────────────────────────
  function defaultState() {
    return {
      bpm: 100, swing: 0, master: 0.85, playing: false, step: -1, engine: '—',
      pattern: patternFromPreset('Afrobeat'),
      tracks: TRACKS.map(function (t) { return { vol: t.vol, mute: false, solo: false }; }),
    };
  }
  function loadSaved() {
    var raw; try { raw = localStorage.getItem(SAVE_KEY); } catch (e) { return null; }
    if (!raw) return null;
    try {
      var d = JSON.parse(raw), st = defaultState();
      if (typeof d.bpm === 'number') st.bpm = d.bpm;
      if (typeof d.swing === 'number') st.swing = d.swing;
      if (typeof d.master === 'number') st.master = d.master;
      if (Array.isArray(d.pattern)) {
        st.pattern = blankPattern();
        d.pattern.forEach(function (rowArr, i) { if (st.pattern[i] && Array.isArray(rowArr)) for (var s = 0; s < STEPS; s++) st.pattern[i][s] = rowArr[s] ? 1 : 0; });
      }
      if (Array.isArray(d.tracks)) d.tracks.forEach(function (s, i) {
        if (!st.tracks[i] || !s) return;
        if (typeof s.vol === 'number') st.tracks[i].vol = s.vol;
        st.tracks[i].mute = !!s.mute; st.tracks[i].solo = !!s.solo;
      });
      return st;
    } catch (e) { return null; }
  }
  function save(s) {
    try {
      localStorage.setItem(SAVE_KEY, JSON.stringify({ bpm: s.bpm, swing: s.swing, master: s.master, pattern: s.pattern, tracks: s.tracks }));
    } catch (e) {}
  }

  // ── WAV export (offline render of the synth → 16-bit PCM) ──────────
  function encodeWav(audioBuffer) {
    var numCh = Math.min(2, audioBuffer.numberOfChannels);
    var len = audioBuffer.length, sr = audioBuffer.sampleRate;
    var blockAlign = numCh * 2, dataLen = len * blockAlign;
    var ab = new ArrayBuffer(44 + dataLen), view = new DataView(ab);
    function str(off, s) { for (var i = 0; i < s.length; i++) view.setUint8(off + i, s.charCodeAt(i)); }
    str(0, 'RIFF'); view.setUint32(4, 36 + dataLen, true); str(8, 'WAVE');
    str(12, 'fmt '); view.setUint32(16, 16, true); view.setUint16(20, 1, true);
    view.setUint16(22, numCh, true); view.setUint32(24, sr, true);
    view.setUint32(28, sr * blockAlign, true); view.setUint16(32, blockAlign, true); view.setUint16(34, 16, true);
    str(36, 'data'); view.setUint32(40, dataLen, true);
    var chans = []; for (var ch = 0; ch < numCh; ch++) chans.push(audioBuffer.getChannelData(ch));
    var offset = 44;
    for (var i = 0; i < len; i++) for (var c2 = 0; c2 < numCh; c2++) {
      var v = Math.max(-1, Math.min(1, chans[c2][i]));
      view.setInt16(offset, v < 0 ? v * 0x8000 : v * 0x7FFF, true); offset += 2;
    }
    return view;
  }
  function exportWav() {
    var st = store.getState();
    var bars = 2, sd = 60 / st.bpm / 4, totalSteps = bars * STEPS, tail = 0.6;
    var sr = (ctx && ctx.sampleRate) || audio().sampleRate || 44100;
    var OAC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    if (!OAC) { status('Your browser can’t render WAV files offline — try the latest Chrome, Edge or Safari.'); return; }
    status('Rendering your beat…');
    var off = new OAC(2, Math.ceil((totalSteps * sd + tail) * sr), sr);
    var master = off.createGain(); master.gain.value = st.master; master.connect(off.destination);
    var anySolo = st.tracks.some(function (t) { return t.solo; });
    TRACKS.forEach(function (t, i) {
      var aud = anySolo ? !!st.tracks[i].solo : !st.tracks[i].mute;
      if (!aud) return;
      var tg = off.createGain(); tg.gain.value = st.tracks[i].vol; tg.connect(master);
      for (var b = 0; b < bars; b++) for (var s = 0; s < STEPS; s++) {
        if (!st.pattern[i][s]) continue;
        var gridT = (b * STEPS + s) * sd;
        var when = gridT + (s % 2 ? st.swing * sd : 0);
        voices[t.voice](off, tg, when);
      }
    });
    off.startRendering().then(function (buf) {
      var blob = new Blob([encodeWav(buf)], { type: 'audio/wav' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url; a.download = 'mam-beat-' + st.bpm + 'bpm.wav';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
      status('Saved “mam-beat-' + st.bpm + 'bpm.wav” — a 2-bar loop, straight from your browser.');
    }).catch(function (e) { status('Couldn’t render the file: ' + (e && e.message || e)); });
  }

  // ── Audio kernels ──────────────────────────────────────────────────
  function trackAudible(i, st) {
    var anySolo = st.tracks.some(function (t) { return t.solo; });
    return anySolo ? !!st.tracks[i].solo : !st.tracks[i].mute;
  }

  // Raw Web-Audio kernel — hand-rolled look-ahead scheduler. The proven,
  // dependency-free path; also the fallback when Tone is unavailable.
  function createRawKernel() {
    var ac, masterGain, tgains = [], timer = null, nextT = 0, stp = 0;
    function build() {
      ac = audio(); ensureBuffers(ac.sampleRate);
      if (masterGain && masterGain.context === ac) return;
      masterGain = ac.createGain(); masterGain.gain.value = store.getState().master; masterGain.connect(ac.destination);
      tgains = TRACKS.map(function (t, i) { var g = ac.createGain(); g.gain.value = store.getState().tracks[i].vol; g.connect(masterGain); return g; });
    }
    function trig(i, when) {
      var v = TRACKS[i].voice;
      if (buffers[v]) { var s = ac.createBufferSource(); s.buffer = buffers[v]; s.connect(tgains[i]); s.start(when); }
      else voices[v](ac, tgains[i], when);
    }
    function sched() {
      var st = store.getState(), sd = 60 / st.bpm / 4;
      while (nextT < ac.currentTime + 0.12) {
        var s = stp % STEPS, swung = nextT + (s % 2 ? st.swing * sd : 0);
        for (var i = 0; i < TRACKS.length; i++) if (st.pattern[i][s] && trackAudible(i, st)) trig(i, swung);
        (function (ss, t) { var d = Math.max(0, (t - ac.currentTime) * 1000); setTimeout(function () { if (store.getState().playing) store.setState({ step: ss }); }, d); })(s, nextT);
        nextT += sd; stp++;
      }
    }
    return {
      name: 'raw',
      start: function () { build(); stp = 0; nextT = ac.currentTime + 0.08; timer = setInterval(sched, 25); },
      stop: function () { if (timer) clearInterval(timer); timer = null; },
      setBpm: function () {}, setSwing: function () {},  // read live from the store each tick
      setMaster: function (v) { if (masterGain) masterGain.gain.value = v; },
      syncTrack: function (i) { if (tgains[i]) tgains[i].gain.value = store.getState().tracks[i].vol; }, // mute/solo via trackAudible()
      audition: function (i) { build(); trig(i, ac.currentTime + 0.01); },
      dispose: function () { this.stop(); },
    };
  }

  // Tone.js kernel — Tone.Transport clock, Tone.Channel mixer (volume / mute /
  // solo bus), Tone.Transport.swing, Tone.Draw visual sync. Higher-quality
  // timing on an AudioWorklet-backed context.
  function createToneKernel() {
    var channels = [], players = [], loopId = null, stp = 0, ready = false;
    function build() {
      audio(); ensureBuffers((Tone.context && Tone.context.sampleRate) || 44100);
      if (ready) return;
      var st = store.getState();
      channels = TRACKS.map(function (t, i) {
        return new Tone.Channel({ volume: Tone.gainToDb(Math.max(0.0001, st.tracks[i].vol)), mute: !!st.tracks[i].mute, solo: !!st.tracks[i].solo }).toDestination();
      });
      players = TRACKS.map(function (t, i) {
        var p = buffers[t.voice] ? new Tone.Player(buffers[t.voice]) : new Tone.Player();
        p.connect(channels[i]); return p;
      });
      Tone.getDestination().volume.value = Tone.gainToDb(Math.max(0.0001, st.master));
      ready = true;
    }
    function trig(i, time) {
      var p = players[i];
      if (p && p.loaded !== false && p.buffer && p.buffer.loaded !== false) { try { p.start(time); return; } catch (e) {} }
      // buffer not warm yet → live synth into the channel's native input
      var dest = channels[i] && channels[i].input ? channels[i].input : Tone.getDestination();
      voices[TRACKS[i].voice](Tone.context.rawContext || audio(), dest, time != null ? time : (Tone.now()));
    }
    return {
      name: 'tone',
      start: function () {
        build();
        var st = store.getState();
        Tone.Transport.bpm.value = st.bpm;
        Tone.Transport.swing = st.swing;
        Tone.Transport.swingSubdivision = '16n';
        stp = 0;
        loopId = Tone.Transport.scheduleRepeat(function (time) {
          var s = stp % STEPS, cur = store.getState();
          for (var i = 0; i < TRACKS.length; i++) if (cur.pattern[i][s] && trackAudible(i, cur)) trig(i, time);
          Tone.Draw.schedule(function () { if (store.getState().playing) store.setState({ step: s }); }, time);
          stp++;
        }, '16n');
        Tone.start(); Tone.Transport.start();
      },
      stop: function () { if (loopId != null) { try { Tone.Transport.clear(loopId); } catch (e) {} loopId = null; } try { Tone.Transport.stop(); } catch (e) {} },
      setBpm: function (v) { try { Tone.Transport.bpm.value = v; } catch (e) {} },
      setSwing: function (v) { try { Tone.Transport.swing = v; } catch (e) {} },
      setMaster: function (v) { try { Tone.getDestination().volume.value = Tone.gainToDb(Math.max(0.0001, v)); } catch (e) {} },
      syncTrack: function (i) {
        if (!ready) return;
        var t = store.getState().tracks[i];
        try { channels[i].volume.value = Tone.gainToDb(Math.max(0.0001, t.vol)); channels[i].mute = !!t.mute; channels[i].solo = !!t.solo; } catch (e) {}
      },
      audition: function (i) { build(); trig(i); },
      dispose: function () {
        this.stop();
        try { players.forEach(function (p) { p.dispose(); }); channels.forEach(function (c) { c.dispose(); }); } catch (e) {}
        players = []; channels = []; ready = false;
      },
    };
  }

  var kernel = null;
  function wantKernel() { return window.Tone ? 'tone' : 'raw'; }
  function ensureKernel() {
    if (!kernel) kernel = wantKernel() === 'tone' ? createToneKernel() : createRawKernel();
    return kernel;
  }

  // ── Transport actions ──────────────────────────────────────────────
  function play() {
    if (store.getState().playing) return;
    var want = wantKernel();
    if (kernel && kernel.name !== want) { try { kernel.dispose(); } catch (e) {} kernel = null; } // upgrade to Tone once it's loaded
    ensureKernel();
    store.setState({ playing: true, engine: kernel.name });
    try {
      kernel.start();
      status(kernel.name === 'tone' ? 'Playing — Tone.js kernel (look-ahead Transport on an AudioWorklet timeline).' : 'Playing — Web Audio kernel.');
    } catch (e) {
      // Any failure in the Tone path silently falls back to the raw kernel.
      if (kernel.name === 'tone') {
        try { kernel.dispose(); } catch (e2) {}
        kernel = createRawKernel();
        store.setState({ engine: 'raw' });
        try { kernel.start(); status('Playing — Web Audio kernel (Tone unavailable).'); }
        catch (e3) { store.setState({ playing: false, step: -1 }); status('Couldn’t start audio in this browser.'); }
      } else {
        store.setState({ playing: false, step: -1 });
        status('Couldn’t start audio: ' + (e && e.message || e));
      }
    }
  }
  function stop() {
    if (kernel) { try { kernel.stop(); } catch (e) {} }
    store.setState({ playing: false, step: -1 });
  }

  // ── Canvas view ────────────────────────────────────────────────────
  var cvs = $('#seqCanvas'), c = cvs.getContext('2d'), wrap = $('#seqCanvasWrap');
  var cssW = 0, cssH = TRACKS.length * ROW, cellW = 0;

  function roundRect(g, x, y, w, h, r) {
    if (g.roundRect) { g.beginPath(); g.roundRect(x, y, w, h, r); return; }
    g.beginPath(); g.moveTo(x + r, y);
    g.arcTo(x + w, y, x + w, y + h, r); g.arcTo(x + w, y + h, x, y + h, r);
    g.arcTo(x, y + h, x, y, r); g.arcTo(x, y, x + w, y, r); g.closePath();
  }
  function layout() {
    var wrapW = wrap.clientWidth || 0;
    if (!wrapW) return;
    var minW = STEPS * 30;
    cssW = Math.max(minW, wrapW); cssH = TRACKS.length * ROW;
    var dpr = window.devicePixelRatio || 1;
    cvs.style.width = cssW + 'px'; cvs.style.height = cssH + 'px';
    cvs.width = Math.round(cssW * dpr); cvs.height = Math.round(cssH * dpr);
    c.setTransform(dpr, 0, 0, dpr, 0, 0);
    cellW = cssW / STEPS;
    draw();
  }
  function draw() {
    if (!cellW) return;
    var st = store.getState();
    c.clearRect(0, 0, cssW, cssH);
    for (var t = 0; t < TRACKS.length; t++) {
      var y = t * ROW;
      for (var s = 0; s < STEPS; s++) {
        var x = s * cellW, group = Math.floor(s / 4);
        var on = st.pattern[t][s], isCur = st.playing && s === st.step;
        roundRect(c, x + 3, y + 3, cellW - 6, ROW - 6, 6);
        if (on) {
          c.fillStyle = isCur ? '#FFD56B' : (group % 2 ? '#E89A2C' : '#F0A93A'); c.fill();
          roundRect(c, x + 4, y + 4, cellW - 8, (ROW - 8) * 0.42, 5);
          c.fillStyle = 'rgba(255,255,255,0.22)'; c.fill();
        } else {
          c.fillStyle = isCur ? '#33271699' : (group % 2 ? '#1b140d' : '#221910'); c.fill();
          c.strokeStyle = 'rgba(255,255,255,0.05)'; c.lineWidth = 1; c.stroke();
        }
      }
    }
    c.strokeStyle = 'rgba(255,255,255,0.12)'; c.lineWidth = 1;
    for (var g = 1; g < STEPS / 4; g++) { var gx = Math.round(g * 4 * cellW) + 0.5; c.beginPath(); c.moveTo(gx, 0); c.lineTo(gx, cssH); c.stroke(); }
  }

  // Tap toggles a cell; a horizontal drag scrolls the grid on narrow screens
  // (canvas uses touch-action:pan-x). Toggle on pointerup only if barely moved.
  var down = null;
  cvs.addEventListener('pointerdown', function (e) { down = { x: e.clientX, y: e.clientY }; });
  cvs.addEventListener('pointercancel', function () { down = null; });
  cvs.addEventListener('pointerup', function (e) {
    if (!down) return;
    var moved = Math.abs(e.clientX - down.x) + Math.abs(e.clientY - down.y); down = null;
    if (moved > 10) return;
    var r = cvs.getBoundingClientRect();
    var s = Math.floor((e.clientX - r.left) / cellW), t = Math.floor((e.clientY - r.top) / ROW);
    if (s < 0 || s >= STEPS || t < 0 || t >= TRACKS.length) return;
    var pat = store.getState().pattern.map(function (row) { return row.slice(); });
    pat[t][s] = pat[t][s] ? 0 : 1;
    store.setState({ pattern: pat });
    if (pat[t][s]) { try { ensureKernel().audition(t); } catch (err) {} } // audition on enable
  });

  // ── Track controls (mute / solo / per-track volume) ────────────────
  function buildTracks() {
    var host = $('#seqTracks'); host.innerHTML = '';
    TRACKS.forEach(function (t, i) {
      var row = document.createElement('div');
      row.className = 'seq-track-row';
      row.innerHTML =
        '<span class="seq-track-name">' + t.name + '</span>' +
        '<button type="button" class="seq-chip seq-mute" data-i="' + i + '" title="Mute" aria-pressed="false">M</button>' +
        '<button type="button" class="seq-chip seq-solo" data-i="' + i + '" title="Solo" aria-pressed="false">S</button>' +
        '<input type="range" class="seq-tvol" data-i="' + i + '" min="0" max="100" value="' + Math.round(t.vol * 100) + '" title="' + t.name + ' volume" aria-label="' + t.name + ' volume">';
      host.appendChild(row);
    });
    host.addEventListener('click', function (e) {
      var b = e.target.closest('.seq-chip'); if (!b) return;
      var i = +b.getAttribute('data-i');
      var tracks = store.getState().tracks.map(function (x) { return Object.assign({}, x); });
      if (b.classList.contains('seq-mute')) tracks[i].mute = !tracks[i].mute;
      else tracks[i].solo = !tracks[i].solo;
      store.setState({ tracks: tracks });
    });
    host.addEventListener('input', function (e) {
      var sl = e.target.closest('.seq-tvol'); if (!sl) return;
      var i = +sl.getAttribute('data-i');
      var tracks = store.getState().tracks.map(function (x) { return Object.assign({}, x); });
      tracks[i].vol = (+sl.value) / 100;
      store.setState({ tracks: tracks });
    });
  }

  // ── Controls ↔ store sync (view side of the reactive loop) ─────────
  function syncControls(s, prev) {
    if (!prev || s.bpm !== prev.bpm) { $('#seqBpm').value = s.bpm; $('#seqBpmVal').textContent = s.bpm; }
    if (!prev || s.swing !== prev.swing) { $('#seqSwing').value = Math.round(s.swing * 100); $('#seqSwingVal').textContent = Math.round(s.swing * 100) + '%'; }
    if (!prev || s.master !== prev.master) { $('#seqMaster').value = Math.round(s.master * 100); $('#seqMasterVal').textContent = Math.round(s.master * 100) + '%'; }
    if (!prev || s.tracks !== prev.tracks) {
      $$('.seq-mute').forEach(function (b) { var on = !!s.tracks[+b.getAttribute('data-i')].mute; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      $$('.seq-solo').forEach(function (b) { var on = !!s.tracks[+b.getAttribute('data-i')].solo; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      $$('.seq-tvol').forEach(function (sl) { sl.value = Math.round(s.tracks[+sl.getAttribute('data-i')].vol * 100); });
    }
    if (!prev || s.playing !== prev.playing) {
      var btn = $('#seqPlay');
      btn.classList.toggle('playing', s.playing);
      btn.querySelector('span').textContent = s.playing ? 'Stop' : 'Play';
      btn.querySelector('i').className = s.playing ? 'fa-solid fa-stop' : 'fa-solid fa-play';
    }
  }
  function status(msg) { var el = $('#seqStatus'); if (el) el.textContent = msg; }

  // ── Input wiring ───────────────────────────────────────────────────
  function wireInputs() {
    $('#seqPlay').addEventListener('click', function () { store.getState().playing ? stop() : play(); });
    $('#seqBpm').addEventListener('input', function (e) {
      var v = parseInt(e.target.value, 10) || 100;
      store.setState({ bpm: v });
      if (window.MAM && window.MAM.tempo) window.MAM.tempo.set(v); // share tempo with the Metronome
    });
    $('#seqSwing').addEventListener('input', function (e) { store.setState({ swing: (parseInt(e.target.value, 10) || 0) / 100 }); });
    $('#seqMaster').addEventListener('input', function (e) { store.setState({ master: (parseInt(e.target.value, 10) || 0) / 100 }); });
    $('#seqClear').addEventListener('click', function () { store.setState({ pattern: blankPattern() }); status('Cleared. A blank canvas — start tapping cells.'); });
    $('#seqRandom').addEventListener('click', function () {
      var prob = { kick: 0.28, snare: 0.12, clap: 0.12, hatClosed: 0.55, hatOpen: 0.12, tom: 0.1, rim: 0.18, shaker: 0.4 };
      var pat = blankPattern();
      TRACKS.forEach(function (t, i) { var p = prob[t.voice] || 0.2; for (var s = 0; s < STEPS; s++) pat[i][s] = Math.random() < p ? 1 : 0; });
      var ki = voiceIndex('kick'); if (ki >= 0) pat[ki][0] = 1;
      store.setState({ pattern: pat }); status('Rolled a fresh groove. Not feeling it? Roll again.');
    });
    $('#seqPresets').addEventListener('change', function (e) {
      if (!e.target.value) return;
      var name = e.target.value; e.target.value = '';
      store.setState({ pattern: patternFromPreset(name) });
      status('Loaded the “' + name + '” groove. Hit play, then make it yours.');
    });
    $('#seqExport').addEventListener('click', exportWav);

    // Tab integration: stop on leaving, (re)lay out when shown.
    Array.prototype.slice.call(document.querySelectorAll('.tools-tabs button')).forEach(function (b) {
      b.addEventListener('click', function () {
        var key = b.getAttribute('data-tool');
        if (key !== 'seq') { if (store.getState().playing) stop(); }
        else setTimeout(layout, 30);
      });
    });
    // Spacebar toggles play while the Beat Maker is the active panel.
    document.addEventListener('keydown', function (e) {
      if (e.code !== 'Space') return;
      var active = document.querySelector('.tool-panel.active');
      if (!active || active.getAttribute('data-tool') !== 'seq') return;
      var tag = document.activeElement && document.activeElement.tagName;
      if (tag === 'INPUT' || tag === 'SELECT' || tag === 'BUTTON') return;
      e.preventDefault();
      store.getState().playing ? stop() : play();
    });
    window.addEventListener('resize', function () { if (panel.classList.contains('active') || wrap.clientWidth) layout(); });
    // Once Tone finishes loading, note that the upgraded kernel is available.
    window.addEventListener('mam:tone-ready', function () { if (!store.getState().playing) status('Tone.js kernel ready — press play for the upgraded engine.'); });
  }

  // ── The reactive loop ──────────────────────────────────────────────
  // One subscriber keeps the audio kernel's parameters and the canvas/controls
  // in lockstep with the immutable store — the GUI and the clock can't drift.
  function onState(s, prev) {
    if (kernel) {
      if (!prev || s.bpm !== prev.bpm) kernel.setBpm(s.bpm);
      if (!prev || s.swing !== prev.swing) kernel.setSwing(s.swing);
      if (!prev || s.master !== prev.master) kernel.setMaster(s.master);
      if (!prev || s.tracks !== prev.tracks) for (var i = 0; i < TRACKS.length; i++) kernel.syncTrack(i);
    }
    draw();
    syncControls(s, prev);
    if (!prev || s.bpm !== prev.bpm || s.swing !== prev.swing || s.master !== prev.master || s.pattern !== prev.pattern || s.tracks !== prev.tracks) save(s);
  }

  // ── Init (async: resolve the Zustand store, then wire everything) ──
  var store = null;
  loadCreateStore().then(function (createStore) {
    var initial = loadSaved();
    store = createStore(function () { return initial || defaultState(); });
    buildTracks();
    wireInputs();
    store.subscribe(onState);
    onState(store.getState(), null); // initial paint
    layout();
    if (!wrap.clientWidth && 'ResizeObserver' in window) {
      var ro = new ResizeObserver(function () { if (wrap.clientWidth) { layout(); ro.disconnect(); } });
      ro.observe(wrap);
    }
    if (!initial) status('Loaded a starter Afrobeat groove. Press play, then make it yours.');

    // Share one tempo across the studio: adopt the bus value, and follow it.
    if (window.MAM && window.MAM.tempo) {
      store.setState({ bpm: window.MAM.tempo.get() });
      window.MAM.tempo.subscribe(function (v) { if (v !== store.getState().bpm) store.setState({ bpm: v }); });
    }

    window.MAM_SEQ = {
      play: play, stop: stop, exportWav: exportWav,
      store: store,
      state: function () { return store.getState(); },
      kernel: function () { return kernel && kernel.name; },
    };
    window.dispatchEvent(new CustomEvent('mam:seq-ready'));
  });
})();
