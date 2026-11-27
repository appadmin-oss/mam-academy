<?php
/**
 * Tests for paid class rescheduling (api/modules/academics/lib/reschedule.php, LMS 2.0 Part C):
 * server-side pricing + free allowance, the wallet settlement on the Part A
 * money model, instructor approve→apply through the audited session writer,
 * decline/cancel refunds, the payment-expiry sweep, and the feature gate.
 */
declare(strict_types=1);

t_group('reschedule — setup & feature gate');

// Deterministic config for the suite.
mam_setting_set('reschedule_enabled', '0');
mam_setting_set('reschedule_fee_usd', '10');
mam_setting_set('reschedule_fee_mode', 'per_session');
mam_setting_set('reschedule_free_per_term', '1');
mam_setting_set('reschedule_max_per_term', '3');
mam_setting_set('reschedule_window_hours', '24');
mam_setting_set('reschedule_payment_expiry_hours', '24');
mam_setting_set('reschedule_approval_required', '1');

$RS_courseId = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($RS_courseId === 0) {
    $RS_courseId = mam_db_insert('courses', ['code' => 'rs_course', 'name' => 'RS Course', 'kind' => 'core']);
}
$RS_instr = mam_db_insert('users', ['name' => 'RS Instr', 'email' => 'rs_instr@test.local', 'role' => 'instructor', 'password_hash' => '', 'status' => 'active']);
$RS_admin = mam_db_insert('users', ['name' => 'RS Admin', 'email' => 'rs_admin@test.local', 'role' => 'admin', 'password_hash' => '', 'status' => 'active']);
$rs_seq = 0;

/** Build an isolated student+cohort+session env with wallet credit (USD cents).
 *  Sessions are scheduled far in the future so the lead-time window is open. */
$rs_env = function (int $creditCents = 5000, int $sessionCount = 1, string $firstAt = '') use (&$rs_seq, $RS_courseId, $RS_instr) {
    $rs_seq++;
    $stu = mam_db_insert('users', ['name' => "RS Stu $rs_seq", 'email' => "rs_stu$rs_seq@test.local", 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
    $coh = mam_db_insert('cohorts', ['course_id' => $RS_courseId, 'instructor_id' => $RS_instr, 'title' => "RS Cohort $rs_seq", 'status' => 'active', 'capacity' => 10]);
    mam_db_insert('course_enrollments', ['student_id' => $stu, 'course_id' => $RS_courseId, 'cohort_id' => $coh, 'status' => 'active']);
    $sessions = [];
    for ($i = 0; $i < $sessionCount; $i++) {
        $at = $firstAt !== '' ? $firstAt : date('Y-m-d H:i:s', time() + (10 + $i) * 86400);
        $sessions[] = mam_db_insert('class_sessions', ['cohort_id' => $coh, 'scheduled_at' => $at, 'status' => 'scheduled', 'kind' => 'class', 'duration_min' => 60]);
    }
    if ($creditCents > 0) mam_wallet_post($stu, $creditCents, 'admin_credit', null);
    return ['student' => $stu, 'cohort' => $coh, 'sessions' => $sessions];
};
$rs_moves = function (array $sessionIds) {
    $newAt = date('Y-m-d H:i:s', time() + 30 * 86400);
    return array_map(fn($sid) => ['session_id' => $sid, 'new_scheduled_at' => $newAt], $sessionIds);
};

// Gate: disabled → every entry point refuses.
$e = $rs_env();
$threw = false;
try { mam_reschedule_create($e['student'], $e['cohort'], $rs_moves($e['sessions']), 'x'); }
catch (\RuntimeException $ex) { $threw = ($ex->getMessage() === 'reschedule_disabled'); }
t_ok($threw, 'create refused while reschedule_enabled=0');

mam_setting_set('reschedule_enabled', '1');
t_ok(mam_reschedule_enabled(), 'feature enabled after toggle');

t_group('reschedule — server-side pricing');

$e = $rs_env();
$q1 = mam_reschedule_quote($e['student'], $e['cohort'], 1);
t_eq($q1['fee_usd_cents'], 0, 'first session is free (free_per_term=1)');
t_eq($q1['free_remaining'], 1, 'one free reschedule remaining');
$q2 = mam_reschedule_quote($e['student'], $e['cohort'], 2);
t_eq($q2['billable'], 1, '2 sessions → 1 billable after the free one');
t_eq($q2['fee_usd_cents'], 1000, '1 billable session @ $10 → 1000 usd cents');
t_ok($q2['fee_ngn'] > 0, 'quote carries a naira equivalent');
$q4 = mam_reschedule_quote($e['student'], $e['cohort'], 4);
t_ok($q4['limit_exceeded'], '4 sessions exceeds max_per_term=3');

t_group('reschedule — free request (fee 0)');

$e = $rs_env();
$req = mam_reschedule_create($e['student'], $e['cohort'], $rs_moves($e['sessions']), 'Family trip');
t_eq($req['status'], 'paid', 'free request settles straight to paid (awaiting approval)');
t_eq((int)$req['fee_usd_cents'], 0, 'no fee charged');
t_eq(count($req['items']), 1, 'one item recorded');
$evs = array_column($req['events'], 'event');
t_ok(in_array('created', $evs, true) && in_array('paid', $evs, true), 'created + paid events logged');

t_group('reschedule — paid wallet flow + honest revenue');

$e = $rs_env(5000); // $50 credit
// Burn the free allowance first so the next request is billable.
mam_reschedule_create($e['student'], $e['cohort'], $rs_moves([$e['sessions'][0]]), 'first (free)');
$e2sessions = [mam_db_insert('class_sessions', ['cohort_id' => $e['cohort'], 'scheduled_at' => date('Y-m-d H:i:s', time() + 12 * 86400), 'status' => 'scheduled', 'kind' => 'class', 'duration_min' => 60])];
$paid = mam_reschedule_create($e['student'], $e['cohort'], $rs_moves($e2sessions), 'second (paid)');
t_eq($paid['status'], 'pending_payment', 'billable request awaits payment');
t_eq((int)$paid['fee_usd_cents'], 1000, 'fee is $10');

$revBefore = mam_money_revenue_usd_cents();
$balBefore = mam_wallet_balance($e['student']);
$afterPay = mam_reschedule_pay_wallet($paid['id'], $e['student']);
t_eq($afterPay['status'], 'paid', 'wallet payment moves request to paid');
t_eq(mam_wallet_balance($e['student']), $balBefore - 1000, 'wallet debited $10');
t_eq(mam_money_revenue_usd_cents() - $revBefore, 1000, 'settlement counts as +$10 honest revenue');
$payRow = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$afterPay['payment_ref']]);
t_eq($payRow['purpose'], 'reschedule', 'payment tagged purpose=reschedule');
t_eq((int)$payRow['amount_usd_cents'], 1000, 'payment carries canonical USD cents');

