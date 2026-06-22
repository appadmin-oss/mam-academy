<?php
declare(strict_types=1);

$in = mam_json_input();
$email = mam_str($in, 'email', 120);
$password = mam_secret($in, 'password');
$remember = isset($in['remember']) ? (bool)$in['remember'] : true;

if (!$email || !$password) mam_fail('validation', 422, ['fields' => ['email', 'password']]);

// On a fresh install, provision the env-configured admin so the very first
// sign-in works with zero manual steps. No-op once any user exists.
mam_maybe_seed_admin_from_env();

// Fresh install with no accounts → point the operator at the installer instead
// of a confusing "invalid credentials". The UI turns this into a setup link.
if (mam_user_count() === 0) {
    mam_fail('setup_required', 409, ['setup_url' => '/api/setup']);
}

$user = mam_login($email, $password, $remember);
if (!$user) mam_fail('invalid_credentials', 401);

mam_ok([
    'user' => [
        'id' => (int)$user['id'],
        'email' => $user['email'],
        'name' => $user['name'],
        'role' => $user['role'],
        'must_change_password' => (int)$user['must_change_password'] === 1,
    ],
    'redirect' => mam_post_login_redirect($user['role']),
]);

function mam_post_login_redirect(string $role): string {
    switch ($role) {
        case 'admin':      return '/admin/';
        case 'instructor': return '/portal/instructor/';
        default:           return '/portal/student/';
    }
}
