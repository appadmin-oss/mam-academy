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
        $currencyOk   = strtoupper((string)($data['currency'] ?? 'NGN')) === 'NGN';
        $ok = ($status === 'success' && $currencyOk && $paidKobo >= $expectedKobo);
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
        $ok = ($status === 'successful' && $currencyOk && (float)($data['amount'] ?? 0) >= (float)$payment['amount']);
        if ($status === 'successful' && !$ok) {
            mam_log('warn', 'flw_amount_or_currency_mismatch', ['ref' => $ref, 'paid' => $data['amount'] ?? 0, 'expected' => $payment['amount'], 'currency' => $data['currency'] ?? '']);
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

mam_db_update('payments', [
    'status'             => 'succeeded',
    'provider_reference' => $providerRef ?? null,
    'provider_response'  => json_encode($data),
    'verified_at'        => date('Y-m-d H:i:s'),
    'updated_at'         => date('Y-m-d H:i:s'),
], ['reference' => $ref]);

// Booking-fee path: this is now the default flow. The $5 is a confirmation of
// intent + registration / ID card / file / 30-min Auditer slot — it does NOT
// pay tuition or enroll the student. We stamp booking_fee_paid_at so /book
// can unlock the Google appointment embed, but leave application.status alone
// (the admin marks it 'paid' / 'enrolled' after the Auditer session).
if ($purpose === 'store') {
    // Mark the order paid + ship/deliver. For digital items we email the
    // file URLs immediately (instant delivery). Physical orders are flipped
    // to 'paid' (admin fulfils + flips to 'shipped' from the dashboard).
    $items = json_decode((string)$order['items_json'], true) ?: [];
    $hasPhysical = false;
    foreach ($items as $i) if (($i['goods_type'] ?? '') === 'physical') { $hasPhysical = true; break; }
    mam_db_update('store_orders', [
        'status'       => $hasPhysical ? 'paid' : 'delivered',
        'delivered_at' => $hasPhysical ? null : date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ], ['id' => (int)$order['id']]);
    // Decrement inventory for tracked items (stock >= 0; -1 means unlimited).
    // Runs exactly once thanks to the already-succeeded short-circuit above.
    // The WHERE stock >= qty guard means a race can never push stock negative,
    // and auto-flips an item to sold_out when it hits zero.
    foreach ($items as $i) {
        $sku = (string)($i['sku'] ?? '');
        $qty = max(1, (int)($i['qty'] ?? 1));
        if ($sku === '') continue;
        try {
            mam_db()->prepare(
                "UPDATE store_items
                    SET stock = stock - ?,
                        availability = CASE WHEN stock - ? <= 0 THEN 'sold_out' ELSE availability END,
                        updated_at = ?
                  WHERE sku = ? AND stock >= 0 AND stock >= ?"
            )->execute([$qty, $qty, date('Y-m-d H:i:s'), $sku, $qty]);
        } catch (Throwable $e) { mam_log('warn', 'store_stock_decrement_failed', ['sku' => $sku, 'err' => $e->getMessage()]); }
    }
    // Resolve digital file_urls server-side (the public endpoints strip them).
    $links = [];
    foreach ($items as $i) {
        if (($i['goods_type'] ?? '') !== 'digital') continue;
        $r = mam_db_row('SELECT name, file_url FROM store_items WHERE sku = ?', [(string)$i['sku']]);
        if ($r && !empty($r['file_url'])) $links[] = ['name' => $r['name'], 'url' => (string)$r['file_url']];
    }
    try {
        mam_send(
            (string)$order['customer_email'],
            'MAM Academy Store — Order ' . $order['reference'] . ' confirmed',
            mam_email_store_receipt($order, $items, $links),
            'store_order_confirmed',
            true
        );
    } catch (Throwable $e) { mam_log('warn', 'store_email_failed', ['err' => $e->getMessage()]); }
} elseif ($purpose === 'booking_fee') {
    mam_db_update('applications', [
        'booking_fee_paid_at' => date('Y-m-d H:i:s'),
        'updated_at'          => date('Y-m-d H:i:s'),
    ], ['id' => $payment['application_id']]);
    try {
        $customer = mam_db_row('SELECT * FROM customers WHERE id = ?', [(int)$app['customer_id']]);
        if ($customer && !empty($customer['email'])) {
            mam_send(
                $customer['email'],
                'Your MAM Academy seat is reserved — pick your time',
                mam_email_booking_confirmed($app, $customer),
                'booking_confirmed',
                true
            );
        }
    } catch (Throwable $e) { mam_log('warn', 'booking_email_failed', ['err' => $e->getMessage()]); }
} else {
    mam_db_update('applications', ['status' => 'paid', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $payment['application_id']]);
}
mam_audit('verify_succeeded', 'payment', (int)$payment['id'], ['ref' => $ref, 'provider' => $provider, 'purpose' => $purpose]);

// Auto-provision a student portal account once TUITION payment clears. The
// $5 booking fee deliberately does NOT yet create a portal account — admin
// confirms enrolment after the Auditer session.
$portalNotice = null;
try {
    if ($purpose === 'tuition' && $app['customer_id']) {
        $provision = mam_ensure_student_user((int)$app['customer_id']);
        if ($provision['password']) {
            $customer = mam_db_row('SELECT * FROM customers WHERE id = ?', [(int)$app['customer_id']]);
            $loginUrl = rtrim((string)mam_env('APP_URL', ''), '/') . '/portal/login';
            $name = htmlspecialchars(trim($customer['first_name'] . ' ' . $customer['last_name']));
            $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>Welcome, $name</h2>"
                  . "<p style='color:rgba(255,255,255,0.86);margin:0'>Your student portal is now active. Sign in to view your applications, classes and MAM Auditer placement.</p>"
                  . "<div style='background:#251A10;border:1px dashed #FBBF24;border-radius:10px;padding:14px 18px;font-family:monospace;font-size:14px;color:#FBBF24;margin:16px 0'>"
                  . "<div>Email: <strong>" . htmlspecialchars($customer['email']) . "</strong></div>"
                  . "<div>Temporary password: <strong>" . htmlspecialchars($provision['password']) . "</strong></div>"
                  . "</div>"
                  . "<p style='color:rgba(255,255,255,0.86);margin:0'>Please change this password on first sign-in.</p>"
                  . mam_email_button($loginUrl, 'Open student portal');
            $body = mam_email_layout([
                'header' => 'MAM ACADEMY',
                'preheader' => 'Your MAM Academy student portal is ready — sign-in details inside.',
                'reason' => 'You received this because your payment activated a MAM Academy student portal account.',
                'body' => $inner,
            ]);
            mam_send($customer['email'], 'Your MAM Academy student portal is ready', $body, 'portal_welcome', true);
            $portalNotice = 'portal_credentials_emailed';
        } else {
            $portalNotice = 'portal_already_provisioned';
        }
    }
} catch (Throwable $e) {
    mam_log('warn', 'student_provision_failed', ['ref' => $ref, 'err' => $e->getMessage()]);
}

mam_ok([
    'status' => 'succeeded',
    'reference' => $ref,
    'provider' => $provider,
    'verified_at' => date('c'),
    'portal' => $portalNotice,
]);
