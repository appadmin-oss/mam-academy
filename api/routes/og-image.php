<?php
/**
 * GET /api/og?t=Title&s=Subtitle&e=Eyebrow
 *
 * Auto-generated Open Graph / social-share image (1200×630 PNG) in the brand
 * flier style: warm white canvas, the vivid flame side-band on the right, the
 * serif title in flier orange, a faint beamed-note watermark, the MAM badge,
 * and the "Crafting the Timeless in Time" tagline. Pages point their og:image
 * here so every share card is on-brand without hand-making images.
 *
 * Pure GD + two bundled OFL fonts (assets/fonts). Results are cached on disk
 * (api/storage/og) keyed by params+version and served with long-lived cache
 * headers + ETag, so the cost is one render per unique card.
 */
declare(strict_types=1);

const MAM_OG_VERSION = 'v1';                    // bump to invalidate every cached card
const MAM_OG_W = 1200;
const MAM_OG_H = 630;

// ── Params (length-capped, control chars stripped — they're drawn, not executed) ──
$clean = function (string $v, int $max): string {
    $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '';
    $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
};
$title    = $clean((string)($_GET['t'] ?? ''), 90) ?: 'MAM Academy';
$subtitle = $clean((string)($_GET['s'] ?? ''), 150);
$eyebrow  = $clean((string)($_GET['e'] ?? ''), 44);

if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
    mam_raw_response('OG image generation unavailable.', 501, 'text/plain; charset=utf-8');
}

$fontsDir = dirname(__DIR__, 2) . '/assets/fonts';
$serif = $fontsDir . '/CormorantGaramond-Bold.ttf';
$sans  = $fontsDir . '/Inter-SemiBold.ttf';
if (!is_file($serif) || !is_file($sans)) {
    mam_raw_response('OG fonts missing.', 501, 'text/plain; charset=utf-8');
}

// ── Disk cache + conditional get ──
$cacheDir = dirname(__DIR__) . '/storage/og';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$key   = md5(MAM_OG_VERSION . '|' . $title . '|' . $subtitle . '|' . $eyebrow);
$file  = $cacheDir . '/' . $key . '.png';
$etag  = '"og-' . $key . '"';
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    // Mark the response as sent so the fatal-error safety net doesn't 500 us.
    if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
    http_response_code(304);
    header('Cache-Control: public, max-age=604800, immutable');
    header('ETag: ' . $etag);
    exit;
}
$serve = function (string $png) use ($etag): void {
    header('Cache-Control: public, max-age=604800, immutable');
    header('ETag: ' . $etag);
    mam_raw_response($png, 200, 'image/png');
};
if (is_file($file)) { $serve((string)file_get_contents($file)); }

// ── Compose (flier layout) ──
$im = imagecreatetruecolor(MAM_OG_W, MAM_OG_H);

$cream   = imagecolorallocate($im, 255, 253, 248);   // warm white canvas
$flame   = imagecolorallocate($im, 226, 96, 28);     // flier band orange
$titleCo = imagecolorallocate($im, 224, 137, 30);    // flier headline orange
$ink     = imagecolorallocate($im, 42, 33, 25);      // body text
$muted   = imagecolorallocate($im, 122, 104, 86);    // domain/handle
$white   = imagecolorallocate($im, 255, 255, 255);
$goldSft = imagecolorallocate($im, 242, 169, 59);    // badge wordmark
$dark    = imagecolorallocate($im, 23, 16, 10);      // badge plate
$wmark   = imagecolorallocatealpha($im, 42, 33, 25, 116); // faint note watermark

imagefilledrectangle($im, 0, 0, MAM_OG_W, MAM_OG_H, $cream);

// Faint beamed eighth-note watermark, bottom-left (drawn first, under text).
$nx = 40; $ny = 380; $sc = 1.05;
$rot = static function (float $cx, float $cy, float $rx, float $ry, $img, $col): void {
    imagefilledellipse($img, (int)$cx, (int)$cy, (int)(2 * $rx), (int)(2 * $ry), $col);
};
$rot($nx + 70 * $sc, $ny + 190 * $sc, 34 * $sc, 24 * $sc, $im, $wmark);
$rot($nx + 205 * $sc, $ny + 165 * $sc, 34 * $sc, 24 * $sc, $im, $wmark);
imagefilledrectangle($im, (int)($nx + 96 * $sc), (int)($ny + 40 * $sc), (int)($nx + 106 * $sc), (int)($ny + 190 * $sc), $wmark);
imagefilledrectangle($im, (int)($nx + 231 * $sc), (int)($ny + 16 * $sc), (int)($nx + 241 * $sc), (int)($ny + 165 * $sc), $wmark);
imagefilledpolygon($im, [
    (int)($nx + 96 * $sc), (int)($ny + 40 * $sc),
    (int)($nx + 241 * $sc), (int)($ny + 16 * $sc),
    (int)($nx + 241 * $sc), (int)($ny + 48 * $sc),
    (int)($nx + 96 * $sc), (int)($ny + 72 * $sc),
], $wmark);

