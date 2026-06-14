<?php
/**
 * Session attendance (LMS L2). Marking attendance against a class_session —
 * one upserted record per (session, student) — plus the roster, summary, and
 * the student's dual-read session-attendance view.
 */
declare(strict_types=1);
t_group('session attendance (L2)');

$cid = mam_lms_create_cohort(['title' => 'Att Cohort']);
$sid = mam_lms_create_session($cid, ['title' => 'Lesson', 'scheduled_at' => '2026-07-15 17:00']);
$a = mam_db_insert('users', ['email' => 't_sa_a@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Ada A', 'status' => 'active']);
$b = mam_db_insert('users', ['email' => 't_sa_b@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Bode B', 'status' => 'active']);

// ── Mark + roster ──
mam_lms_mark_attendance($sid, $a, 'present', 1);
mam_lms_mark_attendance($sid, $b, 'absent', 1, 'no-show');
$roster = mam_lms_session_attendance($sid);
t_eq(count($roster), 2, 'roster lists one record per marked student');
t_eq($roster[0]['student_name'], 'Ada A', 'roster ordered by student name');
t_eq($roster[0]['status'], 'present', 'first student marked present');
t_eq($roster[1]['status'], 'absent', 'second student marked absent');

// ── Re-marking upserts (no duplicate row) ──
mam_lms_mark_attendance($sid, $a, 'late', 1);
t_eq(count(mam_lms_session_attendance($sid)), 2, 're-marking the same student updates in place (no duplicate)');
t_eq((int)mam_db_row('SELECT COUNT(*) AS n FROM session_attendance WHERE session_id = ? AND student_id = ?', [$sid, $a])['n'], 1, 'exactly one row per (session, student)');
$ada = null; foreach (mam_lms_session_attendance($sid) as $r) { if ((int)$r['student_id'] === $a) $ada = $r; }
t_eq($ada['status'], 'late', 're-mark changed the status to late');

// ── Summary counts ──
$sum = mam_lms_session_attendance_summary($sid);
t_eq($sum['late'], 1, 'summary counts late');
t_eq($sum['absent'], 1, 'summary counts absent');
t_eq($sum['present'], 0, 'summary present back to 0 after the re-mark');

// ── Guards ──
$threw = false; try { mam_lms_mark_attendance($sid, $a, 'bogus', 1); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'invalid_status'; }
t_ok($threw, 'an invalid status is rejected');
$threw = false; try { mam_lms_mark_attendance(99999, $a, 'present', 1); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'session_not_found'; }
t_ok($threw, 'marking against a missing session throws session_not_found');

// ── Student dual-read view ──
$view = mam_lms_student_session_attendance($a);
t_ok(count($view) >= 1, 'student session-attendance view returns the record');
t_eq($view[0]['cohort_title'], 'Att Cohort', 'view resolves the cohort title');
t_eq($view[0]['session_title'], 'Lesson', 'view resolves the session title');
t_eq(count(mam_lms_student_session_attendance($a, $cid)), count($view), 'cohort-scoped view matches for the same cohort');
t_eq(count(mam_lms_student_session_attendance($a, 999999)), 0, 'cohort-scoped view excludes other cohorts');