t_group('reschedule — approve → apply through the audited writer');

$e = $rs_env(0); // no credit needed; use the free allowance
$sid = $e['sessions'][0];
$oldAt = mam_db_row('SELECT scheduled_at FROM class_sessions WHERE id = ?', [$sid])['scheduled_at'];
$req = mam_reschedule_create($e['student'], $e['cohort'], $rs_moves([$sid]), 'free move');
$applied = mam_reschedule_approve($req['id'], $RS_instr, 'ok');
t_eq($applied['status'], 'applied', 'approve → applied');
$newAt = mam_db_row('SELECT scheduled_at FROM class_sessions WHERE id = ?', [$sid])['scheduled_at'];
t_ok($newAt !== $oldAt, 'the session was actually moved');
t_eq((int)$applied['items'][0]['applied'], 1, 'item marked applied');
$hist = mam_db_row("SELECT COUNT(*) AS c FROM session_history WHERE session_id = ? AND field_changed='scheduled_at'", [$sid]);
t_ok((int)$hist['c'] >= 1, 'move recorded in session_history (audited writer reused)');

t_group('reschedule — decline refunds a paid fee');

mam_setting_set('reschedule_free_per_term', '0'); // make everything billable
$e = $rs_env(5000);
$req = mam_reschedule_create($e['student'], $e['cohort'], $rs_moves([$e['sessions'][0]]), 'paid');
$paid = mam_reschedule_pay_wallet($req['id'], $e['student']);
$balAfterPay = mam_wallet_balance($e['student']);
$revAfterPay = mam_money_revenue_usd_cents();
$declined = mam_reschedule_decline($req['id'], $RS_instr, 'No slots');
t_eq($declined['status'], 'declined', 'decline is terminal');
t_eq(mam_wallet_balance($e['student']), $balAfterPay + 1000, 'fee refunded to wallet');
t_eq(mam_money_revenue_usd_cents(), $revAfterPay - 1000, 'refund removes the settlement from revenue');
t_eq(mam_db_row('SELECT status FROM payments WHERE reference = ?', [$paid['payment_ref']])['status'], 'refunded',
     'settlement payment marked refunded');
