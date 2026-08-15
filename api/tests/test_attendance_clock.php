<?php
/**
 * The attendance clock (PDF §9, §10, §12, §16, §39-41).
 *
 * Covers the preparation window and its states, the §8 next-class panel, the
 * seven-status vocabulary, the three modes, clock-out duration and the
 * left-early derivation, and the instructor override.
 */
declare(strict_types=1);

t_group('attendance clock — the §9 preparation window');

mam_setting_set('checkin_open_before_min', '30');
mam_setting_set('readiness_before_min', '15');
mam_setting_set('checkin_self_enabled', '1');
mam_setting_set('checkin_geofence_enabled', '0');
mam_setting_set('checkin_late_grace_min', '10');

$CK_start = '2026-09-10 16:00:00';
$CK_ts    = strtotime($CK_start);

$w = mam_checkin_window($CK_start, 60, $CK_ts - 45 * 60);
t_eq($w['state'], 'closed', '45 minutes out the portal has not opened');
t_eq($w['opens_at'], '2026-09-10 15:30:00', 'the portal opens 30 minutes before a 4pm class');
t_eq($w['ready_by'], '2026-09-10 15:45:00', 'the readiness threshold is 15 minutes before');
t_eq($w['ends_at'], '2026-09-10 17:00:00', 'the window ends at the scheduled end');

t_eq(mam_checkin_window($CK_start, 60, $CK_ts - 20 * 60)['state'], 'open', '20 minutes out the portal is open');
t_eq(mam_checkin_window($CK_start, 60, $CK_ts - 10 * 60)['state'], 'readiness_passed', '10 minutes out the readiness point has passed');
t_eq(mam_checkin_window($CK_start, 60, $CK_ts + 10 * 60)['state'], 'in_progress', 'after the start the class is in progress');
t_eq(mam_checkin_window($CK_start, 60, $CK_ts + 90 * 60)['state'], 'ended', 'after the end the window has closed');

// §9 asks for this sentence, in these words, with real times in it.
$msg = mam_checkin_window($CK_start, 60, $CK_ts - 20 * 60)['message'];
t_ok(strpos($msg, '4:00 PM') !== false, 'the notice names the class start time');
t_ok(strpos($msg, '3:45 PM') !== false, 'the notice names the readiness time');
t_ok(strpos($msg, 'no later than') !== false, 'the notice states the readiness expectation');

// A settings change moves BOTH boundaries — the window is not hardcoded.
mam_setting_set('checkin_open_before_min', '45');
mam_setting_set('readiness_before_min', '20');
$w2 = mam_checkin_window($CK_start, 60, $CK_ts - 40 * 60);
t_eq($w2['opens_at'], '2026-09-10 15:15:00', 'the open boundary follows the setting');
t_eq($w2['ready_by'], '2026-09-10 15:40:00', 'the readiness boundary follows the setting');
mam_setting_set('checkin_open_before_min', '30');
mam_setting_set('readiness_before_min', '15');

// An unscheduled session has no window rather than a window at the epoch.
$threw = false;
try { mam_checkin_window('not a date', 60); } catch (\RuntimeException $e) { $threw = ($e->getMessage() === 'session_unscheduled'); }
t_ok($threw, 'an unparseable schedule throws rather than computing from the epoch');

t_group('attendance clock — modes, clock-out and the §12 vocabulary');

t_eq(count(MAM_ATTENDANCE_STATUSES), 7, '§12 defines seven attendance statuses');
foreach (['present', 'late', 'absent', 'excused', 'left_early', 'technical_failure', 'rescheduled'] as $s) {
    t_ok(in_array($s, MAM_ATTENDANCE_STATUSES, true), "'$s' is a recognised status");
}
t_eq(MAM_ATTENDANCE_MODES, ['onsite', 'online', 'hybrid'], '§16 defines three attendance modes');
t_eq(mam_attendance_status_label('technical_failure'), 'Technical failure', 'statuses have human labels');

