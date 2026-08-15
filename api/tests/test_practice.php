<?php
/**
 * Practice-log aggregation (api/lib/practice.php).
 *
 * mam_practice_stats() powers the student dashboard's streak, weekly total and
 * Mon–Sun dot strip. The streak rule in particular is a product decision worth
 * pinning: a student who has not practised YET TODAY must not watch their
 * streak read zero at breakfast, so yesterday keeps it alive until midnight.
 *
 * All dates are relative to "today", so these assertions describe behaviour
 * rather than fixed dates and stay true whenever the suite is run.
 */
declare(strict_types=1);

t_group('practice stats — empty');

$PL_a = mam_db_insert('users', ['name' => 'PL Empty', 'email' => 'pl_a@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$s = mam_practice_stats($PL_a);
t_eq($s['practiceStats']['streak'], 0, 'a student with no logs has no streak');
t_eq($s['practiceStats']['total'], 0, 'and no total');
t_eq($s['practiceStats']['sessions'], 0, 'and no sessions');
t_eq($s['practiceStats']['weekMin'], 0, 'and nothing this week');
t_eq(count($s['practice']), 0, 'the recent list is empty');
t_eq(count($s['practiceStats']['weekDays']), 7, 'the week strip is always seven days, even when empty');
t_ok(!array_filter(array_column($s['practiceStats']['weekDays'], 'on')), 'no day is marked practised');

t_group('practice stats — streak');

$day = fn(int $back) => date('Y-m-d', strtotime("-$back days"));

// Today, yesterday, the day before → a 3-day streak.
$PL_b = mam_db_insert('users', ['name' => 'PL Streak', 'email' => 'pl_b@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
foreach ([0, 1, 2] as $d) mam_db_insert('practice_logs', ['student_id' => $PL_b, 'minutes' => 20, 'logged_on' => $day($d)]);
t_eq(mam_practice_stats($PL_b)['practiceStats']['streak'], 3, 'three consecutive days including today is a 3-day streak');

// The grace rule: nothing logged today, but yesterday and the day before were.
// The streak must survive until midnight rather than resetting at 00:00.
$PL_c = mam_db_insert('users', ['name' => 'PL Grace', 'email' => 'pl_c@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
foreach ([1, 2] as $d) mam_db_insert('practice_logs', ['student_id' => $PL_c, 'minutes' => 15, 'logged_on' => $day($d)]);
t_eq(mam_practice_stats($PL_c)['practiceStats']['streak'], 2, 'a streak ending yesterday survives today (grace for "not yet logged")');

// A gap breaks it. Today + 2 days ago (nothing yesterday) is a streak of 1.
$PL_d = mam_db_insert('users', ['name' => 'PL Gap', 'email' => 'pl_d@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
foreach ([0, 2, 3] as $d) mam_db_insert('practice_logs', ['student_id' => $PL_d, 'minutes' => 15, 'logged_on' => $day($d)]);
t_eq(mam_practice_stats($PL_d)['practiceStats']['streak'], 1, 'a missed day breaks the streak — only today counts');

// Two days stale is over. Nothing today OR yesterday means the streak is gone.
$PL_e = mam_db_insert('users', ['name' => 'PL Stale', 'email' => 'pl_e@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
foreach ([2, 3, 4] as $d) mam_db_insert('practice_logs', ['student_id' => $PL_e, 'minutes' => 15, 'logged_on' => $day($d)]);
t_eq(mam_practice_stats($PL_e)['practiceStats']['streak'], 0, 'the grace is one day only — two days stale ends the streak');

// Two logs on the SAME day are one day of streak, not two. Practising twice
// is not two days, and the day-set must dedupe.
$PL_f = mam_db_insert('users', ['name' => 'PL Twice', 'email' => 'pl_f@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
mam_db_insert('practice_logs', ['student_id' => $PL_f, 'minutes' => 10, 'logged_on' => $day(0)]);
mam_db_insert('practice_logs', ['student_id' => $PL_f, 'minutes' => 25, 'logged_on' => $day(0)]);
$f = mam_practice_stats($PL_f);
t_eq($f['practiceStats']['streak'], 1, 'two logs on one day is a 1-day streak, not 2');
t_eq($f['practiceStats']['sessions'], 2, 'but both are counted as sessions');
t_eq($f['practiceStats']['total'], 35, 'and both minutes are totalled');

t_group('practice stats — totals and the week window');

// weekMin is the last SEVEN days inclusive (today and the six before it).
// Day 6 is inside; day 7 is outside. Both bounds are pinned because an
// off-by-one here quietly under- or over-reports every student's week.
$PL_g = mam_db_insert('users', ['name' => 'PL Week', 'email' => 'pl_g@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
mam_db_insert('practice_logs', ['student_id' => $PL_g, 'minutes' => 100, 'logged_on' => $day(0)]);
mam_db_insert('practice_logs', ['student_id' => $PL_g, 'minutes' =>  10, 'logged_on' => $day(6)]);
mam_db_insert('practice_logs', ['student_id' => $PL_g, 'minutes' =>   7, 'logged_on' => $day(7)]);
mam_db_insert('practice_logs', ['student_id' => $PL_g, 'minutes' => 500, 'logged_on' => $day(60)]);
$g = mam_practice_stats($PL_g);
t_eq($g['practiceStats']['weekMin'], 110, 'the week window is today plus the six days before it');
t_eq($g['practiceStats']['total'], 617, 'the total spans every log, not just the week');
t_eq($g['practiceStats']['sessions'], 4, 'sessions counts rows');

// The recent list is newest-first — the dashboard renders it in order.
t_eq((string) $g['practice'][0]['logged_on'], $day(0), 'the recent list leads with the newest log');
t_eq((string) $g['practice'][3]['logged_on'], $day(60), 'and ends with the oldest');

// One student's logs must never leak into another's stats.
t_eq(mam_practice_stats($PL_a)['practiceStats']['total'], 0, 'stats stay scoped to one student');

t_group('practice stats — the Mon–Sun strip');

$PL_h = mam_db_insert('users', ['name' => 'PL Strip', 'email' => 'pl_h@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
mam_db_insert('practice_logs', ['student_id' => $PL_h, 'minutes' => 30, 'logged_on' => date('Y-m-d')]);
$h = mam_practice_stats($PL_h)['practiceStats']['weekDays'];
t_eq(implode('', array_column($h, 'd')), 'MTWTFSS', 'the strip runs Monday to Sunday');
t_eq(count(array_filter(array_column($h, 'today'))), 1, 'exactly one day is flagged as today');

// The day flagged 'today' is the one marked practised, since that is the only
// log — this ties the strip's day mapping to the calendar rather than to the
// array index, which is where a Monday-vs-Sunday week start goes wrong.
$todayIdx = array_search(true, array_column($h, 'today'), true);
t_ok($h[$todayIdx]['on'] === true, 'the day practised is the day flagged today');
t_eq(count(array_filter(array_column($h, 'on'))), 1, 'no other day is marked practised');
// Monday is index 0: PHP's 'N' is 1 for Monday, so today's weekday must line up.
t_eq($todayIdx, (int) date('N') - 1, 'the strip is indexed from Monday, matching ISO weekday numbering');
