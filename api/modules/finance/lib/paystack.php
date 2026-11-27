<?php
/**
 * Paystack API client (server-side).
 * Docs: https://paystack.com/docs/api
 */
declare(strict_types=1);

/** Paystack can take money only with a secret key and an HTTP client. */
function mam_paystack_configured(): bool {
    return function_exists('curl_init') && trim((string) mam_env('PAYSTACK_SECRET_KEY', '')) !== '';
}

function mam_paystack_key(): string {
    $k = (string)mam_env('PAYSTACK_SECRET_KEY', '');
    if ($k === '') throw new RuntimeException('Paystack not configured');
    return $k;
}

function mam_paystack_request(string $method, string $path, array $payload = []): array {
    $url = 'https://api.paystack.co' . $path;
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . mam_paystack_key(),
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
    if ($resp === false) throw new RuntimeException('Paystack network error: ' . $err);
    $data = json_decode($resp, true);
    return ['code' => $code, 'data' => $data, 'raw' => $resp];
}

function mam_paystack_initialize(string $email, int $amountKobo, string $reference, array $metadata = [], ?string $callbackPath = null): array {
    // $callbackPath: where the buyer lands afterwards. The default is the
    // applicant's thank-you page; a guardian paying fees returns to the portal.
    // The callback is always this site's own APP_URL plus a server-chosen path, never
    // anything a caller sent. Without an absolute APP_URL the field is left out (a
    // relative URL is not one Paystack can redirect to), and the dashboard's callback applies.
    $base = rtrim((string)mam_env('APP_URL', ''), '/');
    $body = [
        'email' => $email,
        'amount' => $amountKobo,
        'reference' => $reference,
        'currency' => 'NGN',
        'metadata' => $metadata,
    ];
    if (preg_match('#^https?://#i', $base)) {
        $body['callback_url'] = $base . ($callbackPath ?? ('/thank-you?ref=' . urlencode($reference)));
    }
    return mam_paystack_request('POST', '/transaction/initialize', $body);
}

function mam_paystack_verify(string $reference): array {
    return mam_paystack_request('GET', '/transaction/verify/' . rawurlencode($reference));
}

function mam_paystack_verify_signature(string $rawBody, string $signature): bool {
    // With no secret at all there is nothing to check against: refuse, rather than
    // throwing (which answered every webhook with a 500).
    $secret = trim((string)mam_env('PAYSTACK_WEBHOOK_SECRET', ''));
    if ($secret === '') $secret = trim((string)mam_env('PAYSTACK_SECRET_KEY', ''));
    if ($secret === '' || $signature === '') return false;
    $expected = hash_hmac('sha512', $rawBody, $secret);
    return hash_equals($expected, strtolower(trim($signature)));
}

/**
 * The copy of a Paystack transaction this site KEEPS (payments.provider_response,
 * webhook_events.payload). Paystack never sends a card number, but it does send
 * `authorization.authorization_code`, which with the secret key charges that card
 * again without the cardholder (charge_authorization), and a card `signature`.
 * Neither is needed after settlement, so neither is stored; the brand, last four
 * digits, bank and channel stay, because receipts and reconciliation read them.
 */
function mam_paystack_redact($data) {
    if (!is_array($data)) return $data;
    foreach ($data as $k => $v) {
        if (in_array((string) $k, ['authorization_code', 'signature'], true)) { $data[$k] = '[redacted]'; continue; }
        if (is_array($v)) $data[$k] = mam_paystack_redact($v);
    }
    return $data;
}
