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
    { id: 'pre-cert', label: 'Pre-Certification', name: 'Foundations', trinity: 'Grade 1–2 equivalent', price: 120, unit: '/term',
      tag: '', accent: 'gold', levels: ['none', 'some'], goals: ['worship', 'exam'], pace: 'ongoing', budget: 1,
      f: { bestFor: 'Beginners, children', length: 'Flexible term', modes: 'Both', auditer: '✓', trinityEq: 'Grade 1–2', instrumental: '✓', performance: 'Introductory', rhythm: 'Foundations', metamusic: 'Exposure', certificate: '✓', registration: 'Once', graduation: 'At completion' } },
    { id: 'cert', label: 'Certification', name: 'Developing', trinity: 'Grade 3–4 equivalent', price: 150, unit: '/term',
      tag: 'Most chosen', accent: 'gold', featured: true, levels: ['some', 'intermediate'], goals: ['perform', 'worship', 'exam'], pace: 'ongoing', budget: 2,
      f: { bestFor: 'Building maturity', length: 'Flexible term', modes: 'Both', auditer: '✓', trinityEq: 'Grade 3–4', instrumental: '✓', performance: '✓', rhythm: 'Intermediate', metamusic: 'Exposure', certificate: '✓', registration: 'Once', graduation: 'At completion' } },
    { id: 'pre-dip', label: 'Pre-Diploma', name: 'Advanced', trinity: 'Grade 5–6 equivalent', price: 160, unit: '/term',
      tag: '', accent: 'gold', levels: ['intermediate'], goals: ['worship', 'perform', 'exam'], pace: 'ongoing', budget: 2,
      f: { bestFor: 'Worship leaders', length: 'Flexible term', modes: 'Both', auditer: '✓', trinityEq: 'Grade 5–6', instrumental: '✓', performance: '✓✓', rhythm: 'Advanced', metamusic: 'Depth', certificate: '✓', registration: 'Once', graduation: 'At completion' } },
    { id: 'diploma', label: 'Diploma', name: 'Professional', trinity: 'Grade 7–8 equivalent', price: 180, unit: '/term',
      tag: '', accent: 'gold', levels: ['advanced'], goals: ['perform', 'lead', 'exam', 'produce'], pace: 'ongoing', budget: 3,
      f: { bestFor: 'Pro performers, music directors', length: 'Flexible term', modes: 'Both', auditer: '✓', trinityEq: 'Grade 7–8', instrumental: '✓', performance: '✓✓✓', rhythm: 'Professional', metamusic: 'Mastery', certificate: '✓ Diploma', registration: 'Once', graduation: 'At completion' } },
    { id: 'crash', label: 'Crash', name: 'Intensive', trinity: '6 weeks · any school', price: 40, unit: '/course',
      tag: '', accent: 'gold', levels: ['none', 'some', 'intermediate', 'advanced'], goals: ['perform', 'produce', 'worship'], pace: 'intensive', budget: 1,
      f: { bestFor: 'Quick wins · summer · busy pros', length: '6 weeks fixed', modes: 'Both', auditer: 'Recommended', trinityEq: '—', instrumental: 'Per chosen course', performance: 'Per chosen course', rhythm: 'Per chosen course', metamusic: 'Per chosen course', certificate: 'Certificate of completion', registration: 'Once', graduation: '—' } },
    { id: 'meta-order', label: 'MetaMusic Order', name: 'Civilization Architect', trinity: '12 weeks · post-graduate', price: 240, unit: '/12wk',
      tag: 'Premium', accent: 'red', premium: true, levels: ['advanced'], goals: ['culture', 'produce', 'lead'], pace: 'intensive', budget: 3,
      f: { bestFor: 'Producers, performers, cultural leaders', length: '12 weeks fixed', modes: 'Online focus', auditer: '✓ + interview', trinityEq: 'Post-Diploma', instrumental: '—', performance: '✓✓✓', rhythm: 'Applied', metamusic: 'Core focus', certificate: 'Order commission', registration: 'Included', graduation: 'Included' } }
  ];

  var FEATURES = [
    { key: 'bestFor', label: 'Best for' },
    { key: 'length', label: 'Length' },
    { key: 'modes', label: 'Online & Onsite' },
    { key: 'auditer', label: 'MAM Auditer' },
    { key: 'trinityEq', label: 'Trinity equivalent' },
    { key: 'instrumental', label: 'Instrumental Intelligence' },
    { key: 'performance', label: 'Performance Intelligence' },
    { key: 'rhythm', label: 'Rhythm Intelligence' },
    { key: 'metamusic', label: 'MetaMusic & Consciousness' },
    { key: 'certificate', label: 'Certificate' },
    { key: 'registration', label: 'Registration (+$5)' },
    { key: 'graduation', label: 'Graduation (+$22)' }
  ];

  var byId = {};
  PROGRAMMES.forEach(function (p) { byId[p.id] = p; });

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function fmtValue(v) {
    if (v === '—') return '<span class="cmp-dash" aria-label="not included">—</span>';
    if (/^✓/.test(v)) return '<span class="cmp-yes"><i class="fa-solid fa-check" aria-hidden="true"></i>' + esc(v.replace(/^✓+\s?/, '') || '') + '</span>';
    if (v === 'Both') return '<span class="cmp-yes"><i class="fa-solid fa-check" aria-hidden="true"></i>Both modes</span>';
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
        var top = ranked[0].p, alt = ranked[1].p;
        mount.innerHTML =
          '<div class="match-head"><div class="match-eyebrow"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> MAM Match · your result</div></div>' +
          '<div class="match-result">' +
            '<div class="match-result-badge"><i class="fa-solid fa-trophy" aria-hidden="true"></i> Best fit</div>' +
            '<div class="match-result-name">' + esc(top.label) + ' <span>· ' + esc(top.name) + '</span></div>' +
            '<div class="match-result-price">$' + top.price + '<small>' + esc(top.unit) + '</small></div>' +
            '<p class="match-result-why">' + esc(reasonText(top, answers)) + '</p>' +
            '<div class="match-result-actions">' +
              '<a class="match-cta" href="' + applyHref(top.id) + '"><i class="fa-solid fa-bolt" aria-hidden="true"></i> Apply for ' + esc(top.label) + '</a>' +
              '<button type="button" class="match-secondary" data-compare="' + esc(top.id) + ',' + esc(alt.id) + '">Compare with ' + esc(alt.label) + '</button>' +
            '</div>' +
            '<button type="button" class="match-restart" data-restart><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Start over</button>' +
          '</div>';
        var restart = mount.querySelector('[data-restart]');
        if (restart) restart.addEventListener('click', function () { answers = {}; step = 0; render(); });
        var cmp = mount.querySelector('[data-compare]');
        if (cmp) cmp.addEventListener('click', function () {
          var pair = cmp.getAttribute('data-compare').split(',');
          var ev = new CustomEvent('mam:compare-set', { detail: { a: pair[0], b: pair[1] } });
          document.dispatchEvent(ev);
          var mc = document.getElementById('mobileCompare');
          (document.querySelector('.cmp-mobile') || mc || mount).scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      }
    }
    render();
  }

  /* ─────────────────────── Mobile head-to-head compare ─────────────────────── */
  function buildMobileCompare(mount) {
    var aId = 'cert', bId = 'crash';

    function selectHtml(which, sel) {
      return '<label class="cmp-pick"><span class="cmp-pick-label">' + (which === 'a' ? 'Programme A' : 'Programme B') + '</span>' +
        '<select class="cmp-select" data-side="' + which + '">' +
        PROGRAMMES.map(function (p) { return '<option value="' + p.id + '"' + (p.id === sel ? ' selected' : '') + '>' + esc(p.label) + ' · ' + esc(p.name) + '</option>'; }).join('') +
        '</select></label>';
    }
    function headCard(p) {
      return '<div class="cmp-card ' + (p.premium ? 'is-premium' : p.featured ? 'is-featured' : '') + '">' +
        (p.tag ? '<span class="cmp-card-tag">' + esc(p.tag) + '</span>' : '') +
        '<div class="cmp-card-name">' + esc(p.name) + '</div>' +
        '<div class="cmp-card-label">' + esc(p.label) + '</div>' +
        '<div class="cmp-card-price">$' + p.price + '<small>' + esc(p.unit) + '</small></div>' +
        '<a class="cmp-card-cta" href="' + applyHref(p.id) + '">Apply</a>' +
      '</div>';
    }
    function render() {
      var A = byId[aId], B = byId[bId];
      mount.innerHTML =
        '<div class="cmp-pickers">' + selectHtml('a', aId) +
          '<button type="button" class="cmp-swap" aria-label="Swap A and B"><i class="fa-solid fa-right-left" aria-hidden="true"></i></button>' +
          selectHtml('b', bId) + '</div>' +
        '<div class="cmp-cards">' + headCard(A) + headCard(B) + '</div>' +
        '<dl class="cmp-rows">' +
          FEATURES.map(function (ft) {
            var av = A.f[ft.key], bv = B.f[ft.key];
            var diff = av !== bv ? ' is-diff' : '';
            return '<div class="cmp-row' + diff + '">' +
              '<dt class="cmp-row-label">' + esc(ft.label) + '</dt>' +
              '<dd class="cmp-row-a">' + fmtValue(av) + '</dd>' +
              '<dd class="cmp-row-b">' + fmtValue(bv) + '</dd>' +
            '</div>';
          }).join('') +
        '</dl>' +
        '<p class="cmp-foot"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Highlighted rows differ between the two. Still unsure? <a href="auditer">Book MAM Auditer</a>.</p>';

      mount.querySelectorAll('.cmp-select').forEach(function (sel) {
        sel.addEventListener('change', function () {
          if (sel.getAttribute('data-side') === 'a') aId = sel.value; else bId = sel.value;
          if (aId === bId) { // avoid comparing a plan with itself — nudge B
            var other = PROGRAMMES.filter(function (p) { return p.id !== aId; })[0];
            if (sel.getAttribute('data-side') === 'a') bId = other.id; else aId = other.id;
          }
          render();
        });
      });
      mount.querySelector('.cmp-swap').addEventListener('click', function () { var t = aId; aId = bId; bId = t; render(); });
    }

    // Let the recommender deep-link a pair into the compare widget.
    document.addEventListener('mam:compare-set', function (e) {
      if (e.detail && byId[e.detail.a] && byId[e.detail.b]) { aId = e.detail.a; bId = e.detail.b; render(); }
    });
    render();
  }

  function init() {
    var rec = document.getElementById('mamMatch'); if (rec) buildRecommender(rec);
    var mc = document.getElementById('mobileCompare'); if (mc) buildMobileCompare(mc);
  }
  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
