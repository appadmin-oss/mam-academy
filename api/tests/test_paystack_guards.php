<?php
/**
 * Paystack scenario guards (api/modules/finance/lib/payment-guards.php, schema v106).
 * One group per guard. The gateway is never called: every function that would
 * call it takes an injectable initializer/verifier, and the suite passes fakes.
 */
declare(strict_types=1);

$PG_envWas = $GLOBALS['__env'] ?? [];
$PG_root = dirname(__DIR__, 2);
$PG_src = static fn(string $rel) => (string) file_get_contents($PG_root . '/' . $rel);
$PG_sfx = substr(bin2hex(random_bytes(4)), 0, 8);
$PG_n = 0;

/** A booking-fee payment for a fresh application: [payment row, application id]. */
$PG_booking = static function (string $appStatus = 'submitted', string $email = '') use ($PG_sfx, &$PG_n): array {
    $PG_n++;
    $cid = mam_db_insert('customers', ['email' => $email !== '' ? $email : "pg_{$PG_sfx}_{$PG_n}@x.dev", 'first_name' => 'Pay', 'last_name' => 'Guard']);
    $aid = mam_db_insert('applications', ['reference' => "MAM-PG-{$PG_sfx}-{$PG_n}", 'customer_id' => $cid, 'pathway' => 'standard', 'status' => $appStatus, 'total_usd' => 5]);
    $pid = mam_db_insert('payments', ['application_id' => $aid, 'reference' => "MAM-PG-{$PG_sfx}-{$PG_n}-BK", 'provider' => 'paystack', 'purpose' => 'booking_fee',
        'status' => 'pending', 'amount' => 7500, 'currency' => 'NGN', 'amount_minor' => 750000, 'created_at' => date('Y-m-d H:i:s')]);
    return [mam_db_row('SELECT * FROM payments WHERE id = ?', [$pid]), (int) $aid];
};
/** A Paystack transaction as verify/webhook would see it. */
$PG_tx = static fn(string $ref, int $kobo = 750000, string $domain = 'test', string $status = 'success', string $cur = 'NGN')
    => ['reference' => $ref, 'status' => $status, 'amount' => $kobo, 'currency' => $cur, 'domain' => $domain, 'channel' => 'card',
        'authorization' => ['authorization_code' => 'AUTH_x', 'last4' => '4081']];

mam_test_env(['PAYSTACK_SECRET_KEY' => 'sk_test_guardkey', 'PAYSTACK_WEBHOOK_SECRET' => '']);

/* ── 1 ── */
t_group('paystack guards — 1. a test charge never settles a live site, nor the reverse');

t_eq(mam_paystack_mode(), 'test', 'an sk_test_ key is test mode');
mam_test_env(['PAYSTACK_SECRET_KEY' => 'sk_live_guardkey']);
t_eq(mam_paystack_mode(), 'live', 'an sk_live_ key is live mode');
t_ok(!mam_paystack_domain_ok(['domain' => 'test']) && mam_paystack_domain_ok(['domain' => 'live']), 'on a live site a test transaction is refused, a live one passes');
[$PG_p1] = $PG_booking();
$PG_r = mam_paystack_apply_tx($PG_p1, $PG_tx($PG_p1['reference'], 750000, 'test'), 'test');
t_eq([$PG_r['outcome'], $PG_r['reason']], ['refused', 'mode_mismatch'], 'a test-domain charge on a live install is refused');
t_eq((string) mam_db_row('SELECT status FROM payments WHERE id = ?', [(int) $PG_p1['id']])['status'], 'pending', 'and the payment is NOT settled');
t_ok(mam_db_row("SELECT 1 FROM payment_events WHERE payment_id = ? AND kind = 'mode_mismatch'", [(int) $PG_p1['id']]) !== null, 'the mismatch is recorded as an event');
mam_test_env(['PAYSTACK_SECRET_KEY' => 'sk_test_guardkey']);
t_ok(!mam_paystack_domain_ok(['domain' => 'live']), 'on a test site a live transaction is refused');
mam_test_env(['PAYSTACK_SECRET_KEY' => 'custom_key']);
t_ok(mam_paystack_domain_ok(['domain' => 'test']) && mam_paystack_domain_ok([]), 'with a key of unknown mode, or no domain sent, there is nothing to compare');
mam_test_env(['PAYSTACK_SECRET_KEY' => 'sk_test_guardkey']);
$PG_ver = $PG_src('api/routes/payment-verify.php');
t_ok(str_contains($PG_ver, "['mode_mismatch', 'reference_mismatch']"), 'verify records neither a mode mismatch nor a foreign reference as this payment failing');

