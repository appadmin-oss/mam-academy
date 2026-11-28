<?php
/**
 * Flutterwave API client (server-side).
 * Docs: https://developer.flutterwave.com/docs
 */
declare(strict_types=1);

function mam_flw_key(): string {
    $k = (string)mam_env('FLW_SECRET_KEY', '');
    if ($k === '') throw new RuntimeException('Flutterwave not configured');
    return $k;
}

function mam_flw_request(string $method, string $path, array $payload = []): array {
    $url = 'https://api.flutterwave.com/v3' . $path;
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . mam_flw_key(),
            'Content-Type: application/json',
            'Cache-Control: no-cache',
        ],
    ];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('Flutterwave network error: ' . $err);
    $data = json_decode($resp, true);
    return ['code' => $code, 'data' => $data, 'raw' => $resp];
}

function mam_flw_verify_by_tx_ref(string $txRef): array {
    return mam_flw_request('GET', '/transactions/verify_by_reference?tx_ref=' . rawurlencode($txRef));
}

function mam_flw_verify_by_id(int $id): array {
    return mam_flw_request('GET', '/transactions/' . $id . '/verify');
}

function mam_flw_verify_signature(string $signature): bool {
    $secret = (string)mam_env('FLW_WEBHOOK_HASH', '');
    return $secret !== '' && hash_equals($secret, $signature);
}

/**
 * 'live' or 'test' from the secret key (FLWSECK_TEST-… is a sandbox key); null
 * when it says neither. Flutterwave's transaction carries no mode field of its
 * own, so the guard that matters is that every charge is re-read with THIS
 * install's key (a sandbox charge cannot be fetched with a live key); this is
 * logged beside each refusal so the office can see which side was which.
 */
function mam_flw_mode(): ?string {
    $k = trim((string) mam_env('FLW_SECRET_KEY', ''));
    if ($k === '') return null;
    if (stripos($k, 'FLWSECK_TEST') === 0) return 'test';
    if (stripos($k, 'FLWSECK-') === 0) return 'live';
    return null;
}

/**
 * The one decision about a Flutterwave transaction that the gateway has
 * confirmed (already re-read with this install's key). Flutterwave takes NAIRA
 * only — which gateway takes other currencies is the owner's decision, not
 * this function's — so any other currency is refused here, explicitly, logged
 * and recorded, and never settled.
 *
 * Returns ['outcome' => settled|already|duplicate_charge|refused|refund_due, 'reason' => ?string, 'settle' => ?array].
 * Throws only when settlement itself could not record the claim, so the
 * webhook answers non-200 and Flutterwave retries.
 */
function mam_flw_apply_tx(array $payment, array $d, string $source): array {
    $ref = (string) $payment['reference'];
    $pid = (int) $payment['id'];
    $txRef = (string) ($d['flw_ref'] ?? ($d['id'] ?? $ref));
    $currency = strtoupper(trim((string) ($d['currency'] ?? '')));
    // Every name this ONE charge goes by. Verify and the webhook both store the
    // flw_ref now; a row settled earlier by verify holds the tx_ref (= our own
    // reference), which says nothing about WHICH charge, so it reads as a
    // repeat rather than raising a refund that may not be owed.
    $sameCharge = array_values(array_filter(array_map('strval', [
        $d['flw_ref'] ?? null, $d['id'] ?? null, $d['tx_ref'] ?? null, $ref,
    ]), fn($v) => $v !== ''));

    if ($currency !== 'NGN') {
        mam_log('warn', 'flw_currency_refused', ['ref' => $ref, 'currency' => $currency, 'source' => $source, 'mode' => mam_flw_mode()]);
        mam_payment_event($pid, $ref, 'currency_refused', ['provider' => 'flutterwave', 'currency' => $currency, 'why' => 'Flutterwave is accepted in NGN only', 'source' => $source]);
        return ['outcome' => 'refused', 'reason' => 'currency_not_ngn', 'settle' => null];
    }
    if (!mam_payment_amount_ok($payment, (float) ($d['amount'] ?? 0) * 100, $currency)) {
        mam_log('warn', 'flw_webhook_amount_mismatch', ['ref' => $ref, 'paid' => $d['amount'] ?? 0, 'expected' => $payment['amount'], 'currency' => $currency]);
        mam_payment_event($pid, $ref, 'amount_mismatch', ['provider' => 'flutterwave', 'paid' => (string) ($d['amount'] ?? ''), 'source' => $source]);
        return ['outcome' => 'refused', 'reason' => 'amount_mismatch', 'settle' => null];
    }

    $fresh = mam_db_row('SELECT * FROM payments WHERE id = ?', [$pid]) ?: $payment;
    if ((string) $fresh['status'] === 'succeeded' || (string) $fresh['status'] === 'refunded') {
        $settledBy = (string) ($fresh['provider_reference'] ?? '');
        if ($settledBy !== '' && !in_array($settledBy, $sameCharge, true)) {
            mam_payment_flag_refund_due($fresh, 'paid twice: a second Flutterwave charge arrived after this was settled', ['second_ref' => $txRef, 'settled_by' => $settledBy, 'source' => $source]);
            return ['outcome' => 'duplicate_charge', 'reason' => 'paid_twice', 'settle' => null];
        }
        return ['outcome' => 'already', 'reason' => null, 'settle' => null];
    }

    $settle = mam_payment_settle($fresh, $d, (string) (json_encode($d) ?: '{}'), $txRef);
    if (empty($settle['ok'])) throw new RuntimeException('settle_claim_failed');
    if (!empty($settle['refund_due'])) return ['outcome' => 'refund_due', 'reason' => (string) $settle['refund_due'], 'settle' => $settle];
    if (!empty($settle['already'])) {
        $after = mam_db_row('SELECT provider_reference FROM payments WHERE id = ?', [$pid]);
        if ($after && (string) $after['provider_reference'] !== '' && !in_array((string) $after['provider_reference'], $sameCharge, true)) {
            mam_payment_flag_refund_due($fresh, 'paid twice: a second Flutterwave charge arrived after this was settled', ['second_ref' => $txRef, 'settled_by' => (string) $after['provider_reference'], 'source' => $source]);
            return ['outcome' => 'duplicate_charge', 'reason' => 'paid_twice', 'settle' => $settle];
        }
        return ['outcome' => 'already', 'reason' => null, 'settle' => $settle];
    }
    return ['outcome' => 'settled', 'reason' => null, 'settle' => $settle];
}
