<?php
/** Payments — webhook signature verification (security-critical) + regional tiers. */
declare(strict_types=1);
t_group('payment webhook signatures');

// Paystack signs the raw body with HMAC-SHA512 of the secret key.
mam_test_env(['PAYSTACK_WEBHOOK_SECRET' => 'sk_test_whatever']);
$body = '{"event":"charge.success","data":{"reference":"MAM-20260101-AAAA","amount":50000,"currency":"NGN"}}';
$valid = hash_hmac('sha512', $body, 'sk_test_whatever');
t_ok(mam_paystack_verify_signature($body, $valid) === true,  'paystack: valid signature accepted');
t_ok(mam_paystack_verify_signature($body, 'deadbeef') === false, 'paystack: forged signature rejected');
t_ok(mam_paystack_verify_signature($body . ' ', $valid) === false, 'paystack: tampered body rejected');

// Flutterwave compares a configured webhook hash in constant time.
mam_test_env(['FLW_WEBHOOK_HASH' => 'flw-secret-hash-123']);
t_ok(mam_flw_verify_signature('flw-secret-hash-123') === true, 'flutterwave: matching hash accepted');
t_ok(mam_flw_verify_signature('nope') === false, 'flutterwave: wrong hash rejected');
mam_test_env(['FLW_WEBHOOK_HASH' => '']);
t_ok(mam_flw_verify_signature('') === false, 'flutterwave: empty secret never accepts (fail-closed)');

t_group('booking tiers (charged amounts)');
// Use the static FX fallback so the tier conversion never hits the network.
try { mam_setting_set('fx_auto', '0'); mam_setting_set('usd_to_ngn', '1500'); } catch (Throwable $e) {}
$ng = mam_booking_tier('NG');
t_eq($ng['currency'], 'NGN', 'NG → NGN tier');
t_eq($ng['amount'], 7500, 'NG amount = ₦7,500');
t_ok($ng['amount_ngn'] === 7500, 'NG tier carries amount_ngn for settlement');
$us = mam_booking_tier('US');
t_eq($us['currency'], 'USD', 'unlisted country → USD default');
t_eq($us['amount'], 5, 'default amount = $5');
$de = mam_booking_tier('DE');
t_eq($de['currency'], 'EUR', 'EU country collapses to the EUR tier');
t_ok($us['amount_ngn'] > 0, 'USD tier still resolves an NGN settlement amount');
