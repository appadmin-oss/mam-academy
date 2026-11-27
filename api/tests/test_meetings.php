<?php
/**
 * Tests for instructor clock-in + meeting reconciliation (api/modules/live/lib/meetings.php,
 * PDF §10-11,14): on-time/late clock-in (+ instructor accountability charge when
 * enabled), clock-out, deterministic Jitsi room, CSV parse + reconcile import,
 * and the Google scaffold staying inert.
 */
declare(strict_types=1);

t_group('meetings — jitsi room');

$r1 = mam_meeting_jitsi_room(4242);
t_ok(strpos($r1, 'MAM-4242-') === 0, 'jitsi room is derived from the session id');
t_eq($r1, mam_meeting_jitsi_room(4242), 'room name is stable for a session');
t_ok($r1 !== mam_meeting_jitsi_room(4243), 'different sessions get different rooms');
t_ok(strpos(mam_meeting_jitsi_url(4242), $r1) !== false, 'join URL contains the room');

t_group('meetings — instructor clock-in + late charge');

$MT_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($MT_course === 0) $MT_course = mam_db_insert('courses', ['code' => 'mt_c', 'name' => 'MT', 'kind' => 'core']);
mam_setting_set('instructor_clockin_grace_min', '5');
mam_setting_set('late_charge_enabled', '1');

$mkSess = function (int $offsetSec) use ($MT_course) {
    $ins = mam_db_insert('users', ['name' => 'MT Ins', 'email' => 'mt_ins' . bin2hex(random_bytes(3)) . '@test.local', 'role' => 'instructor', 'password_hash' => '', 'status' => 'active']);
    $coh = mam_db_insert('cohorts', ['course_id' => $MT_course, 'title' => 'MT Cohort', 'status' => 'active', 'capacity' => 10, 'instructor_id' => $ins]);
    $sess = mam_db_insert('class_sessions', ['cohort_id' => $coh, 'title' => 'MT Session', 'scheduled_at' => date('Y-m-d H:i:s', time() + $offsetSec), 'status' => 'scheduled', 'duration_min' => 60, 'kind' => 'class']);
    return ['ins' => $ins, 'sess' => $sess];
};

// On time (starts in 2 min).
$e = $mkSess(120);
$ci = mam_meeting_clock_in($e['sess'], $e['ins']);
t_eq($ci['status'], 'present', 'clocking in before start is present');
t_eq(mam_accountability_outstanding_cents('instructor', $e['ins']), 0, 'no charge for an on-time instructor');
// Idempotent.
$ci2 = mam_meeting_clock_in($e['sess'], $e['ins']);
t_eq((int) $ci2['id'], (int) $ci['id'], 'a second clock-in is idempotent');

// Late (started 15 min ago, grace 5).
$e = $mkSess(-15 * 60);
$ci = mam_meeting_clock_in($e['sess'], $e['ins']);
t_eq($ci['status'], 'late', 'clocking in past the grace window is late');
t_ok((int) $ci['minutes_late'] >= 10, 'minutes-late recorded');
/* This used to assert that a late instructor IS charged $2 — i.e. the test
   encoded, and protected, a wage deduction.

   Nigeria's Labour Act s.5(1) bars deductions from wages "for or in respect of
   any fines" save for wilful-misconduct loss with an authorised labour officer's
   prior written consent, and the National Industrial Court has struck down
   unilateral deductions repeatedly. Whether it binds MAM's instructors depends
   on their employment status (s.91) — a question for counsel — so the code takes
   the safe default and the test now pins THAT: with the instructor gate closed
   (its default) the lateness is recorded and no money is owed. */
t_ok(!mam_accountability_charging_on('instructor'),
    'the instructor charge gate is closed by default');
t_eq(mam_accountability_outstanding_cents('instructor', $e['ins']), 0,
    'a late instructor owes nothing while the gate is closed');
$__lateRow = mam_db_row("SELECT status, amount_usd_cents FROM accountability_charges
                          WHERE subject_type = 'instructor' AND subject_id = ? AND session_id = ?",
    [$e['ins'], $e['sess']]);
t_ok($__lateRow !== null, '…but the lateness IS on record');
t_eq((string) $__lateRow['status'], MAM_ACCT_RECORDED, '…as `recorded`');

/* And the gate genuinely works when an academy turns it on with legal advice —
   a switch that does nothing is not a policy control. */
mam_setting_set('late_charge_instructor_enabled', '1');
$__onSess = $mkSess(-15 * 60);
mam_meeting_clock_in($__onSess['sess'], $__onSess['ins']);
t_eq(mam_accountability_outstanding_cents('instructor', $__onSess['ins']), 200,
    'with the gate deliberately opened, the configured $2 applies');
mam_setting_set('late_charge_instructor_enabled', '0');

// Clock out.
$co = mam_meeting_clock_out($e['sess'], $e['ins']);
t_ok($co['clock_out_at'] !== null, 'clock-out recorded');
$threw = false;
try { mam_meeting_clock_out($mkSess(120)['sess'], 999999); } catch (\RuntimeException $ex) { $threw = ($ex->getMessage() === 'not_clocked_in'); }
t_ok($threw, 'clocking out without clocking in is refused');
mam_setting_set('late_charge_enabled', '0');

t_group('meetings — CSV parse + reconcile import');

$rows = mam_meeting_parse_csv("Name,Email,Join,Leave\nAda Lovelace,ada@test.local,2026-09-01 10:00:00,2026-09-01 11:00:00\nGrace Hopper,,2026-09-01 10:05:00,2026-09-01 10:55:00");
t_eq(count($rows), 2, 'CSV header skipped, two data rows parsed');
t_eq($rows[0]['email'], 'ada@test.local', 'email column parsed');

$e = $mkSess(120);
$stu = mam_db_insert('users', ['name' => 'Ada Lovelace', 'email' => 'ada@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
$n = mam_meeting_reconcile_import($e['sess'], $rows, 'meet', 'import');
t_eq($n, 2, 'two participants imported');
$recon = mam_db_all('SELECT * FROM meeting_reconciliation WHERE session_id = ? ORDER BY id', [$e['sess']]);
$ada = array_values(array_filter($recon, fn($x) => (string) $x['display_name'] === 'Ada Lovelace'))[0];
t_eq((int) $ada['subject_id'], $stu, 'participant matched to a user by email');
t_eq((int) $ada['minutes'], 60, 'meeting minutes computed from join/leave');
// Re-import replaces (no duplication).
mam_meeting_reconcile_import($e['sess'], $rows, 'meet', 'import');
t_eq((int) mam_db_row('SELECT COUNT(*) c FROM meeting_reconciliation WHERE session_id = ?', [$e['sess']])['c'], 2, 're-import replaces the prior set');

t_group('meetings — google scaffold inert + session view');

t_ok(mam_meeting_google_configured() === false, 'google live API is not configured');
t_ok(mam_meeting_google_fetch($e['sess']) === null, 'google fetch returns null (falls back to import)');
$view = mam_meeting_session_view($e['sess']);
t_ok(strpos($view['jitsi_url'], 'MAM-' . $e['sess'] . '-') !== false, 'session view carries the jitsi room');
t_eq(count($view['reconciliation']), 2, 'session view shows the reconciled participants');
