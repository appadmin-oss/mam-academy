<?php
/**
 * Learning analytics (LMS L5) — read-only aggregates + the transparent at-risk
 * heuristic. The runner shares one DB across files, so overview assertions use
 * DELTAS and at-risk assertions target distinctive students by id (never global
 * absolutes).
 */
declare(strict_types=1);
t_group('analytics (learning dashboard)');

// ── Baseline (shared DB) ──
$ov0 = mam_analytics_overview();
$completed0 = (int)($ov0['enrolments']['by_status']['completed'] ?? 0);
$failed0    = (int)($ov0['enrolments']['by_status']['failed'] ?? 0);
$total0     = (int)$ov0['enrolments']['total'];

// ── Fixtures: distinctive, isolated students ──
$ins   = mam_db_insert('users', ['email' => 't_an_ins@x.dev',  'password_hash' => 'x', 'role' => 'instructor', 'name' => 'An Instructor', 'status' => 'active']);
$good  = mam_db_insert('users', ['email' => 't_an_good@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Healthy Hannah', 'status' => 'active']);
$sam   = mam_db_insert('users', ['email' => 't_an_sam@x.dev',  'password_hash' => 'x', 'role' => 'student', 'name' => 'Sparse Sam', 'status' => 'active']);
$rita  = mam_db_insert('users', ['email' => 't_an_rita@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Redo Rita', 'status' => 'active']);
$steve = mam_db_insert('users', ['email' => 't_an_stv@x.dev',  'password_hash' => 'x', 'role' => 'student', 'name' => 'Stale Steve', 'status' => 'active']);
$cid   = (int)mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];

// Active enrolments for the four flagged-candidate students.
foreach ([$good, $sam, $rita, $steve] as $stu) {
    mam_db_insert('course_enrollments', ['student_id' => $stu, 'course_id' => $cid, 'status' => 'active']);
}
// One completed + one failed (drive the outcome deltas).
$eDone = mam_db_insert('course_enrollments', ['student_id' => $good, 'course_id' => $cid, 'status' => 'completed']);
mam_db_insert('results', ['enrollment_id' => $eDone, 'total_score' => 91, 'grade' => 'A', 'outcome' => 'pass']);
mam_db_insert('course_enrollments', ['student_id' => $sam, 'course_id' => $cid, 'status' => 'failed']);

// Healthy Hannah: good attendance, an accepted submission, a strong assessment,
// and practice TODAY → must NOT be flagged.
$cohort = mam_lms_create_cohort(['title' => 'Analytics Cohort']);
$sess   = mam_lms_create_session($cohort, ['title' => 'Lesson 1', 'scheduled_at' => '2026-07-20 17:00']);
mam_lms_mark_attendance($sess, $good, 'present', $ins);
$hEnr = (int)mam_db_row("SELECT id FROM course_enrollments WHERE student_id = ? AND status='active'", [$good])['id'];
mam_db_insert('assessments', ['enrollment_id' => $hEnr, 'instructor_id' => $ins, 'week' => 1, 'score' => 90, 'max_score' => 100]);
$hAsg = mam_db_insert('assignments', ['student_id' => $good, 'author_id' => $ins, 'title' => 'Scale', 'status' => 'open']);
mam_db_insert('submissions', ['assignment_id' => $hAsg, 'student_id' => $good, 'artifact_kind' => 'link', 'status' => 'accepted']);
mam_db_insert('practice_logs', ['student_id' => $good, 'minutes' => 40, 'focus' => 'scales', 'logged_on' => date('Y-m-d')]);

