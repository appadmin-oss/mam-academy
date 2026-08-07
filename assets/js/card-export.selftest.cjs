/* Node self-test for the ID card imposition planner + cutting guides.
   Run: node assets/js/card-export.selftest.cjs   (exit 0 = pass)

   These are the two things about a print sheet that are expensive to get wrong and
   cheap to check: how many cards land on a sheet, and whether a cutting guide is
   ever drawn across a card. Both are pure geometry, so they are verified here
   rather than by squinting at a PDF. */
'use strict';

var CE = require('./card-export.js').MamCardExport;

var fails = 0;
function ok(cond, msg) {
  if (cond) { console.log('  ✓ ' + msg); }
  else { console.error('  ✗ ' + msg); fails++; }
}
function eq(got, want, msg) { ok(got === want, msg + ' (got ' + got + ', want ' + want + ')'); }

console.log('ID card imposition + cut guides self-test');

/* ── 9 up on A4 — the whole reason the trim is 60×85 ──────────────────────── */
var a4 = CE.planImposition(9, { sheet: 'A4' });
eq(a4.trimW, 60, 'default trim width is 60mm');
eq(a4.trimH, 85, 'default trim height is 85mm');
eq(a4.cols, 3, 'three columns fit A4');
eq(a4.rows, 3, 'three rows fit A4');
eq(a4.perSheet, 9, 'NINE cards per A4 sheet');
eq(a4.sheets, 1, 'nine cards need exactly one sheet');
ok(a4.fits, 'the plan fits');

// The grid must actually be inside the paper, with the stated margin or better.
ok(a4.originX >= a4.margin - 0.001, 'the grid starts at or inside the left margin');
ok(a4.originY >= a4.margin - 0.001, 'the grid starts at or inside the top margin');
ok(a4.originX + a4.gridW <= a4.sheetW - a4.margin + 0.001, 'the grid ends inside the right margin');
ok(a4.originY + a4.gridH <= a4.sheetH - a4.margin + 0.001, 'the grid ends inside the bottom margin');

/* ── The gutter must exceed 2×bleed, or neighbours' bleeds touch ──────────── */
ok(a4.gutter > 2 * a4.bleed, 'the default gutter leaves bare paper between bleeds');
eq(a4.gutter - 2 * a4.bleed, 2, 'exactly 2mm of it — the strip the guides are drawn on');

/* ── A tenth card rolls onto a second sheet ───────────────────────────────── */
eq(CE.planImposition(10, { sheet: 'A4' }).sheets, 2, 'ten cards need two sheets');
eq(CE.planImposition(18, { sheet: 'A4' }).sheets, 2, 'eighteen cards still fit two sheets');
eq(CE.planImposition(19, { sheet: 'A4' }).sheets, 3, 'nineteen need three');

/* ── A card too big for the sheet is reported, not silently truncated ─────── */
var huge = CE.planImposition(1, { sheet: 'A4', trimW_mm: 250, trimH_mm: 350 });
ok(!huge.fits, 'an oversized card does not fit');
ok(typeof huge.reason === 'string' && huge.reason.length > 0, 'and says why');

/* ── Cut guides: never across a card ─────────────────────────────────────────
   This is the invariant that matters. A guide drawn over the artwork is ink on a
   finished card. Every segment must lie wholly outside every card's TRIM box. */
var segs = CE.cutGuideSegments(a4, {});
ok(segs.length > 0, 'guides are produced');

function cardBoxes(plan) {
  var boxes = [], c, r;
  var cellW = plan.trimW + plan.gutter, cellH = plan.trimH + plan.gutter;
  for (r = 0; r < plan.rows; r++) {
    for (c = 0; c < plan.cols; c++) {
      var x = plan.originX + c * cellW, y = plan.originY + r * cellH;
      boxes.push({ x1: x, y1: y, x2: x + plan.trimW, y2: y + plan.trimH });
    }
  }
  return boxes;
}
// A segment "enters" a box only if it overlaps in BOTH axes with real length —
// touching an edge exactly is fine (that IS the trim line).
function entersBox(s, b) {
  var e = 0.001;
  var sx1 = Math.min(s.x1, s.x2), sx2 = Math.max(s.x1, s.x2);
  var sy1 = Math.min(s.y1, s.y2), sy2 = Math.max(s.y1, s.y2);
  return (sx2 > b.x1 + e && sx1 < b.x2 - e) && (sy2 > b.y1 + e && sy1 < b.y2 - e);
}
var boxes = cardBoxes(a4);
var crossings = 0;
segs.forEach(function (s) { boxes.forEach(function (b) { if (entersBox(s, b)) crossings++; }); });
eq(crossings, 0, 'NO guide segment crosses any card’s trim box');

