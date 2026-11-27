<?php
/**
 * Tests for the academic email engine (api/modules/learning/lib/academic-emails.php, PDF §28):
 * the enable gate, per-(type,recipient) dedup, and a representative sweep.
 * (mam_send has no transport in tests; we assert the dedup event is recorded,
 * which is what prevents double-sends.)
 */
declare(strict_types=1);

t_group('academic emails — gate & dedup');

$AE_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($AE_course === 0) $AE_course = mam_db_insert('courses', ['code' => 'ae_c', 'name' => 'AE', 'kind' => 'core']);
$AE_stu = mam_db_insert('users', ['name' => 'AE Stu', 'email' => 'ae_stu@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$AE_coh = mam_db_insert('cohorts', ['course_id' => $AE_course, 'title' => 'AE Cohort', 'status' => 'active', 'capacity' => 10]);
mam_db_insert('course_enrollments', ['student_id' => $AE_stu, 'course_id' => $AE_course, 'cohort_id' => $AE_coh, 'status' => 'active']);
$AE_sess = mam_db_insert('class_sessions', ['cohort_id' => $AE_coh, 'title' => 'AE Session', 'scheduled_at' => date('Y-m-d H:i:s', time() + 3600), 'status' => 'scheduled', 'duration_min' => 60, 'kind' => 'class']);

// Gate OFF → no dispatch, no event.
mam_setting_set('academic_emails_enabled', '0');
t_ok(mam_acadmail_attendance_confirmation($AE_stu, $AE_sess, 'present') === false, 'no email while the engine is disabled');
t_ok(!mam_acadmail_sent('acadmail.attend.s' . $AE_sess, $AE_stu), 'no dedup event recorded while disabled');

// Gate ON → dispatched once, then deduped.
mam_setting_set('academic_emails_enabled', '1');
t_ok(mam_acadmail_attendance_confirmation($AE_stu, $AE_sess, 'present') === true, 'attendance confirmation dispatched when enabled');
t_ok(mam_acadmail_sent('acadmail.attend.s' . $AE_sess, $AE_stu), 'dedup event recorded');
t_ok(mam_acadmail_attendance_confirmation($AE_stu, $AE_sess, 'present') === false, 'a second confirmation is deduped (not re-sent)');

t_group('academic emails — pre-class reminder sweep');

// A fresh cohort so the sweep count is deterministic for our two students.
$AE_coh2 = mam_db_insert('cohorts', ['course_id' => $AE_course, 'title' => 'AE Cohort2', 'status' => 'active', 'capacity' => 10]);
$s1 = mam_db_insert('users', ['name' => 'AE A', 'email' => 'ae_a@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$s2 = mam_db_insert('users', ['name' => 'AE B', 'email' => 'ae_b@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $s1, 'course_id' => $AE_course, 'cohort_id' => $AE_coh2, 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $s2, 'course_id' => $AE_course, 'cohort_id' => $AE_coh2, 'status' => 'active']);
$AE_sess2 = mam_db_insert('class_sessions', ['cohort_id' => $AE_coh2, 'title' => 'AE Soon', 'scheduled_at' => date('Y-m-d H:i:s', time() + 2 * 3600), 'status' => 'scheduled', 'duration_min' => 60, 'kind' => 'class']);

t_ok(mam_db_row('SELECT 1 FROM events WHERE type = ? AND entity_id = ?', ['acadmail.preclass.s' . $AE_sess2, $s1]) === null, 'no reminder yet');
$sent = mam_acadmail_preclass_reminder_sweep(24, 100);
t_ok($sent >= 2, 'sweep reminded at least the two enrolled students');
t_ok(mam_db_row('SELECT 1 FROM events WHERE type = ? AND entity_id = ?', ['acadmail.preclass.s' . $AE_sess2, $s1]) !== null, 'reminder recorded for student A');
// Idempotent: a second sweep sends nothing for the same session/students.
$sentAgain = mam_acadmail_preclass_reminder_sweep(24, 100);
$stillForS1 = (int) mam_db_row('SELECT COUNT(*) c FROM events WHERE type = ? AND entity_id = ?', ['acadmail.preclass.s' . $AE_sess2, $s1])['c'];
t_eq($stillForS1, 1, 'the sweep is idempotent (one reminder per student per session)');

mam_setting_set('academic_emails_enabled', '0');

// Housekeeping: neutralise the upcoming sessions this test created so they don't
// inflate other suites' next-24h sweep counts (shared throwaway DB).
mam_db_update('class_sessions', ['status' => 'held'], ['id' => $AE_sess]);
mam_db_update('class_sessions', ['status' => 'held'], ['id' => $AE_sess2]);

t_group('academic emails — run-all wrapper (§28)');
mam_setting_set('academic_emails_enabled', '0');
$aeOff = mam_acadmail_run_all_sweeps();
t_ok($aeOff['enabled'] === false, 'wrapper reports disabled when the gate is off');
t_eq($aeOff['preclass'] + $aeOff['absence'] + $aeOff['prep'] + $aeOff['weekly'], 0, 'no academic mail is swept while disabled');
mam_setting_set('academic_emails_enabled', '1');
$aeOn = mam_acadmail_run_all_sweeps();
t_ok($aeOn['enabled'] === true, 'wrapper reports enabled when the gate is on');
t_ok(array_key_exists('weekly', $aeOn) && array_key_exists('absence', $aeOn), 'wrapper returns per-type counts');
mam_setting_set('academic_emails_enabled', '0'); // leave the shared DB gate off
