/* /book — the booking-fee gate and the calendar (book.php). Moved out of the page at
   the 2026-11 redesign with the same endpoints: GET /api/booking/pricing (display),
   GET /api/booking/status?ref=, MAM_API.initializePayment({reference, provider,
   purpose:'booking_fee'}), Paystack inline, MAM_API.verifyPayment. One change: an
   `already_valid` answer refreshes into the calendar like `already_paid`. Needs
   config.js (MAM_API, MAM_CONFIG.booking, MAM_CCY), loaded before it. */
(function(){
  'use strict';
  var qs = new URLSearchParams(location.search);
  var ref = (qs.get('ref') || '').trim();
  var $ = function(s){return document.querySelector(s);};
  var loading=$('#bkLoading'), noRef=$('#bkNoRef'), gate=$('#bkGate'), unlocked=$('#bkUnlocked');
  var msg=$('#gateMsg');
  function show(el){ [loading,noRef,gate,unlocked].forEach(function(e){ e.hidden = (e !== el); }); }
  function status(text, kind){ msg.textContent = text; msg.className = 'gate-msg show ' + (kind||''); }

  if (!ref) { show(noRef); return; }

  // Pull the regional booking-fee tier so we render the right currency/amount.
  // Server is authoritative at charge time; this is display-only.
  var feeTier = null;
  function paintFee() {
    if (!feeTier) return;
    var price = document.getElementById('gatePrice');
    if (price) {
      // Symbol only — the amount is the server's charge-currency figure, never re-converted (display == charge).
      var sym = (window.MAM_CCY ? MAM_CCY.symbol(feeTier.currency) : (feeTier.currency + ' ')) || '';
      var amount = Number(feeTier.amount).toLocaleString();
      price.innerHTML = '<span class="currency">' + sym + '</span><b>' + amount + '</b><span class="unit">' + feeTier.currency + ' &middot; one-time</span>';
    }
    var lbl = document.getElementById('payPaystackLabel');
    if (lbl) lbl.textContent = 'Pay ' + feeTier.label + ' with Card / Bank';
  }
  fetch('/api/booking/pricing', { credentials: 'same-origin' }).then(function(r){ return r.json(); })
    .then(function(j){ if (j && j.ok) { feeTier = j; paintFee(); } }).catch(function(){});

  // Build the "covers" list from config (so admin can tweak the value statement).
  function paintCovers(){
    var covers = (window.MAM_CONFIG && window.MAM_CONFIG.booking && window.MAM_CONFIG.booking.covers) || [];
    var ul = $('#gateCovers'); ul.innerHTML = '';
    covers.forEach(function(t){
      var li = document.createElement('li');
      li.innerHTML = '<span aria-hidden="true">✓</span><span>' + t.replace(/</g,'&lt;') + '</span>';
      ul.appendChild(li);
    });
  }

  // ---- Poll the status endpoint to decide which UI to show ----
  function check(){
    fetch('/api/booking/status?ref=' + encodeURIComponent(ref), { credentials: 'same-origin', headers: {'Accept':'application/json'} })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j || !j.ok) throw new Error('bad_response');
        if (!j.found) { show(noRef); return; }
        // Unlock the calendar whenever a VALID MAM Auditer placement stands —
        // this application's own fee, a sibling application within the year, or
        // an enrolled-student exemption — not just this app's payment.
        if (j.paid || (j.auditer && j.auditer.valid)) { renderUnlocked(j); }
        else { renderGate(j); }
      })
      .catch(function(){
        // Fail-safe: show the gate; the user can still attempt payment, and the
        // backend will reject gracefully if the ref is wrong.
        renderGate({ name: '' });
      });
  }

  function renderGate(j){
    paintCovers();
    var sub = j.name
      ? "Hi " + j.name + " — one more step, then we'll see you on the calendar."
      : "One more step, then we'll see you on the calendar.";
    // If their previous Auditer lapsed (valid one year), explain the re-book.
    if (j.auditer && j.auditer.state === 'expired' && j.message) sub = j.message + ' ' + sub;
    $('#gateSub').textContent = sub;
    show(gate);
  }

  function renderUnlocked(j){
    $('#unlockedName').textContent = j.name || 'there';
    var url = j.embed_url || (window.MAM_CONFIG && window.MAM_CONFIG.booking && window.MAM_CONFIG.booking.embedUrl) || '';
    // Scheme-guard before the URL enters an iframe src / link href (match the
    // rest of the codebase's URL-sink convention). Only http(s) is a booking calendar.
    if (url && !/^https?:\/\//i.test(String(url))) url = '';
    var fr = $('#bookFrame'), fb = $('#bookFallback'), tab = $('#bookOpenTab');
    if (url) {
      fr.src = url; tab.href = url;
      // If Google's X-Frame-Options blocks the iframe load, swap to the fallback.
      var loaded = false;
      fr.addEventListener('load', function(){ loaded = true; });
      setTimeout(function(){ if (!loaded) { fr.style.display='none'; fb.hidden = false; } }, 4500);
    } else {
      fr.style.display = 'none';
      fb.hidden = false;
      fb.querySelector('p').textContent = 'The booking calendar is being set up. We will email you the link within the hour.';
      tab.style.display = 'none';
    }
    show(unlocked);
  }

  // ---- Payment buttons ----
  function pay(provider){
    var btn = (provider==='paystack' ? $('#payPaystack') : $('#payBank'));
    var orig = btn.innerHTML; btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Opening secure checkout…';
    status('', '');
    if (!window.MAM_API) { status("We couldn't reach our server. Please refresh and try again.", 'err'); btn.disabled=false; btn.innerHTML=orig; return; }
    window.MAM_API.initializePayment({ reference: ref, provider: provider, purpose: 'booking_fee' })
      .then(function(init){
        if (init.already_paid) { status('Already confirmed — refreshing…', 'ok'); setTimeout(check, 600); return; }
        if (init.already_valid) { status(init.message || 'Your MAM Auditer placement is still valid — refreshing…', 'ok'); setTimeout(check, 600); return; }
        if (provider === 'paystack') return openPaystack(init, btn, orig);
        if (provider === 'bank')     return showBank(init, btn, orig);
        throw new Error('unsupported');
      })
      .catch(function(err){
        btn.disabled = false; btn.innerHTML = orig;
        // Log the real cause so an operator can diagnose from the console.
        try { console.error('[MAM_BOOK] checkout failed', { status: err && err.status, body: err && err.body, message: err && err.message }); } catch (e) {}
        var code = (err && err.body && err.body.error) || (err && err.message) || '';
        if (err && (err.status === 404 || code === 'application_not_found')) {
          // The reference isn't on file — retrying will never help. Send them
          // back to the application instead of looping on a transient message.
          status("We couldn't find an application for reference " + ref + ". If you just applied, use the link in your confirmation email — or re-apply at /apply. Need a hand? Email academy@mamcentre.org with that reference.", 'err');
        } else if (err && (err.apiDown || code === 'api_unavailable' || code === 'Failed to fetch')) {
          status("We couldn't reach our secure server just now. Please check your connection and try again in a moment.", 'err');
        } else if (err && err.status === 429) {
          status("That's a few too many attempts in a row. Please wait a minute and try again.", 'err');
        } else {
          status("Couldn't start checkout (" + (code || 'unknown error') + "). Please try again, or email academy@mamcentre.org with reference " + ref + ".", 'err');
        }
      });
  }

  function loadScript(src){
    return new Promise(function(res, rej){
      if (document.querySelector('script[src="'+src+'"]')) return res();
      var s = document.createElement('script'); s.src = src; s.async = true;
      s.onload = res; s.onerror = function(){ rej(new Error('script')); };
      document.head.appendChild(s);
    });
  }

  function openPaystack(init, btn, orig){
    return loadScript('https://js.paystack.co/v1/inline.js').then(function(){
      btn.disabled = false; btn.innerHTML = orig;
      // Disclose the exact Naira settlement — the regional fee tier may display in
      // another currency, but the card gateway always charges the server's NGN figure.
      if (window.MAM_CCY) { var note = MAM_CCY.settlementText(init); if (note) status(note, 'ok'); }
      var handler = window.PaystackPop.setup({
        key: init.public_key,
        email: 'applicant+' + ref + '@mamcentre.org', // server already has the real one
        amount: init.amount_ngn * 100,
        currency: 'NGN',
        ref: init.reference,
        metadata: { reference: init.reference, purpose: 'booking_fee' },
        callback: function(response){
          status('Verifying your payment…');
          window.MAM_API.verifyPayment({ provider: 'paystack', reference: response.reference || init.reference })
            .then(function(){ status('Payment confirmed! Loading your booking calendar…', 'ok'); setTimeout(check, 800); })
            .catch(function(){ status('Payment received, but verification is still pending. Refresh in a moment.', 'err'); });
        },
        onClose: function(){ /* user closed popup — no state change */ }
      });
      handler.openIframe();
    });
  }

  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  function showBank(init, btn, orig){
    btn.disabled = false; btn.innerHTML = orig;
    var b = init.bank || {};
    var base = init.instructions || ('Send ₦' + (init.amount_ngn || '').toLocaleString() + ' and email proof to academy@mamcentre.org with reference ' + init.reference + '.');
    // Render as (escaped) HTML so the bank line shows formatted — the shared
    // status() uses textContent, which would print the <br>/<strong> literally.
    var html = esc(base);
    if (b.account_number) html += '<br><strong>' + esc(b.bank) + '</strong> · ' + esc(b.account_name) + ' · <strong>' + esc(b.account_number) + '</strong>';
    msg.innerHTML = html;
    msg.className = 'gate-msg show ok';
  }

  document.getElementById('payPaystack').addEventListener('click', function(){ pay('paystack'); });
  document.getElementById('payBank').addEventListener('click', function(){ pay('bank'); });

  check();
})();
