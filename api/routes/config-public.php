<?php
/**
 * GET /api/config/public
 *
 * Public, unauthenticated. Emits the admin-editable pricing/config the
 * front-end uses for DISPLAY so a price change in /admin takes effect without
 * redeploying assets/js/config.js. The server remains authoritative at charge
 * time (application.php, payment-init.php, store totals) — this endpoint is the
 * shop window, not the till.
 *
 *   { ok:true,
 *     pricing:{ 'certified-musician':{name,usd}, ... },
 *     registration_fee_usd:5, online_surcharge_pct:20,
 *     store_shipping_flat_usd:5, usd_to_ngn:1500 }
 */
declare(strict_types=1);

// Programme display names are fixed in code; only the prices are admin-editable.
$names = [
    'certified-musician' => 'Certified Musician',
    'grand-musician'     => 'Grand Musician',
    'crash'              => 'Crash Programme (6 weeks)',
    'meta-order'         => 'The MetaMusic Order (12 weeks)',
];

$prices  = mam_prices();
$pricing = [];
foreach ($names as $key => $name) {
    $pricing[$key] = ['name' => $name, 'usd' => (float)($prices[$key] ?? 0)];
}

// Short browser cache: prices change rarely, and this is hit on every page
// load. 5 minutes keeps it fresh enough while sparing the DB.
if (!headers_sent()) header('Cache-Control: public, max-age=300');

mam_ok([
    'pricing'                 => $pricing,
    'registration_fee_usd'    => mam_registration_fee(),
    'online_surcharge_pct'    => mam_online_surcharge_pct(),
    'store_shipping_flat_usd' => mam_setting_num('store_shipping_flat_usd', 5),
    'usd_to_ngn'              => mam_setting_num('usd_to_ngn', (float)mam_env('USD_TO_NGN', '1500')),
]);
