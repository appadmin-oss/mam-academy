/*
 * Pathways — the live tuition estimator (pathways.php #estimator). Ported from the
 * old page's inline script onto the 2026-11 shell: the rules are the same as
 * mam_compute_application_price(), and every NUMBER is the server's, from
 * window.MAM_SITE.fees (filled by partials/site/shell.php from the functions that
 * charge an application). The currency follows the site-wide choice
 * (window.MAM_SITE.currency, the "mam.currency" key) and repaints on mam:currency.
 * Whole dollars, rounded once at the end, as the server rounds.
 */
(function () {
  'use strict';
  var calc = document.getElementById('calc');
  var F = window.MAM_SITE && window.MAM_SITE.fees;
  if (!calc || !F) return;
  var $ = function (id) { return document.getElementById(id); };
  var $$ = function (s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); };

  var SYM = { USD: '$', NGN: '₦', GBP: '£' };
  var LEVEL_IX = { precert: 0, cert: 1, prediploma: 2, diploma: 3 };
  var state = { prog: 'certified-musician', level: 'precert', mode: 'onsite', extra: 0, reg: true };

  function cur() {
    var c = (window.MAM_SITE.currency && window.MAM_SITE.currency()) || 'USD';
    return (SYM[c] && F.rates && F.rates[c]) ? c : 'USD';
  }
  function fmt(usd) {
    var c = cur(), r = (F.rates && F.rates[c]) || 1;
    return SYM[c] + Math.round(usd * r).toLocaleString('en-US');
  }
  function isFlat() { return state.prog === 'crash'; }
  function extraCap() { return state.prog === 'grand-musician' ? 6 : 4; }

  function compute() {
    var reg = state.reg ? Number(F.registration || 0) : 0;
    if (isFlat()) return Number(F.crash || 0) + reg;
    var B = Number((F.levels[LEVEL_IX[state.level]] || {}).base || 0), ex = state.extra, sub;
    if (state.prog === 'grand-musician') sub = (3 * B + 0.5 * B * ex) * (1 - Number(F.grandPct || 0) / 100);
    else sub = B + (state.prog === 'certified-musician' ? Number(F.compulsory || 0) : 0) + 0.5 * B * ex;
    if (state.mode === 'online') sub = sub * (1 + Number(F.onlinePct || 0) / 100);
    return Math.round(sub) + reg;
  }

  var NAMES = { 'standard': 'Standard', 'certified-musician': 'Certified', 'grand-musician': 'Grand', 'crash': 'Crash' };
  var LVLS = { precert: 'Pre-Certificate', cert: 'Certificate', prediploma: 'Pre-Diploma', diploma: 'Diploma' };

  function render() {
    var flat = isFlat();
    $('calcLevelField').classList.toggle('pw-off', flat);
    $('calcExtraField').classList.toggle('pw-off', flat);
    if (state.extra > extraCap()) state.extra = extraCap();
    $('calcExtraVal').textContent = state.extra;
    $('calcExtraHint').textContent = flat ? 'Not available'
      : (state.prog === 'standard' ? 'Beyond your primary course' : 'Beyond your primary + 4 compulsory');

    // A plain summary of the selection, not a breakdown of how the fee is worked out.
    var bits = [NAMES[state.prog] || state.prog];
    if (!flat) bits.push(LVLS[state.level] || state.level);
    bits.push(state.mode === 'online' ? 'Online' : 'Onsite');
    if (!flat && state.extra > 0) bits.push(state.extra + ' extra course' + (state.extra === 1 ? '' : 's'));
    if (state.reg) bits.push('Registration included');
    var ul = $('calcLines'); ul.textContent = '';
    bits.forEach(function (b) {
      var li = document.createElement('li');
      var tick = document.createElement('span'); tick.setAttribute('aria-hidden', 'true'); tick.textContent = '✓ ';
      li.appendChild(tick); li.appendChild(document.createTextNode(b)); ul.appendChild(li);
    });
    $('calcTotal').textContent = fmt(compute());

    var q = '/apply?pathway=' + encodeURIComponent(state.prog) + '&mode=' + encodeURIComponent(state.mode);
    if (!flat) q += '&level=' + encodeURIComponent(state.level);
    $('calcApply').setAttribute('href', q);

    $('calcFine').textContent = flat ? 'The Crash Programme is a flat fee with no levels.'
      : 'Estimated total — confirmed at checkout. Registration covers your student file, ID card & a 30-minute MAM Auditer assessment.';
  }

  function bindSeg(id, key) {
    var wrap = $(id); if (!wrap) return;
    wrap.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('button[data-v]'); if (!b) return;
      state[key] = b.getAttribute('data-v');
      $$('button[data-v]', wrap).forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      render();
    });
  }
  bindSeg('calcProg', 'prog');
  bindSeg('calcLevel', 'level');
  bindSeg('calcMode', 'mode');
  $('calcPlus').addEventListener('click', function () { if (state.extra < extraCap()) { state.extra++; render(); } });
  $('calcMinus').addEventListener('click', function () { if (state.extra > 0) { state.extra--; render(); } });
  $('calcReg').addEventListener('change', function () { state.reg = this.checked; render(); });
  document.addEventListener('mam:currency', render);
  render();
})();
