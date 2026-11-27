<?php
/**
 * Session manager — stateless-ish cookie + DB-backed sessions.
 *
 * Cookie format:  "<token>"   (40-char hex)
 * Stored as SHA-256 hash in sessions.token_hash so DB leak ≠ session takeover.
 */
declare(strict_types=1);

define('MAM_COOKIE', 'mam_sid');
define('MAM_SESSION_TTL_LONG',  60 * 60 * 24 * 30);  // 30 days when "remember me" is checked
define('MAM_SESSION_TTL_SHORT', 60 * 60 * 24);       // 1 day for a session-by-session sign-in

function mam_session_start_cookie(int $userId, bool $remember = true): string {
    $token = bin2hex(random_bytes(20));
    $hash  = hash('sha256', $token);
    $ttl   = $remember ? MAM_SESSION_TTL_LONG : MAM_SESSION_TTL_SHORT;
    $exp   = date('Y-m-d H:i:s', time() + $ttl);
    mam_db_insert('sessions', [
        'token_hash' => $hash,
        'user_id'    => $userId,
        'ip'         => mam_client_ip(),
        'ua'         => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240),
        'expires_at' => $exp,
    ]);
    mam_session_set_cookie($token, $ttl);
    return $token;
}

/**
 * Whether the session cookie carries the Secure flag. Auto-detection can be
 * fooled by a proxy that doesn't forward X-Forwarded-Proto, so COOKIE_SECURE
 * in .env overrides it: 1/true forces Secure (recommended in production),
 * 0/false disables it (plain-HTTP local dev only).
 */
function mam_cookie_secure(): bool {
    $env = strtolower(trim((string)mam_env('COOKIE_SECURE', '')));
    if ($env === '1' || $env === 'true')  return true;
    if ($env === '0' || $env === 'false') return false;
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function mam_session_set_cookie(string $token, int $ttl = null): void {
    if ($ttl === null) $ttl = MAM_SESSION_TTL_LONG;
    $secure = mam_cookie_secure();
    setcookie(MAM_COOKIE, $token, [
        'expires'  => time() + $ttl,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[MAM_COOKIE] = $token;
}

function mam_session_clear_cookie(): void {
    setcookie(MAM_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[MAM_COOKIE]);
}

/** Returns the logged-in user array or null. Side effect: deletes expired sessions for this user. */
function mam_session_user(): ?array {
    static $cached = false, $user = null;
    if ($cached) return $user;
    $cached = true;
    $token = $_COOKIE[MAM_COOKIE] ?? '';
    if (!$token || !preg_match('/^[a-f0-9]{40}$/', $token)) return $user = null;
    $hash = hash('sha256', $token);
    $row = mam_db_row(
        "SELECT u.*, s.expires_at AS session_expires FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.expires_at > ? AND u.status = 'active'",
        [$hash, date('Y-m-d H:i:s')]
    );
    if (!$row) return $user = null;
    return $user = $row;
}

function mam_session_destroy(): void {
    $token = $_COOKIE[MAM_COOKIE] ?? '';
    if ($token && preg_match('/^[a-f0-9]{40}$/', $token)) {
        $hash = hash('sha256', $token);
        try { mam_db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([$hash]); } catch (Throwable $e) {}
    }
    mam_session_clear_cookie();
}

/** The SHA-256 hash of the current request's session token, or '' if none. */
function mam_session_current_hash(): string {
    $token = $_COOKIE[MAM_COOKIE] ?? '';
    return ($token && preg_match('/^[a-f0-9]{40}$/', $token)) ? hash('sha256', $token) : '';
}

/**
 * Session management (Portals P5): a user's active (non-expired) sessions,
 * newest first, with the CURRENT one flagged. The full token_hash is never
 * exposed — a 16-hex-char id (used for revoke) is enough to disambiguate a
 * user's own handful of sessions while leaking nothing useful.
 */
function mam_sessions_list(int $userId): array {
    if ($userId <= 0) return [];
    $current = mam_session_current_hash();
    $rows = mam_db_all(
        "SELECT token_hash, ip, ua, created_at, expires_at FROM sessions WHERE user_id = ? AND expires_at > ? ORDER BY created_at DESC",
        [$userId, date('Y-m-d H:i:s')]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'         => substr((string)$r['token_hash'], 0, 16),
            'ip'         => (string)($r['ip'] ?? ''),
            'ua'         => (string)($r['ua'] ?? ''),
            'created_at' => (string)($r['created_at'] ?? ''),
            'expires_at' => (string)($r['expires_at'] ?? ''),
            'current'    => ($current !== '' && (string)$r['token_hash'] === $current),
        ];
    }
    return $out;
}

/** Revoke one of a user's sessions by its 16-char id (owner-scoped). */
function mam_sessions_revoke(int $userId, string $id): bool {
    $id = preg_replace('/[^a-f0-9]/', '', $id);
    if ($userId <= 0 || strlen($id) < 8) return false;
    try {
        $st = mam_db()->prepare('DELETE FROM sessions WHERE user_id = ? AND token_hash LIKE ?');
        $st->execute([$userId, $id . '%']);
        return $st->rowCount() > 0;
    } catch (Throwable $e) { return false; }
}

/** Revoke all of a user's OTHER sessions (keep the current one). Returns count. */
function mam_sessions_revoke_others(int $userId): int {
    if ($userId <= 0) return 0;
    $current = mam_session_current_hash();
    try {
        if ($current !== '') {
            $st = mam_db()->prepare('DELETE FROM sessions WHERE user_id = ? AND token_hash <> ?');
            $st->execute([$userId, $current]);
        } else {
            $st = mam_db()->prepare('DELETE FROM sessions WHERE user_id = ?');
            $st->execute([$userId]);
        }
        return $st->rowCount();
    } catch (Throwable $e) { return 0; }
}

/** Sweep expired sessions occasionally (1 in 50 requests). */
function mam_session_gc(): void {
    if (random_int(1, 50) !== 1) return;
    try { mam_db()->prepare('DELETE FROM sessions WHERE expires_at <= ?')->execute([date('Y-m-d H:i:s')]); } catch (Throwable $e) {}
}
