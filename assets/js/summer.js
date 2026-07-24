/**
 * MAM Academy — Summer Programme application form handler.
 * Manages: headshot widget, client-side age validation, conditional guardian
 * section, form submission to POST /api/summer/apply, and success state.
 */
(function () {
  'use strict';

  var form       = document.getElementById('summerApplyForm');
  var errBox     = document.getElementById('summerFormError');
  var success    = document.getElementById('summerSuccess');
  var successRef = document.getElementById('summerSuccessRef');
  var submitBtn  = document.getElementById('summerSubmitBtn');

  if (!form) return; // form not on page (closed/not-yet state)

  var minAge = parseInt(form.dataset.minAge || '0', 10);
  var maxAge = parseInt(form.dataset.maxAge || '0', 10);

  // ── Headshot widget ────────────────────────────────────────────
  var hsMount  = document.getElementById('suHeadshotMount');
  var hsUrlIn  = document.getElementById('suHeadshotUrl');
  var hsState  = { uploaded: null, uploading: false, validation: { ok: false } };

  if (hsMount && window.MAM_HEADSHOT) {
    window.MAM_HEADSHOT.mount(hsMount, {
      onChange: function (state) {
        hsState = state;
        if (hsUrlIn) hsUrlIn.value = (state.uploaded && state.uploaded.url) ? state.uploaded.url : '';
      }
    });
  }

  // ── Guardian conditional display ───────────────────────────────
  var guardSection   = document.getElementById('suGuardianSection');
  var guardReqNote   = document.getElementById('suGuardianReqNote');
  var guardOptNote   = document.getElementById('suGuardianOptNote');
  var guardNameReq   = document.getElementById('suGuardianNameReq');
  var guardPhoneReq  = document.getElementById('suGuardianPhoneReq');
  var sigHint        = document.getElementById('suPolicySigHint');
  var sigInput       = document.getElementById('suPolicySig');

  function calcAge(dob) {
    if (!dob) return NaN;
    var parts = dob.split('-');
    if (parts.length !== 3) return NaN;
    var birth = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    var today = new Date();
    var age   = today.getFullYear() - birth.getFullYear();
    var m     = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
    return age;
  }

  function updateGuardianUI(age) {
    if (!guardSection) return;
    var isMinor = isNaN(age) || age < 18; // default to showing (safe for youth programme)
    if (isMinor) {
      guardSection.style.display = '';
      if (guardReqNote) guardReqNote.style.display = '';
      if (guardOptNote) guardOptNote.style.display = 'none';
      if (guardNameReq)  guardNameReq.style.display  = '';
      if (guardPhoneReq) guardPhoneReq.style.display = '';
    } else {
      guardSection.style.display = '';   // keep visible but mark optional
      if (guardReqNote) guardReqNote.style.display = 'none';
      if (guardOptNote) guardOptNote.style.display = '';
      if (guardNameReq)  guardNameReq.style.display  = 'none';
      if (guardPhoneReq) guardPhoneReq.style.display = 'none';
    }
    // Update signature field to match the signer role
    if (sigHint) sigHint.textContent = isMinor
      ? "Type the guardian's full name as the electronic signature"
      : 'Type your full legal name as your electronic signature';
    if (sigInput) sigInput.placeholder = isMinor ? "Guardian's full name" : 'Type your full name to sign';
  }

  // ── Error display ──────────────────────────────────────────────
  function showError(msg) {
    if (!errBox) return;
    errBox.textContent = msg;
    errBox.classList.add('show');
    errBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function clearError() {
    if (!errBox) return;
    errBox.textContent = '';
    errBox.classList.remove('show');
    clearFieldErrors();
  }

  function clearFieldErrors() {
    form.querySelectorAll('.su-field-error.show').forEach(function (el) { el.classList.remove('show'); });
    form.querySelectorAll('.su-field.has-error').forEach(function (el) { el.classList.remove('has-error'); });
  }

  function showFieldError(fieldName) {
    var errEl = document.getElementById('su-err-' + fieldName);
    if (!errEl) return;
    errEl.classList.add('show');
    var parent = errEl.parentElement;
    while (parent && !parent.classList.contains('su-field')) parent = parent.parentElement;
    if (parent) parent.classList.add('has-error');
  }

  // ── DOB change handler ─────────────────────────────────────────
  var dobEl = document.getElementById('suDob');
  if (dobEl) {
    dobEl.addEventListener('change', function () {
      clearError();
      var age = calcAge(dobEl.value);
      updateGuardianUI(age);
      if (isNaN(age)) return;
      if (minAge > 0 && age < minAge) {
        showError('The minimum age for this programme is ' + minAge + ' years. The date of birth entered gives an age of ' + age + '.');
      } else if (maxAge > 0 && age > maxAge) {
        showError('The maximum age for this programme is ' + maxAge + ' years. The date of birth entered gives an age of ' + age + '.');
      }
    });
  }

  // ── Payload builder ────────────────────────────────────────────
  function collectPayload() {
    var fd = new FormData(form);
    var obj = {};
    fd.forEach(function (v, k) {
      if (k === '_honeypot') { obj[k] = v; return; }
      obj[k] = typeof v === 'string' ? v.trim() : v;
    });
    obj.policyAgreed = !!(form.querySelector('[name="policyAgreed"]') &&
                          form.querySelector('[name="policyAgreed"]').checked);
    // Pull headshot URL from hidden field (set by headshot widget)
    obj.headshotUrl = hsUrlIn ? hsUrlIn.value : '';
    return obj;
  }

  // ── Submit ─────────────────────────────────────────────────────
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    clearError();

    var dobVal = (form.querySelector('[name="dob"]') || {}).value || '';
    var age    = calcAge(dobVal);

    // Required fields (guardian conditionally required if minor)
    var isMinor = isNaN(age) || age < 18;
    var required = [
      ['firstName',       'First name'],
      ['lastName',        'Last name'],
      ['email',           'Email address'],
      ['phone',           'Phone number'],
      ['dob',             'Date of birth'],
      ['policySignedName','Signature'],
    ];
    if (isMinor) {
      required.push(['guardianName',  'Guardian name']);
      required.push(['guardianPhone', 'Guardian phone']);
    }

    var firstInvalid = null;
    for (var i = 0; i < required.length; i++) {
      var el = form.querySelector('[name="' + required[i][0] + '"]');
      if (el && !el.value.trim()) {
        showFieldError(required[i][0]);
        if (!firstInvalid) firstInvalid = el;
      }
    }

    var policyEl = form.querySelector('[name="policyAgreed"]');
    if (policyEl && !policyEl.checked) {
      showFieldError('policyAgreed');
      if (!firstInvalid) firstInvalid = policyEl;
    }

    if (firstInvalid) {
      firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
      firstInvalid.focus();
      return;
    }

    // Client-side age check
    if (!isNaN(age)) {
      if (minAge > 0 && age < minAge) {
        showError('Applicants must be at least ' + minAge + ' years old for this programme.');
        return;
      }
      if (maxAge > 0 && age > maxAge) {
        showError('This programme is for participants up to ' + maxAge + ' years old.');
        return;
      }
    }

    // Block submission while headshot is still uploading
    if (hsState.uploading) {
      showError('Please wait — your photo is still uploading.');
      return;
    }

    var origText = submitBtn.textContent;
    if (window.MAM_FORMS) MAM_FORMS.setBusy(submitBtn, 'Submitting…');
    else { submitBtn.disabled = true; submitBtn.textContent = 'Submitting…'; }
    function unbusy() {
      if (window.MAM_FORMS) MAM_FORMS.resetBusy(submitBtn);
      else { submitBtn.disabled = false; submitBtn.textContent = origText; }
    }

    var payload = collectPayload();

    var api = window.MAM_API;
    var p = api
      ? api.request('summer/apply', { method: 'POST', body: payload })
      : fetch('/api/summer/apply', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        }).then(function (r) { return r.json(); });

    p.then(function (res) {
      unbusy();
      if (res && res.ok && res.reference) {
        form.style.display = 'none';
        var ageNotice = document.querySelector('.su-age-notice');
        if (ageNotice) ageNotice.style.display = 'none';
        if (success) success.style.display = 'block';
        if (successRef) successRef.textContent = res.reference;
        if (success) success.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } else {
        handleServerError(res);
      }
    }).catch(function (e) {
      unbusy();
      handleServerError((e && e.body) ? e.body : null);
    });
  });

  function handleServerError(res) {
    if (res && res.error === 'validation_error' && Array.isArray(res.errors)) {
      clearFieldErrors();
      var firstEl = null;
      for (var i = 0; i < res.errors.length; i++) {
        showFieldError(res.errors[i]);
        if (!firstEl) firstEl = form.querySelector('[name="' + res.errors[i] + '"]');
      }
      if (firstEl) firstEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
      showError('Please correct the highlighted fields.');
    } else if (res && res.error === 'invalid_email') {
      showFieldError('email');
      showError('Please enter a valid email address.');
    } else if (res && res.error === 'invalid_dob') {
      showFieldError('dob');
      showError('Please enter a valid date of birth.');
    } else if (res && (res.error === 'age_too_young' || res.error === 'age_too_old')) {
      showFieldError('dob');
      showError(codeToMsg(res.error, res));
    } else if (res && res.error) {
      showError(codeToMsg(res.error, res));
    } else {
      showError('A network error occurred. Please check your connection and try again.');
    }
  }

  function codeToMsg(code, res) {
    var m = {
      'validation_error'   : 'Please correct the highlighted fields.',
      'invalid_email'      : 'Please enter a valid email address.',
      'invalid_dob'        : 'Please enter a valid date of birth.',
      'age_too_young'      : 'Applicants must be at least ' + (res && res.min_age ? res.min_age : minAge) + ' years old.',
      'age_too_old'        : 'This programme is for participants up to ' + (res && res.max_age ? res.max_age : maxAge) + ' years old.',
      'applications_closed': (res && res.message) || 'Applications are not currently open.',
      'save_failed'        : 'We couldn\'t save your application right now. Please try again.',
      'csrf_invalid'       : 'Your session expired. Please refresh the page and try again.',
      'rate_limit'         : 'Too many submissions. Please wait a moment and try again.',
    };
    return m[code] || 'Something went wrong. Please try again or contact us directly.';
  }
})();
