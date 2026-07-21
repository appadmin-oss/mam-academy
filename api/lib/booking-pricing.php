<?php
/**
 * Booking-fee pricing — the single source of truth for what the $5 booking
 * fee resolves to in each region. Both the public /api/booking/pricing
 * endpoint and the payment-init route read from here, so the price the
 * applicant sees and the price actually charged are always identical and
 * never client-controlled.
 *
 * Override at runtime by setting BOOKING_REGION_PRICES in api/.env to a
 * JSON object keyed by ISO-2 country (and a "DEFAULT" key). Example:
 *   BOOKING_REGION_PRICES='{"NG":{"currency":"NGN","amount":7500,"label":"₦7,500"},
 *                           "GB":{"currency":"GBP","amount":4,"label":"£4"},
 *                           "DEFAULT":{"currency":"USD","amount":5,"label":"$5"}}'
 *
 * If a country has no entry, we fall back to DEFAULT. EU countries collapse
 * to a single EUR tier so admins don't have to list 27 of them.
 */
declare(strict_types=1);

const MAM_BOOKING_DEFAULT_TIERS = [
    'NG' => ['currency' => 'NGN', 'amount' => 7500, 'label' => '₦7,500'],
    'GB' => ['currency' => 'GBP', 'amount' => 4,    'label' => '£4'],
    'CA' => ['currency' => 'CAD', 'amount' => 7,    'label' => 'CA$7'],
    'AU' => ['currency' => 'AUD', 'amount' => 8,    'label' => 'AU$8'],
    'GH' => ['currency' => 'GHS', 'amount' => 75,   'label' => 'GH₵75'],
    'KE' => ['currency' => 'KES', 'amount' => 650,  'label' => 'KSh 650'],
    'ZA' => ['currency' => 'ZAR', 'amount' => 90,   'label' => 'R90'],
    'EU' => ['currency' => 'EUR', 'amount' => 5,    'label' => '€5'],
    'DEFAULT' => ['currency' => 'USD', 'amount' => 5, 'label' => '$5'],
];

const MAM_EU_COUNTRIES = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE'];

/** Detect the visitor's ISO-2 country. Trusts ONLY headers the fronting
 *  platform sets and overwrites (Cloudflare / Vercel) — the generic
 *  `X-Country`/`X-Geo-Country` are not set by any platform here, so honouring
 *  them let a client self-select a cheaper booking tier; they're dropped.
 *  Falls back to Accept-Language (dev only) then 'DEFAULT'. */
function mam_detect_country(): string {
    $hdrs = [
        'HTTP_CF_IPCOUNTRY',        // Cloudflare (overwrites any client value)
        'HTTP_X_VERCEL_IP_COUNTRY', // Vercel edge
    ];
    foreach ($hdrs as $h) {
        $v = strtoupper((string)($_SERVER[$h] ?? ''));
        if (preg_match('/^[A-Z]{2}$/', $v)) return $v;
    }
    // Last-resort fallback from Accept-Language. Not authoritative — only used
    // when the platform doesn't surface geo, e.g. local dev.
    $al = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    if (preg_match('/[a-z]{2}-([A-Z]{2})/', $al, $m)) return $m[1];
    return 'DEFAULT';
}

/** Resolve the booking-fee tier for $country. Reads override JSON from env
 *  when present, else falls back to MAM_BOOKING_DEFAULT_TIERS. EU countries
 *  collapse to the 'EU' key. Always returns a fully-populated tier array. */
function mam_booking_tier(?string $country = null): array {
    $country = $country ?: mam_detect_country();
    $country = strtoupper($country);
    if (in_array($country, MAM_EU_COUNTRIES, true)) $country = 'EU';

    $tiers = MAM_BOOKING_DEFAULT_TIERS;
    $env = (string)mam_env('BOOKING_REGION_PRICES', '');
    if ($env !== '') {
        $parsed = json_decode($env, true);
        if (is_array($parsed)) {
            // Shallow-merge: env overrides default keys.
            $tiers = array_merge($tiers, $parsed);
        }
    }

    $tier = $tiers[$country] ?? $tiers['DEFAULT'];
    $tier['country'] = $country;
    // Derive the NGN charge from the SAME live, bounds-checked FX used everywhere
    // else (payment-init charges `amount_ngn` in NGN via Paystack — so this is the
    // real charge, not a display-only estimate). `mam_currency_rates()` is a
    // units-per-USD map, so USD = amount / rate[cur], then NGN = USD * rate[NGN].
    $rates   = mam_currency_rates();
    $usdRate = ($rates['NGN'] ?? 0) > 0 ? (float)$rates['NGN'] : mam_usd_to_ngn_rate(); // NGN per USD
    $cur = $tier['currency'];
    if ($cur === 'NGN') {
        $tier['amount_ngn'] = (int)$tier['amount'];
        $tier['amount_usd'] = round($tier['amount'] / max(1, $usdRate), 2);
    } else {
        $perUsd = $rates[$cur] ?? null; // units of $cur per 1 USD
        $tier['amount_usd'] = ($perUsd && $perUsd > 0) ? round($tier['amount'] / $perUsd, 2) : (float)$tier['amount'];
        $tier['amount_ngn'] = (int)round($tier['amount_usd'] * $usdRate);
    }
    return $tier;
}
