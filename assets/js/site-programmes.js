/*
 * Programmes & fees — the fee estimator (handoff §6). The rules are the handoff's
 * (fee-model.js); every NUMBER is the server's, from window.MAM_SITE.fees, which
 * partials/site/shell.php fills from the functions that charge an application
 * (validation.php: level bases, the compulsory-course fee, the Grand discount, the
 * online surcharge, the flat intensives, registration) and from the cached
 * exchange rates. So the estimate and the charge cannot drift, and a fee changed
 * in the admin settings moves this page the same day.
 *
 * Rounding follows the server: whole dollars, rounded once at the end.
 */
(function () {
  'use strict';
  var form = document.querySelector('form[data-estimator]');
  var receipt = document.querySelector('aside[data-receipt]');
  var F = window.MAM_SITE && window.MAM_SITE.fees;
  if (!form || !receipt || !F) return;
  var $ = function (s, el) { return (el || document).querySelector(s); };
  var $$ = function (s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); };

  var PROGS = ['standard', 'certified', 'grand', 'crash'];
  var SERVER_KEY = { standard: 'standard', certified: 'certified-musician', grand: 'grand-musician', crash: 'crash' };
  var CURS = ['USD', 'NGN', 'GBP'];
  var SYM = { USD: '$', NGN: '₦', GBP: '£' };
  var s = { prog: 'certified', level: 0, mode: 'onsite', extras: 0, cur: 'USD' };

  function quote() {
    var B = F.levels[s.level].base, half = B / 2, crash = s.prog === 'crash';
    var ex = (s.prog === 'standard' || s.prog === 'grand') ? s.extras : 0, sub, lines = [];
    var plural = function (n) { return n + ' additional course' + (n > 1 ? 's' : ''); };
    if (crash) { sub = F.crash; lines.push(['Crash Programme (flat)', F.crash]); }
    else if (s.prog === 'standard') { sub = B + half * ex; lines.push(['Level base fee', B]); if (ex) lines.push([plural(ex), half * ex]); }
    else if (s.prog === 'certified') { sub = B + F.compulsory; lines.push(['Level base fee', B], ['Four compulsory courses', F.compulsory]); }
    else {
      var pre = 3 * B + half * ex; sub = pre * (1 - F.grandPct / 100);
      lines.push(['All three expressions (3 × base)', 3 * B]); if (ex) lines.push([plural(ex), half * ex]);
      lines.push(['Grand saving (' + pct(F.grandPct) + ')', -(pre - sub)]);
    }
    if (!crash && s.mode === 'online') { lines.push(['Online (+' + pct(F.onlinePct) + ')', sub * F.onlinePct / 100]); sub *= 1 + F.onlinePct / 100; }
    return { total: sub, lines: lines, per: crash ? 'for 6 weeks' : 'per term' };
  }
  function pct(n) { return (Math.round(n * 10) / 10) + '%'; }
  function fmt(usd) {
    var rate = (F.rates && F.rates[s.cur]) || 1, v = Math.round(Math.abs(usd) * rate);
    return (usd < 0 ? '−' : '') + SYM[s.cur] + v.toLocaleString('en-US');
  }

  var SEG = 'min-height: 52px; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 6px 8px; border: 0; border-radius: 9px; font: inherit; font-size: 15px; cursor: pointer; transition: background .2s, box-shadow .2s; ';
  var SEG_ON = SEG + 'background: #FFFFFF; color: #211A13; font-weight: 600; box-shadow: 0 1px 3px rgba(33,26,19,.18)';
  var SEG_OFF = SEG + 'background: transparent; color: #574B40';
  var CUR = 'min-height: 32px; padding: 0 12px; border: 0; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; ';
  var CUR_ON = CUR + 'background: #FCF8F3; color: #211A13', CUR_OFF = CUR + 'background: transparent; color: #D8CEC1';

  function radios(act, on, onStyle, offStyle) {
    $$('[data-act="' + act + '"]').forEach(function (b) {
      var i = +b.getAttribute('data-i'), checked = on(i);
      b.setAttribute('aria-checked', checked ? 'true' : 'false');
      b.tabIndex = checked ? 0 : -1;
      b.setAttribute('style', checked ? onStyle : offStyle);
    });
  }
  function render() {
    radios('prog', function (i) { return PROGS[i] === s.prog; }, SEG_ON, SEG_OFF);
    radios('level', function (i) { return i === s.level; }, SEG_ON, SEG_OFF);
    radios('mode', function (i) { return (i ? 'online' : 'onsite') === s.mode; }, SEG_ON, SEG_OFF);
    radios('cur', function (i) { return CURS[i] === s.cur; }, CUR_ON, CUR_OFF);
    var lv = $('[data-est="level"]', form); if (lv) lv.hidden = s.prog === 'crash';
    var exs = $('[data-est="extras"]', form); if (exs) exs.hidden = !(s.prog === 'standard' || s.prog === 'grand');
    var n = $('[data-est="extras-n"]', form); if (n) n.textContent = String(s.extras);
    var q = quote();
    $('[data-est="total"]', receipt).textContent = fmt(q.total);
    $('[data-est="per"]', receipt).textContent = q.per;
    var dl = $('[data-est="lines"]', receipt);
    dl.innerHTML = q.lines.map(function (l) {
      return '<div style="display: flex; justify-content: space-between; gap: 12px"><dt style="color: #D8CEC1"></dt><dd style="margin: 0"></dd></div>';
    }).join('');
    $$('div', dl).forEach(function (row, i) { row.children[0].textContent = q.lines[i][0]; row.children[1].textContent = fmt(q.lines[i][1]); });
    $('[data-est="reg"]', receipt).textContent = fmt(F.registration);
    $('[data-est="note"]', receipt).textContent = s.cur === 'USD' ? 'Pay in USD, NGN or GBP.' : 'Converted at an indicative rate; final amount set at checkout.';
    // "Apply with this choice" carries the choice into the application form's own prefill.
    var apply = $('a[href^="/apply"]', receipt);
    if (apply) {
      var qs = 'pathway=' + SERVER_KEY[s.prog] + '&mode=' + s.mode + (s.prog === 'crash' ? '' : '&level=' + F.levels[s.level].id);
      apply.setAttribute('href', '/apply?' + qs);
    }
  }

  function pick(act, value) { s[act] = value; if (act === 'prog' && !(value === 'standard' || value === 'grand')) s.extras = 0; render(); }
  // The estimator's currency is the site's one choice (site.js, the footer's switch).
  var site = window.MAM_SITE && window.MAM_SITE.currency;
  if (site && CURS.indexOf(site()) >= 0) s.cur = site();
  var pickSelf = pick;
  pick = function (act, value) { pickSelf(act, value); if (act === 'cur' && site) site(value); };
  document.addEventListener('mam:currency', function (e) {
    var c = e.detail && e.detail.currency; if (CURS.indexOf(c) >= 0 && c !== s.cur) { s.cur = c; render(); }
  });
  form.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-act]'); if (!b) return;
    var act = b.getAttribute('data-act'), i = +b.getAttribute('data-i');
    if (act === 'prog') pick('prog', PROGS[i]);
    else if (act === 'level') pick('level', i);
    else if (act === 'mode') pick('mode', i ? 'online' : 'onsite');
    else if (act === 'extras-inc') { s.extras = Math.min(4, s.extras + 1); render(); }
    else if (act === 'extras-dec') { s.extras = Math.max(0, s.extras - 1); render(); }
  });
  receipt.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-act="cur"]'); if (b) pick('cur', CURS[+b.getAttribute('data-i')]);
  });
  // A radio group moves with the arrow keys, as a radio group does.
  document.addEventListener('keydown', function (e) {
    var b = e.target.closest && e.target.closest('[role="radio"][data-act]'); if (!b) return;
    var k = e.key; if (k !== 'ArrowRight' && k !== 'ArrowLeft' && k !== 'ArrowDown' && k !== 'ArrowUp') return;
    var group = $$('[role="radio"][data-act="' + b.getAttribute('data-act') + '"]');
    var next = group[(group.indexOf(b) + (k === 'ArrowRight' || k === 'ArrowDown' ? 1 : -1) + group.length) % group.length];
    e.preventDefault(); next.click(); next.focus();
  });
  // "Estimate" under each programme column picks it and brings the estimator into view.
  $$('[data-act="prog-estimate"]').forEach(function (b, i) {
    b.addEventListener('click', function () {
      pick('prog', PROGS[i]);
      var el = document.getElementById('fees'); if (el) el.scrollIntoView({ behavior: 'smooth' });
    });
  });
  render();
})();
