<?php
/**
 * Admin-editable runtime settings (key/value), with safe fallbacks.
 *
 * Why this exists: programme prices, the online surcharge, the registration
 * fee and the store shipping flat-rate used to be hardcoded in PHP (and
 * mirrored by hand into assets/js/config.js). That meant a price change was a
 * code edit + redeploy. These now live in a `settings` table the admin can
 * edit from /admin/?v=settings, and the server reads them at charge time so
 * they're authoritative.
 *
 * Resilience: the table may not exist yet on a freshly-pointed MySQL database
 * that hasn't run migrations. Every read therefore falls back to the caller's
 * default, so the site keeps charging the correct shipped-default price instead
 * of 500-ing. Values are cached per-request (one SELECT) — admin saves bust the
 * cache implicitly because they happen on a separate request.
 */
declare(strict_types=1);

/** Load all settings once per request. Returns [] if the table isn't there. */
function mam_settings_all(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach (mam_db_all('SELECT skey, svalue FROM settings') as $row) {
            $cache[(string)$row['skey']] = $row['svalue'];
        }
    } catch (Throwable $e) {
        // Table missing / DB behind on migrations — fall back to defaults.
        mam_log('info', 'settings_table_unavailable', ['err' => $e->getMessage()]);
    }
    return $cache;
}

/**
 * Read one setting. Returns the stored value (string) or $default when the key
 * is unset/blank. Numeric callers should cast the result themselves.
 */
function mam_setting(string $key, $default = null) {
    $all = mam_settings_all();
    if (!array_key_exists($key, $all)) return $default;
    $v = $all[$key];
    if ($v === null || $v === '') return $default;
    return $v;
}

/** Typed convenience reader for the many numeric settings. */
function mam_setting_num(string $key, float $default): float {
    $v = mam_setting($key, null);
    return is_numeric($v) ? (float)$v : $default;
}

/**
 * Persist one setting (upsert). Caller is responsible for auth + CSRF. Values
 * are stored as strings; reads cast as needed. Safe across SQLite + MySQL via
 * mam_db_upsert.
 */
function mam_setting_set(string $key, $value): void {
    mam_db_upsert(
        'settings',
        ['skey' => $key, 'svalue' => (string)$value, 'updated_at' => date('Y-m-d H:i:s')],
        ['skey'],
        ['svalue' => (string)$value, 'updated_at' => date('Y-m-d H:i:s')]
    );
}

/**
 * The canonical list of admin-editable settings with their shipped defaults,
 * labels, groups and input hints. Single source for: seeding, the admin form,
 * and the public /api/config/public endpoint. Keep keys stable.
 */
function mam_settings_schema(): array {
    return [
        // group, key, label, default, suffix
        // Level base prices — the single source from which every programme price
        // is derived (Standard = 1×, Certified = 2×, Grand = 3× − discount).
        ['Level base prices (USD)', 'price_level_precert',      'Pre-Certificate base',  120, '$'],
        ['Level base prices (USD)', 'price_level_cert',         'Certificate base',      130, '$'],
        ['Level base prices (USD)', 'price_level_prediploma',   'Pre-Diploma base',      160, '$'],
        ['Level base prices (USD)', 'price_level_diploma',      'Diploma base',          180, '$'],
        // Standalone / flat programmes — not level-based.
        ['Programme pricing (USD)', 'price_crash',              'Crash Programme (flat)', 70, '$'],
        ['Programme pricing (USD)', 'price_meta_order',         'MetaMusic Order',        60, '$'],
        // Certified compulsory-courses fee (Rhythm&Pitch, Theory, Performance,
        // Meta Music) — added on top of the level base.
        ['Programme pricing (USD)', 'compulsory_fee_usd',       'Compulsory courses fee (Certified)', 120, '$'],
        // Grand = 3×base − this discount.
        ['Programme adjustments',   'grand_discount_pct',       'Grand discount', 13, '%'],
        // Legacy flat programme prices — drive the current form/charging until
        // the level-based form rebuild lands; then these can be retired.
        ['Programme pricing (legacy)', 'price_certified_musician', 'Certified Musician (legacy flat)', 230, '$'],
        ['Programme pricing (legacy)', 'price_grand_musician',     'Grand Musician (legacy flat)',     310, '$'],
        ['Fees & surcharges',       'online_surcharge_pct',     'Online surcharge',       20, '%'],
        ['Fees & surcharges',       'registration_fee_usd',     'Registration fee',        5, '$'],
        ['Store',                   'store_shipping_flat_usd',  'Store shipping (flat, physical goods)', 5, '$'],
        ['Currency',                'fx_auto',                  'Auto-update USD→NGN from live rate API (1=on, 0=off)', 1, ''],
        ['Currency',                'usd_to_ngn',               'USD → NGN fallback rate (used when live FX is off/unreachable)', 1500, '₦'],
        // Scholarship CTA shown in emails (the website reads its own copy from
        // assets/js/config.js → scholarship). Set enabled to 0 to hide it.
        ['Scholarship',             'scholarship_enabled',      'Show scholarship CTA in emails (1=on, 0=off)', 1, ''],
        ['Scholarship',             'scholarship_url',          'Scholarship application URL', 'https://mamcentre.org/scholarships/ngv', ''],
    ];
}
