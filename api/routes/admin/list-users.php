<?php
declare(strict_types=1);
mam_require_method('GET');
mam_require_admin();
$role = $_GET['role'] ?? '';
$where = ''; $params = [];
if (in_array($role, ['admin', 'instructor', 'student'], true)) {
    $where = 'WHERE role = ?'; $params = [$role];
}
$users = mam_db_all(
    "SELECT id, email, name, phone, role, status, last_login, created_at FROM users $where ORDER BY created_at DESC LIMIT 500",
    $params
);
mam_ok(['users' => $users]);
