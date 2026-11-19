<?php
/**
 * A guardian reports a bank transfer; the office confirms it against the
 * statement; online money taken beyond the balance is resolved by a person
 * (fees.php, schema v93, HANDOFF §4v). The rules are NGG's offline-payment
 * rules, ported: evidence by its bytes and once ever, a reference once ever,
 * a rejected reference does not come back, confirming means retyping, and a
 * confirmation that cannot be credited is undone.
 */
declare(strict_types=1);

$FT_png = static function (int $w = 320, int $h = 320, int $seed = 0): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 200 + $seed % 50, 120, 40));
    imagestring($im, 5, 10, 10, 'slip ' . $seed, imagecolorallocate($im, 0, 0, 0));
    ob_start(); imagepng($im); $b = (string) ob_get_clean(); imagedestroy($im);
    return 'data:image/png;base64,' . base64_encode($b);
};
$FT_err = static function (callable $fn): string { try { $fn(); return 'none'; } catch (\Throwable $e) { return $e->getMessage(); } };

$FT_stu   = mam_db_insert('users', ['name' => 'Transfer Kid', 'email' => 'transferkid@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$FT_admin = mam_db_insert('users', ['name' => 'Transfer Office', 'email' => 'transferoffice@test.local', 'role' => 'admin', 'password_hash' => 'x', 'status' => 'active']);
$FT_par   = (int) mam_parent_link('transferguardian@test.local', $FT_stu)['parent_id'];
$FT_stranger = (int) mam_parent_link('transferstranger@test.local',
    mam_db_insert('users', ['name' => 'Elsewhere Kid', 'email' => 'elsewherekid@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']))['parent_id'];

$FT_inv = mam_fee_invoice_open($FT_stu, $FT_par, $FT_admin, ['term_label' => 'Transfer Spring', 'currency' => 'NGN']);
mam_fee_line_add($FT_inv, 'tuition', 'Guitar, Grade 1', '120000', 1);
mam_fee_invoice_issue($FT_inv, $FT_admin, '2027-02-01');
$FT_issued = (string) mam_db_row('SELECT issued_on FROM fee_invoices WHERE id = ?', [$FT_inv])['issued_on'];
$FT_ok = ['amount' => '120000', 'paid_on' => $FT_issued, 'reference' => 'GTB-000771-AB', 'bank_name' => 'GTBank', 'payer_name' => 'Ada Transfer'];

t_group('fee transfer — what a guardian may report');

t_eq($FT_err(fn() => mam_fee_transfer_claim($FT_stranger, $FT_inv, $FT_ok + ['evidence' => $FT_png()])), 'forbidden', 'a guardian of another child cannot report a payment on this invoice');
t_eq($FT_err(fn() => mam_fee_transfer_claim($FT_par, $FT_inv, $FT_ok + ['evidence' => 'data:image/png;base64,' . base64_encode('<?php echo 1;')])), 'evidence_type',
    'the BYTES decide the type: a script labelled image/png is refused');
t_eq($FT_err(fn() => mam_fee_transfer_claim($FT_par, $FT_inv, $FT_ok + ['evidence' => $FT_png(90, 90)])), 'evidence_too_small', 'a picture too small to read is refused');
t_eq($FT_err(fn() => mam_fee_transfer_claim($FT_par, $FT_inv, ['reference' => 'AB12'] + $FT_ok + ['evidence' => $FT_png()])), 'reference_required', 'a reference too short to look up is refused');
t_eq($FT_err(fn() => mam_fee_transfer_claim($FT_par, $FT_inv, ['amount' => '120000.01'] + $FT_ok + ['evidence' => $FT_png()])), 'more_than_due', 'more than is owed is refused');
t_eq($FT_err(fn() => mam_fee_transfer_claim($FT_par, $FT_inv, ['paid_on' => '2099-01-01'] + $FT_ok + ['evidence' => $FT_png()])), 'date_in_future', 'a date that has not happened is refused');
t_eq($FT_err(fn() => mam_fee_transfer_claim($FT_par, $FT_inv, ['paid_on' => date('Y-m-d', strtotime($FT_issued . ' -30 days'))] + $FT_ok + ['evidence' => $FT_png()])),
    'date_before_invoice', 'and so is a transfer from before the invoice existed');

$FT_slip = $FT_png(320, 320, 1);
$FT_c1 = mam_fee_transfer_claim($FT_par, $FT_inv, ['amount' => '50000'] + $FT_ok + ['evidence' => $FT_slip]);
$FT_row = static fn(int $id) => mam_db_row('SELECT * FROM fee_transfer_claims WHERE id = ?', [$id]);
t_eq($FT_row($FT_c1)['status'], 'pending', 'a report is a CLAIM, waiting to be checked');
t_eq((int) mam_db_row('SELECT paid_minor FROM fee_invoices WHERE id = ?', [$FT_inv])['paid_minor'], 0, 'and nothing is credited until somebody has seen the money');
t_ok(is_file(mam_fee_evidence_dir() . '/' . $FT_row($FT_c1)['evidence_file']), 'the slip is stored, under uploads/ so the backup carries it');
t_ok(str_contains(mam_fee_evidence_dir(), '/uploads/'), 'evidence lives under uploads/');
t_eq($FT_err(fn() => mam_fee_transfer_claim($FT_par, $FT_inv, ['reference' => 'ZEN-55555'] + $FT_ok + ['evidence' => $FT_png(320, 320, 2)])), 'claim_pending',
    'one claim at a time per invoice: a second waits for the first');
$FT_st = mam_fee_statement($FT_par, $FT_stu);
t_eq($FT_st['invoices'][0]['claims'][0]['status'] ?? '', 'pending', 'the guardian sees it on the invoice as being checked');
t_ok(!empty($FT_st['bank']['account_number']), 'and the statement says which account to send to');

t_group('fee transfer — the office confirms by retyping from the statement');

t_eq($FT_err(fn() => mam_fee_transfer_verify($FT_c1, 'GTB000771AB', '50000', false, $FT_admin)), 'not_checked', 'it must say it checked the money arrived');
t_eq($FT_err(fn() => mam_fee_transfer_verify($FT_c1, 'GTB000771', '50000', true, $FT_admin)), 'reference_mismatch', 'a reference that does not match is refused');
t_eq($FT_err(fn() => mam_fee_transfer_verify($FT_c1, 'GTB000771AB', '5000', true, $FT_admin)), 'amount_mismatch', 'an amount that does not match is refused');
t_eq($FT_row($FT_c1)['status'], 'pending', 'and a refused confirmation leaves the claim waiting');
$FT_pid = mam_fee_transfer_verify($FT_c1, 'gtb 000771 ab', '50000.00', true, $FT_admin);
t_eq($FT_row($FT_c1)['status'], 'verified', 'the reference compares as letters and digits, so "gtb 000771 ab" is GTB-000771-AB');
t_eq((int) $FT_row($FT_c1)['fee_payment_id'], $FT_pid, 'and the claim points at the payment it became');
$FT_fp = mam_db_row('SELECT * FROM fee_payments WHERE id = ?', [$FT_pid]);
t_ok($FT_fp['method'] === 'transfer' && (int) $FT_fp['amount_minor'] === 5000000 && $FT_fp['received_on'] === $FT_issued,
    'credited as a ₦50,000 transfer, received on the day the family said');
t_eq($FT_err(fn() => mam_fee_transfer_verify($FT_c1, 'GTB000771AB', '50000', true, $FT_admin)), 'not_pending', 'and it cannot be confirmed twice');

t_group('fee transfer — one slip, one reference, one payment');

t_eq($FT_err(fn() => mam_fee_transfer_claim($FT_par, $FT_inv, ['reference' => 'NEW-REF-9001', 'amount' => '1000'] + $FT_ok + ['evidence' => $FT_slip])), 'evidence_reused',
    'the same slip cannot be sent again for another payment');
t_eq($FT_err(fn() => mam_fee_transfer_claim($FT_par, $FT_inv, ['amount' => '1000'] + $FT_ok + ['evidence' => $FT_png(320, 320, 3)])), 'reference_reused',
    'nor can a reference that is already money on the account');
t_eq($FT_err(fn() => mam_fee_payment_record($FT_inv, '1000', 'transfer', $FT_admin, ['reference' => 'GTB 000771 AB'])), 'reference_reused',
    'and the office cannot type the same transfer in by hand — it used to credit twice');

t_group('fee transfer — a claim the office cannot confirm');

$FT_c2 = mam_fee_transfer_claim($FT_par, $FT_inv, ['amount' => '20000', 'reference' => 'FAKE-445566'] + $FT_ok + ['evidence' => $FT_png(320, 320, 4)]);
t_eq($FT_err(fn() => mam_fee_transfer_reject($FT_c2, 'no', $FT_admin)), 'reason_required', 'not confirming needs a reason the family can read');
mam_fee_transfer_reject($FT_c2, 'Nothing with that reference reached the account', $FT_admin);
t_eq($FT_row($FT_c2)['status'], 'rejected', 'it is recorded as not confirmed, with the reason');
t_eq((int) mam_db_row('SELECT paid_minor FROM fee_invoices WHERE id = ?', [$FT_inv])['paid_minor'], 5000000, 'and the balance is still owed');
t_eq($FT_err(fn() => mam_fee_transfer_claim($FT_par, $FT_inv, ['amount' => '20000', 'reference' => 'fake 445566'] + $FT_ok + ['evidence' => $FT_png(320, 320, 5)])), 'reference_rejected',
    'and the same reference cannot come back next week with a new photo');

t_group('fee transfer — a confirmation that cannot be credited is undone');

$FT_c3 = mam_fee_transfer_claim($FT_par, $FT_inv, ['amount' => '70000', 'reference' => 'ZEN-778899'] + $FT_ok + ['evidence' => $FT_png(320, 320, 6)]);
mam_fee_payment_record($FT_inv, '70000', 'cash', $FT_admin, ['reference' => 'CASH-BOOK-12']);   // meanwhile paid in cash
t_eq($FT_err(fn() => mam_fee_transfer_verify($FT_c3, 'ZEN778899', '70000', true, $FT_admin)), 'nothing_owing', 'the invoice was paid another way, so crediting refuses');
t_eq($FT_row($FT_c3)['status'], 'pending', 'and the claim goes BACK to waiting — never "confirmed" on an invoice it did not credit');
mam_fee_transfer_reject($FT_c3, 'Already paid in cash at the office; refund the transfer', $FT_admin);

t_group('fee transfer — the office reads the slip it is checking, and only that slip');

$FT_ev = mam_fee_claim_evidence($FT_c1);
t_ok($FT_ev['ok'] && $FT_ev['type'] === 'image/png', 'the evidence route finds the stored slip');
file_put_contents($FT_ev['path'], 'tampered');
t_eq(mam_fee_claim_evidence($FT_c1)['error'] ?? '', 'evidence_altered', 'and refuses a file that is no longer the one that was sent');
$FT_idx = (string) file_get_contents(dirname(__DIR__) . '/index.php');
t_ok(str_contains($FT_idx, "'/fee-evidence',    \$f('admin/fee-evidence.php'),   [mw_admin_cap('fees')]"), 'the evidence route needs the fees capability');
t_ok(str_contains((string) file_get_contents(dirname(__DIR__) . '/routes/admin/fee-evidence.php'), 'Content-Disposition: attachment'), 'and serves a download, never an inline document');
$FT_adm = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
foreach (['fee_transfer_verify', 'fee_transfer_reject', 'fee_unapplied_resolve'] as $FT_a) {
    t_ok(str_contains($FT_adm, "'$FT_a' => 'fees'"), "$FT_a is gated by the fees capability");
}
$FT_part = (string) file_get_contents(dirname(__DIR__, 2) . '/partials/admin-console/invoices.php');
$FT_form = substr($FT_part, (int) strpos($FT_part, "ac_form('fee_transfer_verify'"), 1400);
t_ok(!str_contains($FT_form, "\$cl['reference']") && !str_contains(substr($FT_form, 0, (int) strpos($FT_form, 'ac_form_end')), "\$cl['amount']"),
    'the confirm form does not print the claimed reference or amount beside the boxes — confirming means typing them from the statement');

t_group('fee transfer — the console queues what is waiting');

$FT_iw = mam_fee_invoice_open($FT_stu, $FT_par, $FT_admin, ['term_label' => 'Waiting', 'currency' => 'NGN']);
mam_fee_line_add($FT_iw, 'tuition', 'Aural', '5000', 1);
mam_fee_invoice_issue($FT_iw, $FT_admin, '2027-04-01');
$FT_c4 = mam_fee_transfer_claim($FT_par, $FT_iw, ['amount' => '5000', 'reference' => 'WAIT-123456', 'paid_on' => (string) mam_db_row('SELECT issued_on FROM fee_invoices WHERE id = ?', [$FT_iw])['issued_on']] + $FT_ok + ['evidence' => $FT_png(320, 320, 7)]);
$FT_cons = mam_console_invoices([]);
t_ok(in_array($FT_c4, array_map('intval', array_column($FT_cons['claims_waiting'], 'id')), true), 'the Invoices screen lists the transfer waiting to be checked');
t_ok((bool) array_filter(mam_console_decisions(), fn($d) => str_contains($d['title'], 'bank transfer')), 'and the console\'s decision queue says families are waiting');
mam_fee_transfer_reject($FT_c4, 'Test row, not a real transfer', $FT_admin);

t_group('fee transfer — online money taken beyond the balance is a row somebody resolves');

$FT_i2 = mam_fee_invoice_open($FT_stu, $FT_par, $FT_admin, ['term_label' => 'Online over', 'currency' => 'NGN']);
mam_fee_line_add($FT_i2, 'tuition', 'Theory', '10000', 1);
mam_fee_invoice_issue($FT_i2, $FT_admin, '2027-03-01');
$FT_i3 = mam_fee_invoice_open($FT_stu, $FT_par, $FT_admin, ['term_label' => 'Next term', 'currency' => 'NGN']);
mam_fee_line_add($FT_i3, 'tuition', 'Theory II', '10000', 1);
mam_fee_invoice_issue($FT_i3, $FT_admin, '2027-06-01');
$FT_op = mam_fee_online_start($FT_par, $FT_i2, null);
mam_fee_payment_record($FT_i2, '4000', 'cash', $FT_admin);
mam_payment_settle(mam_db_row('SELECT * FROM payments WHERE reference = ?', [$FT_op['reference']]), ['channel' => 'card'], '{}', $FT_op['reference']);
$FT_un = mam_db_row('SELECT * FROM fee_online_unapplied WHERE reference = ?', [$FT_op['reference']]);
t_ok($FT_un && (int) $FT_un['over_minor'] === 400000 && $FT_un['status'] === 'open', 'the ₦4,000 taken beyond the balance is an OPEN row, not just a note');
t_eq($FT_err(fn() => mam_fee_unapplied_resolve((int) $FT_un['id'], 'dismissed', '', $FT_admin)), 'reason_required', 'dismissing it needs a reason');
mam_fee_unapplied_resolve((int) $FT_un['id'], 'applied', 'Family asked to keep it for next term', $FT_admin, $FT_i3);
t_eq((int) mam_db_row('SELECT paid_minor FROM fee_invoices WHERE id = ?', [$FT_i3])['paid_minor'], 400000, 'moving it credits the same child\'s next invoice');
t_eq(mam_db_row('SELECT status FROM fee_online_unapplied WHERE id = ?', [(int) $FT_un['id']])['status'], 'applied', 'and the row says so');
t_eq($FT_err(fn() => mam_fee_unapplied_resolve((int) $FT_un['id'], 'refunded', 'again', $FT_admin)), 'already_resolved', 'once');
$FT_other = mam_fee_invoice_open(mam_db_insert('users', ['name' => 'Not Theirs', 'email' => 'nottheirs@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']), null, $FT_admin, ['term_label' => 'x', 'currency' => 'NGN']);
$FT_op2 = mam_fee_online_start($FT_par, $FT_i3, null);
mam_fee_payment_record($FT_i3, '6000', 'cash', $FT_admin);
mam_payment_settle(mam_db_row('SELECT * FROM payments WHERE reference = ?', [$FT_op2['reference']]), ['channel' => 'card'], '{}', $FT_op2['reference']);
$FT_un2 = mam_db_row('SELECT * FROM fee_online_unapplied WHERE reference = ?', [$FT_op2['reference']]);
t_eq($FT_err(fn() => mam_fee_unapplied_resolve((int) $FT_un2['id'], 'applied', '', $FT_admin, $FT_other)), 'apply_target', 'and never onto another family\'s invoice');
