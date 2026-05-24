<?php
declare(strict_types=1);
mam_require_method('POST');
mam_csrf_require();
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
mam_audit('change_password', 'user', (int)$u['id']);
mam_ok(['changed' => true]);
