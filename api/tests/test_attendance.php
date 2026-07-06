<?php
/**
 * QR self-check-in (#9/#10) — signed token round-trip + enrolment-gated,
 * idempotent marking. The token can't be forged or replayed after its day, and
 * check-in only ever marks an enrolled student present, exactly once.
 */
declare(strict_types=1);
t_group('attendance QR check-in');

// ── Token: verifies, carries class + date, and is tamper-evident ──
// Use TODAY (a token is valid for its session day only), so the test isn't
// fragile to the clock rolling past a hard-coded date.
$today = date('Y-m-d');
$tok = mam_attendance_token(42, $today);
$v = mam_attendance_verify_token($tok);
t_ok(is_array($v) && $v['class_id'] === 42 && $v['date'] === $today, 'a fresh token verifies and carries class + date');
t_ok(mam_attendance_verify_token($tok . 'x') === null, 'a tampered token is rejected');
t_ok(mam_attendance_verify_token('not-a-token') === null, 'garbage is rejected');
t_ok(mam_attendance_verify_token('') === null, 'empty is rejected');

// A token for a past date is expired (session day has passed).
$old = mam_attendance_token(42, '2020-01-01');
t_ok(mam_attendance_verify_token($old) === null, 'a past-date token is expired');

// A signature made with the wrong key must not verify (proves it is signed).
$forged = mam_attendance_b64('42:2026-07-05:' . strtotime('2026-07-05 23:59:59') . '|' . hash_hmac('sha256', 'x', 'wrong-key'));
t_ok(mam_attendance_verify_token($forged) === null, 'a wrong-key signature is rejected');

// ── Self-check-in is enrolment-gated + idempotent ──
$stu = mam_db_insert('users', ['email' => 't_attend_stu@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Ada Check', 'status' => 'active']);
$ins = mam_db_insert('users', ['email' => 't_attend_ins@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Ins', 'status' => 'active']);
$cid = mam_db_insert('classes', ['title' => 'Attendance Test Class', 'discipline' => 'piano', 'instructor_id' => $ins, 'status' => 'active']);
$other = mam_db_insert('classes', ['title' => 'Other Class', 'discipline' => 'voice', 'instructor_id' => $ins, 'status' => 'active']);

// Not enrolled anywhere → rejected.
$threw = false; try { mam_attendance_self_checkin((int)$stu, (int)$cid, '2026-07-05'); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'not_enrolled'; }
t_ok($threw, 'a non-enrolled student cannot check in (not_enrolled)');

// Enrol the student in $cid and check in.
mam_db_insert('enrollments', ['student_id' => $stu, 'class_id' => $cid, 'status' => 'active']);
$r1 = mam_attendance_self_checkin((int)$stu, (int)$cid, '2026-07-05');
t_eq($r1['status'], 'present', 'an enrolled student is marked present');
t_ok($r1['already'] === false, 'first check-in is not a repeat');
t_eq((int)mam_db_row('SELECT COUNT(*) AS n FROM attendance WHERE session_date = ?', ['2026-07-05'])['n'], 1, 'exactly one attendance row');

// A second scan the same day is idempotent — still one row, flagged already.
$r2 = mam_attendance_self_checkin((int)$stu, (int)$cid, '2026-07-05');
t_ok($r2['already'] === true, 'a re-scan reports already checked in');
t_eq((int)mam_db_row('SELECT COUNT(*) AS n FROM attendance WHERE session_date = ?', ['2026-07-05'])['n'], 1, 'a re-scan does not create a duplicate row');

// Enrolment in one class does not let the student check in to a different class.
$threw = false; try { mam_attendance_self_checkin((int)$stu, (int)$other, '2026-07-05'); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'not_enrolled'; }
t_ok($threw, 'enrolment is per-class — cannot check in to a class you are not in');

// An instructor mark is never downgraded by a later self-scan.
$eid2 = mam_db_row("SELECT id FROM enrollments WHERE student_id = ? AND class_id = ?", [$stu, $cid])['id'];
mam_db_insert('attendance', ['enrollment_id' => (int)$eid2, 'session_date' => '2026-07-06', 'status' => 'late', 'marked_by' => (int)$ins]);
$r3 = mam_attendance_self_checkin((int)$stu, (int)$cid, '2026-07-06');
t_eq($r3['status'], 'late', 'a self-scan keeps an existing instructor mark (no downgrade)');
t_ok($r3['already'] === true, 'the existing mark is reported as already recorded');
