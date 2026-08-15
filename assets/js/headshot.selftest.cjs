/**
 * Headless verification of the headshot MATH (no browser, no canvas).
 *
 * What this covers, and why it is worth covering: these two functions decide
 * whether a family's registration photo is ACCEPTED and what pixel dimensions
 * it is stored at. Both are pure; only the canvas read and the actual encode
 * need a real browser, and those are thin plumbing around this.
 *
 *   node assets/js/headshot.selftest.cjs
 *
 * headshot.js is a browser IIFE; we give it a global `window`, eval it, and
 * read the functions it exposes on MAM_HEADSHOT._internals. The dynamic
 * import() in the file is never evaluated (it lives inside a lazy function).
 *
 * HISTORY — read before extending. This file used to assert against a much
 * larger internals surface (metricsFromGray / assessQuality / borderStd: a
 * full exposure-contrast-focus-lighting-background quality report). Commit
 * 3a42c11 rewrote headshot.js wholesale (−593/+234) and replaced that report
 * with a single brightness heuristic, WITHOUT touching this file — so the test
 * spent months asserting against functions that no longer existed and exiting
 * 1 the moment anyone ran it. Nobody ran it: CI only `node --check`ed the file
 * rather than executing it. Both halves of that are now fixed — this file
 * asserts what actually ships, and CI executes it. Do not add an assertion
 * here without confirming the function is still exported.
 */
'use strict';
const fs = require('fs');
const path = require('path');

global.window = global; // the IIFE assigns window.MAM_HEADSHOT
global.document = { addEventListener() {}, createElement: () => ({ style: {} }), head: { appendChild() {} } };

eval(fs.readFileSync(path.join(__dirname, 'headshot.js'), 'utf8'));

const I = global.MAM_HEADSHOT && global.MAM_HEADSHOT._internals;
if (!I) { console.error('FATAL: MAM_HEADSHOT._internals not exposed'); process.exit(1); }
const { meanLuma, qualityFromLuma, fitWithin } = I;

let pass = 0, fail = 0;
function ck(cond, msg) { if (cond) { pass++; console.log('  ✓ ' + msg); } else { fail++; console.log('  ✗ FAIL: ' + msg); } }

/** An RGBA buffer of `n` pixels, every pixel the same colour. */
function solid(n, r, g, b) {
  const d = new Uint8ClampedArray(n * 4);
  for (let i = 0; i < n; i++) { d[i * 4] = r; d[i * 4 + 1] = g; d[i * 4 + 2] = b; d[i * 4 + 3] = 255; }
  return d;
}
/** Half `a`, half `b` — mean luma must land between the two. */
function halves(n, a, b) {
  const d = new Uint8ClampedArray(n * 4);
  for (let i = 0; i < n; i++) {
    const c = i < n / 2 ? a : b;
    d[i * 4] = c; d[i * 4 + 1] = c; d[i * 4 + 2] = c; d[i * 4 + 3] = 255;
  }
  return d;
}

console.log('▸ meanLuma — BT.601 weighting');
ck(meanLuma(solid(64, 0, 0, 0), 64) === 0, 'all-black → 0');
ck(Math.abs(meanLuma(solid(64, 255, 255, 255), 64) - 255) < 0.01, 'all-white → 255');
ck(Math.abs(meanLuma(solid(64, 128, 128, 128), 64) - 128) < 0.01, 'uniform grey → that grey');
ck(Math.abs(meanLuma(halves(64, 0, 200), 64) - 100) < 0.01, 'half black / half 200 → 100');
// Green is weighted far above blue: a pure-green frame must read brighter than
// a pure-blue one. A naive (r+g+b)/3 would rank them equal and mis-judge exposure.
ck(meanLuma(solid(16, 0, 255, 0), 16) > meanLuma(solid(16, 0, 0, 255), 16), 'green weighs more than blue (BT.601, not a flat mean)');
ck(Math.abs(meanLuma(solid(16, 0, 255, 0), 16) - 149.685) < 0.01, 'pure green → 149.685');
ck(meanLuma(null, 64) === null, 'a missing buffer is null, not a crash');
ck(meanLuma(solid(4, 10, 10, 10), 0) === null, 'zero pixels is null, not a division by zero');

console.log('▸ qualityFromLuma — the accept/reject thresholds');
ck(qualityFromLuma(128).ok === true, 'a mid-grey photo is accepted');
ck(qualityFromLuma(17).ok === false, 'below 18 is rejected as too dark');
ck(qualityFromLuma(17).quality === 'dark', 'and reported as dark');
ck(qualityFromLuma(18).ok === true, 'exactly 18 is accepted — the bound is exclusive');
ck(qualityFromLuma(249).ok === false, 'above 248 is rejected as overexposed');
ck(qualityFromLuma(249).quality === 'overexposed', 'and reported as overexposed');
ck(qualityFromLuma(248).ok === true, 'exactly 248 is accepted — the bound is exclusive');
ck(qualityFromLuma(0).ok === false, 'a black frame is rejected');
ck(qualityFromLuma(255).ok === false, 'a blown-out frame is rejected');
// Unmeasurable must ACCEPT. A tainted or unavailable canvas is a browser
// quirk, and refusing the photo over it loses a real registration — which is
// the failure mode the first-party store exists to prevent.
ck(qualityFromLuma(null).ok === true, 'an unmeasurable photo is accepted, not refused');
ck(qualityFromLuma(undefined).ok === true, 'an absent reading is accepted');
ck(qualityFromLuma(NaN).ok === true, 'NaN is accepted rather than compared into a false reject');
ck(qualityFromLuma(120).reason === null, 'an accepted photo carries no complaint text');
ck(typeof qualityFromLuma(5).reason === 'string' && qualityFromLuma(5).reason.length > 0, 'a rejected photo explains itself to the applicant');

console.log('▸ fitWithin — downscale geometry');
let f = fitWithin(4000, 3000, 1400);
ck(f.w === 1400, 'the long edge lands exactly on the cap');
ck(f.h === 1050, 'the short edge keeps the aspect ratio');
f = fitWithin(3000, 4000, 1400);
ck(f.h === 1400 && f.w === 1050, 'portrait scales on its own long edge');
// The contract: never upscale. Enlarging adds no detail and inflates the POST.
f = fitWithin(200, 150, 1400);
ck(f.scale === 1, 'a small photo is not upscaled');
ck(f.w === 200 && f.h === 150, 'and keeps its original dimensions exactly');
f = fitWithin(1400, 1400, 1400);
ck(f.scale === 1 && f.w === 1400, 'a photo already at the cap is untouched');
// A very wide banner must not round its short edge to zero — a 0-height
// canvas throws on drawImage and would lose the photo entirely.
f = fitWithin(10000, 3, 1400);
ck(f.h >= 1, 'an extreme aspect ratio never rounds the short edge to 0');
ck(fitWithin(0, 100, 1400) === null, 'a zero-width source is null, not a division by zero');
ck(fitWithin(100, 0, 1400) === null, 'a zero-height source is null');
ck(fitWithin(100, 100, 0) === null, 'a zero cap is null rather than scaling to nothing');
ck(fitWithin(undefined, undefined, 1400) === null, 'missing dimensions are null');

console.log('\n' + (fail ? '❌ ' + fail + ' FAILED' : '✅ ALL ' + pass + ' PASSED'));
process.exit(fail ? 1 : 0);
