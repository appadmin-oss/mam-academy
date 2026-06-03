<?php
/**
 * POST /api/payment/webhook/paystack
 * Receives Paystack webhooks. HMAC verified against PAYSTACK_WEBHOOK_SECRET
 * (or PAYSTACK_SECRET_KEY as fallback per Paystack docs).
 *
 * We respond 200 quickly to acknowledge; processing happens inline but
 * any failure is logged. Duplicate event IDs are dropped.
 */
declare(strict_types=1);

$raw = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
$valid = $signature !== '' && mam_paystack_verify_signature($raw, $signature);

$payload = json_decode($raw, true) ?: [];
// Stable, non-empty event id. Prefer Paystack's numeric data.id; otherwise
// synthesise from event+reference. A random suffix guarantees uniqueness when
// BOTH are absent, so a malformed/empty payload can't collide on the
// UNIQUE(provider,event_id) index and wrongly drop later legitimate events.
$eventId = (string)($payload['data']['id'] ?? '');
if ($eventId === '') {
    $ref = (string)($payload['data']['reference'] ?? '');
    $eventId = (string)($payload['event'] ?? 'event') . '-' . ($ref !== '' ? $ref : bin2hex(random_bytes(6)));
}
$eventType = (string)($payload['event'] ?? '');

try {
    mam_db_insert('webhook_events', [
        'provider'        => 'paystack',
        'event_id'        => $eventId,
        'event_type'      => $eventType,
        'payload'         => $raw,
        'signature_valid' => $valid ? 1 : 0,
        'status'          => $valid ? 'received' : 'invalid',
    ]);
} catch (Throwable $e) {
    // A UNIQUE-constraint violation means we've already recorded this event —
    // ack as a duplicate and stop (idempotency). Any OTHER error (e.g. the DB
    // is down) must NOT be silently reported as a duplicate; log it and fall
    // through so a genuinely-verified charge still gets processed below.
    if (mam_is_unique_violation($e)) {
        mam_log('info', 'paystack_webhook_duplicate', ['event_id' => $eventId]);
        mam_ok(['received' => true, 'duplicate' => true]);
    }
    mam_log('error', 'paystack_webhook_record_failed', ['event_id' => $eventId, 'err' => $e->getMessage()]);
}

if (!$valid) {
    mam_log('warn', 'paystack_webhook_invalid_signature', ['ip' => mam_client_ip()]);
    mam_fail('invalid_signature', 401);
}

try {
    if ($eventType === 'charge.success') {
        $ref = $payload['data']['reference'] ?? '';
        $amount = (float)($payload['data']['amount'] ?? 0) / 100;
        $currency = strtoupper((string)($payload['data']['currency'] ?? 'NGN'));
        $payment = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$ref]);
        // Never credit an underpayment or a different currency, even from a
        // signature-valid webhook.
        $amountOk = $payment && $currency === 'NGN' && (float)($payload['data']['amount'] ?? 0) >= (float)$payment['amount'] * 100;
        if ($payment && !$amountOk) {
            mam_log('warn', 'paystack_webhook_amount_mismatch', ['ref' => $ref, 'paid' => $amount, 'expected' => $payment['amount'], 'currency' => $currency]);
        }
        if ($payment && $amountOk && $payment['status'] !== 'succeeded') {
            mam_db_update('payments', [
                'status' => 'succeeded',
                'provider_reference' => $payload['data']['reference'] ?? null,
                'provider_response' => $raw,
                'verified_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['reference' => $ref]);
            mam_db_update('applications', ['status' => 'paid', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $payment['application_id']]);
            mam_audit('webhook_succeeded', 'payment', (int)$payment['id'], ['ref' => $ref, 'amount' => $amount]);
        }
    }
    mam_db_update('webhook_events', ['status' => 'processed'], ['provider' => 'paystack', 'event_id' => $eventId]);
} catch (Throwable $e) {
    mam_db_update('webhook_events', ['status' => 'error', 'error' => $e->getMessage()],
                  ['provider' => 'paystack', 'event_id' => $eventId]);
    mam_log('error', 'paystack_webhook_processing', ['err' => $e->getMessage()]);
}

mam_ok(['received' => true]);
