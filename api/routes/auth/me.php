<?php
declare(strict_types=1);
mam_require_method('GET');
$u = mam_current_user();
if (!$u) mam_ok(['authenticated' => false, 'user' => null]);
mam_ok([
    'authenticated' => true,
    'user' => [
        'id' => (int)$u['id'],
        'email' => $u['email'],
        'name' => $u['name'],
        'role' => $u['role'],
        'avatar_url' => $u['avatar_url'],
        'must_change_password' => (int)$u['must_change_password'] === 1,
    ],
]);
