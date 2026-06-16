<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Pay Tuition — MAM Academy</title>
<meta name="description" content="Securely pay your MAM Academy tuition with your application reference and email.">
<link rel="canonical" href="https://academy.mamcentre.org/tuition">
<meta name="robots" content="noindex, follow">
<meta property="og:type" content="website">
<meta property="og:site_name" content="MAM Academy">
<meta property="og:title" content="Pay Tuition — MAM Academy">
<meta property="og:image" content="https://academy.mamcentre.org/mam-academy-logo.svg">
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260616h">
<style>
  .tu-wrap{max-width:620px;margin:0 auto}
  .tu-card{background:#1A120B;border:1px solid rgba(224,138,26,.35);border-radius:16px;padding:26px 24px;box-shadow:0 12px 36px rgba(0,0,0,.28)}
  .tu-card h2{font-family:'Cinzel',serif;color:#fff;margin:0 0 4px;font-size:1.3rem}
  .tu-field{display:block;margin:14px 0}
  .tu-field span{display:block;font-size:.82rem;color:#cbb89e;margin-bottom:6px;font-weight:600}
  .tu-field input{width:100%;padding:12px 14px;border-radius:10px;border:1px solid rgba(224,138,26,.3);background:#120c07;color:#fff;font-size:1rem}
  .tu-field input:focus{outline:none;border-color:#E08A1A}
  .tu-btn{margin-top:6px;width:100%;padding:13px 18px;border:0;border-radius:11px;font-weight:800;letter-spacing:.04em;cursor:pointer;background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#1A120B;font-size:1rem}
  .tu-btn[disabled]{opacity:.6;cursor:default}
  .tu-amount{text-align:center;margin:8px 0 20px}
  .tu-amount .v{font-size:3rem;font-weight:800;color:#FBBF24;line-height:1;font-variant-numeric:tabular-nums}
  .tu-amount .c{font-size:.82rem;color:#9a8264;letter-spacing:.1em;text-transform:uppercase;margin-top:8px}
  .tu-pay{display:grid;gap:10px;margin-top:8px}
  .tu-pay button{display:flex;align-items:center;justify-content:center;gap:10px;padding:14px;border-radius:11px;border:1px solid rgba(224,138,26,.35);background:#120c07;color:#fff;font-weight:700;font-size:.96rem;cursor:pointer;min-height:48px}
  .tu-pay button.primary{background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#1A120B;border:0}
  .tu-note{margin-top:16px;padding:12px 14px;border-radius:10px;font-size:.9rem;line-height:1.5}
  .tu-note.err{background:rgba(244,63,94,.12);border:1px solid rgba(244,63,94,.5);color:#FECDD3}
  .tu-note.ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.5);color:#BBF7D0}
  .tu-muted{color:#9a8264;font-size:.86rem;line-height:1.55}
  .tu-muted a{color:#FBBF24}
  .spinner{display:inline-block;width:15px;height:15px;border:2px solid rgba(26,18,11,.35);border-top-color:#1A120B;border-radius:50%;animation:tuspin .7s linear infinite;vertical-align:-2px}
  @keyframes tuspin{to{transform:rotate(360deg)}}
  @media (prefers-reduced-motion:reduce){.spinner{animation:none}}
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<div id="preloader"><div class="preloader-logo"><img src="mam-academy-logo.svg" alt="" onerror="this.style.display='none'"><div class="preloader-name">MAM ACADEMY</div><div class="preloader-tag">Tuition Payment</div></div><div class="waves"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div></div>

<?php include __DIR__ . '/partials/header.html'; ?>

<main id="main">
  <header class="page-hero" style="--page-hero-img:url('https://images.unsplash.com/photo-1507676184212-d03ab07a01bf?w=1920&q=80')">
    <div class="page-hero-inner">
      <nav class="breadcrumb"><a href="/">Home</a><i class="fa-solid fa-chevron-right"></i><span>Pay Tuition</span></nav>
      <div class="page-hero-eyebrow"><i class="fa-solid fa-graduation-cap"></i>Enrolment</div>
      <h1 class="page-hero-title">Pay your <span class="highlight-gold">tuition</span></h1>
      <p class="page-hero-lede">Enter your reference and the email you applied with to view and securely pay your tuition.</p>
    </div>
  </header>

  <section style="padding:48px 20px">
    <div class="tu-wrap">
      <div class="tu-card" id="gate">
        <h2>Confirm it's you</h2>
        <p class="tu-muted">Your reference looks like <strong>MAM-XXXX-XXXX</strong> — it's in your confirmation email.</p>
        <form id="tuForm" novalidate>
          <label class="tu-field"><span>Application reference</span><input id="tuRef" type="text" autocomplete="off" placeholder="MAM-…" required></label>
          <label class="tu-field"><span>Email you applied with</span><input id="tuEmail" type="email" autocomplete="email" placeholder="you@example.com" required></label>
          <button class="tu-btn" id="tuGo" type="submit">View my tuition</button>
        </form>
        <div class="tu-note err" id="tuGateErr" role="alert" hidden></div>
      </div>

      <div class="tu-card" id="payCard" hidden>
        <h2 id="tuHi">Your tuition</h2>
        <p class="tu-muted" id="tuSub"></p>
        <div class="tu-amount"><div class="v" id="tuAmt">—</div><div class="c" id="tuCur">USD</div></div>
        <div class="tu-pay">
          <button type="button" class="primary" data-prov="paystack"><i class="fa-solid fa-credit-card"></i> Pay by card (Paystack)</button>
          <button type="button" data-prov="flutterwave"><i class="fa-solid fa-bolt"></i> Pay with Flutterwave</button>
          <button type="button" data-prov="bank"><i class="fa-solid fa-building-columns"></i> Pay by bank transfer</button>
        </div>
        <div class="tu-note" id="tuStatus" role="status" hidden></div>
        <p class="tu-muted" style="margin-top:16px">Secured by your payment provider. Your tuition is confirmed after the MAM Auditer judges place you — questions? <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a></p>
      </div>

      <p class="tu-muted" style="text-align:center;margin-top:18px">Not placed yet? <a href="/book">Book your MAM Auditer →</a> &middot; <a href="/status">Check application status →</a></p>
    </div>
  </section>
</main>

<?php include __DIR__ . '/partials/footer.html'; ?>
<script src="assets/js/config.js?v=20260616h"></script>
<script src="assets/js/main.js?v=20260616e"></script>
<script>
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

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    ref = refEl.value.trim(); email = emEl.value.trim();
    if (!ref || !email) return;
    btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Checking…'; gateErr.hidden = true;
    var url = 'tuition/status?ref=' + encodeURIComponent(ref) + '&email=' + encodeURIComponent(email);
    (window.MAM_API ? window.MAM_API.request(url, { method: 'GET' }) : Promise.reject(new Error('api'))).then(function (r) {
      btn.disabled = false; btn.textContent = orig;
      if (!r || !r.found) { gateError('We couldn’t find an application with that reference and email. Double-check both, or email academy@mamcentre.org.'); return; }
      tu = r;
      showPay(r);
    }).catch(function () { btn.disabled = false; btn.textContent = orig; gateError('We couldn’t reach our secure server. Please try again in a moment.'); });
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
      status('Your tuition is already settled. <a href="/portal/login" style="color:#fff;text-decoration:underline">Sign in to your student portal →</a>', 'ok');
      return;
    }
    document.getElementById('tuAmt').textContent = '$' + fmt(r.amount_usd);
    document.getElementById('tuCur').textContent = r.amount_ngn ? ('USD · approx ₦' + fmt(r.amount_ngn)) : 'USD';
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
        if (init.already_paid) { status('Your tuition is already settled. <a href="/portal/login" style="color:#fff">Sign in →</a>', 'ok'); setBusy(false); return; }
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
          status('Tuition received — welcome to MAM Academy! We’ve emailed your student-portal credentials. <a href="/portal/login" style="color:#fff;text-decoration:underline">Sign in →</a>', 'ok');
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
</script>
</body>
</html>
