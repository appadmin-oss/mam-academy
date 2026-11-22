/* MAM Scholars Initiative form (/scholarship) on the 2026-11 shell. Posts the same
   fields the page always sent — full_name, email, age_band, experience, the four
   essays and commitment_ack — to the CSRF-gated POST /api/scholarship/apply, the
   way contact.js does, so the page needs neither config.js nor main.js. The
   server is authoritative; the checks here only save a round trip. */
(function () {
  'use strict';
  var form = document.getElementById('schForm');
  if (!form) return;
  var $ = function (id) { return document.getElementById(id); };
  var fb = $('sch-feedback'), submit = $('sch-submit');
  var ESSAYS = [['financial_need', 'sch-fin'], ['musical_journey', 'sch-journey'], ['values_alignment', 'sch-values'], ['community_impact', 'sch-impact']];
  var FIELD_ID = { full_name: 'sch-name', email: 'sch-email', financial_need: 'sch-fin', musical_journey: 'sch-journey',
                   values_alignment: 'sch-values', community_impact: 'sch-impact', commitment_ack: 'sch-commit' };

  var setErr = function (id, text) {
    var input = $(id), p = $(id + '-err');
    if (!input || !p) return;
    p.textContent = text || ''; p.hidden = !text;
    var box = id === 'sch-commit' ? $('sch-consent-wrap') : input;
    if (text) { input.setAttribute('aria-invalid', 'true'); box.style.borderColor = '#B0361C'; box.style.borderWidth = '2px'; }
    else { input.removeAttribute('aria-invalid'); box.style.borderColor = ''; box.style.borderWidth = ''; }
  };
  var val = function (n) { var el = form.querySelector('[name="' + n + '"]'); return el ? (el.value || '').trim() : ''; };

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    fb.textContent = '';
    var body = { full_name: val('full_name'), email: val('email'), age_band: val('age_band'), experience: val('experience'),
                 commitment_ack: $('sch-commit').checked };
    ESSAYS.forEach(function (p) { body[p[0]] = val(p[0]); });

    var bad = [];
    var check = function (id, msg) { setErr(id, msg); if (msg) bad.push(id); };
    check('sch-name', body.full_name.length < 2 ? 'Enter your full name' : '');
    check('sch-email', !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(body.email) ? 'Enter an email address like name@example.com' : '');
    ESSAYS.forEach(function (p) { check(p[1], body[p[0]].length < 10 ? 'A sentence or two, please' : ''); });
    check('sch-commit', body.commitment_ack ? '' : 'Please confirm your commitment');
    if (bad.length) {
      fb.textContent = 'Please complete the highlighted fields (a sentence or two each) and confirm your commitment.';
      $(bad[0]).focus();
      return;
    }

    var label = submit.innerHTML;
    submit.disabled = true; submit.innerHTML = 'Submitting…'; submit.setAttribute('aria-busy', 'true');
    var done = function () { submit.disabled = false; submit.innerHTML = label; submit.removeAttribute('aria-busy'); };
    var post = function (token) {
      return fetch('/api/scholarship/apply', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify(body) });
    };
    var csrf = function () {
      return fetch('/api/csrf', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { return j && (j.token || (j.data && j.data.token)) || ''; });
    };
    csrf().then(post).then(function (r) {
      /* A stale token is refused once with csrf_invalid: fetch a fresh one and retry. */
      if (r.status !== 403) return r;
      return r.clone().json().catch(function () { return {}; }).then(function (j) {
        return j && j.error === 'csrf_invalid' ? csrf().then(post) : r;
      });
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { return { r: r, j: j || {} }; });
    }).then(function (res) {
      if (res.r.ok) {
        var data = res.j.data || res.j;
        var ref = String(data.reference || '').replace(/[^A-Za-z0-9-]/g, '');
        var grid = $('schGrid');
        grid.innerHTML =
          '<div role="status" style="text-align: center; display: flex; flex-direction: column; align-items: center; gap: 16px">' +
          '<h2 style="margin: 0; font-family: \'Cormorant Garamond\', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">Thank you — your application is in</h2>' +
          '<p style="margin: 0; max-width: 560px; font-size: 18px; line-height: 1.6; color: #3E342B">We’re honoured you applied to the MAM Scholars Initiative. Our committee reviews every application holistically; we’ll be in touch by email.</p>' +
          (ref ? '<p style="margin: 0; padding: 8px 16px; border-radius: 12px; background: #F3EEE6; font-family: monospace; font-size: 18px; color: #211A13">' + ref + '</p><p style="margin: 0; font-size: 15px; color: #574B40">Keep this reference to check your status.</p>' : '') +
          '<a href="/" class="btn" style="display: inline-flex; align-items: center; min-height: 52px; padding: 0 25px; border-radius: 999px; border: 1px solid #CFC3B3; color: #211A13; font-weight: 600; text-decoration: none">Back to home</a>' +
          '</div>';
        grid.scrollIntoView({ behavior: 'smooth', block: 'center' }); // 'start' hides the heading under the sticky bar
        return;
      }
      done();
      var fields = (res.j && res.j.fields) || [];
      fields.forEach(function (n) { if (FIELD_ID[n]) setErr(FIELD_ID[n], 'Please check this answer'); });
      fb.textContent = res.r.status === 429 ? 'Too many submissions — please try again later.'
                                            : 'We couldn’t submit that. Please check the highlighted fields and try again.';
    }).catch(function () {
      done();
      fb.textContent = 'We couldn’t reach the server. Please check your connection and try again.';
    });
  });
})();