// Sparse Sam: low attendance (1 present, 3 absent = 25%) → flagged.
$sess2 = mam_lms_create_session($cohort, ['title' => 'Lesson 2', 'scheduled_at' => '2026-07-21 17:00']);
$sess3 = mam_lms_create_session($cohort, ['title' => 'Lesson 3', 'scheduled_at' => '2026-07-22 17:00']);
$sess4 = mam_lms_create_session($cohort, ['title' => 'Lesson 4', 'scheduled_at' => '2026-07-23 17:00']);
mam_lms_mark_attendance($sess,  $sam, 'present', $ins);
mam_lms_mark_attendance($sess2, $sam, 'absent', $ins);
mam_lms_mark_attendance($sess3, $sam, 'absent', $ins);
mam_lms_mark_attendance($sess4, $sam, 'absent', $ins);

// Redo Rita: a submission awaiting resubmission, nothing accepted → flagged.
$rAsg = mam_db_insert('assignments', ['student_id' => $rita, 'author_id' => $ins, 'title' => 'Etude', 'status' => 'open']);
mam_db_insert('submissions', ['assignment_id' => $rAsg, 'student_id' => $rita, 'artifact_kind' => 'link', 'status' => 'needs_resubmission']);

// Stale Steve: last practice 30 days ago → flagged.
mam_db_insert('practice_logs', ['student_id' => $steve, 'minutes' => 20, 'focus' => 'theory', 'logged_on' => date('Y-m-d', strtotime('-30 days'))]);

// ── Overview deltas ──
$ov = mam_analytics_overview();
t_eq((int)($ov['enrolments']['by_status']['completed'] ?? 0), $completed0 + 1, 'completed count rises by exactly one');
t_eq((int)($ov['enrolments']['by_status']['failed'] ?? 0), $failed0 + 1, 'failed count rises by exactly one');
t_ok($ov['enrolments']['total'] >= $total0 + 6, 'total enrolments grew by the six we added');
t_ok($ov['retention']['pass_rate'] === null || ($ov['retention']['pass_rate'] >= 0 && $ov['retention']['pass_rate'] <= 100), 'pass_rate is a percentage or null');

// by_course carries piano with the expected shape.
$piano = null; foreach ($ov['by_course'] as $c) { if ($c['code'] === 'piano') $piano = $c; }
t_ok($piano !== null, 'by_course includes piano');
t_ok($piano['enrolled'] >= 6, 'piano enrolled count reflects our enrolments');
foreach (['course', 'code', 'enrolled', 'completed', 'pass_rate', 'avg_score'] as $k) t_ok(array_key_exists($k, $piano), "by_course row has '$k'");

// attendance rate is consistent.
$att = $ov['attendance'];
t_ok($att['total'] >= 5, 'attendance total counts the rows we marked');
t_ok($att['rate'] === null || ($att['rate'] >= 0 && $att['rate'] <= 100), 'attendance rate is a percentage or null');

// ── At-risk: presence + reasons ──
$risk = mam_analytics_at_risk();
$byId = []; foreach ($risk as $r) $byId[$r['student_id']] = $r;

t_ok(!isset($byId[$good]), 'a healthy active student is NOT flagged');

t_ok(isset($byId[$sam]), 'a low-attendance student is flagged');
t_ok(isset($byId[$sam]) && implode('|', $byId[$sam]['reasons']) !== '' && strpos(implode('|', $byId[$sam]['reasons']), 'Low attendance') !== false, 'the low-attendance reason is given');
t_eq($byId[$sam]['attendance_rate'], 25, 'attendance rate computed (1 of 4 = 25%)');

t_ok(isset($byId[$rita]), 'a student with work awaiting resubmission is flagged');
t_ok(isset($byId[$rita]) && strpos(implode('|', $byId[$rita]['reasons']), 'resubmission') !== false, 'the resubmission reason is given');

t_ok(isset($byId[$steve]), 'a student with stale practice is flagged');
t_ok(isset($byId[$steve]) && strpos(implode('|', $byId[$steve]['reasons']), 'No practice logged') !== false, 'the stale-practice reason is given');

// Sorted: most reasons first.
$counts = array_map(function ($r) { return count($r['reasons']); }, $risk);
$sorted = $counts; rsort($sorted);
t_eq($counts, $sorted, 'at-risk list is sorted by reason count (most at-risk first)');
