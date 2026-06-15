<?php
/**
 * GET /api/studio/card?tool=rhythm&score=92&name=Ada
 * A shareable score card as SVG (Studio S2). Public — it's a vanity asset with
 * no trust requirement; every input is clamped + escaped by the generator, and
 * only a first name is ever shown. Cacheable.
 */
declare(strict_types=1);

$svg = mam_studio_share_svg(
    (string)($_GET['tool'] ?? ''),
    (int)($_GET['score'] ?? 0),
    (string)($_GET['name'] ?? '')
);

if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
if (!headers_sent()) {
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
}
echo $svg;
exit;
