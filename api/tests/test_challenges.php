<?php
declare(strict_types=1);
t_group('practice challenges (§3.3)');
$CH_stu = mam_db_insert('users', ['name' => 'CH Stu', 'email' => 'ch_stu@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$CH_oth = mam_db_insert('users', ['name' => 'CH Oth', 'email' => 'ch_oth@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);

$id = mam_challenge_create($CH_stu, 'Practise 4x this week', 'sessions', 4, '2026-08-10', '2026-08-16');
t_ok($id > 0, 'challenge created');

// Two logs inside the window, one outside.
mam_db_insert('practice_logs', ['student_id' => $CH_stu, 'minutes' => 30, 'logged_on' => '2026-08-11']);
mam_db_insert('practice_logs', ['student_id' => $CH_stu, 'minutes' => 45, 'logged_on' => '2026-08-13']);
mam_db_insert('practice_logs', ['student_id' => $CH_stu, 'minutes' => 20, 'logged_on' => '2026-08-20']); // outside

$list = mam_challenge_list($CH_stu, '2026-08-14');
$c = $list[0];
t_eq($c['progress'], 2, 'sessions metric counts only logs in the window');
t_eq($c['pct'], 50, 'progress percent = 2/4');
t_ok($c['active'] === true, 'challenge is active on a date inside the window');
t_ok($c['done'] === false, 'not done yet');

// A minutes challenge sums minutes in the window.
$mid = mam_challenge_create($CH_stu, '150 minutes', 'minutes', 150, '2026-08-10', '2026-08-16');
$ml = mam_challenge_list($CH_stu, '2026-08-14');
$mc = null; foreach ($ml as $x) { if ((int)$x['id'] === $mid) { $mc = $x; break; } }
t_eq($mc['progress'], 75, 'minutes metric sums 30+45 in the window');

// Bad dates + blank title rejected.
$threw = false; try { mam_challenge_create($CH_stu, 'x', 'sessions', 1, '2026-08-16', '2026-08-10'); } catch (\InvalidArgumentException $e) { $threw = $e->getMessage() === 'bad_dates'; }
t_ok($threw, 'end-before-start is rejected');
$threw = false; try { mam_challenge_create($CH_stu, '  ', 'sessions', 1, '2026-08-10', '2026-08-16'); } catch (\InvalidArgumentException $e) { $threw = $e->getMessage() === 'title_required'; }
t_ok($threw, 'blank title rejected');

// Ownership on delete.
$threw = false; try { mam_challenge_delete($id, $CH_oth); } catch (\RuntimeException $e) { $threw = $e->getMessage() === 'forbidden'; }
t_ok($threw, 'a student cannot delete another student\'s challenge');
t_ok(mam_challenge_delete($id, $CH_stu), 'owner deletes their challenge');
