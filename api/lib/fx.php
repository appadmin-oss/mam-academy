<?php
/**
 * Live USD→NGN exchange rate, with caching and graceful fallback.
 *
 * Why: the naira/dollar rate moves fast, so charging at a hand-set static
 * number goes stale quickly. This fetches a live mid-market rate from a free,
 * key-less provider, caches it in the `settings` table for a TTL, and falls
 * back to the admin-set `usd_to_ngn` value whenever the network or provider is
 * unavailable. It NEVER throws — the payment path depends on it, so a rate-API
 * outage must degrade to the static rate, not 500 a checkout.
 *
 * Provider: defaults to open.er-api.com (no API key). Override with the
 * FX_RATE_URL env when the host's network policy allowlists a different one;
 * both the open.er-api.com (`rates`) and exchangerate-API (`conversion_rates`)
 * JSON shapes are understood.
 */
declare(strict_types=1);

/** How long a fetched live rate is trusted before refetching (seconds). */
const MAM_FX_TTL_SECONDS = 21600; // 6 hours

/** Lowest/highest USD→NGN we'll accept from a provider — guards against a
 *  glitched response (e.g. a `1` or `0`) silently mispricing every charge. */
const MAM_FX_MIN = 100.0;
const MAM_FX_MAX = 100000.0;

/** Admin-set static rate — the authoritative fallback when live FX is off or
 *  unreachable. Legacy USD_TO_NGN env still honoured. */
function mam_usd_to_ngn_fallback(): float {
    return mam_setting_num('usd_to_ngn', (float)mam_env('USD_TO_NGN', '1500'));
}

/**
 * The effective USD→NGN rate to charge/convert at. Resolution order:
 *   1. fx_auto disabled  → static fallback rate.
 *   2. fresh cached live rate (< TTL) → use it (no network hit).
 *   3. otherwise fetch live; on success cache + use it.
 *   4. fetch failed/insane → last good cached live rate, else static fallback.
 *
 * $allowFetch=false makes it read-only (cache/fallback, no network) — used by
 * the public display endpoint so a page load never blocks on the rate API; the
 * cache is (re)primed by charge-time callers that pass the default `true`.
 */
function mam_usd_to_ngn_rate(bool $allowFetch = true): float {
    $fallback = mam_usd_to_ngn_fallback();

    // Auto-update can be switched off from admin settings (fx_auto = 0).
    if ((int)mam_setting_num('fx_auto', 1) !== 1) return $fallback;

    $cached    = mam_setting_num('fx_usd_ngn_cached', 0);
    $fetchedAt = (int)mam_setting_num('fx_usd_ngn_at', 0);
    if ($cached > 0 && (time() - $fetchedAt) < MAM_FX_TTL_SECONDS) {
        return $cached; // still fresh
    }
    if (!$allowFetch) {
        return $cached > 0 ? $cached : $fallback; // stale but no network allowed
    }

    $live = mam_fx_fetch_usd_ngn();
    if ($live !== null && $live >= MAM_FX_MIN && $live <= MAM_FX_MAX) {
        mam_fx_cache_set($live);
        return $live;
    }

    // Unreachable or implausible — prefer the last good live value, else static.
    return $cached > 0 ? $cached : $fallback;
}

/** Persist the freshly-fetched rate + timestamp. Never throws (the settings
 *  table may be missing on a not-yet-migrated DB; a failed cache write must
 *  not break the charge — we just refetch next time). */
function mam_fx_cache_set(float $rate): void {
    try {
        mam_setting_set('fx_usd_ngn_cached', (string)$rate);
        mam_setting_set('fx_usd_ngn_at', (string)time());
    } catch (Throwable $e) {
        mam_log('info', 'fx_cache_write_failed', ['err' => $e->getMessage()]);
    }
}

/**
 * Fetch the raw USD→NGN rate from the configured provider. Returns null on any
 * error (no curl, network down, bad HTTP, unparseable body, missing NGN).
 */
function mam_fx_fetch_usd_ngn(): ?float {
    $url = (string)mam_env('FX_RATE_URL', 'https://open.er-api.com/v6/latest/USD');
    if (!function_exists('curl_init')) return null;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
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
        mam_log('warn', 'fx_fetch_failed', ['code' => $code, 'err' => $err]);
        return null;
    }
    return mam_fx_parse_ngn((string)$resp);
}

/**
 * Pull the NGN rate out of a provider JSON body. Understands the common
 * key-less shapes:
 *   open.er-api.com / exchangerate.host:  { "rates": { "NGN": 1600, … } }
 *   exchangerate-API v6:                  { "conversion_rates": { "NGN": … } }
 * Returns null if no numeric NGN value is present. Pure — easy to unit-test.
 */
function mam_fx_parse_ngn(string $json): ?float {
    $data = json_decode($json, true);
    if (!is_array($data)) return null;
    $rates = $data['rates'] ?? $data['conversion_rates'] ?? null;
    if (is_array($rates) && isset($rates['NGN']) && is_numeric($rates['NGN'])) {
        return (float)$rates['NGN'];
    }
    return null;
}
