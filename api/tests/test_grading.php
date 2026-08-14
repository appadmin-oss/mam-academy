<?php
/**
 * Tests for the grading upgrade (api/lib/lms2.php): rubric scores wired into the
 * gradebook, submission→enrolment resolution, and the term GPA / trend /
 * percentile aggregates.
 */
declare(strict_types=1);

t_group('grading — rubric score feeds the gradebook');

$GR_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($GR_course === 0) $GR_course = mam_db_insert('courses', ['code' => 'gr_c', 'name' => 'GR', 'kind' => 'core']);
$GR_ins = mam_db_insert('users', ['name' => 'GR Ins', 'email' => 'gr_ins@test.local', 'role' => 'instructor', 'password_hash' => '', 'status' => 'active']);
$GR_stu = mam_db_insert('users', ['name' => 'GR Stu', 'email' => 'gr_stu@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
$GR_coh = mam_db_insert('cohorts', ['course_id' => $GR_course, 'title' => 'GR Cohort', 'term' => 'T1', 'status' => 'active', 'capacity' => 10]);
$GR_enr = mam_db_insert('course_enrollments', ['student_id' => $GR_stu, 'course_id' => $GR_course, 'cohort_id' => $GR_coh, 'status' => 'active']);

// A rubric with a single 100-point criterion.
$GR_rub = mam_db_insert('rubrics', ['title' => 'GR Rubric', 'created_by' => $GR_ins]);
$GR_crit = mam_db_insert('rubric_criteria', ['rubric_id' => $GR_rub, 'label' => 'Overall', 'max_points' => 100, 'weight' => 1.0]);

// Assignment → submission for this student, grouped so the enrolment resolves.
$GR_grp = mam_lms2_assignment_group_create(['cohort_id' => $GR_coh, 'author_id' => $GR_ins, 'title' => 'GR Task']);
mam_lms2_assignment_group_fanout($GR_grp, [$GR_stu]);
$GR_asg = (int) mam_db_row('SELECT id FROM assignments WHERE group_id = ? AND student_id = ?', [$GR_grp, $GR_stu])['id'];
$GR_sub = mam_db_insert('submissions', ['assignment_id' => $GR_asg, 'student_id' => $GR_stu, 'artifact_url' => 'https://x', 'artifact_kind' => 'link']);

t_eq(mam_lms2_submission_enrollment($GR_sub), $GR_enr, 'submission resolves to the student\'s enrolment via the group cohort');

// Score 80/100 → the gradebook records an 'assignment' assessment → result 80 (A).
mam_lms2_submission_review_save($GR_sub, $GR_rub, $GR_ins, [$GR_crit => 80], 'Solid', $GR_enr, 'assignment');
$res = mam_db_row('SELECT total_score, grade, outcome FROM results WHERE enrollment_id = ?', [$GR_enr]);
t_ok($res !== null, 'a result row was computed from the rubric score');
t_near((float)$res['total_score'], 80.0, 'rubric 80% flows through to the computed total');
t_eq($res['grade'], 'A', '80% → grade A');
t_eq($res['outcome'], 'pass', 'passing outcome');

t_group('grading — term GPA');

$GP_stu = mam_db_insert('users', ['name' => 'GPA Stu', 'email' => 'gpa_stu@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
$mkResult = function (int $student, int $course, string $term, float $score, string $grade) {
    $coh = mam_db_insert('cohorts', ['course_id' => $course, 'title' => "GPA $term $score", 'term' => $term, 'status' => 'active', 'capacity' => 10]);
    $enr = mam_db_insert('course_enrollments', ['student_id' => $student, 'course_id' => $course, 'cohort_id' => $coh, 'status' => 'completed']);
    mam_db_insert('results', ['enrollment_id' => $enr, 'total_score' => $score, 'grade' => $grade, 'outcome' => ($grade === 'F' ? 'fail' : 'pass')]);
    return ['cohort' => $coh, 'enr' => $enr];
};
$mkResult($GP_stu, $GR_course, 'T1', 85.0, 'A'); // 4.0
$mkResult($GP_stu, $GR_course, 'T1', 62.0, 'C'); // 2.0
$gpa = mam_lms2_student_gpa($GP_stu);
t_eq($gpa['courses'], 2, 'GPA spans both finalised courses');
t_eq($gpa['gpa'], 3.0, 'GPA = mean of grade points (4.0 + 2.0)/2');
t_near($gpa['avg_score'], 73.5, 'mean percentage across courses');
t_eq($gpa['distribution']['A'], 1, 'distribution counts the A');

t_group('grading — term trend');

$mkResult($GP_stu, $GR_course, 'T2', 90.0, 'A');
$trend = mam_lms2_student_grade_trend($GP_stu);
t_eq(count($trend), 2, 'trend has one point per term (T1, T2)');
t_eq($trend[0]['term'], 'T1', 'trend ordered by term');
t_near($trend[0]['avg_score'], 73.5, 'T1 mean = (85 + 62)/2');
t_near($trend[1]['avg_score'], 90.0, 'T2 mean = 90');

t_group('grading — cohort percentile');

$PC_course = $GR_course;
$PC_coh = mam_db_insert('cohorts', ['course_id' => $PC_course, 'title' => 'PC Cohort', 'term' => 'T3', 'status' => 'active', 'capacity' => 10]);
$scores = [40.0, 60.0, 75.0, 90.0]; $target = null;
foreach ($scores as $i => $sc) {
    $st = mam_db_insert('users', ['name' => "PC $i", 'email' => "pc$i@test.local", 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
    $en = mam_db_insert('course_enrollments', ['student_id' => $st, 'course_id' => $PC_course, 'cohort_id' => $PC_coh, 'status' => 'completed']);
    mam_db_insert('results', ['enrollment_id' => $en, 'total_score' => $sc, 'grade' => 'C', 'outcome' => 'pass']);
    if ($sc === 75.0) $target = $st;
}
$pc = mam_lms2_cohort_percentile($target, $PC_coh);
t_eq($pc['cohort_size'], 4, 'percentile sees all 4 cohort results');
t_eq($pc['rank'], 2, 'the 75 scorer ranks 2nd of 4');
t_eq($pc['percentile'], 75.0, '3 of 4 scores are at or below 75 → 75th percentile');
