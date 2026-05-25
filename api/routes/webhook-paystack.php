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
$eventId = (string)($payload['data']['id'] ?? $payload['event'] . '-' . ($payload['data']['reference'] ?? '') ?? '');
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
    // Likely a duplicate event id — record but don't reprocess.
    mam_log('info', 'paystack_webhook_duplicate', ['event_id' => $eventId]);
    mam_ok(['received' => true, 'duplicate' => true]);
}

if (!$valid) {
    mam_log('warn', 'paystack_webhook_invalid_signature', ['ip' => mam_client_ip()]);
    mam_fail('invalid_signature', 401);
}

try {
    if ($eventType === 'charge.success') {
        $ref = $payload['data']['reference'] ?? '';
        $amount = (float)($payload['data']['amount'] ?? 0) / 100;
        $payment = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$ref]);
        if ($payment && $payment['status'] !== 'succeeded') {
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
