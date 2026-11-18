<?php
/**
 * The office's side of fees (api/lib/fees.php, schema v91; HANDOFF §4t).
 *
 * v87 built invoices, aid, plans and bursary applications and nothing outside
 * the library and its tests could act on them: no invoice could be raised from
 * the console, no bursary decided, nothing marked paid, and a plan a guardian
 * "requested" was granted on the spot. These pin the office's actions, the
 * payments ledger, plan approval and the reminder ladder — and that the console
 * screens and the admin capability table actually reach them.
 */
declare(strict_types=1);

t_group('fees office — an invoice raised, issued and paid by the office');

$FO_stu = mam_db_insert('users', ['name' => 'Office Kid', 'email' => 'officekid@test.local',
    'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$FO_admin = mam_db_insert('users', ['name' => 'Office Admin', 'email' => 'officeadmin@test.local',
    'role' => 'admin', 'password_hash' => 'x', 'status' => 'active']);
$FO_link = mam_parent_link('officeguardian@test.local', $FO_stu);
$FO_par = (int) $FO_link['parent_id'];

$FO_inv = mam_fee_invoice_open($FO_stu, null, $FO_admin, ['term_label' => 'Spring 2027']);
mam_fee_line_add($FO_inv, 'tuition', 'Piano, Grade 3', '300.00', 1);
try { mam_fee_payment_record($FO_inv, '10', 'cash', $FO_admin); t_ok(false, 'a draft takes no payment'); }
catch (\RuntimeException $e) { t_eq($e->getMessage(), 'not_issued', 'a draft takes no payment: nothing is owed until it is issued'); }
mam_fee_invoice_issue($FO_inv, $FO_admin, '2027-01-10');

$FO_row = static fn() => mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$FO_inv]);
try { mam_fee_payment_record($FO_inv, '300.01', 'transfer', $FO_admin); t_ok(false, 'overpayment refused'); }
catch (\RuntimeException $e) { t_eq($e->getMessage(), 'more_than_due', 'more than is owed is refused — an overpayment is a refund, not a negative balance'); }
try { mam_fee_payment_record($FO_inv, '10', 'cheque', $FO_admin); t_ok(false, 'unknown method'); }
catch (\InvalidArgumentException $e) { t_eq($e->getMessage(), 'bad_method', 'a payment method off the list is refused'); }

$FO_p1 = mam_fee_payment_record($FO_inv, '100.00', 'transfer', $FO_admin, ['reference' => 'GTB-1182', 'received_on' => '2027-01-05']);
t_eq((int) $FO_row()['paid_minor'], 10000, 'a recorded payment moves paid_minor — it never moved before v91 except in tests');
t_eq($FO_row()['status'], 'part_paid', 'and the invoice is part paid');
$FO_st = mam_fee_statement($FO_par, $FO_stu);
$FO_rc = array_values(array_filter($FO_st['receipts'], fn($r) => ($r['reference'] ?? '') === 'GTB-1182'));
t_ok(count($FO_rc) === 1 && $FO_rc[0]['amount'] === '$100.00', 'the guardian sees it as a receipt on their statement, in the invoice currency');

mam_fee_payment_void($FO_p1, 'Bounced', $FO_admin);
t_eq((int) $FO_row()['paid_minor'], 0, 'voiding a payment brings the balance back — paid can go DOWN once payments are the record');
t_eq($FO_row()['status'], 'issued', 'and the invoice is owed again');
t_eq(mam_db_row('SELECT status, void_reason FROM fee_payments WHERE id = ?', [$FO_p1])['void_reason'], 'Bounced', 'the voided row stays, with its reason');

mam_fee_payment_record($FO_inv, '300', 'pos', $FO_admin);
t_eq($FO_row()['status'], 'paid', 'paying the whole balance marks it paid');

t_group('fees office — a plan a guardian asks for is a request until the office agrees it');

$FO_inv2 = mam_fee_invoice_open($FO_stu, null, $FO_admin, ['term_label' => 'Summer 2027']);
mam_fee_line_add($FO_inv2, 'tuition', 'Piano, Grade 3', '300.00', 1);
mam_fee_invoice_issue($FO_inv2, $FO_admin, '2027-04-01');
$FO_plan = mam_fee_plan_create($FO_inv2, 3, $FO_par, null);
t_ok($FO_plan['approved'] === false, 'a plan the guardian asked for reads as not approved');
try { mam_fee_plan_approve((int) $FO_plan['id'], null); t_ok(false, 'approver needed'); }
catch (\InvalidArgumentException $e) { t_eq($e->getMessage(), 'approver_required', 'approval names a person'); }
mam_fee_plan_approve((int) $FO_plan['id'], $FO_admin);
t_ok(mam_fee_plan_for($FO_inv2)['approved'] === true, 'once approved it says so');

