<?php
declare(strict_types=1);

t_group('opt-in effort leaderboard (§3.4)');
$LB_course = mam_db_insert('courses', ['code' => 'lb_c', 'name' => 'LB', 'kind' => 'core']);
$LB_coh = mam_db_insert('cohorts', ['course_id' => $LB_course, 'title' => 'LB Cohort', 'status' => 'active', 'capacity' => 10]);
$LB1 = mam_db_insert('users', ['name' => 'Ada Grace Okafor', 'email' => 'lb1@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$LB2 = mam_db_insert('users', ['name' => 'Ben Musa', 'email' => 'lb2@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$LB3 = mam_db_insert('users', ['name' => 'Cee Bloggs', 'email' => 'lb3@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
foreach ([$LB1, $LB2, $LB3] as $s) mam_db_insert('course_enrollments', ['student_id' => $s, 'course_id' => $LB_course, 'cohort_id' => $LB_coh, 'status' => 'active']);
mam_db_insert('practice_logs', ['student_id' => $LB1, 'minutes' => 120, 'logged_on' => '2026-08-13']);
mam_db_insert('practice_logs', ['student_id' => $LB2, 'minutes' => 200, 'logged_on' => '2026-08-13']);
mam_leaderboard_optin_set($LB1, true); mam_consent_set($LB1, ['data' => true]);
mam_leaderboard_optin_set($LB2, true); // opted in but NOT consented yet
// LB3 not opted in.
$board = mam_leaderboard_for_student($LB1, '2026-08-15');
t_eq(count($board), 1, 'only opted-in + data-consented students appear');
t_ok($board[0]['is_me'] === true, 'the viewer sees themselves');
mam_consent_set($LB2, ['data' => true]);
$board2 = mam_leaderboard_for_student($LB1, '2026-08-15');
t_eq(count($board2), 2, 'both appear once consented');
t_eq($board2[0]['minutes'], 200, 'ranked by effort (minutes) descending');
t_eq(mam_leaderboard_display_name('Ada Grace Okafor'), 'Ada O.', 'display name is first + last initial');
t_ok(!mam_leaderboard_optin_get($LB3), 'opt-in defaults off');

t_group('arrival analytics (§8.3)');
$AR_ins = mam_db_insert('users', ['name' => 'AR Ins', 'email' => 'ar_ins@test.local', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$AR_course = mam_db_insert('courses', ['code' => 'ar_c', 'name' => 'AR', 'kind' => 'core']);
$AR_coh = mam_db_insert('cohorts', ['course_id' => $AR_course, 'instructor_id' => $AR_ins, 'title' => 'AR Cohort', 'status' => 'active', 'capacity' => 10]);
$AR_sess = mam_db_insert('class_sessions', ['cohort_id' => $AR_coh, 'title' => 'AR Sess', 'scheduled_at' => '2026-08-01 10:00:00', 'status' => 'held', 'duration_min' => 60, 'kind' => 'class']);
$ar1 = mam_db_insert('users', ['name' => 'AR1', 'email' => 'ar1@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$ar2 = mam_db_insert('users', ['name' => 'AR2', 'email' => 'ar2@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$ar3 = mam_db_insert('users', ['name' => 'AR3', 'email' => 'ar3@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
mam_db_insert('session_attendance', ['session_id' => $AR_sess, 'student_id' => $ar1, 'status' => 'present', 'checked_in_at' => '2026-08-01 10:03:00']);
mam_db_insert('session_attendance', ['session_id' => $AR_sess, 'student_id' => $ar2, 'status' => 'late', 'checked_in_at' => '2026-08-01 10:20:00']);
mam_db_insert('session_attendance', ['session_id' => $AR_sess, 'student_id' => $ar3, 'status' => 'absent']);
$a = mam_analytics2_arrival($AR_ins);
t_eq($a['checked_in'], 2, 'two check-ins counted');
t_eq($a['buckets']['on_time'], 1, '10:03 is within 5 min → on time');
t_eq($a['buckets']['very_late'], 1, '10:20 is 15+ min late');
t_eq($a['no_shows'], 1, 'one absent → no-show');

t_group('make-up suggestions (§8.2)');
$MU_stu = mam_db_insert('users', ['name' => 'MU Stu', 'email' => 'mu_stu@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$MU_course = mam_db_insert('courses', ['code' => 'mu_c', 'name' => 'MU', 'kind' => 'core']);
$MU_coh = mam_db_insert('cohorts', ['course_id' => $MU_course, 'title' => 'MU Cohort', 'status' => 'active', 'capacity' => 10]);
mam_db_insert('course_enrollments', ['student_id' => $MU_stu, 'course_id' => $MU_course, 'cohort_id' => $MU_coh, 'status' => 'active']);
$missed = mam_db_insert('class_sessions', ['cohort_id' => $MU_coh, 'title' => 'Missed wk', 'scheduled_at' => date('Y-m-d H:i:s', strtotime('-10 days')), 'status' => 'held', 'duration_min' => 60, 'kind' => 'class']);
mam_db_insert('session_attendance', ['session_id' => $missed, 'student_id' => $MU_stu, 'status' => 'absent']);
mam_db_insert('class_sessions', ['cohort_id' => $MU_coh, 'title' => 'Upcoming wk', 'scheduled_at' => date('Y-m-d H:i:s', strtotime('+10 days')), 'status' => 'scheduled', 'duration_min' => 60, 'kind' => 'class']);
$sugg = mam_makeup_suggestions($MU_stu);
t_ok(count($sugg) >= 1, 'a missed session yields make-up suggestions');
t_ok(count($sugg[0]['options']) >= 1, 'options include the upcoming same-course session');

// Housekeeping: drop the throwaway courses so the seeded-catalogue count test
// (which asserts an absolute 12) isn't inflated by this suite's fixtures.
foreach ([$LB_course, $AR_course, $MU_course] as $cid) { mam_db()->prepare('DELETE FROM courses WHERE id = ?')->execute([$cid]); }
