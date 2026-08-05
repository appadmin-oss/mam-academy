/* ═══════════════════════════════════════════════════════════════════════════
   MAM Academy — ID card QR painter
   Walks every `[data-qr]` box emitted by partials/id-card-face.php and paints
   the signed attendance payload into it with the vendored qrcode.min.js.

   Shared by /card/{serial} and /card-studio so one card and a sheet of two
   hundred are painted by the same code — a studio proof can't disagree with the
   card the student gets.

   Exposes window.MamCardQR = { paintAll, paint, ready }.
   Requires assets/js/vendor/qrcode.min.js to be loaded first.
   ═══════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  /* The rendered module count for a MAMC1 payload at error-correction M. The QR
     is ~30% of the card width, so at a 300px card the box is ~90px: below about
     2px per module a phone camera struggles under classroom lighting. We paint
     at a generous multiple and let CSS scale it down, which keeps the exported
     raster crisp at 350 DPI (where the same box is ~440px). */
  var PAINT_PX = 512;

  function paint(el) {
    if (!el || el.getAttribute('data-qr-done') === '1') return false;
    var payload = el.getAttribute('data-qr') || '';
    if (!payload) return false;

    if (typeof root.QRCode === 'undefined') {
      // No library: show the payload as text rather than an empty white box, so
      // the card is still manually verifiable and the failure is obvious.
      el.textContent = payload;
      el.style.cssText = 'font-size:5px;line-height:1.2;word-break:break-all;color:#9C845A;overflow:hidden';
      el.setAttribute('data-qr-done', 'fallback');
      return false;
    }

    el.textContent = '';   // clear any previous paint (no markup involved)
    new root.QRCode(el, {
      text: payload,
      width: PAINT_PX,
      height: PAINT_PX,
      colorDark: '#1A120B',
      // Opaque light modules, NOT transparent: a transparent quiet zone over the
      // orange face destroys the contrast a scanner needs.
      colorLight: '#FDFCF9',
      correctLevel: root.QRCode.CorrectLevel.M,
    });

    // qrcode.min.js paints a <canvas> AND emits an <img> of the same data, hiding
    // whichever it isn't using. Size EVERY candidate (not just the first match —
    // querySelector returns the canvas, which is the hidden one) and never touch
    // `display`, or the hidden twin gets un-hidden and doubles the box height.
    var painted = el.querySelectorAll('canvas, img');
    for (var i = 0; i < painted.length; i++) {
      painted[i].style.width = '100%';
      painted[i].style.height = '100%';
    }
    el.setAttribute('data-qr-done', '1');
    return true;
  }

  function paintAll(scope) {
    var boxes = (scope || document).querySelectorAll('[data-qr]:not([data-qr-done])');
    var n = 0;
    for (var i = 0; i < boxes.length; i++) { if (paint(boxes[i])) n++; }
    return n;
  }

  /* Resolves once every QR in `scope` is painted AND its <img> has decoded.
     The exporter must await this: html2canvas photographs whatever is on screen,
     so a card snapshotted mid-paint exports with an empty QR box — a card that
     looks perfect and scans as nothing. */
  function ready(scope) {
    paintAll(scope);
    var imgs = (scope || document).querySelectorAll('[data-qr] img');
    var waits = [];
    for (var i = 0; i < imgs.length; i++) {
      var im = imgs[i];
      if (im.complete && im.naturalWidth > 0) continue;
      waits.push(new Promise(function (res) {
        var done = false;
        var fin = function () { if (!done) { done = true; res(); } };
        im.addEventListener('load', fin, { once: true });
        im.addEventListener('error', fin, { once: true });
        // Never hang a batch export on one stuck decode.
        setTimeout(fin, 4000);
      }));
    }
    return Promise.all(waits);
  }

  root.MamCardQR = { paint: paint, paintAll: paintAll, ready: ready, PAINT_PX: PAINT_PX };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { paintAll(document); });
  } else {
    paintAll(document);
  }
})(typeof window !== 'undefined' ? window : this);
