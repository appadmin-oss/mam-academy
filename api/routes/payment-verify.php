<?php
/**
 * POST /api/payment/verify
 * Body: { reference: "MAM-…", provider: "paystack" | "flutterwave", transaction_id?: int }
 * Verifies the payment server-side and marks the application paid.
 * Idempotent: re-verification of an already-paid payment returns success.
 */
declare(strict_types=1);

$in = mam_json_input();
$ref = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($in['reference'] ?? ''));
$provider = strtolower(trim((string)($in['provider'] ?? '')));
$txId = isset($in['transaction_id']) ? (int)$in['transaction_id'] : 0;

$payment = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$ref]);
if (!$payment) mam_fail('payment_not_found', 404);

// Resolve the "subject" of this payment based on its purpose. Store payments
// have no application_id — they're tied to a store_orders row, looked up by
// the same payment reference.
$purpose = $payment['purpose'] ?? 'tuition';
$app = null;
$order = null;
if ($purpose === 'store') {
    $order = mam_db_row('SELECT * FROM store_orders WHERE reference = ?', [$ref]);
    if (!$order) mam_fail('order_not_found', 404);
} elseif ($purpose === 'fee') {
    // A fee invoice paid online: the subject is the invoice, and there is no
    // application. Settlement checks it; a missing one is logged, not a 404 —
    // the gateway already has the money.
} else {
    $app = mam_db_row('SELECT * FROM applications WHERE id = ?', [$payment['application_id']]);
    if (!$app) mam_fail('application_not_found', 404);
}

if ($payment['status'] === 'succeeded') {
    mam_ok(['status' => 'succeeded', 'reference' => $ref, 'already_verified' => true]);
}

try {
    if ($provider === 'paystack') {
        $resp = mam_paystack_verify($ref);
        $data = $resp['data']['data'] ?? null;
        $status = $data['status'] ?? null;
        // CRITICAL: the inline popup sets the charge amount client-side, so we
        // MUST confirm the amount actually paid (kobo) is at least what's owed
        // and in the right currency. Without this, a tampered popup could pay
        // a token amount and still be marked "paid".
        $paidKobo     = (float)($data['amount'] ?? 0);
        $expectedKobo = (float)$payment['amount'] * 100;
        $ok = ($status === 'success' && mam_payment_amount_ok($payment, $paidKobo, (string)($data['currency'] ?? 'NGN')));
        if ($status === 'success' && !$ok) {
            mam_log('warn', 'paystack_amount_or_currency_mismatch', ['ref' => $ref, 'paid_kobo' => $paidKobo, 'expected_kobo' => $expectedKobo, 'currency' => $data['currency'] ?? '']);
        }
        $providerRef = $data['reference'] ?? $ref;
    } elseif ($provider === 'flutterwave') {
        $resp = $txId > 0 ? mam_flw_verify_by_id($txId) : mam_flw_verify_by_tx_ref($ref);
        $data = $resp['data']['data'] ?? null;
        $status = $data['status'] ?? null;
        // Confirm amount AND currency — guard against a tampered inline amount.
        $currencyOk = strtoupper((string)($data['currency'] ?? 'NGN')) === 'NGN';
        // Bind the verified transaction to THIS payment WITHOUT being brittle.
        // `transaction_id` is client-supplied, so `verify_by_id` can return ANY
        // successful charge — but we must not hard-fail a genuine verification
        // just because a provider omits/normalises tx_ref. So reject ONLY the
        // replay attack: a verified tx_ref that names a DIFFERENT, real payment
        // (pay once → settle someone else's obligation). A matching, empty, or
        // unknown tx_ref is not a cross-payment replay and proceeds normally.
        $verifiedRef = (string)($data['tx_ref'] ?? '');
        $crossBound  = $verifiedRef !== ''
            && strcasecmp($verifiedRef, $ref) !== 0
            && mam_db_row('SELECT 1 FROM payments WHERE reference = ?', [$verifiedRef]) !== null;
        $ok = ($status === 'successful' && $currencyOk && !$crossBound
               && mam_payment_amount_ok($payment, (float)($data['amount'] ?? 0) * 100, (string)($data['currency'] ?? 'NGN')));
        if ($status === 'successful' && !$ok) {
            mam_log('warn', 'flw_amount_or_currency_mismatch', ['ref' => $ref, 'paid' => $data['amount'] ?? 0, 'expected' => $payment['amount'], 'currency' => $data['currency'] ?? '', 'tx_ref' => $verifiedRef, 'cross_bound' => $crossBound]);
        }
        $providerRef = $data['tx_ref'] ?? ($data['flw_ref'] ?? $ref);
    } else {
        mam_fail('unsupported_provider', 400);
    }
} catch (Throwable $e) {
    mam_log('error', 'verify_exception', ['err' => $e->getMessage(), 'ref' => $ref]);
    mam_fail('upstream_error', 502, ['message' => $e->getMessage()]);
}

if (!$ok) {
    mam_db_update('payments', [
        'status' => 'failed',
        'provider_response' => json_encode($data),
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['reference' => $ref]);
    mam_audit('verify_failed', 'payment', null, ['ref' => $ref, 'status' => $status]);
    mam_fail('verification_failed', 402, ['provider_status' => $status]);
}

// Settle via the shared, purpose-aware, race-safe path used by the webhooks too,
// so the interactive verify and the asynchronous webhook can never diverge.
$settled = mam_payment_settle($payment, $data, json_encode($data), $providerRef ?? $ref);

mam_ok([
    'status'      => 'succeeded',
    'reference'   => $ref,
    'provider'    => $provider,
    'verified_at' => date('c'),
    'portal'      => $settled['portal'] ?? null,
]);