/* ── 2 ── */
t_group('paystack guards — 2. a retry after closing the checkout gets a fresh reference, and every reference resolves to the one payment');

[$PG_p2] = $PG_booking();
t_eq(mam_payment_next_attempt_ref($PG_p2), $PG_p2['reference'], 'the first attempt keeps the payment\'s own reference');
$PG_calls = [];
$PG_ok_init = static function (string $email, int $kobo, string $ref, array $meta = [], ?string $cb = null) use (&$PG_calls) {
    $PG_calls[] = $ref;
    return ['code' => 200, 'data' => ['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/' . $ref, 'access_code' => 'AC_' . $ref]]];
};
$PG_a1 = mam_paystack_start_attempt($PG_p2, 'pg@x.dev', 750000, [], null, $PG_ok_init, static fn($r) => []);
t_eq([$PG_a1['reference'], $PG_a1['reused']], [$PG_p2['reference'], false], 'the first checkout opens under the payment\'s reference');
// The family closes the window and presses Pay again after the reuse window:
mam_db()->prepare("UPDATE payment_attempts SET created_at = ? WHERE payment_id = ?")->execute([date('Y-m-d H:i:s', time() - 600), (int) $PG_p2['id']]);
$PG_a2 = mam_paystack_start_attempt($PG_p2, 'pg@x.dev', 750000, [], null, $PG_ok_init, static fn($r) => []);
t_eq($PG_a2['reference'], $PG_p2['reference'] . '-A2', 'a later retry opens under a fresh reference (Paystack refuses one it has seen)');
t_eq((int) mam_payment_resolve_ref($PG_a2['reference'])['id'], (int) $PG_p2['id'], 'the attempt reference resolves to the same payment');
t_eq(mam_payment_resolve_ref($PG_a2['reference'])['_charged_ref'], $PG_a2['reference'], '…remembering which reference the gateway charged');
t_eq(mam_payment_charge_refs($PG_p2), [$PG_p2['reference'], $PG_p2['reference'] . '-A2'], 'and both references are this payment\'s charges');
t_eq(mam_payment_resolve_ref('NOPE-' . $PG_sfx), null, 'an unknown reference resolves to nothing');

// Paystack says "Duplicate Transaction Reference": check that charge first.
mam_db()->prepare("UPDATE payment_attempts SET created_at = ? WHERE payment_id = ?")->execute([date('Y-m-d H:i:s', time() - 600), (int) $PG_p2['id']]);
$PG_dupInit = static fn($e, $k, $ref, $m = [], $c = null) => ['code' => 400, 'data' => ['status' => false, 'message' => 'Duplicate Transaction Reference']];
$PG_paidVerifier = static fn($ref) => ['code' => 200, 'data' => ['status' => true, 'data' => ['reference' => $ref, 'status' => 'success', 'amount' => 750000, 'currency' => 'NGN', 'domain' => 'test']]];
$PG_a3 = mam_paystack_start_attempt($PG_p2, 'pg@x.dev', 750000, [], null, $PG_dupInit, $PG_paidVerifier);
t_ok($PG_a3['settled'] === true, 'a duplicate reference that was in fact paid is settled, not opened again');
t_eq((string) mam_db_row('SELECT status FROM payments WHERE id = ?', [(int) $PG_p2['id']])['status'], 'succeeded', 'the payment is settled');

