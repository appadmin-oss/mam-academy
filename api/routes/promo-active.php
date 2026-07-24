<?php
/**
 * GET /api/promo/active
 * Public: the currently live public promo (banner + time-limited discount), or
 * {active:false}. Drives the site-wide promo bar + countdown in main.js. No auth.
 */
declare(strict_types=1);

$promo = function_exists('mam_promo_public_payload') ? mam_promo_public_payload() : null;
if (!$promo) {
    mam_ok(['active' => false]);
}
mam_ok(['active' => true, 'promo' => $promo]);
