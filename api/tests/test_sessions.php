<?php
/**
 * Session management (Portals P5) — see + revoke active sessions. Owner-scoped,
 * the current session is flagged and protected from "revoke others", and the
 * full token hash is never exposed (only a 16-char id).
 */
declare(strict_types=1);
t_group('session management (see + revoke)');

$a = mam_db_insert('users', ['email' => 't_sess_a@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Ada', 'status' => 'active']);
$b = mam_db_insert('users', ['email' => 't_sess_b@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Bee', 'status' => 'active']);

$mk = function (int $uid, string $token, string $ip, string $ua, int $ttl = 86400) {
    mam_db_insert('sessions', [
        'token_hash' => hash('sha256', $token), 'user_id' => $uid,
        'ip' => $ip, 'ua' => $ua,
        'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
    ]);
};
$tokCur = bin2hex(random_bytes(20));   // will be "this device"
$tok2   = bin2hex(random_bytes(20));
$tok3   = bin2hex(random_bytes(20));
$mk($a, $tokCur, '1.1.1.1', 'Firefox');
$mk($a, $tok2,   '2.2.2.2', 'Chrome');
$mk($a, $tok3,   '3.3.3.3', 'Safari');
$mk($b, bin2hex(random_bytes(20)), '9.9.9.9', 'Edge');         // another user
$mk($a, bin2hex(random_bytes(20)), '4.4.4.4', 'Old', -3600);   // EXPIRED (past)

// Simulate "this device" being $tokCur.
$_COOKIE[MAM_COOKIE] = $tokCur;

// ── List ──
$list = mam_sessions_list($a);
t_eq(count($list), 3, 'lists only this user\'s active sessions (expired + other-user excluded)');
$cur = array_values(array_filter($list, function ($s) { return $s['current']; }));
t_eq(count($cur), 1, 'exactly one session is flagged current');
t_eq($cur[0]['ip'], '1.1.1.1', 'the current session is this device');
t_ok(!array_key_exists('token_hash', $list[0]) && strlen($list[0]['id']) === 16, 'the full token hash is never exposed (16-char id only)');

// ── Revoke one (owner-scoped) ──
$other = array_values(array_filter($list, function ($s) { return !$s['current']; }))[0];
t_ok(mam_sessions_revoke($a, $other['id']) === true, 'a user can revoke their own session');
t_eq(count(mam_sessions_list($a)), 2, 'the revoked session is gone');
// Cross-user: A cannot revoke B's session (find B's id from a direct query).
$bHash = mam_db_row('SELECT token_hash FROM sessions WHERE user_id = ?', [$b])['token_hash'];
t_ok(mam_sessions_revoke($a, substr($bHash, 0, 16)) === false, 'a user cannot revoke another user\'s session');
t_eq((int)mam_db_row('SELECT COUNT(*) AS n FROM sessions WHERE user_id = ?', [$b])['n'], 1, 'the other user\'s session is untouched');

// ── Revoke others (keep current) ──
$n = mam_sessions_revoke_others($a);
t_ok($n >= 1, 'revoke-others removed the non-current session(s) (incl. any stale rows)');
$after = mam_sessions_list($a);
t_eq(count($after), 1, 'only the current session remains');
t_ok($after[0]['current'] === true, 'the surviving session is this device');

// Guard.
t_ok(mam_sessions_revoke($a, 'x') === false, 'a too-short id is rejected');
t_eq(mam_sessions_list(0), [], 'no user id → empty list');
