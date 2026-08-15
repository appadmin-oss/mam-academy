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

// Store orders placed by this student (matched on email — the public store
// doesn't require an account, so email is the only stable link). Match BOTH the
// linked customer email AND the login email, so admin-created students (no
// customer row) still see — and can redeem credit against — their orders.
$orderEmails = array_values(array_unique(array_filter([
    isset($customer['email']) ? strtolower((string)$customer['email']) : null,
    strtolower((string)$u['email']),
])));
$storeOrders = $orderEmails ? $soft(fn () => mam_db_all(
    'SELECT id, reference, status, items_json, subtotal_usd, tax_usd, shipping_usd, total_usd, delivered_at, created_at
       FROM store_orders WHERE LOWER(customer_email) IN (' . implode(',', array_fill(0, count($orderEmails), '?')) . ') ORDER BY id DESC',
    $orderEmails
), []) : [];

$enrollments = $soft(fn () => mam_db_all(
    'SELECT e.id, e.status AS enrollment_status, e.enrolled_at, c.title, c.discipline, c.pathway, c.mode, c.schedule, c.starts_on, c.meeting_link, u.name AS instructor_name FROM enrollments e JOIN classes c ON c.id = e.class_id LEFT JOIN users u ON u.id = c.instructor_id WHERE e.student_id = ? ORDER BY c.starts_on DESC',
    [(int)$u['id']]
), []);

$attendance = $soft(fn () => mam_db_all(
    'SELECT a.session_date, a.status, c.title FROM attendance a JOIN enrollments e ON e.id = a.enrollment_id JOIN classes c ON c.id = e.class_id WHERE e.student_id = ? ORDER BY a.session_date DESC LIMIT 10',
    [(int)$u['id']]
), []);

$auditer = $soft(fn () => mam_db_row('SELECT * FROM auditer_results WHERE student_id = ? ORDER BY audited_at DESC LIMIT 1', [(int)$u['id']]), null);
// Live placement validity (the detailed auditer_results above has no writer yet,
// so the tab fell back to a permanent empty state). This gives the Auditer tab
// real, current status — student-exempt / valid-until / expired / not-taken.
$auditerStatus = $soft(fn () => $u['customer_id'] ? mam_auditer_status((int)$u['customer_id']) : null, null);

// Announcements from any class OR cohort the student is enrolled in (dual-read,
// P2): a course/cohort-only student now sees their instructor's updates too.
$announcements = $soft(fn () => mam_student_announcements((int)$u['id']), []);

// Learning resources for any class OR cohort the student is enrolled in (P2).
$resources = $soft(fn () => mam_student_resources((int)$u['id']), []);

// Assignments set by instructors (open first, then by due date).
$assignments = $soft(fn () => mam_db_all(
    "SELECT a.id, a.title, a.instructions, a.due_on, a.status, a.completed_at, a.created_at, a.class_id,
            a.reference_url, a.reference_kind,
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
$practice = $soft(fn () => mam_practice_stats((int)$u['id']), ['practice' => [], 'practiceStats' => ['streak' => 0, 'weekMin' => 0, 'total' => 0, 'sessions' => 0, 'weekDays' => []]]);

// Progress roll-up for the overview card.
// "Attended" = present OR late, matching the client's attendanceRate()/render —
// otherwise the overview progress bar disagreed with the Attendance tab/KPI.
$attCounts = $soft(fn () => mam_db_row(
    "SELECT COUNT(*) AS total, SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) AS present
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
        'student_type' => mam_user_is_scholarship($u) ? 'scholarship' : 'paying',
    ],
    'customer' => $customer,
    'applications' => $apps,
    'payments' => $payments,
    'enrollments' => $enrollments,
    'courses' => $courses,
    'certificates' => $certificates,
    'attendance' => $attendance,
    'auditer' => $auditer,
    'auditer_status' => $auditerStatus,
    'wallet' => ['balance_cents' => mam_wallet_balance((int)$u['id']), 'balance_usd' => round(mam_wallet_balance((int)$u['id']) / 100, 2)],
    'store_orders' => $storeOrders,
    'announcements' => $announcements,
    'resources' => $resources,
    'practice' => $practice['practice'],
    'practice_stats' => $practice['practiceStats'],
    'assignments' => $assignments,
    'progress' => $progress,
    'program_track' => $programTrack,
]);
