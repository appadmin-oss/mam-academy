<?php
/**
 * The 2026-11 public site shell — the design handoff's header, Programmes panel,
 * footer and page frame, shared by the redesigned pages (Home, Programmes, About,
 * MAM Auditer, Life at MAM). The markup in this folder was produced from the
 * handoff's own page sources (docs/design/2026-11-site/), rendered and ported
 * rather than redrawn, so it can be checked against them with Faithful
 * (`npx @faithful/cli verify <page>.dc.html --build <url>`; see HANDOFF §4z16).
 *
 * The pages load bootstrap.php for the few facts they must not type: the intake
 * being promoted (the academic session), every fee, and the exchange rates. A fee
 * printed here and a fee charged at /apply come from the same functions, so the
 * two cannot drift (the handoff's own fee-model.js is kept as a test oracle only).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/bootstrap.php';
// bootstrap serves the JSON API: it sets a JSON content type and, at shutdown,
// appends {"error":"no_response"} unless told this request answered with HTML.
// (A page still on the previous design includes this from its header partial,
// after output has begun; it has set its own headers already.)
if (!headers_sent()) {
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
}
if (!defined('MAM_HTML_RESPONSE')) define('MAM_HTML_RESPONSE', true);

function site_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/**
 * A photo slot. The handoff ships placeholders (§9: real MAM photos, no stock);
 * a file at assets/img/site/<slug>.(webp|jpg) fills the slot the day it exists,
 * and until then the slot is the design's warm gradient, hidden from readers.
 */
function site_photo(string $label): string {
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-');
    foreach (['webp', 'jpg', 'avif'] as $ext) {
        $rel = "assets/img/site/$slug.$ext";
        if (is_file(__DIR__ . '/../../' . $rel)) {
            return '<img src="/' . $rel . '" alt="' . site_h(ucfirst($label)) . '" loading="lazy" decoding="async"'
                 . ' style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover">';
        }
    }
    return '';
}

/** Fill every [data-photo] slot in a rendered fragment. */
function site_photos(string $html): string {
    return preg_replace_callback('/(<div data-photo="([^"]*)" aria-hidden="true"([^>]*)>)/', static function ($m) {
        $img = site_photo($m[2]);
        // A real photo is content: it is no longer hidden, and it carries its alt.
        return $img === '' ? $m[1] : str_replace(' aria-hidden="true"', '', $m[1]) . $img;
    }, $html);
}

/** The organisation node, read from the one place it is written (partials/header.html). */
function site_org_jsonld(): string {
    $h = (string) @file_get_contents(__DIR__ . '/../header.html');
    return preg_match('#<script type="application/ld\+json">.*?</script>#s', $h, $m) ? $m[0] : '';
}

/**
 * The accessibility settings, applied before the first paint (assets/js/a11y.js keeps
 * them in step afterwards). Inline because a reader who needs 175% text or no motion
 * must not see a frame of the page without it. Carries the old toolbar's two keys
 * across once, so nobody loses a setting they made on the previous design.
 */
function site_a11y_head(): string {
    return '<link rel="stylesheet" href="/assets/css/a11y.css?v=20261121">' . "\n"
        . "<script>(function(){try{var d=document.documentElement,s=JSON.parse(localStorage.getItem('mam.a11y')||'null');"
        . "if(!s){s={};if(localStorage.getItem('mam.contrast')==='high')s.contrast=1;var f=parseFloat(localStorage.getItem('mam.fontScale')||'1');if(f>1.02)s.size=f>=1.2?2:1;}"
        . "window.mamA11yZoom=function(n){var z=[1,1.12,1.25,1.5,1.75][n|0]||1,cap=Math.max(1,innerWidth/320);d.style.setProperty('--a11y-z',String(Math.min(z,cap)));return z>cap?cap:0;};"
        . "if(s.size){d.setAttribute('data-a11y-size',s.size);mamA11yZoom(s.size);}"
        . "['contrast','mute','links','focus','cursor','font','spacing','still'].forEach(function(k){if(s[k])d.classList.add('a11y-'+k);});"
        . "if(s.contrast)d.setAttribute('data-contrast','high');}catch(e){}})();</script>\n";
}

/**
 * The accessibility panel's settings: the one list the panel is drawn from and the
 * accessibility statement describes, so the statement cannot offer what the panel lacks.
 * group => [key => [label, what it does]]; every key has a rule in assets/css/a11y.css.
 */
function site_a11y_options(): array {
    return [
        'Seeing the page' => [
            'contrast' => ['Higher contrast', 'Darker words, firmer edges'],
            'mute'     => ['Muted colours', 'Less colour, for comfort or light sensitivity'],
            'links'    => ['Underline links', 'Every link marked, not only by colour'],
            'focus'    => ['Strong focus ring', 'See exactly where the keyboard is'],
            'cursor'   => ['Large pointer', 'A bigger mouse pointer'],
        ],
        'Reading' => [
            'font'     => ['Readable typeface', 'Atkinson Hyperlegible, made for low vision'],
            'spacing'  => ['More space', 'Wider lines, letters and paragraphs'],
            'guide'    => ['Reading guide', 'A band that follows your pointer or keyboard'],
            'still'    => ['Stop movement', 'No animation, no moving waveform'],
        ],
    ];
}

/** The accessibility panel and the cookie notice; $launcher false where the page offers its own way in. */
function site_a11y_panel(bool $launcher = true): void {
    include __DIR__ . '/a11y.php';
    echo '<script src="/assets/js/a11y.js?v=20261121" defer></script>' . "\n";
}

