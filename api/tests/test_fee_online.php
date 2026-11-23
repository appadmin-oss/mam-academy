<?php
/**
 * A guardian pays a fee invoice online (fees.php mam_fee_online_*, schema v92).
 *
 * The Pay button said "the office will send you a link" because nothing could
 * take the money. These pin the charge (who may open one, how much, at what
 * rate), the settlement (it lands as an ordinary fee payment, once, crediting
 * the invoice's own currency and never more than is owed) and the console
 * money figures that read a status nothing wrote.
 */
declare(strict_types=1);

t_group('fee online — who may open a charge, and for how much');

$FN_stu   = mam_db_insert('users', ['name' => 'Online Kid', 'email' => 'onlinekid@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$FN_admin = mam_db_insert('users', ['name' => 'Online Admin', 'email' => 'onlineadmin@test.local', 'role' => 'admin', 'password_hash' => 'x', 'status' => 'active']);
$FN_par   = (int) mam_parent_link('onlineguardian@test.local', $FN_stu)['parent_id'];
$FN_other = (int) mam_parent_link('strangerguardian@test.local', mam_db_insert('users', ['name' => 'Other Kid', 'email' => 'onlineotherkid@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']))['parent_id'];

$FN_inv = mam_fee_invoice_open($FN_stu, $FN_par, $FN_admin, ['term_label' => 'Online Spring', 'currency' => 'NGN']);
mam_fee_line_add($FN_inv, 'tuition', 'Violin, Grade 2', '150000.50', 1);
$FN_refuse = static function (callable $fn): string {
    try { $fn(); return 'none'; } catch (\Throwable $e) { return $e->getMessage(); }
};
t_eq($FN_refuse(fn() => mam_fee_online_start($FN_par, $FN_inv, null)), 'not_payable', 'a draft cannot be paid online — nothing is owed until it is issued');
mam_fee_invoice_issue($FN_inv, $FN_admin, '2027-02-01');
t_eq($FN_refuse(fn() => mam_fee_online_start($FN_other, $FN_inv, null)), 'forbidden', 'a guardian of a different child cannot open a charge on this invoice');
t_eq($FN_refuse(fn() => mam_fee_online_start($FN_par, $FN_inv, 15000051)), 'more_than_due', 'nor can anybody charge more than is owed');
t_eq($FN_refuse(fn() => mam_fee_online_start($FN_par, $FN_inv, 0)), 'bad_amount', 'or nothing');

$FN_p = mam_fee_online_start($FN_par, $FN_inv, null);
t_eq($FN_p['purpose'], 'fee', 'the charge is an ordinary payments row, purpose fee, so verify and both webhooks settle it');
t_eq((int) $FN_p['amount_minor'], 15000050, 'a naira invoice is charged to the KOBO — ₦150,000.50, not rounded to the naira');
t_eq((int) $FN_p['fee_minor'], 15000050, 'and remembers how much of the invoice it is for');
t_eq((int) $FN_p['fee_payer_id'], $FN_par, 'and who pressed Pay');
t_ok($FN_p['application_id'] === null, 'with no application — a fee charge is not an applicant\'s');
t_ok($FN_p['reference'] !== mam_fee_online_start($FN_par, $FN_inv, null)['reference'], 'each press is a fresh reference, because a gateway refuses one it has seen');

t_group('fee online — a dollar invoice is charged at today\'s rate and credited in dollars');

$FN_usd = mam_fee_invoice_open($FN_stu, $FN_par, $FN_admin, ['term_label' => 'Online USD', 'currency' => 'USD']);
mam_fee_line_add($FN_usd, 'tuition', 'Theory', '80.00', 1);
mam_fee_invoice_issue($FN_usd, $FN_admin, '2027-02-01');
$FN_rate = (float) mam_usd_to_ngn_rate(false);
$FN_pu = mam_fee_online_start($FN_par, $FN_usd, null);
t_eq((int) $FN_pu['amount_minor'], mam_money_ngn_from_usd_cents(8000, $FN_rate) * 100, 'the naira charge is $80 at the same rate the statement\'s "you will be charged" sentence uses');
t_eq((int) $FN_pu['fee_minor'], 8000, 'but what it pays off is 8000 cents of the invoice, not whatever the naira came to');
t_eq((int) $FN_pu['amount_usd_cents'], 8000, 'and revenue counts it as $80');

t_group('fee online — settlement credits the invoice once, as a fee payment the office can see');

$FN_row = static fn(int $id) => mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$id]);
$FN_settle = static function (array $p, string $channel = 'card'): array {
    $p = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$p['reference']]);
    return mam_payment_settle($p, ['channel' => $channel, 'reference' => $p['reference']], '{}', $p['reference']);
};
$FN_mail0 = (int) (mam_db_row("SELECT COUNT(*) n FROM mail_log WHERE context = 'fee_receipt'")['n'] ?? 0);
$FN_s = $FN_settle($FN_pu, 'bank_transfer');
t_ok($FN_s['ok'] && !$FN_s['already'], 'the shared settlement path accepts a fee charge');
t_eq($FN_row($FN_usd)['status'], 'paid', 'and the invoice is paid');
$FN_fp = mam_db_row('SELECT * FROM fee_payments WHERE invoice_id = ? AND reference = ?', [$FN_usd, $FN_pu['reference']]);
t_ok($FN_fp && (int) $FN_fp['amount_minor'] === 8000 && $FN_fp['currency'] === 'USD', 'as a fee payment of $80.00 in the invoice\'s own currency');
t_eq($FN_fp['method'] ?? '', 'transfer', 'recorded as a transfer, because that is how the gateway says it was paid');
t_ok(str_contains((string) ($FN_fp['note'] ?? ''), 'Paid online'), 'and labelled as paid online');
t_ok($FN_settle($FN_pu)['already'] === true, 'a second settlement (the webhook after the verify) does nothing');
t_eq((int) mam_db_row('SELECT COUNT(*) n FROM fee_payments WHERE reference = ?', [$FN_pu['reference']])['n'], 1, 'so it is credited once');
$FN_mail = mam_db_row("SELECT to_email, subject FROM mail_log WHERE context = 'fee_receipt' ORDER BY id DESC LIMIT 1");
t_eq((int) (mam_db_row("SELECT COUNT(*) n FROM mail_log WHERE context = 'fee_receipt'")['n'] ?? 0) - $FN_mail0, 1, 'one receipt goes out');
t_eq($FN_mail['to_email'] ?? '', 'onlineguardian@test.local', 'to the guardian who paid');

