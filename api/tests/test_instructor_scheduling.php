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

// ── Legacy-count fold (P2 convergence, read-only) ──
// A cohort backfilled from a legacy class carries legacy_class_id. Its class
// roster must surface as legacy_count so a backfilled cohort no longer misreads
// as "0 students"; the course-enrolment mark-sheet stays course_enrollments-only.
$legClass = mam_db_insert('classes', ['title' => 'Legacy Strings', 'instructor_id' => $ins, 'status' => 'active']);
$legStu1  = mam_db_insert('users', ['email' => 't_isch_leg1@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Leg One', 'status' => 'active']);
$legStu2  = mam_db_insert('users', ['email' => 't_isch_leg2@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Leg Two', 'status' => 'active']);
mam_db_insert('enrollments', ['student_id' => $legStu1, 'class_id' => $legClass, 'status' => 'active']);
mam_db_insert('enrollments', ['student_id' => $legStu2, 'class_id' => $legClass, 'status' => 'paused']);
mam_db_insert('enrollments', ['student_id' => $stu,     'class_id' => $legClass, 'status' => 'cancelled']); // excluded
$legCohort = mam_lms_create_cohort(['title' => 'Backfilled Strings', 'instructor_id' => $ins]);
mam_db_update('cohorts', ['legacy_class_id' => $legClass], ['id' => $legCohort]);
$byId = [];
foreach (mam_lms_instructor_cohorts($ins) as $c) { $byId[(int)$c['id']] = $c; }
t_ok(isset($byId[$legCohort]), 'the backfilled cohort is listed for its instructor');
t_eq((int)$byId[$legCohort]['legacy_count'], 2, 'legacy_count folds active+paused class enrolments (cancelled excluded)');
t_eq((int)$byId[$legCohort]['enrollment_count'], 0, 'a backfilled cohort with no course enrolments reports 0 course students');
t_eq((int)$byId[$cid]['legacy_count'], 0, 'a cohort with no legacy class has legacy_count 0');

// ── Convergence write-path (P2): enrol the legacy roster into course records ──
// Before a course mapping exists, convergence is refused (course_id NOT NULL).
$r0 = mam_lms_converge_cohort_enrollments($legCohort, $ins);
t_eq($r0['reason'], 'no_course_mapping', 'convergence refuses a cohort with no course mapping');
t_eq($r0['converged'], 0, 'nothing is created without a course mapping');

// Map the cohort to a real course, then give one legacy member a *floating*
// enrolment (same course, no cohort) so we exercise both branches: create + link.
mam_db_update('cohorts', ['course_id' => $pianoId], ['id' => $legCohort]);
$floatEid = mam_db_insert('course_enrollments', ['student_id' => $legStu2, 'course_id' => $pianoId, 'status' => 'active']); // cohort_id NULL

$r1 = mam_lms_converge_cohort_enrollments($legCohort, $ins);
t_eq($r1['converged'], 1, 'the un-enrolled legacy member gets a new course enrolment');
t_eq($r1['linked'], 1, 'the already-enrolled floating member is linked, not duplicated');
t_eq($r1['skipped'], 0, 'no member is skipped on first convergence');
t_eq((int)mam_db_row('SELECT cohort_id FROM course_enrollments WHERE id = ?', [$floatEid])['cohort_id'], $legCohort, 'the floating enrolment is now linked to the cohort');
t_eq(count(mam_lms_cohort_roster($legCohort)), 2, 'the cohort roster now carries both converged students');
$only = mam_db_row('SELECT COUNT(*) c FROM course_enrollments WHERE student_id = ? AND course_id = ?', [$legStu2, $pianoId]);
t_eq((int)$only['c'], 1, 'convergence never duplicates an existing enrolment');

// Idempotent: a second run creates/links nothing and skips the whole roster.
$r2 = mam_lms_converge_cohort_enrollments($legCohort, $ins);
t_eq($r2['converged'], 0, 're-running convergence creates nothing');
t_eq($r2['linked'], 0, 're-running convergence links nothing');
t_eq($r2['skipped'], 2, 're-running convergence skips the already-enrolled roster');

// The cancelled legacy member is never converged.
t_ok(mam_db_row('SELECT id FROM course_enrollments WHERE student_id = ? AND cohort_id = ?', [$stu, $legCohort]) === null, 'a cancelled legacy enrolment is not converged');
