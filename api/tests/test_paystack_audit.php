<?php
/**
 * Paystack audit (2026-11-22). Each group pins one defect that was found and
 * fixed, at the layer that keeps it fixed. The gateway is never called: the
 * pieces that decide money are pure functions or source-level guarantees.
 */
declare(strict_types=1);

$PA_root = dirname(__DIR__, 2);
$PA_src  = static fn(string $rel) => (string) file_get_contents($PA_root . '/' . $rel);
$PA_envWas = $GLOBALS['__env'] ?? [];

t_group('paystack audit — the webhook signature never throws, and is checked as Paystack sends it');

mam_test_env(['PAYSTACK_SECRET_KEY' => '', 'PAYSTACK_WEBHOOK_SECRET' => '']);
$PA_threw = false;
try { $PA_r = mam_paystack_verify_signature('{"event":"charge.success"}', 'abc'); } catch (Throwable $e) { $PA_threw = true; $PA_r = null; }
t_ok(!$PA_threw && $PA_r === false, 'with no secret configured a webhook is refused, not answered with a 500');
t_ok(mam_paystack_configured() === false, 'and the site knows card payment is unavailable');

mam_test_env(['PAYSTACK_SECRET_KEY' => 'sk_test_abc123']);
$PA_body = '{"event":"charge.success","data":{"reference":"MAM-X"}}';
$PA_sig  = hash_hmac('sha512', $PA_body, 'sk_test_abc123');
t_ok(mam_paystack_verify_signature($PA_body, $PA_sig), 'a correct HMAC-SHA512 of the raw body is accepted');
t_ok(mam_paystack_verify_signature($PA_body, strtoupper($PA_sig)), '…in either hex case');
t_ok(!mam_paystack_verify_signature($PA_body . ' ', $PA_sig), 'one changed byte of the body is refused');
t_ok(!mam_paystack_verify_signature($PA_body, ''), 'a missing signature is refused');
mam_test_env(['PAYSTACK_WEBHOOK_SECRET' => 'whsec_other']);
t_ok(!mam_paystack_verify_signature($PA_body, $PA_sig), 'a dedicated webhook secret, when set, is the one used');

t_group('paystack audit — the copy we keep carries no reusable card authorisation');

$PA_tx = ['status' => 'success', 'amount' => 500000, 'channel' => 'card',
    'authorization' => ['authorization_code' => 'AUTH_live123', 'signature' => 'SIG_abc', 'last4' => '4081', 'brand' => 'visa', 'bank' => 'Test Bank', 'reusable' => true],
    'customer' => ['email' => 'p@example.com']];
$PA_red = mam_paystack_redact($PA_tx);
t_eq($PA_red['authorization']['authorization_code'], '[redacted]', 'the authorization_code (which can charge the card again) is not kept');
t_eq($PA_red['authorization']['signature'], '[redacted]', 'nor the card signature');
t_eq([$PA_red['authorization']['last4'], $PA_red['authorization']['brand'], $PA_red['channel'], $PA_red['amount']], ['4081', 'visa', 'card', 500000],
    'what receipts and reconciliation read is kept');
t_eq(mam_paystack_redact(null), null, 'a missing payload passes through');
foreach (['api/routes/webhook-paystack.php', 'api/routes/payment-verify.php'] as $PA_f) {
    t_ok(str_contains($PA_src($PA_f), 'mam_paystack_redact('), "$PA_f stores the redacted copy");
}
t_ok(!str_contains($PA_src('api/routes/webhook-paystack.php'), "'payload'         => \$raw"), 'the webhook no longer stores the raw body');

t_group('paystack audit — the payment row\'s amount stays honest across retries');

$PA_init = $PA_src('api/routes/payment-init.php');
t_ok(str_contains($PA_init, '$amountKobo < $was') && str_contains($PA_init, "'amount_minor' => \$amountKobo"),
    'a retry at a LOWER price (the rate moved) lowers the row, so a complete payment is not read as short');
