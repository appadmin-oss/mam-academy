<?php
/**
 * POST /api/payment/verify
 * Body: { reference: "MAM-…", provider: "paystack" | "flutterwave", transaction_id?: int }
 * Verifies the payment server-side and marks the application paid.
 * Idempotent: re-verification of an already-paid payment returns success.
 */
declare(strict_types=1);

mam_require_method('POST');
mam_csrf_require();
mam_rate_limit('payment_verify', mam_client_ip(), 30, 600);

$in = mam_json_input();
$ref = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($in['reference'] ?? ''));
$provider = strtolower(trim((string)($in['provider'] ?? '')));
$txId = isset($in['transaction_id']) ? (int)$in['transaction_id'] : 0;

$payment = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$ref]);
if (!$payment) mam_fail('payment_not_found', 404);
$app = mam_db_row('SELECT * FROM applications WHERE id = ?', [$payment['application_id']]);
if (!$app) mam_fail('application_not_found', 404);

if ($payment['status'] === 'succeeded') {
    mam_ok(['status' => 'succeeded', 'reference' => $ref, 'already_verified' => true]);
}

try {
    if ($provider === 'paystack') {
        $resp = mam_paystack_verify($ref);
        $data = $resp['data']['data'] ?? null;
        $status = $data['status'] ?? null;
        $ok = ($status === 'success');
        $providerRef = $data['reference'] ?? $ref;
    } elseif ($provider === 'flutterwave') {
        $resp = $txId > 0 ? mam_flw_verify_by_id($txId) : mam_flw_verify_by_tx_ref($ref);
        $data = $resp['data']['data'] ?? null;
        $status = $data['status'] ?? null;
        $ok = ($status === 'successful' && (float)($data['amount'] ?? 0) >= (float)$payment['amount']);
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

mam_db_update('payments', [
    'status'             => 'succeeded',
    'provider_reference' => $providerRef ?? null,
    'provider_response'  => json_encode($data),
    'verified_at'        => date('Y-m-d H:i:s'),
    'updated_at'         => date('Y-m-d H:i:s'),
], ['reference' => $ref]);

mam_db_update('applications', ['status' => 'paid', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $payment['application_id']]);
mam_audit('verify_succeeded', 'payment', (int)$payment['id'], ['ref' => $ref, 'provider' => $provider]);

mam_ok([
    'status' => 'succeeded',
    'reference' => $ref,
    'provider' => $provider,
    'verified_at' => date('c'),
]);
