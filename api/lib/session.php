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

function mam_session_set_cookie(string $token, int $ttl = null): void {
    if ($ttl === null) $ttl = MAM_SESSION_TTL_LONG;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
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
        'SELECT u.*, s.expires_at AS session_expires FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.expires_at > datetime("now") AND u.status = "active"',
        [$hash]
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

/** Sweep expired sessions occasionally (1 in 50 requests). */
function mam_session_gc(): void {
    if (random_int(1, 50) !== 1) return;
    try { mam_db()->exec("DELETE FROM sessions WHERE expires_at <= datetime('now')"); } catch (Throwable $e) {}
}
