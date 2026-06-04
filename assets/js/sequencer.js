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
    { name: 'Cowbell',    voice: 'cowbell',   vol: 0.50 },
    { name: 'Crash',      voice: 'crash',     vol: 0.45 },
    // Pitched/harmonic voices — these don't play a fixed sample; their pitch is
    // derived live from the Key + chord progression (see chordAt). `pitched`
    // marks the role: 'bass' plays the chord root, 'chord' plays the triad.
    { name: 'Bass',       voice: 'bass',      vol: 0.85, pitched: 'bass' },
    { name: 'Keys',       voice: 'keys',      vol: 0.55, pitched: 'chord' },
  ];

  // Keyboard mapping for the playable drum kit — digit keys 1..0 trigger the
  // first ten kit pieces. Active only while the Beat Maker panel is showing.
  var PAD_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];

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
  // Soft-clip curve — gentle tanh saturation adds harmonic warmth + weight
  // (used on the kick body and bass) so they sound fuller, less like a raw sine.
  function satCurve(ac) {
    if (ac.__satCurve) return ac.__satCurve;
    var n = 1024, c = new Float32Array(n);
    for (var i = 0; i < n; i++) { var x = i / (n - 1) * 2 - 1; c[i] = Math.tanh(x * 1.8); }
    ac.__satCurve = c; return c;
  }
  // Classic analog-style metallic hi-hat — six inharmonic square partials through
  // a band-pass + high-pass. Far more realistic than filtered white noise.
  function metalHat(ac, out, when, decay, peak) {
    var bp = ac.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = 10000; bp.Q.value = 0.7;
    var hp = ac.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 7000;
    var g = ac.createGain();
    g.gain.setValueAtTime(0.0001, when);
    g.gain.exponentialRampToValueAtTime(peak, when + 0.002);
    g.gain.exponentialRampToValueAtTime(0.0001, when + decay);
    bp.connect(hp).connect(g).connect(out);
    [2, 3, 4.16, 5.43, 6.79, 8.21].forEach(function (r) {
      var o = ac.createOscillator(); o.type = 'square'; o.frequency.value = 40 * r;
      o.connect(bp); o.start(when); o.stop(when + decay + 0.02);
    });
  }
  var voices = {
    kick: function (ac, out, when) {
      // Body — pitch-swept sine, deeper + a touch longer, through soft saturation.
      var o = ac.createOscillator(), g = ac.createGain(), sh = ac.createWaveShaper();
      sh.curve = satCurve(ac);
      o.type = 'sine';
      o.frequency.setValueAtTime(180, when);
      o.frequency.exponentialRampToValueAtTime(45, when + 0.13);
      g.gain.setValueAtTime(0.0001, when);
      g.gain.exponentialRampToValueAtTime(1.0, when + 0.005);
      g.gain.exponentialRampToValueAtTime(0.0001, when + 0.45);
      o.connect(g).connect(sh).connect(out); o.start(when); o.stop(when + 0.47);
      // Beater click — short high transient for attack/definition on small speakers.
      var c = ac.createOscillator(), cg = ac.createGain();
      c.type = 'triangle';
      c.frequency.setValueAtTime(1500, when); c.frequency.exponentialRampToValueAtTime(420, when + 0.02);
      cg.gain.setValueAtTime(0.5, when); cg.gain.exponentialRampToValueAtTime(0.0001, when + 0.03);
      c.connect(cg).connect(out); c.start(when); c.stop(when + 0.04);
    },
    snare: function (ac, out, when) {
      // Tonal body — two partials (fundamental + a higher overtone) for a real drum ring.
      [185, 330].forEach(function (f, i) {
        var o = ac.createOscillator(), og = ac.createGain();
        o.type = 'triangle'; o.frequency.value = f;
        og.gain.setValueAtTime(0.0001, when);
        og.gain.exponentialRampToValueAtTime(i ? 0.16 : 0.34, when + 0.004);
        og.gain.exponentialRampToValueAtTime(0.0001, when + (i ? 0.07 : 0.11));
        o.connect(og).connect(out); o.start(when); o.stop(when + 0.13);
      });
      // Snares — band-passed noise for the crisp "snap", slightly longer tail.
      var s = ac.createBufferSource(); s.buffer = noiseBuf(ac);
      var hp = ac.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 1500;
      var bp = ac.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = 4200; bp.Q.value = 0.6;
      var ng = ac.createGain();
      ng.gain.setValueAtTime(0.0001, when);
      ng.gain.exponentialRampToValueAtTime(0.7, when + 0.003);
      ng.gain.exponentialRampToValueAtTime(0.0001, when + 0.2);
      s.connect(hp).connect(bp).connect(ng).connect(out); s.start(when); s.stop(when + 0.22);
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
    hatClosed: function (ac, out, when) { metalHat(ac, out, when, 0.05, 0.30); },
    hatOpen:   function (ac, out, when) { metalHat(ac, out, when, 0.34, 0.30); },
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
    cowbell: function (ac, out, when) {  // classic 808 cowbell — two detuned squares
      var g = ac.createGain(); env(g, when, 0.42, 0.32, 0.002); g.connect(out);
      [540, 800].forEach(function (f) { var o = ac.createOscillator(); o.type = 'square'; o.frequency.value = f; o.connect(g); o.start(when); o.stop(when + 0.34); });
    },
    crash: function (ac, out, when) {  // bright cymbal — filtered noise + metallic partials, long tail
      var s = ac.createBufferSource(); s.buffer = noiseBuf(ac);
      var hp = ac.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 5200;
      var g = ac.createGain();
      g.gain.setValueAtTime(0.0001, when);
      g.gain.exponentialRampToValueAtTime(0.5, when + 0.005);
      g.gain.exponentialRampToValueAtTime(0.0001, when + 1.1);
      s.connect(hp).connect(g).connect(out); s.start(when); s.stop(when + 1.2);
      [3700, 5200, 6400].forEach(function (f) {
        var o = ac.createOscillator(), og = ac.createGain(); o.type = 'square'; o.frequency.value = f;
        og.gain.setValueAtTime(0.0001, when);
        og.gain.exponentialRampToValueAtTime(0.055, when + 0.006);
        og.gain.exponentialRampToValueAtTime(0.0001, when + 0.9);
        o.connect(og).connect(out); o.start(when); o.stop(when + 0.95);
      });
    },
    // ── Pitched voices (harmony). Receive an array of frequencies. ──
    bass: function (ac, out, when, freqs) {   // round synth bass with a moving filter
      var f = (freqs && freqs[0]) || 110;
      var o = ac.createOscillator(), sub = ac.createOscillator(), g = ac.createGain();
      var lp = ac.createBiquadFilter(); lp.type = 'lowpass'; lp.Q.value = 6;
      // Filter envelope — opens then closes for a punchy, expressive attack.
      lp.frequency.setValueAtTime(Math.min(3200, f * 9), when);
      lp.frequency.exponentialRampToValueAtTime(Math.max(120, f * 2), when + 0.22);
      var sh = ac.createWaveShaper(); sh.curve = satCurve(ac);  // light drive for warmth
      o.type = 'sawtooth'; o.frequency.setValueAtTime(f, when);
      sub.type = 'sine'; sub.frequency.setValueAtTime(f / 2, when);
      g.gain.setValueAtTime(0.0001, when);
      g.gain.exponentialRampToValueAtTime(0.62, when + 0.012);
      g.gain.exponentialRampToValueAtTime(0.2, when + 0.18);
      g.gain.exponentialRampToValueAtTime(0.0001, when + 0.4);
      o.connect(lp).connect(sh).connect(g);   // saw → filter → drive
      sub.connect(g);                          // clean sub for low-end weight
      g.connect(out);
      o.start(when); sub.start(when); o.stop(when + 0.42); sub.stop(when + 0.42);
    },
    keys: function (ac, out, when, freqs) {   // FM electric-piano (tine) chord
      freqs = (freqs && freqs.length) ? freqs : [261.63];
      var g = ac.createGain();
      g.gain.setValueAtTime(0.0001, when);
      g.gain.exponentialRampToValueAtTime(0.16, when + 0.006);   // quick tine attack
      g.gain.exponentialRampToValueAtTime(0.05, when + 0.2);
      g.gain.exponentialRampToValueAtTime(0.0001, when + 0.9);   // gentle release
      g.connect(out);
      freqs.forEach(function (f) {
        // 2-operator FM: a sine carrier whose frequency is modulated at 2× with a
        // decaying index — the classic Rhodes/DX bell-tine timbre.
        var car = ac.createOscillator(); car.type = 'sine'; car.frequency.value = f;
        var mod = ac.createOscillator(); mod.type = 'sine'; mod.frequency.value = f * 2;
        var mg = ac.createGain();
        mg.gain.setValueAtTime(f * 1.4, when);
        mg.gain.exponentialRampToValueAtTime(f * 0.08, when + 0.25);
        mod.connect(mg).connect(car.frequency);
        car.connect(g);
        car.start(when); mod.start(when); car.stop(when + 0.92); mod.stop(when + 0.92);
      });
    },
  };

  // ── Harmony engine ─────────────────────────────────────────────────
  // Pitched tracks have no fixed pitch — they follow a Key + a chord
  // progression so the bass and keys are always in tune with each other.
  var MAJOR = [0, 2, 4, 5, 7, 9, 11];
  var KEYS = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
  var PROGRESSIONS = {
    'I–V–vi–IV': [1, 5, 6, 4], 'I–IV–V':     [1, 4, 5],   'ii–V–I':     [2, 5, 1],
    'vi–IV–I–V': [6, 4, 1, 5], 'I–vi–IV–V':  [1, 6, 4, 5], 'I–IV (vamp)': [1, 4],
    'I (drone)': [1]
  };
  function mtof(m) { return 440 * Math.pow(2, (m - 69) / 12); }
  function scaleMidi(idx, base) { var oct = Math.floor(idx / 7); return base + MAJOR[((idx % 7) + 7) % 7] + 12 * oct; }
  // Returns { bass: midi, chord: [midi,midi,midi] } active at step s.
  function chordAt(s, st) {
    try {
      var prog = PROGRESSIONS[st.prog] || [1];
      var grp = st.group || 4;
      var degree = prog[Math.floor(s / grp) % prog.length] || 1;
      var key = (st.key | 0) % 12;
      var chordBase = 60 + key;            // C4-ish register for the keys
      var bassBase  = 36 + key;            // C2-ish register for the bass
      return {
        bass: scaleMidi(degree - 1, bassBase),
        chord: [scaleMidi(degree - 1, chordBase), scaleMidi(degree + 1, chordBase), scaleMidi(degree + 3, chordBase)]
      };
    } catch (e) { return { bass: 36 + ((st.key | 0) % 12), chord: [60, 64, 67] }; }
  }
  function pitchedFreqs(track, s, st) {
    var ch = chordAt(s, st);
    return track.pitched === 'bass' ? [mtof(ch.bass)] : ch.chord.map(mtof);
  }

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
    var names = {}; TRACKS.forEach(function (t) { if (!t.pitched) names[t.voice] = 1; }); // pitched voices vary in pitch — never cached
    Object.keys(names).forEach(function (v) {
      try {
        var dur = (v === 'crash') ? 1.4 : 0.6;   // crash needs a long tail; others are short
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
  // Time signatures → grid length + beat grouping. `group` = cells per pulse,
  // used for the bar-line/colour grouping and for the harmonic rhythm.
  var TIME_SIGS = {
    '4/4': { steps: 16, group: 4 },
    '3/4': { steps: 12, group: 4 },
    '6/8': { steps: 12, group: 3 },
    '5/4': { steps: 20, group: 4 },
    '7/8': { steps: 14, group: 2 }
  };
  var STEP_OPTIONS = [12, 14, 16, 20];      // every step-count a meter can produce
  var ACCENT_VEL = { 1: 0.85, 2: 1.15 };   // velocity multiplier per cell value (1 = hit, 2 = accent)
  function slotName(i) { return ['A', 'B', 'C', 'D'][i] || '?'; }
  function blankPattern(n) { n = n || STEPS; return TRACKS.map(function () { return new Array(n).fill(0); }); }
  function resizePattern(pat, n) {
    return pat.map(function (row) {
      var out = new Array(n).fill(0);
      for (var i = 0; i < n && i < row.length; i++) out[i] = row[i] | 0;
      return out;
    });
  }
  function P(str) {
    var a = new Array(STEPS).fill(0);
    for (var i = 0; i < STEPS && i < str.length; i++) a[i] = str[i] === 'x' ? 1 : 0;
    return a;
  }
  // Genres. Drum rows plus optional bass/keys rows (their PITCH comes from the
  // key + prog, set here too). 'x' = hit on that 16th step. All presets are 4/4.
  var PRESETS = {
    'Four on the floor': { kick: 'x...x...x...x...', clap: '....x.......x...', hatClosed: 'x.x.x.x.x.x.x.x.', hatOpen: '..x...x...x...x.', bass: 'x...x...x...x...', keys: '....x.......x...', key: 'A', prog: 'vi–IV–I–V' },
    'House':             { kick: 'x...x...x...x...', clap: '....x.......x...', hatClosed: 'x.x.x.x.x.x.x.x.', hatOpen: '..x...x...x...x.', bass: '..x...x...x...x.', keys: 'x.......x.......', key: 'A', prog: 'vi–IV–I–V' },
    'Disco':             { kick: 'x...x...x...x...', hatOpen: '..x.x.x.x.x.x.x.', clap: '....x.......x...', bass: 'x.x.x.x.x.x.x.x.', keys: '....x.......x...', key: 'D', prog: 'I–vi–IV–V' },
    'Boom bap':          { kick: 'x.....x..x......', snare: '....x.......x...', hatClosed: 'x.x.x.x.x.x.x.x.', bass: 'x.....x..x......', key: 'C', prog: 'ii–V–I' },
    'Trap':              { kick: 'x.....x.....x...', snare: '....x.......x...', hatClosed: 'xxxxxx.xxx.xxxxx', bass: 'x.....x.....x...', key: 'F', prog: 'I (drone)' },
    'Afrobeat':          { kick: 'x..x..x...x..x..', rim: '..x..x..x..x..x.', clap: '....x.......x...', hatClosed: 'x.xx.x.xx.x.xx.x', shaker: 'x.x.x.x.x.x.x.x.', bass: 'x..x..x...x..x..', keys: '....x.......x...', key: 'E', prog: 'I–IV (vamp)' },
    'Highlife':          { kick: 'x...x..x.x..x...', rim: '..x.x..x..x.x..x', hatClosed: 'x.x.x.x.x.x.x.x.', shaker: '..x...x...x...x.', bass: 'x...x..x.x..x...', keys: 'x.......x.......', key: 'G', prog: 'I–V–vi–IV' },
    'Amapiano':          { kick: 'x.....x...x.....', shaker: 'x.x.x.x.x.x.x.x.', tom: '......x.....x..x', bass: '......x.....x..x', keys: 'x.......x.......', key: 'F', prog: 'I–V–vi–IV' },
    'Reggaeton':         { kick: 'x.....x...x.....', snare: '...x..x...x..x..', hatClosed: 'x.x.x.x.x.x.x.x.', bass: 'x.....x...x.....', key: 'C', prog: 'vi–IV–I–V' },
    'One drop':          { kick: '........x.......', snare: '........x.......', rim: '..x...x...x...x.', hatClosed: 'x.x.x.x.x.x.x.x.', bass: '........x.......', keys: '....x.x.....x.x.', key: 'A', prog: 'I–IV (vamp)' },
    'Funk':              { kick: 'x..x...x..x.x...', snare: '....x.......x...', hatClosed: 'xxxxxxxxxxxxxxxx', rim: '..x..x..x..x..x.', bass: 'x..x...x..x.x...', key: 'E', prog: 'I–IV (vamp)' },
    'Rock':              { kick: 'x.......x.x.....', snare: '....x.......x...', hatClosed: 'x.x.x.x.x.x.x.x.', crash: 'x...............', bass: 'x.......x.x.....', key: 'E', prog: 'I–IV–V' },
    'Gospel':            { kick: 'x..x..x.x..x..x.', snare: '....x.......x...', hatClosed: 'x.x.x.x.x.x.x.x.', bass: 'x..x..x.x..x..x.', keys: 'x...x...x...x...', key: 'A', prog: 'I–IV–V' },
    'Jazz swing':        { kick: '............x...', snare: '....x.......x...', hatClosed: 'x..x..x..x..x..x', rim: '..x..x..x..x..x.', bass: 'x.x.x.x.x.x.x.x.', key: 'F', prog: 'ii–V–I' },
    'Bossa nova':        { rim: '..x.x..x.x..x.x.', kick: 'x..x..x.x..x..x.', shaker: 'x.x.x.x.x.x.x.x.', bass: 'x..x..x.x..x..x.', keys: '..x.....x.......', key: 'C', prog: 'ii–V–I' }
  };
  function keyIndex(k) { if (typeof k === 'number') return ((k % 12) + 12) % 12; var i = KEYS.indexOf(k); return i < 0 ? 0 : i; }
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
      steps: 16, group: 4, meter: '4/4', key: 0, prog: 'I–V–vi–IV',
      slots: [null, null, null, null], activeSlot: 0,
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
      if (d.meter && TIME_SIGS[d.meter]) { st.meter = d.meter; st.steps = TIME_SIGS[d.meter].steps; st.group = TIME_SIGS[d.meter].group; }
      else if (STEP_OPTIONS.indexOf(d.steps) >= 0) { st.steps = d.steps; if (typeof d.group === 'number' && d.group > 0) st.group = d.group; }
      if (typeof d.key === 'number' && d.key >= 0 && d.key < 12) st.key = d.key | 0;
      if (d.prog && PROGRESSIONS[d.prog]) st.prog = d.prog;
      if (Array.isArray(d.pattern)) {
        st.pattern = blankPattern(st.steps);
        d.pattern.forEach(function (rowArr, i) { if (st.pattern[i] && Array.isArray(rowArr)) for (var s = 0; s < st.steps; s++) st.pattern[i][s] = (rowArr[s] | 0) % 3; });
      }
      if (Array.isArray(d.tracks)) d.tracks.forEach(function (s, i) {
        if (!st.tracks[i] || !s) return;
        if (typeof s.vol === 'number') st.tracks[i].vol = s.vol;
        st.tracks[i].mute = !!s.mute; st.tracks[i].solo = !!s.solo;
      });
      if (Array.isArray(d.slots)) st.slots = [0, 1, 2, 3].map(function (i) {
        var sl = d.slots[i];
        if (sl && Array.isArray(sl.pattern)) return { steps: STEP_OPTIONS.indexOf(sl.steps) >= 0 ? sl.steps : 16, group: (typeof sl.group === 'number' && sl.group > 0) ? sl.group : 4, meter: TIME_SIGS[sl.meter] ? sl.meter : '4/4', pattern: sl.pattern.map(function (r) { return (r || []).map(function (v) { return (v | 0) % 3; }); }) };
        return null;
      });
      if (typeof d.activeSlot === 'number' && d.activeSlot >= 0 && d.activeSlot < 4) st.activeSlot = d.activeSlot;
      return st;
    } catch (e) { return null; }
  }
  function save(s) {
    try {
      localStorage.setItem(SAVE_KEY, JSON.stringify({ bpm: s.bpm, swing: s.swing, master: s.master, steps: s.steps, group: s.group, meter: s.meter, key: s.key, prog: s.prog, pattern: s.pattern, tracks: s.tracks, slots: s.slots, activeSlot: s.activeSlot }));
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
    var steps = st.steps, bars = 2, sd = 60 / st.bpm / 4, totalSteps = bars * steps, tail = 0.6;
    var sr = (ctx && ctx.sampleRate) || audio().sampleRate || 44100;
    var OAC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    if (!OAC) { status('Your browser can’t render WAV files offline — try the latest Chrome, Edge or Safari.'); return; }
    status('Rendering your beat…');
    var off = new OAC(2, Math.ceil((totalSteps * sd + tail) * sr), sr);
    var master = off.createGain(); master.gain.value = st.master;
    // Same bus compressor as live playback so the exported WAV is produced + safe.
    var comp = off.createDynamicsCompressor();
    comp.threshold.value = -14; comp.knee.value = 8; comp.ratio.value = 3.2; comp.attack.value = 0.004; comp.release.value = 0.22;
    master.connect(comp).connect(off.destination);
    var anySolo = st.tracks.some(function (t) { return t.solo; });
    TRACKS.forEach(function (t, i) {
      var aud = anySolo ? !!st.tracks[i].solo : !st.tracks[i].mute;
      if (!aud) return;
      var tg = off.createGain(); tg.gain.value = st.tracks[i].vol; tg.connect(master);
      for (var b = 0; b < bars; b++) for (var s = 0; s < steps; s++) {
        var cell = st.pattern[i][s];
        if (!cell) continue;
        var gridT = (b * steps + s) * sd;
        var when = gridT + (s % 2 ? st.swing * sd : 0);
        var hg = off.createGain(); hg.gain.value = ACCENT_VEL[cell] || 1; hg.connect(tg);
        if (t.pitched) voices[t.voice](off, hg, when, pitchedFreqs(t, s, st));
        else voices[t.voice](off, hg, when);
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
      masterGain = ac.createGain(); masterGain.gain.value = store.getState().master;
      // Bus compressor — glues the kit together and tames peaks so the mix sounds
      // produced, not clippy, on phone speakers.
      var comp = ac.createDynamicsCompressor();
      comp.threshold.value = -14; comp.knee.value = 8; comp.ratio.value = 3.2; comp.attack.value = 0.004; comp.release.value = 0.22;
      masterGain.connect(comp).connect(ac.destination);
      tgains = TRACKS.map(function (t, i) { var g = ac.createGain(); g.gain.value = store.getState().tracks[i].vol; g.connect(masterGain); return g; });
    }
    function trig(i, when, vel, step) {
      var t = TRACKS[i], v = t.voice, out = tgains[i];
      if (vel && vel !== 1) { var gv = ac.createGain(); gv.gain.value = vel; gv.connect(tgains[i]); out = gv; }
      if (t.pitched) { voices[v](ac, out, when, pitchedFreqs(t, step || 0, store.getState())); return; }
      if (buffers[v]) { var s = ac.createBufferSource(); s.buffer = buffers[v]; s.connect(out); s.start(when); }
      else voices[v](ac, out, when);
    }
    function sched() {
      var st = store.getState(), sd = 60 / st.bpm / 4, steps = st.steps;
      while (nextT < ac.currentTime + 0.12) {
        var s = stp % steps, swung = nextT + (s % 2 ? st.swing * sd : 0);
        for (var i = 0; i < TRACKS.length; i++) { var cell = st.pattern[i][s]; if (cell && trackAudible(i, st)) trig(i, swung, ACCENT_VEL[cell] || 1, s); }
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
      audition: function (i, vel) { build(); trig(i, ac.currentTime + 0.01, vel, 0); },
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
    function trig(i, time, vel) {
      var p = players[i];
      if (p && p.loaded !== false && p.buffer && p.buffer.loaded !== false) {
        try { if (p.volume) p.volume.value = Tone.gainToDb(Math.max(0.05, vel || 1)); p.start(time); return; } catch (e) {}
      }
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
          var cur = store.getState(), s = stp % cur.steps;
          for (var i = 0; i < TRACKS.length; i++) { var cell = cur.pattern[i][s]; if (cell && trackAudible(i, cur)) trig(i, time, ACCENT_VEL[cell] || 1); }
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
      audition: function (i, vel) { build(); trig(i, undefined, vel); },
      dispose: function () {
        this.stop();
        try { players.forEach(function (p) { p.dispose(); }); channels.forEach(function (c) { c.dispose(); }); } catch (e) {}
        players = []; channels = []; ready = false;
      },
    };
  }

  var kernel = null;
  // Always use the dependency-free raw Web Audio kernel. The Tone.js kernel
  // (kept below for reference) threw repeated "TickSource: A value with the
  // given key could not be found" errors from Transport.scheduleRepeat /
  // Tone.Draw.schedule, which silently killed playback. The raw look-ahead
  // scheduler is sample-accurate enough for a step sequencer and never depends
  // on a CDN, so the Beat Maker just works.
  function wantKernel() { return 'raw'; }
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

  // ── Pattern length + slots ─────────────────────────────────────────
  function setMeter(label) {
    var m = TIME_SIGS[label]; if (!m) { label = '4/4'; m = TIME_SIGS['4/4']; }
    var st = store.getState();
    if (label === st.meter) return;
    store.setState({ meter: label, steps: m.steps, group: m.group, pattern: resizePattern(st.pattern, m.steps), step: -1 });
    layout(); // cell count changed
    status('Time signature: ' + label + ' (' + m.steps + ' steps).');
  }
  function selectSlot(i) {
    var st = store.getState(), slot = st.slots[i];
    if (slot) {
      store.setState({ activeSlot: i, steps: slot.steps, group: slot.group || 4, meter: slot.meter || '4/4', pattern: slot.pattern.map(function (r) { return r.slice(); }), step: -1 });
      layout();
      status('Recalled pattern ' + slotName(i) + '.');
    } else {
      store.setState({ activeSlot: i });
      status('Pattern ' + slotName(i) + ' is empty — tap Store to save the current groove here.');
    }
  }
  function storeSlot() {
    var st = store.getState(), slots = st.slots.slice();
    slots[st.activeSlot] = { steps: st.steps, group: st.group, meter: st.meter, pattern: st.pattern.map(function (r) { return r.slice(); }) };
    store.setState({ slots: slots });
    status('Stored the current groove in pattern ' + slotName(st.activeSlot) + '.');
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
    var steps = store ? store.getState().steps : STEPS;
    var minW = steps * 28;
    cssW = Math.max(minW, wrapW); cssH = TRACKS.length * ROW;
    var dpr = window.devicePixelRatio || 1;
    cvs.style.width = cssW + 'px'; cvs.style.height = cssH + 'px';
    cvs.width = Math.round(cssW * dpr); cvs.height = Math.round(cssH * dpr);
    c.setTransform(dpr, 0, 0, dpr, 0, 0);
    cellW = cssW / steps;
    draw();
  }
  function draw() {
    if (!cellW || !store) return;
    var st = store.getState(), steps = st.steps, gsize = st.group || 4;
    c.clearRect(0, 0, cssW, cssH);
    for (var t = 0; t < TRACKS.length; t++) {
      var y = t * ROW;
      for (var s = 0; s < steps; s++) {
        var x = s * cellW, group = Math.floor(s / gsize);
        var cell = st.pattern[t][s], isCur = st.playing && s === st.step;
        roundRect(c, x + 3, y + 3, cellW - 6, ROW - 6, 6);
        if (cell) {
          c.fillStyle = isCur ? '#FFE6A0' : (cell === 2 ? '#FCC647' : (group % 2 ? '#D88A22' : '#EE9E2C')); c.fill();
          roundRect(c, x + 4, y + 4, cellW - 8, (ROW - 8) * 0.42, 5);
          c.fillStyle = 'rgba(255,255,255,0.22)'; c.fill();
          if (cell === 2) { // accent marker
            c.fillStyle = 'rgba(26,18,11,0.6)';
            c.beginPath(); c.arc(x + cellW / 2, y + ROW / 2 + (ROW - 8) * 0.12, Math.min(4, cellW * 0.09), 0, Math.PI * 2); c.fill();
          }
        } else {
          c.fillStyle = isCur ? '#33271699' : (group % 2 ? '#1b140d' : '#221910'); c.fill();
          c.strokeStyle = 'rgba(255,255,255,0.05)'; c.lineWidth = 1; c.stroke();
        }
      }
    }
    c.strokeStyle = 'rgba(255,255,255,0.12)'; c.lineWidth = 1;
    for (var g = 1; g < steps / gsize; g++) { var gx = Math.round(g * gsize * cellW) + 0.5; c.beginPath(); c.moveTo(gx, 0); c.lineTo(gx, cssH); c.stroke(); }
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
    var steps = store.getState().steps;
    var s = Math.floor((e.clientX - r.left) / cellW), t = Math.floor((e.clientY - r.top) / ROW);
    if (s < 0 || s >= steps || t < 0 || t >= TRACKS.length) return;
    var pat = store.getState().pattern.map(function (row) { return row.slice(); });
    pat[t][s] = (pat[t][s] + 1) % 3; // off → hit → accent → off
    store.setState({ pattern: pat });
    if (pat[t][s]) { try { ensureKernel().audition(t, ACCENT_VEL[pat[t][s]] || 1); } catch (err) {} } // audition on enable
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

  // ── Playable drum kit (pads) ───────────────────────────────────────
  // A performable kit: click a pad — or press its number key — to play the
  // sound live through the same audio kernel the sequencer uses. Every pad maps
  // to a track, so the kit and the grid are one instrument.
  function hitPad(i) {
    if (i < 0 || i >= TRACKS.length) return;
    try { ensureKernel().audition(i, 1.12); } catch (e) {}
    var pad = $('.seq-pad[data-i="' + i + '"]');
    if (pad) { pad.classList.add('hit'); setTimeout(function () { pad.classList.remove('hit'); }, 110); }
  }
  function buildPads() {
    var host = $('#seqPads'); if (!host) return;
    host.innerHTML = '';
    TRACKS.forEach(function (t, i) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'seq-pad';
      b.setAttribute('data-i', i);
      b.setAttribute('aria-label', 'Play ' + t.name);
      b.innerHTML = '<span class="seq-pad-name">' + t.name + '</span>' +
        (PAD_KEYS[i] ? '<span class="seq-pad-key">' + PAD_KEYS[i] + '</span>' : '');
      host.appendChild(b);
    });
    host.addEventListener('pointerdown', function (e) {
      var p = e.target.closest('.seq-pad'); if (!p) return;
      e.preventDefault();           // keep focus off the button so Space still controls transport
      hitPad(+p.getAttribute('data-i'));
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
    if (!prev || s.meter !== prev.meter) { var mSel = $('#seqMeter'); if (mSel) mSel.value = s.meter; }
    if (!prev || s.key !== prev.key) { var kSel = $('#seqKey'); if (kSel) kSel.value = String(s.key); }
    if (!prev || s.prog !== prev.prog) { var pSel = $('#seqProg'); if (pSel) pSel.value = s.prog; }
    if (!prev || s.slots !== prev.slots || s.activeSlot !== prev.activeSlot) {
      $$('.seq-slot').forEach(function (b) {
        var i = +b.getAttribute('data-slot');
        b.classList.toggle('filled', !!s.slots[i]);
        b.classList.toggle('active', i === s.activeSlot);
      });
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
    $('#seqClear').addEventListener('click', function () { store.setState({ pattern: blankPattern(store.getState().steps) }); status('Cleared. A blank canvas — start tapping cells.'); });
    $('#seqRandom').addEventListener('click', function () {
      var prob = { kick: 0.28, snare: 0.12, clap: 0.12, hatClosed: 0.55, hatOpen: 0.12, tom: 0.1, rim: 0.18, shaker: 0.4, cowbell: 0.06, crash: 0.03, bass: 0.3, keys: 0.14 };
      var st = store.getState(), steps = st.steps, grp = st.group || 4, pat = blankPattern(steps);
      TRACKS.forEach(function (t, i) {
        var p = prob[t.voice] || 0.2;
        for (var s = 0; s < steps; s++) {
          // Land bass on beat starts so the harmony stays grounded.
          if (t.pitched === 'bass') { pat[i][s] = (s % grp === 0 && Math.random() < 0.85) || Math.random() < 0.12 ? 1 : 0; }
          else pat[i][s] = Math.random() < p ? (Math.random() < 0.18 ? 2 : 1) : 0;
        }
      });
      var ki = voiceIndex('kick'); if (ki >= 0) pat[ki][0] = 2;
      store.setState({ pattern: pat }); status('Rolled a fresh groove. Not feeling it? Roll again.');
    });
    $('#seqPresets').addEventListener('change', function (e) {
      if (!e.target.value) return;
      var name = e.target.value; e.target.value = '';
      var pr = PRESETS[name] || {}, cur = store.getState();
      store.setState({
        meter: '4/4', steps: 16, group: 4, pattern: patternFromPreset(name),
        key: pr.key != null ? keyIndex(pr.key) : cur.key,
        prog: (pr.prog && PROGRESSIONS[pr.prog]) ? pr.prog : cur.prog
      });
      layout();
      status('Loaded “' + name + '”' + (pr.key != null ? ' in ' + KEYS[keyIndex(pr.key)] + ' · ' + pr.prog : '') + '. Hit play, then make it yours.');
    });
    $('#seqExport').addEventListener('click', exportWav);

    // Time signature
    var meterSel = $('#seqMeter');
    if (meterSel) meterSel.addEventListener('change', function (e) { setMeter(e.target.value); });
    // Harmony — key + chord progression for the pitched (Bass / Keys) tracks
    var keySel = $('#seqKey');
    if (keySel) keySel.addEventListener('change', function (e) { store.setState({ key: keyIndex(parseInt(e.target.value, 10) || 0) }); status('Key: ' + KEYS[store.getState().key] + ' major.'); });
    var progSel = $('#seqProg');
    if (progSel) progSel.addEventListener('change', function (e) { if (PROGRESSIONS[e.target.value]) { store.setState({ prog: e.target.value }); status('Progression: ' + e.target.value + '.'); } });
    // Pattern slots (A–D): click recalls (or selects an empty slot); Store writes.
    $$('.seq-slot').forEach(function (b) { b.addEventListener('click', function () { selectSlot(+b.getAttribute('data-slot')); }); });
    var storeBtn = $('#seqStore');
    if (storeBtn) storeBtn.addEventListener('click', storeSlot);

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
    // Digit keys 1..0 play the drum kit live while the Beat Maker is active.
    document.addEventListener('keydown', function (e) {
      if (e.repeat || e.metaKey || e.ctrlKey || e.altKey) return;
      var active = document.querySelector('.tool-panel.active');
      if (!active || active.getAttribute('data-tool') !== 'seq') return;
      var tag = document.activeElement && document.activeElement.tagName;
      if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') return;
      var i = PAD_KEYS.indexOf(e.key);
      if (i < 0) return;
      e.preventDefault();
      hitPad(i);
    });
    window.addEventListener('resize', function () { if (panel.classList.contains('active') || wrap.clientWidth) layout(); });
    // Once Tone finishes loading, note that the upgraded kernel is available.
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
    if (!prev || s.bpm !== prev.bpm || s.swing !== prev.swing || s.master !== prev.master || s.pattern !== prev.pattern
        || s.tracks !== prev.tracks || s.steps !== prev.steps || s.meter !== prev.meter || s.key !== prev.key || s.prog !== prev.prog
        || s.slots !== prev.slots || s.activeSlot !== prev.activeSlot) save(s);
  }

  // ── Init (synchronous) ─────────────────────────────────────────────
  // The Beat Maker must be live the instant the panel exists — never gated
  // behind a CDN module load. nativeCreateStore is a complete, API-identical
  // store, so there is nothing to wait for. (The Tone.js kernel still upgrades
  // the *audio clock* on play once Tone has loaded; that's independent of this.)
  var initial = loadSaved();
  var store = nativeCreateStore(function () { return initial || defaultState(); });
  buildTracks();
  buildPads();
  wireInputs();
  store.subscribe(onState);
  onState(store.getState(), null); // initial paint
  layout();
  if (!wrap.clientWidth && 'ResizeObserver' in window) {
    var ro = new ResizeObserver(function () { if (wrap.clientWidth) { layout(); ro.disconnect(); } });
    ro.observe(wrap);
  }
  if (!initial) status('Loaded a starter Afrobeat groove. Press play — or tap the pads to play the kit live.');

  // Share one tempo across the studio: adopt the bus value, and follow it.
  if (window.MAM && window.MAM.tempo) {
    store.setState({ bpm: window.MAM.tempo.get() });
    window.MAM.tempo.subscribe(function (v) { if (v !== store.getState().bpm) store.setState({ bpm: v }); });
  }

  window.MAM_SEQ = {
    play: play, stop: stop, exportWav: exportWav,
    store: store,
    hit: function (i, vel) { try { ensureKernel().audition(i, vel || 1); } catch (e) {} },
    state: function () { return store.getState(); },
    kernel: function () { return kernel && kernel.name; },
  };
  window.dispatchEvent(new CustomEvent('mam:seq-ready'));
})();