[$PG_p2b] = $PG_booking();
$PG_unpaidVerifier = static fn($ref) => ['code' => 200, 'data' => ['status' => true, 'data' => ['reference' => $ref, 'status' => 'abandoned', 'amount' => 750000, 'currency' => 'NGN']]];
$PG_seq = 0;
$PG_dupThenOk = static function ($e, $k, $ref, $m = [], $c = null) use (&$PG_seq) {
    return ++$PG_seq === 1 ? ['code' => 400, 'data' => ['status' => false, 'message' => 'Duplicate Transaction Reference']]
                           : ['code' => 200, 'data' => ['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/' . $ref, 'access_code' => 'AC']]];
};
$PG_a4 = mam_paystack_start_attempt($PG_p2b, 'pg@x.dev', 750000, [], null, $PG_dupThenOk, $PG_unpaidVerifier);
t_eq([$PG_a4['reference'], $PG_a4['settled']], [$PG_p2b['reference'] . '-A2', false], 'a duplicate that was NOT paid gets one more fresh reference');

/* ── 2b ── */
t_group('paystack guards — 2. two attempts can never both settle; the second payment is owed back');

[$PG_p3] = $PG_booking();
mam_payment_claim_attempt((int) $PG_p3['id'], $PG_p3['reference']);
mam_payment_claim_attempt((int) $PG_p3['id'], $PG_p3['reference'] . '-A2');
$PG_s1 = mam_paystack_apply_tx($PG_p3, $PG_tx($PG_p3['reference']), 'test');
$PG_s2 = mam_paystack_apply_tx($PG_p3, $PG_tx($PG_p3['reference'] . '-A2'), 'test');
t_eq([$PG_s1['outcome'], $PG_s2['outcome']], ['settled', 'duplicate_charge'], 'the first charge settles; the second, under another attempt, is a duplicate');
$PG_row3 = mam_db_row('SELECT status, provider_reference, gateway_state FROM payments WHERE id = ?', [(int) $PG_p3['id']]);
t_eq([$PG_row3['status'], $PG_row3['provider_reference'], $PG_row3['gateway_state']], ['succeeded', $PG_p3['reference'], 'refund_due'],
    'the payment stays settled by the first charge and is marked refund due for the second');
t_eq(mam_paystack_apply_tx($PG_p3, $PG_tx($PG_p3['reference']), 'test')['outcome'], 'already', 'the SAME charge arriving again (webhook after verify) is a harmless repeat');
// The compare-and-set itself: two settle calls on one row, one wins.
[$PG_p3b] = $PG_booking();
$PG_c1 = mam_payment_settle($PG_p3b, null, '{}', 'X1'); $PG_c2 = mam_payment_settle($PG_p3b, null, '{}', 'X2');
t_ok(empty($PG_c1['already']) && !empty($PG_c2['already']), 'mam_payment_settle claims a payment exactly once');

/* ── 3 ── */
t_group('paystack guards — 3. a double-click reuses the open checkout instead of opening a second charge');

[$PG_p4] = $PG_booking();
$PG_calls = [];
$PG_b1 = mam_paystack_start_attempt($PG_p4, 'pg@x.dev', 750000, [], null, $PG_ok_init, static fn($r) => []);
$PG_b2 = mam_paystack_start_attempt($PG_p4, 'pg@x.dev', 750000, [], null, $PG_ok_init, static fn($r) => []);
t_eq(count($PG_calls), 1, 'two presses inside the window call Paystack once');
t_eq([$PG_b2['reference'], $PG_b2['authorization_url'], $PG_b2['reused']], [$PG_b1['reference'], $PG_b1['authorization_url'], true], 'the second press gets the same checkout');
t_ok(!mam_payment_claim_attempt((int) $PG_p4['id'], $PG_b1['reference']), 'two simultaneous presses race for one attempt reference and only one can claim it');
t_ok(str_contains($PG_src('api/routes/parent/fee-pay.php'), 'mam_fee_recent_checkout('), 'a guardian\'s second press on Pay gets the first checkout too');

