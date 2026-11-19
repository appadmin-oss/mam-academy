<?php
/**
 * Adaptive assessment (adaptive.php) and growth tracking (growth.php), v102.
 */
declare(strict_types=1);

$GA_now = gmdate('YmdHis') . random_int(100, 999);
$GA_u = static fn(string $t, string $r) => (int) mam_db_insert('users', ['email' => "ga.$t.$GA_now@test.local", 'name' => ucfirst($t) . ' Grow', 'role' => $r, 'password_hash' => 'x', 'status' => 'active']);
$GA_strong = $GA_u('strong', 'student'); $GA_weak = $GA_u('weak', 'student'); $GA_t = $GA_u('teacher', 'instructor'); $GA_other = $GA_u('stranger', 'instructor');
$GA_user = static fn(int $id) => mam_db_row('SELECT * FROM users WHERE id = ?', [$id]);
$GA_course = (int) mam_db_row('SELECT id FROM courses ORDER BY id LIMIT 1')['id'];
foreach ([$GA_strong, $GA_weak] as $s) mam_db_insert('course_enrollments', ['student_id' => $s, 'course_id' => $GA_course, 'instructor_id' => $GA_t, 'status' => 'active']);

t_group('adaptive — difficulty and ability');

$GA_mod = (int) mam_db_insert('modules', ['course_id' => $GA_course, 'week' => 90, 'title' => 'Adaptive bank ' . $GA_now, 'status' => 'draft', 'kind' => 'module']);
$GA_checks = [];
foreach ([1, 1, 2, 3, 3, 4, 5, 5] as $i => $d) {
    $GA_checks[] = ['id' => (int) mam_db_insert('module_checks', ['module_id' => $GA_mod, 'kind' => 'mcq', 'prompt' => "Q$i", 'options' => '["a","b"]',
        'answer' => '0', 'points' => 1, 'position' => $i, 'difficulty' => $d]), 'kind' => 'mcq', 'points' => 1, 'd' => $d];
}
t_ok(mam_adaptive_b($GA_checks[0]['id']) < mam_adaptive_b($GA_checks[7]['id']), 'a question the teacher calls harder starts harder');
// The strong student gets everything right, the weak one only the easy ones, over several rounds.
for ($round = 0; $round < 3; $round++) {
    $ms = []; $mw = [];
    foreach ($GA_checks as $c) { $ms[(string) $c['id']] = ['awarded' => 1.0]; $mw[(string) $c['id']] = ['awarded' => $c['d'] <= 2 ? 1.0 : 0.0]; }
    mam_adaptive_learn($GA_strong, $GA_course, $GA_checks, $ms);
    mam_adaptive_learn($GA_weak, $GA_course, $GA_checks, $mw);
}
$GA_ts = mam_adaptive_theta($GA_strong, $GA_course); $GA_tw = mam_adaptive_theta($GA_weak, $GA_course);
t_ok($GA_ts['theta'] > 1.0 && $GA_tw['theta'] < 0.0, 'the estimate separates them (' . round($GA_ts['theta'], 2) . ' vs ' . round($GA_tw['theta'], 2) . ')');
t_eq($GA_ts['n'], 24, 'it counts the answers it rests on');
mam_adaptive_learn($GA_strong, $GA_course, [['id' => 0, 'kind' => 'short', 'points' => 1]], ['0' => ['awarded' => 1.0]]);
t_eq(mam_adaptive_theta($GA_strong, $GA_course)['n'], 24, 'a written answer never moves it — only machine-marked ones do');
t_eq(mam_adaptive_suggestion($GA_strong, $GA_course)['key'], 'ready_for_more', 'a teacher is told the strong student may be ready for more');
t_eq(mam_adaptive_suggestion($GA_weak, $GA_course)['key'] !== 'ready_for_more', true, 'and not the weak one');
$GA_pick = mam_adaptive_pick($GA_weak, $GA_course, array_column($GA_checks, 'id'), 4);
$GA_avg = array_sum(array_map(fn($id) => mam_adaptive_b($id), $GA_pick)) / 4;
$GA_all = array_sum(array_map(fn($c) => mam_adaptive_b($c['id']), $GA_checks)) / 8;
t_ok(count(array_unique($GA_pick)) === 4 && $GA_avg <= $GA_all, 'a struggling student is given easier questions than the bank as a whole');
t_ok((int) mam_db_row('SELECT answered FROM check_calibration WHERE check_id = ?', [$GA_checks[7]['id']])['answered'] === 6, 'questions learn their difficulty from answers too');

