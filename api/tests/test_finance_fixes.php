<?php
/**
 * The finance audit's faults (2026-11-28), one group each. Every group first
 * reproduces the fault the audit measured, then pins the fix:
 *   1. settlement is recoverable — the claim and its effects job are one write,
 *      a crash in the effects leaves a job, and finishing it is idempotent;
 *   2. a refund brings an invoice balance back (admin and gateway paths);
 *   3. an adult learner with no guardian can be invoiced, reviewed and pay;
 *   4. the console's money figures compare like with like;
 *   5. a bank-confirmed payment settles through mam_payment_settle();
 *   6. only a unique-constraint violation reads as "already recorded";
 *   7. the Flutterwave webhook retries on error, refuses a second charge and
 *      any currency but naira, explicitly;
 *   8. a guardian-thread post really notifies the other participants.
 */
declare(strict_types=1);

$FF_root = dirname(MAM_API_ROOT);
$FF_src  = static fn(string $rel): string => (string) file_get_contents($FF_root . '/' . $rel);
$FF_sfx  = substr(bin2hex(random_bytes(4)), 0, 8);
$FF_err  = static function (callable $fn): string { try { $fn(); return 'none'; } catch (\Throwable $e) { return $e->getMessage(); } };

$FF_admin = (int) mam_db_insert('users', ['name' => 'FF Admin', 'email' => "ffadmin.$FF_sfx@test.local", 'role' => 'admin', 'password_hash' => 'x', 'status' => 'active']);
$FF_kid   = (int) mam_db_insert('users', ['name' => 'FF Kid', 'email' => "ffkid.$FF_sfx@test.local", 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$FF_par   = (int) mam_parent_link("ffguardian.$FF_sfx@test.local", $FF_kid)['parent_id'];
$FF_inv   = static function (string $label, string $amount, string $cur = 'NGN') use ($FF_kid, $FF_par, $FF_admin): int {
    $id = mam_fee_invoice_open($FF_kid, $FF_par, $FF_admin, ['term_label' => $label, 'currency' => $cur]);
    mam_fee_line_add($id, 'tuition', 'Piano', $amount, 1);
    mam_fee_invoice_issue($id, $FF_admin, '2027-03-01');
    return $id;
};
$FF_fresh = static fn(string $ref) => mam_db_row('SELECT * FROM payments WHERE reference = ?', [$ref]);
$FF_job   = static fn(string $ref) => mam_db_row("SELECT * FROM jobs WHERE kind = 'settle_effects' AND payload = ? ORDER BY id DESC LIMIT 1",
    [json_encode(['ref' => $ref], JSON_UNESCAPED_SLASHES)]);
$FF_paid  = static fn(int $inv) => (int) mam_db_row('SELECT paid_minor FROM fee_invoices WHERE id = ?', [$inv])['paid_minor'];
$FF_isSqlite = mam_db_driver() === 'sqlite';

/* ── 1 ── */
t_group('finance fixes — 1. settlement is recoverable');

$FF_i1 = $FF_inv("FF settle $FF_sfx", '50000.00');
$FF_p1 = mam_fee_online_start($FF_par, $FF_i1, null);
if ($FF_isSqlite) {
    // The fault: effects ran OUTSIDE the claim; a crash between the two left the
    // money taken, nothing granted, and a retried webhook saw "already".
    mam_db()->exec("CREATE TEMP TRIGGER ff_outage BEFORE INSERT ON fee_payments BEGIN SELECT RAISE(ABORT, 'simulated outage'); END");
    try { $FF_s1 = mam_payment_settle($FF_fresh($FF_p1['reference']), ['channel' => 'card'], '{}', $FF_p1['reference']); }
    finally { mam_db()->exec('DROP TRIGGER IF EXISTS ff_outage'); }
    t_ok($FF_s1['ok'] === true, 'a settled payment never fails because an effect could not be applied');
    t_ok(!empty($FF_s1['effects_pending']), 'the caller is told the effects are still pending');
    t_eq($FF_fresh($FF_p1['reference'])['status'], 'succeeded', 'the money is recorded as taken');
    t_eq($FF_paid($FF_i1), 0, 'the invoice is not yet credited (the outage stopped it)');
    $FF_j1 = $FF_job($FF_p1['reference']);
    t_ok($FF_j1 && $FF_j1['status'] === 'pending', 'and a settle_effects job is waiting to finish it — nothing is lost');
    t_ok(in_array($FF_p1['reference'], array_column(mam_payment_settle_backlog(500), 'reference'), true), 'the console reconciliation list shows it');
    t_ok(mam_payment_settle($FF_fresh($FF_p1['reference']), [], '{}', $FF_p1['reference'])['already'] === true, 'a retried webhook still sees "already" — but now there is a job behind it');

    // The job runner's handler finishes it; running it twice credits once.
    $FF_h = mam_job_handlers()['settle_effects'] ?? null;
    t_ok(is_callable($FF_h), 'the job runner has a settle_effects handler');
    t_ok($FF_h(['ref' => $FF_p1['reference']]) === true, 'the handler applies the effects');
    $FF_h(['ref' => $FF_p1['reference']]);
    t_eq($FF_paid($FF_i1), 5000000, 'the invoice is credited ₦50,000');
    t_eq((int) mam_db_row('SELECT COUNT(*) n FROM fee_payments WHERE reference = ?', [$FF_p1['reference']])['n'], 1, 'exactly once, however often the job runs');
    $FF_fin = mam_payment_settle_finish($FF_p1['reference']);
    t_ok($FF_fin['ok'], "the console's Finish is safe on a payment already finished");
    t_eq((int) mam_db_row('SELECT COUNT(*) n FROM fee_payments WHERE reference = ?', [$FF_p1['reference']])['n'], 1, '…and credits nothing twice');
    t_eq((string) $FF_job($FF_p1['reference'])['status'], 'done', 'and the job is marked done');
    t_ok(!in_array($FF_p1['reference'], array_column(mam_payment_settle_backlog(500), 'reference'), true), 'so it leaves the reconciliation list');
}
$FF_p1b = mam_fee_online_start($FF_par, $FF_inv("FF settle ok $FF_sfx", '1000.00'), null);
$FF_s1b = mam_payment_settle($FF_fresh($FF_p1b['reference']), ['channel' => 'card'], '{}', $FF_p1b['reference']);
t_ok($FF_s1b['ok'] && empty($FF_s1b['effects_pending']), 'an ordinary settlement applies its effects at once');
t_eq((string) ($FF_job($FF_p1b['reference'])['status'] ?? ''), 'done', 'and its job row is written (in the claim) and marked done');
$FF_pay = $FF_src('api/modules/finance/lib/payments.php');
$FF_claim = substr($FF_pay, strpos($FF_pay, 'function mam_payment_settle('), 2400);
t_ok(strpos($FF_claim, 'beginTransaction') < strpos($FF_claim, "'settle_effects'") && strpos($FF_claim, "'settle_effects'") < strpos($FF_claim, '->commit()'),
    'the pending → succeeded claim and the job insert commit together');
t_ok(str_contains($FF_src('api/modules/kernel/lib/jobs.php'), "'settle_effects'"), 'the job kind is registered with the runner');
t_ok(str_contains($FF_src('admin/index.php'), "'payment_settle_finish' => 'settings'"), 'the Finish action is capability-mapped like refunds');
t_ok(str_contains($FF_src('partials/admin-console/ledger.php'), "ac_form('payment_settle_finish'"), 'and the ledger screen offers it');
$FF_led = mam_console_ledger();
t_ok(array_key_exists('unfinished', $FF_led), 'the ledger provider reports unfinished settlements');
t_eq(mam_console_failures(), [], 'with no failed read');
t_ok(str_contains($FF_pay, 'a.identity_hold IS NULL'), 'a payment held for an identity check is not listed as unfinished');

/* ── 2 ── */
t_group('finance fixes — 2. a refund brings the invoice balance back');

$FF_i2 = $FF_inv("FF refund $FF_sfx", '20000.00');
$FF_p2 = mam_fee_online_start($FF_par, $FF_i2, null);
mam_payment_settle($FF_fresh($FF_p2['reference']), ['channel' => 'card'], '{}', $FF_p2['reference']);
t_eq($FF_paid($FF_i2), 2000000, 'the invoice is paid');
$FF_rev = mam_payment_refund_apply($FF_fresh($FF_p2['reference']), 'Family withdrew', $FF_admin);
t_eq($FF_fresh($FF_p2['reference'])['status'], 'refunded', 'an admin refund marks the payment refunded');
t_eq($FF_paid($FF_i2), 0, 'and voids its fee payment, so the balance is owed again (it used to stay "paid")');
t_eq((string) mam_db_row('SELECT status FROM fee_payments WHERE reference = ?', [$FF_p2['reference']])['status'], 'void', 'the fee payment is void, not deleted');
t_ok(count($FF_rev) === 1, 'what was reversed is returned for the audit');
t_ok(substr_count($FF_src('api/modules/finance/lib/payments.php'), "mam_audit('payment_refund_applied'") === 2, 'and audited, on both the admin and the gateway path (the harness stubs mam_audit, so this is read from the source)');
t_eq(mam_payment_refund_apply($FF_fresh($FF_p2['reference']), 'again', $FF_admin), [], 'refunding twice reverses nothing twice');
t_ok(str_contains($FF_src('admin/index.php'), 'mam_payment_refund_apply($p'), 'the console refund action uses it');

$FF_i2b = $FF_inv("FF gw refund $FF_sfx", '30000.00');
$FF_p2b = mam_fee_online_start($FF_par, $FF_i2b, null);
mam_payment_settle($FF_fresh($FF_p2b['reference']), ['channel' => 'card'], '{}', $FF_p2b['reference']);
$FF_col0 = mam_console_collected_cents();
$FF_fee0 = mam_console_fee_collected_cents();
$FF_ev = mam_paystack_handle_gateway_event('refund.processed', ['transaction_reference' => $FF_p2b['reference'], 'amount' => 3000000, 'status' => 'processed']);
t_eq($FF_ev['state'], 'refunded', 'Paystack refund.processed marks the payment refunded');
t_eq($FF_paid($FF_i2b), 0, 'and voids the fee credit, so the balance comes back');
t_eq($FF_fresh($FF_p2b['reference'])['status'], 'succeeded', 'the payment row itself stays settled (what it paid for is a person\'s decision)');
t_eq(mam_console_collected_cents(), $FF_col0 - (int) $FF_p2b['amount_usd_cents'], 'and it leaves Collected');
t_ok(mam_console_fee_collected_cents() < $FF_fee0, 'and fee collected falls by it');

/* ── 3 ── */
t_group('finance fixes — 3. an adult learner pays their own invoice');

$FF_adultEmail = "ffadult.$FF_sfx@test.local";
$FF_adult = (int) mam_db_insert('users', ['name' => 'FF Adult', 'email' => $FF_adultEmail, 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
mam_db_insert('customers', ['email' => $FF_adultEmail, 'first_name' => 'FF', 'last_name' => 'Adult', 'dob' => '1990-04-02']);
$FF_noAge = (int) mam_db_insert('users', ['name' => 'FF NoAge', 'email' => "ffnoage.$FF_sfx@test.local", 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$FF_teenEmail = "ffteen.$FF_sfx@test.local";
$FF_teen = (int) mam_db_insert('users', ['name' => 'FF Teen', 'email' => $FF_teenEmail, 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
mam_db_insert('customers', ['email' => $FF_teenEmail, 'first_name' => 'FF', 'last_name' => 'Teen', 'dob' => date('Y-m-d', strtotime('-14 years'))]);

t_ok(mam_fee_student_self_pays($FF_adult), 'an adult with no guardian is their own payer');
t_ok(!mam_fee_student_self_pays($FF_noAge), 'unknown age is never treated as adult');
t_ok(!mam_fee_student_self_pays($FF_teen), 'a child is never their own payer');
t_ok(!mam_fee_student_self_pays($FF_kid), 'nor is a student who has a guardian');

$FF_ai = mam_fee_invoice_open($FF_adult, null, $FF_admin, ['term_label' => "FF adult $FF_sfx", 'currency' => 'NGN']);
mam_fee_line_add($FF_ai, 'tuition', 'Voice', '40000.00', 1);
t_eq((int) mam_db_row('SELECT guardian_id FROM fee_invoices WHERE id = ?', [$FF_ai])['guardian_id'], $FF_adult, 'the invoice names the adult as its payer');
$FF_blocks = array_filter(mam_fee_review_checks($FF_ai), fn($c) => $c['level'] === 'block');
t_eq(array_values($FF_blocks), [], 'review does not refuse it (it used to: "Nobody is named to pay it")');
$FF_ti = mam_fee_invoice_open($FF_noAge, null, $FF_admin, ['term_label' => "FF noage $FF_sfx", 'currency' => 'NGN']);
mam_fee_line_add($FF_ti, 'tuition', 'Voice', '40000.00', 1);
t_ok((bool) array_filter(mam_fee_review_checks($FF_ti), fn($c) => $c['level'] === 'block'), 'an unknown-age learner with no guardian is still blocked for a person to resolve');

mam_fee_invoice_issue($FF_ai, $FF_admin, '2027-03-01');
$FF_ap = mam_fee_online_start($FF_adult, $FF_ai, null);
t_eq((int) $FF_ap['fee_payer_id'], $FF_adult, 'the adult can open a charge on their own invoice');
t_eq($FF_err(fn() => mam_fee_online_start($FF_adult, $FF_inv("FF other $FF_sfx", '10.00'), null)), 'forbidden', 'but not on anybody else\'s');
t_eq($FF_err(fn() => mam_fee_online_start($FF_noAge, $FF_ti, null)), 'forbidden', 'a learner of unknown age is not their own payer, so cannot open one');
$FF_route = $FF_src('api/routes/student/fee-pay.php');
t_ok(str_contains($FF_route, "mam_require_role('student')") && str_contains($FF_route, 'mam_fee_online_start((int) $u[\'id\']'), 'the student route opens the charge as the signed-in student, the library refuses the rest');
t_ok((bool) preg_match("#post\('/fee-pay'.*student/fee-pay\.php.*mw_csrf\(\).*mw_role\('student'\)#", $FF_src('api/index.php')), 'the route is CSRF- and role-gated');
$FF_m = mam_sportal_money($FF_adult);
$FF_row = array_values(array_filter($FF_m['invoices'] ?? [], fn($r) => (int) $r['id'] === $FF_ai));
t_eq((int) ($FF_row[0]['self_pay'] ?? 0), 1, 'the student portal offers Pay online on their own invoice');
t_ok(str_contains($FF_src('assets/js/portal-student.js'), "'student/fee-pay'"), 'and the button posts to the student route');

/* ── 4 ── */
t_group('finance fixes — 4. the console money figures compare like with like');

$FF_rate = 1500.0;
$FF_ni = mam_fee_invoice_open($FF_kid, $FF_par, $FF_admin, ['term_label' => "FF fx $FF_sfx", 'currency' => 'NGN']);
mam_fee_line_add($FF_ni, 'tuition', 'Drums', '150000.00', 1);
mam_fee_invoice_issue($FF_ni, $FF_admin, '2027-03-01');
mam_db_update('fee_invoices', ['fx_rate_usd_ngn' => $FF_rate], ['id' => $FF_ni]);
$FF_c0 = mam_console_fee_collected_cents();
mam_fee_payment_record($FF_ni, '30000', 'cash', $FF_admin);
t_eq(mam_console_fee_collected_cents() - $FF_c0, 2000, 'cash at the desk counts as collected: ₦30,000 at the invoice\'s frozen ₦1,500 = $20.00');
$FF_pc0 = mam_console_collected_cents();
mam_fee_payment_record($FF_ni, '15000', 'cash', $FF_admin);
t_eq(mam_console_collected_cents(), $FF_pc0, 'and is not a gateway payment, so the payments total is not where it shows');
$FF_rp = mam_console_reports();
t_ok(!array_filter($FF_rp['aid'] ?? [], fn($a) => array_key_exists('minor', $a)), 'aid is reported in USD cents per invoice, never raw minor units summed across currencies');
t_ok(str_contains($FF_src('partials/admin-console/reports.php'), "usd_cents"), 'and the reports screen reads that');
$FF_con = $FF_src('api/modules/admin/lib/console.php');
t_ok(str_contains($FF_con, "FROM payments WHERE purpose = 'store' AND \" . MAM_CONSOLE_PAID"), 'store revenue is summed from integer cents on settled payments, not store_orders.total_usd');
t_ok(str_contains(MAM_CONSOLE_PAID, "gateway_state <> 'refunded'") && str_contains(MAM_CONSOLE_PAID, "status = 'succeeded'"), 'refunded money is excluded from every console paid figure');
$FF_st0 = mam_console_store()['revenue_usd'];
mam_db_insert('payments', ['reference' => "FF-STORE-$FF_sfx", 'provider' => 'paystack', 'purpose' => 'store', 'amount' => 1500, 'currency' => 'NGN',
    'amount_minor' => 150000, 'amount_usd_cents' => 100, 'status' => 'succeeded']);
t_eq(round(mam_console_store()['revenue_usd'] - $FF_st0, 2), 1.0, 'a $1.00 store sale adds exactly $1.00');
mam_db_update('payments', ['status' => 'refunded'], ['reference' => "FF-STORE-$FF_sfx"]);
t_eq(round(mam_console_store()['revenue_usd'] - $FF_st0, 2), 0.0, 'and a refunded one adds nothing');
t_eq(mam_console_failures(), [], 'no console read failed');

/* ── 5 ── */
t_group('finance fixes — 5. a bank-confirmed payment settles through the one path');

$FF_adm = $FF_src('admin/index.php');
$FF_cb = substr($FF_adm, strpos($FF_adm, "if (\$action === 'payment_confirm_bank') {"), 3000);
t_ok(str_contains($FF_cb, 'mam_payment_settle($p'), 'confirm-bank calls mam_payment_settle()');
t_ok(!str_contains($FF_cb, "mam_db_update('payments', [\n            'status' => 'succeeded'"), 'and no longer marks the row succeeded by hand');
$FF_i5 = $FF_inv("FF bank $FF_sfx", '12000.00');
$FF_p5 = mam_fee_online_start($FF_par, $FF_i5, null);
mam_db_update('payments', ['status' => 'pending_bank_transfer'], ['reference' => $FF_p5['reference']]);
$FF_s5 = mam_payment_settle($FF_fresh($FF_p5['reference']), null, '{}', null);
t_ok($FF_s5['ok'] && !$FF_s5['already'], 'a pending bank transfer settles through it');
t_eq($FF_paid($FF_i5), 1200000, 'and credits the invoice it paid (the hand-rolled path skipped fees entirely)');

/* ── 6 ── */
t_group('finance fixes — 6. only a unique violation reads as "already recorded"');

$FF_dupe = null;
try {
    mam_db_insert('webhook_events', ['provider' => 'ff', 'event_id' => "dup-$FF_sfx", 'status' => 'received']);
    mam_db_insert('webhook_events', ['provider' => 'ff', 'event_id' => "dup-$FF_sfx", 'status' => 'received']);
} catch (\Throwable $e) { $FF_dupe = $e; }
t_ok($FF_dupe !== null && mam_db_is_unique_violation($FF_dupe), 'a real unique-index violation is recognised');
t_ok(!mam_db_is_unique_violation(new RuntimeException('database is locked')), 'a lock is not');
t_ok(!mam_db_is_unique_violation(new PDOException('SQLSTATE[HY000]: General error: 1 no such column: zz')), 'nor a bad column');
$FF_fees = $FF_src('api/modules/finance/lib/fees.php');
t_ok(str_contains($FF_fees, 'mam_db_is_unique_violation($e)'), 'the fee settlement uses it');
if ($FF_isSqlite) {
    // An outage while recording the unapplied remainder used to be read as
    // "already recorded", so the overpayment silently vanished.
    $FF_i6 = $FF_inv("FF unapplied $FF_sfx", '1000.00');
    $FF_p6 = mam_fee_online_start($FF_par, $FF_i6, null);
    mam_fee_payment_record($FF_i6, '1000', 'cash', $FF_admin); // the balance is settled before the card clears
    mam_db()->exec("CREATE TEMP TRIGGER ff_outage2 BEFORE INSERT ON fee_online_unapplied BEGIN SELECT RAISE(ABORT, 'simulated outage'); END");
    try { $FF_s6 = mam_payment_settle($FF_fresh($FF_p6['reference']), ['channel' => 'card'], '{}', $FF_p6['reference']); }
    finally { mam_db()->exec('DROP TRIGGER IF EXISTS ff_outage2'); }
    t_ok(!empty($FF_s6['effects_pending']), 'a non-unique failure is surfaced as unfinished work, not swallowed');
    t_eq((string) ($FF_job($FF_p6['reference'])['status'] ?? ''), 'pending', 'and its job stays pending for a retry');
    mam_payment_settle_finish($FF_p6['reference']);
    t_eq((int) mam_db_row('SELECT COUNT(*) n FROM fee_online_unapplied WHERE reference = ?', [$FF_p6['reference']])['n'], 1, 'finishing records the money to refund, once');
}

/* ── 7 ── */
t_group('finance fixes — 7. Flutterwave refuses a second charge and any currency but naira');

$FF_i7 = $FF_inv("FF flw $FF_sfx", '5000.00');
$FF_p7 = mam_fee_online_start($FF_par, $FF_i7, null, 'flutterwave');
$FF_tx = static fn(array $over = []) => $over + ['id' => 9001, 'tx_ref' => $FF_p7['reference'], 'flw_ref' => "FLW-A-$FF_sfx", 'status' => 'successful', 'amount' => 5000, 'currency' => 'NGN'];
$FF_r7u = mam_flw_apply_tx($FF_fresh($FF_p7['reference']), $FF_tx(['currency' => 'USD', 'amount' => 5000]), 'test');
t_eq([$FF_r7u['outcome'], $FF_r7u['reason']], ['refused', 'currency_not_ngn'], 'a dollar charge is refused explicitly');
t_ok((bool) mam_db_row("SELECT 1 FROM payment_events WHERE reference = ? AND kind = 'currency_refused'", [$FF_p7['reference']]), 'and recorded where the office sees it');
t_eq($FF_fresh($FF_p7['reference'])['status'], 'pending', 'nothing is settled');
t_eq(mam_flw_apply_tx($FF_fresh($FF_p7['reference']), $FF_tx(['amount' => 4999]), 'test')['reason'], 'amount_mismatch', 'a short charge is refused');
$FF_r7 = mam_flw_apply_tx($FF_fresh($FF_p7['reference']), $FF_tx(), 'test');
t_eq($FF_r7['outcome'], 'settled', 'the right charge settles');
t_eq($FF_paid($FF_i7), 500000, 'and credits the invoice');
t_eq((string) $FF_fresh($FF_p7['reference'])['provider_reference'], "FLW-A-$FF_sfx", "the gateway's own charge id is what is stored");
t_eq(mam_flw_apply_tx($FF_fresh($FF_p7['reference']), $FF_tx(), 'test')['outcome'], 'already', 'the same charge again is a repeat');
$FF_r7d = mam_flw_apply_tx($FF_fresh($FF_p7['reference']), $FF_tx(['id' => 9002, 'flw_ref' => "FLW-B-$FF_sfx"]), 'test');
t_eq($FF_r7d['outcome'], 'duplicate_charge', 'a second, different charge is a duplicate');
t_eq((string) $FF_fresh($FF_p7['reference'])['gateway_state'], 'refund_due', 'flagged for a refund, credited nothing');
t_eq($FF_paid($FF_i7), 500000, 'the invoice is credited once');
mam_db_update('payments', ['provider_reference' => $FF_p7['reference'], 'gateway_state' => null], ['reference' => $FF_p7['reference']]);
t_eq(mam_flw_apply_tx($FF_fresh($FF_p7['reference']), $FF_tx(), 'test')['outcome'], 'already', 'a row settled before verify stored the charge id (our own reference) is never misread as paid twice');
t_ok(str_contains($FF_src('api/routes/payment-verify.php'), "\$providerRef = \$data['flw_ref'] ?? (\$data['tx_ref'] ?? \$ref)"), 'verify now stores the charge id too, so verify and webhook agree');
mam_test_env(['FLW_SECRET_KEY' => 'FLWSECK_TEST-abc']);
t_eq(mam_flw_mode(), 'test', 'a sandbox key reads as test mode');
mam_test_env(['FLW_SECRET_KEY' => 'FLWSECK-abc']);
t_eq(mam_flw_mode(), 'live', 'a live key as live');
mam_test_env(['FLW_SECRET_KEY' => '']);
$FF_wh = $FF_src('api/routes/webhook-flutterwave.php');
t_ok(str_contains($FF_wh, "mam_fail('processing_error', 500)"), 'a processing error answers non-200, so Flutterwave retries');
t_ok(str_contains($FF_wh, "(string) \$seen['status'] !== 'error'"), 'and the retry of an errored event is processed again, not acknowledged as a duplicate');
t_ok(strpos($FF_wh, "mam_fail('invalid_signature', 401)") < strpos($FF_wh, 'webhook_events'), 'the signature is still checked before anything is written');
t_ok(str_contains($FF_wh, 'mam_flw_verify_by_id(') && str_contains($FF_wh, 'mam_flw_apply_tx('), 'the charge is re-read with this install\'s key, then decided by the one function');
t_ok(str_contains($FF_src('api/routes/payment-verify.php'), "'flw_currency_refused'"), 'verify logs a non-naira refusal by name too');

/* ── 8 ── */
t_group('finance fixes — 8. a guardian-thread post notifies the other participants');

$FF_ins = (int) mam_db_insert('users', ['name' => 'FF Teacher', 'email' => "ffteach.$FF_sfx@test.local", 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$FF_th = mam_thread_open($FF_kid, 'FF practice plan', 'academic', $FF_ins);
mam_thread_add_participant($FF_th, $FF_admin);
$FF_n0 = static fn(int $uid) => (int) mam_db_row("SELECT COUNT(*) n FROM notifications WHERE user_id = ? AND type = 'thread.message'", [$uid])['n'];
[$FF_g0, $FF_a0, $FF_t0] = [$FF_n0($FF_par), $FF_n0($FF_admin), $FF_n0($FF_ins)];
mam_thread_post($FF_th, $FF_ins, 'Ten minutes of scales each day, please.');
t_eq($FF_n0($FF_par) - $FF_g0, 1, 'the guardian is notified (the link was an array: a swallowed TypeError meant nobody ever was)');
t_eq($FF_n0($FF_admin) - $FF_a0, 1, 'so is the admin on the thread');
t_eq($FF_n0($FF_ins) - $FF_t0, 0, 'the author is not notified of their own message');
$FF_url = static fn(int $uid) => (string) mam_db_row("SELECT url FROM notifications WHERE user_id = ? AND type = 'thread.message' ORDER BY id DESC LIMIT 1", [$uid])['url'];
t_eq($FF_url($FF_par), '/portal/parent/#messages', "the guardian's notice opens their Messages screen");
t_eq($FF_url($FF_admin), '/admin/console.php?v=comms', "the admin's opens the console's Communications");
mam_thread_post($FF_th, $FF_par, 'Thank you — will do.');
t_eq($FF_url($FF_ins), '/portal/instructor/', "the instructor's opens their portal");