/* ── 4 ── */
t_group('paystack guards — 4. something no longer payable is refused at init, and money that arrives anyway is owed back');

[$PG_p5, $PG_a5] = $PG_booking('cancelled');
t_eq(mam_payment_target_closed($PG_p5), 'application_cancelled', 'a cancelled application is closed');
foreach (['expired', 'rejected', 'declined', 'withdrawn'] as $PG_st) {
    [$PG_px] = $PG_booking($PG_st);
    t_eq(mam_payment_target_closed($PG_px), 'application_' . $PG_st, "a $PG_st application is closed");
}
[$PG_pe] = $PG_booking('submitted', "erased-{$PG_sfx}@erased.invalid");
t_eq(mam_payment_target_closed($PG_pe), 'application_erased', 'an erased applicant (.invalid tombstone) is closed');
[$PG_po] = $PG_booking('submitted');
t_eq(mam_payment_target_closed($PG_po), null, 'an open application is payable');
$PG_r5 = mam_paystack_apply_tx($PG_p5, $PG_tx($PG_p5['reference']), 'webhook');
t_eq($PG_r5['outcome'], 'refund_due', 'a charge for a cancelled application credits nothing');
t_ok(mam_db_row('SELECT booking_fee_paid_at FROM applications WHERE id = ?', [$PG_a5])['booking_fee_paid_at'] === null, 'the booking fee is NOT stamped as paid');
t_eq((string) mam_db_row('SELECT gateway_state FROM payments WHERE id = ?', [(int) $PG_p5['id']])['gateway_state'], 'refund_due', 'the payment is marked refund due');
mam_db_insert('store_orders', ['reference' => "ST-PG-{$PG_sfx}", 'items_json' => '[]', 'total_usd' => 10, 'status' => 'cancelled']);
t_eq(mam_payment_target_closed(['purpose' => 'store', 'reference' => "ST-PG-{$PG_sfx}"]), 'order_cancelled', 'a cancelled store order is closed');
$PG_init = $PG_src('api/routes/payment-init.php');
t_ok(substr_count($PG_init, "mam_fail('not_payable', 409") === 2 && strpos($PG_init, "'not_payable'") < strpos($PG_init, "mam_db_insert('payments'"),
    'init refuses a closed application or order before any payments row is written, with a sentence the page shows');
t_ok(str_contains($PG_src('api/modules/finance/lib/payments.php'), 'mam_payment_flag_refund_due($payment, \'paid after it closed'), 'settlement itself refuses a closed target, whoever calls it');
t_ok(str_contains(mam_payment_closed_message('application_cancelled'), 'closed'), 'the refusal reads as a sentence');

/* ── 5 ── */
t_group('paystack guards — 5. the amount is positive, NGN and under the ceiling');

t_eq(mam_paystack_amount_problem(0, 'NGN'), 'zero_amount', 'nothing to charge is refused');
t_eq(mam_paystack_amount_problem(-500, 'NGN'), 'zero_amount', 'a negative amount is refused');
t_eq(mam_paystack_amount_problem(50, 'NGN'), 'amount_too_small', 'under ₦1 is refused (Paystack would)');
t_eq(mam_paystack_amount_problem(750000, 'USD'), 'currency_not_supported', 'a currency other than naira is refused');
t_eq(mam_paystack_amount_problem(750000, 'NGN'), null, 'an ordinary charge passes');
t_eq(mam_paystack_max_kobo(), MAM_PAY_DEFAULT_MAX_NGN * 100, 'the default ceiling is ₦' . number_format(MAM_PAY_DEFAULT_MAX_NGN));
t_eq(mam_paystack_amount_problem(MAM_PAY_DEFAULT_MAX_NGN * 100 + 1, 'NGN'), 'amount_too_large', 'one kobo over the ceiling is refused');
t_ok(str_contains($PG_init, "mam_fail('amount_not_allowed', 422") && str_contains($PG_src('api/routes/parent/fee-pay.php'), "mam_fail('amount_not_allowed', 422"),
    'both card inits refuse an impossible amount');