/**
 * <head> and the opening of <body>.
 * $p: title, description, path (canonical), jsonld (extra <script> blocks)
 */
function site_head(array $p): void {
    $title = (string) ($p['title'] ?? 'MAM Academy');
    $desc  = (string) ($p['description'] ?? '');
    $url   = 'https://academy.mamcentre.org' . ($p['path'] ?? '/');
    $og    = 'https://academy.mamcentre.org/api/og?t=' . rawurlencode('MAM Academy') . '&s=' . rawurlencode($desc);
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= site_h($title) ?></title>
<meta name="description" content="<?= site_h($desc) ?>">
<link rel="canonical" href="<?= site_h($url) ?>">
<meta name="theme-color" content="#FAF8F4">
<meta property="og:type" content="website">
<meta property="og:site_name" content="MAM Academy">
<meta property="og:title" content="<?= site_h($title) ?>">
<meta property="og:description" content="<?= site_h($desc) ?>">
<meta property="og:url" content="<?= site_h($url) ?>">
<meta property="og:image" content="<?= site_h($og) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" type="image/svg+xml" href="/mam-academy-logo.svg">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&amp;family=Inter:wght@400;500;600&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/site.css?v=20261121">
<?= site_a11y_head() ?>
<?= site_org_jsonld() ?>
<?= $p['jsonld'] ?? '' ?>
</head>
<body class="site">
<?php
}

/**
 * The header. 'home' is the handoff's claude.com model (a minimal layer over the
 * hero, the full bar after 560px of scroll, the dismissible intake strip above);
 * 'inner' is the sticky bar that steps aside while reading. $current is the path
 * whose link is marked as the page you are on.
 */
function site_header(string $variant, string $current = '', bool $skip = true): void {
    if ($skip) echo '<a href="#main" class="skip" style="position: absolute; left: 8px; top: -60px; z-index: 50; background: #FFFFFF; color: #211A13; padding: 12px 16px; border-radius: 6px">Skip to main content</a>' . "\n";
    if ($variant === 'home') {
        include __DIR__ . '/announce.php';
        $html = (string) file_get_contents(__DIR__ . '/header-home.php');
    } else {
        $html = (string) file_get_contents(__DIR__ . '/header-inner.php');
    }
    if ($current !== '') {
        $html = preg_replace(
            '#<a href="' . preg_quote($current, '#') . '" class="ul" style="([^"]*?)color: \#211A13#',
            '<a href="' . $current . '" class="ul" aria-current="page" style="$1color: #8A5208',
            $html, 1);
    }
    if ($current === '/programmes') {
        // Programmes is a panel button, not a link: it is marked current by colour, as the design does.
        $html = preg_replace('#(<button type="button" class="ul" aria-controls="programmes-panel" style="[^"]*?)color: \#211A13#',
            '$1color: #8A5208', $html, 1);
    }
    $panel = (string) file_get_contents(__DIR__ . '/panel.php');
    // The panel sits inside the header so it opens under the bar it belongs to.
    echo str_replace('</header>', $panel . "\n</header>", $html), "\n";
}

/** The footer and the scripts the shared chrome needs (the panel, the newsletter). */
function site_footer_chrome(): void {
    include __DIR__ . '/footer.php';
    site_a11y_panel();
    echo "\n<script>window.MAM_SITE=" . json_encode(['fees' => site_fees(), 'intake' => site_intake()], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ";</script>\n";
    echo '<script src="/assets/js/site.js?v=20261121" defer></script>' . "\n";
}

/** The footer, and the end of the document. $scripts: page scripts beyond site.js. */
function site_footer(array $scripts = []): void {
    site_footer_chrome();
    foreach ($scripts as $s) echo '<script src="/assets/js/' . site_h($s) . '?v=20261120" defer></script>' . "\n";
    echo "</body>\n</html>\n";
}

/**
 * A text page in the redesign's language (the About page's breadcrumb, serif H1
 * and hairline-ruled sections), for pages the handoff did not draw but its footer
 * links to: Safeguarding, Accessibility.
 */
function site_doc_open(string $crumb, string $title, string $lead): void {
    ?>
    <section aria-labelledby="page-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px) clamp(32px, 4vw, 56px); box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
      <nav aria-label="Breadcrumb" class="fade-in" style="font-size: 14px; color: #574B40; display: flex; gap: 8px"><a href="/" class="ul" style="color: #574B40; text-decoration: none">Home</a><span aria-hidden="true">/</span><span aria-current="page"><?= site_h($crumb) ?></span></nav>
      <h1 id="page-h" class="fade-in" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(46px, 5.6vw, 84px); line-height: 1.02; letter-spacing: -.01em"><?= site_h($title) ?></h1>
      <p class="fade-in" style="margin: 0; max-width: 760px; font-size: 19px; line-height: 1.7; color: #3E342B"><?= $lead ?></p>
    </section>
    <?php
}

/** One hairline-ruled section; $body is trusted HTML written in the page. */
function site_doc_section(string $id, string $heading, string $body): void {
    ?>
    <section id="<?= site_h($id) ?>" aria-labelledby="<?= site_h($id) ?>-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px); box-sizing: border-box; border-top: 1px solid #E6DED2; display: flex; flex-wrap: wrap; gap: 24px clamp(40px, 6vw, 96px)">
      <h2 id="<?= site_h($id) ?>-h" style="flex: 1 1 280px; margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1"><?= site_h($heading) ?></h2>
      <div class="doc" style="flex: 2 1 480px; min-width: 0; display: flex; flex-direction: column; gap: 16px; font-size: 18px; line-height: 1.7; color: #3E342B"><?= $body ?></div>
    </section>
    <?php
}
