/*! MAM Academy — Studio Core (window.MAM)
 * ---------------------------------------------------------------------------
 * Shared infrastructure for every Practice Studio tool (metronome, tuner,
 * ear-trainer, speed/pitch, beat maker). Extracted so the tools share one
 * audio engine and stay in sync instead of each spinning up their own.
 *
 *   • audio()       one autoplay-policy-safe, Tone.js-aware AudioContext, so
 *                   every tool mixes through the same (AudioWorklet-backed)
 *                   output and there's never a second, competing context.
 *   • tempo         a shared BPM bus — set the tempo in the Metronome and the
 *                   Beat Maker follows, and vice-versa. Persisted per device.
 *   • createStore() a tiny Zustand-compatible immutable store (getState /
 *                   setState / subscribe) for tools that want reactive state.
 *   • bake/buffer/trigger   a warm one-shot buffer cache: pre-render a sound
 *                   once via OfflineAudioContext, then trigger it as a cheap
 *                   buffer playback (falling back to live synthesis until the
 *                   buffer is warm). No sample files to host or decode.
 *   • encodeWav()   client-side 16-bit PCM WAV encoder for offline renders.
 */
(function () {
  'use strict';

  // ── Shared AudioContext ────────────────────────────────────────────
  var ctx = null;
  function audio() {
    if (window.Tone && Tone.context) {
      ctx = Tone.context.rawContext || ctx;
      if (Tone.context.state === 'suspended') { try { Tone.start(); } catch (e) {} }
    }
    if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  }
  function hasTone() { return typeof window.Tone !== 'undefined'; }
  function hasPitchy() { return !!window.MAM_PITCHY; }

  // ── Immutable store (Zustand-compatible) ───────────────────────────
  function createStore(initializer) {
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

  // ── Shared tempo bus ───────────────────────────────────────────────
  var TEMPO_KEY = 'mam.studio.bpm';
  function clampBpm(v) { v = Math.round(+v || 100); return Math.max(30, Math.min(300, v)); }
  var tempoVal = (function () { try { var r = localStorage.getItem(TEMPO_KEY); return r ? clampBpm(JSON.parse(r)) : 100; } catch (e) { return 100; } })();
  var tempoSubs = [];
  var tempo = {
    get: function () { return tempoVal; },
    set: function (v) {
      v = clampBpm(v);
      if (v === tempoVal) return;
      tempoVal = v;
      try { localStorage.setItem(TEMPO_KEY, JSON.stringify(v)); } catch (e) {}
      for (var i = 0; i < tempoSubs.length; i++) { try { tempoSubs[i](v); } catch (e) {} }
    },
    subscribe: function (f) { tempoSubs.push(f); return function () { var k = tempoSubs.indexOf(f); if (k >= 0) tempoSubs.splice(k, 1); }; },
  };

  // ── Warm one-shot buffer cache ─────────────────────────────────────
  // bufs[name]: undefined = not requested, null = baking, AudioBuffer = warm.
  var bufs = {};
  function bake(name, renderFn, dur, sampleRate) {
    if (bufs[name] !== undefined) return; // baked or baking
    var OAC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    if (!OAC) return;
    var sr = sampleRate || (ctx && ctx.sampleRate) || 44100;
    bufs[name] = null; // mark as baking
    try {
      var off = new OAC(1, Math.ceil(sr * (dur || 0.8)), sr);
      renderFn(off, off.destination, 0);
      off.startRendering().then(function (b) { bufs[name] = b; }).catch(function () { bufs[name] = undefined; });
    } catch (e) { bufs[name] = undefined; }
  }
  function buffer(name) { return bufs[name] || null; }
  function trigger(name, dest, when, fallback) {
    var ac = (dest && dest.context) ? dest.context : audio();
    var b = bufs[name];
    if (b) { var s = ac.createBufferSource(); s.buffer = b; s.connect(dest); s.start(when); return true; }
    if (fallback) { fallback(ac, dest, when); return true; }
    return false;
  }

  // ── WAV encoder (16-bit PCM) ───────────────────────────────────────
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

  window.MAM = Object.assign(window.MAM || {}, {
    audio: audio, hasTone: hasTone, hasPitchy: hasPitchy,
    createStore: createStore, tempo: tempo,
    bake: bake, buffer: buffer, trigger: trigger,
    encodeWav: encodeWav,
  });
})();