/* ── Guides must reach the paper edge, at both ends of every cut ──────────── */
function has(pred) { return segs.some(pred); }
var atTop    = segs.filter(function (s) { return Math.min(s.y1, s.y2) === 0; });
var atBottom = segs.filter(function (s) { return Math.max(s.y1, s.y2) === a4.sheetH; });
var atLeft   = segs.filter(function (s) { return Math.min(s.x1, s.x2) === 0; });
var atRight  = segs.filter(function (s) { return Math.max(s.x1, s.x2) === a4.sheetW; });
// 3 columns → 6 vertical trim lines; 3 rows → 6 horizontal.
eq(atTop.length, 6, 'every vertical cut has a guide at the top paper edge');
eq(atBottom.length, 6, 'and one at the bottom paper edge');
eq(atLeft.length, 6, 'every horizontal cut has a guide at the left paper edge');
eq(atRight.length, 6, 'and one at the right paper edge');

/* ── Guides also appear mid-sheet, in the gutters ─────────────────────────── */
// 6 vertical lines × 2 row-gutters + 6 horizontal × 2 column-gutters = 24.
var gutterSegs = segs.filter(function (s) {
  var inMarginY = Math.min(s.y1, s.y2) === 0 || Math.max(s.y1, s.y2) === a4.sheetH;
  var inMarginX = Math.min(s.x1, s.x2) === 0 || Math.max(s.x1, s.x2) === a4.sheetW;
  return !inMarginY && !inMarginX;
});
eq(gutterSegs.length, 24, 'every cut is also marked inside each gutter it crosses');
// And those must be on bare paper: inset from the artwork by at least the bleed.
var onPaper = gutterSegs.every(function (s) {
  var len = Math.max(Math.abs(s.x2 - s.x1), Math.abs(s.y2 - s.y1));
  return len > 0 && len <= a4.gutter - 2 * a4.bleed + 0.001;
});
ok(onPaper, 'gutter guides sit in the bare-paper strip, not on a neighbour’s bleed');

/* ── A single-column plan has no gutters to mark ──────────────────────────── */
var one = CE.planImposition(1, { sheet: 'A4', trimW_mm: 120, trimH_mm: 170 });
eq(one.cols, 1, 'a 120mm card gives one column');
var oneSegs = CE.cutGuideSegments(one, {});
ok(oneSegs.length > 0, 'it still gets edge guides');
var oneCross = 0;
cardBoxes(one).forEach(function (b) { oneSegs.forEach(function (s) { if (entersBox(s, b)) oneCross++; }); });
eq(oneCross, 0, 'and still nothing across the card');

/* ── Landscape and other sheets stay coherent ─────────────────────────────── */
var land = CE.planImposition(9, { sheet: 'A4', orientation: 'landscape' });
eq(land.sheetW, 297, 'landscape swaps the sheet dimensions');
eq(land.sheetH, 210, 'landscape height is 210');
var landCross = 0;
var landSegs = CE.cutGuideSegments(land, {});
cardBoxes(land).forEach(function (b) { landSegs.forEach(function (s) { if (entersBox(s, b)) landCross++; }); });
eq(landCross, 0, 'no guide crosses a card in landscape either');

var a3 = CE.planImposition(1, { sheet: 'A3' });
ok(a3.perSheet >= 9, 'A3 fits at least as many as A4 (' + a3.perSheet + ')');


/* ── Printer-mark rendering, per the reference's prepress rules ────────────── */
console.log('▸ printer marks');
// 0.25pt is the Illustrator/InDesign default hairline.
ok(Math.abs(CE.MARK_W_MM - 0.25 / (72 / 25.4)) < 1e-9, 'mark weight is 0.25pt expressed in mm (' + CE.MARK_W_MM.toFixed(4) + ')');

/* Marks must image on ALL FOUR separations. RGB black separates to the K plate
   only, so on a four-colour job the C, M and Y plates would carry no marks and the
   operator could not check register against them. Registration colour is 100% of
   every plate; jsPDF expresses that as a four-channel stroke, which it writes as a
   PDF `K` operator. This asserts the mechanism the exporter relies on. */
var jsPDFmod = require('./vendor/jspdf.umd.min.js');
var jsPDF = jsPDFmod.jsPDF || jsPDFmod;
function strokeOps(setColour) {
  var d = new jsPDF({ unit: 'mm', format: [210, 297], compress: false });
  setColour(d);
  d.setLineWidth(CE.MARK_W_MM);
  d.line(10, 10, 30, 10);
  var raw = Buffer.from(d.output('datauristring').split(',')[1], 'base64').toString('latin1');
  return raw;
}
var reg = strokeOps(function (d) { d.setDrawColor(1, 1, 1, 1); });
ok(/1\.? 1\.? 1\.? 1\.? K/.test(reg), 'registration colour emits a CMYK K operator (all four plates)');
ok(!/ RG\b/.test(reg), 'and does NOT fall back to an RGB stroke');
var blk = strokeOps(function (d) { d.setDrawColor(0); });
ok(/ G\b|0 0 0 RG/.test(blk), 'for contrast, plain black emits a grey/RGB stroke — K plate only');
// The stream states widths in POINTS, so 0.0882mm is written as `0.25 w` — which
// is the clearest possible confirmation that the mark really is a 0.25pt hairline.
ok(/0\.25 w/.test(reg), 'the stream carries a 0.25pt line width');


console.log(fails ? '\n' + fails + ' FAILED' : '\nall passed');
process.exit(fails ? 1 : 0);
