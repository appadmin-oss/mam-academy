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
header_remove('Content-Type');
header('Content-Type: text/html; charset=utf-8');
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
<link rel="stylesheet" href="/assets/css/site.css?v=20261120">
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
function site_header(string $variant, string $current = ''): void {
    echo '<a href="#main" class="skip" style="position: absolute; left: 8px; top: -60px; z-index: 50; background: #FFFFFF; color: #211A13; padding: 12px 16px; border-radius: 6px">Skip to main content</a>' . "\n";
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

/** The footer, and the end of the document. $scripts: page scripts beyond site.js. */
function site_footer(array $scripts = []): void {
    include __DIR__ . '/footer.php';
    echo "\n<script>window.MAM_SITE=" . json_encode(['fees' => site_fees(), 'intake' => site_intake()], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ";</script>\n";
    echo '<script src="/assets/js/site.js?v=20261120" defer></script>' . "\n";
    foreach ($scripts as $s) echo '<script src="/assets/js/' . site_h($s) . '?v=20261120" defer></script>' . "\n";
    echo "</body>\n</html>\n";
}
