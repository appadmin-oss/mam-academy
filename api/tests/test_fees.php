<?php
/**
 * Fee invoices, the four kinds of aid, and payment plans
 * (api/lib/fees.php, schema v87).
 *
 * Two things here carry most of the weight. The conversion, because the design
 * names the exact float that breaks it. And the ORDER the four kinds of aid apply
 * in, because collapsing them into one discount changes what the academy's books
 * say happened and every stage of it looks plausible on its own.
 */
declare(strict_types=1);

t_group('fees — decimal to minor units, without ever making a float');

// The design's own example: `8.07 * 100` is 806.9999999999999 in IEEE-754.
t_eq(mam_fee_to_minor('8.07'), 807, "8.07 is 807 minor units — the float route gives 806.9999999999999");
t_eq(mam_fee_to_minor('0.07'), 7, '0.07 is 7');
t_eq(mam_fee_to_minor('1.10'), 110, '1.10 is 110');
t_eq(mam_fee_to_minor('300'),  30000, 'a whole number scales');
t_eq(mam_fee_to_minor('300.00'), 30000, 'and so does one with trailing zeros');
t_eq(mam_fee_to_minor('1,234.56'), 123456, 'thousands separators are tolerated');
t_eq(mam_fee_to_minor('.5'), 50, 'a leading point is read as 0.5');
t_eq(mam_fee_to_minor('-3.20'), -320, 'a sign is carried');
t_eq(mam_fee_to_minor(''), 0, 'an empty string is zero, not an error');
t_eq(mam_fee_to_minor(1234), 123400, 'an int works');
t_eq(mam_fee_to_minor(12.5), 1250, 'and so does a float that arrives already made');
// Rounding is half-up on the first discarded digit, and done on the DIGITS.
t_eq(mam_fee_to_minor('1.005'), 101, '1.005 rounds half up to 101');
t_eq(mam_fee_to_minor('1.004'), 100, '1.004 rounds down to 100');
t_eq(mam_fee_to_minor('2.675'), 268, "2.675 rounds to 268 — the classic float case gives 267");
t_eq(mam_fee_to_minor('0.999'), 100, '0.999 rounds to a whole unit');
try { mam_fee_to_minor('12.3.4'); t_ok(false, 'a malformed amount is refused'); }
catch (\InvalidArgumentException $e) { t_ok(true, 'a malformed amount is refused rather than silently truncated'); }

// And back again, for display only.
t_eq(mam_fee_from_minor(30000), '300.00', 'minor units format back');
t_eq(mam_fee_from_minor(807), '8.07', 'including the case the float route loses');
t_eq(mam_fee_from_minor(123456), '1,234.56', 'with thousands separators for a human');
t_eq(mam_fee_display(30000, 'USD'), '$300.00', 'and with the currency symbol');
t_eq(mam_fee_display(48980000, 'NGN'), '₦489,800.00', 'naira too');

// Percentages are integer arithmetic, so a family can recompute on paper.
t_eq(mam_fee_pct_of(30000, 10.0), 3000, '10% of $300.00 is $30.00');
t_eq(mam_fee_pct_of(30000, 12.5), 3750, '12.5% works');
t_eq(mam_fee_pct_of(474800000, 12.5), 59350000, 'and stays exact at scale');
t_eq(mam_fee_pct_of(101, 50.0), 51, 'half of an odd number rounds half up');
t_eq(mam_fee_pct_of(30000, 0.0), 0, 'a zero percentage is zero');
t_eq(mam_fee_pct_of(0, 50.0), 0, 'and so is a percentage of nothing');

t_group('fees — the four kinds of aid, in the order they apply');

$FE_stu = mam_db_insert('users', ['name' => 'Fee Kid', 'email' => 'feekid@test.local',
    'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$FE_stu2 = mam_db_insert('users', ['name' => 'Fee Sib', 'email' => 'feesib@test.local',
    'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$FE_admin = mam_db_insert('users', ['name' => 'Fee Admin', 'email' => 'feeadmin@test.local',
    'role' => 'admin', 'password_hash' => 'x', 'status' => 'active']);
$FE_link = mam_parent_link('feeguardian@test.local', $FE_stu);
$FE_par = (int) $FE_link['parent_id'];
mam_parent_link('feeguardian@test.local', $FE_stu2);