t_group('growth — who may see a student\'s growth');

t_ok(mam_growth_can_view($GA_strong, $GA_user($GA_strong)), 'the student themself');
t_ok(mam_growth_can_view($GA_strong, $GA_user($GA_t)), 'their teacher');
t_ok(!mam_growth_can_view($GA_strong, $GA_user($GA_other)), 'not a teacher who does not teach them');
$GA_par = (int) mam_parent_link("ga.parent.$GA_now@test.local", $GA_strong)['parent_id'];
t_ok(mam_growth_can_view($GA_strong, $GA_user($GA_par)), 'a guardian whose access reaches reports');
mam_db_update('parent_links', ['access_scope' => 'billing'], ['parent_id' => $GA_par, 'student_id' => $GA_strong]);
t_ok(!mam_growth_can_view($GA_strong, $GA_user($GA_par)), 'not a billing-only one');
t_eq(isset(mam_growth_view($GA_strong, $GA_user($GA_t))['suggestions']), true, 'a teacher sees the adaptive suggestion');
t_eq(isset(mam_growth_view($GA_strong, $GA_user($GA_strong))['suggestions']), false, 'a student never sees it as a number or a label');

t_group('growth — the timeline and snapshots');

foreach ([1, 2, 3] as $d) mam_db_insert('practice_logs', ['student_id' => $GA_strong, 'minutes' => 20, 'logged_on' => gmdate('Y-m-d', time() - $d * 86400), 'created_at' => gmdate('Y-m-d H:i:s')]);
$GA_tl = mam_growth_timeline($GA_strong, 12);
t_eq(count($GA_tl['weeks']), 12, 'twelve weeks, oldest first');
t_eq(array_sum(array_column($GA_tl['weeks'], 'practice_min')), 60, 'practice minutes land in their weeks');
t_ok($GA_tl['weeks'][0]['classes'] === null, 'a week with no class scheduled has no attendance figure — not a zero');
mam_growth_snapshot($GA_strong);
mam_growth_snapshot($GA_strong);
t_eq((int) mam_db_row('SELECT COUNT(*) AS n FROM mastery_snapshots WHERE student_id = ?', [$GA_strong])['n'], 1, 'one snapshot per student per week, however often it runs');
t_eq(mam_growth_week('2026-11-19'), '2026-11-16', 'weeks start on Monday');

t_group('growth — goals');

$GA_g = mam_goal_save($GA_strong, ['kind' => 'practice_week', 'target' => 50], $GA_user($GA_strong));
$GA_list = array_column(mam_goals_for($GA_strong), null, 'id');
t_ok($GA_list[$GA_g]['progress']['now'] !== null, 'practice goal progress is worked out from the log');
t_throws(fn() => mam_goal_save($GA_strong, ['kind' => 'lessons'], $GA_user($GA_strong)), null, 'a measured goal needs a number');
t_throws(fn() => mam_goal_save($GA_strong, ['kind' => 'custom', 'title' => 'x'], $GA_user($GA_par)), null, 'a guardian does not set a child\'s goals');
$GA_h = mam_goal_save($GA_strong, ['kind' => 'lessons', 'target' => 1], $GA_user($GA_t));
mam_db_insert('module_progress', ['module_id' => $GA_mod, 'student_id' => $GA_strong, 'status' => 'completed', 'completed_at' => gmdate('Y-m-d H:i:s', time() + 5),
    'started_at' => gmdate('Y-m-d H:i:s'), 'attempts' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
$GA_list = array_column(mam_goals_for($GA_strong), null, 'id');
t_eq($GA_list[$GA_h]['status'], 'achieved', 'a measured goal closes itself when it is met');
t_eq($GA_list[$GA_h]['set_by'], 'Teacher Grow', 'and says who suggested it');
