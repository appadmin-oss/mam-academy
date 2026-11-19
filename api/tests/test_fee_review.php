<?php
/**
 * Invoice review, family queries and discount codes (api/lib/fee-review.php, v100).
 */
declare(strict_types=1);

$FR_now = gmdate('YmdHis') . random_int(100, 999);
$FR_stu = (int) mam_db_insert('users', ['name' => 'Review Kid', 'email' => "fr.kid.$FR_now@test.local", 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$FR_a1 = (int) mam_db_insert('users', ['name' => 'Drafter', 'email' => "fr.a1.$FR_now@test.local", 'role' => 'admin', 'password_hash' => 'x', 'status' => 'active']);
$FR_a2 = (int) mam_db_insert('users', ['name' => 'Checker', 'email' => "fr.a2.$FR_now@test.local", 'role' => 'admin', 'password_hash' => 'x', 'status' => 'active']);
$FR_par = (int) mam_parent_link("fr.parent.$FR_now@test.local", $FR_stu)['parent_id'];
$FR_row = static fn(int $id) => mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$id]);
$FR_code = static function (callable $fn): string { try { $fn(); return 'no error'; } catch (Throwable $e) { return $e->getMessage(); } };

t_group('fee review — a draft is reviewed by somebody else before it is sent');

$FR_inv = mam_fee_invoice_open($FR_stu, null, $FR_a1, ['term_label' => 'Review Term ' . $FR_now]);
t_eq($FR_code(fn() => mam_fee_invoice_review($FR_inv, $FR_a2)), 'review_blocked', 'a draft with no lines cannot be signed off');
mam_fee_line_add($FR_inv, 'tuition', 'Violin, Grade 2', '200.00', 1);
mam_fee_line_add($FR_inv, 'materials', 'Rosin and strings', '40.00', 1);
t_eq($FR_code(fn() => mam_fee_issue_reviewed($FR_inv, $FR_a1)), 'not_reviewed', 'the office cannot issue an unreviewed draft');
t_eq($FR_code(fn() => mam_fee_invoice_review($FR_inv, $FR_a1)), 'review_own_draft', 'whoever drafted it does not review it');
mam_fee_invoice_review($FR_inv, $FR_a2, 'Checked against the price book');
t_ok(mam_fee_is_reviewed($FR_row($FR_inv)), 'a second person signs it off');
mam_fee_line_add($FR_inv, 'exam', 'Exam entry', '30.00', 1);
t_ok(!mam_fee_is_reviewed($FR_row($FR_inv)), 'changing a line after sign-off means it is no longer reviewed');
t_eq($FR_code(fn() => mam_fee_issue_reviewed($FR_inv, $FR_a1)), 'not_reviewed', '…and it cannot be issued until reviewed again');
mam_fee_invoice_review($FR_inv, $FR_a2);
mam_fee_issue_reviewed($FR_inv, $FR_a1, '2027-02-01');
t_eq($FR_row($FR_inv)['status'], 'issued', 'reviewed again, it is issued');

$FR_dup = mam_fee_invoice_open($FR_stu, null, $FR_a1, ['term_label' => 'Review Term ' . $FR_now]);
mam_fee_line_add($FR_dup, 'tuition', 'Again', '1.00', 1);
t_ok((bool) array_filter(mam_fee_review_checks($FR_dup), fn($c) => str_contains($c['text'], 'already')), 'a second invoice for the same child and term is flagged for the reviewer');

t_group('fee review — a family queries an invoice');

t_throws(fn() => mam_fee_query_raise($FR_par, $FR_inv, ['kind' => 'amount', 'message' => 'We cannot pay all of this', 'amount' => '500']), null,
    'proposing a total above what is owed is refused');