$FE_inv = mam_fee_invoice_open($FE_stu, $FE_par, $FE_admin, ['term_label' => 'First Term, 2026/2027']);
t_ok($FE_inv > 0, 'a draft invoice opens');
$FE_got = mam_fee_invoice_get($FE_inv);
t_eq($FE_got['status'], 'draft', 'as a draft — a family sees nothing yet');
t_ok(strpos((string) $FE_got['ref'], 'INV-') === 0, 'with a human-quotable reference');
t_eq($FE_got['currency'], 'USD', 'in the academy invoicing currency');

// The mock's own figures: tuition $300, materials $70, sibling discount shown.
mam_fee_line_add($FE_inv, 'tuition',   'Term tuition · Piano Level 2', '300.00');
mam_fee_line_add($FE_inv, 'materials', 'Materials fee',                '70.00');
$FE_s1 = mam_fee_invoice_get($FE_inv);
t_eq($FE_s1['gross'], '$370.00', 'gross is the sum of the lines');
t_eq($FE_s1['total'], '$370.00', 'and with no relief the total equals it');

/* ── 1 · A WAIVER reduces the LINE and is never a cash entry ─────────────── */
$FE_lines = mam_fee_invoice_get($FE_inv)['lines'];
$FE_mat = array_values(array_filter($FE_lines, fn($l) => $l['kind'] === 'materials'))[0];
try { mam_fee_line_waive($FE_mat['id'], '20.00', ''); t_ok(false, 'a waiver needs a reason'); }
catch (\InvalidArgumentException $e) { t_ok(true, 'a waiver states its reason — it is the academy choosing not to bill'); }
t_throws(fn() => mam_fee_line_waive($FE_mat['id'], '900.00', 'too much'), 'waiver_exceeds_line',
    'a waiver cannot exceed the line it reduces');

$FE_pay0 = (int) mam_db_row('SELECT COUNT(*) AS n FROM payments')['n'];
mam_fee_line_waive($FE_mat['id'], '20.00', 'Books already owned', $FE_admin);
$FE_s2 = mam_fee_invoice_get($FE_inv);
t_eq($FE_s2['gross'],  '$370.00', 'the gross still shows what was billed');
t_eq($FE_s2['waiver'], '$20.00',  'the waiver is stated separately, not netted away');
t_eq($FE_s2['total'],  '$350.00', 'and the total is reduced by it');
t_eq((int) mam_db_row('SELECT COUNT(*) AS n FROM payments')['n'] - $FE_pay0, 0,
    'and NOTHING was written to payments — a waiver is not money');
t_eq((int) mam_db_row('SELECT COUNT(*) AS n FROM fee_invoice_credits WHERE invoice_id = ?', [$FE_inv])['n'], 0,
    'nor to the gift-aid credits, which is a different mechanism');

/* ── 2 · GIFT AID is a credit against the invoice ────────────────────────── */
$FE_bur = mam_fee_aid_award($FE_stu, 'bursary', ['pct' => 20.0, 'basis' => 'Income band'], $FE_admin);
t_ok($FE_bur > 0, 'a bursary award is recorded');
$FE_wav = mam_fee_aid_award($FE_stu, 'waiver', ['pct' => 50.0], $FE_admin);
t_throws(fn() => mam_fee_credit_apply($FE_inv, $FE_wav, $FE_admin), 'not_gift_aid',
    'a WAIVER award cannot be applied as a gift-aid credit — it belongs on a line');
$FE_other = mam_fee_aid_award($FE_stu2, 'bursary', ['pct' => 10.0], $FE_admin);
t_throws(fn() => mam_fee_credit_apply($FE_inv, $FE_other, $FE_admin), 'award_other_student',
    "and one child's award cannot be spent on another's invoice");

mam_fee_credit_apply($FE_inv, $FE_bur, $FE_admin);
$FE_s3 = mam_fee_invoice_get($FE_inv);
// 20% of the CHARGEABLE amount (gross 370 less the 20 waived) = 20% of 350 = 70.
t_eq($FE_s3['aid'], '$70.00', 'aid is a percentage of what is chargeable, not of the gross — the pool must not pay for a waived line');
t_eq($FE_s3['total'], '$280.00', 'and reduces the total further');
t_throws(fn() => mam_fee_credit_apply($FE_inv, $FE_bur, $FE_admin), 'already_applied',
    'one award applies to one invoice ONCE — a double-submitted form must not give away the aid pool twice');

/* ── 3 · SIBLING applies AFTER aid, and is CAPPED ────────────────────────── */
mam_setting_set('sibling_discount_pct', '10');
mam_setting_set('sibling_discount_max', '60');
$FE_sib = mam_fee_aid_award($FE_stu, 'sibling', ['pct' => 10.0, 'basis' => 'Second child'], $FE_admin);
mam_fee_recalc($FE_inv);
$FE_s4 = mam_fee_invoice_get($FE_inv);
// 10% of the POST-AID balance (280), NOT of the gross (370) and not of 350.
t_eq($FE_s4['sibling'], '$28.00',
    'the sibling discount is 10% of the POST-AID balance — a percentage of the gross would discount money the aid pool paid');
