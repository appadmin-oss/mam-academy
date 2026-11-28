<?php
/**
 * /tuition — pay tuition with the application reference and email. On the 2026-11
 * redesign's shell; the behaviour (lookup, checkout, verify) is assets/js/tuition.js,
 * moved out of this page unchanged. config.js is loaded for MAM_API and MAM_CCY, so
 * the footer's currency switch is driven by site-ccy-bridge.js.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$tuStyle = <<<'CSS'
<meta name="robots" content="noindex, follow">
<style>
.tu-card{background:#FFFFFF;border:1px solid #E6DED2;border-radius:20px;padding:clamp(24px,3vw,32px);box-sizing:border-box}
.tu-card h2{margin:0 0 8px;font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:34px;line-height:1.1;color:#211A13}
.tu-field{display:block;margin:16px 0}
.tu-field span{display:block;font-size:14px;font-weight:600;color:#3E342B;margin-bottom:8px}
.tu-field input{width:100%;box-sizing:border-box;min-height:48px;padding:0 16px;border-radius:12px;border:1px solid #CFC3B3;background:#FFFFFF;color:#211A13;font:inherit;font-size:16px}
.tu-field input:focus{outline:3px solid #2563EB;outline-offset:1px;border-color:#211A13}
.tu-btn{width:100%;min-height:52px;margin-top:8px;border:0;border-radius:999px;background:#F2A93B;color:#211A13;font:inherit;font-size:16px;font-weight:600;cursor:pointer}
.tu-btn[disabled]{opacity:.6;cursor:default}
.tu-amount{text-align:center;margin:16px 0 24px}
.tu-amount .v{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:56px;line-height:1;color:#211A13;font-variant-numeric:tabular-nums}
.tu-amount .c{margin-top:8px;font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#574B40}
.tu-pay{display:grid;gap:12px}
.tu-pay button{min-height:52px;border-radius:12px;border:1px solid #CFC3B3;background:#FFFFFF;color:#211A13;font:inherit;font-size:16px;font-weight:600;cursor:pointer}
.tu-pay button.primary{background:#211A13;border-color:#211A13;color:#FAF8F4}
.tu-pay button[disabled]{opacity:.6;cursor:default}
.tu-note{margin-top:16px;padding:12px 16px;border-radius:12px;font-size:15px;line-height:1.55}
.tu-note.err{background:#FFFFFF;border:2px solid #B0361C;color:#B0361C}
.tu-note.ok{background:#F3EEE6;border:1px solid #E6DED2;color:#211A13}
.tu-muted{color:#574B40;font-size:15px;line-height:1.6}
.tu-muted a{color:#8A5208}
</style>
CSS;

site_head([
    'title' => 'Pay tuition — MAM Academy',
    'description' => 'Securely pay your MAM Academy tuition with your application reference and email.',
    'path' => '/tuition',
    'jsonld' => $tuStyle,
]);
site_header('inner');
?>
<main id="main">
  <section aria-labelledby="page-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px) clamp(32px, 4vw, 56px); box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <nav aria-label="Breadcrumb" class="fade-in" style="font-size: 14px; color: #574B40; display: flex; gap: 8px"><a href="/" class="ul" style="color: #574B40; text-decoration: none">Home</a><span aria-hidden="true">/</span><span aria-current="page">Pay tuition</span></nav>
    <h1 id="page-h" class="fade-in" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(46px, 5.6vw, 84px); line-height: 1.02; letter-spacing: -.01em">Pay your tuition</h1>
    <p class="fade-in" style="margin: 0; max-width: 640px; font-size: 19px; line-height: 1.7; color: #3E342B">Enter your reference and the email you applied with to view and securely pay your tuition.</p>
  </section>

  <section style="max-width: 1200px; margin: 0 auto; padding: 0 clamp(20px, 4vw, 40px) clamp(64px, 8vw, 120px); box-sizing: border-box">
    <div style="max-width: 620px; display: flex; flex-direction: column; gap: 16px">
      <div class="tu-card" id="gate">
        <h2>Confirm it’s you</h2>
        <p class="tu-muted" style="margin: 0">Your reference looks like <strong>MAM-XXXX-XXXX</strong>; it’s in your confirmation email.</p>
        <form id="tuForm" novalidate>
          <label class="tu-field"><span>Application reference</span><input id="tuRef" type="text" autocomplete="off" placeholder="MAM-…" required></label>
          <label class="tu-field"><span>Email you applied with</span><input id="tuEmail" type="email" autocomplete="email" placeholder="you@example.com" required></label>
          <button class="tu-btn" id="tuGo" type="submit">View my tuition</button>
        </form>
        <div class="tu-note err" id="tuGateErr" role="alert" hidden></div>
      </div>

      <div class="tu-card" id="payCard" hidden>
        <h2 id="tuHi">Your tuition</h2>
        <p class="tu-muted" id="tuSub" style="margin: 0"></p>
        <div class="tu-amount"><div class="v" id="tuAmt">—</div><div class="c" id="tuCur">USD</div></div>
        <div class="tu-pay">
          <button type="button" class="primary" data-prov="paystack">Pay by card (Paystack)</button>
          <button type="button" data-prov="flutterwave">Pay with Flutterwave</button>
          <button type="button" data-prov="bank">Pay by bank transfer</button>
        </div>
        <div class="tu-note" id="tuStatus" role="status" hidden></div>
        <p class="tu-muted" style="margin: 16px 0 0">Secured by your payment provider. Your tuition is confirmed after the MAM Auditer judges place you. Questions? <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a></p>
      </div>

      <p class="tu-muted" style="margin: 0">Not placed yet? <a href="/book">Book your MAM Auditer →</a> · <a href="/status">Check application status →</a></p>
    </div>
  </section>
</main>
<script src="/assets/js/config.js?v=20261128v"></script>
<?php site_footer(['site-ccy-bridge.js', 'tuition.js']); ?>
