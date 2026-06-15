<?php
/**
 * Payment settlement — the SINGLE source of truth for what a successful charge
 * does. Both the interactive verify endpoint and the provider webhooks call
 * mam_payment_settle() so they can never diverge (previously the webhooks blindly
 * set applications.status='paid' for every charge — wrong for booking fees, and
 * a no-op that lost store orders).
 *
 * Effects are purpose-specific:
 *   store       → mark order paid/delivered, decrement stock, email the receipt
 *   booking_fee → stamp booking_fee_paid_at, email the booking confirmation
 *                 (NEVER marks the application paid — admin enrols after Auditer)
 *   tuition     → status='paid', provision the student portal + email credentials
 *
 * Race-safe + idempotent: the succeeded flip is a conditional UPDATE guarded on
 * status<>'succeeded', so whichever of verify/webhook lands first wins and the
 * other no-ops (no double emails). Never throws — email/provision failures are
 * logged, not fatal.
 */
declare(strict_types=1);

/**
 * @param array       $payment      the payments row (must have reference, purpose, application_id, amount)
 * @param array|null  $providerData the verified provider payload (for storage)
 * @param string      $rawResponse  raw provider response JSON (for audit)
 * @param string|null $providerRef  the provider's own reference
 * @return array ['ok'=>bool,'purpose'=>string,'already'=>bool,'portal'=>?string]
 */
function mam_payment_settle(array $payment, ?array $providerData, string $rawResponse, ?string $providerRef = null): array {
    $ref     = (string)$payment['reference'];
    $purpose = (string)($payment['purpose'] ?? 'tuition');
    $out = ['ok' => true, 'purpose' => $purpose, 'already' => false, 'portal' => null];

    // Atomic, race-safe claim: only one caller flips pending→succeeded.
    try {
        $st = mam_db()->prepare(
            "UPDATE payments SET status='succeeded', provider_reference=?, provider_response=?, verified_at=?, updated_at=? WHERE reference=? AND status<>'succeeded'"
        );
        $now = date('Y-m-d H:i:s');
        $st->execute([$providerRef, $rawResponse, $now, $now, $ref]);
        if ($st->rowCount() === 0) { $out['already'] = true; return $out; } // someone else settled it
    } catch (\Throwable $e) {
        mam_log('error', 'settle_claim_failed', ['ref' => $ref, 'err' => $e->getMessage()]);
        $out['ok'] = false; return $out;
    }

    mam_audit('payment_settled', 'payment', (int)($payment['id'] ?? 0), ['ref' => $ref, 'purpose' => $purpose]);

    if ($purpose === 'store') {
        mam_payment_settle_store($ref);
    } elseif ($purpose === 'booking_fee') {
        mam_payment_settle_booking((int)$payment['application_id']);
    } else { // tuition
        $out['portal'] = mam_payment_settle_tuition((int)$payment['application_id']);
    }
    return $out;
}

/** Store: mark the order paid/delivered, decrement stock once, email the receipt. */
function mam_payment_settle_store(string $ref): void {
    $order = mam_db_row('SELECT * FROM store_orders WHERE reference = ?', [$ref]);
    if (!$order) { mam_log('warn', 'settle_store_no_order', ['ref' => $ref]); return; }
    $items = json_decode((string)$order['items_json'], true) ?: [];
    $hasPhysical = false;
    foreach ($items as $i) if (($i['goods_type'] ?? '') === 'physical') { $hasPhysical = true; break; }
    mam_db_update('store_orders', [
        'status'       => $hasPhysical ? 'paid' : 'delivered',
        'delivered_at' => $hasPhysical ? null : date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ], ['id' => (int)$order['id']]);
    foreach ($items as $i) {
        $sku = (string)($i['sku'] ?? '');
        $qty = max(1, (int)($i['qty'] ?? 1));
        if ($sku === '') continue;
        try {
            mam_db()->prepare(
                "UPDATE store_items SET stock = stock - ?,
                        availability = CASE WHEN stock - ? <= 0 THEN 'sold_out' ELSE availability END,
                        updated_at = ?
                  WHERE sku = ? AND stock >= 0 AND stock >= ?"
            )->execute([$qty, $qty, date('Y-m-d H:i:s'), $sku, $qty]);
        } catch (\Throwable $e) { mam_log('warn', 'store_stock_decrement_failed', ['sku' => $sku, 'err' => $e->getMessage()]); }
    }
    $links = [];
    foreach ($items as $i) {
        if (($i['goods_type'] ?? '') !== 'digital') continue;
        $r = mam_db_row('SELECT name, file_url FROM store_items WHERE sku = ?', [(string)$i['sku']]);
        if ($r && !empty($r['file_url'])) $links[] = ['name' => $r['name'], 'url' => (string)$r['file_url']];
    }
    try {
        mam_send((string)$order['customer_email'], 'MAM Academy Store — Order ' . $order['reference'] . ' confirmed',
            mam_email_store_receipt($order, $items, $links), 'store_order_confirmed', true);
    } catch (\Throwable $e) { mam_log('warn', 'store_email_failed', ['err' => $e->getMessage()]); }
}