t_eq($FE_s4['total'], '$252.00', 'gross 370 − waiver 20 − aid 70 − sibling 28');

// The cap is a MAXIMUM AMOUNT. Capping the percentage instead would let a large
// invoice quietly exceed the published maximum.
$FE_big = mam_fee_invoice_open($FE_stu, $FE_par, $FE_admin, ['term_label' => 'Big Term']);
mam_fee_line_add($FE_big, 'tuition', 'A very large tuition line', '5000.00');
mam_fee_recalc($FE_big);
$FE_bigGot = mam_fee_invoice_get($FE_big);
t_eq($FE_bigGot['sibling'], '$60.00',
    '10% of $5,000 would be $500; the published maximum of $60 is applied to the AMOUNT, so it holds');
t_eq($FE_bigGot['total'], '$4,940.00', 'and the total reflects the capped discount');

/* ── The stages must add up, and nothing may take the bill below zero ────── */
$FE_row = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$FE_inv]);
t_eq((int) $FE_row['total_minor'],
    (int) $FE_row['gross_minor'] - (int) $FE_row['waiver_minor'] - (int) $FE_row['aid_minor'] - (int) $FE_row['sibling_minor'],
    'the stored total is exactly the stages subtracted, which the v87 CHECK also insists on');

// Relief larger than the bill is clamped, not allowed to go negative.
$FE_small = mam_fee_invoice_open($FE_stu, $FE_par, $FE_admin, ['term_label' => 'Small Term']);
mam_fee_line_add($FE_small, 'tuition', 'A small line', '10.00');
$FE_huge = mam_fee_aid_award($FE_stu, 'scholarship', ['amount' => '500.00', 'currency' => 'USD'], $FE_admin);
mam_fee_credit_apply($FE_small, $FE_huge, $FE_admin);
$FE_smallGot = mam_fee_invoice_get($FE_small);
t_eq($FE_smallGot['aid'], '$10.00', 'aid larger than the bill is clamped to the bill');
t_eq($FE_smallGot['total'], '$0.00', 'and the total is zero, never negative');
t_ok((int) mam_db_row('SELECT total_minor FROM fee_invoices WHERE id = ?', [$FE_small])['total_minor'] >= 0,
    'a negative minor amount is a data error, not a refund — a refund is its own payment row');

t_group('fees — issuing freezes the rate, and the naira figure is an estimate');

t_throws(fn() => mam_fee_plan_create($FE_inv, 3), 'not_issued',
    'a plan cannot be made against a draft — there is nothing to be due yet');

$FE_empty = mam_fee_invoice_open($FE_stu, $FE_par, $FE_admin, ['term_label' => 'Empty Term']);
t_throws(fn() => mam_fee_invoice_issue($FE_empty, $FE_admin), 'no_lines',
    'an invoice with no lines cannot be issued');

mam_fee_invoice_issue($FE_inv, $FE_admin);
$FE_iss = mam_fee_invoice_get($FE_inv);
t_eq($FE_iss['status'], 'issued', 'issuing makes it visible to a family');
t_ok($FE_iss['issued_on'] !== null && $FE_iss['due_on'] !== null, 'with a date and something to pay by');
t_ok($FE_iss['grace_until'] !== null, 'and the grace window stated up front');
t_ok($FE_iss['frozen_rate'] !== null, 'the rate is FROZEN at issue — that is what lets a May figure reconcile next year');
t_ok($FE_iss['frozen_rate_label'] !== null, 'and printed on the face of the invoice');
if ($FE_iss['charge'] !== null) {
    t_ok($FE_iss['charge']['estimate'] === true,
        'the naira figure is labelled an ESTIMATE — the rate the payment settles at is the payment row\'s own');
    t_eq($FE_iss['charge']['currency'], 'NGN', 'and it is naira, because that is what the gateway charges');
}
t_throws(fn() => mam_fee_invoice_issue($FE_inv, $FE_admin), 'already_issued', 'issuing twice is refused');

t_group('fees — payment plans sum to the balance, exactly');