t_group('fee online — money that clears after the balance was settled is kept for a refund, not lost');

$FN_part = mam_fee_online_start($FN_par, $FN_inv, 10000000);          // ₦100,000 of ₦150,000.50
mam_fee_payment_record($FN_inv, '120000', 'cash', $FN_admin);           // meanwhile the office takes ₦120,000 in cash
$FN_settle($FN_part);
t_eq((int) $FN_row($FN_inv)['paid_minor'], 15000050, 'only the ₦30,000.50 still owed is credited — the balance never goes negative');
t_eq($FN_row($FN_inv)['status'], 'paid', 'and the invoice is paid');
$FN_cons = mam_console_invoices(['id' => $FN_inv]);
$FN_on = array_values(array_filter($FN_cons['detail']['online'] ?? [], fn($o) => $o['reference'] === $FN_part['reference']));
t_eq((int) ($FN_on[0]['refund_minor'] ?? -1), 6999950, 'and the console shows the ₦69,999.50 the office owes back, on the invoice');
$FN_note = (string) (mam_db_row('SELECT note FROM fee_payments WHERE reference = ?', [$FN_part['reference']])['note'] ?? '');
t_ok(str_contains($FN_note, 'refund it'), 'and the payment\'s own note says how much more was taken and to refund it');

t_group('fee online — the gateway amount is compared in whole kobo');

