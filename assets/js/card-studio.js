/* ═══════════════════════════════════════════════════════════════════════════
   MAM Academy — ID Card Studio controller
   Drives /card-studio: selection, zoom, and the four outputs (verify, PNG zip,
   browser print, print-ready PDF). All rendering is local — see card-export.js.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  var grid  = $('#grid');
  var veil  = $('#veil');
  var CARDS = window.MAM_CARDS || [];
  var cancelled = false;

  /* ── Selection ─────────────────────────────────────────────────────────── */
  function cells() { return $$('.cell', grid); }
  function selected() { return cells().filter(function (c) { return $('.tick', c).checked; }); }

  function syncCell(c) {
    var on = $('.tick', c).checked;
    c.classList.toggle('sel', on);
    c.classList.toggle('unsel', !on);   // drives @media print
  }
  function syncAll() { cells().forEach(syncCell); refreshCount(); }

  function refreshCount() {
    var n = selected().length;
    ['btnPdf', 'btnPng', 'btnVerify'].forEach(function (id) {
      var b = document.getElementById(id);
      if (b) b.disabled = n === 0;
    });
    var b = $('#btnPrint'); if (b) b.disabled = n === 0;
    refreshLayout();
  }

  grid && grid.addEventListener('change', function (e) {
    if (e.target && e.target.classList.contains('tick')) syncCell(e.target.closest('.cell')), refreshCount();
  });
  $('#selAll')  && $('#selAll').addEventListener('click', function () { cells().forEach(function (c) { $('.tick', c).checked = true; }); syncAll(); });
  $('#selNone') && $('#selNone').addEventListener('click', function () { cells().forEach(function (c) { $('.tick', c).checked = false; }); syncAll(); });
  $('#selNoPhoto') && $('#selNoPhoto').addEventListener('click', function () {
    cells().forEach(function (c) { $('.tick', c).checked = c.getAttribute('data-nophoto') === '1'; });
    syncAll();
  });

  /* ── Zoom ──────────────────────────────────────────────────────────────── */
  $('#zoom') && $('#zoom').addEventListener('change', function () {
    grid.style.setProperty('--cardw', this.value + 'px');
  });
  $('#showBacks') && $('#showBacks').addEventListener('change', function () {
    grid.classList.toggle('show-backs', this.checked);
  });

  /* ── Progress overlay ──────────────────────────────────────────────────── */
  function open(title, sub) {
    cancelled = false;
    $('#vtitle').textContent = title;
    $('#vsub').textContent = sub || '';
    $('#vbar').style.width = '0%';
    $('#vlog').textContent = '';
    veil.classList.add('on');
  }
  function close() { veil.classList.remove('on'); }
  function step(done, total) { $('#vbar').style.width = Math.round(done / Math.max(1, total) * 100) + '%'; }
  function log(msg, bad) {
    var d = document.createElement('div');
    d.textContent = msg;
    if (bad) d.style.color = '#F87171';
    $('#vlog').appendChild(d);
    $('#vlog').scrollTop = $('#vlog').scrollHeight;
  }
  $('#vcancel') && $('#vcancel').addEventListener('click', function () { cancelled = true; log('Cancelling…'); });

  /* The artwork's proportion. The background is a traced raster plate, so the trim
     HEIGHT is derived from the width rather than typed: forcing a different
     proportion would stretch the plate, and nobody would notice until the cards
     came back from the printer. */
  function cardRatio() {
    var CE = window.MamCardExport;
    return CE ? (CE.CARD_H_MM / CE.CARD_W_MM) : (105 / 74);
  }

  function num(sel, dflt) {
    var el = $(sel);
    if (!el) return dflt;
    var v = parseFloat(el.value);
    return isFinite(v) ? v : dflt;
  }

  function opts() {
    var w = num('#trimW', 74);
    return {
      dpi:         parseInt($('#dpi').value, 10) || 350,
      sheet:       $('#sheet').value || 'A4',
      intent:      $('#intent').value || 'FOGRA39',
      orientation: $('#orient') ? $('#orient').value : 'portrait',
      trimW_mm:    w,
      trimH_mm:    Math.round(w * cardRatio() * 10) / 10,
      margin_mm:   num('#marginMm', 10),
      gutter_mm:   num('#gutterMm', 6),
      flip:        $('#flip') ? $('#flip').value : 'long',
      backs:       $('#withBacks').checked,
      marks:       $('#withMarks').checked,
      verify:      $('#verifyOnExport') ? $('#verifyOnExport').checked : false,
    };
  }

  /* ── Live sheet plan ─────────────────────────────────────────────────────
     Shows the imposition BEFORE an export runs, so a trim that cannot fit the
     sheet is caught in a glance rather than after two minutes of rasterising. */
  function refreshLayout() {
    var box = $('#layout');
    if (!box) return;
    var o = opts();
    // Keep the derived height visible so it can be quoted to the printer.
    if ($('#trimH')) $('#trimH').value = o.trimH_mm;

    var n = selected().length;
    var CE = window.MamCardExport;
    if (!CE || !CE.planImposition) {
      box.textContent = n + ' selected  ·  ' + o.trimW_mm + ' x ' + o.trimH_mm + ' mm trim';
      return;
    }
    var p = CE.planImposition(n, o);
    box.classList.toggle('bad', !p.fits);
    if (!p.fits) { box.textContent = '\u26a0 ' + p.reason; return; }

    // Built from DOM nodes rather than an HTML string. Every value here is a
    // number or a fixed <select> value, but assembling markup by concatenation is
    // the habit that eventually meets a holder's name — so it isn't started.
    var sides = o.backs ? 2 : 1;
    box.textContent = '';
    var seg = function () {
      var parts = Array.prototype.slice.call(arguments);
      if (box.childNodes.length) box.appendChild(document.createTextNode('  \u00b7  '));
      parts.forEach(function (part) {
        if (typeof part === 'string') { box.appendChild(document.createTextNode(part)); return; }
        var el = document.createElement(part.tag || 'b');
        if (part.cls) el.className = part.cls;
        if (part.color) el.style.color = part.color;
        el.textContent = part.text;
        box.appendChild(el);
      });
    };
    seg({ text: String(n) }, ' card' + (n === 1 ? '' : 's') + ' selected');
    seg({ text: o.trimW_mm + ' \u00d7 ' + o.trimH_mm + ' mm' }, ' trim + ' + p.bleed + ' mm bleed');
    seg({ text: p.cols + ' \u00d7 ' + p.rows }, ' = ', { text: String(p.perSheet) },
        ' per ' + o.sheet + ' ' + o.orientation);
    seg({ text: String(p.sheets * sides) }, ' page' + (p.sheets * sides === 1 ? '' : 's') +
        (o.backs ? ' (' + p.sheets + ' front + ' + p.sheets + ' back)' : ''));
    seg('grid ', { tag: 'span', cls: 'mono', text: p.gridW.toFixed(1) + ' \u00d7 ' + p.gridH.toFixed(1) + ' mm' });
    if (o.backs && o.flip !== 'none') seg('backs mirrored for a ', { text: o.flip + ' edge' }, ' flip');
    if (o.gutter_mm < 2 * p.bleed) {
      seg({ tag: 'span', color: '#F0C36B',
            text: '\u26a0 gutter under ' + (2 * p.bleed) + ' mm \u2014 neighbouring bleed will overlap' });
    }
  }

  ['#trimW', '#marginMm', '#gutterMm', '#sheet', '#orient', '#flip', '#withBacks', '#dpi', '#intent', '#withMarks']
    .forEach(function (sel) {
      var el = $(sel);
      if (!el) return;
      el.addEventListener('input', refreshLayout);
      el.addEventListener('change', refreshLayout);
    });

  /* Filename-safe slug of the holder, so a zip of 200 files is navigable. */
  function slug(s) {
    return String(s || 'card').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40) || 'card';
  }
  function stamp() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  /**
   * Rasterise one face. The node is temporarily promoted to a large CSS width and
   * moved off-canvas: html2canvas upscaling a 210px node by 3.5× renders soft
   * text, and `display:none` would give it no layout to capture at all.
   */
  async function shoot(faceEl, dpi, type, trimMm) {
    var card = $('.mam-card', faceEl);
    if (!card) throw new Error('no card face in slot');
    card.classList.add('is-exporting');
    try {
      // The QR must be painted AND decoded before the snapshot, or the export
      // carries an empty white box that still looks plausible on the sheet.
      if (window.MamCardQR) await window.MamCardQR.ready(card);
      await window.MamCardExport.imagesReady(card);
      return await window.MamCardExport.renderFace(card, {
        dpi: dpi,
        // The operator's trim, so the raster resolution matches the paper size
        // actually being printed rather than the library's default.
        trimW_mm: trimMm || window.MamCardExport.CARD_W_MM,
        type: type || 'image/jpeg',
        quality: 0.95,
        bg: '#ffffff',
      });
    } finally {
      card.classList.remove('is-exporting');
    }
  }

  async function prepare() {
    await window.MamCardExport.ensureDeps();
    await window.MamCardExport.fontsReady();
  }

  /* ── Verify: decode the QR out of the EXPORTED pixels ──────────────────── */
  $('#btnVerify') && $('#btnVerify').addEventListener('click', async function () {
    var list = selected(), o = opts();
    open('Verifying QR codes', 'Decoding each card exactly as it will be printed.');
    var bad = 0;
    try {
      await prepare();
      await window.MamCardExport.ensureQR();
      for (var i = 0; i < list.length; i++) {
        if (cancelled) break;
        var cell = list[i];
        var idx = parseInt(cell.getAttribute('data-i'), 10);
        var meta = CARDS[idx] || {};
        var flag = $('.qrflag', cell);
        try {
          // Verify at the DPI that will actually PRINT. A cheaper fixed DPI would
          // be faster but would prove nothing about the artwork being shipped —
          // decodability is resolution-dependent, so testing 200 DPI and printing
          // 350 (or vice versa) is a verification that can pass on a bad batch.
          var face = $('.slot[data-face="front"]', cell);
          var shot = await shoot(face, o.dpi, 'image/png', o.trimW_mm);
          var res = await window.MamCardExport.verifyQR(shot.dataUrl, meta.qr || '');
          if (flag) {
            flag.hidden = false;
            flag.textContent = res.ok ? 'QR ok' : 'QR FAILED';
            flag.className = 'flag qrflag ' + (res.ok ? 'ok' : 'bad');
          }
          if (!res.ok) { bad++; log((meta.display_id || meta.serial) + ' — ' + res.reason, true); }
        } catch (e) {
          bad++;
          if (flag) { flag.hidden = false; flag.textContent = 'QR FAILED'; flag.className = 'flag qrflag bad'; }
          log((meta.display_id || meta.serial) + ' — ' + e.message, true);
        }
        step(i + 1, list.length);
      }
      log(bad ? bad + ' of ' + list.length + ' failed — do not print these.' : 'All ' + list.length + ' scanned clean.');
      $('#vtitle').textContent = bad ? 'Verification found problems' : 'All QR codes verified';
      $('#vcancel').textContent = 'Close';
    } catch (e) {
      log(e.message, true);
      $('#vtitle').textContent = 'Verification failed';
      $('#vcancel').textContent = 'Close';
    }
  });

  /* ── PNG zip ───────────────────────────────────────────────────────────── */
  $('#btnPng') && $('#btnPng').addEventListener('click', async function () {
    var list = selected(), o = opts();
    open('Rendering PNGs', list.length + ' card' + (list.length === 1 ? '' : 's') + ' at ' + o.dpi + ' DPI with 3mm bleed.');
    try {
      await prepare();
      var files = [];
      for (var i = 0; i < list.length; i++) {
        if (cancelled) break;
        var cell = list[i];
        var meta = CARDS[parseInt(cell.getAttribute('data-i'), 10)] || {};
        var base = slug(meta.name) + '-' + slug(meta.display_id || meta.serial);
        var front = await shoot($('.slot[data-face="front"]', cell), o.dpi, 'image/png', o.trimW_mm);
        files.push({ name: base + '-front.png', bytes: window.MamCardExport.dataUrlToBytes(front.dataUrl) });
        if (o.backs) {
          var backEl = $('.slot[data-face="back"]', cell);
          if (backEl) {
            var back = await shoot(backEl, o.dpi, 'image/png', o.trimW_mm);
            files.push({ name: base + '-back.png', bytes: window.MamCardExport.dataUrlToBytes(back.dataUrl) });
          }
        }
        log(base);
        step(i + 1, list.length);
      }
      if (!files.length) { log('Nothing rendered.', true); $('#vcancel').textContent = 'Close'; return; }
      var zip = window.MamCardExport.zipStore(files);
      window.MamCardExport.download(zip, 'mam-id-cards-' + stamp() + '.zip', 'application/zip');
      $('#vtitle').textContent = 'Downloaded ' + files.length + ' PNG' + (files.length === 1 ? '' : 's');
      $('#vcancel').textContent = 'Close';
    } catch (e) {
      log(e.message, true);
      $('#vtitle').textContent = 'PNG export failed';
      $('#vcancel').textContent = 'Close';
    }
  });

  /* ── Print-ready imposition PDF ────────────────────────────────────────── */
  $('#btnPdf') && $('#btnPdf').addEventListener('click', async function () {
    var list = selected(), o = opts();
    open('Building print-ready PDF', 'Imposing on ' + o.sheet + ' at ' + o.dpi + ' DPI — 3mm bleed, crop marks, ' + o.intent + '.');
    try {
      await prepare();
      var fronts = [], backs = o.backs ? [] : null, badQr = 0;
      if (o.verify) await window.MamCardExport.ensureQR();
      for (var i = 0; i < list.length; i++) {
        if (cancelled) break;
        var cell = list[i];
        var meta = CARDS[parseInt(cell.getAttribute('data-i'), 10)] || {};
        var frontShot = await shoot($('.slot[data-face="front"]', cell), o.dpi, 'image/jpeg', o.trimW_mm);
        fronts.push(frontShot);
        // Verifying inside the export run is the point: it tests the very bytes
        // being embedded, and a failure is reported before the file is handed over.
        if (o.verify) {
          var vq = $('[data-qr]', cell);
          var vr = await window.MamCardExport.verifyQR(frontShot.dataUrl, vq ? vq.getAttribute('data-qr') : '');
          if (!vr.ok) { badQr++; log('QR FAILED: ' + (meta.display_id || meta.serial) + ' — ' + vr.reason, true); }
        }
        if (backs) {
          var be = $('.slot[data-face="back"]', cell);
          backs.push(be ? await shoot(be, o.dpi, 'image/jpeg', o.trimW_mm) : null);
        }
        log(meta.display_id || meta.serial || ('card ' + (i + 1)));
        step(i + 1, list.length);
      }
      if (!fronts.length) { log('Nothing rendered.', true); $('#vcancel').textContent = 'Close'; return; }

      var built = await window.MamCardExport.buildImpositionPDF(fronts, {
        sheet: o.sheet, intent: o.intent, dpi: o.dpi, marks: o.marks,
        orientation: o.orientation, trimW_mm: o.trimW_mm, trimH_mm: o.trimH_mm,
        margin_mm: o.margin_mm, gutter_mm: o.gutter_mm, flip: o.flip,
        backs: backs, job: 'MAM Academy ID cards',
      });
      window.MamCardExport.download(built.bytes, 'mam-id-cards-print-' + stamp() + '.pdf', 'application/pdf');
      if (o.verify) {
        log(badQr ? '\u26a0 ' + badQr + ' card(s) have an unscannable QR — do NOT print this file.'
                  : 'QR verified on all ' + fronts.length + ' exported fronts.', badQr > 0);
      }
      log(built.cols + '\u00d7' + built.rows + ' per sheet \u00b7 ' + built.sheets + ' sheet' +
          (built.sheets === 1 ? '' : 's') +
          (backs ? ' + backs (flip: ' + built.flip + ' edge)' : '') + ' \u00b7 ' +
          o.trimW_mm + '\u00d7' + o.trimH_mm + 'mm \u00b7 ' + built.intent.info);
      $('#vtitle').textContent = 'Print-ready PDF downloaded';
      $('#vcancel').textContent = 'Close';
    } catch (e) {
      log(e.message, true);
      $('#vtitle').textContent = 'PDF export failed';
      $('#vcancel').textContent = 'Close';
    }
  });

  /* ── Browser print ─────────────────────────────────────────────────────── */
  $('#btnPrint') && $('#btnPrint').addEventListener('click', async function () {
    // Paint every QR first: window.print() does not wait for async work, and an
    // unpainted card prints as a blank white box.
    if (window.MamCardQR) await window.MamCardQR.ready(document);
    window.print();
  });

  syncAll();
})();
