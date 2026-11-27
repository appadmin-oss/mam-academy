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