$CL_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($CL_course === 0) $CL_course = mam_db_insert('courses', ['code' => 'cl_c', 'name' => 'CL', 'kind' => 'core']);
$CL_ins = mam_db_insert('users', ['name' => 'CL Ins', 'email' => 'cl_ins@test.local', 'role' => 'instructor', 'password_hash' => '', 'status' => 'active']);
$CL_stu = mam_db_insert('users', ['name' => 'CL Stu', 'email' => 'cl_stu@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
$CL_coh = mam_db_insert('cohorts', ['course_id' => $CL_course, 'title' => 'CL Cohort', 'status' => 'active', 'capacity' => 10, 'instructor_id' => $CL_ins]);
mam_db_insert('course_enrollments', ['student_id' => $CL_stu, 'course_id' => $CL_course, 'cohort_id' => $CL_coh, 'status' => 'active']);

// A session that started 20 minutes ago and runs for an hour.
$CL_sched = date('Y-m-d H:i:s', time() - 20 * 60);
$CL_sess  = mam_db_insert('class_sessions', [
    'cohort_id' => $CL_coh, 'title' => 'CL Session', 'scheduled_at' => $CL_sched,
    'status' => 'scheduled', 'duration_min' => 60, 'kind' => 'class', 'mode' => 'online',
]);

mam_setting_set('late_charge_enabled', '0');   // this test is about the clock, not the charge
$in = mam_checkin_self($CL_stu, $CL_sess, null, null, 'online');
t_eq($in['status'], 'late', '20 minutes past a 10-minute grace is late');
$row = mam_db_row('SELECT * FROM session_attendance WHERE session_id = ? AND student_id = ?', [$CL_sess, $CL_stu]);
t_eq((string) $row['mode'], 'online', 'the §16 mode is recorded on the attendance row');
t_ok((int) $row['minutes_late'] >= 19 && (int) $row['minutes_late'] <= 21, 'lateness is measured from the scheduled start, not the grace window');

// An unknown mode is refused rather than silently stored.
$CL_stu2 = mam_db_insert('users', ['name' => 'CL Stu2', 'email' => 'cl_stu2@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $CL_stu2, 'course_id' => $CL_course, 'cohort_id' => $CL_coh, 'status' => 'active']);
$threw = false;
try { mam_checkin_self($CL_stu2, $CL_sess, null, null, 'astral_projection'); } catch (\RuntimeException $e) { $threw = ($e->getMessage() === 'invalid_mode'); }
t_ok($threw, 'an unknown attendance mode is refused');

// Clocking out 20 minutes in — 40 minutes before the approved end — is early.
$out = mam_checkin_clock_out($CL_stu, $CL_sess);
t_eq($out['status'], 'left_early', 'leaving well before the approved end records left_early');
// The student arrived 20 minutes late and left immediately, so the time they
// were actually present is ~0 — the clock measures presence, not the slot.
t_ok($out['duration_min'] >= 0 && $out['duration_min'] <= 1, 'the duration measures time present, not the length of the slot');
t_ok($out['already'] === false, 'the first clock-out is not a replay');

$out2 = mam_checkin_clock_out($CL_stu, $CL_sess);
t_ok($out2['already'] === true, 'a second clock-out is idempotent');
t_eq($out2['duration_min'], $out['duration_min'], 'and does not re-measure the duration');

// The duration is STORED: moving the session afterwards must not move it.
mam_db_update('class_sessions', ['scheduled_at' => '2020-01-01 09:00:00'], ['id' => $CL_sess]);
$after = mam_db_row('SELECT duration_min FROM session_attendance WHERE session_id = ? AND student_id = ?', [$CL_sess, $CL_stu]);
t_eq((int) $after['duration_min'], (int) $out['duration_min'], 'rescheduling the session does not rewrite what actually happened');
mam_db_update('class_sessions', ['scheduled_at' => $CL_sched], ['id' => $CL_sess]);

// Clocking out without ever clocking in is refused.
$threw = false;
try { mam_checkin_clock_out($CL_stu2, $CL_sess); } catch (\RuntimeException $e) { $threw = ($e->getMessage() === 'not_checked_in'); }
t_ok($threw, 'clock-out requires a check-in');

t_group('attendance clock — the instructor override');

$fixed = mam_checkin_set_status($CL_sess, $CL_stu, 'technical_failure', 'hybrid', $CL_ins, 'Router died mid-lesson');
t_eq((string) $fixed['status'], 'technical_failure', 'an instructor may record a §12 status the student cannot');
t_eq((string) $fixed['mode'], 'hybrid', 'and may correct the mode');
t_eq((string) $fixed['notes'], 'Router died mid-lesson', 'the note is kept with the correction');

// The override IS allowed to downgrade — that is what makes it an override.
$down = mam_checkin_set_status($CL_sess, $CL_stu, 'absent', null, $CL_ins);
t_eq((string) $down['status'], 'absent', 'the override may downgrade an existing mark');
t_eq((string) $down['mode'], 'hybrid', 'omitting the mode leaves it untouched');

$threw = false;
try { mam_checkin_set_status($CL_sess, $CL_stu, 'vibes', null, $CL_ins); } catch (\InvalidArgumentException $e) { $threw = true; }
t_ok($threw, 'an unknown status is refused');

// A brand-new row can be created by the override for a student who never showed.
$made = mam_checkin_set_status($CL_sess, $CL_stu2, 'absent', 'onsite', $CL_ins);
t_eq((string) $made['status'], 'absent', 'the override creates a row for a student who never checked in');

t_group('attendance clock — the §8 next-class panel');

// The panel deliberately still shows a session that started up to an hour ago
// (a student mid-class must not be told their next class is next week), so the
// in-flight fixture session is closed off before testing the forward look.
$still = mam_checkin_next_class($CL_stu2);
t_eq($still['session_id'], $CL_sess, 'a class that has already started is still the one to show');
mam_db_update('class_sessions', ['status' => 'held'], ['id' => $CL_sess]);

// A future session with a module attached, so the panel has a topic to show.
$CL_lesson = mam_db_insert('lessons', [
    'cohort_id' => $CL_coh, 'title' => 'C Major Scale & Arpeggio', 'week_no' => 4,
    'objectives' => 'Play the scale hands together, two octaves.',
    'prep_instructions' => 'Watch the video and practise slowly.',
    'teachback_required' => 1, 'published' => 1,
]);
$CL_future = mam_db_insert('class_sessions', [
    'cohort_id' => $CL_coh, 'title' => 'Next CL Session',
    'scheduled_at' => date('Y-m-d H:i:s', time() + 3 * 86400),
    'status' => 'scheduled', 'duration_min' => 60, 'kind' => 'class',
    'lesson_id' => $CL_lesson, 'mode' => 'online',
]);

$next = mam_checkin_next_class($CL_stu2);
t_ok($next !== null, 'an enrolled student has a next class');
t_eq($next['session_id'], $CL_future, 'the panel resolves the next scheduled session');
t_eq($next['topic'], 'C Major Scale & Arpeggio', 'the panel shows the module topic, not just the session title');
t_eq($next['mode'], 'online', 'the panel carries the session mode');
t_ok($next['teachback_required'] === true, 'the panel says a teach-back is required');
t_eq($next['preparation'], 'not_started', 'with no progress the preparation state is not_started');
t_eq($next['window']['state'], 'closed', 'three days out the portal has not opened');

// §8 asks for a three-way preparation state, not a boolean.
mam_lms2_lesson_progress_set($CL_lesson, $CL_stu2, 40);
t_eq(mam_checkin_next_class($CL_stu2)['preparation'], 'partial', 'part-done preparation reports as partial');
mam_lms2_lesson_progress_set($CL_lesson, $CL_stu2, 100);
t_eq(mam_checkin_next_class($CL_stu2)['preparation'], 'completed', 'finished preparation reports as completed');

// A student enrolled in nothing has no next class rather than a fabricated one.
$CL_nobody = mam_db_insert('users', ['name' => 'CL Nobody', 'email' => 'cl_nobody@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
t_ok(mam_checkin_next_class($CL_nobody) === null, 'an unenrolled student has no next class');
