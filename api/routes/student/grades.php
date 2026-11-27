<?php
/**
 * GET /api/student/grades — the signed-in student's gradebook: 4.0-scale GPA,
 * per-term trend, cohort standing (percentile/rank), and the per-course results
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

$percentiles = [];
$cohorts = mam_db_all(
    "SELECT DISTINCT co.id, co.title FROM course_enrollments ce
       JOIN cohorts co ON co.id = ce.cohort_id
      WHERE ce.student_id = ? AND ce.cohort_id IS NOT NULL",
    [$uid]
);
foreach ($cohorts as $c) {
    $p = mam_lms2_cohort_percentile($uid, (int) $c['id']);
    if ($p['percentile'] !== null) $percentiles[] = ['cohort_title' => $c['title']] + $p;
}

mam_ok([
    'gpa'         => mam_lms2_student_gpa($uid),
    'trend'       => mam_lms2_student_grade_trend($uid),
    'percentiles' => $percentiles,
    'results'     => $results,
    'pass_mark'   => function_exists('mam_lms_pass_mark') ? mam_lms_pass_mark() : 50,
]);
