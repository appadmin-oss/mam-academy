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
if (!in_array($purpose, ['booking_fee', 'tuition', 'store'], true)) $purpose = 'booking_fee';

// Resolve the "subject" record (application OR store order) based on purpose.
// Each purpose uses a distinct payment reference so multiple charges (booking
// + tuition + store) can coexist for the same customer/application without
// colliding on the payments.reference UNIQUE.
if ($purpose === 'store') {
    $order = mam_db_row('SELECT * FROM store_orders WHERE reference = ?', [$ref]);
    if (!$order) mam_fail('order_not_found', 404);
    if (($order['status'] ?? '') === 'paid') {
        mam_ok(['already_paid' => true, 'purpose' => 'store', 'reference' => $ref]);
    }
    $payRef = $ref;
    $app = null; // not academy-bound
    // Customer details for the provider payload come from the order row.
    $customerEmail = (string)$order['customer_email'];
    $customerPhone = (string)$order['customer_phone'];
    $customerName  = (string)$order['customer_name'];
} else {
    $app = mam_db_row('SELECT a.*, c.email AS email, c.first_name, c.last_name, c.phone
                        FROM applications a JOIN customers c ON c.id = a.customer_id
                        WHERE a.reference = ?', [$ref]);
    if (!$app) mam_fail('application_not_found', 404);
    $payRef = $purpose === 'booking_fee' ? ($ref . '-BK') : $ref;
    if ($purpose === 'booking_fee' && !empty($app['booking_fee_paid_at'])) {
        mam_ok(['already_paid' => true, 'purpose' => $purpose, 'reference' => $payRef]);
    }
    // MAM Auditer is once-until-expiration (valid 1 year; enrolled students
    // exempt). If this customer already holds a valid placement — even from a
    // different application — don't let them pay for a redundant assessment.
    if ($purpose === 'booking_fee') {
        $aud = mam_auditer_status((int)$app['customer_id']);
        if (!$aud['can_book']) {
            mam_ok([
                'already_valid' => true,
                'purpose'       => $purpose,
                'reference'     => $payRef,
                'auditer'       => $aud,
                'message'       => mam_auditer_message($aud),
            ]);
        }
    }
    if ($purpose === 'tuition' && ($app['status'] ?? '') === 'paid') {
        mam_ok(['already_paid' => true, 'purpose' => $purpose, 'reference' => $payRef]);
    }
    $customerEmail = (string)$app['email'];
    $customerPhone = (string)$app['phone'];
    $customerName  = trim($app['first_name'] . ' ' . $app['last_name']);
}

// Pricing source-of-truth: booking_fee reads the regional tier (server is
// authoritative — never trust client-side price). Tuition continues to read
// the server-computed total. We always settle to NGN on Paystack/Flutterwave
// (Paystack supports only a few currencies), and surface BOTH the "local"
// display amount and the NGN settlement amount so the UI can disclose it.
$ngnRate = mam_usd_to_ngn_rate();
if ($purpose === 'booking_fee') {
    $tier = mam_booking_tier();
    $totalUsd      = (float)$tier['amount_usd'];
    $amountNgn     = (int)$tier['amount_ngn'];
    $displayLabel  = (string)$tier['label'];
    $displayCur    = (string)$tier['currency'];
    $displayAmount = (float)$tier['amount'];
} elseif ($purpose === 'store') {
    $totalUsd = (float)$order['total_usd'];
    $amountNgn = (int)round($totalUsd * $ngnRate);
    $displayLabel = '$' . number_format($totalUsd, 2);
    $displayCur = 'USD';
    $displayAmount = $totalUsd;
} else {
    $totalUsd = (float)$app['total_usd'];
    $amountNgn = (int)round($totalUsd * $ngnRate);
    $displayLabel = '$' . number_format($totalUsd, 2);
    $displayCur = 'USD';
    $displayAmount = $totalUsd;
}
if ($totalUsd <= 0) mam_fail('zero_amount', 400);
$amountKobo = $amountNgn * 100;

// Persist a payment row (idempotent via the per-purpose reference). Store
// orders aren't tied to an application, so application_id stays NULL there.
$existing = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$payRef]);
if (!$existing) {
    mam_db_insert('payments', [
        'application_id' => $app ? (int)$app['id'] : null,
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
$ref = $payRef;

$metadata = $app
    ? [
        'application_id' => (int)$app['id'],
        'reference'      => $ref,
        'pathway'        => $app['pathway'],
        'mode'           => $app['mode'],
        'discipline'     => $app['discipline'],
    ]
    : [
        'order_id'  => (int)$order['id'],
        'reference' => $ref,
        'purpose'   => 'store',
    ];

try {
    if ($provider === 'paystack') {
        $resp = mam_paystack_initialize($customerEmail, $amountKobo, $ref, $metadata);
        $body = $resp['data'];
        if (!($body['status'] ?? false)) mam_fail('paystack_init_failed', 502, ['provider' => $body]);
        mam_audit('init', 'payment', null, ['ref' => $ref, 'provider' => $provider]);
        mam_ok([
            'provider' => 'paystack',
            'reference' => $ref,
            'amount_ngn'   => $amountNgn,
            'amount_usd'   => $totalUsd,
            'display_label'    => $displayLabel,
            'display_currency' => $displayCur,
            'display_amount'   => $displayAmount,
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
            'amount_ngn'   => $amountNgn,
            'amount_usd'   => $totalUsd,
            'display_label'    => $displayLabel,
            'display_currency' => $displayCur,
            'display_amount'   => $displayAmount,
            'public_key' => mam_env('FLW_PUBLIC_KEY'),
            'payload' => [
                'tx_ref'       => $ref,
                'amount'       => $amountNgn,
                'currency'     => 'NGN',
                // Email only: this endpoint is public (reference = capability),
                // so don't echo the applicant's name/phone back to the caller —
                // Flutterwave accepts an email-only customer object.
                'customer'     => [
                    'email'        => $customerEmail,
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
            'amount_ngn'   => $amountNgn,
            'amount_usd'   => $totalUsd,
            'display_label'    => $displayLabel,
            'display_currency' => $displayCur,
            'display_amount'   => $displayAmount,
            'bank'         => mam_bank_details(),
            'instructions' => mam_bank_instructions($ref, $displayLabel),
        ]);
    } else {
        mam_fail('unsupported_provider', 400, ['provider' => $provider]);
    }
} catch (Throwable $e) {
    mam_log('error', 'payment_init_failed', ['err' => $e->getMessage(), 'ref' => $ref, 'provider' => $provider]);
    mam_fail('init_failed', 502, ['message' => $e->getMessage()]);
}
