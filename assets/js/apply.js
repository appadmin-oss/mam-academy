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

    var savedDraft = loadDraft();
    var resuming = !!savedDraft;
    var data = savedDraft || {
      pathway: '', mode: '', level: '', expression: '', minors: [],
      program: '', discipline: '', session: '',
      firstName: '', lastName: '', email: '', phone: '',
      sex: '', address: '', lga: '', state: '',
      country: 'Nigeria', city: '', dob: '',
      experience: '', goals: '', referrer: '',
      auditer: true, parentName: '', parentPhone: '',
      headshotUrl: '',
      includeRegistration: true, includeCertification: false, payment: '', currency: 'USD',
      programTrack: '', policyAgree: false, policySignName: '',
      reference: null, applicationId: null, serverTotalUsd: null
    };
    // Clean up legacy draft shape — early-2026 drafts had `age` and `emergencyContact`.
    if ('age' in data) delete data.age;
    if ('emergencyContact' in data) delete data.emergencyContact;
    if (typeof data.dob !== 'string') data.dob = '';
    // Default the new required fields to empty so legacy drafts don't write "undefined"
    ['program','sex','address','lga','state','headshotUrl','session','level','expression'].forEach(function (k) {
      if (typeof data[k] !== 'string') data[k] = '';
    });
    if (!Array.isArray(data.minors)) data.minors = [];
    // Stable idempotency key per draft — lets a retried submit be de-duped
    // server-side instead of creating a second application for one applicant.
    if (!data.idempotencyKey) {
      data.idempotencyKey = 'MAMK-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
      saveDraft();
    }
    // The legacy "wind" discipline was split into saxophone/trumpet/flute/clarinet —
    // any draft that still carries it needs the user to re-pick a specific instrument.
    if (data.discipline === 'wind') data.discipline = '';

    // KTAMS (Kids @ MAM) = under 16; MAM = 16 and above. Derived entirely from DOB.
    function ageFromDob(dob) {
      if (!dob) return null;
      var d = new Date(dob); if (isNaN(d)) return null;
      var t = new Date();
      var a = t.getFullYear() - d.getFullYear();
      var m = t.getMonth() - d.getMonth();
      if (m < 0 || (m === 0 && t.getDate() < d.getDate())) a--;
      if (a < 0 || a > 120) return null;
      return a;
    }
    function ageBandFromDob(dob) {
      var a = ageFromDob(dob); if (a === null) return '';
      if (a < 6)  return 'under-6';
      if (a <= 9) return '6-9';
      if (a <= 12) return '10-12';
      if (a <= 15) return '13-15';
      if (a <= 17) return '16-17';
      if (a <= 25) return '18-25';
      return '26+';
    }
    function deriveTrack(dob) {
      var a = ageFromDob(dob); if (a === null) return '';
      return a < 16 ? 'KTAMS' : 'MAM';
    }
    var BAND_LABELS = {
      'under-6':'Under 6','6-9':'6–9 years','10-12':'10–12 years','13-15':'13–15 years',
      '16-17':'16–17 years','18-25':'18–25 years','26+':'26 & above'
    };

    // Pre-fill from URL ?pathway=cert&mode=online&discipline=piano.
    // SECURITY: only accept a value that matches a real radio option for that
    // field. Without this allowlist, an arbitrary ?discipline=<img onerror…>
    // is reflected unescaped into #reviewBody/#applySummary innerHTML at boot
    // (titleCase() does not neutralise HTML) — a reflected DOM XSS. Matching
    // against the rendered inputs makes only known-safe tokens enter `data`.
    try {
      var qs = new URLSearchParams(location.search);
      // `?programme=` is the public-facing alias for the internal `pathway`.
      if (!qs.get('pathway') && qs.get('programme')) {
        try { qs.set('pathway', qs.get('programme')); } catch (e) {}
      }
      ['pathway', 'mode', 'discipline', 'level', 'expression'].forEach(function (k) {
        var val = qs.get(k);
        if (val && document.querySelector('input[name="' + k + '"][value="' + (window.CSS && CSS.escape ? CSS.escape(val) : val.replace(/["\\\]]/g, '')) + '"]')) {
          data[k] = val;
        }
      });
    } catch (e) {}

    function showStep(i) {
      current = Math.max(0, Math.min(totalSteps - 1, i));
      stepsEls.forEach(function (s, idx) { s.classList.toggle('active', idx === current); });
      progressSteps.forEach(function (p, idx) {
        p.classList.toggle('active', idx === current);
        p.classList.toggle('done', idx < current);
        // Future steps require validation — make that visible so the buttons
        // don't look broken when nothing happens on click.
        var isFuture = idx > current;
        p.setAttribute('aria-disabled', isFuture ? 'true' : 'false');
        if (isFuture) p.setAttribute('tabindex', '-1'); else p.removeAttribute('tabindex');
      });
      var main = $('.apply-main');
      if (main) window.scrollTo({ top: main.offsetTop - 100, behavior: 'smooth' });
      updateSummary();
      updateTrackUI();
      if (current === 3) renderPolicy();
      progressSteps.forEach(function (p, idx) { p.setAttribute('aria-current', idx === current ? 'step' : 'false'); });
      var ambStep = $('#ambStep'); if (ambStep) ambStep.textContent = 'Step ' + (current + 1) + ' of ' + totalSteps;
      var bar = $('#applyMobileBar'); if (bar) bar.style.display = current === totalSteps - 1 ? 'none' : '';
      // Remember where the applicant is so a return visit resumes here.
      data.currentStep = current; saveDraft();
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
        // Drop any smart-validation note the moment the user edits again
        var note = document.getElementById('note-' + id); if (note) note.remove();
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
    // Inline coloured note attached underneath a field. Used by smart
    // validation to surface the *specific* reason ("That looks like a
    // temporary email service…") below the matching input. One note per
    // field; replacing it removes any prior note.
    function setFieldHelp(id, text, kind) {
      var input = document.getElementById(id); if (!input) return;
      var parent = input.parentNode; if (!parent) return;
      var noteId = 'note-' + id;
      var note = document.getElementById(noteId);
      if (!note) {
        note = document.createElement('div');
        note.id = noteId;
        note.className = 'field-note';
        parent.appendChild(note);
      }
      note.className = 'field-note field-note-' + (kind || 'info');
      note.textContent = text;
    }
    function setFieldHelpError(id, text) { setFieldHelp(id, text, 'error'); }
    function setFieldHelpWarn(id, text)  { setFieldHelp(id, text, 'warn');  }
    function setFieldHelpOk(id, text)    { setFieldHelp(id, text, 'ok');    }
    function clearFieldHelp(id) { var n = document.getElementById('note-' + id); if (n) n.remove(); }
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
        function need(cond, errId) { if (!cond) { var e = $('#' + errId); if (e) e.classList.add('shown'); ok = false; } else { var e2 = $('#' + errId); if (e2) e2.classList.remove('shown'); } }
        var prog = data.pathway;
        need(!!prog, 'err-pathway');
        need(!!data.mode, 'err-mode');
        // Level required for the level-priced programmes only.
        var levelled = prog === 'standard' || prog === 'certified-musician' || prog === 'grand-musician';
        if (levelled) need(!!data.level, 'err-level');
        // Expression required for crash/standard/certified (Grand is all three;
        // MetaMusic Order has none).
        var needsExpr = prog === 'crash' || prog === 'standard' || prog === 'certified-musician';
        if (needsExpr) need(!!data.expression, 'err-expression');
        check('program',  !!data.program);
        need(!!data.discipline, 'err-discipline');
        // Session is resolved silently — never validated.
      }
      if (idx === 1) {
        var SV = window.MAM_VALIDATE;
        // Smart name validation: gibberish/placeholder detection beats a
        // bare length check ("asdf" was passing the old `length > 1` rule).
        var fn = SV ? SV.name(data.firstName || '') : { ok: (data.firstName || '').trim().length > 1 };
        var ln = SV ? SV.name(data.lastName  || '') : { ok: (data.lastName  || '').trim().length > 1 };
        check('firstName', fn.ok); if (!fn.ok && fn.reason) setFieldHelpError('firstName', fn.reason);
        check('lastName',  ln.ok); if (!ln.ok && ln.reason) setFieldHelpError('lastName',  ln.reason);
        // Email: disposable-domain check + typo detection. A 'warn' lets us
        // surface a message but still allow submission — the user can click
        // through if they really mean it.
        var em = SV ? SV.email(data.email || '') : { ok: /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((data.email || '').trim()) };
        if (em.ok || em.severity === 'warn') {
          clearError('email');
          if (em.severity === 'warn' && em.reason) setFieldHelpWarn('email', em.reason);
        } else {
          setError('email'); if (em.reason) setFieldHelpError('email', em.reason); ok = false;
        }
        // Phone: trust intl-tel-input's libphonenumber-utils when available,
        // fall back to permissive regex if the country-picker didn't initialise.
        var phoneOk;
        if (phoneIti) {
          try { phoneOk = phoneIti.isValidNumber(); } catch (e) { phoneOk = false; }
          if (phoneOk) { data.phone = phoneIti.getNumber(); saveDraft(); setFieldHelpOk('phone', 'Looks valid · ' + phoneIti.getNumber('international')); }
          else if ((data.phone || '').trim()) setFieldHelpError('phone', 'That number isn\'t valid for the selected country.');
        } else {
          phoneOk = /^[+0-9\s\-()]{7,}$/.test((data.phone || '').trim());
        }
        check('phone', phoneOk);
        if (!data.sex) { var sxe = $('#err-sex'); if (sxe) sxe.classList.add('shown'); ok = false; }
        check('address',   (data.address || '').trim().length > 2);
        check('lga',       (data.lga     || '').trim().length > 1);
        check('state',     (data.state   || '').trim().length > 1);
        check('country',   (data.country || '').trim().length > 1);
        check('dob',       ageFromDob(data.dob) !== null);
        // Under-16 (KTAMS) applicants must provide a parent/guardian; if the
        // intl-tel-input is wired on parentPhone, also validate it.
        if (deriveTrack(data.dob) === 'KTAMS') {
          check('parentName', (data.parentName || '').trim().length > 1);
          if (parentPhoneIti) {
            var ppOk = false;
            try { ppOk = parentPhoneIti.isValidNumber(); } catch (e) {}
            if (ppOk) data.parentPhone = parentPhoneIti.getNumber();
          }
        }
        // Headshot is mandatory — every student gets a class roster photo
        // and the AI check stops us collecting blurry/multi-face/no-face
        // shots that we'd otherwise have to chase manually.
        var hsOk = !!(headshotState && headshotState.validation && headshotState.validation.ok && (headshotState.uploaded || !hasCloudinaryConfigured()));
        if (!hsOk) {
          ok = false;
          var hsHost = document.getElementById('headshotMount');
          if (hsHost) {
            hsHost.classList.add('headshot-required-pulse');
            if (!first) first = hsHost;
            // Persistent inline status nudge under the dropzone
            var status = hsHost.parentNode && hsHost.parentNode.querySelector('.hs-status');
            if (status) {
              status.hidden = false;
              status.className = 'hs-status hs-status-error';
              status.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> A clear headshot is required to continue. Upload a front-facing photo.';
            }
          }
        }
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
    // The programme fee already folds in any mode-based adjustment. We surface
    // only the FINAL figure to the applicant — the adjustment is never shown as
    // a separate line (it stays a backend pricing concern).
    // Display-only mirror of the server engine (mam_compute_application_price).
    // B = the chosen level's base price; C = compulsory fee ($120, Certified/
    // Grand only). Online (+20%) is folded into the total silently.
    function priceBreakdown() {
      var prog = data.pathway;
      var B = (cfg.levelPrices && cfg.levelPrices[data.level]) || 0;
      var half = B / 2;
      var extra = (data.minors || []).length;
      var comp = (cfg.compulsoryFeeUsd != null ? cfg.compulsoryFeeUsd : 120);
      var discPct = (cfg.grandDiscountPct != null ? cfg.grandDiscountPct : 13);
      var subtotal = 0, onlineApplies = true, compulsory = 0, preDiscount = null, discountAmount = 0;
      if (prog === 'crash') {
        subtotal = (cfg.pricing.crash && cfg.pricing.crash.usd) || 70; onlineApplies = false;
      } else if (prog === 'standard') {
        subtotal = B + half * extra;
      } else if (prog === 'certified-musician') {
        compulsory = comp;
        subtotal = B + compulsory + half * extra;
      } else if (prog === 'grand-musician') {
        preDiscount = 3 * B + half * extra;
        subtotal = preDiscount * (1 - discPct / 100);
        discountAmount = preDiscount - subtotal;
      } else if (prog === 'meta-order') {
        subtotal = (cfg.pricing['meta-order'] && cfg.pricing['meta-order'].usd) || 60; onlineApplies = false;
      }
      var adjusted = subtotal;
      if (onlineApplies && data.mode === 'online' && cfg.onlineSurchargePct) {
        adjusted = subtotal * (1 + cfg.onlineSurchargePct / 100);
      }
      var registration = data.includeRegistration ? (cfg.registrationFeeUsd || 0) : 0;
      // Prices are flat whole-dollar amounts (no decimals) — mirror the server.
      var r0 = function (n) { return Math.round(n); };
      adjusted = r0(adjusted); registration = r0(registration);
      return {
        base: r0(B), extra: extra, compulsory: r0(compulsory), discountPct: discPct,
        preDiscount: preDiscount === null ? null : r0(preDiscount), discountAmount: r0(discountAmount),
        subtotal: adjusted, registration: registration, total: adjusted + registration
      };
    }
    function programmeFeeUsd() { return priceBreakdown().subtotal; }
    function computeTotalUsd() {
      if (data.serverTotalUsd != null) return data.serverTotalUsd;
      return priceBreakdown().total;
    }
    function fmtMoney(usd, cur) {
      cur = cur || data.currency || 'USD';
      var rate = cfg.currency.rates[cur] || 1;
      var sym = { USD: '$', NGN: '₦', GBP: '£', EUR: '€' }[cur] || '$';
      return sym + Math.round(usd * rate).toLocaleString('en-US');
    }
    function updateSummary() {
      updateProgrammeUI();
      var summary = $('#applySummary'); if (!summary) return;
      var price = cfg.pricing[data.pathway];
      var b = priceBreakdown();
      var rows = '';
      if (price) rows += row('Programme', price.name);
      if (data.mode)       rows += row('Mode', data.mode === 'online' ? 'Online' : 'Onsite');
      if (data.level && cfg.levels && cfg.levels[data.level]) rows += row('Level', cfg.levels[data.level]);
      if (data.expression) rows += row('Expression', data.pathway === 'grand-musician' ? 'All three' : titleCase(data.expression));
      if (data.program)    rows += row('Focus', titleCase(data.program));
      if (data.discipline) rows += row('Course', titleCase(data.discipline));
      if (b.compulsory > 0) rows += row('Compulsory courses', fmtMoney(b.compulsory));
      if ((data.minors || []).length) rows += row('Extra courses', data.minors.map(titleCase).join(', '));
      if (price) {
        if (b.preDiscount != null && b.discountAmount > 0) {
          rows += '<div class="summary-row summary-discount"><span>Grand discount (' + b.discountPct + '%)</span><span>− ' + fmtMoney(b.discountAmount) + '</span></div>';
        }
        rows += row('Tuition', fmtMoney(b.subtotal));
      }
      if (data.includeRegistration && cfg.registrationFeeUsd) rows += row('Registration', fmtMoney(b.registration));
      var total = computeTotalUsd();
      rows += '<div class="summary-total"><span>Total</span><span>' + fmtMoney(total) + '</span></div>';
      if (data.reference) rows += '<div style="margin-top:10px;font-size:0.78rem;color:var(--text-muted);text-align:center">Ref: <span class="mono" style="color:var(--gold-bright)">' + data.reference + '</span></div>';
      summary.innerHTML = rows;
      var review = $('#reviewBody'); if (review) review.innerHTML = renderReview();
      var pb = $('#btnPay');
      if (pb) { var l = pb.querySelector('span:first-child'); if (l) l.textContent = 'Pay ' + fmtMoney(total); }
      var amb = $('#ambTotal'); if (amb) amb.textContent = fmtMoney(total);
      var sp = $('#spTotal'); if (sp) sp.textContent = fmtMoney(total);
    }

    // Conditional Step-1 substeps + live price labels. Idempotent; called from
    // updateSummary (which fires on every change) and on showStep. Crash & the
    // MetaMusic Order have no level; Grand spans all expressions (so the
    // expression step is hidden and set to 'all'); minors apply to Certified &
    // Grand only.
    function toggleHidden(sel, hide) { var el = $(sel); if (el) el.hidden = !!hide; }
    function uncheck(name) { $$('input[name="' + name + '"]').forEach(function (i) { i.checked = false; }); }
    function updateProgrammeUI() {
      var prog = data.pathway;
      var lows = (cfg.levelPrices && Object.keys(cfg.levelPrices).map(function (k) { return cfg.levelPrices[k]; })) || [120];
      var lowB = lows.length ? Math.min.apply(null, lows) : 120;
      var B = (cfg.levelPrices && cfg.levelPrices[data.level]) || 0;
      var bCalc = B || lowB;
      var pre = B ? '' : 'from ';
      var comp = (cfg.compulsoryFeeUsd != null ? cfg.compulsoryFeeUsd : 120);
      var discPct = (cfg.grandDiscountPct != null ? cfg.grandDiscountPct : 13);
      function setP(p, txt) { var el = document.querySelector('[data-prog-price="' + p + '"]'); if (el) el.textContent = txt; }
      setP('crash', fmtMoney((cfg.pricing.crash && cfg.pricing.crash.usd) || 70));
      setP('meta-order', fmtMoney((cfg.pricing['meta-order'] && cfg.pricing['meta-order'].usd) || 60));
      setP('standard', pre + fmtMoney(bCalc));
      setP('certified-musician', pre + fmtMoney(bCalc + comp));
      setP('grand-musician', pre + fmtMoney(Math.round(3 * bCalc * (1 - discPct / 100))));
      if (cfg.levelPrices) Object.keys(cfg.levelPrices).forEach(function (k) {
        var el = document.querySelector('[data-level-price="' + k + '"]'); if (el) el.textContent = fmtMoney(cfg.levelPrices[k]);
      });
      var badge = $('#grandSaveBadge');
      if (badge) { if (discPct > 0) { badge.hidden = false; badge.textContent = 'Save ' + discPct + '%'; } else badge.hidden = true; }

      var levelled  = prog === 'standard' || prog === 'certified-musician' || prog === 'grand-musician';
      var needsExpr = prog === 'crash' || prog === 'standard' || prog === 'certified-musician';
      // Additional courses (+50%·base each) are allowed on Standard, Certified
      // and Grand — only Crash & the MetaMusic Order have none.
      var hasMinors = prog === 'standard' || prog === 'certified-musician' || prog === 'grand-musician';
      toggleHidden('#substepLevel', !levelled);
      toggleHidden('#substepExpression', !needsExpr);
      toggleHidden('#substepMinors', !hasMinors);
      // Clear now-irrelevant selections so a stale value never prices or submits.
      if (!levelled && data.level) { data.level = ''; uncheck('level'); }
      if (prog === 'grand-musician') { data.expression = 'all'; }
      else if (!needsExpr && data.expression) { data.expression = ''; uncheck('expression'); }
      if (!hasMinors && (data.minors || []).length) { data.minors = []; }
      // The "compulsory courses included" note applies to Certified/Grand only.
      var compNote = $('#compulsoryNote');
      if (compNote) compNote.hidden = !(prog === 'certified-musician' || prog === 'grand-musician');
      if (hasMinors) renderMinors();
      var sn = $('#sessionNote'); if (sn) sn.hidden = !data.session;
      // Renumber only the *visible* substeps so hiding Level/Expression/Minors
      // never leaves a confusing gap (e.g. "1, 2, 5, 6").
      var step0 = $('.step[data-step="0"]');
      if (step0) {
        var n = 0;
        $$('.substep-head', step0).forEach(function (h) {
          var wrap = h.closest ? h.closest('.substep') : null;
          if (wrap && wrap.hidden) return;
          n++;
          var num = h.querySelector('.substep-num'); if (num) num.textContent = n;
        });
      }
    }

    // Additional ("minor") courses — instruments/courses beyond the major, each
    // at half the base. Rendered from a catalog, excluding the chosen major.
    var MINOR_CATALOG = [
      ['piano', 'Piano & Keyboard'], ['voice', 'Voice & Choir'], ['guitar', 'Guitar'],
      ['bass', 'Bass'], ['violin', 'Violin / Strings'], ['saxophone', 'Saxophone'],
      ['trumpet', 'Trumpet'], ['flute', 'Flute'], ['clarinet', 'Clarinet'],
      ['drums', 'Drums & Percussion'], ['robotics', 'Robotics']
    ];
    var minorsRenderKey = null;
    function renderMinors() {
      var grid = $('#minorsGrid'); if (!grid) return;
      var key = (data.discipline || '') + '|' + (data.level || '');
      if (minorsRenderKey === key && grid.children.length) return;
      minorsRenderKey = key;
      // A minor can never equal the major.
      data.minors = (data.minors || []).filter(function (m) { return m !== data.discipline; });
      var half = ((cfg.levelPrices && cfg.levelPrices[data.level]) || 0) / 2;
      grid.innerHTML = MINOR_CATALOG.filter(function (c) { return c[0] !== data.discipline; }).map(function (c) {
        var on = data.minors.indexOf(c[0]) >= 0 ? ' checked' : '';
        var price = half ? ('+' + fmtMoney(half)) : '+½ base';
        return '<label class="choice-card"><input type="checkbox" name="minor" value="' + c[0] + '"' + on + '>'
          + '<div class="choice-card-head"><span class="choice-card-title">' + escapeHtml(c[1]) + '</span><span class="choice-card-price">' + price + '</span></div>'
          + '<span class="choice-card-icon"></span></label>';
      }).join('');
      $$('#minorsGrid input[name="minor"]').forEach(function (inp) {
        on(inp, 'change', function () {
          var i = data.minors.indexOf(inp.value);
          if (inp.checked && i < 0) data.minors.push(inp.value);
          else if (!inp.checked && i >= 0) data.minors.splice(i, 1);
          saveDraft(); updateSummary();
        });
      });
    }
    function row(k, v) { return '<div class="summary-row"><span>' + k + '</span><span>' + escapeHtml(v) + '</span></div>'; }
    function titleCase(s) { return String(s || '').replace(/[-_]/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }); }

    // ---------- Track (KTAMS vs MAM) ----------
    function updateTrackUI() {
      data.programTrack = deriveTrack(data.dob);
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
      // Guardian section is only shown for KTAMS (under-16) — no need to
      // collect parent details for an adult applicant.
      var isKid = data.programTrack === 'KTAMS';
      var gf = $('#guardianField'); if (gf) gf.hidden = !isKid;
      if (!isKid) clearError('parentName');

      // Live age-band readout under the DOB picker — gives the applicant
      // immediate, visible confirmation that the date they entered places
      // them on the right track.
      var readout = $('#dobReadout');
      var ageEl = $('#dobAge'), bandEl = $('#dobBand');
      var a = ageFromDob(data.dob);
      if (a === null) {
        if (readout) readout.hidden = true;
      } else {
        if (readout) readout.hidden = false;
        if (ageEl) ageEl.textContent = a + (a === 1 ? ' year' : ' years');
        var band = ageBandFromDob(data.dob);
        if (bandEl) bandEl.textContent = (BAND_LABELS[band] || '') + ' · ' + (isKid ? 'KTAMS — Kids @ MAM' : 'MAM Academy');
      }
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
      function item(l, v) { return '<div class="review-item"><span class="review-label">' + l + '</span><span class="review-value">' + escapeHtml(v || '—') + '</span></div>'; }
      var html = '';
      html += '<div class="review-section"><div class="review-section-head"><h4>Programme</h4><button type="button" data-jump="0">Edit</button></div><div class="review-grid">';
      var trk = deriveTrack(data.dob);
      html += item('Track', trk && cfg.tracks[trk] ? cfg.tracks[trk].name : '—');
      html += item('Programme', price ? price.name : '—');
      html += item('Mode',    data.mode === 'online' ? 'Online' : (data.mode === 'onsite' ? 'Onsite Lagos' : '—'));
      if (data.level && cfg.levels && cfg.levels[data.level]) html += item('Level', cfg.levels[data.level]);
      if (data.expression) html += item('Expression', data.pathway === 'grand-musician' ? 'All three' : titleCase(data.expression));
      html += item('Focus', titleCase(data.program));
      html += item('Primary course', titleCase(data.discipline));
      if ((data.minors || []).length) html += item('Additional courses', data.minors.map(titleCase).join(', '));
      if (data.session) html += item('Session', data.sessionLabel || titleCase(data.session));
      html += item('MAM Auditer', data.auditer ? 'Yes — book session' : 'No');
      html += '</div></div>';
      var ageDisp = ageFromDob(data.dob);
      var dobDisp = data.dob ? new Date(data.dob).toLocaleDateString(undefined, { year:'numeric', month:'long', day:'numeric' }) : '';
      var ageLine = (ageDisp !== null ? (ageDisp + ' yrs') : '') + (dobDisp ? ' · ' + dobDisp : '');
      var locLine = [data.address, data.lga, data.state, data.country].filter(function (v) { return v && v.trim(); }).join(', ');
      html += '<div class="review-section"><div class="review-section-head"><h4>Student Profile</h4><button type="button" data-jump="1">Edit</button></div><div class="review-grid">';
      html += item('Name',    (data.firstName + ' ' + data.lastName).trim());
      html += item('Sex',     titleCase(data.sex));
      html += item('Email',   data.email);
      html += item('Phone',   data.phone);
      html += item('Age',     ageLine || '—');
      html += '</div>';
      if (locLine) html += '<div class="review-item" style="margin-top:14px"><span class="review-label">Address</span><span class="review-value">' + escapeHtml(locLine) + '</span></div>';
      if (data.headshotUrl) html += '<div class="review-item" style="margin-top:14px"><span class="review-label">Headshot</span><span class="review-value"><img src="' + escapeHtml(data.headshotUrl) + '" alt="Headshot" style="width:56px;height:56px;object-fit:cover;border-radius:50%;border:2px solid var(--border-gold);vertical-align:middle"></span></div>';
      html += '</div>';
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
    // Review "Edit" buttons live inside #reviewBody, which is re-rendered via
    // innerHTML — so we delegate instead of binding once (the old bug).
    on(document, 'click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-jump]') : null;
      if (!b) return;
      e.preventDefault();
      // Editing an earlier step invalidates the server reference; the
      // user will re-submit when they return to the review step.
      data.reference = null; data.applicationId = null; data.serverTotalUsd = null;
      saveDraft(); updateSummary();
      showStep(parseInt(b.getAttribute('data-jump'), 10));
    });
    progressSteps.forEach(function (p, idx) { on(p, 'click', function () { if (idx < current) showStep(idx); }); });

    // ---------- Field bindings ----------
    bindRadioGroup('pathway', 'pathway');
    bindRadioGroup('mode', 'mode');
    bindRadioGroup('level', 'level');
    bindRadioGroup('expression', 'expression');
    bindRadioGroup('discipline', 'discipline');
    bindRadioGroup('sex', 'sex');
    // phone + parentPhone are owned by intl-tel-input below; binding them here
    // would race with the country picker's setNumber().
    ['firstName','lastName','email','address','lga','state','country','city','dob','program','experience','goals','referrer','parentName'].forEach(function (id) { bindField(id, id); });

    // ---------- Headshot widget (AI-validated; upload required when Cloudinary is configured) ----------
    var headshotState = null;
    function hasCloudinaryConfigured() {
      var c = (window.MAM_CONFIG && window.MAM_CONFIG.cdn) || {};
      return !!(c.cloudName && c.uploadPreset);
    }
    var hsMount = document.getElementById('headshotMount');
    if (hsMount && window.MAM_HEADSHOT) {
      window.MAM_HEADSHOT.mount(hsMount, {
        onChange: function (s) {
          headshotState = s;
          data.headshotUrl = (s.uploaded && s.uploaded.url) || '';
          saveDraft();
        }
      });
    }

    // ---------- intl-tel-input: international phone with country flag/picker ----------
    // Initialises on #phone and #parentPhone. On every change we write the
    // E.164 form back into data.phone / data.parentPhone so the payload and
    // draft always carry a canonical, dial-anywhere number.
    function initIti(inputId, key) {
      var el = document.getElementById(inputId);
      if (!el || typeof window.intlTelInput !== 'function') return null;
      var iti = window.intlTelInput(el, {
        initialCountry: 'ng',
        preferredCountries: ['ng', 'gb', 'us', 'ca', 'za'],
        separateDialCode: true,
        utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@23.0.12/build/js/utils.js'
      });
      // Hydrate from existing draft value (E.164 → flag auto-picks)
      if (data[key]) try { iti.setNumber(data[key]); } catch (e) {}
      var sync = function () {
        var raw = el.value.trim();
        // Persist E.164 when valid; otherwise persist whatever the user typed
        // so the draft round-trips losslessly even mid-edit.
        var num = '';
        try { num = iti.isValidNumber() ? iti.getNumber() : raw; } catch (e) { num = raw; }
        data[key] = num;
        if (el.classList.contains('error')) el.classList.remove('error');
        var errEl = document.getElementById('err-' + inputId); if (errEl) errEl.classList.remove('shown');
        var note = document.getElementById('note-' + inputId); if (note) note.remove();
        saveDraft();
      };
      on(el, 'blur',            sync);
      on(el, 'countrychange',   sync);
      on(el, 'input',           function () { data[key] = el.value.trim(); saveDraft(); });
      return iti;
    }
    var phoneIti       = initIti('phone',       'phone');
    var parentPhoneIti = initIti('parentPhone', 'parentPhone');

    // ---------- Location-aware address labels ----------
    // Administrative-division terminology differs by country (an "LGA" in
    // Nigeria is a "County" in the US, a "District" in Ghana…). We relabel the
    // address block to read naturally for the applicant's country while the
    // underlying data keys (lga/state/city) never change — so the payload,
    // validation and DB are untouched. Driven by the phone field's country
    // picker plus a best-effort locale guess on first load. EDIT HERE to tune
    // wording for a country, or add a new ISO2 entry.
    var LOCALE_LABELS = {
      _default: { l1: 'State / Province', l1eg: 'State / region',          l2: 'Local Government / County', l2eg: 'District / county',        city: 'City / Town', cityEg: 'City',         addr: 'Street address' },
      NG: { l1: 'State',            l1eg: 'e.g. Lagos State',        l2: 'Local Government (LGA)', l2eg: 'e.g. Alimosho',            city: 'City / Town', cityEg: 'Lagos',        addr: 'House no. & street' },
      US: { l1: 'State',            l1eg: 'e.g. California',         l2: 'County',                 l2eg: 'e.g. Los Angeles County',  city: 'City',        cityEg: 'Los Angeles',  addr: 'Street address' },
      GB: { l1: 'County',           l1eg: 'e.g. Greater London',     l2: 'Town / District',        l2eg: 'e.g. Camden',              city: 'City / Town', cityEg: 'London',       addr: 'House no. & street' },
      CA: { l1: 'Province',         l1eg: 'e.g. Ontario',            l2: 'Municipality',           l2eg: 'e.g. Ottawa',              city: 'City',        cityEg: 'Toronto',      addr: 'Street address' },
      ZA: { l1: 'Province',         l1eg: 'e.g. Gauteng',            l2: 'Municipality',           l2eg: 'e.g. Johannesburg Metro',  city: 'City / Town', cityEg: 'Johannesburg', addr: 'Street address' },
      GH: { l1: 'Region',           l1eg: 'e.g. Greater Accra',      l2: 'District',               l2eg: 'e.g. Accra Metropolitan',  city: 'City / Town', cityEg: 'Accra',        addr: 'House no. & street' },
      KE: { l1: 'County',           l1eg: 'e.g. Nairobi',            l2: 'Sub-county',             l2eg: 'e.g. Westlands',           city: 'Town',        cityEg: 'Nairobi',      addr: 'Street address' },
      IN: { l1: 'State',            l1eg: 'e.g. Maharashtra',        l2: 'District',               l2eg: 'e.g. Mumbai Suburban',     city: 'City',        cityEg: 'Mumbai',       addr: 'House no. & street' },
      AU: { l1: 'State / Territory',l1eg: 'e.g. New South Wales',    l2: 'Local Government Area',  l2eg: 'e.g. City of Sydney',      city: 'Suburb / City',cityEg: 'Sydney',      addr: 'Street address' }
    };
    // Minimal country-name → ISO2 map so typing the country also adapts labels.
    var NAME2ISO = {
      nigeria: 'NG', 'united states': 'US', usa: 'US', 'united states of america': 'US', america: 'US',
      'united kingdom': 'GB', uk: 'GB', england: 'GB', britain: 'GB', 'great britain': 'GB',
      canada: 'CA', 'south africa': 'ZA', ghana: 'GH', kenya: 'KE', india: 'IN', australia: 'AU'
    };
    var currentLocaleIso = '';
    function setLabel(id, text, required) {
      var lab = document.querySelector('label[for="' + id + '"]'); if (!lab) return;
      lab.innerHTML = escapeHtml(text) + (required ? ' <span class="req">*</span>' : '');
    }
    function setPlaceholder(id, text) { var el = document.getElementById(id); if (el) el.setAttribute('placeholder', text); }
    function setErrMsg(id, text) { var s = document.querySelector('#err-' + id + ' span'); if (s) s.textContent = text; }
    function applyLocale(iso2) {
      iso2 = (iso2 || '').toUpperCase();
      var loc = LOCALE_LABELS[iso2] || LOCALE_LABELS._default;
      if (currentLocaleIso === iso2) return;
      currentLocaleIso = iso2;
      setLabel('state', loc.l1, true);  setPlaceholder('state', loc.l1eg); setErrMsg('state', 'Enter your ' + loc.l1.toLowerCase());
      setLabel('lga',   loc.l2, true);  setPlaceholder('lga',   loc.l2eg); setErrMsg('lga',   'Enter your ' + loc.l2.toLowerCase());
      setLabel('city',  loc.city, false); setPlaceholder('city', loc.cityEg);
      setPlaceholder('address', loc.addr);
    }
    // Best-effort country guess from the browser locale (offline, instant).
    function guessCountryIso() {
      try {
        var langs = (navigator.languages && navigator.languages.length) ? navigator.languages : [navigator.language || ''];
        for (var i = 0; i < langs.length; i++) {
          var m = String(langs[i]).match(/-([A-Za-z]{2})\b/);
          if (m) return m[1].toUpperCase();
        }
      } catch (e) {}
      return '';
    }
    // Initial: prefer a country already in the draft, else the phone picker,
    // else the locale guess, else Nigeria (the academy's home market).
    (function initLocale() {
      var fromDraft = data.country ? NAME2ISO[String(data.country).trim().toLowerCase()] : '';
      var iso = fromDraft || guessCountryIso() || 'NG';
      // If the visitor isn't Nigerian and hasn't typed a country yet, reflect
      // the guess in the phone picker too (only when no phone draft exists).
      if (phoneIti && !data.phone && iso && iso !== 'NG' && LOCALE_LABELS[iso]) {
        try { phoneIti.setCountry(iso.toLowerCase()); } catch (e) {}
      }
      applyLocale(iso);
      // Location-aware display currency — default to the visitor's likely
      // currency among the ones we display, on a FRESH visit only (a returning
      // applicant's chosen currency in their draft is always respected).
      if (!resuming) {
        var EU = 'AT BE CY EE FI FR DE GR IE IT LV LT LU MT NL PT SK SI ES'.split(' ');
        var cur = iso === 'NG' ? 'NGN' : iso === 'GB' ? 'GBP' : (EU.indexOf(iso) >= 0 ? 'EUR' : 'USD');
        if (cur !== data.currency && cfg.currency && cfg.currency.rates && cfg.currency.rates[cur]) {
          data.currency = cur;
          var sel = $('#applyCurrency'); if (sel) sel.value = cur;
          saveDraft();
        }
      }
    })();
    // Phone country picker drives the labels; also autofill the country field.
    if (phoneIti) {
      on(document.getElementById('phone'), 'countrychange', function () {
        try {
          var cd = phoneIti.getSelectedCountryData();
          if (cd && cd.iso2) {
            applyLocale(cd.iso2);
            var cEl = document.getElementById('country');
            if (cEl && !(data.country || '').trim() && cd.name) {
              var nm = cd.name.replace(/\s*\(.*\)$/, ''); // strip "(+234)" suffix
              cEl.value = nm; data.country = nm; saveDraft(); updateSummary();
            }
          }
        } catch (e) {}
      });
    }
    // Typing a recognised country name also adapts the labels.
    on(document.getElementById('country'), 'input', function () {
      var iso = NAME2ISO[String(this.value || '').trim().toLowerCase()];
      if (iso) applyLocale(iso);
    });

    // ---------- Live per-field validation (instant feedback on blur) ----------
    // One validator per field id; returns {ok, msg?, warn?}. Reused by the
    // on-blur affordance below. Step-level validateStep() still gates Next.
    var SV = window.MAM_VALIDATE;
    function validateField(id) {
      var v = String(data[id] == null ? '' : data[id]).trim();
      switch (id) {
        case 'firstName':
        case 'lastName':  return SV ? SV.name(v) : { ok: v.length > 1 };
        case 'email':     return SV ? SV.email(v) : { ok: /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) };
        case 'address':   return { ok: v.length > 2, msg: 'Enter your address' };
        case 'lga':       return { ok: v.length > 1, msg: 'Enter your ' + (LOCALE_LABELS[currentLocaleIso] || LOCALE_LABELS._default).l2.toLowerCase() };
        case 'state':     return { ok: v.length > 1, msg: 'Enter your ' + (LOCALE_LABELS[currentLocaleIso] || LOCALE_LABELS._default).l1.toLowerCase() };
        case 'country':   return { ok: v.length > 1, msg: 'Enter country' };
        case 'dob':       return { ok: ageFromDob(v) !== null, msg: 'A valid date of birth is required' };
        default:          return { ok: true };
      }
    }
    function markField(id) {
      var el = document.getElementById(id); if (!el || (el.value || '') === '') { setValid(id, false); return; }
      var r = validateField(id);
      if (r.ok) {
        clearError(id);
        if (r.warn && r.reason) setFieldHelpWarn(id, r.reason); else clearFieldHelp(id);
        setValid(id, !r.warn);
      } else {
        setValid(id, false);
        setError(id);
        if (r.reason || r.msg) setFieldHelpError(id, r.reason || r.msg);
      }
    }
    function setValid(id, on) {
      var el = document.getElementById(id); if (!el) return;
      el.classList.toggle('valid', !!on);
    }
    // Wire blur validation + Enter-to-advance on the Step-2 text inputs.
    ['firstName', 'lastName', 'email', 'address', 'lga', 'state', 'country', 'dob'].forEach(function (id) {
      var el = document.getElementById(id); if (!el) return;
      on(el, 'blur', function () { markField(id); });
      on(el, 'keydown', function (e) {
        if (e.key === 'Enter' && el.tagName !== 'TEXTAREA') { e.preventDefault(); attemptNext(); }
      });
    });

    bindField('auditerOptIn', 'auditer');
    bindField('includeRegistration', 'includeRegistration');
    bindField('includeCertification', 'includeCertification');
    bindField('policySignName', 'policySignName');
    bindField('policyAgree', 'policyAgree');
    // Live feedback: age drives the KTAMS/MAM track + guardian requirement; mode drives the policy shown.
    // Changing mode after the user already signed/agreed must revoke that agreement —
    // an online-policy signature is not valid consent for the onsite policy.
    $$('input[name="mode"]').forEach(function (i) {
      on(i, 'change', function () {
        if (data.policyAgree || (data.policySignName || '').trim() !== '') {
          data.policyAgree = false;
          data.policySignName = '';
          var pa = $('#policyAgree'); if (pa) pa.checked = false;
          var pn = $('#policySignName'); if (pn) pn.value = '';
          // Editing the mode also invalidates the server-issued reference — the
          // next "Continue to Payment" will resubmit with the new policy mode.
          data.reference = null; data.applicationId = null; data.serverTotalUsd = null;
          saveDraft();
        }
        renderPolicy();
      });
    });
    // DOB is the single source of truth — every age-dependent UI (track
    // badge, KTAMS guardian fields, age-band readout, KTAMS copy in Step 2)
    // listens to DOB changes through updateTrackUI() and applyKtamsCopy().
    var dobEl = document.getElementById('dob');
    if (dobEl) {
      // Cap to today so future-dated DOBs can't be picked; also enforce a
      // sensible lower bound matching the server's age <= 120 check.
      var today = new Date().toISOString().slice(0, 10);
      var minDate = new Date(); minDate.setFullYear(minDate.getFullYear() - 120);
      dobEl.setAttribute('max', today);
      dobEl.setAttribute('min', minDate.toISOString().slice(0, 10));
      on(dobEl, 'change', function () {
        clearError('dob');
        updateTrackUI();
        updateSummary();
        if (typeof applyKtamsCopy === 'function') applyKtamsCopy();
      });
    }

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
    on($('#ambView'), 'click', function () {
      var panel = $('#summaryPanel'); if (panel) panel.open = true;
      var s = panel || $('#applySummary');
      if (s) s.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    // ---------- Submit application ----------
    var submitting = false;
    function submitApplicationToServer() {
      // Re-entrancy guard: a fast double-click or a stray keypress must never
      // fire two submits and create duplicate applications for one applicant.
      if (submitting) return;
      submitting = true;
      setBusy('btnNext4', true, 'Submitting…');
      api.submitApplication(buildPayload(), data.idempotencyKey)
        .then(function (resp) {
          data.reference = resp.reference;
          data.applicationId = resp.application_id;
          data.serverTotalUsd = resp.total_usd;
          saveDraft();
          // The server has just emailed the confirmation + invoice. Now advance
          // to Step 5 (Payment) where the applicant pays the regional booking
          // fee. They can also bail and pay later via the link in the email.
          setBusy('btnNext4', false);
          submitting = false;
          showStep(current + 1);
        })
        .catch(function (err) {
          setBusy('btnNext4', false);
          submitting = false;
          // Always log the raw error to the console so the operator can see
          // exactly what the server returned (status, body, message). The
          // friendly user-facing message is chosen below.
          try { console.error('[MAM_APPLY] submit failed', { status: err && err.status, body: err && err.body, message: err && err.message, raw: err }); } catch (e) {}
          if (err && err.body && err.body.fields) {
            showServerErrors(err.body.fields);
          } else if (err && err.status === 429) {
            apiError('Too many attempts. Please try again in a few minutes.');
          } else if (err && (err.apiDown || err.message === 'api_unavailable' || err.message === 'Failed to fetch')) {
            apiUnavailableFallback();
          } else if (err && err.body && err.body.error) {
            // Surface the server's machine-readable error code so support can
            // help quickly. Operator already sees the full body in the console.
            apiError('Submission failed: ' + err.body.error + (err.body.message ? ' — ' + err.body.message : ''));
          } else {
            apiError(err && err.message ? err.message : 'Submission failed. Please try again.');
          }
        });
    }
    // When the API can't be reached, never lose the application — let the
    // applicant send it by email in one tap, pre-filled with their details.
    function apiUnavailableFallback() {
      var p = cfg.pricing[data.pathway] || {};
      var subject = 'MAM Academy application — ' + (data.firstName || '') + ' ' + (data.lastName || '');
      var bodyLines = [
        'Hi MAM Academy, I tried to apply on the site but checkout was unavailable. My details:',
        '', 'Name: ' + data.firstName + ' ' + data.lastName,
        'Email: ' + data.email, 'Phone: ' + data.phone,
        'Country/City: ' + (data.country || '') + ' ' + (data.city || ''),
        'Date of birth: ' + (data.dob || '') + ' (' + (deriveTrack(data.dob) || '') + ')',
        'Pathway: ' + (p.name || data.pathway), 'Mode: ' + data.mode, 'Discipline: ' + data.discipline,
        'Add-ons: ' + (data.includeRegistration ? 'Registration' : 'None'),
        'Policy agreed: ' + (data.policyAgree ? 'Yes — ' + (data.policySignName || '') : 'No'),
        '', 'Please confirm next steps. Thank you.'
      ];
      var mailto = 'mailto:' + cfg.site.contactEmail + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(bodyLines.join('\n'));
      var notice = ensureNotice();
      notice.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> We couldn\'t reach our secure server just now. Your details are saved on this device — '
        + '<a href="' + mailto + '" style="color:var(--gold-bright);font-weight:700;text-decoration:underline">email your application in one tap</a> '
        + 'and we\'ll confirm within 24 hours, or try again shortly.';
      notice.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    function buildPayload() {
      return {
        firstName: data.firstName, lastName: data.lastName,
        email: data.email, phone: data.phone,
        sex: data.sex,
        pathway: data.pathway, mode: data.mode,
        level: data.level, expression: data.expression,
        program: data.program, discipline: data.discipline,
        extraCourses: (data.minors || []).length,
        coursesLabel: (data.minors || []).map(titleCase).join(', '),
        session: data.session,
        address: data.address, lga: data.lga, state: data.state,
        country: data.country, city: data.city, dob: data.dob,
        experience: data.experience, goals: data.goals, referrer: data.referrer,
        parentName: data.parentName, parentPhone: data.parentPhone,
        headshotUrl: data.headshotUrl,
        auditer: !!data.auditer,
        includeRegistration: !!data.includeRegistration,
        includeCertification: !!data.includeCertification,
        programTrack: deriveTrack(data.dob),
        policyMode: data.mode,
        policyAgreed: !!data.policyAgree,
        policySignedName: data.policySignName
      };
    }
    // Server field name -> (input id used by setError, owning step index).
    // Used both to surface inline errors and to send the user to the lowest
    // step that contains a failing field instead of always jumping to Profile.
    var SERVER_FIELDS = {
      pathway:          { id: null,              step: 0 },
      mode:             { id: null,              step: 0 },
      level:            { id: null,              step: 0 },
      expression:       { id: null,              step: 0 },
      program:          { id: 'program',         step: 0 },
      discipline:       { id: null,              step: 0 },
      session:          { id: null,              step: 0 },
      firstName:        { id: 'firstName',       step: 1 },
      lastName:         { id: 'lastName',        step: 1 },
      email:            { id: 'email',           step: 1 },
      phone:            { id: 'phone',           step: 1 },
      sex:              { id: null,              step: 1 },
      address:          { id: 'address',         step: 1 },
      lga:              { id: 'lga',             step: 1 },
      state:            { id: 'state',           step: 1 },
      country:          { id: 'country',         step: 1 },
      dob:              { id: 'dob',             step: 1 },
      parentName:       { id: 'parentName',      step: 1 },
      policyAgreed:     { id: 'policyAgree',     step: 3 },
      policySignedName: { id: 'policySignName',  step: 3 }
    };
    var FIELD_LABELS = {
      pathway: 'programme', mode: 'learning mode', level: 'level', expression: 'expression', program: 'focus area', discipline: 'primary course', session: 'session',
      firstName: 'first name', lastName: 'last name', email: 'email',
      phone: 'mobile number', sex: 'sex',
      address: 'address', lga: 'local government', state: 'state', country: 'country',
      dob: 'date of birth',
      parentName: 'parent/guardian (under-16 students)',
      policyAgreed: 'class policy agreement',
      policySignedName: 'policy signature'
    };
    function showServerErrors(fields) {
      var target = null;
      var labels = [];
      fields.forEach(function (f) {
        var spec = SERVER_FIELDS[f]; if (!spec) return;
        if (spec.id) {
          setError(spec.id);
        } else {
          // Grouped fields (pathway/mode/discipline) live in radio cards; the
          // matching .field-error has id err-<field>.
          var ge = document.getElementById('err-' + f);
          if (ge) ge.classList.add('shown');
        }
        if (target === null || spec.step < target) target = spec.step;
        if (FIELD_LABELS[f]) labels.push(FIELD_LABELS[f]);
      });
      if (target !== null) showStep(target);
      if (labels.length) {
        var notice = ensureNotice();
        notice.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Please fix the highlighted '
          + (labels.length === 1 ? 'field' : 'fields') + ': <strong>' + labels.join(', ') + '</strong>.';
      }
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
    function ensureNotice() {
      var notice = $('#apiNotice');
      if (!notice) {
        notice = document.createElement('div');
        notice.id = 'apiNotice';
        notice.setAttribute('role', 'alert');
        notice.style.cssText = 'margin:14px 0;padding:14px 16px;background:rgba(244,63,94,0.1);border:1px solid rgba(244,63,94,0.45);border-radius:12px;color:#FECDD3;font-size:0.88rem;line-height:1.55';
        var main = $('.apply-main'); if (main) main.prepend(notice);
      }
      return notice;
    }
    function apiError(msg) {
      var notice = ensureNotice();
      notice.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + msg + ' &middot; If this keeps happening, email <a href="mailto:' + cfg.site.contactEmail + '" style="color:var(--gold-bright)">' + cfg.site.contactEmail + '</a>';
      notice.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // ---------- Regional booking-fee pricing ----------
    // Pulled from /api/booking/pricing on page load. The server picks the tier
    // based on the visitor's country (CDN header) and is authoritative at
    // charge time — the values here are display-only. We refresh the visible
    // amount whenever the tier resolves so Step 5, the mobile bar and the
    // Review note are all in sync.
    var feeTier = null;
    function paintFee() {
      if (!feeTier) return;
      var label = feeTier.label || '$5';
      [$('#payAmount'), $('#payHeroLabel'), $('#reviewFeeLabel'), $('#ambTotal')].forEach(function (el) { if (el) el.textContent = label; });
      var bp = $('#btnPayLabel'); if (bp) bp.textContent = 'Pay ' + label;
      var cn = $('#payCountryName'); if (cn) cn.textContent = (feeTier.country && feeTier.country !== 'DEFAULT') ? feeTier.country : 'your region';
      var flag = $('#payCountryFlag'); if (flag) {
        var c = (feeTier.country || '').toUpperCase();
        flag.textContent = (c.length === 2 && c !== 'DE' && c !== 'EU')
          ? String.fromCodePoint(0x1F1A5 + c.charCodeAt(0), 0x1F1A5 + c.charCodeAt(1))
          : (c === 'EU' ? '🇪🇺' : '🌍');
      }
      // For non-NGN tiers we disclose that settlement happens in NGN via Paystack.
      var disc = $('#payDisclose');
      if (disc) {
        if (feeTier.currency && feeTier.currency !== 'NGN') {
          disc.classList.add('show');
          disc.innerHTML = '<i class="fa-solid fa-circle-info"></i> Your card will be charged in <strong>' + feeTier.currency + '</strong> at <strong>' + label + '</strong>. Bank transfer settles in NGN (₦' + (feeTier.amount_ngn || 0).toLocaleString() + ').';
        } else { disc.classList.remove('show'); }
      }
    }
    (function loadFee() {
      try {
        fetch('/api/booking/pricing', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (j) { if (j && j.ok) { feeTier = j; paintFee(); } })
          .catch(function () {});
      } catch (e) {}
    })();

    // ---------- Silent session ----------
    // The academic session is NEVER a form step. It's resolved from the calendar
    // server-side and shown read-only here; the server re-resolves at submit, so
    // a stale client value can never be trusted.
    (function loadSession() {
      try {
        fetch('/api/session/current', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j || !j.ok || !j.code) return;
            data.session = j.code;
            data.sessionLabel = (j.label || j.code) + (j.year ? ' ' + j.year : '');
            saveDraft();
            var lab = $('#sessionNoteLabel'); if (lab) lab.textContent = data.sessionLabel;
            var sub = $('#sessionNoteSub');
            if (sub) {
              var s = '';
              if (j.status === 'upcoming' && j.starts_on) s = ' · starts ' + j.starts_on;
              else if (j.status === 'active') s = ' · enrolling now';
              sub.textContent = s;
            }
            updateSummary();
          })
          .catch(function () {});
      } catch (e) {}
    })();

    // ---------- Pay ----------
    on($('#btnPay'), 'click', function (e) {
      e.preventDefault();
      if (!data.reference) { apiError('Please complete the previous step first.'); return; }
      if (!data.payment) { showFieldError('payment'); return; }
      setBusy('btnPay', true, 'Opening secure checkout…');
      api.initializePayment({ reference: data.reference, provider: data.payment, purpose: 'booking_fee' })
        .then(function (init) {
          if (init.already_paid) { showSuccess(data.reference, 'Already confirmed.'); return; }
          if (data.payment === 'paystack')      return openPaystack(init);
          if (data.payment === 'flutterwave')   return openFlutterwave(init);
          if (data.payment === 'bank')          return handleBank(init);
          throw new Error('unsupported_provider');
        })
        .catch(function (err) {
          setBusy('btnPay', false);
          apiError(err && err.message ? err.message : 'Could not start payment. Try another method, or pay later via the link we just emailed you.');
        });
    });

    function loadScript(src) {
      return new Promise(function (resolve, reject) {
        if (document.querySelector('script[src="' + src + '"]')) return resolve();
        var s = document.createElement('script'); s.src = src; s.async = true;
        s.onload = resolve; s.onerror = function () { reject(new Error('script_load_failed')); };
        document.head.appendChild(s);
      });
    }
    function openPaystack(init) {
      return loadScript('https://js.paystack.co/v1/inline.js').then(function () {
        setBusy('btnPay', false);
        var handler = window.PaystackPop.setup({
          key: init.public_key,
          email: data.email,
          amount: init.amount_ngn * 100,
          currency: 'NGN',
          ref: init.reference,
          metadata: { reference: init.reference, application_id: data.applicationId, purpose: 'booking_fee' },
          callback: function (response) { afterProviderCharge('paystack', response.reference || init.reference); },
          onClose: function () { setBusy('btnPay', false); }
        });
        handler.openIframe();
      });
    }
    function openFlutterwave(init) {
      return loadScript('https://checkout.flutterwave.com/v3.js').then(function () {
        setBusy('btnPay', false);
        var payload = init.payload || {};
        payload.public_key = init.public_key;
        payload.payment_options = 'card, banktransfer, ussd, mobilemoneyghana, mobilemoneyfranco';
        payload.callback = function (response) {
          afterProviderCharge('flutterwave', response.tx_ref || init.reference, response.transaction_id);
        };
        payload.onclose = function () { setBusy('btnPay', false); };
        window.FlutterwaveCheckout(payload);
      });
    }
    function handleBank(init) {
      setBusy('btnPay', false);
      showSuccess(init.reference, 'Bank transfer initiated. ' + (init.instructions || 'See your email for instructions.'));
    }

    function afterProviderCharge(provider, reference, transactionId) {
      setBusy('btnPay', true, 'Verifying…');
      api.verifyPayment({ provider: provider, reference: reference, transaction_id: transactionId || 0 })
        .then(function (res) {
          if (res.status === 'succeeded') showSuccess(reference, 'Paid via ' + capitalize(provider));
          else apiError('Payment not confirmed yet. Please refresh, or email us your reference.');
        })
        .catch(function (err) {
          console.warn('verify_error', err);
          // A 402 is a DEFINITIVE rejection (e.g. amount/currency mismatch) —
          // never show success for it. Only a transient/network/5xx error gets
          // the soft "webhook will reconcile" message, since a genuine payment
          // will be confirmed by the signed webhook shortly.
          var status = err && err.status;
          if (status === 402 || (err && err.body && err.body.error === 'verification_failed')) {
            apiError('We could not confirm your payment amount. If you were charged, email ' +
              cfg.site.contactEmail + ' with reference ' + reference + ' and we will sort it out.');
          } else {
            showSuccess(reference, 'Payment received. Our system will confirm shortly.');
          }
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
        '  <div class="success-emoji" aria-hidden="true"><span class="emoji-pop" role="img" aria-label="Confetti">🎉</span></div>' +
        '  <h2 class="success-h">You\'re In!</h2>' +
        '  <p class="success-p">Thank you for enrolling in MAM Academy. Our team will reach you within <strong>24 hours</strong> to confirm your schedule and book your <strong>MAM Auditer</strong> entry assessment.</p>' +
        '  <div class="success-ref">Reference: ' + ref + '</div>' +
        '  <p class="success-p" style="font-size:0.88rem;color:var(--text-muted)">' + escapeHtml(paymentNote) + '</p>' +
        '  <div style="margin:24px auto 28px;max-width:480px;padding:18px 22px;background:rgba(224,138,26,0.08);border:1px solid var(--border-gold);border-radius:12px;font-size:0.88rem;color:var(--text-soft);line-height:1.65;text-align:left">' +
        '    <strong style="display:block;color:var(--gold-bright);margin-bottom:6px"><i class="fa-solid fa-envelope-open-text"></i> Check your email</strong>' +
        '    We just emailed you your student portal credentials — sign in to track your application, see your classes once you\'re placed, and view your MAM Auditer report.' +
        '  </div>' +
        '  <div class="success-actions">' +
        '    <a href="portal/login" class="btn btn-primary"><i class="fa-solid fa-right-to-bracket"></i> Open Student Portal</a>' +
        '    <a href="/" class="btn btn-secondary"><i class="fa-solid fa-house"></i> Back to Home</a>' +
        '  </div>' +
        '</div>';
      window.scrollTo({ top: main.offsetTop - 100, behavior: 'smooth' });
    }

    // ──────────────────────────────────────────────────────────
    // Smart, context-aware behaviours
    // ──────────────────────────────────────────────────────────

    // 1) Pathway → Discipline coupling. The MetaMusic Order intensive IS the
    //    MetaMusic discipline — auto-select it unless the user picked one
    //    already, and surface a contextual tip.
    function reactToPathwayChange() {
      // Auto-pick metamusic discipline for the MetaMusic Order pathway
      if (data.pathway === 'meta-order' && !data.discipline) {
        var radio = document.querySelector('input[name="discipline"][value="metamusic"]');
        if (radio) {
          radio.checked = true;
          data.discipline = 'metamusic';
          saveDraft(); updateSummary();
          var err = $('#err-discipline'); if (err) err.classList.remove('shown');
        }
      }
      renderPathwayHint();
      renderAddonContext();
    }

    // Every programme — Grand Musician included — can be taken online or onsite.
    // The mode-based price difference is handled entirely on the server, so the
    // form imposes no mode restriction per programme.
    $$('input[name="pathway"]').forEach(function (i) { on(i, 'change', reactToPathwayChange); });

    // 2) Pathway → Add-on relevance. The standalone $22 graduation add-on was
    //    retired (certification is now baked into the programme fee), leaving
    //    only the always-relevant $5 registration. Kept as a no-op so callers
    //    stay stable if a future add-on becomes pathway-specific again.
    function renderAddonContext() { /* no pathway-specific add-ons remain */ }

    function renderPathwayHint() {
      var anchor = document.querySelector('.field [id="err-pathway"]');
      if (!anchor) return;
      var hintId = 'pathway-context-tip';
      var tip = document.getElementById(hintId);
      var msg = '';
      if (data.pathway === 'meta-order') {
        msg = 'The MetaMusic Order is our 12-week civilization-engineering intensive — discipline is pre-set to MetaMusic & Consciousness.';
      } else if (data.pathway === 'crash') {
        msg = '6-week accelerated intensive — a fast-track version of the standard curriculum.';
      } else if (data.pathway === 'grand-musician') {
        msg = 'Our flagship: you train across all three traditions — African, Classical & Contemporary — plus the supporting courses. Runs across all four certification levels.';
      } else if (data.pathway === 'certified-musician') {
        msg = 'Pick one major tradition (Contemporary, Classical or African) plus your supporting courses. Your MAM Auditer placement sets your starting level.';
      }
      if (!msg) { if (tip) tip.remove(); return; }
      if (!tip) {
        tip = document.createElement('div');
        tip.id = hintId;
        tip.className = 'context-tip';
        anchor.parentNode.appendChild(tip);
      }
      tip.innerHTML = '<i class="fa-solid fa-circle-info"></i><span>' + msg + '</span>';
    }

    // 3) Mode → contextual callout. Online students learn what kit they'll
    //    need without scrolling; onsite students see the actual address.
    function renderModeHint() {
      var anchor = document.querySelector('.field [id="err-mode"]');
      if (!anchor) return;
      var hintId = 'mode-context-tip';
      var tip = document.getElementById(hintId);
      var msg = '';
      if (data.mode === 'online') {
        msg = "You'll need <strong>two internet-connected devices</strong> (one on your instrument, one on your face) and a quiet space. We hold live video classes plus daily practice review.";
      } else if (data.mode === 'onsite') {
        msg = 'Onsite classes are at <strong>MAM Centre, 2 Oremeji Street, Egbeda, Lagos</strong>. Arrive 30 minutes early; ensemble &amp; stagecraft are baked in.';
      }
      if (!msg) { if (tip) tip.remove(); return; }
      if (!tip) {
        tip = document.createElement('div');
        tip.id = hintId;
        tip.className = 'context-tip';
        anchor.parentNode.appendChild(tip);
      }
      tip.innerHTML = '<i class="fa-solid fa-' + (data.mode === 'online' ? 'wifi' : 'location-dot') + '"></i><span>' + msg + '</span>';
    }
    $$('input[name="mode"]').forEach(function (i) { on(i, 'change', renderModeHint); });

    // 4) Email typo detection. Catches the half-dozen domain typos that
    //    silently swallow our confirmation emails ("gmial.com", "yahooo.com",
    //    etc.) and offers a one-tap correction.
    var EMAIL_TYPOS = {
      'gmial.com':'gmail.com','gmal.com':'gmail.com','gmai.com':'gmail.com',
      'gnail.com':'gmail.com','gmail.con':'gmail.com','gmail.co':'gmail.com',
      'gmaill.com':'gmail.com','gmail.cm':'gmail.com',
      'yahooo.com':'yahoo.com','yaho.com':'yahoo.com','yhoo.com':'yahoo.com',
      'yahoo.con':'yahoo.com','yahoo.co':'yahoo.com',
      'hotnail.com':'hotmail.com','hotmial.com':'hotmail.com','hotmal.com':'hotmail.com',
      'hotmail.con':'hotmail.com','hotmai.com':'hotmail.com',
      'outlok.com':'outlook.com','outloook.com':'outlook.com','outlook.con':'outlook.com',
      'icoud.com':'icloud.com','iclod.com':'icloud.com','icloud.con':'icloud.com',
      'ymai.com':'ymail.com','live.con':'live.com','aol.con':'aol.com'
    };
    function suggestEmailFix(value) {
      var at = value.lastIndexOf('@');
      if (at < 1) return null;
      var local = value.slice(0, at);
      var domain = value.slice(at + 1).toLowerCase().trim();
      if (!domain) return null;
      if (EMAIL_TYPOS[domain]) return local + '@' + EMAIL_TYPOS[domain];
      // Missing dot or single-char TLD ("gmail.c", "gmailcom")
      if (domain.indexOf('.') === -1) {
        if (domain === 'gmailcom') return local + '@gmail.com';
        if (domain === 'yahoocom') return local + '@yahoo.com';
      }
      return null;
    }
    var emailEl = document.getElementById('email');
    if (emailEl) {
      var emailTipId = 'email-typo-tip';
      var renderEmailTip = function () {
        var existing = document.getElementById(emailTipId);
        var s = suggestEmailFix((emailEl.value || '').trim());
        if (!s) { if (existing) existing.remove(); return; }
        if (!existing) {
          existing = document.createElement('div');
          existing.id = emailTipId;
          existing.className = 'context-tip context-tip-suggest';
          emailEl.parentNode.appendChild(existing);
        }
        existing.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i>'
          + '<span>Did you mean <button type="button" class="suggest-apply">' + escapeHtml(s) + '</button>?</span>';
        var btn = existing.querySelector('.suggest-apply');
        if (btn) on(btn, 'click', function () {
          emailEl.value = s; data.email = s; saveDraft();
          existing.remove();
          clearError('email');
          emailEl.focus();
        });
      };
      on(emailEl, 'blur', renderEmailTip);
      on(emailEl, 'input', function () {
        // Live-clear the tip the moment the user starts editing again
        var t = document.getElementById(emailTipId); if (t) t.remove();
      });
    }

    // 5) KTAMS-aware copy. Under-16 enrolments are usually completed by a
    //    parent — the form copy should match that mental model.
    function applyKtamsCopy() {
      var isKid = deriveTrack(data.dob) === 'KTAMS';
      var titleEl = document.querySelector('.step[data-step="1"] .step-title');
      var descEl  = document.querySelector('.step[data-step="1"] .step-desc');
      if (titleEl) titleEl.textContent = isKid ? "Child's Profile" : 'Student Profile';
      if (descEl)  descEl.textContent  = isKid
        ? 'Tell us about the young musician. The contact details below are the parent or guardian we should reach.'
        : 'Tell us about the student. We use this only to onboard and contact you about your enrollment.';
      var firstName = document.getElementById('firstName');
      var lastName  = document.getElementById('lastName');
      if (firstName) firstName.placeholder = isKid ? "Child's first name" : 'e.g. Tunde';
      if (lastName)  lastName.placeholder  = isKid ? "Child's last name"  : 'e.g. Adeyemi';
    }
    // DOB drives KTAMS copy; the change listener above on #dob already calls applyKtamsCopy.

    // 6) Currency auto-default by locale. First-time visitors land on the
    //    currency that matches their browser locale instead of always USD.
    function detectCurrencyFromLocale() {
      try {
        var lang = (navigator.language || 'en-US');
        var country = (lang.split('-')[1] || '').toUpperCase();
        if (country === 'NG') return 'NGN';
        if (country === 'GB' || country === 'UK') return 'GBP';
        if (['IE','DE','FR','IT','ES','NL','BE','AT','PT','FI','GR','LU','SK','SI','LT','LV','EE','CY','MT'].indexOf(country) >= 0) return 'EUR';
      } catch (e) {}
      return 'USD';
    }
    if (!data.currency || data.currency === 'USD') {
      var draftRaw = null;
      try { draftRaw = localStorage.getItem('mam.apply.draft'); } catch (e) {}
      // Only auto-default if the user has never touched the selector before
      if (!draftRaw || draftRaw.indexOf('"currency"') === -1) {
        var detected = detectCurrencyFromLocale();
        if (detected !== data.currency) {
          data.currency = detected;
          var sel = $('#applyCurrency'); if (sel) sel.value = detected;
          saveDraft();
        }
      }
    }

    // Returning visitor: resume where they left off (never auto-land on the
    // payment step — they must re-confirm review → submit), and offer a
    // one-tap "start over".
    function showResumeBanner() {
      var main = $('.apply-main'); if (!main || document.getElementById('resumeBanner')) return;
      var b = document.createElement('div');
      b.id = 'resumeBanner';
      b.className = 'resume-banner';
      b.setAttribute('role', 'status');
      b.innerHTML = '<span><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Welcome back — we saved your progress on this device.</span>' +
        '<button type="button" id="resumeStartOver">Start over</button>';
      main.insertBefore(b, main.firstChild);
      var btn = document.getElementById('resumeStartOver');
      if (btn) on(btn, 'click', function () {
        var go = function () { clearDraft(); location.href = location.pathname; };
        if (window.MAM_DIALOG && window.MAM_DIALOG.confirm) {
          window.MAM_DIALOG.confirm('Start a new application?', 'This clears the details saved on this device.', 'Start over').then(function (ok) { if (ok) go(); });
        } else if (confirm('Start over? This clears your saved details.')) { go(); }
      });
    }

    // Live pricing can arrive after first paint (config.js fetches it from
    // /api/config/public). Re-render the summary when it lands so the displayed
    // tuition matches the admin's current prices.
    document.addEventListener('mam:config', function () { updateSummary(); });

    // ---------- Boot ----------
    updateSummary();
    renderPathwayHint();
    renderModeHint();
    renderAddonContext();
    applyKtamsCopy();

    var restoreTo = 0;
    if (resuming && typeof data.currentStep === 'number') {
      // Cap at Review (3); the payment step always needs a fresh submit.
      restoreTo = Math.max(0, Math.min(3, data.currentStep));
    }
    showStep(restoreTo);
    if (resuming && restoreTo > 0) showResumeBanner();
  }
})();
