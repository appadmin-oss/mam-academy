<?php
/**
 * POST /api/student/fee-pay {invoice_id, amount_minor?}
 *
 * An adult student paying their OWN invoice (they are its payer: mam_fee_student_self_pays). Same guards as the guardian route. Opens a Paystack charge for the balance (or for
 * `amount_minor` of it — an instalment) and returns the gateway's own checkout
 * page to send the browser to. The refusals are the library's
 * (mam_fee_online_start); settlement happens on return through
 * /api/payment/verify, or by webhook, whichever lands first.
 *
 * Paystack only, because only it redirects: the inline checkouts need a script
 * from the gateway's domain that the portal's CSP does not admit. Without a
 * Paystack key the answer is `online_payment_unavailable`, and the screen says
 * how else to pay rather than opening a checkout that cannot finish.
 */
declare(strict_types=1);
$u  = mam_require_role('student');
$in = mam_json_input();
$invId = (int) ($in['invoice_id'] ?? 0);
$amt   = isset($in['amount_minor']) && $in['amount_minor'] !== null && $in['amount_minor'] !== '' ? (int) $in['amount_minor'] : null;
if ($invId < 1) mam_fail('validation', 422, ['fields' => ['invoice_id']]);
if (!mam_fee_online_available()) mam_fail('online_payment_unavailable', 503);
if (!filter_var((string) ($u['email'] ?? ''), FILTER_VALIDATE_EMAIL)) mam_fail('no_email', 409);

// A second press (a double-click, a second tab) within a couple of minutes gets the
// checkout the first one opened, instead of a second payments row and a second
// charge (payment-guards.php, guard 3).
if ($recent = mam_fee_recent_checkout((int) $u['id'], $invId, $amt)) {
    mam_ok(['reference' => $recent['reference'], 'authorization_url' => $recent['authorization_url'],
            'amount_ngn' => '₦' . number_format((int) $recent['amount_minor'] / 100, 2), 'reused' => true]);
}

try {
    $pay = mam_fee_online_start((int) $u['id'], $invId, $amt, 'paystack');
    $pay['student_id'] = (int) (mam_db_row('SELECT student_id FROM fee_invoices WHERE id = ?', [$invId])['student_id'] ?? 0);
} catch (\InvalidArgumentException $e) {
    mam_fail('validation', 422, ['reason' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    $code = $e->getMessage();
    mam_fail($code, $code === 'forbidden' ? 403 : ($code === 'invoice_not_found' ? 404 : 409));
}

$ref = (string) $pay['reference'];
// Positive, NGN, under the ceiling (payment-guards.php, guard 5).
if ($amtProblem = mam_paystack_amount_problem((int) $pay['amount_minor'], (string) ($pay['currency'] ?? 'NGN'))) {
    mam_db_update('payments', ['status' => 'failed', 'updated_at' => date('Y-m-d H:i:s')], ['reference' => $ref]);
    mam_log('error', 'fee_pay_amount_refused', ['ref' => $ref, 'kobo' => (int) $pay['amount_minor'], 'problem' => $amtProblem]);
    mam_fail('amount_not_allowed', 422, ['reason' => $amtProblem]);
}
// Opened through the attempt register, so the reuse above can find it.
$att = mam_paystack_start_attempt($pay, (string) $u['email'], (int) $pay['amount_minor'],
    ['purpose' => 'fee', 'invoice_id' => $invId, 'reference' => $ref],
    '/portal/student/?paid=' . rawurlencode($ref) . '#money');
$url = (string) ($att['authorization_url'] ?? '');
if (!empty($att['error']) || $url === '') {
    mam_db_update('payments', ['status' => 'failed', 'updated_at' => date('Y-m-d H:i:s')], ['reference' => $ref]);
    mam_fail(($att['error'] ?? '') === 'init_failed' ? 'init_failed' : 'paystack_init_failed', 502);
}
$ref = (string) $att['reference'];

mam_ok([
    'reference'         => $ref,
    'authorization_url' => $url,
    'amount_ngn'        => '₦' . number_format((int) $pay['amount_minor'] / 100, 2),
]);
