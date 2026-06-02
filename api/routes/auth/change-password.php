<?php
declare(strict_types=1);

$u = mam_require_user();

$in = mam_json_input();
$current = mam_str($in, 'current', 200);
$next    = mam_str($in, 'next',    200);

if (strlen($next) < 8) mam_fail('weak_password', 422, ['fields' => ['next']]);
if (!password_verify($current, $u['password_hash'])) mam_fail('invalid_current_password', 401);

mam_db_update('users', [
    'password_hash' => password_hash($next, PASSWORD_BCRYPT),
    'must_change_password' => 0,
    'updated_at' => date('Y-m-d H:i:s'),
], ['id' => (int)$u['id']]);
if (!empty($u['email'])) {
    // The password is already changed; a mail hiccup must never fail the action.
    try {
        mam_send($u['email'], 'Your MAM Academy password was changed',
            mam_email_password_changed((string)($u['name'] ?? ''), 'self'), 'password_changed', true);
    } catch (Throwable $e) { mam_log('warn', 'mail_build_failed', ['context' => 'password_changed', 'err' => $e->getMessage()]); }
}
mam_audit('change_password', 'user', (int)$u['id']);
mam_ok(['changed' => true]);
