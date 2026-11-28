<?php
/**
 * Live USD-based exchange rates (NGN/GBP/EUR), with a multi-provider chain,
 * caching, and graceful fallback.
 *
 * Why: the naira/dollar rate moves fast, so charging at a hand-set static number
 * goes stale quickly. This fetches live mid-market rates from free, key-less
 * providers — trying several in order so one blocked/troubled endpoint can't
 * leave us stranded on the static rate — caches them in `settings` for a TTL,
 * and falls back to the admin-set `usd_to_ngn` value when everything is
 * unreachable. It NEVER throws: the payment path depends on it, so a rate-API
 * outage degrades to the static rate, not a 500 at checkout.
 *
 * Providers (USD base, no key): the jsdelivr-hosted currency-api is primary —
 * the host already reaches jsdelivr for our JS libs, it's CDN-fast and updates
 * daily — with a pages.dev mirror and open.er-api as further fallbacks. Set the
 * FX_RATE_URL env to prepend a preferred endpoint. Three JSON shapes are
 * understood: fawaz `{usd:{ngn,…}}`, open.er-api `{rates:{NGN,…}}`, and
 * exchangerate-API `{conversion_rates:{…}}`.
 */
declare(strict_types=1);

/** How long fetched live rates are trusted before refetching (seconds). */
const MAM_FX_TTL_SECONDS = 14400; // 4 hours

/** Lowest/highest USD→NGN we'll accept from a provider — guards against a
 *  glitched response (e.g. a `1` or `0`) silently mispricing every charge. */
const MAM_FX_MIN = 100.0;
const MAM_FX_MAX = 100000.0;

/** The currencies we display/convert (besides the USD base). Broad coverage:
 *  major world currencies + English-speaking African markets. The live providers
 *  return a full USD-based map; we extract this set (missing ones use the static
 *  fallback below, and anything we can't price at all falls back to USD client-side). */
function mam_fx_currencies(): array {
    return [
        // Charge + core display
        'NGN', 'GBP', 'EUR',
        // English-speaking / major African markets
        'GHS', 'KES', 'ZAR', 'UGX', 'TZS', 'RWF', 'ZMW', 'MWK', 'BWP', 'NAD',
        'GMD', 'SLE', 'LRD', 'SZL', 'LSL', 'MUR', 'EGP',
        // Other majors / large diaspora markets
        'USD', 'CAD', 'AUD', 'NZD', 'INR', 'PKR', 'BDT', 'AED', 'SAR', 'QAR',
        'JPY', 'CNY', 'CHF', 'SEK', 'NOK', 'DKK', 'BRL', 'MXN', 'PHP',
    ];
}

/** Static, USD-relative fallback rates used when live FX is off/unreachable for a
 *  given currency (display-only; the charge rate for NGN comes from the live path).
 *  Approximate mid-market values — refreshed live in production. */
function mam_fx_fallback_rates(): array {
    return [
        'USD' => 1.0,   'NGN' => 1500.0, 'GBP' => 0.79,  'EUR' => 0.92,
        'GHS' => 15.0,  'KES' => 129.0,  'ZAR' => 18.0,  'UGX' => 3800.0, 'TZS' => 2600.0,
        'RWF' => 1300.0,'ZMW' => 27.0,   'MWK' => 1700.0,'BWP' => 13.5,   'NAD' => 18.0,
        'GMD' => 70.0,  'SLE' => 22.0,   'LRD' => 190.0, 'SZL' => 18.0,   'LSL' => 18.0,
        'MUR' => 46.0,  'EGP' => 49.0,
        'CAD' => 1.37,  'AUD' => 1.52,   'NZD' => 1.66,  'INR' => 84.0,   'PKR' => 278.0,
        'BDT' => 120.0, 'AED' => 3.67,   'SAR' => 3.75,  'QAR' => 3.64,
        'JPY' => 155.0, 'CNY' => 7.2,    'CHF' => 0.88,  'SEK' => 10.7,   'NOK' => 10.9,
        'DKK' => 6.9,   'BRL' => 5.6,    'MXN' => 18.5,  'PHP' => 58.0,
    ];
}

/** Admin-set static NGN rate — the authoritative fallback when live FX is off
 *  or unreachable. Legacy USD_TO_NGN env still honoured. */
