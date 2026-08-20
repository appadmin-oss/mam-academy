<?php
/**
 * Tests for NGG-style student check-in (api/lib/checkin.php): self check-in
 * with the late-grace window, the open/closed window, enrolment + geofence
 * gates, and the derived XP / streak / attendance-rate / dashboard.
 */
declare(strict_types=1);

t_group('checkin — setup');

mam_setting_set('checkin_self_enabled', '1');
mam_setting_set('checkin_open_before_min', '30');
mam_setting_set('checkin_late_grace_min', '10');
mam_setting_set('checkin_geofence_enabled', '0');

$CK_courseId = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($CK_courseId === 0) $CK_courseId = mam_db_insert('courses', ['code' => 'ck_c', 'name' => 'CK', 'kind' => 'core']);
$ck_seq = 0;
$ck_env = function () use (&$ck_seq, $CK_courseId) {
    $ck_seq++;
    $stu = mam_db_insert('users', ['name' => "CK Stu $ck_seq", 'email' => "ck$ck_seq@test.local", 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
    $coh = mam_db_insert('cohorts', ['course_id' => $CK_courseId, 'title' => "CK Cohort $ck_seq", 'status' => 'active', 'capacity' => 10, 'starts_on' => date('Y-m-d', time() - 14 * 86400), 'ends_on' => date('Y-m-d', time() + 70 * 86400)]);
    mam_db_insert('course_enrollments', ['student_id' => $stu, 'course_id' => $CK_courseId, 'cohort_id' => $coh, 'status' => 'active']);
    return ['student' => $stu, 'cohort' => $coh];
};
$ck_session = function (int $cohort, int $offsetSec, string $status = 'scheduled') {
    return mam_db_insert('class_sessions', ['cohort_id' => $cohort, 'title' => 'CK Session', 'scheduled_at' => date('Y-m-d H:i:s', time() + $offsetSec), 'status' => $status, 'kind' => 'class', 'duration_min' => 60]);
};

t_group('checkin — self check-in + late window');

$e = $ck_env();
$s = $ck_session($e['cohort'], 5 * 60); // starts in 5 min → on time
$r = mam_checkin_self($e['student'], $s, null, null);
t_eq($r['status'], 'present', 'on-time check-in is present');
t_ok(!$r['already'], 'first check-in is not a repeat');
$row = mam_db_row('SELECT * FROM session_attendance WHERE session_id = ? AND student_id = ?', [$s, $e['student']]);
t_eq($row['method'], 'self', 'method recorded as self');
t_ok($row['checked_in_at'] !== null, 'checked_in_at stamped');
$r2 = mam_checkin_self($e['student'], $s, null, null);
t_ok($r2['already'], 'second check-in is idempotent (already)');

$e = $ck_env();
$sLate = $ck_session($e['cohort'], -20 * 60); // started 20 min ago, grace 10 → late
$r = mam_checkin_self($e['student'], $sLate, null, null);
t_eq($r['status'], 'late', 'arriving past the grace window is late');
t_ok($r['late'], 'late flag set');

t_group('checkin — window & enrolment gates');

$e = $ck_env();
$sFuture = $ck_session($e['cohort'], 60 * 60); // opens 30 min before → not open at 60 min out
$threw = '';
try { mam_checkin_self($e['student'], $sFuture, null, null); } catch (\RuntimeException $ex) { $threw = $ex->getMessage(); }
t_eq($threw, 'checkin_not_open', 'check-in before the window opens is refused');

$e = $ck_env();
$sOld = $ck_session($e['cohort'], -5 * 3600); // 5h ago, closes ~2h after start
$threw = '';
try { mam_checkin_self($e['student'], $sOld, null, null); } catch (\RuntimeException $ex) { $threw = $ex->getMessage(); }
t_eq($threw, 'checkin_closed', 'check-in long after the session is closed');

$e = $ck_env();
$s = $ck_session($e['cohort'], 5 * 60);
$stranger = mam_db_insert('users', ['name' => 'CK Stranger', 'email' => 'ck_stranger@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
$threw = '';
try { mam_checkin_self($stranger, $s, null, null); } catch (\RuntimeException $ex) { $threw = $ex->getMessage(); }
t_eq($threw, 'not_enrolled', 'a non-enrolled student cannot check in');

t_group('checkin — geofence');

mam_setting_set('checkin_geofence_enabled', '1');
mam_setting_set('checkin_geofence_lat', '6.5');
mam_setting_set('checkin_geofence_lng', '3.4');
mam_setting_set('checkin_geofence_radius_m', '200');

$e = $ck_env();
$s = $ck_session($e['cohort'], 5 * 60);
$threw = '';
try { mam_checkin_self($e['student'], $s, null, null); } catch (\RuntimeException $ex) { $threw = $ex->getMessage(); }
t_eq($threw, 'location_required', 'geofence on + no coords → location required');
$threw = '';
try { mam_checkin_self($e['student'], $s, 6.6, 3.5); } catch (\RuntimeException $ex) { $threw = $ex->getMessage(); }
t_eq($threw, 'out_of_range', 'a point ~15km away is out of range');
$r = mam_checkin_self($e['student'], $s, 6.5001, 3.4001); // ~15m away
t_eq($r['status'], 'present', 'a point inside the radius checks in');
mam_setting_set('checkin_geofence_enabled', '0');

t_group('checkin — disabled gate');

mam_setting_set('checkin_self_enabled', '0');
$e = $ck_env();
$s = $ck_session($e['cohort'], 5 * 60);
$threw = '';
try { mam_checkin_self($e['student'], $s, null, null); } catch (\RuntimeException $ex) { $threw = $ex->getMessage(); }
t_eq($threw, 'checkin_disabled', 'self check-in refused when disabled');
mam_setting_set('checkin_self_enabled', '1');

t_group('checkin — XP, streak, rate, dashboard');

$e = $ck_env();
$stu = $e['student']; $coh = $e['cohort'];
// Four past sessions: -1d,-2d,-3d attended; -4d missed → streak 3, rate 3/4.
$sPast = [];
foreach ([1, 2, 3, 4] as $d) $sPast[$d] = $ck_session($coh, -$d * 86400, 'held');
foreach ([1, 2, 3] as $d) mam_db_insert('session_attendance', ['session_id' => $sPast[$d], 'student_id' => $stu, 'status' => ($d === 2 ? 'late' : 'present'), 'method' => 'self']);
// Two completed lessons → XP.
$les = mam_db_insert('lessons', ['cohort_id' => $coh, 'title' => 'CK Lesson', 'published' => 1]);
mam_db_insert('lesson_progress', ['lesson_id' => $les, 'student_id' => $stu, 'status' => 'completed', 'percent' => 100]);

// XP = present(2)*10 + late(1)*5 + lessons(1)*15 = 40.
t_eq(mam_checkin_xp($stu), 40, 'XP = present*10 + late*5 + lessons*15');
t_eq(mam_checkin_streak($stu), 3, 'streak counts the 3 attended sessions back to the -4d miss');
t_eq(mam_checkin_attendance_rate($stu), 75.0, 'attendance rate = 3 of 4 past sessions');

$ck_session($coh, 3 * 86400); // a future session for next_session
// A session inside the check-in window AND on today's date. Five minutes from
// now crosses midnight for the last five minutes of every day, and
// `mam_checkin_session_on()` looks the session up BY DATE — so the assertion
// below used to fail for those five minutes and pass for the other 1,435.
// Five minutes AGO is equally inside the window (it opens 30 minutes before),
// so one of the two is always on today's date.
$ck_soonOffset = gmdate('Y-m-d', time() + 5 * 60) === gmdate('Y-m-d') ? 5 * 60 : -5 * 60;
$ck_session($coh, $ck_soonOffset);
$dash = mam_checkin_dashboard($stu);
t_ok($dash['week'] >= 1 && $dash['week'] <= $dash['total_weeks'], 'week is within the programme length');
t_eq($dash['xp'], 40, 'dashboard surfaces XP');
t_eq($dash['streak'], 3, 'dashboard surfaces streak');
t_ok($dash['next_session'] !== null, 'dashboard has a next session');
t_ok($dash['today'] !== null && $dash['today']['can_check_in'] === true, "today's session is checkable now");
t_eq((int)$dash['lessons_completed'], 1, 'dashboard counts completed lessons');
