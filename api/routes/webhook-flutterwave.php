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

// A delivery seen before is acknowledged — unless its processing FAILED, in
// which case this retry is the recovery and it runs again.
$seen = $eventId !== '' ? mam_db_row("SELECT status FROM webhook_events WHERE provider = 'flutterwave' AND event_id = ?", [$eventId]) : null;
if ($seen && (string) $seen['status'] !== 'error') {
    mam_log('info', 'flw_webhook_duplicate', ['event_id' => $eventId]);
    mam_ok(['received' => true, 'duplicate' => true]);
}
if (!$seen) {
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
        // Lost the race with a parallel delivery of the same event.
        mam_log('info', 'flw_webhook_duplicate', ['event_id' => $eventId]);
        mam_ok(['received' => true, 'duplicate' => true]);
    }
}

$outcome = null;
try {
    if ($eventType === 'charge.completed' && ($payload['data']['status'] ?? '') === 'successful') {
        $txId = (int)($payload['data']['id'] ?? 0);
        // Re-read with THIS install's key: a sandbox charge cannot be fetched
        // with a live key (and vice versa), which is the test/live guard.
        $resp = mam_flw_verify_by_id($txId);
        $d = $resp['data']['data'] ?? null;
        if ($d && ($d['status'] ?? '') === 'successful') {
            $ref = (string) ($d['tx_ref'] ?? '');
            $payment = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$ref]);
            if ($payment) {
                $outcome = mam_flw_apply_tx($payment, $d, 'webhook');
                if ($outcome['outcome'] === 'settled') {
                    mam_audit('webhook_succeeded', 'payment', (int)$payment['id'], ['ref' => $ref]);
                }
            } else {
                mam_log('warn', 'flw_webhook_unknown_ref', ['tx_ref' => $ref, 'mode' => mam_flw_mode()]);
            }
        } elseif ($txId > 0) {
            mam_log('warn', 'flw_webhook_unverified', ['tx_id' => $txId, 'http' => $resp['code'] ?? null, 'mode' => mam_flw_mode()]);
        }
    }
    mam_db_update('webhook_events', ['status' => 'processed', 'error' => null], ['provider' => 'flutterwave', 'event_id' => $eventId]);
} catch (Throwable $e) {
    mam_db_update('webhook_events', ['status' => 'error', 'error' => $e->getMessage()],
                  ['provider' => 'flutterwave', 'event_id' => $eventId]);
    mam_log('error', 'flw_webhook_processing', ['err' => $e->getMessage(), 'event_id' => $eventId]);
    // Non-200 so Flutterwave retries; the retry is let through above.
    mam_fail('processing_error', 500);
}

mam_ok(['received' => true, 'outcome' => $outcome['outcome'] ?? null]);
