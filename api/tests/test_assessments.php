<?php
/** Assessment → results engine — weighted averaging, grades, pass/fail. */
declare(strict_types=1);
t_group('assessments & results');

// Grade banding.
t_eq(mam_lms_grade(82)[0], 'A', '82% → A');
t_eq(mam_lms_grade(70)[0], 'B', '70% → B');
t_eq(mam_lms_grade(60)[0], 'C', '60% → C');
t_eq(mam_lms_grade(50)[0], 'D', '50% → D (pass mark)');
t_eq(mam_lms_grade(49)[0], 'F', '49% → F');

// Fresh student + enrolment to grade.
$sid = mam_db_insert('users', ['email' => 't_asg@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Grade Me', 'status' => 'active']);
$iid = mam_db_insert('users', ['email' => 't_asg_i@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Grader', 'status' => 'active']);
$cid = (int)mam_db_row("SELECT id FROM courses WHERE code='theory'")['id'];
$eid = mam_db_insert('course_enrollments', ['student_id' => $sid, 'course_id' => $cid, 'status' => 'active', 'instructor_id' => $iid]);

// No assessments yet → no result.
$r = mam_lms_compute_result($eid);
t_ok($r['total'] === null, 'no assessments → null total');

// The spec's example weighting: 10/20/30/10/30.
mam_lms_record_assessment($eid, 'attendance', $iid, 100);
mam_lms_record_assessment($eid, 'assignment', $iid, 80);
mam_lms_record_assessment($eid, 'practical',  $iid, 90);
mam_lms_record_assessment($eid, 'continuous', $iid, 70);
$r = mam_lms_record_assessment($eid, 'final', $iid, 60);
// 10*100 + 20*80 + 30*90 + 10*70 + 30*60 = 7800 / 100 = 78.0
t_near($r['total'], 78.0, 'weighted total = 78.0 (full 100% graded)');
t_eq($r['grade'], 'B', '78% → grade B');
t_eq($r['outcome'], 'pass', '78% ≥ pass mark → pass');
t_eq($r['graded_weight'], 100, 'all five components graded (weight 100)');

// Persisted to results + surfaced to the student.
$mine = mam_lms_student_courses($sid);
$row = $mine[0];
t_near($row['total_score'], 78.0, 'result persisted on the enrolment');
t_eq($row['outcome'], 'pass', 'student sees pass outcome');

// Partial gradebook normalises over graded weight (current standing).
$sid2 = mam_db_insert('users', ['email' => 't_asg2@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Partial', 'status' => 'active']);
$eid2 = mam_db_insert('course_enrollments', ['student_id' => $sid2, 'course_id' => $cid, 'status' => 'active', 'instructor_id' => $iid]);
$r2 = mam_lms_record_assessment($eid2, 'attendance', $iid, 40); // only one component
t_near($r2['total'], 40.0, 'partial: normalised over graded weight (40)');
t_eq($r2['outcome'], 'fail', '40% < pass mark → fail');
t_eq($r2['graded_weight'], 10, 'only attendance (weight 10) graded so far');

// Repeated scores of one type average.
$r3 = mam_lms_record_assessment($eid2, 'attendance', $iid, 100); // now avg(40,100)=70 for attendance
t_near($r3['total'], 70.0, 'two attendance scores average (40,100 → 70)');

// max_score scaling: 18/20 = 90%.
$sid3 = mam_db_insert('users', ['email' => 't_asg3@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Scaled', 'status' => 'active']);
$eid3 = mam_db_insert('course_enrollments', ['student_id' => $sid3, 'course_id' => $cid, 'status' => 'active', 'instructor_id' => $iid]);
$r4 = mam_lms_record_assessment($eid3, 'practical', $iid, 18, 20);
t_near($r4['total'], 90.0, 'score 18/20 scales to 90%');

// Unknown type rejected.
$bad = false;
try { mam_lms_record_assessment($eid3, 'nope', $iid, 50); } catch (InvalidArgumentException $e) { $bad = true; }
t_ok($bad, 'unknown assessment type rejected');

// Instructor gradebook lists the enrolment with its result.
$gb = mam_lms_instructor_gradebook($iid);
t_ok(count($gb) >= 3, 'gradebook lists the instructor\'s enrolments');
