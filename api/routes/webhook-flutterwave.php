<?php
/**
 * POST /api/payment/webhook/flutterwave
 * Verifies via `verif-hash` header matching FLW_WEBHOOK_HASH and then
 * confirms with the verify-by-id API before crediting the application.
 */
declare(strict_types=1);

$raw = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_VERIF_HASH'] ?? '';
$valid = mam_flw_verify_signature($signature);

// Reject an unsigned/forged webhook BEFORE writing anything (DB-write DoS guard).
if (!$valid) {
    mam_log('warn', 'flw_webhook_invalid_signature', ['ip' => mam_client_ip()]);
    mam_fail('invalid_signature', 401);
}

$payload = json_decode($raw, true) ?: [];
$eventId = (string)($payload['data']['id'] ?? '');
$eventType = (string)($payload['event'] ?? '');

try {
    mam_db_insert('webhook_events', [
        'provider'        => 'flutterwave',
        'event_id'        => $eventId,
        'event_type'      => $eventType,
        'payload'         => $raw,
        'signature_valid' => 1,
        'status'          => 'received',
    ]);
} catch (Throwable $e) {
    mam_log('info', 'flw_webhook_duplicate', ['event_id' => $eventId]);
    mam_ok(['received' => true, 'duplicate' => true]);
}

try {
    if ($eventType === 'charge.completed' && ($payload['data']['status'] ?? '') === 'successful') {
        $txId = (int)($payload['data']['id'] ?? 0);
        $resp = mam_flw_verify_by_id($txId);
        $d = $resp['data']['data'] ?? null;
        if ($d && ($d['status'] ?? '') === 'successful') {
            $ref = $d['tx_ref'] ?? '';
            $payment = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$ref]);
            $currency = strtoupper((string)($d['currency'] ?? 'NGN'));
            $amountOk = $payment && mam_payment_amount_ok($payment, (float)($d['amount'] ?? 0) * 100, $currency);
            if ($payment && !$amountOk) {
                mam_log('warn', 'flw_webhook_amount_mismatch', ['ref' => $ref, 'paid' => $d['amount'] ?? 0, 'expected' => $payment['amount'], 'currency' => $currency]);
            }
            if ($payment && $amountOk && $payment['status'] !== 'succeeded') {
                // Shared, purpose-aware settlement (matches verify exactly).
                mam_payment_settle($payment, $d, json_encode($d), (string)($d['flw_ref'] ?? $ref));
                mam_audit('webhook_succeeded', 'payment', (int)$payment['id'], ['ref' => $ref]);
            }
        }
    }
    mam_db_update('webhook_events', ['status' => 'processed'], ['provider' => 'flutterwave', 'event_id' => $eventId]);
} catch (Throwable $e) {
    mam_db_update('webhook_events', ['status' => 'error', 'error' => $e->getMessage()],
                  ['provider' => 'flutterwave', 'event_id' => $eventId]);
    mam_log('error', 'flw_webhook_processing', ['err' => $e->getMessage()]);
}

mam_ok(['received' => true]);
