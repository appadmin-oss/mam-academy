<?php
/**
 * MAM Academy — money normalization helpers (LMS 2.0, v61).
 * ─────────────────────────────────────────────────────────────
 * The `payments` table historically stored a single `amount` column whose
 * meaning depended on `currency`: NGN rows hold whole naira (e.g. 90000),
 * USD rows hold dollars (e.g. 60.00). Summing that column across rows — as
 * the admin Revenue KPI and the 14-day trend did — literally added naira to
 * dollars and rendered the garbage total as "₦…". This is the bug this
 * module fixes.
 *
 * From v61 every money row also carries an explicit, integer, currency-tagged
 * representation:
 *   • amount_minor      — the charged amount in the row currency's minor unit
 *                         (NGN → kobo, USD → cents). No floats, no rounding
 *                         drift.
 *   • amount_usd_cents  — the canonical USD value in cents, FROZEN at charge
 *                         time. This is the only column safe to SUM across
 *                         rows, so all revenue reporting runs on it.
 *   • fx_rate_usd_ngn   — the USD→NGN rate used at charge time, frozen so the
 *                         historical row never silently re-values when the
 *                         live rate moves.
 *   • fx_backfilled      — 0 when the pair was captured live at charge time,
 *                         1 when derived after the fact by the backfill tool
 *                         (i.e. an estimate at the then-current rate).
 *
 * The helpers here are deliberately DB-free and side-effect-free (except the
 * one clearly-marked reporting query) so writers, the backfill tool, and the
 * test suite all share exactly one conversion definition.
 */
declare(strict_types=1);

/** Minor units (2-decimal) of an amount expressed in its own currency.
 *  90000 NGN → 9000000 kobo; 60.00 USD → 6000 cents. */
function mam_money_to_minor(float $amount): int {
    return (int) round($amount * 100);
}

/** A usable USD→NGN rate: the argument when plausible, else the static
 *  fallback. Never returns ≤ 0, so callers can divide safely. */
function mam_money_safe_rate(?float $rate): float {
    $r = (float) $rate;
    if ($r > 0) return $r;
    return function_exists('mam_usd_to_ngn_fallback') ? mam_usd_to_ngn_fallback() : 1500.0;
}

/**
 * Canonical USD value, in cents, of an `amount` given its `currency` and the
 * USD→NGN rate in force. USD amounts convert 1:1 (dollars→cents); NGN amounts
 * are divided by the rate. The result is what belongs in amount_usd_cents and
 * is the only cross-row-summable money figure.
 */
function mam_money_usd_cents(float $amount, string $currency, ?float $rate): int {
    $cur = strtoupper(trim($currency));
    if ($cur === 'USD') return (int) round($amount * 100);
    // NGN (and any other naira-priced currency we settle in) → USD via the rate.
    return (int) round(($amount / mam_money_safe_rate($rate)) * 100);
}

/** Whole naira equivalent of a USD-cents figure, at the given/current rate.
 *  Used for the "≈₦…" half of a dual-currency display. */
function mam_money_ngn_from_usd_cents(int $usdCents, ?float $rate = null): int {
    $r = $rate !== null ? mam_money_safe_rate($rate)
                        : (function_exists('mam_usd_to_ngn_rate') ? mam_usd_to_ngn_rate(false) : 1500.0);
    return (int) round(($usdCents / 100.0) * $r);
}

/**
 * Dual-currency display string for a canonical USD-cents figure: the honest
 * USD total up front, the naira equivalent at today's rate as a muted aside.
 * Mirrors mam_admin_money()'s markup so it drops into the admin KPIs cleanly.
 */
function mam_money_format_dual(int $usdCents, ?float $rate = null): string {
    $ngn = mam_money_ngn_from_usd_cents($usdCents, $rate);
    return '$' . number_format($usdCents / 100.0, 2)
         . ' <span class="muted" style="font-size:.82em">&#8358;' . number_format($ngn) . '</span>';
}

/**
 * Total succeeded revenue in USD cents — the honest, currency-safe replacement
 * for `SUM(amount)`. Optional ISO `$since` (compared against
 * COALESCE(verified_at, created_at)) scopes it to a window. Reads the primary
 * connection; the admin dashboard runs the equivalent query on its own
 * read connection to respect replica routing.
 */
function mam_money_revenue_usd_cents(?string $since = null): int {
    if ($since === null) {
        $row = mam_db_row("SELECT COALESCE(SUM(amount_usd_cents),0) AS c FROM payments WHERE status='succeeded'");
    } else {
        $row = mam_db_row(
            "SELECT COALESCE(SUM(amount_usd_cents),0) AS c FROM payments
              WHERE status='succeeded' AND COALESCE(verified_at, created_at) >= ?",
            [$since]
        );
    }
    return (int) ($row['c'] ?? 0);
}