/* ── 6 ── */
t_group('paystack guards — 6. refunds and disputes are recorded and flagged, never acted on automatically');

[$PG_p6, $PG_a6] = $PG_booking();
mam_paystack_apply_tx($PG_p6, $PG_tx($PG_p6['reference']), 'test');
$PG_gs = static fn() => (string) mam_db_row('SELECT gateway_state FROM payments WHERE id = ?', [(int) $PG_p6['id']])['gateway_state'];
t_eq(mam_paystack_handle_gateway_event('charge.dispute.create', ['transaction' => ['reference' => $PG_p6['reference'], 'amount' => 750000], 'status' => 'awaiting-merchant-feedback'])['state'], 'disputed', 'a dispute marks the payment disputed');
t_eq($PG_gs(), 'disputed', '…on the row');
t_eq(mam_paystack_handle_gateway_event('charge.dispute.resolve', ['transaction' => ['reference' => $PG_p6['reference']], 'resolution' => 'declined'])['state'], 'dispute_won', 'a declined claim is a dispute the academy won');
t_eq(mam_paystack_handle_gateway_event('charge.dispute.resolve', ['transaction' => ['reference' => $PG_p6['reference']], 'resolution' => 'merchant-accepted'])['state'], 'dispute_lost', 'an accepted claim is a dispute lost');
t_eq(mam_paystack_handle_gateway_event('refund.processed', ['transaction_reference' => $PG_p6['reference'], 'amount' => 750000, 'status' => 'processed'])['state'], 'refunded', 'a processed refund marks it refunded');
t_eq(mam_paystack_handle_gateway_event('refund.failed', ['transaction_reference' => $PG_p6['reference'], 'status' => 'failed'])['state'], 'refund_failed', 'a failed refund is flagged');
t_eq((string) mam_db_row('SELECT status FROM payments WHERE id = ?', [(int) $PG_p6['id']])['status'], 'succeeded', 'the payment itself is never un-settled');
t_ok(mam_db_row('SELECT booking_fee_paid_at FROM applications WHERE id = ?', [$PG_a6])['booking_fee_paid_at'] !== null, 'and what it paid for is left alone');
t_eq((int) mam_db_row("SELECT COUNT(*) n FROM payment_events WHERE payment_id = ? AND kind LIKE 'charge_dispute%' OR (payment_id = ? AND kind LIKE 'refund_%')", [(int) $PG_p6['id'], (int) $PG_p6['id']])['n'], 5, 'every event is recorded');
t_eq(mam_paystack_handle_gateway_event('refund.processed', ['transaction_reference' => 'UNKNOWN-' . $PG_sfx])['reason'], 'payment_not_found', 'an event for an unknown charge is recorded, not crashed on');
t_eq(mam_paystack_handle_gateway_event('subscription.create', [])['handled'], false, 'other events are not ours');
$PG_wh = $PG_src('api/routes/webhook-paystack.php');
t_ok(strpos($PG_wh, "mam_fail('invalid_signature', 401)") < strpos($PG_wh, 'mam_paystack_handle_gateway_event('), 'refund and dispute events are signature-checked first');
t_ok(strpos($PG_wh, "mam_db_insert('webhook_events'") < strpos($PG_wh, 'mam_paystack_handle_gateway_event('), '…and deduplicated like charge.success');
try { mam_payment_set_gateway_state((int) $PG_p6['id'], 'nonsense'); $PG_bad = false; } catch (InvalidArgumentException $e) { $PG_bad = true; }
t_ok($PG_bad, 'a gateway state outside the list is refused');

/* ── 7 ── */
t_group('paystack guards — 7. the sweep settles payments whose webhook never came, and gives up on abandoned ones');

