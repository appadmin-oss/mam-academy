/**
 * MAM Academy — "Find Your Path" Learning Advisor
 * ---------------------------------------------------------------
 * A warm, aspiration-first helper that suggests a starting programme, then
 * hands off into the existing application wizard.
 *
 * Two guiding principles (both deliberate — see docs/application-flow-guide-review.md §2):
 *  - NEVER DISTURB. The advisor is *offered*, never imposed. Undecided
 *    applicants see a small, dismissible invitation above the form — the wizard
 *    itself is always right there and fully usable. The full advisor opens only
 *    when the applicant asks for it (the invitation, or ?advisor=1). It never
 *    hijacks the page on arrival.
 *  - DON'T FEEL LIKE AI. The AI IS used — the gateway chooses the best starting
 *    programme and writes a warm rationale — but the applicant never sees a
 *    machine. A deterministic score renders instantly (no "thinking" spinner);
 *    the AI's pick is then quietly adopted and its sentence is shown in MAM's
 *    own mentor voice, under a warm heading, with a match % and (in the
 *    deterministic fallback) plain "because" reasons. No chatbot framing, no
 *    "the AI chose this", no AI badge. If AI is off/unreachable the deterministic
 *    recommendation stands, so the advisor always works. It should feel like MAM
 *    understands you — not like you're talking to a model.
 *
 * Other invariants:
 *  - Psychologically ordered (dream first), one question at a time, with a LIVE
 *    recommendation panel that re-scores after every answer.
 *  - Placement, not judgement: a *starting point* that always defers final
 *    placement to the MAM Auditer. No guaranteed outcomes, no pressure.
 *  - Hand-off = the wizard's existing, allow-listed URL prefill
 *    (?mode=&pathway=&level=&discipline=&expression=). No new endpoint, no new
 *    trust surface, no DB change. The server stays authoritative on price.
 *  - Progressive enhancement: with JS off, or on "skip", apply.php is unchanged.
 *
 * Brand voice: encouraging · respectful · aspirational · confident · honest —
 * never elitist, never transactional, never pressuring.
 */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };

  var host = document.getElementById('mamAdvisor');
  if (!host) return;

  // ---- Should the advisor open? -------------------------------------------
  // Show only for undecided applicants: no saved draft, no ?pathway/?programme
  // deep-link, and not dismissed this visit. ?advisor=1 forces it (the "change"
  // affordance). This keeps returning/resuming applicants straight in the wizard.
  var qs = new URLSearchParams(location.search);
  var forced = qs.get('advisor') === '1';
  // apply.js writes an auto-draft (just an idempotency key) on load, so mere
  // draft existence isn't a useful signal. Only treat the applicant as "already
  // started" if the draft holds a real selection or progress beyond step 1.
  function started() {
    try {
      var d = JSON.parse(localStorage.getItem('mam.apply.draft') || 'null');
      if (!d) return false;
      return !!(d.pathway || d.mode || d.discipline || d.level || d.firstName || d.email ||
                (typeof d.currentStep === 'number' && d.currentStep > 0));
    } catch (e) { return false; }
  }
  function dismissed() { try { return sessionStorage.getItem('mam.advisor.skip') === '1'; } catch (e) { return false; } }
  var deepLinked = !!(qs.get('pathway') || qs.get('programme'));

  // ---- Data model ----------------------------------------------------------
  var PATHWAYS = ['crash', 'certified-musician', 'grand-musician', 'meta-order'];

  // Display meta. Prices are display-only and overridden by live server config
  // (window.MAM_CONFIG.pricing) when present — the server remains authoritative.
  var PROG = {
    'crash':              { name: 'Crash Programme',     tag: '6 weeks',  from: 70,  blurb: 'A fast, focused 6-week intensive — one course, one flat fee. A brilliant way to begin.' },
    'certified-musician': { name: 'Certified Musician',  tag: '12 weeks', from: 240, blurb: 'Your core path to certification — one tradition, all four levels.' },
    'grand-musician':     { name: 'Grand Musician',      tag: 'Flagship', from: 313, blurb: 'The complete musician — all three traditions across all four levels.' },
    'meta-order':         { name: 'The MetaMusic Order', tag: '12 weeks', from: 60,  blurb: 'The post-diploma intensive — theory, philosophy and composition.' }
  };
  var LEVELS = { precert: 'Pre-Certification', cert: 'Certification', prediploma: 'Pre-Diploma', diploma: 'Diploma' };
  var DISC   = { piano: 'Piano', voice: 'Voice', guitar: 'Guitar', drums: 'Drums & Percussion', violin: 'Violin', saxophone: 'Saxophone' };
  var EXPR   = { classical: 'Classical', contemporary: 'Contemporary', african: 'African' };

  function priceOf(key) {
    try {
      var p = window.MAM_CONFIG && MAM_CONFIG.pricing && MAM_CONFIG.pricing[key];
      if (p && p.usd) return Math.round(p.usd);
    } catch (e) {}
    return PROG[key].from;
  }

  // ---- Currency: show prices in the user's local currency unless they've
  // modified it. Mirrors the site engine — saved override ('mam.currency') wins,
  // else detect from locale; convert the USD base via the live FX cache. --------
  var SYM = { USD: '$', NGN: '₦', GBP: '£', EUR: '€' };
  function curPref() {
    try { var s = localStorage.getItem('mam.currency'); if (s && SYM[s]) return s; } catch (e) {}
    var region = (navigator.language || '').split('-')[1];
    return ({ NG: 'NGN', GB: 'GBP', US: 'USD', IE: 'EUR', DE: 'EUR', FR: 'EUR', ES: 'EUR', IT: 'EUR', NL: 'EUR' })[region] || 'USD';
  }
  function fxRates() {
    var base = { USD: 1, NGN: 1500, GBP: 0.79, EUR: 0.92 };
    try { var c = JSON.parse(localStorage.getItem('mam.fx') || 'null'); if (c && c.rates) return Object.assign(base, c.rates); } catch (e) {}
    try { if (window.MAM_CONFIG && MAM_CONFIG.currency && MAM_CONFIG.currency.rates) return Object.assign(base, MAM_CONFIG.currency.rates); } catch (e) {}
    return base;
  }
  function money(usd) {
    // Single source of truth: local-currency formatting via MAM_CCY (config.js).
    if (window.MAM_CCY) return MAM_CCY.format(usd);
    var cur = curPref(), r = fxRates();
    var v = usd * (r[cur] || 1);
    var n = cur === 'NGN' ? Math.round(v / 50) * 50 : Math.round(v);
    return (SYM[cur] || cur + ' ') + n.toLocaleString();
  }

  // Each option adds weights to programmes and may set a wizard field. `because`
  // is the reason shown when that option's leading programme wins.
  var QUESTIONS = [
    {
      key: 'dream', q: 'What do you dream of becoming?',
      help: 'Start with the vision — we\'ll shape the path around it.',
      opts: [
        { v: 'performer', label: 'A performer on stage',        w: { 'certified-musician': 3, 'grand-musician': 2, crash: 1 }, because: 'You want to perform' },
        { v: 'producer',  label: 'A producer / creator',         w: { 'meta-order': 3, 'certified-musician': 1 },              because: 'You want to create & produce' },
        { v: 'worship',   label: 'A worship leader',             w: { 'certified-musician': 3, 'grand-musician': 2 },          because: 'You want to lead worship' },
        { v: 'teacher',   label: 'A music teacher',              w: { 'grand-musician': 3, 'certified-musician': 1 },          because: 'You want to teach' },
        { v: 'complete',  label: 'A complete, well-rounded musician', w: { 'grand-musician': 3, 'meta-order': 2 },             because: 'You want the complete-musician path' }
      ]
    },
    {
      key: 'excites', q: 'What excites you most?',
      help: 'The part of music you can\'t stop thinking about.',
      opts: [
        { v: 'instrument', label: 'Playing an instrument',       w: { 'certified-musician': 2 },                because: 'You love playing' },
        { v: 'voice',      label: 'Singing / your voice',        w: { 'certified-musician': 2 }, set: { discipline: 'voice' }, because: 'Voice is your instrument' },
        { v: 'rhythm',     label: 'Rhythm & groove',             w: { 'certified-musician': 1, crash: 1 },      because: 'Rhythm drives you' },
        { v: 'theory',     label: 'Theory & composition',        w: { 'meta-order': 2, 'grand-musician': 1 },   because: 'You love theory & composition' },
        { v: 'philosophy', label: 'The philosophy & mystery of music', w: { 'meta-order': 3 },                  because: 'The philosophy of music calls you' }
      ]
    },
    {
      key: 'level', q: 'Where are you now?',
      help: 'Be honest — the MAM Auditer fine-tunes this later. There\'s no wrong answer.',
      opts: [
        { v: 'beginner', label: 'Just starting out',             w: { crash: 2, 'certified-musician': 1 }, set: { level: 'precert' }, because: 'You\'re starting fresh' },
        { v: 'self',     label: 'Self-taught',                    w: { 'certified-musician': 2 },           set: { level: 'precert' }, because: 'Self-taught and ready to go formal' },
        { v: 'some',     label: 'Some formal training',           w: { 'certified-musician': 2, 'grand-musician': 1 }, set: { level: 'cert' }, because: 'You have some training' },
        { v: 'advanced', label: 'Advanced / experienced',         w: { 'grand-musician': 3, 'meta-order': 1 }, set: { level: 'prediploma' }, because: 'You\'re already advanced' }
      ]
    },
    {
      key: 'discipline', q: 'Which instrument or discipline?',
      help: 'Your main focus. You can add more later.',
      opts: [
        { v: 'piano',     label: 'Piano / Keyboard', set: { discipline: 'piano' } },
        { v: 'voice',     label: 'Voice',            set: { discipline: 'voice' } },
        { v: 'guitar',    label: 'Guitar',           set: { discipline: 'guitar' } },
        { v: 'drums',     label: 'Drums',            set: { discipline: 'drums' } },
        { v: 'violin',    label: 'Violin / Strings', set: { discipline: 'violin' } },
        { v: 'saxophone', label: 'Sax / Wind',       set: { discipline: 'saxophone' } },
        { v: '',          label: 'Help me decide' }
      ]
    },
    {
      key: 'expression', q: 'Which tradition calls you?',
      help: 'The sound you most want to master. Grand Musician covers all three.',
      opts: [
        { v: 'classical',    label: 'Classical',     set: { expression: 'classical' },    because: 'Drawn to the classical tradition' },
        { v: 'contemporary', label: 'Contemporary',  set: { expression: 'contemporary' }, because: 'Drawn to contemporary music' },
        { v: 'african',      label: 'African',        set: { expression: 'african' },      because: 'Drawn to African music' },
        { v: '',             label: 'All / not sure', w: { 'grand-musician': 1 } }
      ]
    },
    {
      key: 'mode', q: 'How would you like to learn?',
      help: 'Both are fully guided — pick what fits your life.',
      opts: [
        { v: 'online', label: 'Online, from anywhere', set: { mode: 'online' }, because: 'Learning online' },
        { v: 'onsite', label: 'Onsite in Lagos',       set: { mode: 'onsite' }, because: 'Learning onsite in Lagos' }
      ]
    }
  ];

  var answers = {};     // key -> option object
  var idx = 0;          // current question index
  try { var saved = JSON.parse(localStorage.getItem('mam.advisor') || 'null'); if (saved && saved.answers) restore(saved.answers); } catch (e) {}
  function restore(a) { QUESTIONS.forEach(function (qq) { if (a[qq.key]) { var o = qq.opts.filter(function (x) { return x.v === a[qq.key]; })[0]; if (o) answers[qq.key] = o; } }); }

  // ---- Scoring -------------------------------------------------------------
  function score() {
    var s = {}; PATHWAYS.forEach(function (p) { s[p] = 0; });
    var reasons = {}; PATHWAYS.forEach(function (p) { reasons[p] = []; });
    Object.keys(answers).forEach(function (k) {
      var o = answers[k]; if (!o) return;
      if (o.w) Object.keys(o.w).forEach(function (p) { if (s[p] != null) { s[p] += o.w[p]; if (o.because) reasons[p].push(o.because); } });
    });
    var ranked = PATHWAYS.slice().sort(function (a, b) { return s[b] - s[a]; });
    var top = ranked[0], second = ranked[1];
    var sum = PATHWAYS.reduce(function (t, p) { return t + Math.max(0, s[p]); }, 0) || 1;
    var pct = {}; PATHWAYS.forEach(function (p) { pct[p] = Math.max(45, Math.min(98, Math.round(45 + (s[p] / sum) * 55))); });
    var gap = s[top] - s[second];
    var confidence = s[top] === 0 ? 'none' : (gap >= 2 ? 'high' : (gap >= 1 ? 'good' : 'multiple'));
    return { s: s, ranked: ranked, top: top, pct: pct, confidence: confidence, reasons: reasons };
  }

  // Collected wizard fields from the answers (only what the wizard accepts).
  function fields() {
    var f = {};
    Object.keys(answers).forEach(function (k) { var o = answers[k]; if (o && o.set) Object.keys(o.set).forEach(function (kk) { if (o.set[kk]) f[kk] = o.set[kk]; }); });
    return f;
  }

  // ---- Rendering -----------------------------------------------------------
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

  function badge(conf) {
    if (conf === 'high') return '<span class="adv-badge adv-badge-high">★ Recommended with high confidence</span>';
    if (conf === 'good') return '<span class="adv-badge adv-badge-good">Great match</span>';
    if (conf === 'multiple') return '<span class="adv-badge adv-badge-multi">A few great options</span>';
    return '<span class="adv-badge adv-badge-multi">Building your recommendation…</span>';
  }

  // ---- AI recommendation (used, but never made to LOOK like AI) --------------
  // The deterministic score() renders instantly, so there is never a "thinking"
  // state. In the background we ask the gateway for its pick + a warm rationale;
  // when it lands we quietly adopt its programme and show its human-voiced
  // sentence — no AI labels, no chatbot, no spinner. If AI is off/unreachable the
  // deterministic preview simply stands. `aiRec` = { pathway, rationale } | null.
  var aiRec = null, aiRecKey = '';
  function apiBase() {
    try { if (window.MAM_CONFIG && MAM_CONFIG.api && MAM_CONFIG.api.base) return String(MAM_CONFIG.api.base).replace(/\/$/, ''); } catch (e) {}
    return 'api';
  }
  function answerKey() { return Object.keys(answers).sort().map(function (k) { return k + '=' + (answers[k] ? answers[k].v : ''); }).join('&'); }
  function maybeFetchRec() {
    if (Object.keys(answers).length < 5) return;   // wait for enough signal
    var key = answerKey();
    if (key === aiRecKey) return;                   // already fetched / in-flight for this set
    aiRecKey = key;
    var f = fields();
    var p = new URLSearchParams();
    if (answers.dream)   p.set('dream',   answers.dream.v);
    if (answers.excites) p.set('excites', answers.excites.v);
    if (f.level)      p.set('level', f.level);
    if (f.discipline) p.set('discipline', f.discipline);
    if (f.expression) p.set('expression', f.expression);
    if (f.mode)       p.set('mode', f.mode);
    fetch(apiBase() + '/advisor/recommend?' + p.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (j) {
        if (key !== aiRecKey) return;               // a newer answer set superseded this
        if (j && j.ok && j.recommendation && j.recommendation.pathway && PROG[j.recommendation.pathway]) {
          aiRec = j.recommendation; persist(); renderPanel();
        }
      })
      .catch(function () { /* AI off/unreachable → the deterministic preview stands */ });
  }

  function renderPanel() {
    var r = score();
    var panel = $('#advReco'); if (!panel) return;
    var answered = Object.keys(answers).length;
    if (!answered || r.s[r.top] === 0) {
      panel.innerHTML = '<div class="adv-reco-empty"><i class="fa-solid fa-compass" aria-hidden="true"></i><p>Answer as you go — your recommended path builds here in real time.</p></div>';
      maybeFetchRec();
      return;
    }
    // Prefer the AI's programme once it lands; otherwise the instant deterministic
    // top. Either way it's shown in MAM's own warm voice — the AI is used, but the
    // panel never announces itself as AI.
    var aiOn = !!(aiRec && aiRec.pathway && PROG[aiRec.pathway] && aiRec.rationale);
    var top = aiOn ? aiRec.pathway : r.top;
    var meta = PROG[top];
    var reasons = r.reasons[top] ? r.reasons[top].filter(function (v, i, a) { return a.indexOf(v) === i; }).slice(0, 4) : [];
    var f = fields();
    var chips = [];
    if (f.discipline && DISC[f.discipline]) chips.push(DISC[f.discipline]);
    if (f.expression && EXPR[f.expression] && (top === 'certified-musician')) chips.push(EXPR[f.expression]);
    if ((top === 'certified-musician' || top === 'grand-musician') && f.level && LEVELS[f.level]) chips.push(LEVELS[f.level]);
    if (f.mode) chips.push(f.mode === 'online' ? 'Online' : 'Onsite Lagos');

    // When the AI has spoken, show its warm rationale sentence (mentor voice, no
    // AI mention); otherwise the curated blurb + the legible deterministic reasons.
    var rationale = aiOn
      ? '<p class="adv-reco-blurb">' + esc(aiRec.rationale) + '</p>'
      : '<p class="adv-reco-blurb">' + esc(meta.blurb) + '</p>' +
        (reasons.length ? '<ul class="adv-reco-because">' + reasons.map(function (b) { return '<li><i class="fa-solid fa-check" aria-hidden="true"></i>' + esc(b) + '</li>'; }).join('') + '</ul>' : '');

    var html = '' +
      '<div class="adv-reco-card">' +
        '<div class="adv-reco-kicker"><i class="fa-solid fa-compass" aria-hidden="true"></i> A great place to begin</div>' +
        badge(r.confidence) +
        '<div class="adv-reco-match" aria-hidden="true">' + r.pct[top] + '%<span>match</span></div>' +
        '<h3 class="adv-reco-name">' + esc(meta.name) + ' <span class="adv-reco-tag">' + esc(meta.tag) + '</span></h3>' +
        rationale +
        (chips.length ? '<div class="adv-reco-chips">' + chips.map(function (c) { return '<span>' + esc(c) + '</span>'; }).join('') + '</div>' : '') +
        '<div class="adv-reco-price">From <strong>' + money(priceOf(top)) + '</strong> · the MAM Auditer confirms your exact placement</div>' +
      '</div>';

    // Runner-up only in the deterministic preview (an honest "a few great options").
    if (!aiOn && r.confidence === 'multiple') {
      var second = r.ranked[1];
      html += '<button type="button" class="adv-reco-alt" data-pick-alt="' + esc(second) + '">' +
        'Also strong for you: <strong>' + esc(PROG[second].name) + '</strong> (' + r.pct[second] + '%)' +
      '</button>';
    }
    panel.innerHTML = html;

    // Once there's enough signal, ask the gateway to make the actual pick.
    maybeFetchRec();
  }

  function renderQuestion() {
    var q = QUESTIONS[idx];
    var total = QUESTIONS.length;
    var chosen = answers[q.key] ? answers[q.key].v : null;
    var opts = q.opts.map(function (o) {
      var on = chosen === o.v;
      return '<button type="button" class="adv-opt' + (on ? ' selected' : '') + '" role="radio" aria-checked="' + (on ? 'true' : 'false') + '" data-opt="' + esc(o.v) + '">' +
        '<span>' + esc(o.label) + '</span>' + (on ? '<i class="fa-solid fa-circle-check" aria-hidden="true"></i>' : '') +
      '</button>';
    }).join('');
    $('#advStage').innerHTML = '' +
      '<div class="adv-qmeta">Question ' + (idx + 1) + ' of ' + total + '</div>' +
      '<h2 class="adv-q" id="advQTitle">' + esc(q.q) + '</h2>' +
      '<p class="adv-help">' + esc(q.help) + '</p>' +
      '<div class="adv-opts" role="radiogroup" aria-labelledby="advQTitle">' + opts + '</div>';
    // progress bar + nav state
    var pct = Math.round((idx) / total * 100);
    var bar = $('#advBar'); if (bar) bar.style.width = pct + '%';
    var back = $('#advBack'); if (back) back.disabled = idx === 0;
    var next = $('#advNext');
    var isLast = idx === total - 1;
    if (next) {
      next.disabled = !answers[q.key];
      next.innerHTML = isLast
        ? 'See my path &amp; start <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>'
        : 'Next <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>';
    }
  }

  // The chosen programme: the AI's pick once it lands, else the deterministic top.
  function chosenPathway() {
    if (aiRec && aiRec.pathway && PROG[aiRec.pathway]) return aiRec.pathway;
    var r = score(); return r.s[r.top] > 0 ? r.top : '';
  }
  function persist() {
    try {
      var a = {}; Object.keys(answers).forEach(function (k) { if (answers[k] && answers[k].v !== undefined) a[k] = answers[k].v; });
      localStorage.setItem('mam.advisor', JSON.stringify({ answers: a, rec: chosenPathway(), at: Date.now() }));
    } catch (e) {}
  }

  function choose(v) {
    var q = QUESTIONS[idx];
    var o = q.opts.filter(function (x) { return x.v === v; })[0];
    if (!o) return;
    answers[q.key] = o;
    persist();
    renderQuestion();
    renderPanel();
    track('advisor_answer', { q: q.key, a: v });
  }

  // ---- Hand-off: the wizard's existing allow-listed URL prefill ------------
  function handoff() {
    var r = score();
    var f = fields();
    var pathway = chosenPathway();
    var params = new URLSearchParams();
    if (f.mode) params.set('mode', f.mode);
    if (pathway) params.set('pathway', pathway);
    if (f.discipline) params.set('discipline', f.discipline);
    // level only matters for the levelled programmes; expression only for Certified.
    if ((pathway === 'certified-musician' || pathway === 'grand-musician') && f.level) params.set('level', f.level);
    if (pathway === 'certified-musician' && f.expression) params.set('expression', f.expression);
    persist();
    track('advisor_complete', { pathway: pathway, confidence: r.confidence });
    // Reload into the wizard with the prefill (re-uses apply.js's XSS-safe
    // allow-list). Params present → the advisor won't re-open.
    location.assign('apply?' + params.toString());
  }

  function skip() {
    try { sessionStorage.setItem('mam.advisor.skip', '1'); } catch (e) {}
    track('advisor_skip', {});
    close();
  }

  function close() {
    host.hidden = true;
    var shell = document.querySelector('.apply-shell');
    if (shell) shell.classList.remove('advisor-open');
    document.body.classList.remove('mam-advisor-open');
  }

  function track(ev, meta) {
    try { if (window.mamTrack) window.mamTrack(ev, meta); } catch (e) {}
  }

  // ---- Shell + events ------------------------------------------------------
  function open() {
    var shell = document.querySelector('.apply-shell');
    if (shell) shell.classList.add('advisor-open');
    document.body.classList.add('mam-advisor-open');
    host.classList.remove('adv-invite-host');
    host.hidden = false;
    host.innerHTML = '' +
      '<div class="adv-inner">' +
        '<div class="adv-head">' +
          '<div class="adv-kicker"><i class="fa-solid fa-compass" aria-hidden="true"></i> Your Learning Advisor</div>' +
          '<h1 class="adv-title">Let\'s find where you belong</h1>' +
          '<p class="adv-sub">A few quick questions — no account, no pressure. There\'s a place for you here; let\'s find it together.</p>' +
          '<button type="button" class="adv-skip" id="advSkip">I know what I want — skip <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>' +
        '</div>' +
        '<div class="adv-grid">' +
          '<div class="adv-quiz">' +
            '<div class="adv-progress" aria-hidden="true"><span id="advBar"></span></div>' +
            '<div id="advStage" aria-live="polite"></div>' +
            '<div class="adv-nav">' +
              '<button type="button" class="adv-btn ghost" id="advBack"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back</button>' +
              '<button type="button" class="adv-btn primary" id="advNext" disabled>Next <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>' +
            '</div>' +
          '</div>' +
          '<aside class="adv-side" aria-label="Your recommendation">' +
            '<div class="adv-side-head">Your recommended path</div>' +
            '<div id="advReco" aria-live="polite"></div>' +
            '<p class="adv-side-foot"><i class="fa-solid fa-shield-heart" aria-hidden="true"></i> A recommended starting point — the MAM Auditer fine-tunes your exact placement.</p>' +
          '</aside>' +
        '</div>' +
      '</div>';

    // Jump to the first unanswered question when restoring.
    idx = 0;
    for (var i = 0; i < QUESTIONS.length; i++) { if (!answers[QUESTIONS[i].key]) { idx = i; break; } if (i === QUESTIONS.length - 1) idx = i; }

    renderQuestion();
    renderPanel();

    host.addEventListener('click', function (e) {
      var opt = e.target.closest('[data-opt]');
      if (opt) { choose(opt.getAttribute('data-opt')); return; }
      var alt = e.target.closest('[data-pick-alt]');
      if (alt) {
        // Nudge the ranking toward the runner-up the applicant prefers, honestly:
        // record their explicit preference and re-render.
        var key = alt.getAttribute('data-pick-alt');
        answers.__pref = { v: key, w: (function () { var w = {}; w[key] = 3; return w; })() };
        persist(); renderPanel(); return;
      }
      if (e.target.closest('#advNext')) {
        if (idx < QUESTIONS.length - 1) { idx++; renderQuestion(); renderPanel(); var s = $('#advStage'); if (s) s.focus && s.focus(); }
        else handoff();
        return;
      }
      if (e.target.closest('#advBack')) { if (idx > 0) { idx--; renderQuestion(); renderPanel(); } return; }
      if (e.target.closest('#advSkip')) { skip(); return; }
    });

    track('advisor_start', {});
  }

  // ---- Summary-panel chip: "Recommended by your Advisor · Change" ----------
  // Shown in the wizard (when the advisor isn't open) so the recommendation
  // persists and can be revisited without restarting the application.
  function injectChip() {
    // Prefer the recommendation actually made (AI or deterministic), saved at
    // hand-off; else recompute from restored answers.
    var pathway = '';
    try { var s = JSON.parse(localStorage.getItem('mam.advisor') || 'null'); if (s && s.rec && PROG[s.rec]) pathway = s.rec; } catch (e) {}
    if (!pathway) { if (!Object.keys(answers).length) return; var r = score(); if (r.s[r.top] <= 0) return; pathway = r.top; }
    var body = document.querySelector('.summary-panel-body');
    if (!body || document.getElementById('advChip')) return;
    var chip = document.createElement('div');
    chip.id = 'advChip'; chip.className = 'adv-chip';
    chip.innerHTML = '<span><i class="fa-solid fa-compass" aria-hidden="true"></i> Recommended by your Advisor: <strong>' + esc(PROG[pathway].name) + '</strong></span>' +
                     '<button type="button" id="advChipChange">Change</button>';
    body.insertBefore(chip, body.firstChild);
    var btn = document.getElementById('advChipChange');
    if (btn) btn.addEventListener('click', function () {
      try { sessionStorage.removeItem('mam.advisor.skip'); } catch (e) {}
      location.assign('apply?advisor=1');
    });
  }

  // ---- Invitation: the non-intrusive offer ---------------------------------
  // Undecided applicants get a small, dismissible banner above the form — the
  // wizard stays fully visible and usable. The full advisor opens ONLY when the
  // applicant accepts (or arrives with ?advisor=1). It never takes over on load.
  function renderInvite() {
    host.hidden = false;
    host.classList.add('adv-invite-host');
    host.innerHTML = '' +
      '<div class="adv-invite">' +
        '<div class="adv-invite-text">' +
          '<span class="adv-invite-title"><i class="fa-solid fa-compass" aria-hidden="true"></i> Not sure where to begin?</span>' +
          '<span class="adv-invite-sub">Answer a few quick questions and we’ll suggest a starting point — or just fill in the form below.</span>' +
        '</div>' +
        '<div class="adv-invite-actions">' +
          '<button type="button" class="adv-invite-go" id="advInviteGo">Help me choose <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>' +
          '<button type="button" class="adv-invite-dismiss" id="advInviteDismiss">No thanks</button>' +
        '</div>' +
      '</div>';
    var go = document.getElementById('advInviteGo');
    if (go) go.addEventListener('click', function () { track('advisor_invite_accept', {}); open(); });
    var no = document.getElementById('advInviteDismiss');
    if (no) no.addEventListener('click', function () {
      try { sessionStorage.setItem('mam.advisor.skip', '1'); } catch (e) {}
      track('advisor_invite_dismiss', {});
      host.hidden = true; host.innerHTML = ''; host.classList.remove('adv-invite-host');
    });
    track('advisor_invite_view', {});
  }

  function boot() {
    // Explicit request always opens the full advisor.
    if (forced) { open(); return; }
    // Resuming / deep-linked / already-dismissed → don't offer; just surface the
    // recommendation chip in the wizard if we have one.
    if (started() || deepLinked || dismissed()) { injectChip(); return; }
    // Otherwise: offer, never impose.
    renderInvite();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
