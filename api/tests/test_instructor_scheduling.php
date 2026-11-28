<?php
/**
 * Instructor scheduling + attendance ownership (LMS L2). The service that backs
 * the instructor routes: which cohorts an instructor is responsible for, the
 * ownership check, the cohort roster, and the session mark sheet.
 */
declare(strict_types=1);
t_group('instructor scheduling (ownership + roster, L2)');

$ins   = mam_db_insert('users', ['email' => 't_isch_ins@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Tutor T', 'status' => 'active']);
$other = mam_db_insert('users', ['email' => 't_isch_oth@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Other O', 'status' => 'active']);
$stu   = mam_db_insert('users', ['email' => 't_isch_stu@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Pupil P', 'status' => 'active']);

$cid = mam_lms_create_cohort(['title' => 'Tutor Cohort']);
$pianoId = (int)mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];
$eid = mam_db_insert('course_enrollments', ['student_id' => $stu, 'course_id' => $pianoId, 'status' => 'active', 'instructor_id' => $ins, 'cohort_id' => $cid]);

// ── Ownership ──
/* Changed 2026-11-28 (teaching model, teaching.php): being named on ONE
   student's enrolment used to make an instructor own the whole cohort — the
   bug the classes audit found (and they kept it after that student withdrew).
   A class is owned by its staff; the enrolment's teacher reaches that student. */
t_ok(!mam_lms_instructor_owns_cohort($ins, $cid), 'being named on one enrolment does NOT make an instructor own the cohort');
t_ok(mam_teaches_student($ins, $stu), '…it makes them the teacher of that student');
mam_cohort_set_lead($cid, $ins, null);
t_ok(mam_lms_instructor_owns_cohort($ins, $cid), 'the cohort\'s lead owns it');
t_ok(!mam_lms_instructor_owns_cohort($other, $cid), 'an unrelated instructor does not own the cohort');
$mine = array_map(function ($c) { return (int)$c['id']; }, mam_lms_instructor_cohorts($ins));
t_ok(in_array($cid, $mine, true), 'instructor_cohorts lists the owned cohort');
t_ok(!in_array($cid, array_map(function ($c) { return (int)$c['id']; }, mam_lms_instructor_cohorts($other)), true), 'instructor_cohorts excludes cohorts the instructor does not teach');

// ── Roster ──
$roster = mam_lms_cohort_roster($cid);
t_eq(count($roster), 1, 'cohort roster has the one enrolled student');
t_eq((int)$roster[0]['student_id'], $stu, 'roster carries the student id');

// ── Mark sheet: enrolled students with null status until marked ──
$sid = mam_lms_create_session($cid, ['title' => 'Lesson 1', 'created_by' => $ins]);
$sheet = mam_lms_session_mark_sheet($sid, $cid);
t_eq(count($sheet), 1, 'mark sheet covers the roster');
t_ok($sheet[0]['status'] === null, 'student is unmarked until attendance is taken');
mam_lms_mark_attendance($sid, $stu, 'present', $ins);
$sheet2 = mam_lms_session_mark_sheet($sid, $cid);
t_eq($sheet2[0]['status'], 'present', 'mark sheet reflects the recorded attendance');

// ── Cohort model only: a carried class is just a cohort ──
// mam_legacy_carry() copies a class's roster into course_enrollments, so the
// instructor's cohort list counts course enrolments alone. The legacy_count
// fold and the per-cohort converge write-path it pointed at are both gone.
$legClass = mam_db_insert('classes', ['title' => 'Legacy Strings', 'instructor_id' => $ins, 'status' => 'active']);
$legStu1  = mam_db_insert('users', ['email' => 't_isch_leg1@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Leg One', 'status' => 'active']);
mam_db_insert('enrollments', ['student_id' => $legStu1, 'class_id' => $legClass, 'status' => 'active']);
$legCohort = mam_lms_create_cohort(['title' => 'Backfilled Strings', 'instructor_id' => $ins]);
mam_db_update('cohorts', ['legacy_class_id' => $legClass, 'course_id' => $pianoId], ['id' => $legCohort]);
$byId = [];
foreach (mam_lms_instructor_cohorts($ins) as $c) { $byId[(int)$c['id']] = $c; }
t_ok(isset($byId[$legCohort]), 'a carried cohort is listed for its instructor');
t_ok(!array_key_exists('legacy_count', $byId[$legCohort]), 'the cohort list no longer reads the legacy class roster');
t_eq((int)$byId[$legCohort]['enrollment_count'], 0, 'an un-carried class roster is NOT counted (the old table is unread)');
mam_db_insert('course_enrollments', ['student_id' => $legStu1, 'course_id' => $pianoId, 'status' => 'active', 'cohort_id' => $legCohort]);
$byId = [];
foreach (mam_lms_instructor_cohorts($ins) as $c) { $byId[(int)$c['id']] = $c; }
t_eq((int)$byId[$legCohort]['enrollment_count'], 1, 'once carried into course_enrollments the student counts');
t_ok(!function_exists('mam_lms_converge_cohort_enrollments'), 'the per-cohort converge write-path is gone (mam_legacy_carry replaces it)');
