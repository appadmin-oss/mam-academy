/**
 * MAM Academy — instructor application wizard (v2, the "Quiet" four-step rework).
 *
 * Four steps, mirroring the student wizard's shape and reusing its components
 * (`.step`, the draft plumbing, the error tones) so the two forms cannot drift
 * into two house styles. The differences are deliberate: no payment step
 * (nobody pays to apply to teach), a structured teaching list instead of a
 * free-text discipline field, and a references step that collects referee
 * CONTACTS and two commitments — never a certificate, a document number, or
 * anything about an offence.
 *
 * WHAT CHANGED FROM THE SEVEN-STEP VERSION, and why each is not a regression:
 *
 *  · Training + Experience + Availability collapsed into "Track record"; the
 *    five questions the handoff cuts from that step are NOT cut from the
 *    product — they live behind the "Add more detail (optional)" disclosure
 *    with their ids, their validation and their place in the POST body intact.
 *  · Courses come first, then levels. The old matrix rendered 12 courses × 4
 *    levels — 48 checkboxes before the applicant had said anything.
 *  · A course now carries its HIGHEST level, chosen with radio semantics.
 *    `collectSubjects()` expands that to the full 0..N set at collect time, so
 *    the API contract (`subjects: [{course, level, years}]`) is untouched and
 *    `mam_faculty_normalise_subjects()` sees exactly what it always saw.
 *  · A referee's email and phone became one "Email or phone" field.
 *    `collectReferees()` routes it back into the `email`/`phone` keys the
 *    endpoint normalises — an address goes to `email`, anything else to
 *    `phone`, which is what `filter_var(..., FILTER_VALIDATE_EMAIL)` on the
 *    server would have decided anyway.
 *  · The review step is gone; its job (see it before you send it) is done by
 *    the desktop running summary and by four screens short enough to check.
 *
 * The availability state key is UNCHANGED (`state.avail['mon:morning']`), and
 * `loadDraft()` migrates a seven-step draft — including the old per-course
 * `subjects` map and the split referee contacts — so somebody who started the
 * old form finishes on the new one rather than losing their work.
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
  var STEPS = 4;
  var STEP_NAMES = ['Courses', 'You', 'Track record', 'References'];

  var gate = $('facGate');
  var form = $('facForm');
  var progWrap = $('facProgWrap');
  var progFill = $('facProgFill');
  var announce = $('facAnnounce');

  var state = {
    token: new URLSearchParams(location.search).get('token') || '',
    email: '',
    emailLocked: false,        // an invited address is the invite's, not the body's
    catalogue: null,
    step: 0,
    courses: {},               // course code -> true
    levels: {},                // course code -> the HIGHEST level code claimed
    years: {},                 // legacy per-course years, carried from an old draft only
    programmes: {},
    modes: {},
    avail: {},                 // 'mon:morning' -> true  (key unchanged from v1)
    headshotUrl: ''
  };

  /**
   * The handoff's §4 accessibility contract is explicit that
   * `prefers-reduced-motion` is respected, and smooth scrolling is motion the
   * user did not ask for — for some vestibular conditions it is the motion
   * that actually causes symptoms. Read at CALL time, not captured once: the
   * setting can change while the page is open, and an application is open for
   * a while.
   */
  function motionOk() {
    try { return !window.matchMedia('(prefers-reduced-motion: reduce)').matches; }
    catch (e) { return true; }
  }
  function scrollBehavior() { return motionOk() ? 'smooth' : 'auto'; }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* The chip reads "1-2", not "Grade 1-2" — the row already says "up to grade",
     and at 12.5px in a quarter of 390px the word does not fit twice. The full
     level name goes on the control's aria-label, so nothing is lost to a
     reader. (The handoff's prose and its desktop frame both say "up to"; its
     MOBILE frame says "up to grade", which is what the academy chose.) */
  function gradeChip(level) {
    var g = String(level.trinity_grades || '').replace(/^\s*grades?\s+/i, '').trim();
    return g || level.name;
  }
  function levelFullName(level) {
    return level.trinity_grades ? level.name + ', ' + level.trinity_grades : level.name;
  }

  /* ── Gate ──────────────────────────────────────────────────────────────── */

  /* One attribute drives what the app bar carries, whether the page reserves
     room for the fixed action bar, and whether the logo shows below 1024px.
     'loading' is set in the markup so the bar is branded before JS runs. */
  function setState(st) { document.body.setAttribute('data-fac-state', st); }

  function gateFail(msg, showAsk) {
    setState('gate');
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
        setState('gate');
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
    setState('form');
    gate.hidden = true;
    progWrap.hidden = false;
    $('facPos').hidden = false;
    $('facExit').hidden = false;
    form.hidden = false;

    renderMatrix();
    renderSteps();
    renderOpts('facProgrammes', state.catalogue.programmes || [], 'programmes',
      function (p) { return p.name; }, function (p) { return p.code; });
    renderOpts('facModes', (state.catalogue.modes || []).map(function (m) {
      return { code: m, name: m.charAt(0).toUpperCase() + m.slice(1) };
    }), 'modes', function (m) { return m.name; }, function (m) { return m.code; });
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
    trackDock();
    showStep(state.step, true);
  }

  /* ── Step 1 · the course list ──────────────────────────────────────────── */

  /**
   * One row per course, hairline-separated. The level control is rendered for
   * every row but revealed only under a chosen course — building it up front
   * keeps `loadDraft()` able to tick a restored level without re-rendering, and
   * a `hidden` container is not in the tab order.
   */
  function renderMatrix() {
    var wrap = $('facMatrix');
    var levels = state.catalogue.levels || [];
    wrap.innerHTML = (state.catalogue.courses || []).map(function (c) {
      var chips = levels.map(function (l) {
        return '<label class="fac-grade">'
          + '<input type="radio" name="lvl-' + esc(c.code) + '" id="lvl-' + esc(c.code) + '-' + esc(l.code) + '"'
          + ' data-course="' + esc(c.code) + '" data-level="' + esc(l.code) + '"'
          + ' aria-label="' + esc(c.name + ' — up to ' + levelFullName(l)) + '">'
          + '<span>' + esc(gradeChip(l)) + '</span></label>';
      }).join('');
      return '<div class="fac-row" data-course-card="' + esc(c.code) + '">'
        + '<label class="fac-row-head" for="course-' + esc(c.code) + '">'
        + '<input type="checkbox" class="fac-row-cb" id="course-' + esc(c.code) + '" data-course-cb="' + esc(c.code) + '">'
        + '<span class="fac-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>'
        + '<span class="fac-row-name">' + esc(c.name) + '</span>'
        // The four core courses carry the marker on the row itself. It replaces
        // two section headings and their helper lines: "Core" belongs on the
        // rows that are core, not above a group of them.
        + (c.kind === 'core' ? '<span class="fac-row-core">Core</span>' : '')
        + '</label>'
        + '<div class="fac-grades" hidden role="group" aria-label="' + esc('Highest level you can teach ' + c.name) + '">'
        + '<span class="fac-grades-label">up to grade</span>' + chips
        + '</div>'
        + '</div>';
    }).join('');

    wrap.addEventListener('change', function (e) {
      var el = e.target;
      if (!el || !el.dataset) return;
      if (el.dataset.courseCb) {
        var code = el.dataset.courseCb;
        if (el.checked) state.courses[code] = true;
        else { delete state.courses[code]; delete state.levels[code]; }
        syncMatrixCard(code);
      } else if (el.dataset.level) {
        state.levels[el.dataset.course] = el.dataset.level;
      } else return;
      updateSummary();
      saveDraft();
    });
  }

  /* A course with no level chosen has nothing to say about levels, so the
     control only appears once it is relevant. */
  function syncMatrixCard(course) {
    var card = document.querySelector('[data-course-card="' + CSS.escape(course) + '"]');
    if (!card) return;
    var on = !!state.courses[course];
    var grades = card.querySelector('.fac-grades');
    if (grades) grades.hidden = !on;
    if (!on) {
      Array.prototype.forEach.call(card.querySelectorAll('input[type="radio"]'), function (r) { r.checked = false; });
    }
  }

  /* ── Chip groups (teaching modes, programmes) ──────────────────────────── */

  function renderOpts(mountId, items, bucket, label, code) {
    var el = $(mountId);
    if (!el) return;
    el.innerHTML = items.map(function (it) {
      var id = bucket + '-' + code(it);
      return '<label class="fac-opt" for="' + esc(id) + '">'
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

  /* ── Step 3 · the week ─────────────────────────────────────────────────── */

  /**
   * One row per day, three part toggles. The old grid was
   * `84px + 7 × minmax(52px,1fr)` at a 460px minimum inside a 358px card, so it
   * scrolled sideways on every phone the faculty funnel actually sees.
   *
   * Each cell keeps its OWN aria-label: "Mon" and "Morning" are adjacent only
   * visually, and a checkbox named by neither is a checkbox named by nothing.
   */
  function renderAvailability() {
    var days = [['mon', 'Mon'], ['tue', 'Tue'], ['wed', 'Wed'], ['thu', 'Thu'], ['fri', 'Fri'], ['sat', 'Sat'], ['sun', 'Sun']];
    var parts = [['morning', 'Morning'], ['afternoon', 'Afternoon'], ['evening', 'Evening']];
    var el = $('facAvail');
    el.innerHTML = days.map(function (d) {
      return '<div class="fac-day">'
        + '<span class="fac-day-name" aria-hidden="true">' + d[1] + '</span>'
        + parts.map(function (p) {
          var slot = d[0] + ':' + p[0];
          return '<label class="fac-slot">'
            + '<input type="checkbox" data-slot="' + slot + '" aria-label="' + d[1] + ' ' + p[1] + '">'
            + '<span>' + p[1] + '</span></label>';
        }).join('')
        + '</div>';
    }).join('');
    el.addEventListener('change', function (e) {
      var cb = e.target;
      if (!cb || !cb.dataset || !cb.dataset.slot) return;
      if (cb.checked) state.avail[cb.dataset.slot] = true; else delete state.avail[cb.dataset.slot];
      saveDraft();
    });
  }

  /* ── Headshot ──────────────────────────────────────────────────────────── */

  /**
   * The 220px dashed dropzone became a 48px row — on a 390px screen the old one
   * was a third of the viewport for an optional field.
   *
   * ⚠️ The UPLOADER is untouched. `MAM_HEADSHOT.mount()` is still called once,
   * on the same element, at the same point in the lifecycle; only the
   * container's visibility is deferred. That chain has four independent
   * failure points and all four have failed in production, so the change here
   * is deliberately confined to a `hidden` attribute.
   */
  function mountHeadshot() {
    if (!window.MAM_HEADSHOT || !$('facHeadshot')) return;
    window.MAM_HEADSHOT.mount($('facHeadshot'), {
      onChange: function (s) {
        state.headshotUrl = (s && s.uploaded && s.uploaded.url) || '';
        if (state.headshotUrl) {
          $('f-shot').value = state.headshotUrl;
          markHeadshot(state.headshotUrl);
          saveDraft();
        }
      }
    });
  }

  function markHeadshot(url) {
    var icon = $('facShotIcon'), st = $('facShotState');
    if (!icon || !st) return;
    if (url) {
      // The row reports a STORED url and nothing else. No branch here may say
      // "accepted" without one — a green tick over an empty column is exactly
      // how this shipped a faceless printed card once already.
      icon.style.backgroundImage = 'url("' + url.replace(/"/g, '%22') + '")';
      icon.innerHTML = '';
      st.textContent = 'Added';
    } else {
      icon.style.backgroundImage = '';
      icon.innerHTML = '<i class="fa-solid fa-camera" aria-hidden="true"></i>';
      st.textContent = 'Optional';
    }
  }

  /* ── The dock ──────────────────────────────────────────────────────────── */

  /**
   * Keep `--fac-dock-h` equal to whatever `window.mamDock` is currently
   * holding, so the action bar rides above it instead of under it.
   *
   * The dock is the column-reverse stack the cookie banner lives in, at
   * z-index 950 — above this bar ON PURPOSE, because a consent notice must not
   * be covered by a form control. MEASURED at `bottom:0`: the banner's Accept
   * button sat directly over Submit and the form could not be sent on a first
   * visit. This is the same collision the redesign's floating Apply pill hit,
   * and the reason that pill had to join the dock rather than sit beside it.
   *
   * Measured rather than assumed: the banner's height depends on how its
   * sentence wraps, which depends on the text size the accessibility toolbar is
   * set to. An EMPTY dock measures 0 here, not its own padding — otherwise
   * dismissing the banner would leave the bar permanently floating.
   */
  function trackDock() {
    var set = function (px) { document.body.style.setProperty('--fac-dock-h', px + 'px'); };
    var watch = function (dock) {
      var apply = function () {
        var shown = Array.prototype.filter.call(dock.children, function (c) { return c.offsetParent !== null; });
        set(shown.length ? dock.offsetHeight : 0);
      };
      apply();
      try { new ResizeObserver(apply).observe(dock); }
      catch (e) { window.addEventListener('resize', apply); }
      try { new MutationObserver(apply).observe(dock, { childList: true, attributes: true, subtree: true }); } catch (e) {}
    };
    var dock = document.getElementById('mamDock');
    if (dock) return watch(dock);
    // main.js creates it on demand, so it may not exist yet.
    try {
      var mo = new MutationObserver(function () {
        var d = document.getElementById('mamDock');
        if (d) { mo.disconnect(); watch(d); }
      });
      mo.observe(document.body, { childList: true });
    } catch (e) {}
  }

  /* ── Steps ─────────────────────────────────────────────────────────────── */

  function renderSteps() {
    $('facSteps').innerHTML = STEP_NAMES.map(function (n, i) {
      return '<button type="button" class="fac-step-row" data-step-to="' + i + '">'
        + '<span class="fac-step-dot" aria-hidden="true"></span>'
        + '<span class="fac-step-label">' + esc(n) + '</span></button>';
    }).join('');
  }

  function showStep(i, silent) {
    state.step = Math.max(0, Math.min(STEPS - 1, i));
    var last = state.step === STEPS - 1;

    Array.prototype.forEach.call(document.querySelectorAll('#facForm .step'), function (s) {
      s.classList.toggle('active', Number(s.dataset.step) === state.step);
    });
    Array.prototype.forEach.call($('facSteps').querySelectorAll('.fac-step-row'), function (b) {
      if (Number(b.dataset.stepTo) === state.step) b.setAttribute('aria-current', 'step');
      else b.removeAttribute('aria-current');
    });

    $('facPos').textContent = (state.step + 1) + ' of ' + STEPS;
    $('facBack').hidden = state.step === 0;
    progFill.style.width = ((state.step + 1) / STEPS * 100) + '%';
    $('facNextLabel').textContent = last ? 'Submit' : 'Next';
    $('facNextIcon').className = last ? 'fa-solid fa-paper-plane' : 'fa-solid fa-arrow-right';

    updateSummary();

    var head = document.querySelector('#facForm .step.active .fac-h');
    if (head && !silent) {
      // Announce rather than only move focus: a reader following focus into a
      // heading does not say WHICH step of how many, which is the stepper's
      // whole job.
      announce.textContent = 'Step ' + (state.step + 1) + ' of ' + STEPS + ': ' + head.textContent;
      head.setAttribute('tabindex', '-1');
      head.focus({ preventScroll: true });
    }
    window.scrollTo({ top: 0, behavior: silent ? 'auto' : scrollBehavior() });
    saveDraft();
  }

  /* ── The running summary (bar status + desktop "So far") ───────────────── */

  function courseName(code) {
    var c = (state.catalogue.courses || []).filter(function (x) { return x.code === code; })[0];
    return c ? c.name : code;
  }
  function levelByCode(code) {
    return (state.catalogue.levels || []).filter(function (x) { return x.code === code; })[0] || null;
  }

  function chosenCourses() {
    return Object.keys(state.courses).filter(function (c) { return state.courses[c]; });
  }

  function updateSummary() {
    var n = chosenCourses().length;
    var bar = $('facBarStatus');
    bar.textContent = '';
    if (state.step === 0 && n) {
      bar.appendChild(document.createTextNode(n + ' chosen'));
      if (hasDraft()) {
        var d = document.createElement('span');
        d.className = 'fac-bar-draft';
        d.textContent = ' · draft saved';
        bar.appendChild(d);
      }
    }

    var list = $('facSoFarList'), panel = $('facSoFar');
    if (!list || !panel) return;
    var years = ($('f-years').value || '').trim();
    var rows = chosenCourses().map(function (c) {
      var lv = levelByCode(state.levels[c]);
      var bits = [];
      if (lv) bits.push('up to ' + levelFullName(lv));
      if (years) bits.push(years + (years === '1' ? ' year' : ' years'));
      return '<div class="fac-sofar-item"><span class="fac-sofar-name">' + esc(courseName(c)) + '</span>'
        + (bits.length ? '<span class="fac-sofar-detail">' + esc(bits.join(' · ')) + '</span>' : '')
        + '</div>';
    });
    list.innerHTML = rows.join('');
    panel.hidden = rows.length === 0;
  }

  /* ── Validation ────────────────────────────────────────────────────────── */

  /* Which control(s) an error message is ABOUT. `main.js` already gives every
     error a role="alert", so the message is announced; without aria-invalid the
     control itself still reads as fine, so a screen-reader user hears a
     complaint and cannot tell which field it names. */
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

  /**
   * The rules are the seven-step form's, remapped to four. Nothing was relaxed:
   * step 4 still demands two referees and both commitments, and the optional
   * date still has to parse if it was filled in — it now lives inside the
   * "Add more detail" disclosure, which is opened before the error is shown so
   * the candidate can see the field the message is about.
   */
  function validateStep(i, quiet) {
    var ok = true;
    if (i === 0) {
      var any = chosenCourses().length > 0;
      setErr('err-subjects', !any); if (!any) ok = false;
    }
    if (i === 1) {
      var name = $('f-name').value.trim();
      setErr('err-f-name', !name); if (!name) ok = false;
      var em = $('f-email').value.trim();
      var emOk = state.emailLocked || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em);
      setErr('err-f-email', !emOk); if (!emOk) ok = false;
    }
    if (i === 2) {
      var bio = $('f-bio').value.trim();
      setErr('err-f-bio', bio.length < 40); if (bio.length < 40) ok = false;
      var d = $('f-start').value.trim();
      var dOk = d === '' || /^\d{4}-\d{2}-\d{2}$/.test(d);
      setErr('err-f-start', !dOk);
      if (!dOk) { ok = false; $('facMore').open = true; }
    }
    if (i === 3) {
      var refs = collectReferees();
      var refsOk = refs.length >= 2;
      setErr('err-referees', !refsOk);
      // A folded referee 2 cannot answer a complaint about referee 2.
      if (!refsOk) { ok = false; $('facRef2').open = true; }
      var s1 = $('f-safeack').checked, s2 = $('f-polack').checked;
      setErr('err-safeack', !s1); if (!s1) ok = false;
      setErr('err-polack', !s2); if (!s2) ok = false;
    }
    if (!ok && !quiet) {
      var firstErr = document.querySelector('#facForm .step.active .fac-err.shown');
      if (firstErr) firstErr.scrollIntoView({ block: 'center', behavior: scrollBehavior() });
    }
    return ok;
  }

  /**
   * Once a step has complained, it re-checks itself as the candidate answers.
   *
   * MEASURED: both consent boxes were ticked and both refusals were still on
   * screen underneath them, because the errors were only ever recomputed on the
   * next press of the button. A refusal sitting under the very control that
   * satisfies it reads as a broken form, and the candidate's next move is to
   * press Submit again to find out.
   *
   * Deliberately does nothing until something is already shown: live validation
   * before the first attempt complains about fields the candidate has not
   * reached yet. It is also `quiet` — re-running the check must not drag the
   * viewport around while somebody is typing.
   */
  function revalidate() {
    if (!document.querySelector('#facForm .step.active .fac-err.shown')) return;
    validateStep(state.step, true);
  }

  /**
   * "Email or phone" is one field on the page and two keys in the payload.
   * An address goes to `email`, anything else to `phone` — which is the same
   * decision `mam_faculty_normalise_referees()` makes on the server with
   * `filter_var(..., FILTER_VALIDATE_EMAIL)`, so a value that reaches one key
   * here would have been rejected out of the other there.
   *
   * `organisation` is deliberately no longer asked: the handoff cuts referee
   * fields from five to three, and "How do they know you?" carries the
   * organisation ("Head of Music, my last school"). The key is still sent so
   * the panel's notification email template needs no change.
   */
  function collectReferees() {
    return [1, 2].map(function (n) {
      var contact = $('r' + n + '-contact').value.trim();
      var isEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contact);
      return {
        name: $('r' + n + '-name').value.trim(),
        relationship: $('r' + n + '-rel').value.trim(),
        organisation: '',
        email: isEmail ? contact : '',
        phone: isEmail ? '' : contact
      };
    }).filter(function (r) { return r.name !== ''; });
  }

  /**
   * HIGHEST LEVEL ONLY on the page; the full set in the payload.
   *
   * The row asks for the furthest a candidate can take a student, and
   * everything below it is assumed. The API has always received one row per
   * (course, level), and `instructor_assignments` has a UNIQUE index of that
   * shape, so the chosen index is expanded to 0..N here rather than changing a
   * contract three tables deep.
   *
   * A chosen course with NO level is still sent — as a level-less row, which is
   * what the old form produced for a course whose boxes were all clear, and
   * which `mam_faculty_normalise_subjects()` already stores with a NULL level.
   */
  function collectSubjects() {
    var levels = state.catalogue.levels || [];
    var out = [];
    chosenCourses().forEach(function (code) {
      var years = state.years[code] || '';
      var top = state.levels[code] || '';
      var idx = -1;
      for (var i = 0; i < levels.length; i++) { if (levels[i].code === top) { idx = i; break; } }
      if (idx < 0) { out.push({ course: code, level: '', years: years }); return; }
      for (var j = 0; j <= idx; j++) out.push({ course: code, level: levels[j].code, years: years });
    });
    return out;
  }

  /* ── Draft: save & resume ──────────────────────────────────────────────── */

  var TEXT_FIELDS = ['f-name', 'f-email', 'f-phone', 'f-location', 'f-shot', 'f-years', 'f-cv',
    'f-quals', 'f-bio', 'f-philosophy', 'f-links', 'f-hours', 'f-start',
    'r1-name', 'r1-rel', 'r1-contact',
    'r2-name', 'r2-rel', 'r2-contact'];

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
      || Object.keys(d.courses || {}).length > 0
      || Object.keys(d.subjects || {}).length > 0       // a seven-step draft
      || Object.keys(d.programmes || {}).length > 0
      || Object.keys(d.modes || {}).length > 0
      || Object.keys(d.avail || {}).length > 0;
  }

  function hasDraft() {
    try { return !!localStorage.getItem(DRAFT_KEY); } catch (e) { return false; }
  }

  function saveDraft() {
    try {
      var f = {};
      TEXT_FIELDS.forEach(function (id) { var el = $(id); if (el) f[id] = el.value; });
      var d = {
        f: f, courses: state.courses, levels: state.levels, years: state.years,
        programmes: state.programmes, modes: state.modes, avail: state.avail,
        step: state.step, at: Date.now()
      };
      // Nothing to resume → write nothing, and clear a draft emptied by hand.
      if (!draftHasContent(d)) { localStorage.removeItem(DRAFT_KEY); return; }
      localStorage.setItem(DRAFT_KEY, JSON.stringify(d));
    } catch (e) { /* private mode, quota — a draft is a convenience, never a dependency */ }
  }

  /**
   * A seven-step draft is MIGRATED, not discarded. Somebody who opened the old
   * form yesterday finishes on the new one: `subjects['piano|cert']` becomes
   * `courses.piano` + `levels.piano = the highest level claimed`, and the split
   * referee email/phone fold into the single contact field. `step` is clamped,
   * because step 6 does not exist any more.
   */
  function migrateDraft(d) {
    if (!d) return d;
    if (!d.courses && d.subjects) {
      var levels = state.catalogue.levels || [];
      var rank = {}; levels.forEach(function (l, i) { rank[l.code] = i; });
      d.courses = {}; d.levels = {};
      Object.keys(d.subjects).forEach(function (k) {
        var p = k.split('|'), c = p[0], l = p[1];
        d.courses[c] = true;
        if (l && rank[l] !== undefined && (d.levels[c] === undefined || rank[l] > rank[d.levels[c]])) d.levels[c] = l;
      });
    }
    var f = d.f || {};
    [1, 2].forEach(function (n) {
      if (f['r' + n + '-contact']) return;
      var v = String(f['r' + n + '-email'] || '').trim() || String(f['r' + n + '-phone'] || '').trim();
      if (v) f['r' + n + '-contact'] = v;
    });
    d.f = f;
    return d;
  }

  function loadDraft() {
    var d = null;
    try { d = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null'); } catch (e) { d = null; }
    if (!d || !draftHasContent(d)) return;
    d = migrateDraft(d);

    TEXT_FIELDS.forEach(function (id) {
      var el = $(id);
      // An invited address is the invite's; a stale draft must not overwrite it.
      if (id === 'f-email' && state.emailLocked) return;
      if (el && typeof d.f[id] === 'string') el.value = d.f[id];
    });
    state.courses = d.courses || {};
    state.levels = d.levels || {};
    state.years = d.years || {};
    state.programmes = d.programmes || {};
    state.modes = d.modes || {};
    state.avail = d.avail || {};
    state.step = Math.max(0, Math.min(STEPS - 1, Number(d.step) || 0));

    Object.keys(state.courses).forEach(function (c) {
      var cb = $('course-' + c);
      if (cb) cb.checked = true;
      var lv = state.levels[c];
      if (lv) { var r = $('lvl-' + c + '-' + lv); if (r) r.checked = true; }
      syncMatrixCard(c);
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

    // A restored answer must never sit behind a closed disclosure.
    if (['f-quals', 'f-philosophy', 'f-cv', 'f-links', 'f-start'].some(function (id) { return ($(id).value || '').trim() !== ''; })
      || Object.keys(state.programmes).length) $('facMore').open = true;
    if (($('r2-name').value || '').trim() || ($('r2-rel').value || '').trim() || ($('r2-contact').value || '').trim()) $('facRef2').open = true;

    var shot = ($('f-shot').value || '').trim();
    if (shot) { state.headshotUrl = shot; markHeadshot(shot); $('facShotPanel').hidden = false; $('facShotToggle').setAttribute('aria-expanded', 'true'); }

    updateSummary();
    $('facResume').hidden = false;
  }

  function clearDraft() { try { localStorage.removeItem(DRAFT_KEY); } catch (e) {} }

  /* ── Binding ───────────────────────────────────────────────────────────── */

  function bindAll() {
    $('facBack').addEventListener('click', function () { showStep(state.step - 1); });

    // The draft is already written on every keystroke, so "Save & exit" only
    // has to be honest about it — one more save covers the keystroke that has
    // not fired its `input` yet.
    $('facExit').addEventListener('click', saveDraft);

    form.addEventListener('input', function (e) {
      revalidate();
      if (!e.target || !e.target.id) return;
      if (TEXT_FIELDS.indexOf(e.target.id) === -1) return;
      if (e.target.id === 'f-years') updateSummary();
      saveDraft();
    });
    // A checkbox or radio fires `change`, not `input`, in older WebKit.
    form.addEventListener('change', revalidate);

    // The desktop step list walks BACK freely. Jumping forward past an unfilled
    // step lands on a later screen with holes behind it, which reads as the
    // form losing work.
    $('facSteps').addEventListener('click', function (e) {
      var b = e.target.closest('.fac-step-row');
      if (!b) return;
      var n = Number(b.dataset.stepTo);
      if (n <= state.step) { showStep(n); return; }
      for (var i = state.step; i < n; i++) { if (!validateStep(i)) { showStep(i); return; } }
      showStep(n);
    });

    var shotToggle = $('facShotToggle');
    shotToggle.addEventListener('click', function () {
      var panel = $('facShotPanel');
      panel.hidden = !panel.hidden;
      shotToggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
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

  /**
   * ONE submit listener drives the whole wizard — the bar's button is the only
   * primary control on any step, and it is a real `type="submit"`. On steps
   * 1–3 it validates and advances; on step 4 it sends. That keeps the audit's
   * headline finding closed (the fields are in a `<form>` with a `submit`
   * listener, not behind click handlers) and makes Enter in any text field do
   * what a candidate expects on every screen instead of only the last.
   */
  function onSubmit(e) {
    e.preventDefault();
    if (state.step < STEPS - 1) {
      if (validateStep(state.step)) showStep(state.step + 1);
      return;
    }

    setErr('err-submit', false);
    for (var i = 0; i < STEPS; i++) {
      if (!validateStep(i)) { showStep(i); return; }
    }

    var btn = $('facNext'), orig = btn.innerHTML;
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
        setState('done');
        form.hidden = true;
        progWrap.hidden = true;
        $('facPos').hidden = true;
        $('facBack').hidden = true;
        $('facExit').hidden = true;
        $('facResume').hidden = true;
        $('facDone').hidden = false;
        announce.textContent = 'Application submitted. Please book your interview.';
        window.scrollTo({ top: 0, behavior: scrollBehavior() });
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
        $('err-submit').scrollIntoView({ block: 'center', behavior: scrollBehavior() });
      });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
