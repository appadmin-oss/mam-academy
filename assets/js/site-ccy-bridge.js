/*
 * The footer currency switch on a redesigned page that also loads config.js.
 *
 * site.js owns the footer's switch only where config.js is absent (it steps aside
 * when window.MAM_CCY exists, because config.js's MAM_CCY is then the source of
 * truth). On the older design main.js painted it; the redesigned pages that need
 * config.js for MAM_API (summer, book, tuition, status) load neither main.js nor
 * site.js's painter, so without this the switch would do nothing. This is the
 * part of main.js's initCurrency those pages need, and no more: the buttons,
 * [data-amt-usd] amounts, and the 'mam.currency' choice every page reads.
 */
(function () {
  'use strict';
  var CCY = window.MAM_CCY;
  if (!CCY) return;
  var btns = Array.prototype.slice.call(document.querySelectorAll('.cur-sw [data-currency]'));
  function store(v) { try { localStorage.setItem('mam.currency', v); } catch (e) { /* private mode */ } }
  function saved() { try { return localStorage.getItem('mam.currency'); } catch (e) { return null; } }
  var current = CCY.detect();

  function paint(cur, persist) {
    if (!CCY.known(cur)) cur = 'USD';
    current = cur;
    btns.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-currency') === cur ? 'true' : 'false'); });
    Array.prototype.forEach.call(document.querySelectorAll('[data-amt-usd]'), function (el) {
      el.textContent = CCY.format(parseFloat(el.getAttribute('data-amt-usd')), cur);
    });
    if (persist) {
      store(cur);
      try { document.dispatchEvent(new CustomEvent('mam:currency', { detail: { currency: cur } })); } catch (e) { /* old browser */ }
    }
  }
  btns.forEach(function (b) { b.addEventListener('click', function () { paint(b.getAttribute('data-currency'), true); }); });
  paint(current, false);
  document.addEventListener('mam:config', function () { paint(current, false); });
  document.addEventListener('mam:currency', function () { if (!saved()) paint(CCY.detect(), false); });
})();
