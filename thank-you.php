<?php
/**
 * After payment — on the 2026-11 redesign shell. The reference shown is the
 * one in the return URL (?ref=, ?reference= or ?tx_ref=, whichever the gateway
 * sends) or, failing that, the last application saved in this browser
 * (localStorage "mam.apply.lastSubmission"), exactly as before.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$tyCard = 'padding: 24px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2; display: flex; flex-direction: column; gap: 8px; text-align: left';

site_head([
    'title' => 'Thank you — MAM Academy',
    'description' => 'Your transaction has been received.',
    'path' => '/thank-you',
    'jsonld' => '<meta name="robots" content="noindex, follow">',
]);
site_header('inner');
?>
<main id="main" style="padding: clamp(56px, 7vw, 112px) clamp(20px, 4vw, 40px); box-sizing: border-box">
  <div style="max-width: 680px; margin: 0 auto; display: flex; flex-direction: column; gap: 20px; text-align: center; align-items: center">
    <h1 class="fade-in" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(46px, 5.6vw, 84px); line-height: 1.02">Thank <em style="font-style: italic; color: #8A5208">you</em></h1>
    <p class="fade-in" style="animation-delay: .1s; margin: 0; font-size: 18px; line-height: 1.7; color: #3E342B">Your transaction has been received. Our team will contact you within <strong>24 hours</strong> to confirm your enrollment and schedule your MAM Auditer assessment.</p>
    <p class="fade-in" style="animation-delay: .15s; margin: 0; font-size: 17px; color: #3E342B">While you wait: <a href="/auditer#checklist" class="ul" style="color: #8A5208">prepare with the Auditer Applicant Checklist →</a></p>
    <p id="refDisplay" class="fade-in" style="animation-delay: .2s; margin: 0; padding: 10px 18px; border-radius: 999px; background: #F3EEE6; font-size: 15px; font-weight: 600; color: #211A13" hidden></p>
    <p id="refMissing" style="margin: 0; font-size: 15px; color: #574B40">Your reference is in the confirmation email we sent you.</p>
    <p style="margin: 0; font-size: 15px; line-height: 1.6; color: #574B40">A copy of your application is also stored in your browser. We’ll email a full confirmation to the address you provided.</p>

    <div style="width: 100%; display: flex; flex-direction: column; gap: 16px; margin-top: 8px">
      <div style="<?= $tyCard ?>">
        <h2 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 24px">Check your email</h2>
        <p style="margin: 0; font-size: 16px; line-height: 1.65; color: #3E342B">Once your payment clears we’ll email you student portal credentials — sign in to track your application, classes &amp; MAM Auditer report.</p>
      </div>
      <div style="<?= $tyCard ?>">
        <h2 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 24px">MAM Scholars Initiative</h2>
        <p style="margin: 0; font-size: 16px; line-height: 1.65; color: #3E342B">Would you like to be considered for a scholarship? It’s a separate, holistic review — earned, values-driven, and community-minded.</p>
        <a href="/scholarship" class="ul" style="display: inline-flex; align-items: center; min-height: 44px; font-weight: 600; color: #8A5208; text-decoration: none">Consider me for a scholarship →</a>
      </div>
    </div>

    <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-top: 8px">
      <a href="/portal/login" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 52px; padding: 0 26px; border-radius: 999px; background: #211A13; color: #FAF8F4; font-weight: 600; text-decoration: none">Open student portal <span aria-hidden="true">→</span></a>
      <a href="/" class="btn" style="display: inline-flex; align-items: center; min-height: 52px; padding: 0 26px; border-radius: 999px; border: 1px solid #CFC3B3; color: #211A13; font-weight: 600; text-decoration: none">Back to home</a>
    </div>
  </div>
</main>
<script>
(function () {
  try {
    var qs = new URLSearchParams(location.search);
    var ref = qs.get('ref') || qs.get('reference') || qs.get('tx_ref');
    if (!ref) {
      var last = JSON.parse(localStorage.getItem('mam.apply.lastSubmission') || 'null');
      if (last && last.reference) ref = last.reference;
    }
    /* Only a real reference is shown. A placeholder "pending" read as if the
       application had not been recorded (UX quick win 25). */
    if (ref) {
      var el = document.getElementById('refDisplay');
      el.textContent = 'Reference: ' + ref; el.hidden = false;
      document.getElementById('refMissing').hidden = true;
    }
  } catch (e) { /* storage blocked: the email still carries the reference */ }
})();
</script>
<?php
site_footer();
