<?php
declare(strict_types=1);

$u = mam_require_role('student');

$customer = $u['customer_id'] ? mam_db_row('SELECT * FROM customers WHERE id = ?', [(int)$u['customer_id']]) : null;

$apps = $customer ? mam_db_all(
    'SELECT id, reference, pathway, mode, discipline, total_usd, status, created_at FROM applications WHERE customer_id = ? ORDER BY created_at DESC',
    [(int)$customer['id']]
) : [];

// Student-visible payments span BOTH academy (joined via the application) and
// store orders (joined by the customer's email — store orders aren't tied to
// the academy customer record). A LEFT JOIN keeps the row even when neither
// match (e.g. an admin-attached manual payment), so nothing disappears.
$payments = $customer ? mam_db_all(
    "SELECT DISTINCT p.id, p.reference, p.provider, p.status, p.amount, p.currency, p.verified_at, p.created_at,
            COALESCE(p.purpose, 'tuition') AS purpose,
            a.id AS application_id
       FROM payments p
       LEFT JOIN applications a  ON a.id = p.application_id
       LEFT JOIN store_orders so ON so.reference = p.reference
      WHERE (a.customer_id = ?) OR (so.customer_email = ?)
      ORDER BY p.created_at DESC",
    [(int)$customer['id'], (string)$customer['email']]
) : [];

// Store orders placed by this customer (matched on email — public store
// doesn't require an account so the email is the only stable link).
$storeOrders = $customer ? mam_db_all(
    'SELECT id, reference, status, items_json, subtotal_usd, tax_usd, shipping_usd, total_usd, delivered_at, created_at
       FROM store_orders WHERE customer_email = ? ORDER BY id DESC',
    [(string)$customer['email']]
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

// Announcements from any class the student is enrolled in.
$announcements = mam_db_all(
    "SELECT an.id, an.title, an.body, an.created_at, c.title AS class_title, usr.name AS author
       FROM announcements an
       JOIN classes c ON c.id = an.class_id
       JOIN enrollments e ON e.class_id = c.id
       LEFT JOIN users usr ON usr.id = an.author_id
      WHERE e.student_id = ? ORDER BY an.created_at DESC LIMIT 20",
    [(int)$u['id']]
);

// Learning resources for any class the student is enrolled in.
$resources = mam_db_all(
    "SELECT r.id, r.title, r.url, r.type, r.created_at, c.title AS class_title
       FROM class_resources r
       JOIN classes c ON c.id = r.class_id
       JOIN enrollments e ON e.class_id = c.id
      WHERE e.student_id = ? ORDER BY r.created_at DESC LIMIT 50",
    [(int)$u['id']]
);

// Server-persisted practice stats (cross-device).
$practice = mam_practice_stats((int)$u['id']);

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
    'store_orders' => $storeOrders,
    'announcements' => $announcements,
    'resources' => $resources,
    'practice' => $practice['practice'],
    'practice_stats' => $practice['practiceStats'],
]);