// The flier's flame side-band.
$bandX = 952;
imagefilledrectangle($im, $bandX, 0, MAM_OG_W, MAM_OG_H, $flame);

// Badge plate in the band (stand-in for the logo mark).
$bx = $bandX + (MAM_OG_W - $bandX - 128) / 2; $by = 76;
imagefilledrectangle($im, (int)$bx + 12, (int)$by, (int)$bx + 116, (int)$by + 128, $dark);
imagefilledrectangle($im, (int)$bx, (int)$by + 12, (int)$bx + 128, (int)$by + 116, $dark);
imagefilledellipse($im, (int)$bx + 12, (int)$by + 12, 24, 24, $dark);
imagefilledellipse($im, (int)$bx + 116, (int)$by + 12, 24, 24, $dark);
imagefilledellipse($im, (int)$bx + 12, (int)$by + 116, 24, 24, $dark);
imagefilledellipse($im, (int)$bx + 116, (int)$by + 116, 24, 24, $dark);
$bb = imagettfbbox(34, 0, $serif, 'MAM');
imagettftext($im, 34, 0, (int)($bx + 64 - ($bb[2] - $bb[0]) / 2), (int)$by + 66, $goldSft, $serif, 'MAM');
$bb = imagettfbbox(13, 0, $sans, 'ACADEMY');
imagettftext($im, 13, 0, (int)($bx + 64 - ($bb[2] - $bb[0]) / 2), (int)$by + 96, $white, $sans, 'ACADEMY');

// Tagline, two centred white lines at the foot of the band (as on the flier).
foreach (['Crafting the', 'Timeless in Time'] as $i => $line) {
    $bb = imagettfbbox(19, 0, $serif, $line);
    imagettftext($im, 19, 0, (int)($bandX + (MAM_OG_W - $bandX) / 2 - ($bb[2] - $bb[0]) / 2), MAM_OG_H - 72 + $i * 30, $white, $serif, $line);
}

// Top-left domain, flier-style.
imagettftext($im, 19, 0, 64, 74, $muted, $sans, 'academy.mamcentre.org');

// Wrap helper: greedy word-wrap to a pixel width for a font+size.
$wrap = function (string $text, string $font, float $size, int $maxW): array {
    $words = preg_split('/\s+/u', $text) ?: [];
    $lines = []; $cur = '';
    foreach ($words as $w) {
        $try = $cur === '' ? $w : $cur . ' ' . $w;
        $bb = imagettfbbox($size, 0, $font, $try);
        if (($bb[2] - $bb[0]) > $maxW && $cur !== '') { $lines[] = $cur; $cur = $w; }
        else $cur = $try;
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines;
};

$textRight = $bandX - 72;           // text column: x 64 → band, with a gutter
$maxW = $textRight - 64;
$y = 190;

// Eyebrow (optional).
if ($eyebrow !== '') {
    imagettftext($im, 19, 0, 64, $y, $flame, $sans, strtoupper($eyebrow));
    $y += 34;
}

// Title: shrink-to-fit (3 lines max), flier orange serif.
$size = 86;
do {
    $lines = $wrap($title, $serif, $size, $maxW);
    if (count($lines) <= 3) break;
    $size -= 8;
} while ($size > 46);
$y += (int)($size * 1.02);
foreach (array_slice($lines, 0, 3) as $line) {
    imagettftext($im, $size, 0, 62, $y, $titleCo, $serif, $line);
    $y += (int)($size * 1.14);
}

// Subtitle: up to 3 sans lines in ink.
if ($subtitle !== '') {
    $y += 8;
    foreach (array_slice($wrap($subtitle, $sans, 25, $maxW), 0, 3) as $line) {
        imagettftext($im, 25, 0, 64, $y, $ink, $sans, $line);
        $y += 42;
    }
}

// Bottom-left handle, flier-style.
imagettftext($im, 19, 0, 64, MAM_OG_H - 44, $muted, $sans, '@mamcentre1');

ob_start();
imagepng($im, null, 8);
$png = (string)ob_get_clean();
imagedestroy($im);
@file_put_contents($file, $png);
$serve($png);
