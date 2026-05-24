/* ============================================================
   MAM Academy — Application & Payment Flow
   Multi-step smart onboarding with Paystack / Flutterwave / Stripe.
   ============================================================ */
(function () {
  'use strict';

  var $  = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var on = function (el, ev, fn) { if (el) el.addEventListener(ev, fn, false); };

  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  var booted = false;
  function boot() {
    if (booted) return;
    if (typeof window.MAM_CONFIG === 'undefined') {
      console.error('MAM_CONFIG missing — load assets/js/config.js first');
      return;
    }
    booted = true;
    initApply();
  }
  // Wait for main.js to load partials and finish booting
  if (document.querySelector('#navbar')) boot();
  else document.addEventListener('mam:ready', boot, { once: true });
  // Fallback in case mam:ready never fires (e.g., partials missing)
  ready(function () { setTimeout(boot, 2500); });

  function initApply() {
    var cfg = window.MAM_CONFIG;
    var stepsEls = $$('.step');
    var progressSteps = $$('.apply-progress-step');
    var current = 0;
    var totalSteps = stepsEls.length;
    var data = loadDraft() || {
      pathway: '', mode: '', discipline: '', firstName: '', lastName: '', email: '', phone: '',
      country: 'Nigeria', city: '', age: '', dob: '', experience: '', goals: '', referrer: '',
      auditer: true, parentName: '', parentPhone: '', emergencyContact: '',
      includeRegistration: true, includeCertification: false, payment: '', currency: 'USD'
    };

    // Restore from URL ?pathway=cert etc.
    try {
      var qs = new URLSearchParams(location.search);
      ['pathway', 'mode', 'discipline'].forEach(function (k) {
        if (qs.get(k)) data[k] = qs.get(k);
      });
    } catch (e) {}

    // ---------- Helpers ----------
    function showStep(i) {
      current = Math.max(0, Math.min(totalSteps - 1, i));
      stepsEls.forEach(function (s, idx) { s.classList.toggle('active', idx === current); });
      progressSteps.forEach(function (p, idx) {
        p.classList.toggle('active', idx === current);
        p.classList.toggle('done', idx < current);
      });
      window.scrollTo({ top: $('.apply-main').offsetTop - 100, behavior: 'smooth' });
      updateSummary();
    }
    function saveDraft() {
      try { localStorage.setItem('mam.apply.draft', JSON.stringify(data)); } catch (e) {}
    }
    function loadDraft() {
      try { var raw = localStorage.getItem('mam.apply.draft'); return raw ? JSON.parse(raw) : null; }
      catch (e) { return null; }
    }
    function clearDraft() { try { localStorage.removeItem('mam.apply.draft'); } catch (e) {} }

    function bindField(id, key) {
      var el = document.getElementById(id);
      if (!el) return;
      // Hydrate
      if (data[key]) {
        if (el.type === 'checkbox') el.checked = !!data[key];
        else el.value = data[key];
      }
      on(el, 'input', function () {
        data[key] = el.type === 'checkbox' ? el.checked : el.value;
        if (el.classList.contains('error')) el.classList.remove('error');
        var errEl = document.getElementById('err-' + id); if (errEl) errEl.classList.remove('shown');
        saveDraft();
        updateSummary();
      });
      on(el, 'change', function () {
        data[key] = el.type === 'checkbox' ? el.checked : el.value;
        saveDraft();
        updateSummary();
      });
    }

    function bindRadioGroup(name, key) {
      $$('input[name="' + name + '"]').forEach(function (input) {
        if (data[key] === input.value) input.checked = true;
        on(input, 'change', function () {
          if (input.checked) { data[key] = input.value; saveDraft(); updateSummary(); }
        });
      });
    }

    function setError(id, msg) {
      var input = document.getElementById(id);
      var err = document.getElementById('err-' + id);
      if (input) input.classList.add('error');
      if (err) { err.classList.add('shown'); if (msg && err.querySelector('span')) err.querySelector('span').textContent = msg; }
    }

    // ---------- Step validation ----------
    function validateStep(idx) {
      var ok = true;
      var first = null;
      function check(id, condition, msg) {
        var el = document.getElementById(id);
        if (!el) return;
        if (!condition) { setError(id, msg); ok = false; if (!first) first = el; }
        else { el.classList.remove('error'); var e = document.getElementById('err-' + id); if (e) e.classList.remove('shown'); }
      }

      if (idx === 0) {
        // Pathway + mode
        if (!data.pathway) { var p = document.getElementById('err-pathway'); if (p) p.classList.add('shown'); ok = false; }
        if (!data.mode)    { var m = document.getElementById('err-mode');    if (m) m.classList.add('shown'); ok = false; }
        if (!data.discipline) { var d = document.getElementById('err-discipline'); if (d) d.classList.add('shown'); ok = false; }
      }
      if (idx === 1) {
        var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        var phoneRe = /^[+0-9\s\-()]{7,}$/;
        check('firstName', data.firstName && data.firstName.trim().length > 1, 'Enter first name');
        check('lastName',  data.lastName  && data.lastName.trim().length > 1, 'Enter last name');
        check('email',     emailRe.test((data.email || '').trim()), 'Enter a valid email');
        check('phone',     phoneRe.test((data.phone || '').trim()), 'Enter a valid phone');
        check('country',   data.country && data.country.trim().length > 1, 'Enter country');
        check('age',       !!data.age, 'Select age range');
      }
      if (idx === 2) {
        // Soft validations — optional info
      }
      if (idx === 3) {
        var consentEl = document.getElementById('consent');
        if (!consentEl || !consentEl.checked) {
          var c = document.getElementById('err-consent'); if (c) c.classList.add('shown'); ok = false;
        }
      }
      if (idx === 4) {
        if (!data.payment) {
          var pm = document.getElementById('err-payment'); if (pm) pm.classList.add('shown'); ok = false;
        }
      }

      if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return ok;
    }

    // ---------- Pricing & summary ----------
    function computeTotalUsd() {
      var price = cfg.pricing[data.pathway];
      var sum = price ? price.usd : 0;
      if (data.includeRegistration) sum += cfg.registrationFeeUsd || 0;
      if (data.includeCertification) sum += cfg.certificationFeeUsd || 0;
      return sum;
    }
    function fmtMoney(usd, currency) {
      var cur = currency || data.currency || 'USD';
      var rate = (cfg.currency.rates[cur] || 1);
      var symbol = { USD: '$', NGN: '₦', GBP: '£', EUR: '€' }[cur] || '$';
      var v = Math.round(usd * rate);
      return symbol + v.toLocaleString('en-US');
    }
    function updateSummary() {
      var summary = $('#applySummary');
      if (!summary) return;
      var price = cfg.pricing[data.pathway];
      var rows = '';
      if (price) {
        rows += row('Pathway',  price.name);
        rows += row('Tuition',  fmtMoney(price.usd));
      }
      if (data.mode)       rows += row('Mode', data.mode === 'online' ? 'Online' : 'Onsite');
      if (data.discipline) rows += row('Discipline', titleCase(data.discipline));
      if (data.includeRegistration && cfg.registrationFeeUsd) rows += row('Registration', fmtMoney(cfg.registrationFeeUsd));
      if (data.includeCertification && cfg.certificationFeeUsd) rows += row('Certification', fmtMoney(cfg.certificationFeeUsd));
      var total = computeTotalUsd();
      rows += '<div class="summary-total"><span>Total</span><span>' + fmtMoney(total) + '</span></div>';
      summary.innerHTML = rows;

      // Update review screen
      var review = $('#reviewBody');
      if (review) review.innerHTML = renderReview();

      // Pay button text
      var pb = $('#btnPay'); if (pb) {
        var label = pb.querySelector('span:first-child');
        if (label) label.textContent = 'Pay ' + fmtMoney(total);
      }
    }
    function row(k, v) { return '<div class="summary-row"><span>' + k + '</span><span>' + v + '</span></div>'; }
    function titleCase(s) { return String(s || '').replace(/[-_]/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }); }

    function renderReview() {
      var price = cfg.pricing[data.pathway];
      function item(label, value) {
        return '<div class="review-item"><span class="review-label">' + label + '</span><span class="review-value">' + (value || '—') + '</span></div>';
      }
      var html = '';
      html += '<div class="review-section"><div class="review-section-head"><h4>Programme</h4><button type="button" data-jump="0">Edit</button></div><div class="review-grid">';
      html += item('Pathway',    price ? price.name : '—');
      html += item('Mode',       data.mode === 'online' ? 'Online' : (data.mode === 'onsite' ? 'Onsite Lagos' : '—'));
      html += item('Discipline', titleCase(data.discipline));
      html += item('MAM Auditer', data.auditer ? 'Yes — book session' : 'No');
      html += '</div></div>';

      html += '<div class="review-section"><div class="review-section-head"><h4>Student Profile</h4><button type="button" data-jump="1">Edit</button></div><div class="review-grid">';
      html += item('Name',    (data.firstName + ' ' + data.lastName).trim());
      html += item('Email',   data.email);
      html += item('Phone',   data.phone);
      html += item('Country', data.country);
      html += item('City',    data.city);
      html += item('Age',     data.age);
      html += '</div></div>';

      if (data.experience || data.goals || data.referrer) {
        html += '<div class="review-section"><div class="review-section-head"><h4>Background</h4><button type="button" data-jump="2">Edit</button></div><div class="review-grid">';
        html += item('Experience', titleCase(data.experience));
        html += item('Heard from us via', titleCase(data.referrer));
        html += '</div>';
        if (data.goals) html += '<div class="review-item" style="margin-top:14px"><span class="review-label">Goals</span><span class="review-value">' + escapeHtml(data.goals) + '</span></div>';
        html += '</div>';
      }
      return html;
    }
    function escapeHtml(s) { return String(s || '').replace(/[&<>"']/g, function (c) { return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]; }); }

    // ---------- Navigation buttons ----------
    on($('#btnNext'),   'click', function () { if (validateStep(current)) showStep(current + 1); });
    on($('#btnNext2'),  'click', function () { if (validateStep(current)) showStep(current + 1); });
    on($('#btnNext3'),  'click', function () { if (validateStep(current)) showStep(current + 1); });
    on($('#btnNext4'),  'click', function () { if (validateStep(current)) showStep(current + 1); });
    $$('[data-back]').forEach(function (b) { on(b, 'click', function () { showStep(current - 1); }); });
    $$('[data-jump]').forEach(function (b) { on(b, 'click', function () { showStep(parseInt(b.getAttribute('data-jump'), 10)); }); });

    // Progress nav (jump to completed step)
    progressSteps.forEach(function (p, idx) {
      on(p, 'click', function () { if (idx < current) showStep(idx); });
    });

    // ---------- Bind fields ----------
    bindRadioGroup('pathway', 'pathway');
    bindRadioGroup('mode', 'mode');
    bindRadioGroup('discipline', 'discipline');
    bindField('firstName', 'firstName');
    bindField('lastName', 'lastName');
    bindField('email', 'email');
    bindField('phone', 'phone');
    bindField('country', 'country');
    bindField('city', 'city');
    bindField('age', 'age');
    bindField('dob', 'dob');
    bindField('experience', 'experience');
    bindField('goals', 'goals');
    bindField('referrer', 'referrer');
    bindField('parentName', 'parentName');
    bindField('parentPhone', 'parentPhone');
    bindField('emergencyContact', 'emergencyContact');
    bindField('auditerOptIn', 'auditer');
    bindField('includeRegistration', 'includeRegistration');
    bindField('includeCertification', 'includeCertification');

    // Payment radio
    bindRadioGroup('payment', 'payment');
    $$('input[name="payment"]').forEach(function (r) {
      on(r, 'change', function () {
        var bank = $('#bankDetails');
        if (bank) bank.classList.toggle('shown', r.checked && r.value === 'bank');
      });
    });
    // Restore radio visual state
    if (data.payment) {
      var prev = document.querySelector('input[name="payment"][value="' + data.payment + '"]');
      if (prev) prev.checked = true;
      var bank = $('#bankDetails'); if (bank && data.payment === 'bank') bank.classList.add('shown');
    }

    // Auto-hide unconfigured providers
    function hideProviderIfUnconfigured(value, isConfigured) {
      if (isConfigured) return;
      var radio = document.querySelector('input[name="payment"][value="' + value + '"]');
      if (radio) {
        var label = radio.closest('.payment-method');
        if (label) label.style.display = 'none';
      }
    }
    hideProviderIfUnconfigured('paystack', !!cfg.payments.paystack.publicKey);
    hideProviderIfUnconfigured('flutterwave', !!cfg.payments.flutterwave.publicKey);
    hideProviderIfUnconfigured('stripe', !!cfg.payments.stripe.publicKey);
    hideProviderIfUnconfigured('bank', cfg.payments.bankTransfer.enabled);

    // Bank details auto-fill
    if (cfg.payments.bankTransfer.enabled) {
      var setText = function (id, v) { var e = $('#' + id); if (e) e.textContent = v; };
      setText('bankName', cfg.payments.bankTransfer.bankName);
      setText('bankAccountName', cfg.payments.bankTransfer.accountName);
      setText('bankAccountNumber', cfg.payments.bankTransfer.accountNumber);
    }

    // Copy bank fields
    $$('.bank-copy').forEach(function (btn) {
      on(btn, 'click', function () {
        var targetId = btn.getAttribute('data-copy');
        var el = document.getElementById(targetId);
        if (!el || !navigator.clipboard) return;
        navigator.clipboard.writeText(el.textContent.trim()).then(function () {
          var orig = btn.textContent;
          btn.textContent = 'Copied!';
          setTimeout(function () { btn.textContent = orig; }, 1500);
        });
      });
    });

    // Currency change inside summary
    on($('#applyCurrency'), 'change', function () {
      data.currency = $('#applyCurrency').value;
      saveDraft(); updateSummary();
    });
    if (data.currency) {
      var cs = $('#applyCurrency'); if (cs) cs.value = data.currency;
    }

    // ---------- Payment handler ----------
    on($('#btnPay'), 'click', function (e) {
      e.preventDefault();
      if (!validateStep(current)) return;

      var totalUsd = computeTotalUsd();
      if (totalUsd <= 0) { alert('Please select a pathway first.'); return; }

      var ref = 'MAM-' + Date.now() + '-' + Math.random().toString(36).slice(2, 6).toUpperCase();
      data.reference = ref;
      saveDraft();

      var btn = $('#btnPay');
      var origLabel = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span><span>Opening secure checkout…</span>';

      function restoreBtn() { btn.disabled = false; btn.innerHTML = origLabel; updateSummary(); }

      if (data.payment === 'paystack') {
        payWithPaystack(totalUsd, ref, restoreBtn);
      } else if (data.payment === 'flutterwave') {
        payWithFlutterwave(totalUsd, ref, restoreBtn);
      } else if (data.payment === 'stripe') {
        payWithStripe(totalUsd, ref, restoreBtn);
      } else if (data.payment === 'bank') {
        // For bank transfer, record application then show confirmation
        submitApplication(ref, 'pending_bank_transfer').then(function () {
          showSuccess(ref, 'Bank Transfer — Awaiting Confirmation');
        }).catch(function () {
          showSuccess(ref, 'Bank Transfer — please email proof of payment to ' + cfg.site.contactEmail);
        });
      } else {
        restoreBtn();
        alert('Please choose a payment method.');
      }
    });

    // ---------- Paystack ----------
    function payWithPaystack(usd, ref, onClose) {
      var key = cfg.payments.paystack.publicKey;
      var ngn = Math.round(usd * cfg.currency.rates.NGN);
      function open() {
        var handler = window.PaystackPop.setup({
          key: key,
          email: data.email,
          amount: ngn * 100, // kobo
          currency: 'NGN',
          ref: ref,
          metadata: {
            custom_fields: [
              { display_name: 'Full Name', variable_name: 'full_name', value: data.firstName + ' ' + data.lastName },
              { display_name: 'Pathway',  variable_name: 'pathway',  value: data.pathway },
              { display_name: 'Mode',     variable_name: 'mode',     value: data.mode },
              { display_name: 'Phone',    variable_name: 'phone',    value: data.phone }
            ]
          },
          callback: function (response) {
            submitApplication(response.reference || ref, 'paid_paystack', response).then(function () {
              showSuccess(response.reference || ref, 'Paid via Paystack');
            });
          },
          onClose: onClose
        });
        handler.openIframe();
      }
      loadScript('https://js.paystack.co/v1/inline.js').then(open).catch(function () {
        onClose(); alert('Could not load Paystack. Please check your connection.');
      });
    }

    // ---------- Flutterwave ----------
    function payWithFlutterwave(usd, ref, onClose) {
      var key = cfg.payments.flutterwave.publicKey;
      var ngn = Math.round(usd * cfg.currency.rates.NGN);
      function open() {
        window.FlutterwaveCheckout({
          public_key: key,
          tx_ref: ref,
          amount: ngn,
          currency: 'NGN',
          payment_options: 'card, banktransfer, ussd, mobilemoneyghana, mobilemoneyfranco',
          customer: {
            email: data.email,
            phone_number: data.phone,
            name: data.firstName + ' ' + data.lastName
          },
          customizations: {
            title: 'MAM Academy',
            description: (cfg.pricing[data.pathway] && cfg.pricing[data.pathway].name) || 'MAM Enrollment',
            logo: cfg.site.url + '/mam-academy-logo.svg'
          },
          callback: function (response) {
            submitApplication(response.tx_ref || ref, 'paid_flutterwave', response).then(function () {
              showSuccess(response.tx_ref || ref, 'Paid via Flutterwave');
            });
          },
          onclose: onClose
        });
      }
      loadScript('https://checkout.flutterwave.com/v3.js').then(open).catch(function () {
        onClose(); alert('Could not load Flutterwave. Please check your connection.');
      });
    }

    // ---------- Stripe (Checkout redirect — needs server session, here we use Payment Links) ----------
    function payWithStripe(usd, ref, onClose) {
      // Stripe requires a server-side session for full Checkout; for static hosts we
      // fall back to a configurable Payment Link in MAM_CONFIG.payments.stripe.publicKey
      // If the key looks like a Payment Link (https://buy.stripe.com/…), redirect to it.
      var key = cfg.payments.stripe.publicKey;
      if (key && key.indexOf('https://') === 0) {
        submitApplication(ref, 'pending_stripe').then(function () {
          var url = key + '?prefilled_email=' + encodeURIComponent(data.email) + '&client_reference_id=' + encodeURIComponent(ref);
          window.location.href = url;
        });
      } else {
        onClose();
        alert('Stripe is not configured for this site. Please choose another payment method.');
      }
    }

    function loadScript(src) {
      return new Promise(function (resolve, reject) {
        if (document.querySelector('script[src="' + src + '"]')) return resolve();
        var s = document.createElement('script'); s.src = src; s.async = true;
        s.onload = resolve; s.onerror = reject;
        document.head.appendChild(s);
      });
    }

    // ---------- Application submit ----------
    function submitApplication(ref, status, providerResponse) {
      var payload = Object.assign({}, data, {
        reference: ref,
        status: status,
        providerResponse: providerResponse || null,
        totalUsd: computeTotalUsd(),
        submittedAt: new Date().toISOString(),
        source: location.hostname
      });
      var ep = cfg.forms.applicationEndpoint;
      if (!ep) {
        // No endpoint — open mailto as honest fallback (no fake "submitted")
        var subject = 'MAM Academy Application — ' + payload.firstName + ' ' + payload.lastName;
        var lines = ['New Application from ' + location.hostname, '',
          'Reference: '   + ref,
          'Status: '      + status,
          'Pathway: '     + payload.pathway,
          'Mode: '        + payload.mode,
          'Discipline: '  + payload.discipline,
          'Name: '        + payload.firstName + ' ' + payload.lastName,
          'Email: '       + payload.email,
          'Phone: '       + payload.phone,
          'Country/City: '+ payload.country + ' / ' + (payload.city || '—'),
          'Age: '         + payload.age,
          'Experience: '  + (payload.experience || '—'),
          'Goals: '       + (payload.goals || '—'),
          'MAM Auditer: ' + (payload.auditer ? 'Yes' : 'No'),
          'Total (USD): ' + payload.totalUsd, '',
          'Notes: ' + JSON.stringify(providerResponse || {}, null, 2)
        ];
        var url = 'mailto:' + cfg.forms.fallbackEmail + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(lines.join('\n'));
        // We don't redirect during a payment success — store locally instead
        try { localStorage.setItem('mam.apply.lastSubmission', JSON.stringify(payload)); } catch (e) {}
        return Promise.resolve({ mailto: url, payload: payload });
      }
      return fetch(ep, {
        method: 'POST',
        body: JSON.stringify(payload),
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }
      }).then(function (r) {
        if (!r.ok) throw new Error('Submit failed ' + r.status);
        return r.json().catch(function () { return {}; });
      });
    }

    // ---------- Success ----------
    function showSuccess(ref, paymentNote) {
      clearDraft();
      var main = $('.apply-main');
      if (!main) return;
      main.innerHTML = '' +
        '<div class="apply-success">' +
        '  <div class="success-emoji" aria-hidden="true">🎵</div>' +
        '  <h2 class="success-h">You\'re In!</h2>' +
        '  <p class="success-p">Thank you for enrolling in MAM Academy. Our team will reach you within <strong>24 hours</strong> to confirm your schedule, book your <strong>MAM Auditer</strong> entry assessment and prepare your student file.</p>' +
        '  <div class="success-ref">Reference: ' + ref + '</div>' +
        '  <p class="success-p" style="font-size:0.88rem;color:var(--text-muted)">' + paymentNote + '</p>' +
        '  <div class="success-actions">' +
        '    <a href="index.html" class="btn btn-primary"><i class="fa-solid fa-house"></i> Back to Home</a>' +
        '    <a href="mailto:' + cfg.site.contactEmail + '?subject=' + encodeURIComponent('Re: ' + ref) + '" class="btn btn-secondary"><i class="fa-solid fa-envelope"></i> Email Us</a>' +
        '  </div>' +
        '</div>';
      window.scrollTo({ top: main.offsetTop - 100, behavior: 'smooth' });
    }

    // ---------- Pre-fill from URL ----------
    if (data.pathway) {
      var pr = document.querySelector('input[name="pathway"][value="' + data.pathway + '"]'); if (pr) pr.checked = true;
    }
    if (data.mode) {
      var pm = document.querySelector('input[name="mode"][value="' + data.mode + '"]'); if (pm) pm.checked = true;
    }
    if (data.discipline) {
      var pd = document.querySelector('input[name="discipline"][value="' + data.discipline + '"]'); if (pd) pd.checked = true;
    }

    // Boot
    updateSummary();
    showStep(0);
  }
})();
</content>
</invoke>