<?php
declare(strict_types=1);

$in = mam_json_input();
$email = mam_email(mam_str($in, 'email', 120));
$name  = mam_str($in, 'name', 120);
$role  = mam_str($in, 'role', 16);
$phone = mam_str($in, 'phone', 24) ?: null;

$errors = [];
if (!$email) $errors[] = 'email';
if (!$name)  $errors[] = 'name';
if (!in_array($role, ['admin', 'instructor', 'student', 'parent'], true)) $errors[] = 'role';
if ($errors) mam_fail('validation', 422, ['fields' => $errors]);

$existing = mam_db_row('SELECT id FROM users WHERE email = ?', [$email]);
if ($existing) mam_fail('email_exists', 409);

$id = mam_db_insert('users', [
    'email' => $email,
    'name'  => $name,
    'phone' => $phone,
    'role'  => $role,
    // No password is set or emailed: the person is sent a link to choose one.
    'password_hash' => mam_auth_unusable_password(),
    'must_change_password' => 0,
]);

// Mirror the primary role into the multi-role join so the two role stores
// agree from creation (mam_user_has_role folds in the primary today, but any
// code reading user_roles alone would otherwise miss this user).
mam_user_ensure_role($id, $role);

if ($role === 'instructor') {
    mam_db_insert('instructor_profiles', ['user_id' => $id]);
}
if ($role === 'student') {
    try { mam_enrollment_ref_generate($id); } catch (Throwable $e) { /* non-fatal — admin can trigger later */ }
}

// The user already exists; a mail issue must not fail the admin action.
$invited = (bool) mam_auth_send_invite($id, $role, 'account_invite');

mam_audit('create', 'user', $id, ['role' => $role, 'email' => $email]);
mam_ok(['user_id' => $id, 'invited' => $invited]);
