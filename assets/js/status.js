/* /status — application lookup by reference + email (status.php). Moved out of the
   page at the 2026-11 redesign; the same two endpoints by prefix (SUM- → summer/status,
   anything else → application/status), the same pre-fill and auto-submit from ?ref=&email=,
   the same tuition banner. The page loads no config.js, so the request is the plain
   fetch the old page already fell back to (both are GETs; no CSRF involved). */
(function () {
  'use strict';

  var form    = document.getElementById('stForm');
  var out     = document.getElementById('stResult');
  var btn     = document.getElementById('stGo');
  var refEl   = document.getElementById('stRef');
  var emEl    = document.getElementById('stEmail');
  var tuBanner = document.getElementById('stTuitionBanner');
  var tuLink   = document.getElementById('stTuitionLink');

  // Pre-fill from URL params
  var qs = new URLSearchParams(location.search);
  if (qs.get('ref'))   refEl.value = qs.get('ref');
  if (qs.get('email')) emEl.value  = qs.get('email');

  function syncTuition(ref, email) {
    if (!tuLink) return;
    var q = [];
    if (ref)   q.push('ref='   + encodeURIComponent(ref));
    if (email) q.push('email=' + encodeURIComponent(email));
    tuLink.href = '/tuition' + (q.length ? '?' + q.join('&') : '');
  }

  // ── Stage configs ─────────────────────────────────────────────
  var REG_STAGES = [
    { key: 'received',  label: 'Received' },
    { key: 'confirmed', label: 'Confirmed' },
    { key: 'accepted',  label: 'Accepted' },
    { key: 'enrolled',  label: 'Enrolled' },
  ];
  var REG_ORDER = { received: 0, reviewing: 0, confirmed: 1, accepted: 2, enrolled: 3 };

  var SUM_STAGES = [
    { key: 'received',  label: 'Received' },
    { key: 'confirmed', label: 'Confirmed' },
    { key: 'enrolled',  label: 'Enrolled' },
  ];
  var SUM_ORDER = { received: 0, confirmed: 1, enrolled: 2 };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  // The placeholder where a photo would be: the applicant's initial.
  function initialOf(a) { var n = String((a && a.first_name) || '').trim(); return n ? esc(n.charAt(0).toUpperCase()) : '·'; }

  function buildRail(stages, order, currentStage) {
    var on = order[currentStage] != null ? order[currentStage] : -1;
    var html = '<div class="st-rail" role="list" aria-label="Application progress">';
    stages.forEach(function (s, i) {
      var cls = i < on ? 'done' : (i === on ? 'active' : '');
      var dot = i < on ? '✓' : (i + 1);
      html += '<div class="st-rail-step ' + cls + '" role="listitem">'
        + (i > 0 ? '<div class="st-rail-line' + (i <= on ? ' done' : '') + '" aria-hidden="true"></div>' : '')
        + '<div class="st-rail-dot">' + dot + '</div>'
        + esc(s.label) + '</div>';
    });
    return html + '</div>';
  }

  function fmtDate(iso) {
    if (!iso) return '';
    try {
      return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
    } catch (e) { return iso.slice(0, 10); }
  }

  function renderResult(a) {
    var isSummer = !!(a.is_summer);
    var stage    = a.stage || 'received';
    var isClosed = stage === 'closed' || stage === 'expired';
    var isWait   = stage === 'waitlisted';
    var ref      = refEl.value.trim();
    var email    = emEl.value.trim();

    // Tuition banner
    if (!isSummer && (stage === 'accepted' || stage === 'enrolled' || stage === 'confirmed')) {
      syncTuition(ref, email);
      if (tuBanner) tuBanner.classList.add('show');
    } else {
      if (tuBanner) tuBanner.classList.remove('show');
    }

    // Photo section
    var photoHtml;
    if (a.headshot_url) {
      photoHtml = '<img class="st-result-photo" src="' + esc(a.headshot_url) + '" alt="Applicant photo">';
    } else {
      photoHtml = '<div class="st-result-photo-placeholder" aria-hidden="true">' + initialOf(a) + '</div>';
    }

    // Programme badge
    var progLabel = a.programme || a.pathway || (isSummer ? 'Summer Programme' : 'MAM Academy');
    var progBadge = '<span class="st-result-prog">' + esc(progLabel) + '</span>';

    // Progress rail (skip for terminal states)
    var rail = (isClosed || isWait) ? '' : (isSummer
      ? buildRail(SUM_STAGES, SUM_ORDER, stage)
      : buildRail(REG_STAGES, REG_ORDER, stage));

    // Session dates (summer only)
    var datesHtml = '';
    if (isSummer && (a.session_start || a.session_end)) {
      var d = '';
      if (a.session_start && a.session_end) d = fmtDate(a.session_start) + ' — ' + fmtDate(a.session_end);
      else if (a.session_start) d = 'From ' + fmtDate(a.session_start);
      else d = 'Until ' + fmtDate(a.session_end);
      datesHtml = '<div class="st-dates">Programme dates: <strong>' + esc(d) + '</strong></div>';
    }

    // Meta chips
    var meta = '';
    meta += '<span class="st-meta-chip">Reference <strong>' + esc(a.reference) + '</strong></span>';
    if (a.first_name) meta += '<span class="st-meta-chip">Name <strong>' + esc(a.first_name) + '</strong></span>';
    if (a.submitted_at) meta += '<span class="st-meta-chip">Applied <strong>' + esc(String(a.submitted_at).slice(0,10)) + '</strong></span>';
    if (isSummer && a.session_year) meta += '<span class="st-meta-chip">Summer <strong>' + esc(a.session_year) + '</strong></span>';

    out.innerHTML =
      '<div class="st-result-card stage-' + esc(stage) + '">'
      + '<div class="st-stripe"></div>'
      + '<div class="st-result-header">'
        + photoHtml
        + '<div class="st-result-head-text">'
          + '<div class="st-result-stage-label">' + esc(a.stage_label) + '</div>'
          + '<div class="st-result-name">' + esc(a.first_name || '') + (a.last_name ? ' ' + esc(a.last_name) : '') + '</div>'
          + progBadge
        + '</div>'
      + '</div>'
      + rail
      + datesHtml
      + (a.next_step
        ? '<div class="st-next"><div class="st-next-label">What\'s next</div><div class="st-next-text">' + esc(a.next_step) + '</div></div>'
        : '')
      + '<div class="st-meta">' + meta + '</div>'
      + '</div>';

    out.classList.add('visible');
    out.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function renderNotFound() {
    if (tuBanner) tuBanner.classList.remove('show');
    out.innerHTML =
      '<div class="st-not-found">'
      + '<p>We couldn\'t find an application matching that reference and email.</p>'
      + '<p>Double-check both fields — or <a href="/apply">start a new application</a> · <a href="/summer">summer programme</a></p>'
      + '</div>';
    out.classList.add('visible');
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var ref   = refEl.value.trim();
    var email = emEl.value.trim();
    if (!ref || !email) return;

    var orig = btn.innerHTML;
    if (window.MAM_FORMS) MAM_FORMS.setBusy(btn, 'Checking…');
    else { btn.disabled = true; btn.textContent = 'Checking…'; }
    out.classList.remove('visible');
    out.innerHTML = '';

    // Route by prefix
    var isSummer = ref.replace(/\s/g,'').toUpperCase().startsWith('SUM-');
    var endpoint = isSummer
      ? 'summer/status?ref=' + encodeURIComponent(ref) + '&email=' + encodeURIComponent(email)
      : 'application/status?ref=' + encodeURIComponent(ref) + '&email=' + encodeURIComponent(email);

    function done() { if (window.MAM_FORMS) MAM_FORMS.resetBusy(btn); else { btn.disabled = false; btn.innerHTML = orig; } }

    var p = window.MAM_API
      ? window.MAM_API.request(endpoint, { method: 'GET' })
      : fetch('/api/' + endpoint).then(function (r) { return r.json(); });

    p.then(function (r) {
      done();
      if (r && r.application) renderResult(r.application);
      else renderNotFound();
    }).catch(function () { done(); renderNotFound(); });
  });

  // Auto-submit if URL has both params
  if (refEl.value && emEl.value) form.dispatchEvent(new Event('submit'));
})();