$FR_q = mam_fee_query_raise($FR_par, $FR_inv, ['kind' => 'amount', 'message' => 'We were told materials were included this term.', 'amount' => '230']);
t_ok(mam_fee_query_open($FR_inv), 'a query is open');
t_throws(fn() => mam_fee_query_raise($FR_par, $FR_inv, ['kind' => 'question', 'message' => 'And another thing']), null, 'one open query per invoice');
$FR_stranger = (int) mam_parent_link("fr.other.$FR_now@test.local", (int) mam_db_insert('users', ['name' => 'Other', 'email' => "fr.o.$FR_now@test.local", 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']))['parent_id'];
t_eq($FR_code(fn() => mam_fee_query_raise($FR_stranger, $FR_inv, ['kind' => 'question', 'message' => 'Whose is this?'])), 'not_found', 'another family cannot query it');

t_eq($FR_code(fn() => mam_fee_query_decide($FR_q, $FR_a2, 'accept', '')), 'reply_required', 'the office must say something the family reads');
mam_fee_query_decide($FR_q, $FR_a2, 'counter', 'Materials were included for returning students; we can do 250.', ['amount' => '250']);
t_ok(mam_fee_query_open($FR_inv), 'a counter-offer keeps it open, waiting for the family');
mam_fee_query_respond($FR_par, $FR_q, true);
$FR_r = $FR_row($FR_inv);
t_eq((int) $FR_r['total_minor'], 25000, 'accepting the counter brings the total to what was agreed');
t_ok((bool) mam_db_row("SELECT 1 FROM fee_invoice_lines WHERE invoice_id = ? AND waiver_reason LIKE ?", [$FR_inv, '%query #' . $FR_q . '%']),
    'as a waiver whose reason names the query — the invoice still shows what was charged');
t_ok(!mam_fee_query_open($FR_inv), 'and the query is settled');

$FR_q2 = mam_fee_query_raise($FR_par, $FR_inv, ['kind' => 'plan', 'message' => 'Could we pay monthly?', 'instalments' => 3]);
mam_fee_query_decide($FR_q2, $FR_a2, 'accept', 'Yes — three monthly payments.');
$FR_plan = mam_fee_plan_for($FR_inv);
t_ok($FR_plan && (int) $FR_plan['instalments'] === 3, 'agreeing instalments opens the plan');
t_ok($FR_plan && $FR_plan['approved'] === true, '…already approved, because the office just agreed it');

t_group('fee review — reminders pause while a family is asking');

$FR_inv2 = mam_fee_invoice_open($FR_stu, null, $FR_a1, ['term_label' => 'Paused ' . $FR_now]);
mam_fee_line_add($FR_inv2, 'tuition', 'Cello', '100.00', 1);
mam_fee_invoice_review($FR_inv2, $FR_a2);
mam_fee_issue_reviewed($FR_inv2, $FR_a1, '2020-01-01');
mam_db_update('users', ['email' => "fr.parent.$FR_now@test.local"], ['id' => $FR_par]);
mam_fee_query_raise($FR_par, $FR_inv2, ['kind' => 'question', 'message' => 'Is this the right child?']);
mam_fee_dunning_sweep(500);
t_eq((int) $FR_row($FR_inv2)['dunning_stage'], 0, 'an overdue invoice with an open question is not chased');

t_group('fee review — discount codes');

t_throws(fn() => mam_fee_code_save(['code' => 'SPRING', 'label' => 'Spring', 'pct' => '10', 'amount' => '5'], $FR_a1), null, 'a percentage OR an amount, not both');
t_throws(fn() => mam_fee_code_save(['code' => 'SPRING', 'label' => '', 'pct' => '10'], $FR_a1), null, 'a code needs a reason');
mam_fee_code_save(['code' => 'early-bird ' . substr($FR_now, -4), 'label' => 'Early enrolment', 'pct' => '10', 'max_uses' => 5], $FR_a1);
$FR_cd = 'EARLYBIRD' . substr($FR_now, -4);
t_ok((bool) mam_db_row('SELECT 1 FROM fee_discount_codes WHERE code = ?', [$FR_cd]), 'codes are stored without spaces or punctuation, in capitals');
$FR_inv3 = mam_fee_invoice_open($FR_stu, null, $FR_a1, ['term_label' => 'Code ' . $FR_now]);
mam_fee_line_add($FR_inv3, 'tuition', 'Piano', '300.00', 1);
mam_fee_line_add($FR_inv3, 'materials', 'Book', '50.00', 1);
mam_fee_invoice_review($FR_inv3, $FR_a2);
mam_fee_issue_reviewed($FR_inv3, $FR_a1, '2027-03-01');
$FR_off = mam_fee_code_apply($FR_inv3, strtolower($FR_cd), null, $FR_par);
t_eq($FR_off, 3500, 'ten per cent of 350 is 35 off');
t_eq((int) $FR_row($FR_inv3)['total_minor'], 31500, 'the total moves');
t_ok((bool) mam_db_row("SELECT 1 FROM fee_invoice_lines WHERE invoice_id = ? AND waiver_reason LIKE 'Code %'", [$FR_inv3]), 'recorded as a waiver with the code as its reason');
t_throws(fn() => mam_fee_code_apply($FR_inv3, $FR_cd, null, $FR_par), null, 'the same code twice on one invoice is refused');
$FR_inv4 = mam_fee_invoice_open($FR_stu, null, $FR_a1, ['term_label' => 'Code two ' . $FR_now]);
mam_fee_line_add($FR_inv4, 'tuition', 'Piano', '100.00', 1);
t_throws(fn() => mam_fee_code_apply($FR_inv4, $FR_cd, null, $FR_par), null, 'once per family by default');
t_throws(fn() => mam_fee_code_apply($FR_inv4, 'NOSUCHCODE', $FR_a1), null, 'an unknown code is refused in words');
t_ok(str_contains(file_get_contents(__DIR__ . '/../../admin/index.php'), "'fee_invoice_review' => 'fees'"), 'the office actions sit behind the fees capability');
