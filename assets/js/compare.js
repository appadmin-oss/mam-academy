/* ============================================================
   MAM Academy — Compare page enhancements
   ------------------------------------------------------------
   Two progressive enhancements driven by ONE data model:
     1. MAM Match — a guided, on-device recommender (rule-based,
        no API, works offline) that scores the six programmes
        against four quick answers and surfaces the best fit + an
        alternative, with a plain-English reason and an apply CTA.
     2. Mobile head-to-head compare — pick any two programmes and
        read their values stacked per feature. Replaces the
        horizontal-scroll table on small screens (table stays for
        desktop / no-JS / SEO).
   The desktop HTML <table> remains the canonical content; this
   data mirrors it for the interactive widgets. Keep in sync.
   ============================================================ */
(function () {
  'use strict';

  var PROGRAMMES = [
    { id: 'certified-musician', label: 'Certified Musician', name: 'One tradition', trinity: 'All levels · Pre-Cert → Diploma', price: 230, unit: '/programme',
      tag: 'Most chosen', accent: 'gold', featured: true, levels: ['none', 'some', 'intermediate', 'advanced'], goals: ['perform', 'worship', 'exam'], pace: 'ongoing', budget: 2,
      f: { bestFor: 'Focused mastery in one tradition', length: '12 weeks · 1hr/wk major', modes: 'Both', auditer: '✓', trinityEq: 'All four levels', instrumental: '✓', performance: '✓✓', rhythm: '✓', metamusic: '✓', certificate: '✓ Certified Musician', registration: '+$5', graduation: 'Included' } },
    { id: 'grand-musician', label: 'Grand Musician', name: 'All three traditions', trinity: 'All levels · flagship', price: 310, unit: '/programme',
      tag: 'Flagship', accent: 'red', premium: true, levels: ['some', 'intermediate', 'advanced'], goals: ['perform', 'lead', 'exam', 'produce', 'culture'], pace: 'ongoing', budget: 3,
      f: { bestFor: 'African + Classical + Contemporary mastery', length: '12 weeks · three majors', modes: 'Both', auditer: '✓', trinityEq: 'All four levels', instrumental: '✓', performance: '✓✓✓', rhythm: '✓', metamusic: '✓', certificate: '✓ Grand Musician', registration: '+$5', graduation: 'Included' } },
    { id: 'crash', label: 'Crash', name: 'Intensive', trinity: '6 weeks · any school', price: 40, unit: '/course',
      tag: '', accent: 'gold', levels: ['none', 'some', 'intermediate', 'advanced'], goals: ['perform', 'produce', 'worship'], pace: 'intensive', budget: 1,
      f: { bestFor: 'Quick wins · summer · busy pros', length: '6 weeks accelerated', modes: 'Both', auditer: 'Recommended', trinityEq: '—', instrumental: 'Per chosen course', performance: 'Per chosen course', rhythm: 'Per chosen course', metamusic: 'Per chosen course', certificate: 'Certificate of completion', registration: '+$5', graduation: '—' } },
    { id: 'meta-order', label: 'MetaMusic Order', name: 'Civilization Architect', trinity: '12 weeks · post-graduate', price: 60, unit: '/12wk',
      tag: 'Premium', accent: 'red', premium: true, levels: ['advanced'], goals: ['culture', 'produce', 'lead'], pace: 'intensive', budget: 3,
      f: { bestFor: 'Producers, performers, cultural leaders', length: '12 weeks fixed', modes: 'Online focus', auditer: '✓ + interview', trinityEq: 'Post-Diploma', instrumental: '—', performance: '✓✓✓', rhythm: 'Applied', metamusic: 'Core focus', certificate: 'Order commission', registration: 'Included', graduation: 'Included' } }
  ];

  var FEATURES = [
    { key: 'bestFor', label: 'Best for' },
    { key: 'length', label: 'Length' },
    { key: 'modes', label: 'Online & Onsite' },
    { key: 'auditer', label: 'MAM Auditer' },
    { key: 'trinityEq', label: 'Certification levels' },
    { key: 'instrumental', label: 'Instrumental Intelligence' },
    { key: 'performance', label: 'Performance Intelligence' },
    { key: 'rhythm', label: 'Rhythm Intelligence' },
    { key: 'metamusic', label: 'MetaMusic & Consciousness' },
    { key: 'certificate', label: 'Certificate' },
    { key: 'registration', label: 'Registration (+$5)' },
    { key: 'graduation', label: 'Graduation' }
  ];

  var byId = {};
  PROGRAMMES.forEach(function (p) { byId[p.id] = p; });

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function fmtValue(v) {
    if (v === '—') return '<span class="pc-dash" aria-label="not included">—</span>';
    if (/^✓/.test(v)) return '<span class="pc-yes"><i class="fa-solid fa-check" aria-hidden="true"></i>' + esc(v.replace(/^✓+\s?/, '') || '') + '</span>';
    if (v === 'Both') return '<span class="pc-yes"><i class="fa-solid fa-check" aria-hidden="true"></i>Both modes</span>';
    return esc(v);
  }
  function applyHref(id) { return 'apply?pathway=' + id; }

  /* ───────────────────────── MAM Match recommender ───────────────────────── */
  var QUESTIONS = [
    { key: 'level', q: 'Where are you right now?', options: [
      { v: 'none', label: 'Complete beginner', icon: 'seedling' },
      { v: 'some', label: 'Some basics', icon: 'music' },
      { v: 'intermediate', label: 'Intermediate', icon: 'guitar' },
      { v: 'advanced', label: 'Advanced / pro', icon: 'star' }
    ] },
    { key: 'goal', q: "What's your main goal?", options: [
      { v: 'worship', label: 'Lead worship', icon: 'church' },
      { v: 'perform', label: 'Perform & gig', icon: 'microphone' },
      { v: 'exam', label: 'Graded certification', icon: 'award' },
      { v: 'produce', label: 'Produce & create', icon: 'sliders' },
      { v: 'lead', label: 'Teach & direct', icon: 'chalkboard-user' },
      { v: 'culture', label: 'Shape culture', icon: 'fire' }
    ] },
    { key: 'pace', q: 'How do you want to learn?', options: [
      { v: 'intensive', label: 'Short intensive', icon: 'bolt' },
      { v: 'ongoing', label: 'Ongoing weekly terms', icon: 'calendar-days' }
    ] },
    { key: 'budget', q: "What's your budget comfort?", options: [
      { v: 1, label: 'Keep it low', icon: 'tag' },
      { v: 2, label: 'Standard', icon: 'scale-balanced' },
      { v: 3, label: 'Invest fully', icon: 'gem' }
    ] }
  ];

  function score(p, a) {
    var s = 0, reasons = [];
    if (a.level && p.levels.indexOf(a.level) >= 0) { s += 3; }
    if (a.goal && p.goals.indexOf(a.goal) >= 0) { s += 3; reasons.push('goal'); }
    if (a.pace) { if (p.pace === a.pace) { s += 2; reasons.push('pace'); } else if (p.pace === 'both') { s += 1; } }
    if (a.budget) { var d = Math.abs(p.budget - a.budget); s += (2 - d); if (d === 0) reasons.push('budget'); }
    return { s: s, reasons: reasons };
  }
  function recommend(a) {
    var ranked = PROGRAMMES.map(function (p) { return { p: p, r: score(p, a) }; })
      .sort(function (x, y) { return y.r.s - x.r.s; });
    return ranked;
  }
  function reasonText(p, a) {
    var bits = [];
    var lvlMap = { none: 'starting from scratch', some: 'building on some basics', intermediate: 'at an intermediate level', advanced: 'already advanced' };
    var goalMap = { worship: 'lead worship', perform: 'perform & gig', exam: 'earn graded certification', produce: 'produce & create', lead: 'teach & direct', culture: 'shape culture' };
    if (a.level && lvlMap[a.level]) bits.push("you're " + lvlMap[a.level]);
    if (a.goal && goalMap[a.goal]) bits.push('you want to ' + goalMap[a.goal]);
    if (a.pace === 'intensive') bits.push('you prefer a short intensive');
    if (a.pace === 'ongoing') bits.push('you prefer ongoing terms');
    return bits.length ? 'Because ' + bits.join(', ') + '.' : 'A strong all-round fit for you.';
  }

  function buildRecommender(mount) {
    mount.removeAttribute('hidden');
    var answers = {};
    var step = 0;

    function render() {
      if (step < QUESTIONS.length) {
        var Q = QUESTIONS[step];
        mount.innerHTML =
          '<div class="match-head">' +
            '<div class="match-eyebrow"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> MAM Match</div>' +
            '<div class="match-progress" aria-hidden="true">' +
              QUESTIONS.map(function (_, i) { return '<span class="' + (i <= step ? 'on' : '') + '"></span>'; }).join('') +
            '</div>' +
          '</div>' +
          '<h3 class="match-q" id="matchQ">' + esc(Q.q) + '</h3>' +
          '<div class="match-options" role="group" aria-labelledby="matchQ">' +
            Q.options.map(function (o) {
              return '<button type="button" class="match-opt" data-val="' + esc(String(o.v)) + '">' +
                '<i class="fa-solid fa-' + o.icon + '" aria-hidden="true"></i><span>' + esc(o.label) + '</span></button>';
            }).join('') +
          '</div>' +
          (step > 0 ? '<button type="button" class="match-back" data-back>&larr; Back</button>' : '<p class="match-sub">Four quick taps. No email needed.</p>');

        mount.querySelectorAll('.match-opt').forEach(function (b) {
          b.addEventListener('click', function () {
            var raw = b.getAttribute('data-val');
            answers[QUESTIONS[step].key] = QUESTIONS[step].key === 'budget' ? parseInt(raw, 10) : raw;
            step++; render();
            mount.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          });
        });
        var back = mount.querySelector('[data-back]');
        if (back) back.addEventListener('click', function () { step--; render(); });
      } else {
        var ranked = recommend(answers);
        var top = ranked[0].p;
        mount.innerHTML =
          '<div class="match-head"><div class="match-eyebrow"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> MAM Match · your result</div></div>' +
          '<div class="match-result">' +
            '<div class="match-result-badge"><i class="fa-solid fa-trophy" aria-hidden="true"></i> Best fit</div>' +
            '<div class="match-result-name">' + esc(top.label) + ' <span>· ' + esc(top.name) + '</span></div>' +
            '<div class="match-result-price">$' + top.price + '<small>' + esc(top.unit) + '</small></div>' +
            '<p class="match-result-why">' + esc(reasonText(top, answers)) + '</p>' +
            '<div class="match-result-actions">' +
              '<a class="match-cta" href="' + applyHref(top.id) + '"><i class="fa-solid fa-bolt" aria-hidden="true"></i> Apply for ' + esc(top.label) + '</a>' +
              '<button type="button" class="match-secondary" data-compare="' + esc(top.id) + '">See all programmes</button>' +
            '</div>' +
            '<button type="button" class="match-restart" data-restart><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Start over</button>' +
          '</div>';
        var restart = mount.querySelector('[data-restart]');
        if (restart) restart.addEventListener('click', function () { answers = {}; step = 0; render(); });
        var cmp = mount.querySelector('[data-compare]');
        if (cmp) cmp.addEventListener('click', function () {
          var pick = cmp.getAttribute('data-compare');
          var mc = document.getElementById('mobileCompare');
          // On mobile the stacked cards are visible — flash + scroll to the pick.
          // On desktop they're display:none, so fall back to the full table.
          if (mc && getComputedStyle(mc).display !== 'none') {
            document.dispatchEvent(new CustomEvent('mam:compare-set', { detail: { a: pick } }));
          } else {
            var tbl = document.querySelector('.compare-table-wrap');
            (tbl || mount).scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        });
      }
    }
    render();
  }

  /* ─────────────────────── Mobile programme cards ─────────────────────── */
  // Four headline features shown up front; the rest live behind a disclosure
  // so each card stays clean and scannable instead of a wall of rows.
  var HEADLINE = ['bestFor', 'length', 'modes', 'certificate'];

  function buildMobileCards(mount) {
    function rowsHtml(p, keys) {
      return '<dl class="pc-rows">' + keys.map(function (k) {
        var ft = FEATURES.filter(function (f) { return f.key === k; })[0];
        if (!ft) return '';
        return '<div class="pc-row"><dt>' + esc(ft.label) + '</dt><dd>' + fmtValue(p.f[ft.key]) + '</dd></div>';
      }).join('') + '</dl>';
    }
    function card(p) {
      var cls = p.premium ? 'is-premium' : p.featured ? 'is-featured' : '';
      var rest = FEATURES.map(function (f) { return f.key; }).filter(function (k) { return HEADLINE.indexOf(k) < 0; });
      return '<article class="pcard ' + cls + '" id="pc-' + p.id + '">' +
        (p.tag ? '<span class="pcard-tag">' + esc(p.tag) + '</span>' : '') +
        '<div class="pcard-label">' + esc(p.label) + '</div>' +
        '<h3 class="pcard-name">' + esc(p.name) + '</h3>' +
        '<div class="pcard-trinity">' + esc(p.trinity) + '</div>' +
        '<div class="pcard-price">$' + p.price + '<small>' + esc(p.unit) + '</small></div>' +
        rowsHtml(p, HEADLINE) +
        '<div class="pc-more" hidden>' + rowsHtml(p, rest) + '</div>' +
        '<button type="button" class="pc-toggle" aria-expanded="false">See full breakdown <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>' +
        '<a class="pcard-cta" href="' + applyHref(p.id) + '"><i class="fa-solid fa-bolt" aria-hidden="true"></i> Apply for ' + esc(p.label) + '</a>' +
      '</article>';
    }

    mount.innerHTML =
      '<div class="pcards">' + PROGRAMMES.map(card).join('') + '</div>' +
      '<p class="cmp-foot"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Not sure which fits? Use <strong>MAM Match</strong> above, or <a href="auditer">book the MAM Auditer</a> for an accurate placement.</p>';

    mount.querySelectorAll('.pc-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var c = btn.parentNode, more = c.querySelector('.pc-more');
        var willOpen = more.hidden;
        more.hidden = !willOpen;
        btn.setAttribute('aria-expanded', String(willOpen));
        btn.innerHTML = (willOpen ? 'Hide breakdown' : 'See full breakdown') +
          ' <i class="fa-solid fa-chevron-' + (willOpen ? 'up' : 'down') + '" aria-hidden="true"></i>';
      });
    });

    // Recommender deep-link: flash + scroll to the recommended programme card.
    document.addEventListener('mam:compare-set', function (e) {
      var id = e.detail && e.detail.a;
      var el = id && document.getElementById('pc-' + id);
      if (!el) return;
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      el.classList.add('pc-flash');
      setTimeout(function () { el.classList.remove('pc-flash'); }, 1800);
    });
  }

  function init() {
    var rec = document.getElementById('mamMatch'); if (rec) buildRecommender(rec);
    var mc = document.getElementById('mobileCompare'); if (mc) buildMobileCards(mc);
  }
  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
