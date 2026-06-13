<?php
declare(strict_types=1);

$u = mam_require_role('student');

/**
 * Resilience: the dashboard aggregates many independent data sources, several
 * of which live in tables added by later migrations (v16 LMS, store, practice).
 * If a deployment's DB is behind on migrations (the #1 production gotcha) ONE
 * missing table must not 500 the student's entire home screen — it should just
 * omit that section. So every optional source runs through $soft(): it returns
 * the fallback and logs a warning on any error, while the admin overview and
 * /api/diag still surface the real "schema behind" banner for the operator.
 * Auth (above) and core identity stay strict.
 */
$soft = function (callable $fn, $default) {
    try { return $fn(); }
    catch (Throwable $e) { mam_log('warn', 'dashboard_section_failed', ['err' => $e->getMessage()]); return $default; }
};

$customer = $u['customer_id'] ? $soft(fn () => mam_db_row('SELECT * FROM customers WHERE id = ?', [(int)$u['customer_id']]), null) : null;

$apps = $customer ? $soft(fn () => mam_db_all(
    'SELECT id, reference, pathway, mode, discipline, total_usd, status, program_track, created_at FROM applications WHERE customer_id = ? ORDER BY created_at DESC',
    [(int)$customer['id']]
), []) : [];

// Program track drives the child-friendly KTAMS portal variant. KTAMS = the
// under-16 "Kids @ MAM" track; default to MAM (adult) when unknown.
$programTrack = 'MAM';
foreach ($apps as $a) { if (($a['program_track'] ?? 'MAM') === 'KTAMS') { $programTrack = 'KTAMS'; break; } }

// Student-visible payments span BOTH academy (joined via the application) and
// store orders (joined by the customer's email — store orders aren't tied to
// the academy customer record). A LEFT JOIN keeps the row even when neither
// match (e.g. an admin-attached manual payment), so nothing disappears.
$payments = $customer ? $soft(fn () => mam_db_all(
    "SELECT DISTINCT p.id, p.reference, p.provider, p.status, p.amount, p.currency, p.verified_at, p.created_at,
            COALESCE(p.purpose, 'tuition') AS purpose,
            a.id AS application_id
       FROM payments p
       LEFT JOIN applications a  ON a.id = p.application_id
       LEFT JOIN store_orders so ON so.reference = p.reference
      WHERE (a.customer_id = ?) OR (so.customer_email = ?)
      ORDER BY p.created_at DESC",
    [(int)$customer['id'], (string)$customer['email']]
), []) : [];

// Store orders placed by this customer (matched on email — public store
// doesn't require an account so the email is the only stable link).
$storeOrders = $customer ? $soft(fn () => mam_db_all(
    'SELECT id, reference, status, items_json, subtotal_usd, tax_usd, shipping_usd, total_usd, delivered_at, created_at
       FROM store_orders WHERE customer_email = ? ORDER BY id DESC',
    [(string)$customer['email']]
), []) : [];

$enrollments = $soft(fn () => mam_db_all(
    'SELECT e.id, e.status AS enrollment_status, e.enrolled_at, c.title, c.discipline, c.pathway, c.mode, c.schedule, c.starts_on, u.name AS instructor_name FROM enrollments e JOIN classes c ON c.id = e.class_id LEFT JOIN users u ON u.id = c.instructor_id WHERE e.student_id = ? ORDER BY c.starts_on DESC',
    [(int)$u['id']]
), []);

$attendance = $soft(fn () => mam_db_all(
    'SELECT a.session_date, a.status, c.title FROM attendance a JOIN enrollments e ON e.id = a.enrollment_id JOIN classes c ON c.id = e.class_id WHERE e.student_id = ? ORDER BY a.session_date DESC LIMIT 10',
    [(int)$u['id']]
), []);

$auditer = $soft(fn () => mam_db_row('SELECT * FROM auditer_results WHERE student_id = ? ORDER BY audited_at DESC LIMIT 1', [(int)$u['id']]), null);

// Announcements from any class the student is enrolled in.
$announcements = $soft(fn () => mam_db_all(
    "SELECT an.id, an.title, an.body, an.created_at, c.title AS class_title, usr.name AS author
       FROM announcements an
       JOIN classes c ON c.id = an.class_id
       JOIN enrollments e ON e.class_id = c.id
       LEFT JOIN users usr ON usr.id = an.author_id
      WHERE e.student_id = ? ORDER BY an.created_at DESC LIMIT 20",
    [(int)$u['id']]
), []);

// Learning resources for any class the student is enrolled in.
$resources = $soft(fn () => mam_db_all(
    "SELECT r.id, r.title, r.url, r.type, r.created_at, c.title AS class_title
       FROM class_resources r
       JOIN classes c ON c.id = r.class_id
       JOIN enrollments e ON e.class_id = c.id
      WHERE e.student_id = ? ORDER BY r.created_at DESC LIMIT 50",
    [(int)$u['id']]
), []);

// Assignments set by instructors (open first, then by due date).
$assignments = $soft(fn () => mam_db_all(
    "SELECT a.id, a.title, a.instructions, a.due_on, a.status, a.completed_at, a.created_at, a.class_id,
            c.title AS class_title, usr.name AS author
       FROM assignments a
       LEFT JOIN classes c ON c.id = a.class_id
       LEFT JOIN users usr ON usr.id = a.author_id
      WHERE a.student_id = ?
      ORDER BY (a.status = 'done'), COALESCE(a.due_on, a.created_at) ASC",
    [(int)$u['id']]
), []);

// Flexible course registrations (v16 LMS — distinct from legacy class
// enrollments above). Drives the "My Courses" tab + "Add a course" flow.
$courses = $soft(fn () => mam_lms_student_courses((int)$u['id']), []);
// Earned certificates (issued on a passed, completed course/programme).
$certificates = $soft(fn () => mam_lms_student_certificates((int)$u['id']), []);

// Server-persisted practice stats (cross-device).
$practice = $soft(fn () => mam_practice_stats((int)$u['id']), ['practice' => [], 'practiceStats' => ['streak' => 0, 'weekMin' => 0, 'total' => 0, 'sessions' => 0]]);

// Progress roll-up for the overview card.
$attCounts = $soft(fn () => mam_db_row(
    "SELECT COUNT(*) AS total, SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present
       FROM attendance a JOIN enrollments e ON e.id = a.enrollment_id WHERE e.student_id = ?",
    [(int)$u['id']]
), ['total' => 0, 'present' => 0]) ?: ['total' => 0, 'present' => 0];
$asgDone = 0; foreach ($assignments as $a) { if (($a['status'] ?? '') === 'done') $asgDone++; }
$progress = [
    'assignments_total'  => count($assignments),
    'assignments_done'   => $asgDone,
    'assignments_open'   => count($assignments) - $asgDone,
    'attendance_total'   => (int)($attCounts['total'] ?? 0),
    'attendance_present' => (int)($attCounts['present'] ?? 0),
    'classes'            => count($enrollments),
];

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
    'courses' => $courses,
    'certificates' => $certificates,
    'attendance' => $attendance,
    'auditer' => $auditer,
    'store_orders' => $storeOrders,
    'announcements' => $announcements,
    'resources' => $resources,
    'practice' => $practice['practice'],
    'practice_stats' => $practice['practiceStats'],
    'assignments' => $assignments,
    'progress' => $progress,
    'program_track' => $programTrack,
]);
