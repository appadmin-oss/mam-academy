<?php
/**
 * Stateless HMAC-based CSRF tokens.
 * Token format: timestamp.random.hmac
 * Verified against APP_KEY. Valid for 2 hours.
 */
declare(strict_types=1);

function mam_csrf_token(): string {
    $key = mam_env('APP_KEY', '');
    if (!$key) return '';
    $ts = time();
    $nonce = bin2hex(random_bytes(8));
    $payload = $ts . '.' . $nonce;
    $sig = hash_hmac('sha256', $payload, $key);
    return $payload . '.' . $sig;
}

function mam_csrf_verify(?string $token, int $maxAgeSec = 7200): bool {
    if (!$token) return false;
    $key = mam_env('APP_KEY', '');
    if (!$key) return false;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    [$ts, $nonce, $sig] = $parts;
    if (!ctype_digit($ts)) return false;
    if (time() - (int)$ts > $maxAgeSec) return false;
    $expected = hash_hmac('sha256', $ts . '.' . $nonce, $key);
    return hash_equals($expected, $sig);
}

function mam_csrf_require(): void {
    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!mam_csrf_verify($hdr)) mam_fail('csrf_invalid', 403);
}
