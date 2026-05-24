<?php
declare(strict_types=1);
mam_require_method('POST');
mam_csrf_require();
mam_require_admin();

$in = mam_json_input();
$email = mam_email(mam_str($in, 'email', 120));
$name  = mam_str($in, 'name', 120);
$role  = mam_str($in, 'role', 16);
$phone = mam_str($in, 'phone', 24) ?: null;
$password = mam_str($in, 'password', 200);

$errors = [];
if (!$email) $errors[] = 'email';
if (!$name)  $errors[] = 'name';
if (!in_array($role, ['admin', 'instructor', 'student'], true)) $errors[] = 'role';
if (strlen($password) < 8) $errors[] = 'password';
if ($errors) mam_fail('validation', 422, ['fields' => $errors]);

$existing = mam_db_row('SELECT id FROM users WHERE email = ?', [$email]);
if ($existing) mam_fail('email_exists', 409);

$id = mam_db_insert('users', [
    'email' => $email,
    'name'  => $name,
    'phone' => $phone,
    'role'  => $role,
    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
    'must_change_password' => 1,
]);

if ($role === 'instructor') {
    mam_db_insert('instructor_profiles', ['user_id' => $id]);
}

mam_audit('create', 'user', $id, ['role' => $role, 'email' => $email]);
mam_ok(['user_id' => $id]);
