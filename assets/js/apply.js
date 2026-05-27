/* ============================================================
   MAM Academy — Application & Payment Flow (full-stack)
   Multi-step onboarding wired to the PHP API:
     1. POST /api/application       -> server-issued reference
     2. POST /api/payment/initialize -> provider session payload
     3. Open Paystack/Flutterwave/Stripe SDK
     4. POST /api/payment/verify    -> server confirmed paid
   Falls back to a clear error UI if the API is unreachable.
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
    if (typeof window.MAM_CONFIG === 'undefined' || typeof window.MAM_API === 'undefined') {
      console.error('MAM_CONFIG/MAM_API missing — load config.js first');
      return;
    }
    booted = true;
    initApply();
  }
  // Wait for main.js to load partials, then init
  if (document.querySelector('#navbar')) boot();
  else document.addEventListener('mam:ready', boot, { once: true });
  ready(function () { setTimeout(boot, 2500); });

  function initApply() {
    var cfg = window.MAM_CONFIG;
    var api = window.MAM_API;
    var stepsEls = $$('.step');
    var progressSteps = $$('.apply-progress-step');
    var current = 0;
    var totalSteps = stepsEls.length;

    var data = loadDraft() || {
      pathway: '', mode: '', discipline: '', firstName: '', lastName: '', email: '', phone: '',
      country: 'Nigeria', city: '', age: '', dob: '', experience: '', goals: '', referrer: '',
      auditer: true, parentName: '', parentPhone: '', emergencyContact: '',
      includeRegistration: true, includeCertification: false, payment: '', currency: 'USD',
      programTrack: '', policyAgree: false, policySignName: '',
      reference: null, applicationId: null, serverTotalUsd: null
    };

    // KTAMS (Kids @ MAM) = under 16; MAM = 16 and above. Derived from age.
    var KTAMS_AGES = ['under-6', '6-9', '10-12', '13-15'];
    function deriveTrack(age) { return age ? (KTAMS_AGES.indexOf(age) >= 0 ? 'KTAMS' : 'MAM') : ''; }

    // Pre-fill from URL ?pathway=cert&mode=online&discipline=piano
    try {
      var qs = new URLSearchParams(location.search);
      ['pathway', 'mode', 'discipline'].forEach(function (k) {
        if (qs.get(k)) data[k] = qs.get(k);
      });
    } catch (e) {}

    function showStep(i) {
      current = Math.max(0, Math.min(totalSteps - 1, i));
      stepsEls.forEach(function (s, idx) { s.classList.toggle('active', idx === current); });
      progressSteps.forEach(function (p, idx) {
        p.classList.toggle('active', idx === current);
        p.classList.toggle('done', idx < current);
      });
      var main = $('.apply-main');
      if (main) window.scrollTo({ top: main.offsetTop - 100, behavior: 'smooth' });
      updateSummary();
      updateTrackUI();
      if (current === 3) renderPolicy();
    }
    function saveDraft() { try { localStorage.setItem('mam.apply.draft', JSON.stringify(data)); } catch (e) {} }
    function loadDraft() { try { var r = localStorage.getItem('mam.apply.draft'); return r ? JSON.parse(r) : null; } catch (e) { return null; } }
    function clearDraft() { try { localStorage.removeItem('mam.apply.draft'); } catch (e) {} }

    function bindField(id, key) {
      var el = document.getElementById(id); if (!el) return;
      if (data[key] != null) { if (el.type === 'checkbox') el.checked = !!data[key]; else el.value = data[key]; }
      var set = function () {
        data[key] = el.type === 'checkbox' ? el.checked : el.value;
        if (el.classList.contains('error')) el.classList.remove('error');
        var errEl = document.getElementById('err-' + id); if (errEl) errEl.classList.remove('shown');
        saveDraft(); updateSummary();
      };
      on(el, 'input', set); on(el, 'change', set);
    }
    function bindRadioGroup(name, key) {
      $$('input[name="' + name + '"]').forEach(function (input) {
        if (data[key] === input.value) input.checked = true;
        on(input, 'change', function () { if (input.checked) { data[key] = input.value; saveDraft(); updateSummary(); } });
      });
    }
    function setError(id) {
      var input = document.getElementById(id); if (input) input.classList.add('error');
      var err = document.getElementById('err-' + id); if (err) err.classList.add('shown');
    }
    function clearError(id) {
      var input = document.getElementById(id); if (input) input.classList.remove('error');
      var err = document.getElementById('err-' + id); if (err) err.classList.remove('shown');
    }

    function validateStep(idx) {
      var ok = true; var first = null;
      function check(id, condition) {
        var el = document.getElementById(id); if (!el) return;
        if (!condition) { setError(id); ok = false; if (!first) first = el; } else clearError(id);
      }
      if (idx === 0) {
        if (!data.pathway)    { var p = $('#err-pathway');    if (p) p.classList.add('shown');    ok = false; }
        if (!data.mode)       { var m = $('#err-mode');       if (m) m.classList.add('shown');       ok = false; }
        if (!data.discipline) { var d = $('#err-discipline'); if (d) d.classList.add('shown'); ok = false; }
      }
      if (idx === 1) {
        var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/, phoneRe = /^[+0-9\s\-()]{7,}$/;
        check('firstName', (data.firstName || '').trim().length > 1);
        check('lastName',  (data.lastName  || '').trim().length > 1);
        check('email',     emailRe.test((data.email || '').trim()));
        check('phone',     phoneRe.test((data.phone || '').trim()));
        check('country',   (data.country || '').trim().length > 1);
        check('age',       !!data.age);
        // Under-16 (KTAMS) applicants must provide a parent/guardian
        if (deriveTrack(data.age) === 'KTAMS') check('parentName', (data.parentName || '').trim().length > 1);
      }
      if (idx === 3) {
        var pa = $('#policyAgree');
        if (!pa || !pa.checked) { var pe = $('#err-policyAgree'); if (pe) pe.classList.add('shown'); ok = false; if (!first) first = pa; }
        check('policySignName', (data.policySignName || '').trim().length > 1);
        var consentEl = $('#consent');
        if (!consentEl || !consentEl.checked) { var c = $('#err-consent'); if (c) c.classList.add('shown'); ok = false; }
      }
      if (idx === 4) {
        if (!data.payment) { var pm = $('#err-payment'); if (pm) pm.classList.add('shown'); ok = false; }
      }
      if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return ok;
    }

    // ---------- Pricing & summary (display only; server enforces) ----------
    function computeTotalUsd() {
      if (data.serverTotalUsd != null) return data.serverTotalUsd;
      var p = cfg.pricing[data.pathway]; var sum = p ? p.usd : 0;
      if (data.includeRegistration) sum += cfg.registrationFeeUsd || 0;
      if (data.includeCertification) sum += cfg.certificationFeeUsd || 0;
      return sum;
    }
    function fmtMoney(usd, cur) {
      cur = cur || data.currency || 'USD';
      var rate = cfg.currency.rates[cur] || 1;
      var sym = { USD: '$', NGN: '₦', GBP: '£', EUR: '€' }[cur] || '$';
      return sym + Math.round(usd * rate).toLocaleString('en-US');
    }
    function updateSummary() {
      var summary = $('#applySummary'); if (!summary) return;
      var price = cfg.pricing[data.pathway]; var rows = '';
      if (price) rows += row('Pathway', price.name) + row('Tuition', fmtMoney(price.usd));
      if (data.mode)       rows += row('Mode', data.mode === 'online' ? 'Online' : 'Onsite');
      if (data.discipline) rows += row('Discipline', titleCase(data.discipline));
      if (data.includeRegistration && cfg.registrationFeeUsd) rows += row('Registration', fmtMoney(cfg.registrationFeeUsd));
      if (data.includeCertification && cfg.certificationFeeUsd) rows += row('Certification', fmtMoney(cfg.certificationFeeUsd));
      var total = computeTotalUsd();
      rows += '<div class="summary-total"><span>Total</span><span>' + fmtMoney(total) + '</span></div>';
      if (data.reference) rows += '<div style="margin-top:10px;font-size:0.78rem;color:var(--text-muted);text-align:center">Ref: <span class="mono" style="color:var(--gold-bright)">' + data.reference + '</span></div>';
      summary.innerHTML = rows;
      var review = $('#reviewBody'); if (review) review.innerHTML = renderReview();
      var pb = $('#btnPay');
      if (pb) { var l = pb.querySelector('span:first-child'); if (l) l.textContent = 'Pay ' + fmtMoney(total); }
    }
    function row(k, v) { return '<div class="summary-row"><span>' + k + '</span><span>' + v + '</span></div>'; }
    function titleCase(s) { return String(s || '').replace(/[-_]/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }); }

    // ---------- Track (KTAMS vs MAM) ----------
    function updateTrackUI() {
      data.programTrack = deriveTrack(data.age);
      var tracks = cfg.tracks || {};
      var badge = $('#trackBadge');
      if (badge) {
        if (data.programTrack && tracks[data.programTrack]) {
          var t = tracks[data.programTrack];
          badge.hidden = false;
          badge.classList.toggle('is-ktams', data.programTrack === 'KTAMS');
          var nm = $('#trackBadgeName'); if (nm) nm.textContent = t.name;
          var nt = $('#trackBadgeNote'); if (nt) nt.textContent = t.note;
        } else { badge.hidden = true; }
      }
      // Guardian is required for KTAMS (under-16) applicants
      var isKid = data.programTrack === 'KTAMS';
      var req = $('#guardianReq'), opt = $('#guardianOpt');
      if (req) req.hidden = !isKid;
      if (opt) opt.textContent = isKid ? '(required for under-16)' : '(for under 16)';
      if (!isKid) clearError('parentName');
    }

    // ---------- Policy summary (online vs onsite) ----------
    function renderPolicy() {
      var pol = (cfg.policies || {})[data.mode];
      var titleEl = $('#policyTitle'), introEl = $('#policyIntro'),
          pointsEl = $('#policyPoints'), nameEl = $('#policyConsentName'), dateEl = $('#policySignDate');
      if (dateEl) dateEl.textContent = new Date().toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
      if (!pol) {
        if (titleEl) titleEl.textContent = 'Class Policy';
        if (introEl) introEl.textContent = 'Choose a learning mode in Step 1 to see the policy that applies to you.';
        if (pointsEl) pointsEl.innerHTML = '';
        if (nameEl) nameEl.textContent = 'MAM Academy class policy';
        return;
      }
      if (titleEl) titleEl.textContent = pol.title;
      if (introEl) introEl.textContent = pol.intro;
      if (pointsEl) pointsEl.innerHTML = pol.points.map(function (p) {
        return '<li><i class="fa-solid fa-check"></i><span>' + escapeHtml(p) + '</span></li>';
      }).join('');
      if (nameEl) nameEl.textContent = pol.title;
    }

    function renderReview() {
      var price = cfg.pricing[data.pathway];
      function item(l, v) { return '<div class="review-item"><span class="review-label">' + l + '</span><span class="review-value">' + (v || '—') + '</span></div>'; }
      var html = '';
      html += '<div class="review-section"><div class="review-section-head"><h4>Programme</h4><button type="button" data-jump="0">Edit</button></div><div class="review-grid">';
      var trk = deriveTrack(data.age);
      html += item('Track', trk && cfg.tracks[trk] ? cfg.tracks[trk].name : '—');
      html += item('Pathway', price ? price.name : '—');
      html += item('Mode',    data.mode === 'online' ? 'Online' : (data.mode === 'onsite' ? 'Onsite Lagos' : '—'));
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
    function escapeHtml(s) { return String(s || '').replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

    // ---------- Step nav ----------
    function attemptNext() {
      if (!validateStep(current)) return;
      // When leaving step 3 (Review) → submit to backend FIRST, then move to payment step
      if (current === 3 && !data.reference) {
        submitApplicationToServer();
        return;
      }
      showStep(current + 1);
    }
    on($('#btnNext'),  'click', attemptNext);
    on($('#btnNext2'), 'click', attemptNext);
    on($('#btnNext3'), 'click', attemptNext);
    on($('#btnNext4'), 'click', attemptNext);
    $$('[data-back]').forEach(function (b) { on(b, 'click', function () { showStep(current - 1); }); });
    $$('[data-jump]').forEach(function (b) {
      on(b, 'click', function () {
        // Editing an earlier step invalidates the server reference; the
        // user will re-submit when they return to the review step.
        data.reference = null; data.applicationId = null; data.serverTotalUsd = null;
        saveDraft(); updateSummary();
        showStep(parseInt(b.getAttribute('data-jump'), 10));
      });
    });
    progressSteps.forEach(function (p, idx) { on(p, 'click', function () { if (idx < current) showStep(idx); }); });

    // ---------- Field bindings ----------
    bindRadioGroup('pathway', 'pathway');
    bindRadioGroup('mode', 'mode');
    bindRadioGroup('discipline', 'discipline');
    ['firstName','lastName','email','phone','country','city','age','dob','experience','goals','referrer','parentName','parentPhone','emergencyContact'].forEach(function (id) { bindField(id, id); });
    bindField('auditerOptIn', 'auditer');
    bindField('includeRegistration', 'includeRegistration');
    bindField('includeCertification', 'includeCertification');
    bindField('policySignName', 'policySignName');
    bindField('policyAgree', 'policyAgree');
    // Live feedback: age drives the KTAMS/MAM track + guardian requirement; mode drives the policy shown
    $$('input[name="mode"]').forEach(function (i) { on(i, 'change', renderPolicy); });
    var ageEl = document.getElementById('age'); if (ageEl) { on(ageEl, 'change', updateTrackUI); }

    bindRadioGroup('payment', 'payment');
    $$('input[name="payment"]').forEach(function (r) {
      on(r, 'change', function () {
        var bank = $('#bankDetails'); if (bank) bank.classList.toggle('shown', r.checked && r.value === 'bank');
      });
    });
    if (data.payment) {
      var prev = document.querySelector('input[name="payment"][value="' + data.payment + '"]');
      if (prev) prev.checked = true;
      var bank = $('#bankDetails'); if (bank && data.payment === 'bank') bank.classList.add('shown');
    }

    // Hide unconfigured providers
    Object.keys(cfg.paymentMethods).forEach(function (key) {
      if (!cfg.paymentMethods[key].enabled) {
        var r = document.querySelector('input[name="payment"][value="' + key + '"]');
        if (r && r.closest('.payment-method')) r.closest('.payment-method').style.display = 'none';
      }
    });

    // Copy bank fields
    $$('.bank-copy').forEach(function (btn) {
      on(btn, 'click', function () {
        var id = btn.getAttribute('data-copy'); var el = document.getElementById(id);
        if (!el || !navigator.clipboard) return;
        navigator.clipboard.writeText(el.textContent.trim()).then(function () {
          var orig = btn.textContent; btn.textContent = 'Copied!'; setTimeout(function () { btn.textContent = orig; }, 1500);
        });
      });
    });

    on($('#applyCurrency'), 'change', function () { data.currency = $('#applyCurrency').value; saveDraft(); updateSummary(); });
    if (data.currency) { var cs = $('#applyCurrency'); if (cs) cs.value = data.currency; }

    // ---------- Submit application ----------
    function submitApplicationToServer() {
      setBusy('btnNext4', true, 'Submitting…');
      api.submitApplication(buildPayload())
        .then(function (resp) {
          data.reference = resp.reference;
          data.applicationId = resp.application_id;
          data.serverTotalUsd = resp.total_usd;
          saveDraft(); updateSummary();
          setBusy('btnNext4', false);
          showStep(current + 1);
        })
        .catch(function (err) {
          setBusy('btnNext4', false);
          if (err && err.body && err.body.fields) {
            showServerErrors(err.body.fields);
            showStep(1); // jump back to profile section
          } else if (err && err.status === 429) {
            apiError('Too many attempts. Please try again in a few minutes.');
          } else {
            apiError(err && err.message ? err.message : 'Submission failed. Please try again.');
          }
        });
    }
    function buildPayload() {
      return {
        firstName: data.firstName, lastName: data.lastName,
        email: data.email, phone: data.phone,
        pathway: data.pathway, mode: data.mode, discipline: data.discipline,
        country: data.country, city: data.city, age: data.age, dob: data.dob,
        experience: data.experience, goals: data.goals, referrer: data.referrer,
        parentName: data.parentName, parentPhone: data.parentPhone, emergencyContact: data.emergencyContact,
        auditer: !!data.auditer,
        includeRegistration: !!data.includeRegistration,
        includeCertification: !!data.includeCertification,
        programTrack: deriveTrack(data.age),
        policyMode: data.mode,
        policyAgreed: !!data.policyAgree,
        policySignedName: data.policySignName
      };
    }
    function showServerErrors(fields) {
      var map = { firstName: 'firstName', lastName: 'lastName', email: 'email', phone: 'phone',
                  pathway: 'pathway', mode: 'mode', discipline: 'discipline', age: 'age' };
      fields.forEach(function (f) { if (map[f]) setError(map[f]); });
    }
    function setBusy(btnId, busy, label) {
      var btn = document.getElementById(btnId); if (!btn) return;
      if (busy) {
        btn.dataset.origLabel = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span><span>' + (label || 'Working…') + '</span>';
      } else {
        btn.disabled = false;
        if (btn.dataset.origLabel) { btn.innerHTML = btn.dataset.origLabel; delete btn.dataset.origLabel; }
      }
    }
    function apiError(msg) {
      var notice = $('#apiNotice') || (function () {
        var n = document.createElement('div');
        n.id = 'apiNotice';
        n.style.cssText = 'margin:14px 0;padding:12px 16px;background:rgba(244,63,94,0.1);border:1px solid rgba(244,63,94,0.4);border-radius:10px;color:#FB7185;font-size:0.86rem';
        var main = $('.apply-main'); if (main) main.prepend(n);
        return n;
      })();
      notice.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + msg + ' &middot; If this keeps happening, email <a href="mailto:' + cfg.site.contactEmail + '" style="color:var(--gold-bright)">' + cfg.site.contactEmail + '</a>';
      notice.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // ---------- Pay ----------
    on($('#btnPay'), 'click', function (e) {
      e.preventDefault();
      if (!validateStep(current)) return;
      if (!data.reference) { apiError('Application not submitted yet. Please go back and complete review.'); return; }
      setBusy('btnPay', true, 'Opening secure checkout…');

      api.initializePayment({ reference: data.reference, provider: data.payment })
        .then(function (init) {
          if (data.payment === 'paystack')      return openPaystack(init);
          if (data.payment === 'flutterwave')   return openFlutterwave(init);
          if (data.payment === 'stripe')        return openStripe(init);
          if (data.payment === 'bank')          return handleBank(init);
          throw new Error('unsupported_provider');
        })
        .catch(function (err) {
          setBusy('btnPay', false);
          apiError(err && err.message ? err.message : 'Could not start payment. Please try another method.');
        });
    });

    function openPaystack(init) {
      return loadScript('https://js.paystack.co/v1/inline.js').then(function () {
        setBusy('btnPay', false);
        var handler = window.PaystackPop.setup({
          key: init.public_key,
          email: data.email,
          amount: init.amount_ngn * 100,
          currency: 'NGN',
          ref: init.reference,
          metadata: { reference: init.reference, application_id: data.applicationId },
          callback: function (response) { afterProviderCharge('paystack', response.reference || init.reference); },
          onClose: function () { setBusy('btnPay', false); }
        });
        handler.openIframe();
      });
    }
    function openFlutterwave(init) {
      return loadScript('https://checkout.flutterwave.com/v3.js').then(function () {
        setBusy('btnPay', false);
        var payload = init.payload;
        payload.public_key = init.public_key;
        payload.payment_options = 'card, banktransfer, ussd, mobilemoneyghana, mobilemoneyfranco';
        payload.callback = function (response) {
          afterProviderCharge('flutterwave', response.tx_ref || init.reference, response.transaction_id);
        };
        payload.onclose = function () { setBusy('btnPay', false); };
        window.FlutterwaveCheckout(payload);
      });
    }
    function openStripe(init) {
      // For shared-host PHP, Stripe full Checkout requires a server session.
      // We support that via init.checkout_url when configured.
      if (init.checkout_url) { window.location.href = init.checkout_url; return; }
      setBusy('btnPay', false);
      apiError('Stripe Checkout is not configured for this site yet.');
    }
    function handleBank(init) {
      setBusy('btnPay', false);
      showSuccess(init.reference, 'Bank transfer initiated. ' + init.instructions);
    }

    function afterProviderCharge(provider, reference, transactionId) {
      setBusy('btnPay', true, 'Verifying…');
      api.verifyPayment({ provider: provider, reference: reference, transaction_id: transactionId || 0 })
        .then(function (res) {
          if (res.status === 'succeeded') showSuccess(reference, 'Paid via ' + capitalize(provider));
          else apiError('Payment not confirmed yet. Please refresh, or email us your reference.');
        })
        .catch(function (err) {
          // Even if frontend verify fails, the webhook will reconcile shortly. Show a soft success.
          showSuccess(reference, 'Payment received. Our system will confirm shortly.');
          console.warn('verify_error', err);
        });
    }
    function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }

    function loadScript(src) {
      return new Promise(function (resolve, reject) {
        if (document.querySelector('script[src="' + src + '"]')) return resolve();
        var s = document.createElement('script'); s.src = src; s.async = true;
        s.onload = resolve; s.onerror = function () { reject(new Error('script_load_failed')); };
        document.head.appendChild(s);
      });
    }

    function showSuccess(ref, paymentNote) {
      clearDraft();
      var main = $('.apply-main'); if (!main) return;
      main.innerHTML = '' +
        '<div class="apply-success">' +
        '  <div class="success-emoji" aria-hidden="true" style="background:transparent;border:none"><dotlottie-wc src="https://fonts.gstatic.com/s/e/notoemoji/latest/1f389/lottie.json" speed="1" autoplay loop class="fx-lottie fx-xl" aria-hidden="true"></dotlottie-wc></div>' +
        '  <h2 class="success-h">You\'re In!</h2>' +
        '  <p class="success-p">Thank you for enrolling in MAM Academy. Our team will reach you within <strong>24 hours</strong> to confirm your schedule and book your <strong>MAM Auditer</strong> entry assessment.</p>' +
        '  <div class="success-ref">Reference: ' + ref + '</div>' +
        '  <p class="success-p" style="font-size:0.88rem;color:var(--text-muted)">' + escapeHtml(paymentNote) + '</p>' +
        '  <div style="margin:24px auto 28px;max-width:480px;padding:18px 22px;background:rgba(224,138,26,0.08);border:1px solid var(--border-gold);border-radius:12px;font-size:0.88rem;color:var(--text-soft);line-height:1.65;text-align:left">' +
        '    <strong style="display:block;color:var(--gold-bright);margin-bottom:6px"><i class="fa-solid fa-envelope-open-text"></i> Check your email</strong>' +
        '    We just emailed you your student portal credentials — sign in to track your application, see your classes once you\'re placed, and view your MAM Auditer report.' +
        '  </div>' +
        '  <div class="success-actions">' +
        '    <a href="portal/login.html" class="btn btn-primary"><i class="fa-solid fa-right-to-bracket"></i> Open Student Portal</a>' +
        '    <a href="index.html" class="btn btn-secondary"><i class="fa-solid fa-house"></i> Back to Home</a>' +
        '  </div>' +
        '</div>';
      window.scrollTo({ top: main.offsetTop - 100, behavior: 'smooth' });
    }

    // ---------- Boot ----------
    updateSummary();
    showStep(0);
  }
})();