function mam_usd_to_ngn_fallback(): float {
    return mam_setting_num('usd_to_ngn', (float)mam_env('USD_TO_NGN', '1500'));
}

/** Ordered provider endpoints. First that returns a sane NGN wins. */
function mam_fx_providers(): array {
    $list = [
        'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.min.json',
        'https://latest.currency-api.pages.dev/v1/currencies/usd.json',
        'https://open.er-api.com/v6/latest/USD',
    ];
    $env = trim((string)mam_env('FX_RATE_URL', ''));
    if ($env !== '') array_unshift($list, $env);
    return $list;
}

/**
 * One authoritative USD-based display-rate map for the whole front end, so the
 * homepage toggle, the apply form and the charged amount can't disagree. NGN is
 * the live charge rate; GBP/EUR are live too when a provider supplied them
 * (else the admin-tunable settings defaults). $allowFetch=false keeps a page
 * load off the network (reads cache/fallback only).
 */
function mam_currency_rates(bool $allowFetch = false): array {
    $ngn    = round(mam_usd_to_ngn_rate($allowFetch), 2); // also primes the cache when fetching
    $cached = mam_fx_cached_rates(true) ?: [];            // allow a stale read for display currencies

    // Start from the broad static fallback, overlay any live rates the provider
    // supplied (they only ever fill in / refresh, never invent), then pin the two
    // authoritative values (USD base, and the NGN charge rate).
    $out = mam_fx_fallback_rates();
    foreach ($cached as $k => $v) { if (is_numeric($v)) $out[strtoupper((string)$k)] = (float)$v; }
    // Admin-tunable GBP/EUR defaults still apply when live FX hasn't supplied them.
    if (!isset($cached['GBP'])) $out['GBP'] = mam_setting_num('fx_usd_gbp', $out['GBP'] ?? 0.79);
    if (!isset($cached['EUR'])) $out['EUR'] = mam_setting_num('fx_usd_eur', $out['EUR'] ?? 0.92);
    $out['USD'] = 1.0;
    $out['NGN'] = $ngn;

    foreach ($out as $k => $v) $out[$k] = round((float)$v, 4);
    return $out;
}

/**
 * Admin money display: Naira primary + the original USD in small muted text.
 * Amounts are stored in USD (the accounting unit); this converts for display at
 * the current charge rate (read-only — no network hit during a page render),
 * static-cached for the request. Returns HTML (uses admin's .muted class).
 */
function mam_admin_money(float $usd): string {
    static $rate = null;
    if ($rate === null) $rate = mam_usd_to_ngn_rate(false);
    return '&#8358;' . number_format($usd * $rate)
         . ' <span class="muted" style="font-size:.82em">$' . number_format($usd, 2) . '</span>';
}

/**
 * The effective USD→NGN rate to charge/convert at. Resolution order:
 *   1. fx_auto disabled  → static fallback rate.
 *   2. fresh cached live rates (< TTL) → use them (no network hit).
 *   3. otherwise fetch the provider chain; on success cache + use it.
 *   4. all providers failed/insane → last good cached rate, else static fallback.
 *
 * $allowFetch=false makes it read-only (cache/fallback, no network) — used by
 * the public display endpoint so a page load never blocks on the rate API; the
 * cache is (re)primed by charge-time callers that pass the default `true`.
 */
function mam_usd_to_ngn_rate(bool $allowFetch = true): float {
    $fallback = mam_usd_to_ngn_fallback();

    // Auto-update can be switched off from admin settings (fx_auto = 0).
    if ((int)mam_setting_num('fx_auto', 1) !== 1) return $fallback;

    $cached = mam_fx_cached_rates();
    if ($cached && isset($cached['NGN'])) return (float)$cached['NGN']; // still fresh

    if (!$allowFetch) {
        $last = mam_setting_num('fx_usd_ngn_cached', 0);
        return $last > 0 ? $last : $fallback; // stale but no network allowed
    }

    /* A web request never waits on the FX provider once a live rate has been
       stored. Checkout, booking pricing and the payment email used to fetch
       whenever the 4-hour cache had lapsed — up to 5 s + 8 s inside a payment
       page if the job runner (the one place meant to fetch) had stopped. The
       runner refreshes it every pass; /api/health says when it has stopped.
       Only an install that has NEVER stored a live rate still fetches here. */
    if (PHP_SAPI !== 'cli') {
        $last = mam_setting_num('fx_usd_ngn_cached', 0);
        if ($last > 0) return $last;
    }

    $live = mam_fx_fetch_rates();
    if ($live !== null && isset($live['NGN']) && $live['NGN'] >= MAM_FX_MIN && $live['NGN'] <= MAM_FX_MAX) {
        mam_fx_cache_set($live);
        return (float)$live['NGN'];
    }

    // Unreachable or implausible — prefer the last good live value, else static.
    $last = mam_setting_num('fx_usd_ngn_cached', 0);
    return $last > 0 ? $last : $fallback;
}

