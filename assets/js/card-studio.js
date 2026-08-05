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

  function opts() {
    return {
      dpi:    parseInt($('#dpi').value, 10) || 350,
      sheet:  $('#sheet').value || 'A4',
      intent: $('#intent').value || 'FOGRA39',
      backs:  $('#withBacks').checked,
      marks:  $('#withMarks').checked,
    };
  }

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
  async function shoot(faceEl, dpi, type) {
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
        trimW_mm: window.MamCardExport.CARD_W_MM,
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
    var list = selected();
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
          var shot = await shoot(face, opts().dpi, 'image/png');
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
        var front = await shoot($('.slot[data-face="front"]', cell), o.dpi, 'image/png');
        files.push({ name: base + '-front.png', bytes: window.MamCardExport.dataUrlToBytes(front.dataUrl) });
        if (o.backs) {
          var backEl = $('.slot[data-face="back"]', cell);
          if (backEl) {
            var back = await shoot(backEl, o.dpi, 'image/png');
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
      var fronts = [], backs = o.backs ? [] : null;
      for (var i = 0; i < list.length; i++) {
        if (cancelled) break;
        var cell = list[i];
        var meta = CARDS[parseInt(cell.getAttribute('data-i'), 10)] || {};
        fronts.push(await shoot($('.slot[data-face="front"]', cell), o.dpi, 'image/jpeg'));
        if (backs) {
          var be = $('.slot[data-face="back"]', cell);
          backs.push(be ? await shoot(be, o.dpi, 'image/jpeg') : null);
        }
        log(meta.display_id || meta.serial || ('card ' + (i + 1)));
        step(i + 1, list.length);
      }
      if (!fronts.length) { log('Nothing rendered.', true); $('#vcancel').textContent = 'Close'; return; }

      var built = await window.MamCardExport.buildImpositionPDF(fronts, {
        sheet: o.sheet, intent: o.intent, dpi: o.dpi, marks: o.marks,
        backs: backs, job: 'MAM Academy ID cards',
      });
      window.MamCardExport.download(built.bytes, 'mam-id-cards-print-' + stamp() + '.pdf', 'application/pdf');
      log(built.cols + '×' + built.rows + ' per sheet · ' + built.sheets + ' sheet' + (built.sheets === 1 ? '' : 's') +
          (backs ? ' + backs' : '') + ' · ' + built.intent.info);
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
