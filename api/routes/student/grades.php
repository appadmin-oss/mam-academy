<?php
/**
 * GET /api/student/grades — the signed-in student's gradebook: 4.0-scale GPA,
 * per-term trend, and the per-course results (no cohort rank — refusal 5)
 * table. Read-only; all aggregates come from api/modules/academics/lib/lms2.php.
 */
declare(strict_types=1);

$u   = mam_require_user();
$uid = (int) $u['id'];

$results = mam_db_all(
    "SELECT r.total_score, r.grade, r.outcome, r.finalized_at,
            c.name AS course_name, co.title AS cohort_title, l.name AS level_name
       FROM results r
       JOIN course_enrollments ce ON ce.id = r.enrollment_id
       JOIN courses c ON c.id = ce.course_id
       LEFT JOIN cohorts co ON co.id = ce.cohort_id
       LEFT JOIN levels l ON l.id = ce.level_id
      WHERE ce.student_id = ?
      ORDER BY r.finalized_at DESC",
    [$uid]
);

// No cohort rank or percentile is sent to a student: the academy refuses to
// rank children against classmates (refusal 5). `percentiles` stays, empty.
$percentiles = [];

mam_ok([
    'gpa'         => mam_lms2_student_gpa($uid),
    'trend'       => mam_lms2_student_grade_trend($uid),
    'percentiles' => $percentiles,
    'results'     => $results,
    'pass_mark'   => function_exists('mam_lms_pass_mark') ? mam_lms_pass_mark() : 50,
]);
