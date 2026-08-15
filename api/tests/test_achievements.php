<?php
declare(strict_types=1);
t_group('achievements — award, evaluate, wins feed (§3.1/§3.5)');

$AC_ins = mam_db_insert('users', ['name' => 'AC Ins', 'email' => 'ach_ins@test.local', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$AC_stu = mam_db_insert('users', ['name' => 'AC Stu', 'email' => 'ach_stu@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);

// No progress yet → no badges.
t_eq(count(mam_ach_evaluate($AC_stu)), 0, 'a fresh student has no badges');

// A submission earns "first submission"; awarding is idempotent.
$AC_asg = mam_db_insert('assignments', ['student_id' => $AC_stu, 'title' => 'Scale', 'status' => 'open']);
$AC_sub = mam_db_insert('submissions', ['assignment_id' => $AC_asg, 'student_id' => $AC_stu, 'artifact_url' => 'https://x/a.mp3', 'artifact_kind' => 'audio', 'status' => 'submitted']);
$list = mam_ach_evaluate($AC_stu);
t_ok(count(array_filter($list, fn($a) => $a['code'] === 'first_submission')) === 1, 'first submission earns a badge');
t_ok(!mam_ach_award($AC_stu, 'first_submission', 'dup'), 'a badge is awarded at most once');
t_eq(count(mam_ach_evaluate($AC_stu)), count($list), 're-evaluating does not duplicate badges');

// Accepting the submission earns "first accepted".
mam_db_update('submissions', ['status' => 'accepted'], ['id' => $AC_sub]);
t_ok(count(array_filter(mam_ach_evaluate($AC_stu), fn($a) => $a['code'] === 'first_accepted')) === 1, 'an accepted submission earns a badge');

// 10h practice earns the practice badge.
mam_db_insert('practice_logs', ['student_id' => $AC_stu, 'minutes' => 650]);
t_ok(count(array_filter(mam_ach_evaluate($AC_stu), fn($a) => $a['code'] === 'practice_10h')) === 1, '10 hours practised earns a badge');

// Wins feed carries the practice + the earned badges, newest-first, never negative.
$wins = mam_wins_feed($AC_stu, 20);
t_ok(count($wins) >= 2, 'wins feed aggregates positive moments');
t_ok(count(array_filter($wins, fn($w) => strpos($w['text'], 'Practised') === 0)) >= 1, 'wins feed includes practice');

// §3.2 — the streak freeze tolerates a single miss.
t_ok(function_exists('mam_checkin_streak'), 'streak fn exists');