mam_fee_payment_record($FO_inv2, '100.00', 'transfer', $FO_admin);
$FO_sched = mam_fee_plan_for($FO_inv2)['schedule'];
t_eq(array_column($FO_sched, 'status'), ['paid', 'pending', 'pending'], 'a payment clears the first instalment, in order');
mam_fee_payment_record($FO_inv2, '200.00', 'transfer', $FO_admin);
t_eq(mam_db_row('SELECT status FROM fee_payment_plans WHERE id = ?', [(int) $FO_plan['id']])['status'], 'completed', 'paying the rest completes the plan');

t_group('fees office — overdue reminders on the published ladder, forward only, to the billing guardian');

mam_automation_set('fee_reminders', true);
$FO_inv3 = mam_fee_invoice_open($FO_stu, null, $FO_admin, ['term_label' => 'Late 2026']);
mam_fee_line_add($FO_inv3, 'tuition', 'Theory', '50.00', 1);
$FO_late = date('Y-m-d', strtotime((function_exists('mam_calendar_today') ? mam_calendar_today() : date('Y-m-d')) . ' -8 days'));
mam_fee_invoice_issue($FO_inv3, $FO_admin, $FO_late);
$FO_before = (int) (mam_db_row("SELECT COUNT(*) n FROM mail_log WHERE context = 'fee_reminder'")['n'] ?? 0);
mam_fee_dunning_sweep();
mam_fee_dunning_sweep();
$FO_after = (int) (mam_db_row("SELECT COUNT(*) n FROM mail_log WHERE context = 'fee_reminder'")['n'] ?? 0);
$FO_r3 = mam_db_row('SELECT dunning_stage FROM fee_invoices WHERE id = ?', [$FO_inv3]);
t_eq((int) $FO_r3['dunning_stage'], count(MAM_FEE_DUNNING_DAYS), 'an invoice found eight days late jumps straight to the last step');
t_eq($FO_after - $FO_before, 1, 'and its guardian gets ONE email for it, however often the sweep runs');
$FO_mail = mam_db_row("SELECT to_email, subject FROM mail_log WHERE context = 'fee_reminder' ORDER BY id DESC LIMIT 1");
t_eq($FO_mail['to_email'] ?? '', 'officeguardian@test.local', 'to the billing guardian');
t_ok(str_contains((string) ($FO_mail['subject'] ?? ''), 'Fees outstanding'), 'saying the fees are outstanding');
$FO_src = (string) file_get_contents(dirname(__DIR__) . '/lib/fees.php');
t_ok(!preg_match('/suspend|lock_?out|withhold_?report/i', substr($FO_src, (int) strpos($FO_src, 'function mam_fee_dunning_sweep'))),
    'the reminder sweep suspends nothing and withholds nothing — the design\'s refusal, kept by leaving the mechanism unbuilt');
mam_automation_set('fee_reminders', false);
mam_db_update('fee_invoices', ['dunning_stage' => 0], ['id' => $FO_inv3]);
t_eq(mam_fee_dunning_sweep(), 0, 'switched off in Automations, it sends nothing');
mam_automation_set('fee_reminders', true);

t_group('fees office — the console and the admin handler reach all of it');

$FO_admin_src = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
foreach (['fee_invoice_open', 'fee_line_add', 'fee_line_waive', 'fee_invoice_issue', 'fee_invoice_void', 'fee_aid_award',
          'fee_credit_apply', 'fee_payment_record', 'fee_payment_void', 'fee_plan_approve', 'fee_plan_cancel', 'bursary_decide'] as $FO_a) {
    t_ok(preg_match("/'" . $FO_a . "' => 'fees'/", $FO_admin_src) === 1, "$FO_a is gated by the fees capability");
}
t_eq(mam_admin_capabilities()['fees'] ?? null, 'super', 'the fees capability is super-only by default, like refunds');
foreach (['mam_fee_invoice_open(', 'mam_fee_credit_apply(', 'mam_bursary_decide(', 'mam_fee_line_add(', 'mam_fee_payment_record('] as $FO_fn) {
    t_ok(str_contains($FO_admin_src, $FO_fn), "the admin handler calls $FO_fn — it had no caller outside the library");
}
$FO_cons = mam_console_invoices(['id' => $FO_inv2]);
t_ok(($FO_cons['detail']['id'] ?? 0) === $FO_inv2, 'the console opens one invoice with its detail');
t_ok(count($FO_cons['detail']['payments'] ?? []) === 2, 'with its payments');
t_ok(is_array($FO_cons['students']) && in_array($FO_stu, array_map('intval', array_column($FO_cons['students'], 'id')), true), 'and lists the students an invoice can be raised for');
$FO_pt = (string) file_get_contents(dirname(__DIR__, 2) . '/partials/admin-console/invoices.php');
foreach (['fee_invoice_open', 'fee_line_add', 'fee_payment_record', 'fee_invoice_issue', 'fee_plan_approve', 'fee_credit_apply'] as $FO_a) {
    t_ok(str_contains($FO_pt, "ac_form('$FO_a'") || str_contains($FO_pt, "ac_action_btn('$FO_a'"), "the Invoices screen offers $FO_a");
}
t_ok(str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/partials/admin-console/bursaries.php'), "ac_form('bursary_decide'"),
    'the Bursaries screen can decide an application — guardians could apply and nobody could answer');
