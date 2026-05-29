<?php
/**
 * POST /api/payment/initialize
 *
 * Body JSON:
 *   { reference: "MAM-…", provider: "paystack" | "flutterwave" | "bank",
 *     purpose: "booking_fee" | "tuition"   (default: "booking_fee") }
 *
 * purpose=booking_fee — the public flow used by /book. Charges a flat $5 that
 *   covers registration, ID card, student file and the 30-minute MAM Auditer
 *   session. On success, payment-verify.php stamps booking_fee_paid_at and
 *   unlocks the Google appointment embed for the applicant.
 * purpose=tuition   — legacy / future portal use. Charges the application's
 *   computed total. Untouched here so the portal tuition flow stays trivial.
 *
 * Each purpose uses a distinct payment reference (App-ref vs App-ref + "-BK")
 * so the payments table's UNIQUE(reference) lets booking + tuition coexist for
 * the same application without colliding.
 */
declare(strict_types=1);

$in = mam_json_input();
$ref = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($in['reference'] ?? ''));
$provider = strtolower(trim((string)($in['provider'] ?? '')));
$purpose  = strtolower(trim((string)($in['purpose']  ?? 'booking_fee')));
if (!in_array($purpose, ['booking_fee', 'tuition'], true)) $purpose = 'booking_fee';

$app = mam_db_row('SELECT a.*, c.email AS email, c.first_name, c.last_name, c.phone
                    FROM applications a JOIN customers c ON c.id = a.customer_id
                    WHERE a.reference = ?', [$ref]);
if (!$app) mam_fail('application_not_found', 404);

// Don't restart a charge that's already settled for THIS purpose.
$payRef = $purpose === 'booking_fee' ? ($ref . '-BK') : $ref;
if ($purpose === 'booking_fee' && !empty($app['booking_fee_paid_at'])) {
    mam_ok(['already_paid' => true, 'purpose' => $purpose, 'reference' => $payRef]);
}
if ($purpose === 'tuition' && ($app['status'] ?? '') === 'paid') {
    mam_ok(['already_paid' => true, 'purpose' => $purpose, 'reference' => $payRef]);
}

// Pricing: booking_fee is the single source of truth, $5 flat. Tuition reads
// the server-side total previously computed for the application.
if ($purpose === 'booking_fee') {
    $totalUsd = 5.00;
} else {
    $totalUsd = (float)$app['total_usd'];
}
if ($totalUsd <= 0) mam_fail('zero_amount', 400);

$ngnRate = (float)mam_env('USD_TO_NGN', '1500');
$amountNgn = (int)round($totalUsd * $ngnRate);
$amountKobo = $amountNgn * 100;

// Persist a payment row (idempotent via the per-purpose reference)
$existing = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$payRef]);
if (!$existing) {
    mam_db_insert('payments', [
        'application_id' => (int)$app['id'],
        'reference'      => $payRef,
        'idempotency_key'=> $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null,
        'provider'       => $provider,
        'purpose'        => $purpose,
        'status'         => 'pending',
        'amount'         => $amountNgn,
        'currency'       => 'NGN',
    ]);
} else {
    mam_db_update('payments', ['provider' => $provider, 'updated_at' => date('Y-m-d H:i:s')], ['reference' => $payRef]);
}
// From here on the rest of the file treats $payRef as the payment reference.
$ref = $payRef;

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
