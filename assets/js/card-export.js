/* ═══════════════════════════════════════════════════════════════════════════
   MAM Academy — ID card export toolkit  (window.MamCardExport)

   Turns the rendered card faces (partials/id-card-face.php + id-card.css) into
   things a printer can use. Everything happens IN THE BROWSER — no card artwork,
   photograph or QR payload is ever uploaded.

   Loaded on demand by the Card Studio, never by a public page: the three vendored
   libraries total ~700KB and only an admin pressing "export" needs them.

     • html2canvas — rasterises the card DOM
     • jsPDF       — assembles the print-ready PDF
     • jsQR        — decodes the RENDERED QR to prove it survived rasterising

   ── The invariants that matter ────────────────────────────────────────────
   1. BLEED. Print shops trim with a tolerance of a fraction of a millimetre. Art
      that stops exactly at the trim line shows a white hairline down one edge
      when the guillotine drifts. Every exported face therefore carries 3mm of
      bleed on all four sides, produced by replicating the edge pixels outward
      (see addBleed — it neither rescales nor crops the artwork).
   2. A QR THAT SCANS. Rasterising, downscaling and JPEG-ing a QR can destroy it,
      and a batch of 200 unscannable cards is only discovered at the front desk.
      `verifyQR` decodes the exported pixels and reports the failures.
   3. NOTHING PAINTED HALF-WAY. html2canvas photographs whatever is on screen. A
      face snapshotted before its photo decodes or its QR paints exports blank,
      and it looks fine in the grid. Every export awaits both first.
   ═══════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  var MM_PER_INCH = 25.4;
  var PT_PER_MM = 72 / 25.4;
  /* Printer-mark weight: 0.25pt, the Illustrator/InDesign default hairline —
     thin enough not to read as a band, thick enough for a laser to hold. */
  var MARK_W_MM = 0.25 / PT_PER_MM;
  var BLEED_MM = 3;          // industry standard for small-format cards
  /* 60×85mm portrait — MUST match id-card.css's aspect-ratio (a mismatch silently
     stretches every card in the PDF). Chosen so THREE fit across and three down an
     A4 sheet — 9 per sheet. A7 (74×105) cannot: 3×74 = 222mm exceeds A4's 210mm
     width, so 9-up was arithmetically impossible at that size. 85/60 = 1.4167 is
     within 0.16% of the artwork's 1:√2, so the plate stretches imperceptibly, and
     60×85 drops into a standard 60×90mm badge holder. */
  var CARD_W_MM = 60;
  var CARD_H_MM = 85;

  /* A sheet an office printer or a print shop will actually accept. */
  var SHEETS = {
    A4:      { label: 'A4 (210 × 297 mm)',      w: 210,   h: 297 },
    A3:      { label: 'A3 (297 × 420 mm)',      w: 297,   h: 420 },
    SRA3:    { label: 'SRA3 (320 × 450 mm)',    w: 320,   h: 450 },
    LETTER:  { label: 'US Letter (216 × 279 mm)', w: 215.9, h: 279.4 },
  };

  /* Output intents. The RIP separates the embedded RGB artwork to CMYK against
     whichever of these the shop works to; naming it in the PDF is what stops a
     press operator guessing. */
  var INTENTS = {
    FOGRA39:    { id: 'FOGRA39',            info: 'Coated FOGRA39 (ISO 12647-2:2004)', cond: 'Offset, coated stock, 330% TAC' },
    FOGRA51:    { id: 'FOGRA51',            info: 'PSO Coated v3 (FOGRA51)',           cond: 'Offset, coated stock, 300% TAC' },
    GRACOL2013: { id: 'CGATS21-2-CRPC6',    info: 'GRACoL 2013 (CRPC6)',               cond: 'Offset, premium coated, 320% TAC' },
    SWOP2013:   { id: 'CGATS21-2-CRPC5',    info: 'SWOP 2013 (CRPC5)',                 cond: 'Web offset, coated, 300% TAC' },
  };

  var _deps = null;
  var _qr = null;

  function loadScript(src) {
    return new Promise(function (res, rej) {
      var existing = document.querySelector('script[data-src="' + src + '"]');
      if (existing) { existing.addEventListener('load', res); existing.addEventListener('error', rej); return; }
      var el = document.createElement('script');
      el.async = true;
      el.setAttribute('data-src', src);
      el.onload = res;
      el.onerror = function () { rej(new Error('Could not load ' + src)); };
      el.src = src;
      document.head.appendChild(el);
    });
  }

  async function ensureDeps() {
    if (_deps) return _deps;
    if (!root.html2canvas) await loadScript('/assets/js/vendor/html2canvas.min.js');
    if (!(root.jspdf && root.jspdf.jsPDF)) await loadScript('/assets/js/vendor/jspdf.umd.min.js');
    if (!root.html2canvas) throw new Error('html2canvas unavailable');
    if (!(root.jspdf && root.jspdf.jsPDF)) throw new Error('jsPDF unavailable');
    _deps = { html2canvas: root.html2canvas, jsPDF: root.jspdf.jsPDF };
    return _deps;
  }

  async function ensureQR() {
    if (_qr) return _qr;
    if (!root.jsQR) await loadScript('/assets/js/vendor/jsQR.min.js');
    _qr = root.jsQR || null;
    return _qr;
  }

  /* A promise that loses to a deadline rather than hanging the whole batch. */
  function withDeadline(p, ms, what) {
    return Promise.race([
      p,
      new Promise(function (_, rej) { setTimeout(function () { rej(new Error((what || 'step') + ' timed out')); }, ms); }),
    ]);
  }

  /* ── Waiting for the face to be genuinely paintable ───────────────────────
     Fonts first: an undecoded webfont paints in the fallback, and html2canvas
     photographs the fallback — the exported card then uses different metrics
     than the proof on screen. document.fonts.ready covers the whole document, so
     it is awaited once per export rather than per card. */
  async function fontsReady() {
    if (!document.fonts || !document.fonts.ready) return;
    try { await withDeadline(document.fonts.ready, 5000, 'font decode'); }
    catch (e) { /* proceed in the fallback rather than refuse to export */ }
  }

  /* Every <img> inside the node, decoded. Includes the photograph AND the QR
     image qrcode.min.js may have produced. */
  async function imagesReady(node) {
    var imgs = node.querySelectorAll('img');
    var waits = [];
    for (var i = 0; i < imgs.length; i++) {
      var im = imgs[i];
      if (im.complete && im.naturalWidth > 0) continue;
      waits.push(new Promise(function (res) {
        var done = false, fin = function () { if (!done) { done = true; res(); } };
        im.addEventListener('load', fin, { once: true });
        im.addEventListener('error', fin, { once: true });
        setTimeout(fin, 6000);
      }));
    }
    if (waits.length) await Promise.all(waits);
  }

  /**
   * The html2canvas scale that lands `cssW` CSS pixels on `mm` of paper at `dpi`.
   * Getting this wrong is the classic ID-card export bug: the PNG looks fine on
   * screen and prints soft, because the raster was sized in pixels rather than
   * derived from the physical trim size.
   */
  function scaleForDpi(cssW, mm, dpi) {
    var targetPx = (mm / MM_PER_INCH) * dpi;
    // No `Math.max(1, …)` floor here. A floor looks like a safety net against
    // soft output but it silently BREAKS the DPI contract: with the studio's
    // 560px export node, any DPI under ~263 clamps to scale 1 and the caller is
    // handed a raster at a resolution it did not ask for, while the PDF still
    // places it at the 60mm trim. Downscaling a large node is a clean resample;
    // honouring the request is what lets the verify pass test the real artwork.
    return targetPx / cssW;
  }

  /**
   * Grow a trim-sized raster into a bleed-sized one by REPLICATING the edge
   * pixels outward.
   *
   * Two other approaches were tried and are wrong for this card:
   *   • html2canvas's own x/y/width/height crop — those coordinates are
   *     DOCUMENT-relative, so a face parked off-canvas (which the studio does, to
   *     rasterise at a larger CSS width without the grid jumping) captures the
   *     wrong region entirely: correct pixel dimensions, displaced content.
   *   • Scaling the art up to cover the bleed box — that silently crops ~5% off
   *     every edge, which eats the expiry text on the right edge.
   * Edge replication neither moves nor rescales the artwork, and it is exact here
   * because all four edges of the face are flat colour (orange below, paper
   * above). The caller squares off the corner radius first — see `is-exporting`
   * in id-card.css — so there is no rounded corner to smear.
   */
  function addBleed(canvas, bleedPx, bg) {
    if (!bleedPx) return canvas;
    var cw = canvas.width, ch = canvas.height;
    var out = document.createElement('canvas');
    out.width = cw + 2 * bleedPx;
    out.height = ch + 2 * bleedPx;
    var g = out.getContext('2d');
    g.fillStyle = bg || '#ffffff';
    g.fillRect(0, 0, out.width, out.height);
    g.imageSmoothingEnabled = false;   // replication must not blur the edge colour

    /* Sample the strip a few pixels INSIDE the artwork, never the outermost row.
       html2canvas anti-aliases the element's own boundary against the canvas
       backdrop (sub-pixel rounding of width*scale, plus any corner radius), so the
       extreme row is a BLEND, not the artwork colour. Replicating it produced a
       uniform pale-peach frame on all four sides of every printed card — measured:
       row 0 came out rgb(243,242,239) and the bottom row rgb(250,239,230) where the
       artwork 3px in is pure cream rgb(253,252,249) and pure orange rgb(240,127,38).
       An inset of INSET px is invisible on the trimmed card (it is inside the bleed,
       which the guillotine removes) and gives a true edge colour. */
    var INSET = Math.min(3, Math.max(1, Math.floor(Math.min(cw, ch) / 200)));

    // The artwork itself, unscaled and centred.
    g.drawImage(canvas, bleedPx, bleedPx);
    // Four edges: a 1px strip taken INSET px in, stretched outward — and drawn to
    // cover the inset band too, so the blended outer row is overpainted.
    g.drawImage(canvas, 0, INSET, cw, 1, bleedPx, 0, cw, bleedPx + INSET);                                  // top
    g.drawImage(canvas, 0, ch - 1 - INSET, cw, 1, bleedPx, bleedPx + ch - INSET, cw, bleedPx + INSET);      // bottom
    g.drawImage(canvas, INSET, 0, 1, ch, 0, bleedPx, bleedPx + INSET, ch);                                  // left
    g.drawImage(canvas, cw - 1 - INSET, 0, 1, ch, bleedPx + cw - INSET, bleedPx, bleedPx + INSET, ch);      // right
    // Four corners, from the pixel INSET in on both axes.
    g.drawImage(canvas, INSET, INSET, 1, 1, 0, 0, bleedPx + INSET, bleedPx + INSET);
    g.drawImage(canvas, cw - 1 - INSET, INSET, 1, 1, bleedPx + cw - INSET, 0, bleedPx + INSET, bleedPx + INSET);
    g.drawImage(canvas, INSET, ch - 1 - INSET, 1, 1, 0, bleedPx + ch - INSET, bleedPx + INSET, bleedPx + INSET);
    g.drawImage(canvas, cw - 1 - INSET, ch - 1 - INSET, 1, 1, bleedPx + cw - INSET, bleedPx + ch - INSET, bleedPx + INSET, bleedPx + INSET);
    return out;
  }

  /**
   * Rasterise one card face WITH bleed.
   * @returns {Promise<{dataUrl:string,wPx:number,hPx:number,bleedPx:number,trimWPx:number,trimHPx:number}>}
   */
  async function renderFace(node, opts) {
    opts = opts || {};
    var d = await ensureDeps();
    var dpi = opts.dpi || 350;
    var bleed = opts.bleed_mm == null ? BLEED_MM : opts.bleed_mm;
    var trimW = opts.trimW_mm || CARD_W_MM;

    var cssW = node.offsetWidth;
    if (!cssW) throw new Error('card face has no width (is it hidden?)');

    // Scale so the TRIM width lands exactly on the requested DPI.
    var scale = scaleForDpi(cssW, trimW, dpi);
    var bleedPx = Math.round((bleed / MM_PER_INCH) * dpi);

    var trimCanvas = await withDeadline(d.html2canvas(node, {
      scale: scale,
      backgroundColor: opts.bg || null,
      useCORS: true,
      allowTaint: false,
      logging: false,
      imageTimeout: 8000,
    }), opts.timeout || 30000, 'rasterise');

    var canvas = addBleed(trimCanvas, bleedPx, opts.bg || '#ffffff');
    var type = opts.type || 'image/jpeg';
    var q = opts.quality == null ? 0.95 : opts.quality;
    return {
      dataUrl: canvas.toDataURL(type, q),
      wPx: canvas.width,
      hPx: canvas.height,
      bleedPx: bleedPx,
      trimWPx: trimCanvas.width,
      trimHPx: trimCanvas.height,
    };
  }

  function dataUrlToBytes(dataUrl) {
    var i = dataUrl.indexOf(',');
    var bin = atob(dataUrl.slice(i + 1));
    var out = new Uint8Array(bin.length);
    for (var k = 0; k < bin.length; k++) out[k] = bin.charCodeAt(k);
    return out;
  }

  /**
   * Decode the QR out of an EXPORTED face and compare it to what should be there.
   * The whole point is to test the pixels that will be printed, not the payload we
   * intended — so it runs on the export's own data URL.
   *
   * @returns {Promise<{ok:boolean, found:?string, reason:?string}>}
   */
  async function verifyQR(dataUrl, expected) {
    var lib = await ensureQR();
    if (!lib) return { ok: false, found: null, reason: 'decoder unavailable' };
    var img = new Image();
    var loaded = new Promise(function (res, rej) {
      img.onload = res;
      img.onerror = function () { rej(new Error('export did not decode')); };
    });
    img.src = dataUrl;
    try { await withDeadline(loaded, 8000, 'QR image decode'); }
    catch (e) { return { ok: false, found: null, reason: e.message }; }

    var c = document.createElement('canvas');
    c.width = img.naturalWidth; c.height = img.naturalHeight;
    var ctx = c.getContext('2d');
    ctx.drawImage(img, 0, 0);
    var px = ctx.getImageData(0, 0, c.width, c.height);
    var res = lib(px.data, px.width, px.height);
    if (!res || !res.data) return { ok: false, found: null, reason: 'no QR detected in the exported artwork' };
    if (expected && res.data !== expected) return { ok: false, found: res.data, reason: 'decoded a different payload' };
    return { ok: true, found: res.data, reason: null };
  }

  /* ── ZIP (store, no compression) ──────────────────────────────────────────
     PNG and JPEG are already compressed; deflating them again buys ~1% for a lot
     of CPU on a 200-card batch. A stored ZIP is a few hundred lines and avoids a
     fourth vendored library. */
  function crc32(buf) {
    var table = crc32.table;
    if (!table) {
      table = crc32.table = new Int32Array(256);
      for (var n = 0; n < 256; n++) {
        var c = n;
        for (var k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
        table[n] = c;
      }
    }
    var crc = -1;
    for (var i = 0; i < buf.length; i++) crc = (crc >>> 8) ^ table[(crc ^ buf[i]) & 0xFF];
    return (crc ^ -1) >>> 0;
  }

  function zipStore(files) {
    var enc = new TextEncoder();
    var chunks = [], central = [], offset = 0;

    function u16(v) { return [v & 0xFF, (v >>> 8) & 0xFF]; }
    function u32(v) { return [v & 0xFF, (v >>> 8) & 0xFF, (v >>> 16) & 0xFF, (v >>> 24) & 0xFF]; }

    files.forEach(function (f) {
      var name = enc.encode(f.name);
      var data = f.bytes;
      var crc = crc32(data);
      var local = [].concat(
        u32(0x04034b50), u16(20), u16(0), u16(0), u16(0), u16(0),
        u32(crc), u32(data.length), u32(data.length), u16(name.length), u16(0)
      );
      chunks.push(new Uint8Array(local), name, data);
      central.push({ name: name, crc: crc, size: data.length, offset: offset });
      offset += local.length + name.length + data.length;
    });

    var dirStart = offset, dirChunks = [];
    central.forEach(function (e) {
      var rec = [].concat(
        u32(0x02014b50), u16(20), u16(20), u16(0), u16(0), u16(0), u16(0),
        u32(e.crc), u32(e.size), u32(e.size),
        u16(e.name.length), u16(0), u16(0), u16(0), u16(0), u32(0), u32(e.offset)
      );
      dirChunks.push(new Uint8Array(rec), e.name);
      offset += rec.length + e.name.length;
    });
    var end = new Uint8Array([].concat(
      u32(0x06054b50), u16(0), u16(0), u16(central.length), u16(central.length),
      u32(offset - dirStart), u32(dirStart), u16(0)
    ));

    var all = chunks.concat(dirChunks, [end]);
    var total = all.reduce(function (n, c) { return n + c.length; }, 0);
    var out = new Uint8Array(total), p = 0;
    all.forEach(function (c) { out.set(c, p); p += c.length; });
    return out;
  }

  /**
   * Plan the sheet layout WITHOUT rendering anything.
   *
   * Pure arithmetic, so the studio can show "2 × 2 = 4 per A4 · 3 sheets" live as
   * the operator changes the trim, margin or gutter — and refuse the job before
   * spending two minutes rasterising when the trim simply does not fit the sheet.
   *
   * @returns {{cols:number,rows:number,perSheet:number,sheets:number,fits:boolean,
   *            sheetW:number,sheetH:number,gridW:number,gridH:number,
   *            originX:number,originY:number,reason:?string}}
   */
  function planImposition(count, opts) {
    opts = opts || {};
    var sheet = SHEETS[opts.sheet] || SHEETS.A4;
    var sheetW = sheet.w, sheetH = sheet.h;
    if (opts.orientation === 'landscape') { var t = sheetW; sheetW = sheetH; sheetH = t; }

    var trimW = opts.trimW_mm || CARD_W_MM;
    var trimH = opts.trimH_mm || CARD_H_MM;
    var bleed = opts.bleed_mm == null ? BLEED_MM : opts.bleed_mm;
    var margin = opts.margin_mm == null ? 6 : opts.margin_mm;
    /* Gutter = 2×bleed + 2mm. The 2×bleed part is the floor: any less and one
       card's bleed prints over the next card's face. The extra 2mm is what makes
       the cut guides usable — at exactly 2×bleed the neighbouring bleeds MEET and
       there is no bare paper anywhere between cards, so a mid-sheet guide would be
       drawn on top of artwork and be all but invisible. 2mm of white down the
       middle of every gutter gives the blade something to line up against. */
    var gutter = opts.gutter_mm == null ? 2 * bleed + 2 : opts.gutter_mm;

    var cellW = trimW + gutter, cellH = trimH + gutter;
    var availW = sheetW - 2 * margin + gutter;
    var availH = sheetH - 2 * margin + gutter;
    var cols = Math.floor(availW / cellW);
    var rows = Math.floor(availH / cellH);

    var reason = null;
    if (cols < 1 || rows < 1) {
      reason = 'A ' + trimW + '×' + trimH + ' mm card with ' + margin + ' mm margins does not fit ' +
               (opts.sheet || 'A4') + ' ' + (opts.orientation || 'portrait') +
               ' — reduce the trim or the margin, or choose a larger sheet.';
      cols = Math.max(cols, 0); rows = Math.max(rows, 0);
    }
    var perSheet = cols * rows;
    var gridW = cols > 0 ? cols * cellW - gutter : 0;
    var gridH = rows > 0 ? rows * cellH - gutter : 0;

    return {
      cols: cols, rows: rows, perSheet: perSheet,
      sheets: perSheet > 0 ? Math.ceil(count / perSheet) : 0,
      fits: perSheet > 0,
      sheetW: sheetW, sheetH: sheetH,
      gridW: gridW, gridH: gridH,
      originX: (sheetW - gridW) / 2,
      originY: (sheetH - gridH) / 2,
      trimW: trimW, trimH: trimH, bleed: bleed, margin: margin, gutter: gutter,
      reason: reason,
    };
  }

  /* ── Print-ready imposition PDF ───────────────────────────────────────────
     Lays the faces out N-up at an exact trim size with bleed, crop marks and a
     named output intent. Fronts and backs go on interleaved sheets in the same
     grid position so a duplex run registers, and long-edge flipping is handled by
     mirroring each back row horizontally.  */
  /**
   * Where the cutting guides go, in mm. PURE geometry so it can be reasoned about
   * and unit-tested (assets/js/card-export.selftest.cjs) without a PDF engine.
   *
   * The old marks were four short ticks per card, sitting in the bleed. Three
   * problems with that for anyone actually cutting a sheet:
   *   • Adjacent cards' ticks landed in the same gutter and overlapped, so a
   *     3×3 sheet had a confusing double set of marks between every pair.
   *   • Nothing reached the paper edge, so there was no way to line a guillotine
   *     blade up with a cut before committing to it.
   *   • With gutter == 2×bleed the ticks were printed ON the neighbour's bleed
   *     artwork, which is dark orange here — a black hairline on orange is close
   *     to invisible.
   *
   * What this produces instead, for every trim line on the sheet:
   *   • a guide running from the PAPER EDGE inward to just short of the grid, at
   *     both ends of the line — align the blade to two marks and the cut is true;
   *   • a guide through every gutter it crosses, inset by the bleed so it lands on
   *     bare paper rather than on artwork (this is what the extra 2mm of default
   *     gutter buys).
   * Nothing is ever drawn across a card. Every segment is either in a margin or in
   * the white centre of a gutter.
   *
   * @returns {Array<{x1:number,y1:number,x2:number,y2:number}>}
   */
  function cutGuideSegments(plan, opts) {
    opts = opts || {};
    var gap = opts.gap_mm == null ? 1 : opts.gap_mm;   // clear space beside the artwork
    var bleed = plan.bleed;
    var segs = [];
    if (!plan.cols || !plan.rows) return segs;

    var cellW = plan.trimW + plan.gutter, cellH = plan.trimH + plan.gutter;
    var gridL = plan.originX, gridR = plan.originX + plan.gridW;
    var gridT = plan.originY, gridB = plan.originY + plan.gridH;

    // Every vertical trim line (left and right edge of each column)…
    var xs = [], ys = [], c, r;
    for (c = 0; c < plan.cols; c++) {
      xs.push(plan.originX + c * cellW);
      xs.push(plan.originX + c * cellW + plan.trimW);
    }
    for (r = 0; r < plan.rows; r++) {
      ys.push(plan.originY + r * cellH);
      ys.push(plan.originY + r * cellH + plan.trimH);
    }

    function push(x1, y1, x2, y2) {
      // Skip anything that rounds away to nothing — a zero-length line is a dot of
      // ink in the margin and nothing else.
      if (Math.abs(x2 - x1) < 0.2 && Math.abs(y2 - y1) < 0.2) return;
      segs.push({ x1: x1, y1: y1, x2: x2, y2: y2 });
    }

    xs.forEach(function (x) {
      // Top and bottom margins, running to the paper edge.
      push(x, 0, x, Math.max(0, gridT - gap));
      push(x, Math.min(plan.sheetH, gridB + gap), x, plan.sheetH);
      // Through each gutter BETWEEN rows, inset by the bleed so it is on paper.
      for (r = 0; r < plan.rows - 1; r++) {
        var yb = plan.originY + r * cellH + plan.trimH;   // this row's bottom trim
        var yt = yb + plan.gutter;                        // next row's top trim
        push(x, yb + bleed, x, yt - bleed);
      }
    });

    ys.forEach(function (y) {
      // Left and right margins, running to the paper edge.
      push(0, y, Math.max(0, gridL - gap), y);
      push(Math.min(plan.sheetW, gridR + gap), y, plan.sheetW, y);
      // Through each gutter BETWEEN columns.
      for (c = 0; c < plan.cols - 1; c++) {
        var xr = plan.originX + c * cellW + plan.trimW;
        var xl = xr + plan.gutter;
        push(xr + bleed, y, xl - bleed, y);
      }
    });

    return segs;
  }

  function buildPDF(faces, opts) {
    opts = opts || {};
    var jsPDF = _deps.jsPDF;
    var intent = INTENTS[opts.intent] || INTENTS.FOGRA39;
    var marks = opts.marks !== false;
    var backs = opts.backs || null;

    // One planner for the UI readout and for the real thing, so what the operator
    // was shown before pressing export is exactly what gets imposed.
    var plan = planImposition(faces.length, opts);
    if (!plan.fits) throw new Error(plan.reason || 'the card does not fit the sheet');

    var trimW = plan.trimW, trimH = plan.trimH, bleed = plan.bleed;
    var cols = plan.cols, rows = plan.rows, perSheet = plan.perSheet;
    var cellW = trimW + plan.gutter, cellH = trimH + plan.gutter;
    var originX = plan.originX, originY = plan.originY;
    // 'long'  — flip about the long edge (work-and-turn): mirror COLUMNS.
    // 'short' — flip about the short edge: mirror ROWS.
    // 'none'  — single-sided runs, or a duplex press that needs no mirroring.
    var flip = opts.flip || 'long';

    /* compress:false is a debugging affordance — an uncompressed content stream can
       be read straight out of the bytes, which is how the mark colour and hairline
       weight are verified. Production always compresses. */
    var doc = new jsPDF({ unit: 'mm', format: [plan.sheetW, plan.sheetH], compress: opts.compress !== false });

    /* Cut guides + a registration target, drawn once per sheet. 0.1mm is a
       hairline that a laser reproduces cleanly without becoming a visible band. */
    /* Registration colour — 100% of every plate — NOT RGB black. A black mark
       separates to the K plate ONLY, so on a four-colour job the cyan, magenta and
       yellow plates carry no marks at all and the operator cannot check register
       against them. jsPDF emits a CMYK stroke (`1 1 1 1 K`) when setDrawColor is
       given four channels. (A true /Separation "All" colorant is the textbook
       answer; 400% CMYK is the practical approximation jsPDF can express, and it
       images on all four separations, which is the point.) */
    function registrationStroke() {
      doc.setLineWidth(MARK_W_MM);
      doc.setDrawColor(1, 1, 1, 1);
    }

    function drawGuides() {
      registrationStroke();
      var segs = cutGuideSegments(plan, {});
      for (var i = 0; i < segs.length; i++) {
        doc.line(segs[i].x1, segs[i].y1, segs[i].x2, segs[i].y2);
      }
    }

    /* A crosshair-in-a-circle in each sheet corner. Identical on the front and the
       back sheet, so an operator can hold the pair to the light and see instantly
       whether the duplex run is in register — before cutting 9 cards in half. */
    function drawRegistration() {
      var inset = Math.min(plan.margin, 8) / 2 + 2;
      var rad = 1.6;
      registrationStroke();
      [[inset, inset], [plan.sheetW - inset, inset],
       [inset, plan.sheetH - inset], [plan.sheetW - inset, plan.sheetH - inset]]
      .forEach(function (p) {
        doc.circle(p[0], p[1], rad);
        doc.line(p[0] - rad - 1.2, p[1], p[0] + rad + 1.2, p[1]);
        doc.line(p[0], p[1] - rad - 1.2, p[0], p[1] + rad + 1.2);
      });
    }

    /* MediaBox / TrimBox / BleedBox. These are the first thing an imposition RIP
       reads, and jsPDF writes only a MediaBox on its own. An IMPOSED sheet is a
       press sheet: the sheet itself is what gets trimmed, and the individual card
       trims are carried by the cut guides — so TrimBox and BleedBox are the sheet.
       Stating them explicitly stops a RIP guessing. Coordinates are PDF points with
       the origin at the bottom-left, hence the y flip. */
    function setPageBoxes() {
      try {
        var info = doc.internal.getPageInfo(doc.internal.getNumberOfPages());
        if (!info || !info.pageContext) return;
        var box = function (x0, y0, x1, y1) {
          return {
            bottomLeftX: x0 * PT_PER_MM, bottomLeftY: (plan.sheetH - y1) * PT_PER_MM,
            topRightX:   x1 * PT_PER_MM, topRightY:   (plan.sheetH - y0) * PT_PER_MM,
          };
        };
        info.pageContext.mediaBox = box(0, 0, plan.sheetW, plan.sheetH);
        info.pageContext.trimBox  = box(0, 0, plan.sheetW, plan.sheetH);
        info.pageContext.bleedBox = box(0, 0, plan.sheetW, plan.sheetH);
      } catch (e) { /* metadata only — never fail an export over it */ }
    }

    function placeSheet(list, isBack, sheetIndex) {
      for (var i = 0; i < list.length; i++) {
        var f = list[i];
        if (!f) continue;
        var col = i % cols, rowN = Math.floor(i / cols);
        // Mirror the backs so the chosen duplex flip registers. Getting this wrong
        // prints every back on the wrong card — and it is only discovered after the
        // run, so the mode is explicit rather than assumed.
        if (isBack) {
          if (flip === 'long')  col = cols - 1 - col;
          if (flip === 'short') rowN = rows - 1 - rowN;
        }
        var trimX = originX + col * cellW;
        var trimY = originY + rowN * cellH;
        // The image carries bleed, so it is drawn larger than the trim box and
        // offset outward by exactly the bleed.
        doc.addImage(f.dataUrl, f.dataUrl.indexOf('image/png') > 0 ? 'PNG' : 'JPEG',
          trimX - bleed, trimY - bleed, trimW + 2 * bleed, trimH + 2 * bleed, undefined, 'FAST');

      }
      if (marks) { drawGuides(); drawRegistration(); }
      setPageBoxes();
      // Slug: what this sheet is, so a stack of proofs stays identifiable.
      doc.setFontSize(6);
      doc.setTextColor(120);
      doc.text(
        (opts.job || 'MAM Academy ID cards') + '  ·  ' + (isBack ? 'BACK' : 'FRONT') +
        '  ·  sheet ' + sheetIndex + '  ·  ' + trimW + '×' + trimH + 'mm trim, ' + bleed + 'mm bleed  ·  ' +
        cols + '×' + rows + ' up  ·  ' + (opts.dpi || 350) + ' DPI  ·  ' + intent.info +
        (isBack ? '  ·  flip: ' + flip + ' edge' : ''),
        plan.margin, plan.sheetH - 4
      );
    }

    var sheetNo = 0;
    for (var start = 0; start < faces.length; start += perSheet) {
      var frontSlice = faces.slice(start, start + perSheet);
      sheetNo++;
      if (sheetNo > 1) doc.addPage([plan.sheetW, plan.sheetH]);
      placeSheet(frontSlice, false, sheetNo);
      if (backs) {
        doc.addPage([plan.sheetW, plan.sheetH]);
        placeSheet(backs.slice(start, start + perSheet), true, sheetNo);
      }
    }

    doc.setProperties({
      title: (opts.job || 'MAM Academy ID cards') + ' — print-ready',
      subject: trimW + '×' + trimH + ' mm trim, ' + bleed + ' mm bleed, ' + intent.info,
      keywords: 'prepress, imposed, bleed, crop marks, ' + intent.id + ', ' + intent.cond,
      creator: 'MAM Academy Card Studio',
    });

    return {
      bytes: new Uint8Array(doc.output('arraybuffer')),
      cols: cols, rows: rows, perSheet: perSheet, sheets: sheetNo,
      intent: intent, plan: plan, flip: flip,
    };
  }

  /* Rotate a rasterised face 180°. Pre-rotating the pixels keeps the PDF
     placement maths identical for every card (jsPDF's own addImage rotation turns
     about the anchor point, so it also needs a compensating offset — easy to get
     subtly wrong, and wrong here means a whole run of upside-down backs). */
  function rotate180(shot) {
    return new Promise(function (resolve) {
      var img = new Image();
      img.onload = function () {
        var c = document.createElement('canvas');
        c.width = img.naturalWidth; c.height = img.naturalHeight;
        var g = c.getContext('2d');
        g.translate(c.width, c.height);
        g.rotate(Math.PI);
        g.drawImage(img, 0, 0);
        var isPng = shot.dataUrl.indexOf('image/png') > 0;
        resolve(Object.assign({}, shot, {
          dataUrl: c.toDataURL(isPng ? 'image/png' : 'image/jpeg', 0.95),
        }));
      };
      // If it will not decode there is nothing to rotate; ship it as-is rather
      // than losing the card from the sheet.
      img.onerror = function () { resolve(shot); };
      img.src = shot.dataUrl;
    });
  }

  async function buildImpositionPDF(faces, opts) {
    await ensureDeps();
    opts = opts || {};
    /* A SHORT-edge (work-and-tumble) flip turns the sheet about its short axis, so
       the card that was at the head of the sheet comes back at the foot: mirroring
       rows is not enough, the artwork itself must be turned 180° or every back
       prints upside down relative to its front. Long-edge (work-and-turn) keeps
       the art upright, which is why it is the default. */
    if (opts.backs && opts.flip === 'short') {
      var turned = [];
      for (var i = 0; i < opts.backs.length; i++) {
        turned.push(opts.backs[i] ? await rotate180(opts.backs[i]) : null);
      }
      opts = Object.assign({}, opts, { backs: turned });
    }
    return buildPDF(faces, opts);
  }

  /* Blob + object URL: works for files far larger than a data: URL allows. */
  function download(bytes, filename, mime) {
    var blob = (bytes instanceof Uint8Array || bytes instanceof ArrayBuffer)
      ? new Blob([bytes], { type: mime || 'application/octet-stream' })
      : bytes;
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { document.body.removeChild(a); URL.revokeObjectURL(url); }, 1500);
  }

  root.MamCardExport = {
    ensureDeps: ensureDeps,
    ensureQR: ensureQR,
    fontsReady: fontsReady,
    imagesReady: imagesReady,
    renderFace: renderFace,
    addBleed: addBleed,
    scaleForDpi: scaleForDpi,
    verifyQR: verifyQR,
    zipStore: zipStore,
    buildImpositionPDF: buildImpositionPDF,
    planImposition: planImposition,
    cutGuideSegments: cutGuideSegments,
    rotate180: rotate180,
    dataUrlToBytes: dataUrlToBytes,
    download: download,
    SHEETS: SHEETS,
    INTENTS: INTENTS,
    MM_PER_INCH: MM_PER_INCH,
    BLEED_MM: BLEED_MM,
    MARK_W_MM: MARK_W_MM,
    CARD_W_MM: CARD_W_MM,
    CARD_H_MM: CARD_H_MM,
  };
})(typeof window !== 'undefined' ? window : this);
