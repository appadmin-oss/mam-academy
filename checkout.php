<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Checkout — MAM Academy Store</title>
<meta name="description" content="Securely check out from the MAM Academy Store — sheet music, recordings, learning bundles and merch. Region-aware delivery, card &amp; bank transfer.">
<link rel="canonical" href="https://academy.mamcentre.org/checkout">
<meta name="robots" content="noindex, follow">
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260616a">
<style>
  .co-wrap{max-width:1080px;margin:0 auto}
  .co-grid{display:grid;grid-template-columns:1.3fr 1fr;gap:26px;align-items:start}
  @media (max-width:860px){.co-grid{grid-template-columns:1fr}}
  .co-card{background:#1A120B;border:1px solid rgba(224,138,26,.32);border-radius:16px;padding:24px;box-shadow:0 12px 36px rgba(0,0,0,.28)}
  .co-card h2{font-family:'Cinzel',serif;color:#fff;margin:0 0 16px;font-size:1.2rem}
  .co-field{display:block;margin:13px 0}
  .co-field span{display:block;font-size:.82rem;color:#cbb89e;margin-bottom:6px;font-weight:600}
  .co-field input,.co-field select,.co-field textarea{width:100%;padding:12px 14px;border-radius:10px;border:1px solid rgba(224,138,26,.3);background:#120c07;color:#fff;font-size:1rem;font-family:inherit}
  .co-field input:focus,.co-field select:focus,.co-field textarea:focus{outline:none;border-color:#E08A1A}
  .co-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  @media (max-width:520px){.co-row{grid-template-columns:1fr}}
  .co-pay{display:grid;gap:10px;margin-top:6px}
  .co-pay label{display:flex;align-items:center;gap:12px;padding:13px 15px;border-radius:11px;border:1px solid rgba(224,138,26,.3);background:#120c07;cursor:pointer;font-weight:600;color:#e7d9c2}
  .co-pay label:has(input:checked){border-color:#E08A1A;background:rgba(224,138,26,.12);color:#fff}
  .co-pay input{accent-color:#E08A1A;width:18px;height:18px}
  .co-line{display:flex;gap:13px;padding:13px 0;border-bottom:1px solid rgba(224,138,26,.14)}
  .co-line-thumb{width:54px;height:54px;border-radius:10px;flex:0 0 auto;background:linear-gradient(135deg,rgba(252,198,71,.16),#241710);display:flex;align-items:center;justify-content:center;color:#FBBF24;overflow:hidden}
  .co-line-thumb img{width:100%;height:100%;object-fit:cover}
  .co-line-main{flex:1;min-width:0}
  .co-line-name{font-weight:700;color:#fff;font-size:.94rem;margin:0 0 2px;line-height:1.3}
  .co-line-meta{color:#9a8264;font-size:.82rem}
  .co-line-amt{font-weight:700;color:#FBBF24;font-variant-numeric:tabular-nums;white-space:nowrap}
  .co-tot{display:flex;justify-content:space-between;padding:7px 0;font-size:.95rem;color:#cbb89e}
  .co-tot.grand{margin-top:8px;padding-top:14px;border-top:1px solid rgba(224,138,26,.25);font-size:1.15rem;font-weight:800;color:#fff}
  .co-tot.grand b{color:#FBBF24;font-variant-numeric:tabular-nums}
  .co-place{width:100%;margin-top:16px;padding:15px;border:0;border-radius:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;cursor:pointer;background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#1A120B;font-size:1rem}
  .co-place[disabled]{opacity:.6;cursor:default}
  .co-trust{margin-top:12px;font-size:.78rem;color:#9a8264;text-align:center;line-height:1.6}
  .co-note{margin-top:14px;padding:12px 14px;border-radius:10px;font-size:.9rem;line-height:1.5}
  .co-note.err{background:rgba(244,63,94,.12);border:1px solid rgba(244,63,94,.5);color:#FECDD3}
  .co-note.ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.5);color:#BBF7D0}
  .co-empty{text-align:center;padding:60px 20px;color:#cbb89e}
  .co-empty i{font-size:3rem;color:rgba(224,138,26,.5);margin-bottom:16px;display:block}
  .spinner{display:inline-block;width:15px;height:15px;border:2px solid rgba(26,18,11,.35);border-top-color:#1A120B;border-radius:50%;animation:cospin .7s linear infinite;vertical-align:-2px}
  @keyframes cospin{to{transform:rotate(360deg)}}
  @media (prefers-reduced-motion:reduce){.spinner{animation:none}}
  .co-edit{color:#FBBF24;font-size:.84rem;text-decoration:none}
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<div id="preloader"><div class="preloader-logo"><img src="mam-academy-logo.svg" alt="" onerror="this.style.display='none'"><div class="preloader-name">MAM ACADEMY</div><div class="preloader-tag">Secure Checkout</div></div><div class="waves"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div></div>

<?php include __DIR__ . '/partials/header.html'; ?>

<main id="main">
  <header class="page-hero" style="--page-hero-img:url('https://images.unsplash.com/photo-1511671782779-c97d3d27a1d4?w=1920&q=80');padding:clamp(56px,7vw,84px) 24px clamp(40px,5vw,60px)">
    <div class="page-hero-inner">
      <nav class="breadcrumb"><a href="/">Home</a><i class="fa-solid fa-chevron-right"></i><a href="/store">Store</a><i class="fa-solid fa-chevron-right"></i><span>Checkout</span></nav>
      <h1 class="page-hero-title">Secure <span class="highlight-gold">checkout</span></h1>
      <p class="page-hero-lede">Review your order, add your details, and pay securely. Digital items arrive by email instantly.</p>
    </div>
  </header>

  <section style="padding:40px 20px">
    <div class="co-wrap">
      <div class="co-empty" id="coEmpty" hidden>
        <i class="fa-solid fa-bag-shopping" aria-hidden="true"></i>
        <h2 style="font-family:'Cinzel',serif;color:#fff">Your cart is empty</h2>
        <p>Browse the library and add something first.</p>
        <p style="margin-top:18px"><a class="btn btn-primary" href="/store"><i class="fa-solid fa-store"></i> Go to the store</a></p>
      </div>

      <form class="co-grid" id="coGrid" novalidate hidden>
        <div>
          <div class="co-card">
            <h2>Your details</h2>
            <div class="co-row">
              <label class="co-field"><span>Full name</span><input id="coName" type="text" autocomplete="name" required placeholder="Ada Bello"></label>
              <label class="co-field"><span>Email</span><input id="coEmail" type="email" autocomplete="email" required placeholder="you@example.com"></label>
            </div>
            <div class="co-row">
              <label class="co-field"><span>Phone <span style="color:#7a6a52">(optional)</span></span><input id="coPhone" type="tel" autocomplete="tel" placeholder="+234…"></label>
              <label class="co-field"><span>Country</span>
                <select id="coCountry" autocomplete="country">
                  <option value="NG">Nigeria</option><option value="GB">United Kingdom</option><option value="US">United States</option>
                  <option value="CA">Canada</option><option value="GH">Ghana</option><option value="KE">Kenya</option>
                  <option value="ZA">South Africa</option><option value="AU">Australia</option><option value="">Elsewhere</option>
                </select>
              </label>
            </div>
            <label class="co-field" id="coShipWrap" hidden><span>Shipping address <span style="color:#7a6a52">(required for physical items)</span></span><textarea id="coShip" rows="3" autocomplete="shipping street-address" placeholder="Street, City, State, Country, Postcode"></textarea></label>
          </div>

          <div class="co-card" style="margin-top:20px">
            <h2>Payment method</h2>
            <div class="co-pay">
              <label><input type="radio" name="prov" value="paystack" checked> <i class="fa-solid fa-credit-card"></i> Card (Paystack)</label>
              <label><input type="radio" name="prov" value="flutterwave"> <i class="fa-solid fa-bolt"></i> Flutterwave</label>
              <label><input type="radio" name="prov" value="bank"> <i class="fa-solid fa-building-columns"></i> Bank transfer</label>
            </div>
            <div class="co-note" id="coStatus" role="status" hidden></div>
          </div>
        </div>

        <div class="co-card" id="coSummaryCard">
          <h2>Order summary <a class="co-edit" href="/store" style="float:right;font-weight:600">Edit cart →</a></h2>
          <div id="coLines"></div>
          <div class="co-tot"><span>Subtotal</span><span id="coSub">$0</span></div>
          <div class="co-tot" id="coShipRow" hidden><span>Shipping</span><span id="coShipAmt">—</span></div>
          <div class="co-tot" id="coTaxRow" hidden><span>Tax</span><span id="coTaxAmt">—</span></div>
          <div class="co-tot grand"><span>Total</span><b id="coGrand">$0</b></div>
          <button type="submit" class="co-place" id="coPlace"><i class="fa-solid fa-lock"></i> Place order &amp; pay</button>
          <p class="co-trust"><i class="fa-solid fa-shield-halved"></i> Secure payment · region-aware delivery · digital items by email instantly. Final shipping &amp; tax are confirmed before you pay.</p>
        </div>
      </form>

      <div class="co-card co-empty" id="coDone" hidden style="max-width:560px;margin:0 auto">
        <i class="fa-solid fa-circle-check" style="color:#34D399" aria-hidden="true"></i>
        <h2 style="font-family:'Cinzel',serif;color:#fff">Order confirmed</h2>
        <p id="coDoneMsg">Check your email — your receipt and any download links are on the way.</p>
        <div id="coDoneRef" style="font-family:monospace;color:#FBBF24;margin:14px 0"></div>
        <p style="margin-top:8px"><a class="btn btn-primary" href="/store"><i class="fa-solid fa-store"></i> Browse more</a></p>
      </div>
    </div>
  </section>
</main>

<?php include __DIR__ . '/partials/footer.html'; ?>
<script src="assets/js/config.js?v=20260615f"></script>
<script src="assets/js/main.js?v=20260616a"></script>
<script>
(function () {
  var CART_KEY = 'mam.store.cart';
  function loadCart() { try { return JSON.parse(localStorage.getItem(CART_KEY)) || []; } catch (e) { return []; } }
  function clearCart() { try { localStorage.removeItem(CART_KEY); } catch (e) {} }
  var cart = loadCart();
  var $ = function (s) { return document.querySelector(s); };
  function esc(s) { return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function money(n) { return '$' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function hasPhysical() { return cart.some(function (l) { return l.goods_type === 'physical'; }); }

  var grid = $('#coGrid'), empty = $('#coEmpty'), done = $('#coDone'), statusEl = $('#coStatus'), placeBtn = $('#coPlace');

  function status(msg, kind) {
    statusEl.className = 'co-note ' + (kind === 'err' ? 'err' : 'ok');
    statusEl.innerHTML = msg; statusEl.hidden = false;
    statusEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  function renderSummary() {
    if (!cart.length) { empty.hidden = false; grid.hidden = true; return; }
    empty.hidden = true; grid.hidden = false;
    $('#coLines').innerHTML = cart.map(function (l) {
      var line = (Number(l.price_usd) || 0) * (l.qty || 1);
      return '<div class="co-line"><div class="co-line-thumb">' + (l.image ? '<img src="' + esc(l.image) + '" alt="">' : '<i class="fa-solid fa-music"></i>') + '</div>'
        + '<div class="co-line-main"><div class="co-line-name">' + esc(l.name) + '</div>'
        + '<div class="co-line-meta">Qty ' + (l.qty || 1) + ' · ' + money(l.price_usd) + (l.goods_type === 'physical' ? ' · ships' : ' · digital') + '</div></div>'
        + '<div class="co-line-amt">' + money(line) + '</div></div>';
    }).join('');
    var sub = cart.reduce(function (a, l) { return a + (Number(l.price_usd) || 0) * (l.qty || 1); }, 0);
    $('#coSub').textContent = money(sub);
    $('#coGrand').textContent = money(sub); // server confirms shipping/tax at order creation
    $('#coShipWrap').hidden = !hasPhysical();
  }
  renderSummary();

  function loadScript(src) {
    return new Promise(function (res, rej) {
      if (document.querySelector('script[src="' + src + '"]')) return res();
      var s = document.createElement('script'); s.src = src; s.async = true;
      s.onload = res; s.onerror = function () { rej(new Error('script')); };
      document.head.appendChild(s);
    });
  }
  function provider() { var r = document.querySelector('input[name=prov]:checked'); return r ? r.value : 'paystack'; }
  function busy(b, label) { placeBtn.disabled = b; placeBtn.innerHTML = b ? '<span class="spinner"></span> ' + (label || 'Working…') : '<i class="fa-solid fa-lock"></i> Place order &amp; pay'; }

  grid.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!cart.length) { status('Your cart is empty.', 'err'); return; }
    var name = $('#coName').value.trim(), email = $('#coEmail').value.trim();
    if (!name || !email) { status('Please add your name and email.', 'err'); return; }
    if (hasPhysical() && !$('#coShip').value.trim()) { status('A shipping address is required for the physical items in your cart.', 'err'); return; }
    if (!window.MAM_API) { status('We couldn’t reach our secure server. Please refresh and try again.', 'err'); return; }

    var payload = {
      items: cart.map(function (l) { return { sku: l.sku, qty: l.qty || 1 }; }),
      name: name, email: email, phone: $('#coPhone').value.trim(),
      country: $('#coCountry').value, shipping_address: $('#coShip').value.trim()
    };
    var prov = provider();
    busy(true, 'Creating your order…');
    window.MAM_API.storeCheckout(payload)
      .then(function (order) {
        // Show the server-authoritative totals before payment.
        if (order.shipping_usd > 0) { $('#coShipRow').hidden = false; $('#coShipAmt').textContent = money(order.shipping_usd); }
        if (order.tax_usd > 0) { $('#coTaxRow').hidden = false; $('#coTaxAmt').textContent = money(order.tax_usd); }
        $('#coSub').textContent = money(order.subtotal_usd);
        $('#coGrand').textContent = money(order.total_usd);
        return window.MAM_API.initializePayment({ reference: order.reference, provider: prov, purpose: 'store' })
          .then(function (init) {
            if (prov === 'paystack') return openPaystack(init, order.reference, email);
            if (prov === 'flutterwave') return openFlutterwave(init, order.reference);
            if (prov === 'bank') return showBank(init);
            throw new Error('unsupported');
          });
      })
      .catch(function (err) {
        busy(false);
        try { console.error('[MAM_CHECKOUT]', { status: err && err.status, body: err && err.body, message: err && err.message }); } catch (e) {}
        var code = (err && err.body && (err.body.message || err.body.error)) || (err && err.message) || 'unknown error';
        status('Could not start payment: ' + esc(code) + '. Please try again, or email academy@mamcentre.org.', 'err');
      });
  });

  function afterCharge(provider, reference, txId) {
    status('Verifying your payment…', 'ok'); busy(true, 'Verifying…');
    window.MAM_API.verifyPayment({ provider: provider, reference: reference, transaction_id: txId || 0 })
      .then(function () { showDone(reference); })
      .catch(function (err) {
        busy(false);
        var s = err && err.status;
        if (s === 402 || (err && err.body && err.body.error === 'verification_failed')) status('We couldn’t confirm your payment amount. If you were charged, email academy@mamcentre.org with order ' + esc(reference) + '.', 'err');
        else status('Payment received — our system will confirm it shortly and email your receipt.', 'ok');
      });
  }
  function openPaystack(init, ref, email) {
    return loadScript('https://js.paystack.co/v1/inline.js').then(function () {
      busy(false);
      window.PaystackPop.setup({
        key: init.public_key, email: email, amount: init.amount_ngn * 100, currency: 'NGN', ref: init.reference,
        metadata: { reference: init.reference, purpose: 'store' },
        callback: function (r) { afterCharge('paystack', r.reference || init.reference); },
        onClose: function () { busy(false); status('Checkout closed — your cart is saved whenever you’re ready.', 'ok'); }
      }).openIframe();
    });
  }
  function openFlutterwave(init, ref) {
    return loadScript('https://checkout.flutterwave.com/v3.js').then(function () {
      busy(false);
      var p = init.payload || {}; p.public_key = init.public_key; p.payment_options = 'card, banktransfer, ussd';
      p.callback = function (r) { afterCharge('flutterwave', r.tx_ref || init.reference, r.transaction_id); };
      p.onclose = function () { busy(false); };
      window.FlutterwaveCheckout(p);
    });
  }
  function showBank(init) {
    busy(false);
    var b = init.bank || {};
    var lines = b.account_number ? ('<br><strong>' + esc(b.bank) + '</strong> · ' + esc(b.account_name) + ' · <strong>' + esc(b.account_number) + '</strong>') : '';
    status((esc(init.instructions || ('Send ' + money(init.amount_usd) + ' and email proof to academy@mamcentre.org with order ' + init.reference + '.'))) + lines, 'ok');
  }
  function showDone(ref) {
    var digitalOnly = !hasPhysical();
    clearCart();
    grid.hidden = true; empty.hidden = true; done.hidden = false;
    $('#coDoneRef').textContent = 'Order ' + ref;
    $('#coDoneMsg').textContent = digitalOnly
      ? 'Your download links are on the way — check your inbox in the next minute.'
      : 'Check your email for your receipt. We’ll ship from Lagos within 2 business days.';
    done.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
})();
</script>
</body>
</html>
