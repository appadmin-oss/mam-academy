<?php
/**
 * POST /api/parent/fee-pay {invoice_id, amount_minor?}
 *
 * The guardian's Pay button. Opens a Paystack charge for the balance (or for
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
$u  = mam_require_role('parent');
$in = mam_json_input();
$invId = (int) ($in['invoice_id'] ?? 0);
$amt   = isset($in['amount_minor']) && $in['amount_minor'] !== null && $in['amount_minor'] !== '' ? (int) $in['amount_minor'] : null;
if ($invId < 1) mam_fail('validation', 422, ['fields' => ['invoice_id']]);
if (trim((string) mam_env('PAYSTACK_SECRET_KEY', '')) === '') mam_fail('online_payment_unavailable', 503);
if (!filter_var((string) ($u['email'] ?? ''), FILTER_VALIDATE_EMAIL)) mam_fail('no_email', 409);

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
try {
    $resp = mam_paystack_initialize((string) $u['email'], (int) $pay['amount_minor'], $ref,
        ['purpose' => 'fee', 'invoice_id' => $invId, 'reference' => $ref],
        '/portal/parent/?paid=' . rawurlencode($ref) . '&child=' . (int) $pay['student_id']);
    $body = $resp['data'] ?? [];
    $url  = (string) ($body['data']['authorization_url'] ?? '');
} catch (\Throwable $e) {
    mam_log('error', 'fee_pay_init_failed', ['ref' => $ref, 'err' => $e->getMessage()]);
    mam_db_update('payments', ['status' => 'failed', 'updated_at' => date('Y-m-d H:i:s')], ['reference' => $ref]);
    mam_fail('init_failed', 502);
}
if (!($body['status'] ?? false) || $url === '') {
    mam_db_update('payments', ['status' => 'failed', 'updated_at' => date('Y-m-d H:i:s')], ['reference' => $ref]);
    mam_fail('paystack_init_failed', 502);
}

mam_ok([
    'reference'         => $ref,
    'authorization_url' => $url,
    'amount_ngn'        => '₦' . number_format((int) $pay['amount_minor'] / 100, 2),
]);
