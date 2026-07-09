/* Node self-test for the notation capture DSP + segmenter (milestone 2).
   Run: node assets/js/notation.selftest.cjs   (exit 0 = pass) */
'use strict';
var N = require('./notation.js');
var fails = 0;
function ok(cond, msg) { if (!cond) { console.error('  ✗ ' + msg); fails++; } else { console.log('  ✓ ' + msg); } }
function near(a, b, tol, msg) { ok(Math.abs(a - b) <= tol, msg + ' (got ' + a + ', want ~' + b + ')'); }

console.log('notation capture self-test');

// ── detectPitchAC on a synthetic 440 Hz (A4, midi 69) sine ──
var sr = 44100, n = 2048, buf = new Float32Array(n);
for (var i = 0; i < n; i++) buf[i] = 0.5 * Math.sin(2 * Math.PI * 440 * i / sr);
var p = N.detectPitchAC(buf, sr);
ok(p && p.hz, 'detects a pitch on a 440 Hz sine');
near(N.freqToMidi(p.hz), 69, 0.3, 'detected pitch is ~A4 (midi 69)');

// 220 Hz (A3, midi 57)
for (i = 0; i < n; i++) buf[i] = 0.5 * Math.sin(2 * Math.PI * 220 * i / sr);
var p2 = N.detectPitchAC(buf, sr);
near(N.freqToMidi(p2.hz), 57, 0.3, 'detects 220 Hz as ~A3 (midi 57)');

// silence → null
var quiet = new Float32Array(n); // all zeros
ok(N.detectPitchAC(quiet, sr) === null, 'returns null on silence');

// ── framesToNotes: two sustained pitches at 100 BPM (beat = 600 ms) ──
// 600 ms of C4 (60), then 600 ms of D4 (62) → two quarter notes.
var frames = [];
for (var t = 0; t < 600; t += 40) frames.push({ t: t, midi: 60 + (Math.random() - 0.5) * 0.4 }); // jitter under a semitone
for (t = 600; t < 1200; t += 40) frames.push({ t: t, midi: 62 + (Math.random() - 0.5) * 0.4 });
var notes = N.framesToNotes(frames, 100);
ok(notes.length === 2, 'two sustained pitches → two notes (got ' + notes.length + ')');
ok(notes[0].midi === 60 && notes[1].midi === 62, 'notes are C4 then D4');
ok(notes[0].beats === 1 && notes[1].beats === 1, 'each ~600 ms → 1 beat at 100 BPM');

// ── blips shorter than minMs are dropped ──
var blip = [{ t: 0, midi: 60 }, { t: 40, midi: 60 }]; // ~40 ms, under 90 ms floor
ok(N.framesToNotes(blip, 100).length === 0, 'a sub-90 ms blip is discarded as noise');

// ── leading/trailing rests trimmed; a held note quantises to 2 beats ──
var held = [];
for (t = 0; t < 300; t += 40) held.push({ t: t, midi: null });      // leading rest (trimmed)
for (t = 300; t < 1500; t += 40) held.push({ t: t, midi: 67 });     // 1200 ms = 2 beats @100
var h = N.framesToNotes(held, 100);
ok(h.length === 1 && h[0].midi === 67, 'leading rest trimmed, one note kept');
ok(h[0].beats === 2, 'a ~1200 ms note → 2 beats (half note)');

console.log(fails === 0 ? '\nPASS' : '\nFAIL: ' + fails + ' assertion(s)');
process.exit(fails === 0 ? 0 : 1);
