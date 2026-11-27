<?php
declare(strict_types=1);

t_group('the cohort effort board stays REMOVED (redesign refusal 5)');
/* This group used to exercise an opt-in effort leaderboard. The 2026-08 portal
 * redesign refuses a cohort ranking outright, so the feature was removed — lib,
 * routes, route file and the student-portal panel.
 *
 * Deleting the tests with it would leave nothing to stop somebody reintroducing
 * it, which is how a refusal quietly stops being one. These assertions are the
 * refusal, stated the way the v2 schema states its own ("there is NO leaderboard
 * table here and there must not be one").
 *
 * The STUDIO board (mam_studio_leaderboard, /api/studio/leaderboard) is a
 * DIFFERENT feature and is deliberately kept: it ranks attempts at a practice
 * TOOL, opt-in and self-selected, not children against their classmates. Its
 * own tests live in test_studio_leaderboard.php. */
foreach (['mam_leaderboard_for_student', 'mam_leaderboard_optin_get', 'mam_leaderboard_optin_set',
          'mam_leaderboard_display_name', 'mam_leaderboard_eligible'] as $gone) {
    t_ok(!function_exists($gone), "$gone() is gone and must not come back");
}
t_ok(!file_exists(__DIR__ . '/../lib/leaderboard.php') && !glob(__DIR__ . '/../modules/*/lib/leaderboard.php'), 'the leaderboard lib is deleted (in no module)');
t_ok(!file_exists(__DIR__ . '/../routes/student/leaderboard.php'), 'the student leaderboard route file is deleted');
t_ok(strpos(file_get_contents(__DIR__ . '/../index.php'), "student/leaderboard.php") === false,
    'no route registers the cohort board');
t_ok(strpos(file_get_contents(__DIR__ . '/../../portal/student/classic.html'), 'leaderboardArea') === false,
    'the student portal renders no cohort board');
// The studio board is NOT collateral damage.
t_ok(function_exists('mam_studio_leaderboard'), 'the studio practice-tool board is untouched');

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
