/*
 * Compare — MAM Match, the four-question recommender (compare.php #mamMatch).
 * Rule-based and on the device: no API, no email. The programmes come from the
 * page (#cpData, the same list the table is drawn from) and every price from
 * window.MAM_SITE.fees.from, the figures the server charges, shown in the
 * visitor's chosen currency (window.MAM_SITE.currency, repainted on mam:currency).
 * The scoring is the old compare.js's, unchanged.
 */
(function () {
  'use strict';
  var mount = document.getElementById('mamMatch');
  var dataEl = document.getElementById('cpData');
  if (!mount || !dataEl) return;
  var PROGS;
  try { PROGS = JSON.parse(dataEl.textContent || '[]'); } catch (e) { return; }
  if (!PROGS.length) return;
  var F = (window.MAM_SITE && window.MAM_SITE.fees) || {};
  var SYM = { USD: '$', NGN: '₦', GBP: '£' };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function cur() {
    var c = (window.MAM_SITE && window.MAM_SITE.currency && window.MAM_SITE.currency()) || 'USD';
    return (SYM[c] && F.rates && F.rates[c]) ? c : 'USD';
  }
  function price(id) {
    var usd = Number((F.from || {})[id] || 0);
    if (!usd) return '';
    var c = cur(), r = (F.rates && F.rates[c]) || 1;
    return SYM[c] + Math.round(usd * r).toLocaleString('en-US');
  }

  var QUESTIONS = [
    { key: 'level', q: 'Where are you right now?', options: [
      { v: 'none', label: 'Complete beginner' }, { v: 'some', label: 'Some basics' },
      { v: 'intermediate', label: 'Intermediate' }, { v: 'advanced', label: 'Advanced / pro' }] },
    { key: 'goal', q: 'What’s your main goal?', options: [
      { v: 'worship', label: 'Lead worship' }, { v: 'perform', label: 'Perform & gig' }, { v: 'exam', label: 'Graded certification' },
      { v: 'produce', label: 'Produce & create' }, { v: 'lead', label: 'Teach & direct' }, { v: 'culture', label: 'Shape culture' }] },
    { key: 'pace', q: 'How do you want to learn?', options: [
      { v: 'intensive', label: 'Short intensive' }, { v: 'ongoing', label: 'Ongoing weekly terms' }] },
    { key: 'budget', q: 'What’s your budget comfort?', options: [
      { v: 1, label: 'Keep it low' }, { v: 2, label: 'Standard' }, { v: 3, label: 'Invest fully' }] }
  ];

  function score(p, a) {
    var s = 0;
    if (a.level && p.levels.indexOf(a.level) >= 0) s += 3;
    if (a.goal && p.goals.indexOf(a.goal) >= 0) s += 3;
    if (a.pace) { if (p.pace === a.pace) s += 2; else if (p.pace === 'both') s += 1; }
    if (a.budget) s += 2 - Math.abs(p.budget - a.budget);
    return s;
  }
  function reasonText(a) {
    var lvl = { none: 'starting from scratch', some: 'building on some basics', intermediate: 'at an intermediate level', advanced: 'already advanced' };
    var goal = { worship: 'lead worship', perform: 'perform & gig', exam: 'earn graded certification', produce: 'produce & create', lead: 'teach & direct', culture: 'shape culture' };
    var bits = [];
    if (lvl[a.level]) bits.push('you’re ' + lvl[a.level]);
    if (goal[a.goal]) bits.push('you want to ' + goal[a.goal]);
    if (a.pace === 'intensive') bits.push('you prefer a short intensive');
    if (a.pace === 'ongoing') bits.push('you prefer ongoing terms');
    return bits.length ? 'Because ' + bits.join(', ') + '.' : 'A strong all-round fit for you.';
  }

  var EYEBROW = 'margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208';
  var OPT = 'display: inline-flex; align-items: center; min-height: 48px; padding: 0 20px; border-radius: 999px; border: 1px solid #D6CCBE; background: #FFFFFF; color: #211A13; font: inherit; font-size: 15px; font-weight: 600; cursor: pointer';
  var LINK = 'display: inline-flex; align-items: center; min-height: 44px; padding: 0; border: 0; background: none; color: #8A5208; font: inherit; font-size: 15px; font-weight: 600; cursor: pointer';
  var answers = {}, step = 0, top = null;

  function render(focus) {
    if (step < QUESTIONS.length) {
      var Q = QUESTIONS[step];
      mount.innerHTML =
        '<div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px">' +
          '<p style="' + EYEBROW + '">MAM Match</p>' +
          '<p style="margin: 0; font-size: 14px; color: #574B40">Question ' + (step + 1) + ' of ' + QUESTIONS.length + '</p>' +
        '</div>' +
        '<h3 id="matchQ" tabindex="-1" style="margin: 0 0 18px; font-family: \'Cormorant Garamond\', Georgia, serif; font-weight: 500; font-size: 32px; line-height: 1.1; outline: none">' + esc(Q.q) + '</h3>' +
        '<div role="group" aria-labelledby="matchQ" style="display: flex; flex-wrap: wrap; gap: 8px">' +
          Q.options.map(function (o) { return '<button type="button" class="cp-opt" data-val="' + esc(String(o.v)) + '" aria-pressed="' + (String(answers[Q.key]) === String(o.v) ? 'true' : 'false') + '" style="' + OPT + '">' + esc(o.label) + '</button>'; }).join('') +
        '</div>' +
        (step > 0 ? '<button type="button" data-back style="' + LINK + '; margin-top: 12px">← Back</button>'
                  : '<p style="margin: 14px 0 0; font-size: 14px; color: #574B40">Four quick taps. No email needed.</p>');
      Array.prototype.forEach.call(mount.querySelectorAll('.cp-opt'), function (b) {
        b.addEventListener('click', function () {
          var raw = b.getAttribute('data-val');
          answers[Q.key] = Q.key === 'budget' ? parseInt(raw, 10) : raw;
          step++; render(true);
        });
      });
      var back = mount.querySelector('[data-back]');
      if (back) back.addEventListener('click', function () { step--; render(true); });
    } else {
      top = PROGS.slice().sort(function (x, y) { return score(y, answers) - score(x, answers); })[0];
      mount.innerHTML =
        '<p style="' + EYEBROW + '; margin-bottom: 14px">MAM Match · your result</p>' +
        '<h3 id="matchQ" tabindex="-1" style="margin: 0; font-family: \'Cormorant Garamond\', Georgia, serif; font-weight: 500; font-size: 32px; line-height: 1.1; outline: none">Best fit: ' + esc(top.label) + ' <span style="font-size: 18px; color: #574B40">· ' + esc(top.name) + '</span></h3>' +
        '<p style="margin: 10px 0 0; font-family: \'Cormorant Garamond\', Georgia, serif; font-weight: 600; font-size: 32px" data-match-price>' + esc(price(top.id)) + '<span style="font-family: Inter, system-ui, sans-serif; font-weight: 400; font-size: 14px; color: #574B40">' + esc(top.unit) + '</span></p>' +
        '<p style="margin: 8px 0 18px; font-size: 17px; line-height: 1.6; color: #3E342B">' + esc(reasonText(answers)) + '</p>' +
        '<div style="display: flex; flex-wrap: wrap; align-items: center; gap: 12px 20px">' +
          '<a href="/apply?pathway=' + encodeURIComponent(top.id) + '" class="btn" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 24px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Apply for ' + esc(top.label) + '</a>' +
          '<button type="button" data-compare style="' + LINK + '">See it in the comparison</button>' +
          '<button type="button" data-restart style="' + LINK + '">Start over</button>' +
        '</div>';
      mount.querySelector('[data-restart]').addEventListener('click', function () { answers = {}; step = 0; top = null; render(true); });
      mount.querySelector('[data-compare]').addEventListener('click', function () {
        var card = document.getElementById('pc-' + top.id);
        var target = (card && card.offsetParent !== null) ? card : document.getElementById('table');
        if (target) target.scrollIntoView({ behavior: 'smooth', block: card && target === card ? 'center' : 'start' });
        if (card && target === card) { card.classList.add('cp-flash'); setTimeout(function () { card.classList.remove('cp-flash'); }, 1800); }
      });
    }
    if (focus) { var h = document.getElementById('matchQ'); if (h) h.focus(); }
  }

  document.addEventListener('mam:currency', function () {
    var el = mount.querySelector('[data-match-price]');
    if (el && top) el.firstChild.nodeValue = price(top.id);
  });
  mount.hidden = false;
  render(false);
})();