/** The cached live rate map if present and (unless $allowStale) still fresh. */
function mam_fx_cached_rates(bool $allowStale = false): ?array {
    $raw = (string)mam_setting('fx_rates_cached', '');
    if ($raw === '') return null;
    $map = json_decode($raw, true);
    if (!is_array($map) || !isset($map['NGN'])) return null;
    if (!$allowStale) {
        $at = (int)mam_setting_num('fx_rates_at', 0);
        if ($at <= 0 || (time() - $at) >= MAM_FX_TTL_SECONDS) return null;
    }
    return $map;
}

/** Persist the freshly-fetched rate map + timestamp (+ legacy NGN keys for any
 *  external reader). Never throws — a failed cache write must not break a charge. */
function mam_fx_cache_set(array $rates): void {
    try {
        mam_setting_set('fx_rates_cached', json_encode($rates, JSON_UNESCAPED_SLASHES));
        mam_setting_set('fx_rates_at', (string)time());
        if (isset($rates['NGN'])) { // keep the legacy keys in step
            mam_setting_set('fx_usd_ngn_cached', (string)$rates['NGN']);
            mam_setting_set('fx_usd_ngn_at', (string)time());
        }
    } catch (Throwable $e) {
        mam_log('info', 'fx_cache_write_failed', ['err' => $e->getMessage()]);
    }
}

/**
 * Fetch the live rate map, trying each provider until one returns a sane NGN.
 * Returns ['NGN'=>…,'GBP'=>…,'EUR'=>…] (whatever the winner supplied, NGN
 * guaranteed), or null if every provider failed.
 */
function mam_fx_fetch_rates(): ?array {
    if (!function_exists('curl_init')) return null;
    foreach (mam_fx_providers() as $url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'mam-academy/1.0 (+fx)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $code < 200 || $code >= 300) {
            mam_log('warn', 'fx_fetch_failed', ['url' => $url, 'code' => $code, 'err' => $err]);
            continue;
        }
        $map = mam_fx_parse_rates((string)$resp);
        if ($map !== null && $map['NGN'] >= MAM_FX_MIN && $map['NGN'] <= MAM_FX_MAX) {
            return $map;
        }
        mam_log('warn', 'fx_parse_failed', ['url' => $url]);
    }
    return null;
}

/**
 * Pull the USD-based rate map out of a provider JSON body. Understands:
 *   fawaz currency-api:                   { "date":…, "usd": { "ngn":1600, … } }
 *   open.er-api.com / exchangerate.host:  { "rates": { "NGN": 1600, … } }
 *   exchangerate-API v6:                  { "conversion_rates": { "NGN": … } }
 * Returns the {NGN,GBP,EUR} subset (NGN required), or null. Pure — unit-tested.
 */
function mam_fx_parse_rates(string $json): ?array {
    $data = json_decode($json, true);
    if (!is_array($data)) return null;

    $src = null;
    if (isset($data['usd']) && is_array($data['usd'])) {
        $src = []; // fawaz uses lowercase keys
        foreach ($data['usd'] as $k => $v) $src[strtoupper((string)$k)] = $v;
    } else {
        $src = $data['rates'] ?? $data['conversion_rates'] ?? null;
    }
    if (!is_array($src)) return null;

    $out = [];
    foreach (mam_fx_currencies() as $c) {
        if (isset($src[$c]) && is_numeric($src[$c])) $out[$c] = (float)$src[$c];
    }
    return isset($out['NGN']) ? $out : null; // NGN is the must-have (the charge rate)
}

/** Pull just the NGN rate out of a provider JSON body (back-compat helper). */
function mam_fx_parse_ngn(string $json): ?float {
    $map = mam_fx_parse_rates($json);
    return $map['NGN'] ?? null;
}