t_ok(str_contains($PA_init, "(string) \$existing['status'] !== 'succeeded'"), 'a settled row is never repriced');
// The comparison it protects, at the kobo:
$PA_row = ['amount' => 5000, 'amount_minor' => 500000];
t_ok(!mam_payment_amount_ok($PA_row, 480000.0, 'NGN'), 'without the fix: the old row reads a ₦4,800 payment at today\'s price as short');
$PA_row['amount_minor'] = min(500000, 480000);
t_ok(mam_payment_amount_ok($PA_row, 480000.0, 'NGN') && mam_payment_amount_ok($PA_row, 500000.0, 'NGN'),
    'with it: a charge at either server price settles');
t_ok(!mam_payment_amount_ok($PA_row, 479999.0, 'NGN') && !mam_payment_amount_ok($PA_row, 480000.0, 'USD'),
    'one kobo short, or another currency, is still refused');

t_group('paystack audit — refusals are clean and say nothing internal');

t_ok(str_contains($PA_init, "mam_fail('online_payment_unavailable', 503") && strpos($PA_init, 'online_payment_unavailable') < strpos($PA_init, "mam_db_insert('payments'"),
    'without a key, card payment is refused before any payments row is written');
t_ok(!str_contains($PA_init, "['provider' => \$body]"), 'Paystack\'s raw answer is not echoed to the caller');
t_ok(!preg_match("#mam_fail\\('init_failed'[^;]*getMessage#", $PA_init), 'an exception message is not echoed by init');
$PA_ver = $PA_src('api/routes/payment-verify.php');
t_ok(!preg_match("#mam_fail\\('upstream_error'[^;]*getMessage#", $PA_ver), 'nor by verify');

t_group('paystack audit — verify fails only what has failed');

t_ok(str_contains($PA_ver, "mam_fail('payment_pending', 409") && strpos($PA_ver, 'payment_pending') < strpos($PA_ver, "'status' => 'failed'"),
    'a charge still in flight answers 409 and writes nothing (anybody with a reference could mark it failed)');
t_ok((bool) preg_match("#\\[\\s*'failed',\\s*'reversed',\\s*'success',\\s*'successful'\\s*\\]#", $PA_ver), 'failed, reversed, or a mismatched success are the only failures recorded');
// The live form (/apply → apply-flow.js) answers any verify refusal after the card
// window's own success callback with "we are confirming", which is right for 409.
t_ok(str_contains($PA_src('assets/js/apply-flow.js'), ".catch(function () { s.payNote = 'We are confirming your payment"),
    'the applicant sees "we are confirming your payment", not a failure');

t_group('paystack audit — the webhook drops repeats only');

$PA_wh = $PA_src('api/routes/webhook-paystack.php');
t_ok(str_contains($PA_wh, "\$eventType . ':'"), 'the dedupe key names the event type as well as the object');
t_ok(str_contains($PA_wh, "mam_fail('retry_later', 503)"), 'a failure to record that is not a repeat is not acknowledged, so Paystack retries');
t_ok(strpos($PA_wh, "mam_fail('invalid_signature', 401)") < strpos($PA_wh, "mam_db_insert('webhook_events'"), 'an unsigned call writes nothing');

t_group('paystack audit — the callback is always this site\'s own');

$PA_lib = $PA_src('api/modules/finance/lib/paystack.php');
t_ok(str_contains($PA_lib, "preg_match('#^https?://#i', \$base)"), 'with no absolute APP_URL the callback is left to the dashboard, never a relative URL');
t_ok(!preg_match('#callback_url[^;]*\\$_(GET|POST|REQUEST)#', $PA_lib . $PA_init . $PA_src('api/routes/parent/fee-pay.php')), 'no caller-supplied value reaches callback_url');
t_ok(!preg_match('#PAYSTACK_SECRET_KEY|sk_(live|test)_#', implode('', array_map('file_get_contents', glob($PA_root . '/assets/js/*.js') ?: []))),
    'the secret key appears nowhere in the browser\'s code');

$GLOBALS['__env'] = $PA_envWas;
