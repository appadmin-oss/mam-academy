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

/** Detect the visitor's ISO-2 country. Trusts the standard upstream-proxy
 *  headers in priority order (Cloudflare > generic CDN > Accept-Language
 *  fallback). Defaults to 'DEFAULT' if nothing is determinable. */
function mam_detect_country(): string {
    $hdrs = [
        'HTTP_CF_IPCOUNTRY',       // Cloudflare
        'HTTP_X_VERCEL_IP_COUNTRY',
        'HTTP_X_COUNTRY',
        'HTTP_X_GEO_COUNTRY',
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
    // Carry the equivalent NGN so the payment provider (which charges in NGN
    // via Paystack) and the email/invoice can show "approx ₦7,500" disclosure.
    $usdRate = (float)mam_env('USD_TO_NGN', '1500');
    // Quick conversion: amount in tier currency → USD → NGN.
    // For NGN, amount IS already NGN.
    $cur = $tier['currency'];
    if ($cur === 'NGN') {
        $tier['amount_ngn'] = (int)$tier['amount'];
        $tier['amount_usd'] = round($tier['amount'] / max(1, $usdRate), 2);
    } else {
        // Rough cross-rate via USD. For currencies other than USD, this is a
        // display-only approximation — actual charge is in tier currency.
        $crossToUsd = ['USD' => 1, 'GBP' => 1.27, 'EUR' => 1.08, 'CAD' => 0.73, 'AUD' => 0.66, 'GHS' => 0.065, 'KES' => 0.0077, 'ZAR' => 0.054];
        $rate = $crossToUsd[$cur] ?? 1;
        $tier['amount_usd'] = round($tier['amount'] * $rate, 2);
        $tier['amount_ngn'] = (int)round($tier['amount_usd'] * $usdRate);
    }
    return $tier;
}
