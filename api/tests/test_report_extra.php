<?php
/**
 * Tests for the LMS 2.0 learner-report enrichment (mam_progress_report_data):
 * lesson progress, attendance, rubric feedback, reschedule history, and the
 * academic (GPA / trend / percentile) section — all soft-degrading.
 */
declare(strict_types=1);

t_group('learner report — LMS 2.0 sections');

$RX_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($RX_course === 0) $RX_course = mam_db_insert('courses', ['code' => 'rx_c', 'name' => 'RX', 'kind' => 'core']);
$RX_ins = mam_db_insert('users', ['name' => 'RX Ins', 'email' => 'rx_ins@test.local', 'role' => 'instructor', 'password_hash' => '', 'status' => 'active']);
$RX_stu = mam_db_insert('users', ['name' => 'RX Stu', 'email' => 'rx_stu@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active', 'enrollment_ref' => 'MAM/26/RX']);
$RX_coh = mam_db_insert('cohorts', ['course_id' => $RX_course, 'title' => 'RX Cohort', 'term' => 'T1', 'status' => 'active', 'capacity' => 10, 'starts_on' => date('Y-m-d', time() - 14 * 86400), 'ends_on' => date('Y-m-d', time() + 70 * 86400)]);
$RX_enr = mam_db_insert('course_enrollments', ['student_id' => $RX_stu, 'course_id' => $RX_course, 'cohort_id' => $RX_coh, 'status' => 'active']);

// Lessons: 2 published, 1 completed → 50%.
$RX_l1 = mam_db_insert('lessons', ['cohort_id' => $RX_coh, 'title' => 'RX L1', 'published' => 1]);
mam_db_insert('lessons', ['cohort_id' => $RX_coh, 'title' => 'RX L2', 'published' => 1]);
mam_db_insert('lesson_progress', ['lesson_id' => $RX_l1, 'student_id' => $RX_stu, 'status' => 'completed', 'percent' => 100]);

// A held past session the student attended → attendance rate/streak.
$RX_sess = mam_db_insert('class_sessions', ['cohort_id' => $RX_coh, 'title' => 'RX Past', 'scheduled_at' => date('Y-m-d H:i:s', time() - 86400), 'status' => 'held', 'duration_min' => 60, 'kind' => 'class']);
mam_db_insert('session_attendance', ['session_id' => $RX_sess, 'student_id' => $RX_stu, 'status' => 'present', 'method' => 'self']);

// Rubric-scored submission → also feeds a result (so GPA is populated).
$RX_rub = mam_db_insert('rubrics', ['title' => 'RX Rubric', 'created_by' => $RX_ins]);
$RX_crit = mam_db_insert('rubric_criteria', ['rubric_id' => $RX_rub, 'label' => 'Overall', 'max_points' => 100, 'weight' => 1.0]);
$RX_grp = mam_lms2_assignment_group_create(['cohort_id' => $RX_coh, 'author_id' => $RX_ins, 'title' => 'RX Task']);
mam_lms2_assignment_group_fanout($RX_grp, [$RX_stu]);
$RX_asg = (int) mam_db_row('SELECT id FROM assignments WHERE group_id = ? AND student_id = ?', [$RX_grp, $RX_stu])['id'];
$RX_sub = mam_db_insert('submissions', ['assignment_id' => $RX_asg, 'student_id' => $RX_stu, 'artifact_url' => 'https://x', 'artifact_kind' => 'link']);
mam_lms2_submission_review_save($RX_sub, $RX_rub, $RX_ins, [$RX_crit => 88], 'Great', $RX_enr, 'assignment');

// A reschedule request in the student's history (row-level; feature flow tested elsewhere).
mam_db_insert('reschedule_requests', ['requester_id' => $RX_stu, 'cohort_id' => $RX_coh, 'status' => 'applied', 'session_count' => 1, 'fee_usd_cents' => 1000, 'fee_currency' => 'USD']);

$rep = mam_progress_report_data($RX_stu);

// New keys are present and additive.
foreach (['lesson_progress', 'attendance', 'rubric_reviews', 'reschedule_history', 'academic'] as $k) {
    t_ok(array_key_exists($k, $rep), "report has the '$k' section");
}

// Lesson progress: 1 of 2 → 50%.
$lp = array_values(array_filter($rep['lesson_progress'], fn($r) => (int) $r['cohort_id'] === $RX_coh));
t_ok(count($lp) === 1, 'one lesson-progress row for the cohort');
t_eq((float) $lp[0]['percent'], 50.0, 'lesson progress is 50% (1 of 2)');

// Attendance section carries a rate + streak.
t_ok($rep['attendance'] !== null, 'attendance section present');
t_ok($rep['attendance']['streak'] >= 1, 'attendance streak counts the attended session');

// Rubric feedback surfaced.
$mine = array_values(array_filter($rep['rubric_reviews'], fn($r) => (string) $r['assignment_title'] === 'RX Task'));
t_ok(count($mine) === 1, 'rubric review appears in the report');
t_near((float) $mine[0]['total_points'], 88.0, 'rubric review carries the score');

// Reschedule history.
t_ok(count($rep['reschedule_history']) >= 1, 'reschedule history present');

// Academic summary: GPA populated from the wired result.
t_ok($rep['academic'] !== null && $rep['academic']['gpa']['courses'] >= 1, 'academic GPA reflects the graded course');
t_ok(is_array($rep['academic']['trend']), 'academic trend is a list');

// Existing sections still intact (no regression).
foreach (['student', 'results', 'assessments', 'generated_at'] as $k) {
    t_ok(array_key_exists($k, $rep), "existing '$k' section still present");
}
