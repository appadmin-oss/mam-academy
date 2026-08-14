<?php
/**
 * POST /api/student/store-redeem  {reference}
 * Pay a PENDING store order entirely from the signed-in student's internal
 * credit (store-only spend, the decided wallet scope — no card rails here).
 *
 * Safety:
 *  - the order must belong to the caller (matched by email) and be 'pending';
 *  - an atomic status claim (pending→paid) prevents a double-spend / double-settle;
 *  - the wallet debit is server-validated (can't overdraw);
 *  - the whole money move is one transaction; stock/receipt side-effects run
 *    via the same mam_payment_settle_store() path the gateways use.
 */
declare(strict_types=1);

$u    = mam_require_user();
$in   = mam_json_input();
$ref  = mam_str($in, 'reference', 64);
if ($ref === '') mam_fail('validation', 422, ['fields' => ['reference']]);

$order = mam_db_row('SELECT * FROM store_orders WHERE reference = ?', [$ref]);
if (!$order) mam_fail('order_not_found', 404);

// Ownership: the order's email must be the caller's (credits are personal).
if (strtolower(trim((string)$order['customer_email'])) !== strtolower(trim((string)$u['email']))) {
    mam_fail('not_your_order', 403);
}
if ((string)$order['status'] !== 'pending') mam_fail('already_settled', 409, ['status' => $order['status']]);

$totalUsd = (float)$order['total_usd'];
if ($totalUsd <= 0) mam_fail('zero_total', 400);
$cents = (int) round($totalUsd * 100);

$uid = (int)$u['id'];
if (mam_wallet_balance($uid) < $cents) {
    mam_fail('insufficient_credit', 402, [
        'balance_usd' => round(mam_wallet_balance($uid) / 100, 2),
        'needed_usd'  => round($totalUsd, 2),
    ]);
}

$db = mam_db();
$db->beginTransaction();
try {
    // Atomic claim — only one caller flips pending→paid.
    $claim = $db->prepare("UPDATE store_orders SET status='paid', updated_at=? WHERE reference=? AND status='pending'");
    $claim->execute([date('Y-m-d H:i:s'), $ref]);
    if ($claim->rowCount() === 0) { $db->rollBack(); mam_fail('already_settled', 409); }

    // Debit the wallet (nest-safe inside this open transaction; re-validates balance).
    mam_wallet_post($uid, -$cents, 'store_redeem', $uid, $ref, 'Store order ' . $ref);

    // Record the settlement as a 'wallet' payment so it shows in history/receipts.
    $existing = mam_db_row('SELECT id FROM payments WHERE reference = ?', [$ref]);
    $now = date('Y-m-d H:i:s');
    // Currency-safe representation, frozen now (v61). A wallet redemption is
    // USD-denominated, so minor units and canonical USD cents coincide; freeze
    // today's rate (read-only — never block a redemption on the FX API) for the
    // naira-equivalent display.
    $usdCents = mam_money_to_minor($totalUsd);
    $rate     = mam_usd_to_ngn_rate(false);
    if ($existing) {
        mam_db_update('payments', [
            'provider' => 'wallet', 'status' => 'succeeded', 'amount' => $totalUsd,
            'currency' => 'USD', 'purpose' => 'store', 'verified_at' => $now, 'updated_at' => $now,
            'amount_minor' => $usdCents, 'amount_usd_cents' => $usdCents,
            'fx_rate_usd_ngn' => $rate, 'fx_backfilled' => 0,
        ], ['id' => (int)$existing['id']]);
    } else {
        mam_db_insert('payments', [
            'application_id' => null, 'reference' => $ref, 'provider' => 'wallet',
            'purpose' => 'store', 'status' => 'succeeded', 'amount' => $totalUsd,
            'currency' => 'USD', 'verified_at' => $now,
            'amount_minor' => $usdCents, 'amount_usd_cents' => $usdCents,
            'fx_rate_usd_ngn' => $rate, 'fx_backfilled' => 0,
        ]);
    }
    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ($e->getMessage() === 'insufficient_credit') mam_fail('insufficient_credit', 402);
    mam_log('error', 'store_redeem_failed', ['ref' => $ref, 'err' => $e->getMessage()]);
    mam_fail('redeem_failed', 500);
}

// Side-effects (stock decrement, digital delivery, receipt email) — same path
// the card webhooks use. Outside the money transaction; best-effort.
mam_payment_settle_store($ref);
mam_audit('store_redeem', 'store_order', (int)$order['id'], ['ref' => $ref, 'amount_usd' => $totalUsd]);

mam_ok([
    'ok'          => true,
    'reference'   => $ref,
    'paid_usd'    => round($totalUsd, 2),
    'balance_usd' => round(mam_wallet_balance($uid) / 100, 2),
]);
