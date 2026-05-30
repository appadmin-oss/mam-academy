<?php
/**
 * Authentication & authorization.
 *
 * Three roles: admin, instructor, student.
 * Two ways to be admin:
 *   1. Logged-in user with role='admin' (session cookie)
 *   2. HTTP Basic Auth using ADMIN_USER / ADMIN_PASSWORD_HASH (env)
 *      — kept as a break-glass path before any users exist.
 */
declare(strict_types=1);

function mam_login(string $email, string $password, bool $remember = true): ?array {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    $u = mam_db_row("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email]);
    if (!$u) return null;
    if (!password_verify($password, $u['password_hash'])) return null;
    // Rehash if algorithm has been upgraded
    if (password_needs_rehash($u['password_hash'], PASSWORD_BCRYPT)) {
        try {
            mam_db_update('users', ['password_hash' => password_hash($password, PASSWORD_BCRYPT)], ['id' => (int)$u['id']]);
        } catch (Throwable $e) {}
    }
    mam_db_update('users', ['last_login' => date('Y-m-d H:i:s')], ['id' => (int)$u['id']]);
    mam_session_start_cookie((int)$u['id'], $remember);
    mam_audit('login', 'user', (int)$u['id'], ['email' => $email, 'remember' => $remember]);
    return $u;
}

function mam_current_user(): ?array {
    return mam_session_user();
}

function mam_require_user(): array {
    $u = mam_current_user();
    if (!$u) mam_fail('authentication_required', 401);
    return $u;
}

function mam_require_role(string ...$roles): array {
    $u = mam_require_user();
    if (!in_array($u['role'], $roles, true)) mam_fail('forbidden', 403);
    return $u;
}

/**
 * Admin guard accepted by both portals AND the legacy admin dashboard.
 * Prefers session role=admin; falls back to env-configured Basic Auth.
 */
function mam_require_admin(): void {
    $u = mam_current_user();
    if ($u && $u['role'] === 'admin') return;

    // Fallback: HTTP Basic Auth. On cPanel / LiteSpeed / many shared
    // hosts the `Authorization` header is stripped before PHP sees it
    // (PHP-FPM behind FastCGI strips it for security), so PHP_AUTH_USER
    // / PHP_AUTH_PW arrive empty even when the browser is sending
    // correct credentials. We probe every variant the host may use,
    // and as a last resort decode the raw `Authorization: Basic …` header
    // ourselves (the .htaccess RewriteRule below forwards it as
    // HTTP_AUTHORIZATION). One of these MUST work — without it, the
    // browser re-prompts forever.
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW']   ?? '';
    if ($user === '') {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
                  ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                  ?? $_SERVER['REMOTE_USER']
                  ?? '';
        if (is_string($authHeader) && stripos($authHeader, 'basic ') === 0) {
            $decoded = base64_decode(substr($authHeader, 6), true);
            if (is_string($decoded) && strpos($decoded, ':') !== false) {
                [$user, $pass] = explode(':', $decoded, 2);
            }
        }
    }
    $expectedUser = mam_env('ADMIN_USER', '');
    $expectedHash = mam_env('ADMIN_PASSWORD_HASH', '');

    if ($expectedUser && $expectedHash && $user
        && hash_equals($expectedUser, $user)
        && password_verify($pass, $expectedHash)) {
        return;
    }

    if (!$expectedUser || !$expectedHash) {
        mam_fail('admin_not_configured', 503);
    }

    header('WWW-Authenticate: Basic realm="MAM Academy Admin"');
    mam_fail('authentication_required', 401);
}

/** Ensure (or create) a student user record linked to a customer. Returns user row + optional plaintext password (only on creation). */
function mam_ensure_student_user(int $customerId): array {
    $cust = mam_db_row('SELECT * FROM customers WHERE id = ?', [$customerId]);
    if (!$cust) throw new RuntimeException('customer_not_found');

    $existing = mam_db_row('SELECT * FROM users WHERE email = ?', [$cust['email']]);
    if ($existing) {
        if (!$existing['customer_id']) {
            mam_db_update('users', ['customer_id' => $customerId], ['id' => (int)$existing['id']]);
        }
        return ['user' => $existing, 'password' => null];
    }

    $plain = bin2hex(random_bytes(5)); // 10-char temp password
    $userId = mam_db_insert('users', [
        'email'         => $cust['email'],
        'password_hash' => password_hash($plain, PASSWORD_BCRYPT),
        'role'          => 'student',
        'name'          => trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? '')),
        'phone'         => $cust['phone'],
        'customer_id'   => $customerId,
        'must_change_password' => 1,
    ]);
    $user = mam_db_row('SELECT * FROM users WHERE id = ?', [$userId]);
    mam_audit('create', 'user', $userId, ['role' => 'student', 'auto' => true]);
    return ['user' => $user, 'password' => $plain];
}