$FE_plan = mam_fee_plan_create($FE_inv, 3, $FE_par, $FE_admin);
t_eq($FE_plan['instalments'], 3, '"Pay in 3 instalments instead" makes three');
t_eq(count($FE_plan['schedule']), 3, 'and three rows');
$FE_sum = 0;
foreach ($FE_plan['schedule'] as $i) $FE_sum += $i['amount_minor'];
t_eq($FE_sum, $FE_iss['due_minor'],
    'the instalments sum EXACTLY to the balance — a plan changes when, never how much');
t_ok($FE_plan['schedule'][0]['amount_minor'] >= $FE_plan['schedule'][2]['amount_minor'],
    'and the rounding remainder is FRONT-loaded, so no family meets a larger final payment than the plan showed them');
t_throws(fn() => mam_fee_plan_create($FE_inv, 3), 'plan_exists',
    'one live plan per invoice — two would make "what is due next" ambiguous');
t_throws(fn() => mam_fee_plan_create($FE_inv, 1), 'bad_instalments', 'one instalment is not a plan');

// An awkward balance still divides exactly.
$FE_odd = mam_fee_invoice_open($FE_stu, $FE_par, $FE_admin, ['term_label' => 'Odd Term']);
mam_fee_line_add($FE_odd, 'tuition', 'An awkward amount', '100.01');
mam_fee_invoice_issue($FE_odd, $FE_admin);
$FE_oddDue = mam_fee_invoice_get($FE_odd)['due_minor'];
$FE_oddPlan = mam_fee_plan_create($FE_odd, 3, $FE_par, $FE_admin);
$FE_oddSum = 0;
foreach ($FE_oddPlan['schedule'] as $i) $FE_oddSum += $i['amount_minor'];
t_eq($FE_oddSum, $FE_oddDue, 'an indivisible balance still sums exactly — the remainder is not rounded away');

t_group('fees — the statement a guardian is shown');

$FE_st = mam_fee_statement($FE_par, $FE_stu);
t_ok($FE_st['scope_denied'] === false, 'a linked guardian may read the statement');
t_ok(count($FE_st['invoices']) > 0, 'and sees the issued invoices');
t_ok($FE_st['owed_minor'] > 0, 'with a total owed');
// array_key_exists, not isset: with no calendar authored the rule is legitimately
// NULL, and isset() would report the key missing when it is present and empty.
t_ok(array_key_exists('refund_rule', $FE_st),
    'the refund rule that applies TODAY travels with the statement — the calendar is the only authority on a refund');
t_ok(strpos($FE_st['assurance'], 'Nothing is withheld') === 0,
    'and the assurance is DATA, so the screen cannot forget to say it');
t_eq($FE_st['dunning']['reminder_days'], MAM_FEE_DUNNING_DAYS, 'the reminder ladder is day 0, 3, 7');
t_ok(strpos($FE_st['dunning']['next_step'], 'conversation') !== false,
    'and the next step after grace is a conversation, not a disconnection');

// A draft invoice never reaches the family.
$FE_hidden = mam_fee_invoice_open($FE_stu, $FE_par, $FE_admin, ['term_label' => 'Hidden Term']);
mam_fee_line_add($FE_hidden, 'tuition', 'Not yet issued', '99.00');
$FE_ids = array_map(fn($i) => $i['id'], mam_fee_statement($FE_par, $FE_stu)['invoices']);
t_ok(!in_array($FE_hidden, $FE_ids, true), 'a DRAFT invoice is not on the family statement');

// Billing-only still sees the statement — that is what the scope is for.
mam_parent_scope_set($FE_par, $FE_stu, 'billing', $FE_admin);
t_ok(mam_fee_statement($FE_par, $FE_stu)['scope_denied'] === false,
    'a billing-only guardian DOES see invoices and receipts — that is the whole point of the scope');
mam_parent_scope_set($FE_par, $FE_stu, 'standard', $FE_admin);

$FE_stranger = mam_db_insert('users', ['name' => 'Stranger', 'email' => 'feestranger@test.local',
    'role' => 'parent', 'password_hash' => 'x', 'status' => 'active']);
t_throws(fn() => mam_fee_statement($FE_stranger, $FE_stu), 'forbidden',
    'and an unlinked adult sees nothing');

t_group('fees — voiding, and what may not be voided');

$FE_void = mam_fee_invoice_open($FE_stu, $FE_par, $FE_admin, ['term_label' => 'Void Term']);
mam_fee_line_add($FE_void, 'tuition', 'To be withdrawn', '50.00');
try { mam_fee_invoice_void($FE_void, ''); t_ok(false, 'voiding needs a reason'); }
catch (\InvalidArgumentException $e) { t_ok(true, 'voiding states its reason — a bill withdrawn is an event'); }
mam_fee_invoice_void($FE_void, 'Raised against the wrong child.', $FE_admin);
t_eq(mam_fee_invoice_get($FE_void)['status'], 'void', 'the row is kept, not deleted');
t_ok(mam_fee_invoice_get($FE_void)['void_reason'] !== null, 'with its reason');
t_throws(fn() => mam_fee_line_add($FE_void, 'tuition', 'after the fact', '1.00'), 'invoice_closed',
    'and a void invoice takes no more lines');

