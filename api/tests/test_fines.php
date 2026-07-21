<?php
/**
 * Tests for instructor fine resolution (mam_fine_resolve) and
 * student session-report listing (mam_student_session_reports).
 */
declare(strict_types=1);

t_group('instructor fines');

t_ok(is_array(mam_instructor_fines(100, '')), 'mam_instructor_fines returns array on clean DB');
t_eq(count(mam_instructor_fines(100, '')), 0, 'no fines on clean DB');

// Fixture: admin + instructor + cohort + session + fine.
$_fa = mam_db_insert('users', ['name' => 'Fine Admin', 'email' => 'fineadmin@test.local', 'role' => 'admin', 'password_hash' => '', 'status' => 'active']);
$_fi = mam_db_insert('users', ['name' => 'Fine Instr', 'email' => 'fineinstr@test.local', 'role' => 'instructor', 'password_hash' => '', 'status' => 'active']);
$_fc = mam_db_insert('cohorts', ['title' => 'Fine Test Cohort', 'status' => 'active', 'capacity' => 5]);
$_fs = mam_db_insert('class_sessions', ['cohort_id' => $_fc, 'scheduled_at' => '2026-07-01 10:00:00', 'status' => 'held', 'kind' => 'class']);
$_ff = mam_db_insert('instructor_fines', ['instructor_id' => $_fi, 'session_id' => $_fs, 'reason' => 'late_progress_report', 'amount_kobo' => 0, 'status' => 'pending']);

// List with full-join enrichment.
$fines = mam_instructor_fines(100, '');
t_eq(count($fines), 1, 'one fine in the list');
t_eq($fines[0]['status'], 'pending', 'fine is pending');
t_eq($fines[0]['instructor_name'], 'Fine Instr', 'instructor name joined');
t_eq($fines[0]['cohort_title'], 'Fine Test Cohort', 'cohort title joined');

// Resolve.
$resolved = mam_fine_resolve($_ff, $_fa, 'resolved', 'Explained absence');
t_eq($resolved['status'], 'resolved', 'fine status after resolve');
t_eq((int)$resolved['resolved_by'], $_fa, 'resolved_by recorded');
t_eq($resolved['resolution_note'], 'Explained absence', 'note preserved');

// DB persisted.
$row = mam_db_row('SELECT status FROM instructor_fines WHERE id = ?', [$_ff]);
t_eq($row['status'], 'resolved', 'DB row updated to resolved');

// Status filter.
t_eq(count(mam_instructor_fines(100, 'pending')), 0, 'pending filter = 0 after resolution');
t_eq(count(mam_instructor_fines(100, 'resolved')), 1, 'resolved filter = 1');

// Waive a second fine (no session, empty note → stored as null).
$_ff2 = mam_db_insert('instructor_fines', ['instructor_id' => $_fi, 'session_id' => null, 'reason' => 'late_progress_report', 'amount_kobo' => 0, 'status' => 'pending']);
$waived = mam_fine_resolve($_ff2, $_fa, 'waived', '');
t_eq($waived['status'], 'waived', 'waived status correct');
t_ok($waived['resolution_note'] === null, 'empty note stored as null');

// Double-resolution throws.
$threw = false;
try { mam_fine_resolve($_ff2, $_fa, 'resolved', ''); } catch (\RuntimeException $e) { $threw = true; }
t_ok($threw, 'double-resolve throws RuntimeException');

// Invalid status throws.
$_ff3 = mam_db_insert('instructor_fines', ['instructor_id' => $_fi, 'session_id' => null, 'reason' => 'late_progress_report', 'amount_kobo' => 0, 'status' => 'pending']);
$threw2 = false;
try { mam_fine_resolve($_ff3, $_fa, 'deleted', ''); } catch (\InvalidArgumentException $e) { $threw2 = true; }
t_ok($threw2, 'invalid status throws InvalidArgumentException');

// Missing fine throws.
$threw3 = false;
try { mam_fine_resolve(999999, $_fa, 'resolved', ''); } catch (\RuntimeException $e) { $threw3 = true; }
t_ok($threw3, 'missing fine throws RuntimeException');

t_group('student session reports');

$_sr = mam_db_insert('users', ['name' => 'Report Stu', 'email' => 'rpstu@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
$reports = mam_student_session_reports($_sr);
t_ok(is_array($reports), 'returns array');
t_eq(count($reports), 0, 'empty for student with no reports');
