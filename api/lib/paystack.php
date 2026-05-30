<?php
/**
 * Paystack API client (server-side).
 * Docs: https://paystack.com/docs/api
 */
declare(strict_types=1);

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

function mam_paystack_initialize(string $email, int $amountKobo, string $reference, array $metadata = []): array {
    return mam_paystack_request('POST', '/transaction/initialize', [
        'email' => $email,
        'amount' => $amountKobo,
        'reference' => $reference,
        'currency' => 'NGN',
        'metadata' => $metadata,
        'callback_url' => rtrim((string)mam_env('APP_URL', ''), '/') . '/thank-you?ref=' . urlencode($reference),
    ]);
}

function mam_paystack_verify(string $reference): array {
    return mam_paystack_request('GET', '/transaction/verify/' . rawurlencode($reference));
}

function mam_paystack_verify_signature(string $rawBody, string $signature): bool {
    $secret = (string)mam_env('PAYSTACK_WEBHOOK_SECRET', '');
    if ($secret === '') $secret = mam_paystack_key();
    $expected = hash_hmac('sha512', $rawBody, $secret);
    return hash_equals($expected, $signature);
}