t_ok(mam_payment_amount_ok(['amount' => '1234.56', 'amount_minor' => 123456], 123456, 'NGN'), 'an exact charge passes — 1234.56 × 100 is a float that can land above 123456');
t_ok(!mam_payment_amount_ok(['amount' => '1234.56', 'amount_minor' => 123456], 123455, 'NGN'), 'a kobo short fails');
t_ok(!mam_payment_amount_ok(['amount' => '1234.56', 'amount_minor' => 123456], 123456, 'USD'), 'and so does another currency');
t_ok(mam_payment_amount_ok(['amount' => '5000', 'amount_minor' => null], 500000, 'NGN'), 'a row from before v61 falls back to its naira figure');
// Paystack reaches it through mam_paystack_apply_tx() → mam_paystack_tx_check()
// (payment-guards.php), the one decision verify, the webhook and the sweep share.
$FN_guards = (string) file_get_contents(dirname(__DIR__) . '/lib/payment-guards.php');
t_ok(str_contains($FN_guards, 'mam_payment_amount_ok('), 'the shared Paystack check uses the one comparison');
foreach (['payment-verify.php', 'webhook-paystack.php', 'webhook-flutterwave.php'] as $FN_f) {
    $FN_s = (string) file_get_contents(dirname(__DIR__) . '/routes/' . $FN_f);
    t_ok(str_contains($FN_s, 'mam_payment_amount_ok(') || str_contains($FN_s, 'mam_paystack_apply_tx('), "$FN_f uses the one comparison");
}

t_group('fee online — the console counts money that was actually settled');

$FN_csrc = (string) file_get_contents(dirname(__DIR__) . '/lib/console.php');
t_ok(!preg_match("/status\\s*=\\s*'success'/", $FN_csrc), 'no console query asks for status \'success\', which nothing writes — Collected read zero');
$FN_i3 = mam_fee_invoice_open($FN_stu, $FN_par, $FN_admin, ['term_label' => 'Online Count', 'currency' => 'USD']);
mam_fee_line_add($FN_i3, 'tuition', 'Aural', '50.00', 1);
mam_fee_invoice_issue($FN_i3, $FN_admin, '2027-03-01');
$FN_p3 = mam_fee_online_start($FN_par, $FN_i3, null);
$FN_before = mam_console_collected_cents();
$FN_settle($FN_p3);
t_eq(mam_console_collected_cents() - $FN_before, 5000, 'Collected moves by the $50 that cleared');
t_ok(mam_db_row('SELECT 1 FROM payments WHERE reference = ? AND ' . MAM_CONSOLE_SUSPENSE, [$FN_p3['reference']]) === null,
    'and a fee payment is not "unmatched" just because it has no application — nor is a store order');

t_group('fee online — a settled naira family is told it owes nothing in naira');
$FN_st = mam_fee_statement($FN_par, $FN_stu);
t_eq($FN_st['currency'], 'USD', 'the newest invoice here is the dollar one, so the statement is in dollars');
$FN_ng = mam_db_insert('users', ['name' => 'Naira Kid', 'email' => 'nairakid@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$FN_ngp = (int) mam_parent_link('nairaguardian@test.local', $FN_ng)['parent_id'];
$FN_ni = mam_fee_invoice_open($FN_ng, $FN_ngp, $FN_admin, ['term_label' => 'Naira only', 'currency' => 'NGN']);
mam_fee_line_add($FN_ni, 'tuition', 'Drums', '1000', 1);
mam_fee_invoice_issue($FN_ni, $FN_admin, '2027-02-01');
mam_fee_payment_record($FN_ni, '1000', 'cash', $FN_admin);
t_eq(mam_fee_statement($FN_ngp, $FN_ng)['owed'], '₦0.00', 'with everything paid, a naira family reads ₦0.00, not $0.00');
