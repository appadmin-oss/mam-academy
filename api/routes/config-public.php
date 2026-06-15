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
    // The academy's receiving bank account, so the apply/store bank-transfer
    // panels show the real details instead of placeholders.
    'bank'                    => mam_bank_details(),
    // Effective live USD→NGN (auto-updated from the rate API, cached, with the
    // admin static rate as fallback) so the UI's "approx ₦…" matches the charge.
    'usd_to_ngn'              => mam_usd_to_ngn_rate(false),
    // Level-based pricing model (spec §2). The new application form computes
    // display prices from the level base + these rules; the server stays
    // authoritative at charge time via mam_compute_application_price().
    'levels'                  => MAM_LEVELS,                 // code => label
    'level_prices'            => mam_level_base_prices(),    // code => base USD
    'compulsory_fee_usd'      => mam_compulsory_fee(),       // Certified flat add-on
    'grand_discount_pct'      => mam_grand_discount_pct(),   // Grand = 3×base − this
    'programme_from_prices'   => mam_programme_from_prices(),// "from $X" display
]);