/** Booking fee: stamp the paid time + email the confirmation. Does NOT enrol. */
function mam_payment_settle_booking(int $appId): void {
    if ($appId <= 0) return;
    mam_db_update('applications', ['booking_fee_paid_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $appId]);
    try {
        $app = mam_db_row('SELECT * FROM applications WHERE id = ?', [$appId]);
        $customer = $app ? mam_db_row('SELECT * FROM customers WHERE id = ?', [(int)$app['customer_id']]) : null;
        if ($customer && !empty($customer['email'])) {
            mam_send((string)$customer['email'], 'Your MAM Academy seat is reserved — pick your time',
                mam_email_booking_confirmed($app, $customer), 'booking_confirmed', true);
        }
    } catch (\Throwable $e) { mam_log('warn', 'booking_email_failed', ['err' => $e->getMessage()]); }
}

/** Tuition: mark paid, provision the student portal, email credentials. Returns a notice tag. */
function mam_payment_settle_tuition(int $appId): ?string {
    if ($appId <= 0) return null;
    mam_db_update('applications', ['status' => 'paid', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $appId]);
    $app = mam_db_row('SELECT * FROM applications WHERE id = ?', [$appId]);
    if (!$app || !$app['customer_id']) return null;
    try {
        $provision = mam_ensure_student_user((int)$app['customer_id']);
        if (empty($provision['password'])) return 'portal_already_provisioned';
        $customer = mam_db_row('SELECT * FROM customers WHERE id = ?', [(int)$app['customer_id']]);
        $loginUrl = rtrim((string)mam_env('APP_URL', ''), '/') . '/portal/login';
        $name = htmlspecialchars(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')));
        $inner = "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>Welcome, $name</h2>"
              . "<p style='color:rgba(255,255,255,0.86);margin:0'>Your student portal is now active. Sign in to view your applications, classes and MAM Auditer placement.</p>"
              . "<div style='background:#251A10;border:1px dashed #FBBF24;border-radius:10px;padding:14px 18px;font-family:monospace;font-size:14px;color:#FBBF24;margin:16px 0'>"
              . "<div>Email: <strong>" . htmlspecialchars((string)$customer['email']) . "</strong></div>"
              . "<div>Temporary password: <strong>" . htmlspecialchars((string)$provision['password']) . "</strong></div></div>"
              . "<p style='color:rgba(255,255,255,0.86);margin:0'>Please change this password on first sign-in.</p>"
              . mam_email_button($loginUrl, 'Open student portal');
        mam_send((string)$customer['email'], 'Your MAM Academy student portal is ready',
            mam_email_layout(['header' => 'MAM ACADEMY', 'preheader' => 'Your MAM Academy student portal is ready — sign-in details inside.',
                'reason' => 'You received this because your payment activated a MAM Academy student portal account.', 'body' => $inner]),
            'portal_welcome', true);
        return 'portal_credentials_emailed';
    } catch (\Throwable $e) {
        mam_log('warn', 'student_provision_failed', ['app' => $appId, 'err' => $e->getMessage()]);
        return null;
    }
}
