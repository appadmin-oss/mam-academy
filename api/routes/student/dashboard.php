<?php
declare(strict_types=1);

$u = mam_require_role('student');

$customer = $u['customer_id'] ? mam_db_row('SELECT * FROM customers WHERE id = ?', [(int)$u['customer_id']]) : null;

$apps = $customer ? mam_db_all(
    'SELECT id, reference, pathway, mode, discipline, total_usd, status, created_at FROM applications WHERE customer_id = ? ORDER BY created_at DESC',
    [(int)$customer['id']]
) : [];

$payments = $customer ? mam_db_all(
    'SELECT p.id, p.reference, p.provider, p.status, p.amount, p.currency, p.verified_at, p.created_at FROM payments p JOIN applications a ON a.id = p.application_id WHERE a.customer_id = ? ORDER BY p.created_at DESC',
    [(int)$customer['id']]
) : [];

$enrollments = mam_db_all(
    'SELECT e.id, e.status AS enrollment_status, e.enrolled_at, c.title, c.discipline, c.pathway, c.mode, c.schedule, c.starts_on, u.name AS instructor_name FROM enrollments e JOIN classes c ON c.id = e.class_id LEFT JOIN users u ON u.id = c.instructor_id WHERE e.student_id = ? ORDER BY c.starts_on DESC',
    [(int)$u['id']]
);

$attendance = mam_db_all(
    'SELECT a.session_date, a.status, c.title FROM attendance a JOIN enrollments e ON e.id = a.enrollment_id JOIN classes c ON c.id = e.class_id WHERE e.student_id = ? ORDER BY a.session_date DESC LIMIT 10',
    [(int)$u['id']]
);

$auditer = mam_db_row('SELECT * FROM auditer_results WHERE student_id = ? ORDER BY audited_at DESC LIMIT 1', [(int)$u['id']]);

mam_ok([
    'user' => [
        'id' => (int)$u['id'],
        'name' => $u['name'],
        'email' => $u['email'],
        'phone' => $u['phone'],
        'avatar_url' => $u['avatar_url'],
        'must_change_password' => (int)$u['must_change_password'] === 1,
    ],
    'customer' => $customer,
    'applications' => $apps,
    'payments' => $payments,
    'enrollments' => $enrollments,
    'attendance' => $attendance,
    'auditer' => $auditer,
]);
