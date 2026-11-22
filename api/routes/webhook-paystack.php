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

// Reject an unsigned/forged webhook BEFORE writing anything, so an
// unauthenticated flood can't fill webhook_events with attacker-supplied raw
// payloads (a DB-write DoS). Only signature-valid events are persisted.
if (!$valid) {
    mam_log('warn', 'paystack_webhook_invalid_signature', ['ip' => mam_client_ip()]);
    mam_fail('invalid_signature', 401);
}

$payload = json_decode($raw, true) ?: [];
$eventType = (string)($payload['event'] ?? '');
// One row per (event, object): Paystack reuses an object's id across event types
// (a charge and the dispute on it), and only a true repeat may be dropped.
$eventId = $eventType . ':' . (string)($payload['data']['id'] ?? ($payload['data']['reference'] ?? ''));
// What is kept carries no reusable card authorisation (mam_paystack_redact).
$stored = json_encode(mam_paystack_redact($payload)) ?: '{}';

try {
    mam_db_insert('webhook_events', [
        'provider'        => 'paystack',
        'event_id'        => $eventId,
        'event_type'      => $eventType,
        'payload'         => $stored,
        'signature_valid' => 1,
        'status'          => 'received',
    ]);
} catch (Throwable $e) {
    // A repeat is acknowledged and dropped. Any OTHER failure to record it (the
    // database away for a moment) must not be acknowledged: a 200 tells Paystack
    // to stop retrying, and a charge nobody else verifies would never settle.
    if (mam_db_row('SELECT 1 FROM webhook_events WHERE provider = ? AND event_id = ?', ['paystack', $eventId])) {
        mam_log('info', 'paystack_webhook_duplicate', ['event_id' => $eventId]);
        mam_ok(['received' => true, 'duplicate' => true]);
    }
    mam_log('error', 'paystack_webhook_store_failed', ['event_id' => $eventId, 'err' => $e->getMessage()]);
    mam_fail('retry_later', 503);
}

try {
    if ($eventType === 'charge.success') {
        $ref = $payload['data']['reference'] ?? '';
        $amount = (float)($payload['data']['amount'] ?? 0) / 100;
        $currency = strtoupper((string)($payload['data']['currency'] ?? 'NGN'));
        $payment = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$ref]);
        // Never credit an underpayment or a different currency, even from a
        // signature-valid webhook.
        $amountOk = $payment && mam_payment_amount_ok($payment, (float)($payload['data']['amount'] ?? 0), $currency);
        if ($payment && !$amountOk) {
            mam_log('warn', 'paystack_webhook_amount_mismatch', ['ref' => $ref, 'paid' => $amount, 'expected' => $payment['amount'], 'currency' => $currency]);
        }
        if ($payment && $amountOk && $payment['status'] !== 'succeeded') {
            // Settle via the shared, purpose-aware path (store delivery /
            // booking-fee stamp / tuition enrolment + provisioning) — identical
            // to the interactive verify endpoint, idempotent + race-safe.
            mam_payment_settle($payment, $payload['data'] ?? null, json_encode(mam_paystack_redact($payload['data'] ?? [])) ?: '{}', (string)($payload['data']['reference'] ?? $ref));
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
