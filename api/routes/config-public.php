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
    // One authoritative display-rate map (USD/NGN/GBP/EUR). The homepage toggle,
    // the apply form and the charged amount all hydrate from this, so a visitor
    // can never see a naira figure that disagrees with what we actually charge.
    'currency_rates'          => mam_currency_rates(false),
    // Level-based pricing model (spec §2). The new application form computes
    // display prices from the level base + these rules; the server stays
    // authoritative at charge time via mam_compute_application_price().
    'levels'                  => MAM_LEVELS,                 // code => label
    'level_prices'            => mam_level_base_prices(),    // code => base USD
    'compulsory_fee_usd'      => mam_compulsory_fee(),       // Certified flat add-on
    'grand_discount_pct'      => mam_grand_discount_pct(),   // Grand = 3×base − this
    'programme_from_prices'   => mam_programme_from_prices(),// "from $X" display
    // CDN (Cloudinary) — admin-editable so the cloud name and upload preset can
    // be set without a code deploy. config.js patches MAM_CONFIG.cdn with these
    // values on page load; headshot.js reads them at upload time.
    'cdn' => [
        'cloudName'    => (string)mam_setting('cloudinary_cloud_name', ''),
        'uploadPreset' => (string)mam_setting('cloudinary_upload_preset', ''),
    ],
    // Summer Programme public config — drives /summer display and the chooser on
    // /apply. All values are admin-editable via the "Summer Programme" settings group.
    'summer' => [
        // Shared predicates (admissions.php) so the public config, /summer, the
        // /apply chooser and the POST guard can never disagree. Each window bound
        // is honoured independently; both used to be required here.
        'enabled'      => mam_summer_is_configured(),
        'active'       => mam_summer_accepting(),
        'progName'     => (string)mam_setting('summer_prog_name',         'MAM Summer Music Camp'),
        'tagline'      => (string)mam_setting('summer_tagline',           ''),
        'sessionStart' => (string)mam_setting('summer_session_start',     ''),
        'sessionEnd'   => (string)mam_setting('summer_session_end',       ''),
        'appOpen'      => (string)mam_setting('summer_app_open',          ''),
        'appClose'     => (string)mam_setting('summer_app_close',         ''),
        'minAge'       => (int)mam_setting_num('summer_min_age',           8),
        'maxAge'       => (int)mam_setting_num('summer_max_age',          18),
        'fee'          => (float)mam_setting_num('summer_fee',             0),
        'location'     => (string)mam_setting('summer_location',          ''),
        'mainProgName' => (string)mam_setting('main_prog_chooser_name',   'Full Year Programmes'),
        'mainProgDesc' => (string)mam_setting('main_prog_chooser_desc',   ''),
    ],
]);
