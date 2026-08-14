<?php
/**
 * MAM Academy — money backfill (LMS 2.0 Part A) · CLI / one-shot.
 * ─────────────────────────────────────────────────────────────
 * Populates the v61 currency-safe money columns for rows written before the
 * migration: payments.{amount_minor, amount_usd_cents, fx_rate_usd_ngn,
 * fx_backfilled} and instructor_fines.{amount_usd_cents, fx_rate_usd_ngn}
 * (+ normalising currency). The legacy `amount` / `amount_kobo` columns are
 * only READ here — nothing is dropped or overwritten.
 *
 * Because we have no historical FX rate per row, backfilled USD values are an
 * ESTIMATE at the current (or --rate) rate, and every payment row touched is
 * stamped fx_backfilled=1 so reporting can tell captured-live from derived.
 * Rows already carrying amount_usd_cents are left untouched, so the tool is
 * safe to re-run.
 *
 *   php api/tools/backfill-money.php --dry-run        # preview, writes nothing
 *   php api/tools/backfill-money.php                  # apply
 *   php api/tools/backfill-money.php --rate=1620.5    # apply at a known rate
 *
 * Exit: 0 success, 1 invalid usage / error.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); echo "This tool is CLI-only.\n"; exit(1); }
require_once __DIR__ . '/../lib/bootstrap.php';

$dryRun = false;
$rateOverride = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run' || $a === '-n') { $dryRun = true; continue; }
    if (strpos($a, '--rate=') === 0) {
        $rateOverride = (float) substr($a, 7);
        if ($rateOverride <= 0) { fwrite(STDERR, "Invalid --rate (must be > 0).\n"); exit(1); }
        continue;
    }
    fwrite(STDERR, "Unknown argument: $a\n");
    fwrite(STDERR, "Usage: php backfill-money.php [--dry-run] [--rate=N]\n");
    exit(1);
}

// mam_db() lazily runs migrations, so the v61 columns exist by the time we read.
$db   = mam_db();
$rate = $rateOverride ?? mam_usd_to_ngn_rate(false); // read-only: cache/fallback, never blocks on the FX API
$rate = mam_money_safe_rate($rate);

echo "── MAM money backfill ", ($dryRun ? "(DRY RUN — no writes)" : "(APPLYING)"), " ──\n";
echo "USD→NGN rate for derived rows: ", number_format($rate, 4),
     $rateOverride !== null ? " (from --rate)\n" : " (current cache/fallback)\n";

/* ── payments ──────────────────────────────────────────────────────────── */
$payRows = mam_db_all(
    "SELECT id, amount, currency FROM payments WHERE amount_usd_cents IS NULL ORDER BY id"
);
$paySamples = [];
$payCount = 0;
if (!$dryRun && $payRows) $db->beginTransaction();
try {
    foreach ($payRows as $r) {
        $id       = (int) $r['id'];
        $amount   = (float) $r['amount'];
        $currency = strtoupper(trim((string) ($r['currency'] ?? 'NGN'))) ?: 'NGN';
        $minor    = mam_money_to_minor($amount);
        $usdCents = mam_money_usd_cents($amount, $currency, $rate);
        if (count($paySamples) < 8) {
            $paySamples[] = sprintf("  #%d  %s %s → minor=%d  usd_cents=%d ($%.2f)",
                $id, number_format($amount, 2), $currency, $minor, $usdCents, $usdCents / 100.0);
        }
        if (!$dryRun) {
            mam_db_update('payments', [
                'amount_minor'     => $minor,
                'amount_usd_cents' => $usdCents,
                'fx_rate_usd_ngn'  => $rate,
                'fx_backfilled'    => 1,
            ], ['id' => $id]);
        }
        $payCount++;
    }
    if (!$dryRun && $payRows) $db->commit();
} catch (\Throwable $e) {
    if (!$dryRun && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "payments backfill failed: " . $e->getMessage() . "\n");
    exit(1);
}

/* ── instructor_fines ──────────────────────────────────────────────────── */
$fineRows = mam_db_all(
    "SELECT id, amount_kobo, currency FROM instructor_fines WHERE amount_usd_cents IS NULL ORDER BY id"
);
$fineCount = 0;
if (!$dryRun && $fineRows) $db->beginTransaction();
try {
    foreach ($fineRows as $r) {
        $id       = (int) $r['id'];
        $naira    = ((int) $r['amount_kobo']) / 100.0; // amount_kobo is naira minor units
        $currency = strtoupper(trim((string) ($r['currency'] ?? 'NGN'))) ?: 'NGN';
        $usdCents = mam_money_usd_cents($naira, $currency, $rate);
        if (!$dryRun) {
            mam_db_update('instructor_fines', [
                'currency'         => $currency,
                'amount_usd_cents' => $usdCents,
                'fx_rate_usd_ngn'  => $rate,
            ], ['id' => $id]);
        }
        $fineCount++;
    }
    if (!$dryRun && $fineRows) $db->commit();
} catch (\Throwable $e) {
    if (!$dryRun && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "fines backfill failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\npayments : ", ($dryRun ? "would update " : "updated "), $payCount, " row(s)\n";
foreach ($paySamples as $s) echo $s, "\n";
if ($payCount > count($paySamples)) echo "  … +", ($payCount - count($paySamples)), " more\n";
echo "fines    : ", ($dryRun ? "would update " : "updated "), $fineCount, " row(s)\n";

// Post-run integrity read (only meaningful after an apply): every succeeded
// payment should now carry a canonical USD figure.
if (!$dryRun) {
    $stillNull = (int) (mam_db_row(
        "SELECT COUNT(*) AS c FROM payments WHERE amount_usd_cents IS NULL AND status='succeeded'"
    )['c'] ?? 0);
    echo "check    : succeeded payments still missing amount_usd_cents = ", $stillNull, "\n";
}
echo "done", ($dryRun ? " (dry run — nothing written)" : ""), ".\n";
exit(0);
