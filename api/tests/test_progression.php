<?php
/** Progression — level chain + pass-gated, idempotent promotion. */
declare(strict_types=1);
t_group('progression');

// Level chain: Pre-Certification → Certificate → Pre-Diploma → Diploma.
t_eq(mam_lms_next_level('precert')['code'], 'cert', 'precert → cert');
t_eq(mam_lms_next_level('cert')['code'], 'prediploma', 'cert → prediploma');
t_eq(mam_lms_next_level('prediploma')['code'], 'diploma', 'prediploma → diploma');
t_ok(mam_lms_next_level('diploma') === null, 'diploma is the top — no next level');

$sid  = mam_db_insert('users', ['email' => 't_prog@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Climber', 'status' => 'active']);
$cid  = (int)mam_db_row("SELECT id FROM courses WHERE code='piano'")['id'];
$std  = (int)mam_db_row("SELECT id FROM programs WHERE code='standard'")['id'];
$pre  = (int)mam_db_row("SELECT id FROM levels WHERE code='precert'")['id'];
$cert = (int)mam_db_row("SELECT id FROM levels WHERE code='cert'")['id'];

$eid = mam_db_insert('course_enrollments', ['student_id' => $sid, 'course_id' => $cid, 'program_id' => $std, 'level_id' => $pre, 'status' => 'active']);
t_ok(mam_lms_promote_enrollment($eid, 1) === null, 'no promotion before a passing result');

mam_db_insert('results', ['enrollment_id' => $eid, 'total_score' => 85, 'grade' => 'A', 'outcome' => 'pass']);
$p = mam_lms_promote_enrollment($eid, 1);
t_ok($p && $p['created'] === true, 'passed Pre-Cert enrolment is advanced');
t_eq($p['level'], 'cert', 'advanced to the Certificate level');
$adv = mam_db_row('SELECT id FROM course_enrollments WHERE student_id = ? AND course_id = ? AND level_id = ?', [$sid, $cid, $cert]);
t_ok($adv !== null, 'a Certificate-level enrolment for the same course now exists');

$p2 = mam_lms_promote_enrollment($eid, 1);
t_ok($p2 && $p2['created'] === false, 're-promotion is idempotent (already enrolled at next level)');

// A Diploma-level pass has nowhere higher to go.
$cid2 = (int)mam_db_row("SELECT id FROM courses WHERE code='voice'")['id'];
$dip  = (int)mam_db_row("SELECT id FROM levels WHERE code='diploma'")['id'];
$eidD = mam_db_insert('course_enrollments', ['student_id' => $sid, 'course_id' => $cid2, 'level_id' => $dip, 'status' => 'active']);
mam_db_insert('results', ['enrollment_id' => $eidD, 'total_score' => 90, 'grade' => 'A', 'outcome' => 'pass']);
t_ok(mam_lms_promote_enrollment($eidD, 1) === null, 'Diploma pass has no further level');
