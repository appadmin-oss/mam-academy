/**
 * MAM Academy — instructor application wizard.
 *
 * Seven steps, mirroring the student wizard's shape and reusing its components
 * (`.step`, `.field`, `.btn-step`) so the two forms cannot drift into two house
 * styles. The differences are deliberate: no payment step (nobody pays to apply
 * to teach), a structured teaching matrix instead of a free-text discipline
 * list, and a safeguarding step that collects referee CONTACTS and two
 * commitments — never a certificate, a document number, or anything about an
 * offence.
 *
 * WHY THIS FILE IS NOT INLINE. `.github/scripts/check-inline-js.cjs` walks only
 * `.html`, so an inline block in a `.php` page is syntax-checked by nothing —
 * which is how a portal once shipped an empty shell behind a parse error. Here
 * `node --check assets/js/*.js` covers it. It also keeps the page one step
 * closer to a CSP without 'unsafe-inline'.
 */
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };
  var DRAFT_KEY = 'mam.facapply.draft';
  var STEPS = 7;

  var gate = $('facGate');
  var form = $('facForm');
  var progress = $('facProgress');
  var announce = $('facAnnounce');

  var state = {
    token: new URLSearchParams(location.search).get('token') || '',
    email: '',
    emailLocked: false,        // an invited address is the invite's, not the body's
    catalogue: null,
    step: 0,
    subjects: {},              // 'course|level' -> true
    years: {},                 // course -> years
    programmes: {},
    modes: {},
    avail: {},
    headshotUrl: ''
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ── Gate ──────────────────────────────────────────────────────────────── */

  function gateFail(msg, showAsk) {
    gate.innerHTML = '<i class="fa-solid fa-envelope-circle-check" aria-hidden="true"></i>' + msg
      + (showAsk ? '<div class="fac-gate-actions"><a class="btn btn-secondary" href="/contact?topic=Faculty">'
        + '<i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Ask us for an invitation</a></div>' : '');
  }

  /**
   * MEASURED: with `config.js` blocked, this threw "Cannot read properties of
   * undefined (reading 'request')" and the gate sat on "Loading the
   * application…" forever — a candidate on a bad connection saw a spinner and
   * no way forward, which is worse than an error.
   *
   * config.js is a synchronous script and this one is deferred, so if it
   * loaded at all `MAM_API` is already here; the short poll covers the ordering
   * changing under us rather than a slow network. Same shape as the repo's
   * `whenChart` fallback: wait a bounded time, then say so plainly.
   */
  function whenApi(cb, tries) {
    if (window.MAM_API && typeof window.MAM_API.request === 'function') return cb(true);
    if ((tries || 0) >= 25) return cb(false);
    setTimeout(function () { whenApi(cb, (tries || 0) + 1); }, 120);
  }

  function boot() {
    whenApi(function (ok) {
      if (!ok) {
        gate.innerHTML = '<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>'
          + 'We could not load the application form — part of the page did not download. '
          + 'This is usually a connection problem rather than anything you did.'
          + '<div class="fac-gate-actions">'
          + '<button type="button" class="btn btn-primary" id="facRetry"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Try again</button>'
          + '<a class="btn btn-secondary" href="/contact?topic=Faculty"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Email us instead</a>'
          + '</div>';
        var r = $('facRetry');
        if (r) r.addEventListener('click', function () { location.reload(); });
        return;
      }
      loadCatalogue();
    });
  }

  function loadCatalogue() {
    var q = state.token ? ('faculty-apply?token=' + encodeURIComponent(state.token)) : 'faculty-apply';
    window.MAM_API.request(q, { method: 'GET' })
      .then(function (r) {
        state.catalogue = (r && r.catalogue) || { courses: [], levels: [], programmes: [], modes: [] };
        if (r && r.email) { state.email = r.email; state.emailLocked = true; }
        openForm();
      })
      .catch(function (e) {
        var code = (e && e.body && e.body.error) || '';
        if (code === 'invite_used') gateFail('This link has already been used — each invitation works exactly once. If you need to update your application, ask us and we will send a fresh one.', true);
        else if (code === 'invite_expired') gateFail('This invitation has expired (links are valid for 7 days). Ask us for a fresh one — it takes a minute.', true);
        else if (code === 'invite_required') gateFail('We are not accepting open applications just now. Tell us you are interested and we will send you a personal invitation when we next recruit.', true);
        else if (code === 'invite_invalid') gateFail('This invitation link is not valid. Open the exact link from your email, or ask us for a new one.', true);
        else gateFail('We could not load the application form just now. Please refresh, or try again in a moment.', false);
      });
  }

  function openForm() {
    gate.hidden = true;
    progress.hidden = false;
    form.hidden = false;

    renderMatrix();
    renderChips('facProgrammes', state.catalogue.programmes || [], 'programmes', function (p) { return p.name; }, function (p) { return p.code; });
    renderChips('facModes', (state.catalogue.modes || []).map(function (m) { return { code: m, name: m.charAt(0).toUpperCase() + m.slice(1) }; }), 'modes', function (m) { return m.name; }, function (m) { return m.code; });
    renderAvailability();
    mountHeadshot();

    if (state.emailLocked) {
      var em = $('f-email');
      em.value = state.email;
      em.readOnly = true;
      $('f-email-help').textContent = 'This is the address we invited — it cannot be changed here.';
    }

    loadDraft();
    bindAll();
    showStep(0, true);
  }

  /* ── The teaching matrix ───────────────────────────────────────────────── */

  function renderMatrix() {
    var wrap = $('facMatrix');
    var levels = state.catalogue.levels || [];
    wrap.innerHTML = (state.catalogue.courses || []).map(function (c) {
      var chips = levels.map(function (l) {
        var id = 'sub-' + c.code + '-' + l.code;
        return '<label class="fac-level" for="' + esc(id) + '">'
          + '<input type="checkbox" id="' + esc(id) + '" data-course="' + esc(c.code) + '" data-level="' + esc(l.code) + '">'
          + '<span>' + esc(l.name) + (l.trinity_grades ? '<small>' + esc(l.trinity_grades) + '</small>' : '') + '</span>'
          + '</label>';
      }).join('');
      return '<div class="fac-subject" data-course-card="' + esc(c.code) + '">'
        + '<div class="fac-subject-head">'
        + '<span class="fac-subject-name">' + esc(c.name) + '</span>'
        + (c.kind === 'core' ? '<span class="fac-subject-kind">Core</span>' : '')
        + '</div>'
        + '<div class="fac-levels">' + chips + '</div>'
        + '<div class="field fac-years" hidden data-years-for="' + esc(c.code) + '">'
        + '<label class="field-label" for="yrs-' + esc(c.code) + '">Years teaching ' + esc(c.name) + '</label>'
        + '<input type="number" min="0" max="60" inputmode="numeric" class="field-input" id="yrs-' + esc(c.code) + '" data-years="' + esc(c.code) + '">'
        + '</div>'
        + '</div>';
    }).join('');

    wrap.addEventListener('change', function (e) {
      var cb = e.target;
      if (!cb || cb.type !== 'checkbox' || !cb.dataset.course) return;
      var key = cb.dataset.course + '|' + cb.dataset.level;
      if (cb.checked) state.subjects[key] = true; else delete state.subjects[key];
      syncMatrixCard(cb.dataset.course);
      updateMatrixCount();
      saveDraft();
    });
    wrap.addEventListener('input', function (e) {
      var n = e.target;
      if (!n || !n.dataset || !n.dataset.years) return;
      state.years[n.dataset.years] = n.value;
      saveDraft();
    });
  }

  /* A course with no level chosen has nothing to say about years, so the
     question only appears once it is relevant. */
  function syncMatrixCard(course) {
    var card = document.querySelector('[data-course-card="' + CSS.escape(course) + '"]');
    if (!card) return;
    var any = Object.keys(state.subjects).some(function (k) { return k.split('|')[0] === course; });
    card.classList.toggle('is-chosen', any);
    var yrs = card.querySelector('[data-years-for]');
    if (yrs) yrs.hidden = !any;
  }

  function updateMatrixCount() {
    var keys = Object.keys(state.subjects);
    var courses = {};
    keys.forEach(function (k) { courses[k.split('|')[0]] = true; });
    var nc = Object.keys(courses).length;
    $('facMatrixCount').innerHTML = keys.length
      ? '<strong>' + nc + '</strong> course' + (nc === 1 ? '' : 's') + ', <strong>' + keys.length + '</strong> course–level combination' + (keys.length === 1 ? '' : 's') + ' chosen.'
      : 'Nothing chosen yet.';
  }

  function renderChips(mountId, items, bucket, label, code) {
    var el = $(mountId);
    if (!el) return;
    el.innerHTML = items.map(function (it) {
      var id = bucket + '-' + code(it);
      return '<label class="fac-level" for="' + esc(id) + '">'
        + '<input type="checkbox" id="' + esc(id) + '" data-bucket="' + esc(bucket) + '" value="' + esc(code(it)) + '">'
        + '<span>' + esc(label(it)) + '</span></label>';
    }).join('');
    el.addEventListener('change', function (e) {
      var cb = e.target;
      if (!cb || cb.type !== 'checkbox') return;
      if (cb.checked) state[bucket][cb.value] = true; else delete state[bucket][cb.value];
      saveDraft();
    });
  }

  function renderAvailability() {
    var days = [['mon', 'Mon'], ['tue', 'Tue'], ['wed', 'Wed'], ['thu', 'Thu'], ['fri', 'Fri'], ['sat', 'Sat'], ['sun', 'Sun']];
    var parts = [['morning', 'Morning'], ['afternoon', 'Afternoon'], ['evening', 'Evening']];
    var html = '<span class="fac-avail-head" aria-hidden="true"></span>'
      + days.map(function (d) { return '<span class="fac-avail-head">' + d[1] + '</span>'; }).join('');
    parts.forEach(function (p) {
      html += '<span class="fac-avail-row-label">' + p[1] + '</span>';
      days.forEach(function (d) {
        var slot = d[0] + ':' + p[0];
        // The visible grid is a matrix, so each cell needs its own accessible
        // name — "Mon" and "Morning" are only adjacent visually.
        html += '<label class="fac-avail-cell"><input type="checkbox" data-slot="' + slot + '" '
          + 'aria-label="' + d[1] + ' ' + p[1] + '"><span></span></label>';
      });
    });
    var el = $('facAvail');
    el.innerHTML = html;
    el.addEventListener('change', function (e) {
      var cb = e.target;
      if (!cb || !cb.dataset || !cb.dataset.slot) return;
      if (cb.checked) state.avail[cb.dataset.slot] = true; else delete state.avail[cb.dataset.slot];
      saveDraft();
    });
  }

  function mountHeadshot() {
    if (!window.MAM_HEADSHOT || !$('facHeadshot')) return;
    window.MAM_HEADSHOT.mount($('facHeadshot'), {
      onChange: function (s) {
        state.headshotUrl = (s && s.uploaded && s.uploaded.url) || '';
        if (state.headshotUrl) { $('f-shot').value = state.headshotUrl; saveDraft(); }
      }
    });
  }

  /* ── Steps ─────────────────────────────────────────────────────────────── */

  function showStep(i, silent) {
    state.step = Math.max(0, Math.min(STEPS - 1, i));
    Array.prototype.forEach.call(document.querySelectorAll('#facForm .step'), function (s) {
      s.classList.toggle('active', Number(s.dataset.step) === state.step);
    });
    Array.prototype.forEach.call(progress.querySelectorAll('.apply-progress-step'), function (b) {
      var n = Number(b.dataset.step);
      b.classList.toggle('active', n === state.step);
      b.classList.toggle('done', n < state.step);
      if (n === state.step) b.setAttribute('aria-current', 'step');
      else b.removeAttribute('aria-current');
    });
    if (state.step === STEPS - 1) renderReview();

    var head = document.querySelector('#facForm .step.active .step-title');
    if (head && !silent) {
      // Announce rather than only move focus: a reader following focus into a
      // heading does not say WHICH step of how many, which is the stepper's
      // whole job.
      announce.textContent = 'Step ' + (state.step + 1) + ' of ' + STEPS + ': ' + head.textContent;
      head.setAttribute('tabindex', '-1');
      head.focus({ preventScroll: true });
    }
    window.scrollTo({ top: 0, behavior: silent ? 'auto' : 'smooth' });
    saveDraft();
  }

  /* Which control(s) an error message is ABOUT. `main.js` already gives every
     .field-error a role="alert", so the message is announced; without
     aria-invalid the control itself still reads as fine, so a screen-reader
     user hears a complaint and cannot tell which field it names. */
  var ERR_CONTROLS = {
    'err-subjects': ['facMatrix'],
    'err-referees': ['r1-name', 'r2-name'],
    'err-safeack': ['f-safeack'],
    'err-polack': ['f-polack']
  };

  function setErr(id, on) {
    var e = $(id);
    if (e) e.classList.toggle('shown', !!on);
    var targets = ERR_CONTROLS[id] || [id.replace(/^err-/, '')];
    targets.forEach(function (t) {
      var c = $(t);
      if (!c) return;
      if (on) c.setAttribute('aria-invalid', 'true');
      else c.removeAttribute('aria-invalid');
    });
  }

  function validateStep(i) {
    var ok = true;
    if (i === 0) {
      var any = Object.keys(state.subjects).length > 0;
      setErr('err-subjects', !any); if (!any) ok = false;
    }
    if (i === 1) {
      var name = $('f-name').value.trim();
      setErr('err-f-name', !name); if (!name) ok = false;
      var em = $('f-email').value.trim();
      var emOk = state.emailLocked || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em);
      setErr('err-f-email', !emOk); if (!emOk) ok = false;
    }
    if (i === 3) {
      var bio = $('f-bio').value.trim();
      setErr('err-f-bio', bio.length < 40); if (bio.length < 40) ok = false;
    }
    if (i === 4) {
      var d = $('f-start').value.trim();
      var dOk = d === '' || /^\d{4}-\d{2}-\d{2}$/.test(d);
      setErr('err-f-start', !dOk); if (!dOk) ok = false;
    }
    if (i === 5) {
      var refs = collectReferees();
      setErr('err-referees', refs.length < 2); if (refs.length < 2) ok = false;
      var s1 = $('f-safeack').checked, s2 = $('f-polack').checked;
      setErr('err-safeack', !s1); if (!s1) ok = false;
      setErr('err-polack', !s2); if (!s2) ok = false;
    }
    if (!ok) {
      var firstErr = document.querySelector('#facForm .step.active .field-error.shown');
      if (firstErr) firstErr.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
    return ok;
  }

  function collectReferees() {
    return [1, 2].map(function (n) {
      return {
        name: $('r' + n + '-name').value.trim(),
        relationship: $('r' + n + '-rel').value.trim(),
        organisation: $('r' + n + '-org').value.trim(),
        email: $('r' + n + '-email').value.trim(),
        phone: $('r' + n + '-phone').value.trim()
      };
    }).filter(function (r) { return r.name !== ''; });
  }

  function collectSubjects() {
    return Object.keys(state.subjects).map(function (k) {
      var p = k.split('|');
      return { course: p[0], level: p[1], years: state.years[p[0]] || '' };
    });
  }

  /* ── Review ────────────────────────────────────────────────────────────── */

  function courseName(code) {
    var c = (state.catalogue.courses || []).filter(function (x) { return x.code === code; })[0];
    return c ? c.name : code;
  }
  function levelName(code) {
    var l = (state.catalogue.levels || []).filter(function (x) { return x.code === code; })[0];
    return l ? l.name : code;
  }

  function reviewItem(label, value, step) {
    return '<div class="review-item">'
      + '<div class="review-label">' + esc(label)
      + ' <button type="button" class="review-edit" data-goto="' + step + '">Edit</button></div>'
      + '<div class="review-value">' + (value || '<em>Not given</em>') + '</div></div>';
  }

  function renderReview() {
    var subs = collectSubjects().map(function (s) {
      return esc(courseName(s.course)) + ' — ' + esc(levelName(s.level)) + (s.years ? ' (' + esc(s.years) + ' yrs)' : '');
    });
    var refs = collectReferees().map(function (r) {
      return esc(r.name) + (r.relationship ? ' — ' + esc(r.relationship) : '') + (r.organisation ? ', ' + esc(r.organisation) : '');
    });
    var avail = Object.keys(state.avail);

    $('facReview').innerHTML =
        reviewItem('Courses & levels', subs.length ? subs.join('<br>') : '', 0)
      + reviewItem('Programmes', Object.keys(state.programmes).map(esc).join(', '), 0)
      + reviewItem('Teaching modes', Object.keys(state.modes).map(esc).join(', '), 0)
      + reviewItem('Name', esc($('f-name').value.trim()), 1)
      + reviewItem('Email', esc(state.emailLocked ? state.email : $('f-email').value.trim()), 1)
      + reviewItem('Phone', esc($('f-phone').value.trim()), 1)
      + reviewItem('Based in', esc($('f-location').value.trim()), 1)
      + reviewItem('Years teaching', esc($('f-years').value.trim()), 2)
      + reviewItem('Qualifications', esc($('f-quals').value.trim()).replace(/\n/g, '<br>'), 2)
      + reviewItem('CV', esc($('f-cv').value.trim()), 2)
      + reviewItem('Biography', esc($('f-bio').value.trim()).replace(/\n/g, '<br>'), 3)
      + reviewItem('How you teach', esc($('f-philosophy').value.trim()).replace(/\n/g, '<br>'), 3)
      + reviewItem('Links', esc($('f-links').value.trim()), 3)
      + reviewItem('Availability', avail.length ? (avail.length + ' block' + (avail.length === 1 ? '' : 's') + ' a week') : '', 4)
      + reviewItem('Hours a week', esc($('f-hours').value.trim()), 4)
      + reviewItem('Earliest start', esc($('f-start').value.trim()), 4)
      + reviewItem('Referees', refs.length ? refs.join('<br>') : '', 5);
  }

  /* ── Draft: save & resume ──────────────────────────────────────────────── */

  var TEXT_FIELDS = ['f-name', 'f-email', 'f-phone', 'f-location', 'f-shot', 'f-years', 'f-cv',
    'f-quals', 'f-bio', 'f-philosophy', 'f-links', 'f-hours', 'f-start',
    'r1-name', 'r1-rel', 'r1-org', 'r1-email', 'r1-phone',
    'r2-name', 'r2-rel', 'r2-org', 'r2-email', 'r2-phone'];

  /**
   * Has the candidate actually put anything in?
   *
   * MEASURED: `showStep()` saves, and `openForm()` calls `showStep(0)`, so
   * merely OPENING the page wrote a draft — and the next visit greeted a
   * first-time reader with "We saved your draft on this device" over an empty
   * form. A save-and-resume affordance that fires when there is nothing to
   * resume teaches people to ignore it.
   *
   * An invited address does not count as content: it was filled in FOR them.
   */
  function draftHasContent(d) {
    if (!d) return false;
    var f = d.f || {};
    var typed = Object.keys(f).some(function (k) {
      if (k === 'f-email' && state.emailLocked) return false;
      return String(f[k] || '').trim() !== '';
    });
    return typed
      || Object.keys(d.subjects || {}).length > 0
      || Object.keys(d.programmes || {}).length > 0
      || Object.keys(d.modes || {}).length > 0
      || Object.keys(d.avail || {}).length > 0;
  }

  function saveDraft() {
    try {
      var f = {};
      TEXT_FIELDS.forEach(function (id) { var el = $(id); if (el) f[id] = el.value; });
      var d = {
        f: f, subjects: state.subjects, years: state.years, programmes: state.programmes,
        modes: state.modes, avail: state.avail, step: state.step, at: Date.now()
      };
      // Nothing to resume → write nothing, and clear a draft emptied by hand.
      if (!draftHasContent(d)) { localStorage.removeItem(DRAFT_KEY); return; }
      localStorage.setItem(DRAFT_KEY, JSON.stringify(d));
    } catch (e) { /* private mode, quota — a draft is a convenience, never a dependency */ }
  }

  function loadDraft() {
    var d = null;
    try { d = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null'); } catch (e) { d = null; }
    if (!d || !draftHasContent(d)) return;

    TEXT_FIELDS.forEach(function (id) {
      var el = $(id);
      // An invited address is the invite's; a stale draft must not overwrite it.
      if (id === 'f-email' && state.emailLocked) return;
      if (el && typeof d.f[id] === 'string') el.value = d.f[id];
    });
    state.subjects = d.subjects || {};
    state.years = d.years || {};
    state.programmes = d.programmes || {};
    state.modes = d.modes || {};
    state.avail = d.avail || {};

    Object.keys(state.subjects).forEach(function (k) {
      var p = k.split('|');
      var cb = $('sub-' + p[0] + '-' + p[1]);
      if (cb) cb.checked = true;
      syncMatrixCard(p[0]);
    });
    ['programmes', 'modes'].forEach(function (bucket) {
      Object.keys(state[bucket]).forEach(function (v) {
        var cb = $(bucket + '-' + v); if (cb) cb.checked = true;
      });
    });
    Object.keys(state.avail).forEach(function (slot) {
      var cb = document.querySelector('[data-slot="' + CSS.escape(slot) + '"]');
      if (cb) cb.checked = true;
    });
    Object.keys(state.years).forEach(function (c) {
      var el = $('yrs-' + c); if (el) el.value = state.years[c];
    });
    updateMatrixCount();
    $('facResume').hidden = false;
  }

  function clearDraft() { try { localStorage.removeItem(DRAFT_KEY); } catch (e) {} }

  /* ── Binding ───────────────────────────────────────────────────────────── */

  function bindAll() {
    form.addEventListener('click', function (e) {
      var next = e.target.closest('[data-next]');
      if (next) { if (validateStep(state.step)) showStep(state.step + 1); return; }
      var back = e.target.closest('[data-back]');
      if (back) { showStep(state.step - 1); return; }
      var goto = e.target.closest('[data-goto]');
      if (goto) { showStep(Number(goto.dataset.goto)); }
    });

    form.addEventListener('input', function (e) {
      if (e.target && e.target.id && TEXT_FIELDS.indexOf(e.target.id) !== -1) saveDraft();
    });

    // The stepper only walks BACK freely. Jumping forward past an unfilled step
    // lands on a review with holes in it, which reads as the form losing work.
    progress.addEventListener('click', function (e) {
      var b = e.target.closest('.apply-progress-step');
      if (!b) return;
      var n = Number(b.dataset.step);
      if (n <= state.step) { showStep(n); return; }
      for (var i = state.step; i < n; i++) { if (!validateStep(i)) { showStep(i); return; } }
      showStep(n);
    });

    $('facDiscard').addEventListener('click', function () {
      clearDraft();
      location.reload();
    });

    form.addEventListener('submit', onSubmit);
  }

  /* The server answers a 422 with COLUMN names. "teaching_philosophy" and
     "headshot_url" are our vocabulary, not the candidate's, and a validation
     message they cannot map back to a field on the page is not a message. */
  var FIELD_LABELS = {
    full_name: 'your name', email: 'your email address', phone: 'your phone number',
    subjects: 'the courses and levels you teach', referees: 'your two referees',
    bio: 'your biography', qualifications: 'your qualifications',
    teaching_philosophy: 'how you teach', links: 'your links', cv_url: 'your CV link',
    headshot_url: 'your photo link', location: 'where you are based',
    earliest_start: 'your earliest start date', disciplines: 'the courses you teach',
    safeguarding_ack: 'the safeguarding agreement', policy_ack: 'the policy agreement'
  };
  function fieldLabel(f) { return FIELD_LABELS[f] || String(f).replace(/_/g, ' '); }

  function onSubmit(e) {
    e.preventDefault();
    setErr('err-submit', false);
    for (var i = 0; i < STEPS - 1; i++) {
      if (!validateStep(i)) { showStep(i); return; }
    }

    var btn = $('facSubmit'), orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i> Submitting…';

    var body = {
      full_name: $('f-name').value.trim(),
      phone: $('f-phone').value.trim(),
      location: $('f-location').value.trim(),
      subjects: collectSubjects(),
      referees: collectReferees(),
      programmes: Object.keys(state.programmes),
      teaching_modes: Object.keys(state.modes),
      availability: Object.keys(state.avail),
      experience_years: $('f-years').value ? parseInt($('f-years').value, 10) : undefined,
      qualifications: $('f-quals').value.trim(),
      bio: $('f-bio').value.trim(),
      teaching_philosophy: $('f-philosophy').value.trim(),
      links: $('f-links').value.trim(),
      cv_url: $('f-cv').value.trim(),
      headshot_url: ($('f-shot').value.trim() || state.headshotUrl),
      weekly_hours: $('f-hours').value ? parseInt($('f-hours').value, 10) : undefined,
      earliest_start: $('f-start').value.trim(),
      safeguarding_ack: $('f-safeack').checked ? 1 : 0,
      policy_ack: $('f-polack').checked ? 1 : 0,
      website: $('f-web').value
    };
    if (state.token) body.token = state.token;
    else body.email = $('f-email').value.trim();

    window.MAM_API.request('faculty-apply', { method: 'POST', body: body })
      .then(function (r) {
        clearDraft();
        if (r && r.book_url) $('facBookBtn').href = r.book_url;
        if (r && r.reference) $('facRef').textContent = 'Your reference: ' + r.reference;
        form.hidden = true;
        progress.hidden = true;
        $('facResume').hidden = true;
        $('facDone').hidden = false;
        announce.textContent = 'Application submitted. Please book your interview.';
        window.scrollTo({ top: 0, behavior: 'smooth' });
      })
      .catch(function (e2) {
        btn.disabled = false; btn.innerHTML = orig;
        var code = (e2 && e2.body && e2.body.error) || '';
        var fields = (e2 && e2.body && e2.body.fields) || [];
        var msg;
        if (code === 'invite_used') msg = 'This link was already used to submit. Ask us for a fresh one if you need to change anything.';
        else if (code === 'invite_expired' || code === 'invite_invalid') msg = 'Your invitation expired while you were filling this in. Ask us for a fresh link — your answers are saved on this device.';
        else if (code === 'invite_required') msg = 'We are not accepting open applications just now. Please ask us for an invitation.';
        else if (code === 'validation') msg = 'Some answers need another look' + (fields.length ? ': ' + fields.map(fieldLabel).join(', ') : '') + '.';
        else if (code === 'rate_limited') msg = 'That is a few attempts in a short time. Please wait a minute and try again.';
        else msg = 'We could not submit this just now. Your answers are saved on this device — please try again in a moment.';
        $('err-submit-text').textContent = msg;
        setErr('err-submit', true);
        $('err-submit').scrollIntoView({ block: 'center', behavior: 'smooth' });
      });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
