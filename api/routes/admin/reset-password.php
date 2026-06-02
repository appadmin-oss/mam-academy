<?php
declare(strict_types=1);

$in = mam_json_input();
$userId = (int)($in['user_id'] ?? 0);
$password = mam_str($in, 'password', 200);
if (!$userId || strlen($password) < 8) mam_fail('validation', 422, ['fields' => ['user_id', 'password']]);

$user = mam_db_row('SELECT id, email FROM users WHERE id = ?', [$userId]);
if (!$user) mam_fail('user_not_found', 404);

mam_db_update('users', [
    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
    'must_change_password' => 1,
    'updated_at' => date('Y-m-d H:i:s'),
], ['id' => $userId]);

// Invalidate any existing sessions for that user
mam_db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);

// Email the affected user their new temporary credentials.
$full = mam_db_row('SELECT name, email FROM users WHERE id = ?', [$userId]);
if ($full && !empty($full['email'])) {
    $loginUrl = rtrim((string)mam_env('APP_URL', ''), '/') . '/portal/login';
    try {
        mam_send($full['email'], 'Your MAM Academy password was reset',
            mam_email_account_created((string)($full['name'] ?? ''), (string)$full['email'], $password, 'portal', $loginUrl),
            'admin_password_reset', true);
    } catch (Throwable $e) { mam_log('warn', 'mail_build_failed', ['context' => 'admin_password_reset', 'err' => $e->getMessage()]); }
}

mam_audit('admin_reset_password', 'user', $userId);
mam_ok(['reset' => true]);
