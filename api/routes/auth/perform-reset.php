<?php
/**
 * POST /api/auth/perform-reset
 * Body: { token, password }
 *
 * Validates the token (sha256-matched against the password_resets
 * table), updates the user's password, kills all existing sessions
 * for that user, and marks the token used.
 */
declare(strict_types=1);

$in       = mam_json_input();
$token    = mam_str($in, 'token', 64);
$password = mam_str($in, 'password', 200);

if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
    mam_fail('invalid_token', 400);
}
if (strlen($password) < 8) {
    mam_fail('weak_password', 422, ['fields' => ['password']]);
}

$hash  = hash('sha256', $token);
$reset = mam_db_row(
    'SELECT * FROM password_resets
       WHERE token_hash = ?
         AND expires_at > datetime("now")
         AND used_at IS NULL',
    [$hash]
);
if (!$reset) {
    mam_fail('invalid_or_expired', 400);
}

mam_db_update('users', [
    'password_hash'        => password_hash($password, PASSWORD_BCRYPT),
    'must_change_password' => 0,
    'updated_at'           => date('Y-m-d H:i:s'),
], ['id' => (int)$reset['user_id']]);

mam_db_update('password_resets',
    ['used_at' => date('Y-m-d H:i:s')],
    ['token_hash' => $hash]
);

// Kill any existing sessions for this user — force a fresh sign-in.
mam_db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([(int)$reset['user_id']]);

// Security confirmation that the password just changed.
$who = mam_db_row('SELECT name, email FROM users WHERE id = ?', [(int)$reset['user_id']]);
if ($who && !empty($who['email'])) {
    try {
        mam_send($who['email'], 'Your MAM Academy password was changed',
            mam_email_password_changed((string)($who['name'] ?? ''), 'reset'), 'password_changed', true);
    } catch (Throwable $e) { mam_log('warn', 'mail_build_failed', ['context' => 'password_changed', 'err' => $e->getMessage()]); }
}

mam_audit('reset_password', 'user', (int)$reset['user_id']);
mam_ok(['reset' => true]);