t_ok(in_array('refunded', array_column($declined['events'], 'event'), true), 'refunded event logged');

t_group('reschedule — cancel & expiry');

$e = $rs_env(0); // unpaid pending
$req = mam_reschedule_create($e['student'], $e['cohort'], $rs_moves([$e['sessions'][0]]), 'oops');
t_eq($req['status'], 'pending_payment', 'billable + no credit → pending_payment');
$cancelled = mam_reschedule_cancel($req['id'], $e['student']);
t_eq($cancelled['status'], 'cancelled', 'requester can cancel an unpaid request');

// Expiry sweep: force the window into the past then sweep.
$e = $rs_env(0);
$req = mam_reschedule_create($e['student'], $e['cohort'], $rs_moves([$e['sessions'][0]]), 'will expire');
mam_db_update('reschedule_requests', ['expires_at' => date('Y-m-d H:i:s', time() - 3600)], ['id' => $req['id']]);
$n = mam_reschedule_expire_due();
t_ok($n >= 1, 'expiry sweep expired at least one request');
t_eq(mam_db_row('SELECT status FROM reschedule_requests WHERE id = ?', [$req['id']])['status'], 'expired',
     'the lapsed request is now expired');

t_group('reschedule — guards');

// Insufficient credit.
$e = $rs_env(500); // $5 only
$req = mam_reschedule_create($e['student'], $e['cohort'], $rs_moves([$e['sessions'][0]]), 'too pricey');
$threw = false;
try { mam_reschedule_pay_wallet($req['id'], $e['student']); }
catch (\RuntimeException $ex) { $threw = ($ex->getMessage() === 'insufficient_credit'); }
t_ok($threw, 'paying with too little wallet credit throws insufficient_credit');

// Lead-time window closed (session within window_hours).
mam_setting_set('reschedule_free_per_term', '1');
$e = $rs_env(0, 1, date('Y-m-d H:i:s', time() + 3600)); // 1h out < 24h window
$threw = false;
try { mam_reschedule_create($e['student'], $e['cohort'], $rs_moves($e['sessions']), 'too soon'); }
catch (\RuntimeException $ex) { $threw = ($ex->getMessage() === 'window_closed'); }
t_ok($threw, 'a session inside the lead-time window is refused');

// Not enrolled.
$stranger = mam_db_insert('users', ['name' => 'Stranger', 'email' => 'rs_stranger@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
$e = $rs_env(0);
$threw = false;
try { mam_reschedule_create($stranger, $e['cohort'], $rs_moves($e['sessions']), 'not mine'); }
catch (\RuntimeException $ex) { $threw = ($ex->getMessage() === 'not_enrolled'); }
t_ok($threw, 'a non-enrolled user cannot request a reschedule');

t_group('reschedule — auto-apply when approval not required');

mam_setting_set('reschedule_approval_required', '0');
$e = $rs_env(0);
$sid = $e['sessions'][0];
$oldAt = mam_db_row('SELECT scheduled_at FROM class_sessions WHERE id = ?', [$sid])['scheduled_at'];
$req = mam_reschedule_create($e['student'], $e['cohort'], $rs_moves([$sid]), 'auto');
t_eq($req['status'], 'applied', 'free request auto-applies when approval not required');
t_ok(mam_db_row('SELECT scheduled_at FROM class_sessions WHERE id = ?', [$sid])['scheduled_at'] !== $oldAt,
     'session moved by the auto-apply path');
mam_setting_set('reschedule_approval_required', '1');

// Provider settlement seam.
mam_setting_set('reschedule_free_per_term', '0');
$e = $rs_env(0);
$req = mam_reschedule_create($e['student'], $e['cohort'], $rs_moves([$e['sessions'][0]]), 'card');
$marked = mam_reschedule_mark_paid($req['id'], 'PROV-REF-1', 'paystack');
t_eq($marked['status'], 'paid', 'provider seam flips pending_payment → paid');
mam_setting_set('reschedule_free_per_term', '1');
