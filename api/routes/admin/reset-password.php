<?php
declare(strict_types=1);
mam_require_method('POST');
mam_csrf_require();
mam_require_admin();

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

mam_audit('admin_reset_password', 'user', $userId);
mam_ok(['reset' => true]);
