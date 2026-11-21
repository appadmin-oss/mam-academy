<?php
/**
 * Not found — served by .htaccess (ErrorDocument 404 /404.php), on the 2026-11
 * redesign shell. It says 404 itself as well, so a request for /404 directly, or
 * a server that forwards without the status, is not indexed as a real page.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';
http_response_code(404);

site_head([
    'title' => 'Page not found — MAM Academy',
    'description' => 'The page you’re looking for isn’t here.',
    'path' => '/404',
    'jsonld' => '<meta name="robots" content="noindex, nofollow">',
]);
site_header('inner');
?>
<main id="main" style="min-height: 60vh; display: flex; align-items: center; justify-content: center; padding: clamp(64px, 8vw, 120px) clamp(20px, 4vw, 40px); box-sizing: border-box; text-align: center">
  <div style="max-width: 640px; display: flex; flex-direction: column; align-items: center; gap: 20px">
    <p class="fade-in" aria-hidden="true" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(96px, 18vw, 192px); line-height: 1; color: #C27A12">404</p>
    <h1 class="fade-in" style="animation-delay: .1s; margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">Off the <em style="font-style: italic; color: #8A5208">score sheet</em></h1>
    <p class="fade-in" style="animation-delay: .2s; margin: 0; font-size: 18px; line-height: 1.6; color: #3E342B">The page you’re looking for isn’t on the staff. Let’s get you back to the music.</p>
    <div class="fade-in" style="animation-delay: .3s; display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-top: 8px">
      <a href="/" class="btn" style="display: inline-flex; align-items: center; min-height: 52px; padding: 0 26px; border-radius: 999px; background: #211A13; color: #FAF8F4; font-weight: 600; text-decoration: none">Home</a>
      <a href="/apply" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 52px; padding: 0 26px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Apply now <span aria-hidden="true">→</span></a>
      <a href="/compare" class="btn" style="display: inline-flex; align-items: center; min-height: 52px; padding: 0 26px; border-radius: 999px; border: 1px solid #CFC3B3; color: #211A13; font-weight: 600; text-decoration: none">Compare programmes</a>
    </div>
  </div>
</main>
<?php
site_footer();
