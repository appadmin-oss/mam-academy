<?php
/**
 * POST /api/payment/webhook/stripe
 *
 * The other two providers have had one of these from the start; Stripe did
 * not, which meant a Checkout Session that succeeded while the payer's phone
 * lost signal on the redirect would never be recorded. The money would be in
 * the account and the application would still read unpaid.
 *
 * Signature verified against STRIPE_WEBHOOK_SECRET, including the timestamp,
 * so an old capture cannot be replayed. Duplicate event ids are dropped.
 */
declare(strict_types=1);

$raw = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$valid = mam_stripe_verify_signature($raw, $signature);

$payload   = json_decode($raw, true) ?: [];
$eventId   = (string)($payload['id'] ?? '');
$eventType = (string)($payload['type'] ?? '');

try {
    mam_db_insert('webhook_events', [
        'provider'        => 'stripe',
        'event_id'        => $eventId,
        'event_type'      => $eventType,
        'payload'         => $raw,
        'signature_valid' => $valid ? 1 : 0,
        'status'          => $valid ? 'received' : 'invalid',
    ]);
} catch (Throwable $e) {
    // A duplicate event id. Stripe retries until it gets a 200, so this is
    // the normal path for a retry rather than an error — record and stop.
    mam_log('info', 'stripe_webhook_duplicate', ['event_id' => $eventId]);
    mam_ok(['received' => true, 'duplicate' => true]);
}

if (!$valid) {
    mam_log('warn', 'stripe_webhook_invalid_signature', ['ip' => mam_client_ip()]);
    mam_fail('invalid_signature', 401);
}

try {
    /*
     * checkout.session.completed is the event that means the payer finished.
     * async_payment_succeeded covers the slower methods, where the session
     * completes before the money actually clears.
     */
    if ($eventType === 'checkout.session.completed'
     || $eventType === 'checkout.session.async_payment_succeeded') {

        $session = $payload['data']['object'] ?? [];
        $ref = (string)($session['client_reference_id'] ?? '');

        // Only when Stripe says it is paid. A completed session with
        // payment_status 'unpaid' is somebody who reached the end of an
        // async method that has not cleared.
        if ($ref !== '' && ($session['payment_status'] ?? '') === 'paid') {
            $payment = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$ref]);

            if ($payment && $payment['status'] !== 'succeeded') {
                $paidCents = (int)($session['amount_total'] ?? 0);
                $wantCents = (int)round((float)$payment['amount'] * 100);

                if ($wantCents > 0 && $paidCents < $wantCents) {
                    // Short payment is not payment. Left pending and logged,
                    // so somebody looks at it rather than a place being given
                    // away for less than it costs.
                    mam_log('warn', 'stripe_webhook_amount_short',
                            ['ref' => $ref, 'paid' => $paidCents, 'want' => $wantCents]);
                } else {
                    mam_db_update('payments', [
                        'status'             => 'succeeded',
                        'provider_reference' => (string)($session['payment_intent'] ?? ($session['id'] ?? '')),
                        'provider_response'  => $raw,
                        'verified_at'        => date('Y-m-d H:i:s'),
                        'updated_at'         => date('Y-m-d H:i:s'),
                    ], ['reference' => $ref]);
                    mam_db_update('applications',
                        ['status' => 'paid', 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => $payment['application_id']]);
                    mam_audit('webhook_succeeded', 'payment', (int)$payment['id'],
                              ['ref' => $ref, 'amount' => $paidCents / 100, 'provider' => 'stripe']);
                }
            }
        }
    }
    mam_db_update('webhook_events', ['status' => 'processed'], ['provider' => 'stripe', 'event_id' => $eventId]);
} catch (Throwable $e) {
    mam_db_update('webhook_events', ['status' => 'error', 'error' => $e->getMessage()],
                  ['provider' => 'stripe', 'event_id' => $eventId]);
    mam_log('error', 'stripe_webhook_processing', ['err' => $e->getMessage()]);
}

mam_ok(['received' => true]);
