<?php
/**
 * POST /api/payment/initialize
 *
 * Body JSON:
 *   { reference: "MAM-…", provider: "paystack" | "flutterwave" | "bank" }
 *
 * For Paystack: returns the authorization_url + access_code (recommended)
 *   AND/OR keeps the existing inline-popup flow (the frontend can choose).
 *
 * For Flutterwave: returns the FlutterwaveCheckout payload (the frontend
 *   opens the SDK; we record the tx_ref server-side for verification).
 *
 * For bank: records intent + returns bank instructions.
 */
declare(strict_types=1);

mam_require_method('POST');
mam_csrf_require();
mam_rate_limit('payment_init', mam_client_ip(), 20, 600);

$in = mam_json_input();
$ref = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($in['reference'] ?? ''));
$provider = strtolower(trim((string)($in['provider'] ?? '')));

$app = mam_db_row('SELECT a.*, c.email AS email, c.first_name, c.last_name, c.phone
                    FROM applications a JOIN customers c ON c.id = a.customer_id
                    WHERE a.reference = ?', [$ref]);
if (!$app) mam_fail('application_not_found', 404);

$totalUsd = (float)$app['total_usd'];
if ($totalUsd <= 0) mam_fail('zero_amount', 400);

$ngnRate = (float)mam_env('USD_TO_NGN', '1500');
$amountNgn = (int)round($totalUsd * $ngnRate);
$amountKobo = $amountNgn * 100;

// Persist a payment row (idempotent via reference)
$existing = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$ref]);
if (!$existing) {
    mam_db_insert('payments', [
        'application_id' => (int)$app['id'],
        'reference'      => $ref,
        'idempotency_key'=> $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null,
        'provider'       => $provider,
        'status'         => 'pending',
        'amount'         => $amountNgn,
        'currency'       => 'NGN',
    ]);
} else {
    // Update provider in case it changed (e.g. user picked Flutterwave then switched to Paystack)
    mam_db_update('payments', ['provider' => $provider, 'updated_at' => date('Y-m-d H:i:s')], ['reference' => $ref]);
}

$metadata = [
    'application_id' => (int)$app['id'],
    'reference'      => $ref,
    'pathway'        => $app['pathway'],
    'mode'           => $app['mode'],
    'discipline'     => $app['discipline'],
];

try {
    if ($provider === 'paystack') {
        $resp = mam_paystack_initialize($app['email'], $amountKobo, $ref, $metadata);
        $body = $resp['data'];
        if (!($body['status'] ?? false)) mam_fail('paystack_init_failed', 502, ['provider' => $body]);
        mam_audit('init', 'payment', null, ['ref' => $ref, 'provider' => $provider]);
        mam_ok([
            'provider' => 'paystack',
            'reference' => $ref,
            'amount_ngn' => $amountNgn,
            'amount_usd' => $totalUsd,
            'public_key' => mam_env('PAYSTACK_PUBLIC_KEY'),
            'access_code' => $body['data']['access_code'] ?? null,
            'authorization_url' => $body['data']['authorization_url'] ?? null,
        ]);
    } elseif ($provider === 'flutterwave') {
        // Flutterwave inline: build the payload the frontend will hand
        // to FlutterwaveCheckout(). We just persist tx_ref here so the
        // webhook + verify endpoints can reconcile.
        mam_audit('init', 'payment', null, ['ref' => $ref, 'provider' => $provider]);
        mam_ok([
            'provider' => 'flutterwave',
            'reference' => $ref,
            'amount_ngn' => $amountNgn,
            'amount_usd' => $totalUsd,
            'public_key' => mam_env('FLW_PUBLIC_KEY'),
            'payload' => [
                'tx_ref'       => $ref,
                'amount'       => $amountNgn,
                'currency'     => 'NGN',
                'customer'     => [
                    'email'        => $app['email'],
                    'phone_number' => $app['phone'],
                    'name'         => trim($app['first_name'] . ' ' . $app['last_name']),
                ],
                'customizations' => [
                    'title'       => 'MAM Academy',
                    'description' => 'Application ' . $ref,
                    'logo'        => rtrim((string)mam_env('APP_URL', ''), '/') . '/mam-academy-logo.svg',
                ],
                'meta' => $metadata,
            ],
        ]);
    } elseif ($provider === 'bank') {
        mam_db_update('payments', ['status' => 'pending_bank_transfer'], ['reference' => $ref]);
        mam_audit('init', 'payment', null, ['ref' => $ref, 'provider' => $provider]);
        mam_ok([
            'provider' => 'bank',
            'reference' => $ref,
            'amount_ngn' => $amountNgn,
            'amount_usd' => $totalUsd,
            'instructions' => 'Email proof of payment to ' . mam_env('NOTIFY_EMAIL', 'connect@mamcentre.org') . ' with reference ' . $ref,
        ]);
    } else {
        mam_fail('unsupported_provider', 400, ['provider' => $provider]);
    }
} catch (Throwable $e) {
    mam_log('error', 'payment_init_failed', ['err' => $e->getMessage(), 'ref' => $ref, 'provider' => $provider]);
    mam_fail('init_failed', 502, ['message' => $e->getMessage()]);
}
