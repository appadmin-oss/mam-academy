<?php
declare(strict_types=1);

$in = mam_json_input();
$email = mam_str($in, 'email', 120);
$password = mam_secret($in, 'password');
$remember = isset($in['remember']) ? (bool)$in['remember'] : true;

if (!$email || !$password) mam_fail('validation', 422, ['fields' => ['email', 'password']]);

// Per-account throttle, on top of the per-IP limit in the router: a distributed
// attacker rotating source IPs still can't brute-force a single account. Fails
// closed so a DB error can't silently disable the protection.
mam_rate_limit('login_email', strtolower(trim($email)), 12, 900, true);

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

$roles = mam_user_roles($user);

mam_ok([
    'user' => [
        'id' => (int)$user['id'],
        'email' => $user['email'],
        'name' => $user['name'],
        'role' => $user['role'],
        'roles' => $roles,
        'must_change_password' => (int)$user['must_change_password'] === 1,
    ],
    'redirect' => mam_post_login_redirect($roles),
]);
