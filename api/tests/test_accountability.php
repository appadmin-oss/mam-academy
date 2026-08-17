<?php
/**
 * Tests for the accountability ledger (api/lib/accountability.php, PDF §13-15,37):
 * charge recording + idempotency, ledger/outstanding totals, the waiver
 * decision, and the auto-charge on a late check-in (gated by the toggle).
 */
declare(strict_types=1);

t_group('accountability — charges & ledger');

$AC_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($AC_course === 0) $AC_course = mam_db_insert('courses', ['code' => 'ac_c', 'name' => 'AC', 'kind' => 'core']);
$AC_admin = mam_db_insert('users', ['name' => 'AC Admin', 'email' => 'ac_admin@test.local', 'role' => 'admin', 'password_hash' => '', 'status' => 'active']);
$AC_stu = mam_db_insert('users', ['name' => 'AC Stu', 'email' => 'ac_stu@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
$AC_coh = mam_db_insert('cohorts', ['course_id' => $AC_course, 'title' => 'AC Cohort', 'status' => 'active', 'capacity' => 10]);
mam_db_insert('course_enrollments', ['student_id' => $AC_stu, 'course_id' => $AC_course, 'cohort_id' => $AC_coh, 'status' => 'active']);
$AC_sess = mam_db_insert('class_sessions', ['cohort_id' => $AC_coh, 'title' => 'AC Session', 'scheduled_at' => '2026-09-01 10:00:00', 'status' => 'held', 'duration_min' => 60, 'kind' => 'class']);

t_eq(mam_accountability_outstanding_cents('student', $AC_stu), 0, 'no charges to start');

/* ── The gate, tested before anything relies on it ───────────────────────────
   Charging is OFF by default and the gate now lives inside
   mam_accountability_charge() rather than in each caller — it used to read the
   setting into $cfg and never check it, so the guarantee belonged to whichever
   two call sites happened to test it themselves. With charging off the lateness
   is still RECORDED, because "we chose not to charge" and "this never happened"
   are different facts. */
$AC_gateStu = mam_db_insert('users', ['name' => 'AC Gate', 'email' => 'ac_gate@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
$AC_offSess = mam_db_insert('class_sessions', ['cohort_id' => $AC_coh, 'title' => 'AC Off', 'scheduled_at' => '2026-08-30 10:00:00', 'status' => 'held', 'duration_min' => 60, 'kind' => 'class']);
mam_setting_set('late_charge_enabled', '0');
$AC_off = mam_accountability_charge('student', $AC_gateStu, $AC_offSess, 'late_arrival', 9);
t_ok($AC_off > 0, 'with charging off the lateness is still recorded');
$AC_offRow = mam_db_row('SELECT status, amount_usd_cents FROM accountability_charges WHERE id = ?', [$AC_off]);
t_eq((string) $AC_offRow['status'], MAM_ACCT_RECORDED, '…as `recorded`, not `outstanding`');
t_eq((int) $AC_offRow['amount_usd_cents'], 0, '…for no money');
t_eq(mam_accountability_outstanding_cents('student', $AC_gateStu), 0, '…and nothing is owed');
t_ok(!in_array(MAM_ACCT_RECORDED, MAM_ACCT_OWED, true), '`recorded` is outside the owed set by construction');

// An admin entering an agreed charge by hand is a different act from the system
// imposing one, and stays available with the automatic path off.
$AC_manSess = mam_db_insert('class_sessions', ['cohort_id' => $AC_coh, 'title' => 'AC Man', 'scheduled_at' => '2026-08-31 10:00:00', 'status' => 'held', 'duration_min' => 60, 'kind' => 'class']);
$AC_man = mam_accountability_charge('student', $AC_gateStu, $AC_manSess, 'late_arrival', 5, 2.50, true);
t_eq(mam_accountability_outstanding_cents('student', $AC_gateStu), 250, 'an admin-initiated charge is applied even with the gate closed');
mam_accountability_resolve($AC_man, $AC_admin, 'reversed', 'test cleanup');
t_eq(mam_accountability_outstanding_cents('student', $AC_gateStu), 0, '…and reverses cleanly');

// The student and instructor gates are SEPARATE — enabling one must not enable
// the other. One is a fee to a customer, the other a deduction from pay.
mam_setting_set('late_charge_enabled', '1');
mam_setting_set('late_charge_instructor_enabled', '0');
t_ok(mam_accountability_charging_on('student'), 'the student gate is open');
t_ok(!mam_accountability_charging_on('instructor'), '…while the instructor gate stays shut');

// Record a $1 late-arrival charge (default student rate).
$cid = mam_accountability_charge('student', $AC_stu, $AC_sess, 'late_arrival', 7);
t_ok($cid > 0, 'charge recorded');
t_eq(mam_accountability_outstanding_cents('student', $AC_stu), 100, 'outstanding = $1');

// Idempotent per (subject, session, reason).
$cid2 = mam_accountability_charge('student', $AC_stu, $AC_sess, 'late_arrival', 7);
t_eq($cid2, $cid, 're-charging the same session+reason returns the same row');
t_eq(mam_accountability_outstanding_cents('student', $AC_stu), 100, 'no double-charge');

// Ledger summary.
$led = mam_accountability_ledger('student', $AC_stu);
t_eq($led['occurrences'], 1, 'ledger has one charge');
t_eq($led['outstanding_usd_cents'], 100, 'ledger outstanding total');
t_near((float) $led['charges'][0]['amount_usd_cents'], 100.0, 'charge carries canonical USD cents');

t_group('accountability — waiver decision (§15)');

$w = mam_accountability_resolve($cid, $AC_admin, 'waived', 'Academy technical problem');
t_eq($w['status'], 'waived', 'charge waived');
t_eq(mam_accountability_outstanding_cents('student', $AC_stu), 0, 'waiving clears the outstanding balance');
$led = mam_accountability_ledger('student', $AC_stu);
t_eq($led['waived_usd_cents'], 100, 'ledger tracks the waived amount');
// Double-resolve is refused.
$threw = false;
try { mam_accountability_resolve($cid, $AC_admin, 'paid', null); } catch (\RuntimeException $e) { $threw = ($e->getMessage() === 'already_resolved'); }
t_ok($threw, 'an already-resolved charge cannot be resolved again');
// Bad status refused.
$threw = false;
try { mam_accountability_resolve($cid, $AC_admin, 'nonsense', null); } catch (\InvalidArgumentException $e) { $threw = true; }
t_ok($threw, 'an unknown status is rejected');

t_group('accountability — auto-charge on late check-in (toggle)');

mam_setting_set('checkin_self_enabled', '1');
mam_setting_set('checkin_late_grace_min', '10');
mam_setting_set('checkin_geofence_enabled', '0');

// Disabled: a late check-in records NO charge.
mam_setting_set('late_charge_enabled', '0');
$env = function () use ($AC_course) {
    $stu = mam_db_insert('users', ['name' => 'AC L', 'email' => 'ac_l' . bin2hex(random_bytes(3)) . '@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
    $coh = mam_db_insert('cohorts', ['course_id' => $AC_course, 'title' => 'AC L', 'status' => 'active', 'capacity' => 10]);
    mam_db_insert('course_enrollments', ['student_id' => $stu, 'course_id' => $AC_course, 'cohort_id' => $coh, 'status' => 'active']);
    $sess = mam_db_insert('class_sessions', ['cohort_id' => $coh, 'title' => 'AC L sess', 'scheduled_at' => date('Y-m-d H:i:s', time() - 20 * 60), 'status' => 'scheduled', 'duration_min' => 60, 'kind' => 'class']);
    return ['student' => $stu, 'session' => $sess];
};
$e1 = $env();
$r1 = mam_checkin_self($e1['student'], $e1['session'], null, null);
t_eq($r1['status'], 'late', 'late check-in is marked late');
t_eq(mam_accountability_outstanding_cents('student', $e1['student']), 0, 'no charge while late_charge_enabled=0');

// Enabled: a late check-in records the $1 charge.
mam_setting_set('late_charge_enabled', '1');
$e2 = $env();
mam_checkin_self($e2['student'], $e2['session'], null, null);
t_eq(mam_accountability_outstanding_cents('student', $e2['student']), 100, 'late check-in records the $1 charge when enabled');
$row = mam_db_row("SELECT reason, minutes FROM accountability_charges WHERE subject_id = ? AND session_id = ?", [$e2['student'], $e2['session']]);
t_eq($row['reason'], 'late_arrival', 'charge reason is late_arrival');
t_ok((int) $row['minutes'] >= 1, 'minutes-late recorded');
mam_setting_set('late_charge_enabled', '0');
