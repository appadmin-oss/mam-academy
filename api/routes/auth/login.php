<?php
declare(strict_types=1);
mam_require_method('POST');
mam_csrf_require();
mam_rate_limit('login', mam_client_ip(), 8, 600);

$in = mam_json_input();
$email = mam_str($in, 'email', 120);
$password = mam_str($in, 'password', 200);

if (!$email || !$password) mam_fail('validation', 422, ['fields' => ['email', 'password']]);

$user = mam_login($email, $password);
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