[$PG_p7] = $PG_booking();
[$PG_p7b] = $PG_booking();
[$PG_p7c] = $PG_booking();
$PG_old = static fn(int $id, int $sec) => mam_db()->prepare('UPDATE payments SET created_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - $sec), $id]);
$PG_old((int) $PG_p7['id'], 3600);           // paid, webhook lost
$PG_old((int) $PG_p7b['id'], 26 * 3600);     // never paid, over a day old
$PG_old((int) $PG_p7c['id'], 600);           // too recent to touch
$PG_v7 = static function ($ref) use ($PG_p7) {
    $paid = $ref === $PG_p7['reference'];
    return ['code' => 200, 'data' => ['status' => true, 'data' => ['reference' => $ref, 'status' => $paid ? 'success' : 'abandoned', 'amount' => 750000, 'currency' => 'NGN', 'domain' => 'test']]];
};
$PG_sw = mam_paystack_reconcile_sweep(200, 30, 24, $PG_v7);
$PG_st = static fn(array $p) => (string) mam_db_row('SELECT status FROM payments WHERE id = ?', [(int) $p['id']])['status'];
t_eq([$PG_st($PG_p7), $PG_st($PG_p7b), $PG_st($PG_p7c)], ['succeeded', 'abandoned', 'pending'], 'paid → settled, a day unpaid → abandoned, recent → untouched');
t_ok($PG_sw['settled'] >= 1 && $PG_sw['abandoned'] >= 1 && $PG_sw['skipped'] === null, 'the sweep reports what it did');
$PG_throwing = static function ($ref) { throw new RuntimeException('network down'); };
$PG_old((int) $PG_p7c['id'], 3600);
$PG_threw = false;
try { $PG_sw2 = mam_paystack_reconcile_sweep(5, 30, 24, $PG_throwing); } catch (Throwable $e) { $PG_threw = true; }
t_ok(!$PG_threw && $PG_sw2['errors'] >= 1, 'a gateway outage is counted, never thrown');
t_eq($PG_st($PG_p7c), 'pending', 'and a payment it could not check is left pending');
mam_automation_set('payment_reconcile', false);
t_eq(mam_paystack_reconcile_sweep(5, 30, 24, $PG_v7)['skipped'], 'automation_off', 'turning the automation off stops the sweep');
mam_automation_set('payment_reconcile', true);
t_ok(isset(mam_automations_registry()['payment_reconcile']) && mam_automations_registry()['payment_reconcile']['default'] === true, 'the sweep is a registered automation, on by default');
t_ok(str_contains($PG_src('api/tools/run-jobs.php'), 'mam_paystack_reconcile_sweep()'), 'and the scheduled jobs run it');

/* ── 8 ── */
t_group('paystack guards — 8. the verify-time checks stay: amount in kobo, naira, and the reference must be this payment\'s');

[$PG_p8] = $PG_booking();
t_eq(mam_paystack_tx_check($PG_p8, $PG_tx($PG_p8['reference'], 749999))['reason'], 'amount_or_currency', 'one kobo short is refused');
t_eq(mam_paystack_tx_check($PG_p8, $PG_tx($PG_p8['reference'], 750000, 'test', 'success', 'USD'))['reason'], 'amount_or_currency', 'another currency is refused');
t_eq(mam_paystack_tx_check($PG_p8, $PG_tx('SOMEONE-ELSES-' . $PG_sfx))['reason'], 'reference_mismatch', 'a charge under another payment\'s reference cannot settle this one');
t_eq(mam_paystack_tx_check($PG_p8, $PG_tx($PG_p8['reference'], 750000, 'test', 'ongoing')), ['ok' => false, 'terminal' => false, 'reason' => 'pending'], 'a charge in flight is neither paid nor failed');
t_eq(mam_paystack_tx_check($PG_p8, $PG_tx($PG_p8['reference'], 750000, 'test', 'failed'))['terminal'], true, 'a failed charge is final');
t_eq(mam_paystack_tx_check($PG_p8, $PG_tx($PG_p8['reference']))['ok'], true, 'the right amount, in naira, under its own reference, in the right mode, passes');
t_eq(mam_paystack_tx_check($PG_p8, null)['reason'], 'no_transaction', 'no transaction is not a payment');

$GLOBALS['__env'] = $PG_envWas;
