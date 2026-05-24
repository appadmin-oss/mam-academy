<?php
declare(strict_types=1);
mam_require_method('POST');
mam_csrf_require();
$u = mam_require_role('student');

$in = mam_json_input();
$name = mam_str($in, 'name', 120);
$phone = mam_str($in, 'phone', 24);

if ($name === '') mam_fail('validation', 422, ['fields' => ['name']]);

mam_db_update('users', [
    'name' => $name,
    'phone' => $phone ?: null,
    'updated_at' => date('Y-m-d H:i:s'),
], ['id' => (int)$u['id']]);

if ($u['customer_id']) {
    $parts = explode(' ', $name, 2);
    mam_db_update('customers', [
        'first_name' => $parts[0],
        'last_name'  => $parts[1] ?? '',
        'phone' => $phone ?: null,
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['id' => (int)$u['customer_id']]);
}

mam_audit('update', 'user', (int)$u['id'], ['fields' => ['name', 'phone']]);
mam_ok(['updated' => true]);
