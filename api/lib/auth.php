<?php
/**
 * Admin authentication (HTTP Basic Auth, bcrypt-hashed).
 */
declare(strict_types=1);

function mam_require_admin(): void {
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW']   ?? '';
    $expectedUser = mam_env('ADMIN_USER', '');
    $expectedHash = mam_env('ADMIN_PASSWORD_HASH', '');

    if (!$expectedUser || !$expectedHash) {
        mam_fail('admin_not_configured', 503);
    }

    if (!$user || !hash_equals($expectedUser, $user) || !password_verify($pass, $expectedHash)) {
        header('WWW-Authenticate: Basic realm="MAM Academy Admin"');
        mam_fail('authentication_required', 401);
    }
}

function mam_constant_time_equals(string $a, string $b): bool {
    return hash_equals($a, $b);
}
