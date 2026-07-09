<?php
/**
 * Cohort SESSION QR self-check-in (P2 convergence) — signed session token +
 * enrolment-gated, idempotent marking against session_attendance. Twin of the
 * legacy class check-in (test_attendance.php), over the cohort model.
 */
declare(strict_types=1);
t_group('cohort session QR check-in');

$today = date('Y-m-d');

// ── Token: verifies, carries the session id, tamper-evident, day-scoped ──
$tok = mam_attendance_session_token(77, $today);
$v = mam_attendance_verify_session_token($tok);
t_ok(is_array($v) && $v['session_id'] === 77 && $v['date'] === $today, 'a fresh session token verifies and carries the session id');
t_ok(mam_attendance_verify_session_token($tok . 'x') === null, 'a tampered session token is rejected');
t_ok(mam_attendance_verify_session_token('') === null, 'empty is rejected');
t_ok(mam_attendance_verify_session_token(mam_attendance_session_token(77, '2020-01-01')) === null, 'a past-date session token is expired');

// ── The two token grammars never cross-verify (security isolation) ──
$classTok = mam_attendance_token(77, $today);
t_ok(mam_attendance_verify_session_token($classTok) === null, 'a legacy class token does NOT verify as a session token');
t_ok(mam_attendance_verify_token($tok) === null, 'a session token does NOT verify as a legacy class token');

// ── Self-check-in is enrolment-gated (course_enrollments), idempotent ──
$insId = mam_db_insert('users', ['email' => 't_sck_ins@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Sky Ins', 'status' => 'active']);
$stuId = mam_db_insert('users', ['email' => 't_sck_stu@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Sam Stu', 'status' => 'active']);
$outId = mam_db_insert('users', ['email' => 't_sck_out@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Otto Out', 'status' => 'active']);
$pianoId = (int)mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];
$coId = mam_lms_create_cohort(['title' => 'Checkin Cohort', 'instructor_id' => $insId, 'course_id' => $pianoId]);
mam_db_insert('course_enrollments', ['student_id' => $stuId, 'course_id' => $pianoId, 'instructor_id' => $insId, 'cohort_id' => $coId, 'status' => 'active']);
$sid = mam_lms_create_session($coId, ['title' => 'Lesson 1', 'created_by' => $insId]);

// Not enrolled → rejected.
$threw = false; try { mam_attendance_session_self_checkin($outId, $sid); } catch (RuntimeException $e) { $threw = ($e->getMessage() === 'not_enrolled'); }
t_ok($threw, 'a non-enrolled student cannot check in');

// Missing session → rejected.
$threw = false; try { mam_attendance_session_self_checkin($stuId, 999999); } catch (RuntimeException $e) { $threw = ($e->getMessage() === 'session_not_found'); }
t_ok($threw, 'an unknown session is rejected');

// Enrolled → marked present.
$r1 = mam_attendance_session_self_checkin($stuId, $sid);
t_eq($r1['status'], 'present', 'an enrolled student is marked present');
t_ok($r1['already'] === false, 'the first check-in is not a re-scan');
t_eq($r1['cohort_title'], 'Checkin Cohort', 'the cohort title comes through');
t_eq((int)mam_db_row('SELECT COUNT(*) c FROM session_attendance WHERE session_id = ? AND student_id = ?', [$sid, $stuId])['c'], 1, 'exactly one attendance row exists');

// Re-scan → idempotent, still one row, flagged already.
$r2 = mam_attendance_session_self_checkin($stuId, $sid);
t_ok($r2['already'] === true, 'a re-scan reports already checked in');
t_eq((int)mam_db_row('SELECT COUNT(*) c FROM session_attendance WHERE session_id = ? AND student_id = ?', [$sid, $stuId])['c'], 1, 'a re-scan never duplicates the row');

// Never downgrade: an instructor's explicit mark stands through a self check-in.
$sid2 = mam_lms_create_session($coId, ['title' => 'Lesson 2', 'created_by' => $insId]);
mam_lms_mark_attendance($sid2, $stuId, 'late', $insId);
$r3 = mam_attendance_session_self_checkin($stuId, $sid2);
t_eq($r3['status'], 'late', 'self check-in never downgrades an existing instructor mark');
t_ok($r3['already'] === true, 'the pre-existing mark is reported as already recorded');
