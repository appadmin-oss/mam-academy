/**
 * Headless verification of the headshot quality MATH (no browser/canvas).
 *
 * The pixel scan can't run in CI (no camera/canvas), but its computational core
 * — metricsFromGray / borderStd / assessQuality — is pure, so we exercise it
 * here against synthetic grayscale buffers with known properties. This turns
 * "needs browser QA" into "the math is verified"; only the live canvas-capture
 * path itself still wants a real-device pass.
 *
 *   node assets/js/headshot.selftest.cjs
 *
 * headshot.js is a browser IIFE; we give it a global `window`, eval it, and read
 * the functions it exposes on MAM_HEADSHOT._internals. The dynamic import() in
 * the file is never evaluated (it lives inside a lazy function).
 */
'use strict';
const fs = require('fs');
const path = require('path');

global.window = global; // the IIFE assigns window.MAM_HEADSHOT
// eslint-disable-next-line no-eval
eval(fs.readFileSync(path.join(__dirname, 'headshot.js'), 'utf8'));

const I = global.MAM_HEADSHOT && global.MAM_HEADSHOT._internals;
if (!I) { console.error('FATAL: MAM_HEADSHOT._internals not exposed'); process.exit(1); }
const { metricsFromGray, assessQuality, borderStd } = I;

let pass = 0, fail = 0;
function ck(cond, msg) { if (cond) { pass++; console.log('  ✓ ' + msg); } else { fail++; console.log('  ✗ FAIL: ' + msg); } }

// ── synthetic gray buffers (0..255) ─────────────────────────────────────────
function uniform(cw, ch, v) { const g = new Float32Array(cw * ch); g.fill(v); return g; }
function checker(cw, ch) { const g = new Float32Array(cw * ch); for (let y = 0; y < ch; y++) for (let x = 0; x < cw; x++) g[y * cw + x] = ((x + y) & 1) ? 255 : 0; return g; }
function leftRight(cw, ch, l, r) { const g = new Float32Array(cw * ch); for (let y = 0; y < ch; y++) for (let x = 0; x < cw; x++) g[y * cw + x] = x < cw / 2 ? l : r; return g; }

console.log('▸ metricsFromGray — exposure / contrast / focus');
let m = metricsFromGray(uniform(40, 40, 128), 40, 40, null, 1);
ck(m.brightness === 128, 'uniform 128 → brightness 128');
ck(m.contrast === 0, 'uniform → contrast 0');
ck(m.sharpness === 0, 'uniform → sharpness 0 (no edges)');
ck(m.clip === 0, 'uniform mid-grey → no clipping');
ck(metricsFromGray(uniform(40, 40, 0), 40, 40, null, 1).clip === 1, 'all-black → clip 1.0');
ck(metricsFromGray(uniform(40, 40, 255), 40, 40, null, 1).clip === 1, 'all-white → clip 1.0');

m = metricsFromGray(checker(40, 40), 40, 40, null, 1);
ck(m.sharpness > 1000, 'checkerboard → high sharpness (' + m.sharpness + ')');
ck(m.contrast > 100, 'checkerboard → high contrast (' + m.contrast + ')');

console.log('▸ metricsFromGray — even lighting (face box)');
m = metricsFromGray(leftRight(40, 40, 200, 80), 40, 40, { x: 0, y: 0, w: 40, h: 40 }, 1);
ck(m.lightingDiff !== null && m.lightingDiff > 0.5, 'bright-left/dark-right → high lightingDiff (' + m.lightingDiff + ')');
ck(metricsFromGray(uniform(40, 40, 140), 40, 40, { x: 0, y: 0, w: 40, h: 40 }, 1).lightingDiff === 0, 'even face → lightingDiff 0');
// faceBoxPx is in FULL-RES pixels; `scale` must map it onto the buffer.
m = metricsFromGray(leftRight(40, 40, 200, 80), 40, 40, { x: 0, y: 0, w: 80, h: 80 }, 0.5);
ck(m.lightingDiff > 0.5, 'face box scaled by `scale` lands on the buffer');
ck(metricsFromGray(new Float32Array(4), 0, 0, null, 1) === null, 'empty buffer → null');

console.log('▸ borderStd — background uniformity');
ck(borderStd(uniform(50, 50, 100), 50, 50) === 0, 'plain border → std 0');
ck(borderStd(checker(50, 50), 50, 50) > 50, 'busy border → high std');

console.log('▸ assessQuality — checklist + grade');
let q = assessQuality({ faceCount: 0 }, metricsFromGray(uniform(40, 40, 140), 40, 40, null, 1));
ck(q.hasFail === true, 'no face → hasFail true');
ck(q.checks.some(c => c.id === 'face' && c.status === 'fail'), 'no face → face check fails');
ck(q.checks.some(c => c.id === 'exposure'), 'quality criteria still scored without a face');

const goodGeom = { faceCount: 1, score: 0.9, pct: 0.4, cx: 0.5, cy: 0.5, tiltDeg: 3, eyesVisible: true, yawRatio: 0.1 };
const goodMetrics = { brightness: 130, contrast: 45, sharpness: 300, clip: 0.0, lightingDiff: 0.1, bgStd: 20 };
q = assessQuality(goodGeom, goodMetrics);
ck(q.hasFail === false && q.score === 100 && q.grade === 'Excellent', 'ideal photo → 100 / Excellent (' + q.score + ')');

const tiltGeom = { faceCount: 1, score: 0.5, pct: 0.05, cx: 0.9, cy: 0.5, tiltDeg: 40, eyesVisible: false, yawRatio: 0.9 };
const badMetrics = { brightness: 30, contrast: 10, sharpness: 5, clip: 0.2, lightingDiff: 0.6, bgStd: 120 };
q = assessQuality(tiltGeom, badMetrics);
ck(q.score < 55 && q.grade === 'Needs work', 'thoroughly poor photo → Needs work (' + q.score + ')');
ck(q.checks.filter(c => c.status === 'warn').length >= 5, 'poor photo flags many warnings (' + q.checks.filter(c => c.status === 'warn').length + ')');
ck(q.hasFail === false, 'advisory: a poor-but-present face never hard-fails');

q = assessQuality(null, null);
ck(q.checks.some(c => c.id === 'quality' && c.status === 'skip'), 'no metrics → quality scan marked skip');

console.log('\n' + (fail ? '❌ ' + fail + ' FAILED' : '✅ ALL ' + pass + ' PASSED'));
process.exit(fail ? 1 : 0);
