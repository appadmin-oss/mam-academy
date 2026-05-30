<?php
/**
 * GET /api/csrf
 * Returns a fresh, HMAC-signed CSRF token.
 *
 * Wrapped in a try/catch so any underlying failure (storage dir not
 * writable for the auto-generated APP_KEY fallback, hash_hmac failure,
 * random_bytes failure) returns a clean JSON error with the actual
 * exception message in dev mode — instead of bubbling a 500 with no
 * detail. The exception still goes to api/logs/php-error.log so the
 * operator can diagnose post-hoc.
 */
declare(strict_types=1);

try {
    $token = mam_csrf_token();
} catch (Throwable $e) {
    @error_log('[mam-api csrf] ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    $env = strtolower((string)mam_env('APP_ENV', 'production'));
    mam_fail('csrf_failed', 500, [
        'message' => $env === 'production' ? 'CSRF token generation failed' : ($e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()),
    ]);
}
if (!$token) mam_fail('csrf_not_configured', 503);
mam_ok(['token' => $token, 'expires_in' => 7200]);
