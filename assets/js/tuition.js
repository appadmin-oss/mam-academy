/* /tuition — pay tuition by reference + email (tuition.php). Moved out of the page
   unchanged in behaviour at the 2026-11 redesign: GET /api/tuition/status, then
   MAM_API.initializePayment({reference, provider, purpose:'tuition'}) and the
   provider's checkout, then MAM_API.verifyPayment. Needs config.js (MAM_API,
   MAM_CCY), loaded before it. */
(function () {
  var form = document.getElementById('tuForm'), btn = document.getElementById('tuGo');
  var refEl = document.getElementById('tuRef'), emEl = document.getElementById('tuEmail');
  var gate = document.getElementById('gate'), gateErr = document.getElementById('tuGateErr');
  var payCard = document.getElementById('payCard'), statusEl = document.getElementById('tuStatus');
  var ref = '', email = '', tu = null;
  var qs = new URLSearchParams(location.search);
  if (qs.get('ref')) refEl.value = qs.get('ref');
  if (qs.get('email')) emEl.value = qs.get('email');

  function esc(s) { return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function fmt(n) { return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }); }
  function status(msg, kind) {
    statusEl.className = 'tu-note ' + (kind === 'err' ? 'err' : 'ok');
    statusEl.innerHTML = msg; statusEl.hidden = false;
    statusEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  function gateError(msg) { gateErr.textContent = msg; gateErr.hidden = false; }
  function invoiceHint() {
    gateErr.textContent = 'That is an invoice reference. Invoices are paid in the family portal: sign in and open Fees to see and pay it. ';
    var a = document.createElement('a');
    a.href = '/portal/parent/'; a.textContent = 'Sign in to pay this invoice';
    gateErr.appendChild(a);
    gateErr.hidden = false;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    ref = refEl.value.trim(); email = emEl.value.trim();
    if (!ref || !email) return;
    /* An INV-… reference is a fee invoice, which lives in the family portal, not
       an application. Say where to pay it instead of "we couldn't find it". */
    if (/^INV-/i.test(ref)) { invoiceHint(); return; }
    var orig = btn.textContent;
    if (window.MAM_FORMS) MAM_FORMS.setBusy(btn, 'Checking…'); else { btn.disabled = true; btn.textContent = 'Checking…'; }
    gateErr.hidden = true;
    var unbusy = function () { if (window.MAM_FORMS) MAM_FORMS.resetBusy(btn); else { btn.disabled = false; btn.textContent = orig; } };
    var url = 'tuition/status?ref=' + encodeURIComponent(ref) + '&email=' + encodeURIComponent(email);
    (window.MAM_API ? window.MAM_API.request(url, { method: 'GET' }) : Promise.reject(new Error('api'))).then(function (r) {
      unbusy();
      if (r && r.kind === 'fee_invoice') { invoiceHint(); return; }
      if (!r || !r.found) { gateError('We couldn’t find an application with that reference and email. Double-check both, or email academy@mamcentre.org.'); return; }
      tu = r;
      showPay(r);
    }).catch(function () { unbusy(); gateError('We couldn’t reach our secure server. Please try again in a moment.'); });
  });
  if (refEl.value && emEl.value) form.dispatchEvent(new Event('submit'));

  function showPay(r) {
    gate.hidden = true; payCard.hidden = false;
    document.getElementById('tuHi').textContent = (r.name ? 'Hi ' + r.name + ', ' : '') + 'your tuition';
    document.getElementById('tuSub').innerHTML = 'Reference <strong>' + esc(r.reference) + '</strong>' + (r.pathway ? ' &middot; ' + esc(r.pathway) : '');
    if (r.already_paid) {
      document.getElementById('tuAmt').textContent = 'Paid';
      document.getElementById('tuCur').textContent = 'Thank you';
      document.querySelector('.tu-pay').style.display = 'none';
      status('Your tuition is already settled. <a href="/portal/login" style="color:#211A13;text-decoration:underline">Sign in to your student portal →</a>', 'ok');
      return;
    }
    document.getElementById('tuAmt').textContent = '$' + fmt(r.amount_usd);
    var cur = window.MAM_CCY ? MAM_CCY.detect() : 'USD';
    if (window.MAM_CCY && cur !== 'USD') {
      document.getElementById('tuCur').textContent = 'USD · approx ' + MAM_CCY.format(Number(r.amount_usd) || 0, cur);
    } else {
      document.getElementById('tuCur').textContent = r.amount_ngn ? ('USD · approx ₦' + fmt(r.amount_ngn)) : 'USD';
    }
    payCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function loadScript(src) {
    return new Promise(function (res, rej) {
      if (document.querySelector('script[src="' + src + '"]')) return res();
      var s = document.createElement('script'); s.src = src; s.async = true;
      s.onload = res; s.onerror = function () { rej(new Error('script')); };
      document.head.appendChild(s);
    });
  }
  function setBusy(b) { document.querySelectorAll('.tu-pay button').forEach(function (x) { x.disabled = b; }); }

  function pay(provider) {
    if (!window.MAM_API) { status('We couldn’t reach our secure server. Please refresh and try again.', 'err'); return; }
    setBusy(true); status('Opening secure checkout…', 'ok');
    window.MAM_API.initializePayment({ reference: ref, provider: provider, purpose: 'tuition' })
      .then(function (init) {
        if (init.already_paid) { status('Your tuition is already settled. <a href="/portal/login" style="color:#211A13">Sign in →</a>', 'ok'); setBusy(false); return; }
        if (window.MAM_CCY && (provider === 'paystack' || provider === 'flutterwave')) {
          var note = MAM_CCY.settlementNote(init); if (note) status(note, 'ok');
        }
        if (provider === 'paystack')    return openPaystack(init);
        if (provider === 'flutterwave') return openFlutterwave(init);
        if (provider === 'bank')        return showBank(init);
        throw new Error('unsupported');
      })
      .catch(function (err) {
        setBusy(false);
        try { console.error('[MAM_TUITION] checkout failed', { status: err && err.status, body: err && err.body, message: err && err.message }); } catch (e) {}
        var code = (err && err.body && err.body.error) || (err && err.message) || '';
        if (err && err.status === 429) status('That’s a few too many attempts. Please wait a minute and try again.', 'err');
        else status('Couldn’t start checkout (' + esc(code || 'unknown error') + '). Please try again, or email academy@mamcentre.org with reference ' + esc(ref) + '.', 'err');
      });
  }
  function afterCharge(provider, reference, txId) {
    status('Verifying your payment…', 'ok');
    window.MAM_API.verifyPayment({ provider: provider, reference: reference, transaction_id: txId || 0 })
      .then(function (res) {
        setBusy(false);
        if (res && res.status === 'succeeded') {
          document.querySelector('.tu-pay').style.display = 'none';
          document.getElementById('tuAmt').textContent = 'Paid';
          document.getElementById('tuCur').textContent = 'Thank you';
          status('Tuition received — welcome to MAM Academy! We’ve emailed your student-portal credentials. <a href="/portal/login" style="color:#211A13;text-decoration:underline">Sign in →</a>', 'ok');
        } else {
          status('Payment received — our system will confirm it shortly. You can refresh this page in a minute.', 'ok');
        }
      })
      .catch(function (err) {
        setBusy(false);
        var s = err && err.status;
        if (s === 402 || (err && err.body && err.body.error === 'verification_failed')) {
          status('We couldn’t confirm your payment amount. If you were charged, email academy@mamcentre.org with reference ' + esc(ref) + ' and we’ll sort it out.', 'err');
        } else {
          status('Payment received — our system will confirm it shortly.', 'ok');
        }
      });
  }
  function openPaystack(init) {
    return loadScript('https://js.paystack.co/v1/inline.js').then(function () {
      setBusy(false);
      var h = window.PaystackPop.setup({
        key: init.public_key, email: email, amount: init.amount_ngn * 100, currency: 'NGN', ref: init.reference,
        metadata: { reference: init.reference, purpose: 'tuition' },
        callback: function (resp) { afterCharge('paystack', resp.reference || init.reference); },
        onClose: function () { setBusy(false); status('Checkout closed — your tuition is still due whenever you’re ready.', 'ok'); }
      });
      h.openIframe();
    });
  }
  function openFlutterwave(init) {
    return loadScript('https://checkout.flutterwave.com/v3.js').then(function () {
      setBusy(false);
      var p = init.payload || {};
      p.public_key = init.public_key;
      p.payment_options = 'card, banktransfer, ussd';
      p.callback = function (resp) { afterCharge('flutterwave', resp.tx_ref || init.reference, resp.transaction_id); };
      p.onclose = function () { setBusy(false); };
      window.FlutterwaveCheckout(p);
    });
  }
  function showBank(init) {
    setBusy(false);
    var b = init.bank || {};
    var lines = b.account_number ? ('<br><strong>' + esc(b.bank) + '</strong> · ' + esc(b.account_name) + ' · <strong>' + esc(b.account_number) + '</strong>') : '';
    status((esc(init.instructions || ('Send the tuition and email proof to academy@mamcentre.org with reference ' + init.reference + '.'))) + lines + ' We email your portal credentials once confirmed.', 'ok');
  }
  document.querySelectorAll('.tu-pay button').forEach(function (b) {
    b.addEventListener('click', function () { pay(b.getAttribute('data-prov')); });
  });
})();
