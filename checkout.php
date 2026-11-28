<?php
/**
 * Checkout — on the 2026-11 redesign shell (partials/site/), from the handoff's own
 * parts (the Apply flow's fields and summary, the About page's serif headings).
 *
 * The flow is the previous page's, unchanged: the cart is the store's localStorage
 * "mam.store.cart"; POST /api/store/checkout with {items:[{sku,qty}], name, email,
 * phone, country, shipping_address} creates the order and the SERVER prices it; then
 * POST /api/payment/initialize {reference, provider, purpose:'store'} and the
 * gateway (Paystack inline, Flutterwave, or bank details), then /api/payment/verify.
 * config.js is loaded for MAM_API (CSRF), MAM_CCY and the settlement disclosure; the
 * footer's currency switch is wired by this page for the reason store.php gives.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

site_head([
    'title' => 'Checkout — MAM Academy Store',
    'description' => 'Securely check out from the MAM Academy Store — sheet music, recordings, learning bundles and merch. Region-aware delivery, card and bank transfer.',
    'path' => '/checkout',
    'jsonld' => '<meta name="robots" content="noindex">',
]);
site_header('inner');
ob_start();
?>
<style>
.co-wrap{max-width:1200px;margin:0 auto;padding:0 clamp(20px,4vw,40px) clamp(64px,8vw,120px);box-sizing:border-box}
.co-grid{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:24px;align-items:start}
@media (max-width:860px){.co-grid{grid-template-columns:minmax(0,1fr)}}
.co-grid[hidden],.co-empty[hidden]{display:none}
.co-card{background:#FFFFFF;border:1px solid #E6DED2;border-radius:20px;padding:clamp(20px,3vw,32px)}
.co-card + .co-card{margin-top:20px}
.co-card h2{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin:0 0 16px;font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:32px;line-height:1.1;color:#211A13}
.co-field{display:block;margin:0 0 16px}
.co-field > span{display:block;margin-bottom:8px;font-size:15px;font-weight:600;color:#211A13}
.co-field .opt{font-weight:400;color:#574B40}
.co-field input,.co-field select,.co-field textarea{box-sizing:border-box;width:100%;min-height:52px;padding:12px 16px;border-radius:12px;border:1px solid #CFC3B3;background:#FFFFFF;font:inherit;font-size:16px;color:#211A13}
.co-field textarea{min-height:96px;resize:vertical}
.co-field input:focus,.co-field select:focus,.co-field textarea:focus{outline:2px solid #8A5208;outline-offset:2px;border-color:#8A5208}
.co-row{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}
@media (max-width:520px){.co-row{grid-template-columns:1fr}}
.co-pay{display:grid;gap:10px}
.co-pay label{display:flex;align-items:center;gap:12px;min-height:56px;padding:0 16px;border-radius:12px;border:1px solid #CFC3B3;background:#FFFFFF;cursor:pointer;font-size:16px;font-weight:600;color:#211A13}
.co-pay label:has(input:checked){border-color:#211A13;background:#F3EEE6}
.co-pay input{accent-color:#211A13;width:18px;height:18px;margin:0}
.co-pay small{margin-left:auto;font-size:14px;font-weight:400;color:#574B40}
.co-line{display:flex;gap:14px;padding:14px 0;border-bottom:1px solid #E6DED2}
.co-line-thumb{flex:none;width:56px;height:56px;border-radius:12px;overflow:hidden;background:linear-gradient(135deg,#E8DECF,#CDB89C);display:flex;align-items:center;justify-content:center;color:#574B40}
.co-line-thumb img{width:100%;height:100%;object-fit:cover}
.co-line-main{flex:1;min-width:0}
.co-line-name{font-weight:600;font-size:16px;line-height:1.3;color:#211A13}
.co-line-meta{font-size:14px;color:#574B40}
.co-line-amt{font-weight:600;font-size:16px;color:#211A13;font-variant-numeric:tabular-nums;white-space:nowrap}
.co-tot{display:flex;justify-content:space-between;padding:6px 0;font-size:16px;color:#3E342B;font-variant-numeric:tabular-nums}
.co-tot[hidden]{display:none}
.co-tot.grand{margin-top:8px;padding-top:14px;border-top:1px solid #E6DED2;font-size:19px;font-weight:600;color:#211A13}
.co-place{display:inline-flex;align-items:center;justify-content:center;gap:10px;width:100%;min-height:56px;margin-top:16px;border:0;border-radius:999px;background:#F2A93B;color:#211A13;font:inherit;font-size:17px;font-weight:600;cursor:pointer}
.co-place[disabled]{opacity:.6;cursor:default}
.co-trust{margin:12px 0 0;font-size:14px;line-height:1.6;color:#574B40;text-align:center}
.co-note{margin-top:16px;padding:12px 16px;border-radius:12px;font-size:15px;line-height:1.55}
.co-note[hidden]{display:none}
.co-note.err{background:#FFFFFF;border:2px solid #B0361C;color:#B0361C}
.co-note.ok{background:#F3EEE6;border:1px solid #E3DACD;color:#211A13}
.co-empty{text-align:center;padding:48px 20px;color:#3E342B}
.co-empty h2{justify-content:center}
.co-empty .co-ic{display:block;margin:0 auto 12px;color:#C27A12}
.co-btn{display:inline-flex;align-items:center;min-height:48px;padding:0 24px;border-radius:999px;background:#211A13;color:#FAF8F4;font-weight:600;text-decoration:none}
.co-edit{font-family:Inter,system-ui,sans-serif;font-size:15px;font-weight:600;color:#8A5208}
#coDoneRef{font-family:ui-monospace,Menlo,monospace;font-size:15px;color:#8A5208;margin:14px 0}
.spinner{display:inline-block;width:16px;height:16px;border:2px solid rgba(33,26,19,.3);border-top-color:#211A13;border-radius:50%;animation:cospin .7s linear infinite}
@keyframes cospin{to{transform:rotate(360deg)}}
</style>
<main id="main">
<?php site_doc_open('Checkout', 'Secure checkout', 'Review your order, add your details and pay securely. Digital items arrive by email.'); ?>
  <section class="co-wrap" aria-label="Checkout">
      <div class="co-card co-empty" id="coEmpty" hidden>
        <svg class="co-ic" aria-hidden="true" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg>
        <h2>Your cart is empty</h2>
        <p>Browse the library and add something first.</p>
        <p style="margin-top: 20px"><a class="co-btn" href="/store">Go to the store</a></p>
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
              <label class="co-field"><span>Phone <span class="opt">(optional)</span></span><input id="coPhone" type="tel" autocomplete="tel" placeholder="+234…"></label>
              <label class="co-field"><span>Country</span>
                <select id="coCountry" autocomplete="country">
                  <option value="NG">Nigeria</option><option value="GB">United Kingdom</option><option value="US">United States</option>
                  <option value="CA">Canada</option><option value="GH">Ghana</option><option value="KE">Kenya</option>
                  <option value="ZA">South Africa</option><option value="AU">Australia</option><option value="">Elsewhere</option>
                </select>
              </label>
            </div>
            <label class="co-field" id="coShipWrap" hidden><span>Shipping address <span class="opt">(required for physical items)</span></span><textarea id="coShip" rows="3" autocomplete="shipping street-address" placeholder="Street, City, State, Country, Postcode"></textarea></label>
          </div>

          <div class="co-card">
            <h2>Payment method</h2>
            <div class="co-pay">
              <label><input type="radio" name="prov" value="paystack" checked> Card <small>Paystack</small></label>
              <label><input type="radio" name="prov" value="flutterwave"> Card or mobile money <small>Flutterwave</small></label>
              <label><input type="radio" name="prov" value="bank"> Bank transfer</label>
            </div>
            <div class="co-note" id="coStatus" role="status" hidden></div>
          </div>
        </div>

        <div class="co-card" id="coSummaryCard">
          <h2>Order summary <a class="co-edit" href="/store">Edit cart</a></h2>
          <div id="coLines"></div>
          <div class="co-tot"><span>Subtotal</span><span id="coSub">—</span></div>
          <div class="co-tot" id="coShipRow" hidden><span>Shipping</span><span id="coShipAmt">—</span></div>
          <div class="co-tot" id="coTaxRow" hidden><span>Tax</span><span id="coTaxAmt">—</span></div>
          <div class="co-tot grand"><span>Total</span><b id="coGrand">—</b></div>
          <button type="submit" class="co-place" id="coPlace">Place order &amp; pay</button>
          <p class="co-trust">Secure payment · region-aware delivery · digital items by email. Final shipping and tax are confirmed before you pay.</p>
        </div>
      </form>

      <div class="co-card co-empty" id="coDone" hidden style="max-width: 560px; margin: 0 auto">
        <svg class="co-ic" aria-hidden="true" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/></svg>
        <h2>Order confirmed</h2>
        <p id="coDoneMsg">Check your email — your receipt and any download links are on the way.</p>
        <div id="coDoneRef"></div>
        <p style="margin-top: 8px"><a class="co-btn" href="/store">Browse more</a></p>
      </div>
  </section>
</main>

<?php
echo (string) ob_get_clean();
// config.js before site.js (deferred): MAM_API, MAM_CCY and MAM_CONFIG drive the checkout.
echo '<script src="/assets/js/config.js?v=20261128v"></script>' . "\n";
site_footer_chrome();
?>
<script>
(function () {
  var CART_KEY = 'mam.store.cart';
  var NOTE_SVG = '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>';
  function loadCart() { try { return JSON.parse(localStorage.getItem(CART_KEY)) || []; } catch (e) { return []; } }
  function clearCart() { try { localStorage.removeItem(CART_KEY); } catch (e) {} }
  var cart = loadCart();
  var $ = function (s) { return document.querySelector(s); };
  function esc(s) { return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function money(n) {
    if (window.MAM_CCY) return MAM_CCY.format(Number(n) || 0, MAM_CCY.detect());
    return '$' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
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
      return '<div class="co-line"><div class="co-line-thumb">' + (l.image ? '<img src="' + esc(l.image) + '" alt="">' : NOTE_SVG) + '</div>'
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

  // The footer's currency switch (see store.php): config.js is loaded for MAM_CCY,
  // so site.js leaves the switch alone and this page wires it to "mam.currency".
  var ccyBtns = Array.prototype.slice.call(document.querySelectorAll('.cur-sw [data-currency]'));
  function curNow() { return (window.MAM_CCY && MAM_CCY.detect()) || 'USD'; }
  function paintCcy() { var c = curNow(); ccyBtns.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-currency') === c ? 'true' : 'false'); }); }
  ccyBtns.forEach(function (b) {
    if (window.MAM_CCY && !MAM_CCY.known(b.getAttribute('data-currency'))) { b.hidden = true; return; }
    b.addEventListener('click', function () {
      try { localStorage.setItem('mam.currency', b.getAttribute('data-currency')); } catch (e) {}
      paintCcy();
      try { document.dispatchEvent(new CustomEvent('mam:currency', { detail: { currency: b.getAttribute('data-currency') } })); } catch (e) {}
    });
  });
  paintCcy();
  // Re-price the summary while nothing has been ordered yet (after an order the
  // server's own totals are on screen and stay as they are).
  var ordered = false;
  document.addEventListener('mam:currency', function () { paintCcy(); if (!ordered) renderSummary(); });
  document.addEventListener('mam:config', function () { if (!ordered) renderSummary(); });

  function loadScript(src) {
    return new Promise(function (res, rej) {
      if (document.querySelector('script[src="' + src + '"]')) return res();
      var s = document.createElement('script'); s.src = src; s.async = true;
      s.onload = res; s.onerror = function () { rej(new Error('script')); };
      document.head.appendChild(s);
    });
  }
  function provider() { var r = document.querySelector('input[name=prov]:checked'); return r ? r.value : 'paystack'; }
  function busy(b, label) { placeBtn.disabled = b; placeBtn.innerHTML = b ? '<span class="spinner"></span> ' + (label || 'Working…') : 'Place order &amp; pay'; }

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
        ordered = true;
        // Show the server-authoritative totals before payment.
        if (order.shipping_usd > 0) { $('#coShipRow').hidden = false; $('#coShipAmt').textContent = money(order.shipping_usd); }
        if (order.tax_usd > 0) { $('#coTaxRow').hidden = false; $('#coTaxAmt').textContent = money(order.tax_usd); }
        $('#coSub').textContent = money(order.subtotal_usd);
        $('#coGrand').textContent = money(order.total_usd);
        return window.MAM_API.initializePayment({ reference: order.reference, provider: prov, purpose: 'store' })
          .then(function (init) {
            // Disclose the exact NGN settlement before handing off to the card gateway,
            // so the local-currency cart estimate reconciles with what's actually charged.
            if (window.MAM_CCY && (prov === 'paystack' || prov === 'flutterwave')) {
              var note = MAM_CCY.settlementNote(init); if (note) status(note, 'ok');
            }
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
        status('Could not start payment: ' + esc(String(code).replace(/\.+$/, '')) + '. Please try again, or email academy@mamcentre.org.', 'err');
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
    // Bank transfers settle in NGN — quote the Naira figure, never the local display amount.
    var amt = (window.MAM_CCY && init.amount_ngn) ? MAM_CCY.ngn(init.amount_ngn) : money(init.amount_usd);
    status((esc(init.instructions || ('Send ' + amt + ' and email proof to academy@mamcentre.org with order ' + init.reference + '.'))) + lines, 'ok');
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
