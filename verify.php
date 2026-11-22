<?php
/**
 * /verify — public certificate verification (Advanced Modernization §6.4).
 * A QR on every certificate resolves here. No login; data comes from the
 * rate-limited public API (/api/verify-certificate). ?ref=MAM-CERT-… auto-loads.
 * On the 2026-11 redesign's shell; the behaviour is assets/js/verify.js.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';
$refGet = trim((string) ($_GET['ref'] ?? ''));

site_head([
    'title' => 'Verify a certificate — MAM Academy',
    'description' => 'Check that a MAM Academy certificate is genuine using the ID printed on it.',
    'path' => '/verify',
    'jsonld' => '<meta name="robots" content="noindex">',
]);
site_header('inner');
?>
<main id="main">
  <section aria-labelledby="page-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px) clamp(64px, 8vw, 120px); box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <nav aria-label="Breadcrumb" class="fade-in" style="font-size: 14px; color: #574B40; display: flex; gap: 8px"><a href="/" class="ul" style="color: #574B40; text-decoration: none">Home</a><span aria-hidden="true">/</span><span aria-current="page">Verify a certificate</span></nav>
    <h1 id="page-h" class="fade-in" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(46px, 5.6vw, 84px); line-height: 1.02; letter-spacing: -.01em">Verify a certificate</h1>
    <p class="fade-in" style="margin: 0; max-width: 640px; font-size: 19px; line-height: 1.7; color: #3E342B">Every MAM Academy certificate carries an ID and a QR code. Scan the code, or enter the ID below, to confirm it was issued by the academy.</p>
    <div id="vfBody" data-ref="<?= site_h($refGet) ?>" aria-live="polite" style="max-width: 640px; padding: clamp(24px, 3vw, 32px); border-radius: 20px; background: #FFFFFF; border: 1px solid #E6DED2; box-sizing: border-box">
      <noscript><p style="margin: 0; font-size: 17px">Checking a certificate needs JavaScript. You can also email <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a> with the certificate ID.</p></noscript>
    </div>
  </section>
</main>
<?php site_footer(['verify.js']); ?>
