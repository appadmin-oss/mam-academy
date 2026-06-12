<?php
/**
 * GET /api/student/assessments?enrollment_id=N
 *
 * A student's per-component gradebook breakdown for ONE of their own course
 * enrolments: every recorded assessment (component, weight, score, week) plus
 * the computed standing — so the final grade is never a black box.
 */
declare(strict_types=1);

$u = mam_require_role('student');

$eid = (int)($_GET['enrollment_id'] ?? 0);
if ($eid <= 0) mam_fail('validation', 422, ['fields' => ['enrollment_id']]);

// Ownership: the enrolment must belong to the requesting student.
$enr = mam_db_row(
    "SELECT ce.id, ce.status, c.name AS course_name, l.name AS level_name,
            r.total_score, r.grade, r.outcome
       FROM course_enrollments ce
       JOIN courses c       ON c.id = ce.course_id
       LEFT JOIN levels l   ON l.id = ce.level_id
       LEFT JOIN results r  ON r.enrollment_id = ce.id
      WHERE ce.id = ? AND ce.student_id = ?",
    [$eid, (int)$u['id']]
);
if (!$enr) mam_fail('enrollment_not_found', 404);

mam_ok([
    'enrollment'  => $enr,
    'assessments' => mam_lms_enrollment_assessments($eid),
    'pass_mark'   => mam_lms_pass_mark(),
]);
