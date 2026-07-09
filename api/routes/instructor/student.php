<?php
/**
 * GET /api/instructor/student?id=<student_id>
 *
 * Drill-down for one student the caller actually teaches (admins: any student).
 * Returns the student's contact info, their enrolments — in BOTH models, the
 * caller's legacy classes AND the courses/cohorts they teach (dual-read, P2) —
 * and their attendance history (legacy class attendance + cohort session
 * attendance) with a combined present/late/absent summary.
 */
declare(strict_types=1);

$u  = mam_require_role('instructor', 'admin');
$sid = (int)($_GET['id'] ?? 0);
if (!$sid) mam_fail('validation', 422, ['fields' => ['id']]);

$student = mam_db_row("SELECT id, name, email, phone FROM users WHERE id = ? AND role = 'student'", [$sid]);
if (!$student) mam_fail('not_found', 404);

// Scope to the caller's own teaching unless they're an admin. Both scopes are
// built from an (int)-cast id, so there's no injection surface.
$isAdmin = $u['role'] === 'admin';
$scope   = $isAdmin ? '' : ' AND c.instructor_id = ' . (int)$u['id'];
$cscope  = $isAdmin ? '' : ' AND ce.instructor_id = ' . (int)$u['id'];

$enrollments = mam_db_all(
    "SELECT e.id AS enrollment_id, e.status, c.title, c.discipline, c.mode, c.schedule
       FROM enrollments e JOIN classes c ON c.id = e.class_id
      WHERE e.student_id = ?$scope ORDER BY c.title",
    [$sid]
);

// Dual-read: the courses/cohorts the caller teaches this student in.
$courses = mam_db_all(
    "SELECT ce.id AS enrollment_id, ce.status, ce.cohort_id,
            co.name AS course_name, l.name AS level_name, coh.title AS cohort_title
       FROM course_enrollments ce
       JOIN courses co       ON co.id = ce.course_id
       LEFT JOIN levels l    ON l.id  = ce.level_id
       LEFT JOIN cohorts coh ON coh.id = ce.cohort_id
      WHERE ce.student_id = ?$cscope ORDER BY co.name",
    [$sid]
);

// An instructor may only view a student they share EITHER model with.
if (!$isAdmin && !$enrollments && !$courses) mam_fail('forbidden', 403);

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

// Dual-read: cohort session attendance, scoped to the cohorts the caller
// teaches this student in, folded into the same summary.
$sessionAttendance = [];
$cohortIds = array_values(array_filter(array_map(static fn($c) => (int)$c['cohort_id'], $courses)));
if ($cohortIds) {
    $ph = implode(',', array_fill(0, count($cohortIds), '?'));
    $sessionAttendance = mam_db_all(
        "SELECT sa.status, sa.notes, cs.scheduled_at, cs.title AS session_title, coh.title AS cohort_title
           FROM session_attendance sa
           JOIN class_sessions cs ON cs.id = sa.session_id
           JOIN cohorts coh       ON coh.id = cs.cohort_id
          WHERE sa.student_id = ? AND cs.cohort_id IN ($ph)
          ORDER BY cs.scheduled_at DESC LIMIT 80",
        array_merge([$sid], $cohortIds)
    );
    foreach ($sessionAttendance as $a) { if (isset($summary[$a['status']])) $summary[$a['status']]++; }
}

mam_ok([
    'student'            => $student,
    'enrollments'        => $enrollments,
    'courses'            => $courses,
    'attendance'         => $attendance,
    'session_attendance' => $sessionAttendance,
    'summary'            => $summary,
]);
