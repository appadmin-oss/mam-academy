<?php
/**
 * POST /api/payment/verify
 * Body: { reference: "MAM-…", provider: "paystack" | "flutterwave", transaction_id?: int }
 * Verifies the payment server-side and marks the application paid.
 * Idempotent: re-verification of an already-paid payment returns success.
 *
 * `reference` may be the payment's own reference or one of its Paystack attempt
 * references (payment_attempts, schema v106): a retry after closing the checkout
 * charges under a fresh reference, and every one resolves to the same payment.
 * The Paystack decision is mam_paystack_apply_tx() (payment-guards.php), the one
 * the webhook and the reconcile sweep make too.
 */
declare(strict_types=1);

$in = mam_json_input();
$ref = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($in['reference'] ?? ''));
$provider = strtolower(trim((string)($in['provider'] ?? '')));
$txId = isset($in['transaction_id']) ? (int)$in['transaction_id'] : 0;

$payment = mam_payment_resolve_ref($ref);
if (!$payment) mam_fail('payment_not_found', 404);
$chargedRef = $ref;                          // the reference the gateway knows the charge by
$ref = (string) $payment['reference'];       // the payment's own reference: subjects are keyed by it

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
    // Already settled. A verify for a DIFFERENT attempt than the one that settled
    // it means the family may have paid twice: check that charge, so a second
    // payment is recorded as refund due rather than silently absorbed.
    $settledBy = (string) ($payment['provider_reference'] ?? '');
    if ($provider === 'paystack' && $chargedRef !== $ref && $settledBy !== '' && $chargedRef !== $settledBy) {
        try {
            $tx = mam_paystack_verify($chargedRef)['data']['data'] ?? null;
            if (is_array($tx)) mam_paystack_apply_tx($payment, $tx, 'verify');
        } catch (Throwable $e) {
            mam_log('warn', 'verify_second_attempt_check_failed', ['ref' => $ref, 'charged' => $chargedRef, 'err' => $e->getMessage()]);
        }
    }
    mam_ok(['status' => 'succeeded', 'reference' => $ref, 'already_verified' => true]);
}

/* ── Paystack: the shared decision (guards 1, 2, 4 and 8) ── */
if ($provider === 'paystack') {
    try {
        $resp = mam_paystack_verify($chargedRef);
    } catch (Throwable $e) {
        mam_log('error', 'verify_exception', ['err' => $e->getMessage(), 'ref' => $ref]);
        mam_fail('upstream_error', 502, ['message' => 'We could not check this payment just now. If you were charged, it will be confirmed automatically.']);
    }
    $data = $resp['data']['data'] ?? null;
    $r = is_array($data) ? mam_paystack_apply_tx($payment, $data, 'verify') : ['outcome' => 'pending', 'reason' => 'no_transaction', 'settle' => null];

    // A charge still in flight has not FAILED. Writing 'failed' here let anybody
    // holding a reference mark a live payment failed; only a finished charge that
    // does not match is recorded as failed, below.
    if ($r['outcome'] === 'pending') {
        mam_fail('payment_pending', 409, ['provider_status' => $data['status'] ?? null, 'message' => 'This payment is not complete yet. It will be confirmed automatically once it is.']);
    }
    if ($r['outcome'] === 'refund_due') {
        mam_fail('target_closed', 409, ['message' => 'Your payment arrived after this ' . ($purpose === 'store' ? 'order' : 'application') . ' was closed, so nothing was charged to it. The academy will refund you.']);
    }
    if ($r['outcome'] === 'refused') {
        // A test charge on a live site (or the reverse), or a charge under some other
        // payment's reference, says nothing about THIS payment: refuse it, record
        // nothing as failed. A finished charge that failed, or paid short or in the
        // wrong currency, is recorded as failed.
        if (!in_array($r['reason'], ['mode_mismatch', 'reference_mismatch'], true)) {
            mam_db_update('payments', [
                'status' => 'failed',
                'provider_response' => json_encode(mam_paystack_redact($data)),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['reference' => $ref]);
            if ($r['reason'] === 'amount_or_currency') {
                mam_log('warn', 'paystack_amount_or_currency_mismatch', ['ref' => $ref, 'paid_kobo' => $data['amount'] ?? 0, 'expected_kobo' => $payment['amount_minor'] ?? null, 'currency' => $data['currency'] ?? '']);
            }
        }
        mam_audit('verify_failed', 'payment', (int) $payment['id'], ['ref' => $ref, 'charged' => $chargedRef, 'reason' => $r['reason']]);
        mam_fail('verification_failed', 402, ['provider_status' => $data['status'] ?? null, 'reason' => $r['reason']]);
    }
    mam_ok([
        'status'          => 'succeeded',
        'reference'       => $ref,
        'provider'        => 'paystack',
        'verified_at'     => date('c'),
        'portal'          => $r['settle']['portal'] ?? null,
        'already_verified'=> $r['outcome'] === 'already',
        'duplicate_charge'=> $r['outcome'] === 'duplicate_charge',
    ]);
}

try {
    if ($provider === 'flutterwave') {
        $resp = $txId > 0 ? mam_flw_verify_by_id($txId) : mam_flw_verify_by_tx_ref($chargedRef);
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
    mam_fail('upstream_error', 502, ['message' => 'We could not check this payment just now. If you were charged, it will be confirmed automatically.']);
}

// Only a terminal answer, or a success that does not match, is recorded as failed.
$terminal = in_array(strtolower((string) $status), ['failed', 'reversed', 'success', 'successful'], true);
if (!$ok && !$terminal) {
    mam_fail('payment_pending', 409, ['provider_status' => $status, 'message' => 'This payment is not complete yet. It will be confirmed automatically once it is.']);
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
if (!empty($settled['refund_due'])) {
    mam_fail('target_closed', 409, ['message' => 'Your payment arrived after this was closed, so nothing was charged to it. The academy will refund you.']);
}

mam_ok([
    'status'      => 'succeeded',
    'reference'   => $ref,
    'provider'    => $provider,
    'verified_at' => date('c'),
    'portal'      => $settled['portal'] ?? null,
]);