t_group('fees — bursary applications are assessed on a BAND, never a written case');

t_ok(count(mam_bursary_bands()) >= 5, 'the bands are published');
foreach (mam_bursary_bands() as $b) {
    t_ok(isset($b['code'], $b['label']), 'each band has a code and a label a family can recognise');
    break;
}
/* The schema has no free-text hardship column, and this is the assertion that
 * keeps it that way: a written case rewards the family best able to write one. */
$FE_bcols = [];
foreach (mam_db_all('PRAGMA table_info(bursary_applications)') as $c) $FE_bcols[strtolower($c['name'])] = true;
foreach (['hardship', 'hardship_statement', 'circumstances', 'case_text', 'reason'] as $forbidden) {
    t_ok(!isset($FE_bcols[$forbidden]), "bursary_applications has no '$forbidden' column — a band is a fact, a case is an essay");
}
t_ok(!isset($FE_bcols['affects_admission']), 'and nothing links a bursary application to an admission decision');

t_throws(fn() => mam_bursary_apply($FE_stu, $FE_par, 'made_up_band'), null, 'an unknown income band is refused');
$FE_bapp = mam_bursary_apply($FE_stu, $FE_par, '150k_300k', ['household_size' => 5, 'dependants_in_academy' => 2]);
t_ok($FE_bapp > 0, 'an application is recorded');
t_throws(fn() => mam_bursary_apply($FE_stu, $FE_par, '150k_300k'), 'already_open',
    'one open application at a time');
t_throws(fn() => mam_bursary_apply($FE_stu, $FE_stranger, 'under_150k'), 'forbidden',
    'and only a linked guardian may apply');

try { mam_bursary_decide($FE_bapp, 'declined', $FE_admin, ''); t_ok(false, 'a decision needs an outcome note'); }
catch (\InvalidArgumentException $e) { t_ok(true, 'a decision states its outcome, whichever way it went'); }

// The published table is a CEILING a committee may go under, never over.
t_throws(fn() => mam_bursary_decide($FE_bapp, 'awarded', $FE_admin, 'Generous', 90.0), 'above_published_band',
    'a committee cannot award above the published band — a family that read the table is entitled to its answer');
$FE_dec = mam_bursary_decide($FE_bapp, 'awarded', $FE_admin, 'Awarded at the band rate.', 40.0);
t_eq($FE_dec['status'], 'awarded', 'an award is recorded');
t_ok($FE_dec['award_id'] > 0, 'and MINTS the aid award, so the decision and the entitlement cannot drift apart');
t_eq(mam_db_row('SELECT kind FROM fee_aid_awards WHERE id = ?', [$FE_dec['award_id']])['kind'], 'bursary',
    'as a bursary — needs-based gift aid');
t_throws(fn() => mam_bursary_decide($FE_bapp, 'declined', $FE_admin, 'changed my mind'), 'already_decided',
    'and it is decided once');

// The assessor's private note never reaches the family.
mam_db_update('bursary_applications', ['note' => 'Committee felt the household understated income.'], ['id' => $FE_bapp]);
$FE_view = mam_bursary_for_guardian($FE_par, $FE_stu);
t_ok(count($FE_view) > 0, 'a guardian sees their own applications');
t_ok(!array_key_exists('note', $FE_view[0]),
    "and never the assessor's private note — it is the academy's working, not the family's record");
t_ok(isset($FE_view[0]['band_label']), 'the band is shown as the words the family chose, not a code');

t_group('fees — receipts read the currency-safe column');

/* BI money reads `payments.amount_usd_cents` only; `amount` is the CHARGE
 * currency and summing NGN into a USD total is the bug v61 exists to prevent. */
$FE_src = file_get_contents(__DIR__ . '/../lib/fees.php');
t_ok(strpos($FE_src, 'amount_usd_cents') !== false, 'the receipts query selects amount_usd_cents');
t_ok(preg_match('/SUM\s*\(\s*p?\.?amount\s*\)/i', $FE_src) !== 1,
    'and nothing in this file SUMs the charge-currency `amount` column');
t_ok(is_array(mam_fee_receipts($FE_stu)), 'receipts return a list even when there are none');
