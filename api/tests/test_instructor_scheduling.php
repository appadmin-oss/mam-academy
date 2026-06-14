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
// The instructor "owns" the cohort by teaching an active enrolment linked to it.
$pianoId = (int)mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];
$eid = mam_db_insert('course_enrollments', ['student_id' => $stu, 'course_id' => $pianoId, 'status' => 'active', 'instructor_id' => $ins, 'cohort_id' => $cid]);

// ── Ownership ──
t_ok(mam_lms_instructor_owns_cohort($ins, $cid), 'instructor who teaches an enrolment in the cohort owns it');
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
