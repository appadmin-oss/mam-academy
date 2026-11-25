<?php
/**
 * GET /api/instructor/student?id=<student_id>
 *
 * Drill-down for one student the caller actually teaches (admins: any student).
 * Returns the student's contact info, the courses/cohorts the caller teaches
 * them in, and their cohort session attendance with a present/late/absent
 * summary. Cohort model only: the legacy class model is retired, its classes,
 * enrolments and marks carried into cohorts by mam_legacy_carry().
 */
declare(strict_types=1);

$u  = mam_require_role('instructor', 'admin');
$sid = (int)($_GET['id'] ?? 0);
if (!$sid) mam_fail('validation', 422, ['fields' => ['id']]);

$isAdmin = $u['role'] === 'admin';

$student = mam_db_row("SELECT id, name, email, phone, enrollment_ref FROM users WHERE id = ? AND role = 'student'", [$sid]);
if (!$student) mam_fail('not_found', 404);

// Instructors only see name + enrollment_ref — contact details are admin-only.
if (!$isAdmin) unset($student['email'], $student['phone']);
// A child's record is closed to an uncleared teacher once the academy enforces (vetting.php).
if (!$isAdmin && function_exists('mam_vetting_may_see_child') && !mam_vetting_may_see_child((int) $u['id'], $sid)) {
    mam_fail('clearance_required', 403, ['message' => 'Your safeguarding clearance is not complete, so this child\'s record is closed to you. The office can see what is missing.']);
}
$meId    = (int)$u['id'];
// A course the caller teaches: either assigned as the enrolment's instructor OR
// the owner of its cohort. course_enrollments.instructor_id is often NULL (set
// later by admin), so cohort ownership must also grant the drill-down — matching
// mam_lms_instructor_owns_cohort — else a legitimately-teaching instructor 403s.
$cscope  = $isAdmin ? '' : ' AND (ce.instructor_id = ' . $meId . ' OR coh.instructor_id = ' . $meId . ')';

// The courses/cohorts the caller teaches this student in.
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

// An instructor may only view a student they teach.
if (!$isAdmin && !$courses) mam_fail('forbidden', 403);

$summary = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];

// Cohort session attendance, scoped to the cohorts the caller teaches this
// student in (carried legacy marks arrive here as method 'legacy').
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
    'courses'            => $courses,
    'session_attendance' => $sessionAttendance,
    'summary'            => $summary,
]);
