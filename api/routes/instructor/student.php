<?php
/**
 * GET /api/instructor/student?id=<student_id>
 *
 * Drill-down for one student the caller actually teaches (admins: any student).
 * Returns the student's contact info, their enrollments in the caller's classes,
 * and their full attendance history there with a present/late/absent summary.
 */
declare(strict_types=1);

$u  = mam_require_role('instructor', 'admin');
$sid = (int)($_GET['id'] ?? 0);
if (!$sid) mam_fail('validation', 422, ['fields' => ['id']]);

$student = mam_db_row("SELECT id, name, email, phone FROM users WHERE id = ? AND role = 'student'", [$sid]);
if (!$student) mam_fail('not_found', 404);

// Scope to the caller's classes unless they're an admin.
$isAdmin = $u['role'] === 'admin';
$scope   = $isAdmin ? '' : ' AND c.instructor_id = ' . (int)$u['id'];

$enrollments = mam_db_all(
    "SELECT e.id AS enrollment_id, e.status, c.title, c.discipline, c.mode, c.schedule
       FROM enrollments e JOIN classes c ON c.id = e.class_id
      WHERE e.student_id = ?$scope ORDER BY c.title",
    [$sid]
);
// An instructor may only view a student they share a class with.
if (!$isAdmin && !$enrollments) mam_fail('forbidden', 403);

$attendance = mam_db_all(
    "SELECT a.session_date, a.status, a.notes, c.title
       FROM attendance a
       JOIN enrollments e ON e.id = a.enrollment_id
       JOIN classes c     ON c.id = e.class_id
      WHERE e.student_id = ?$scope ORDER BY a.session_date DESC LIMIT 80",
    [$sid]
);

$summary = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
foreach ($attendance as $a) { if (isset($summary[$a['status']])) $summary[$a['status']]++; }

mam_ok([
    'student'     => $student,
    'enrollments' => $enrollments,
    'attendance'  => $attendance,
    'summary'     => $summary,
]);
