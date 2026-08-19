<?php
/**
 * Payment settlement (the shared verify+webhook path). Guards the bug where the
 * webhooks blindly set applications.status='paid' for EVERY charge: a booking
 * fee must NOT enrol, a store payment must deliver the order, tuition must
 * enrol + provision — and settling twice must be a safe no-op.
 */
declare(strict_types=1);
t_group('payment settlement (purpose-aware, idempotent)');

// ── Booking fee: stamps paid time, does NOT mark the application paid ──
$cid = mam_db_insert('customers', ['email' => 't_set_book@x.dev', 'first_name' => 'Book', 'last_name' => 'Fee']);
$aid = mam_db_insert('applications', ['reference' => 'MAM-SET-BK', 'customer_id' => $cid, 'pathway' => 'standard', 'mode' => 'online', 'status' => 'submitted', 'total_usd' => 300]);
$pid = mam_db_insert('payments', ['application_id' => $aid, 'reference' => 'MAM-SET-BK-BK', 'provider' => 'paystack', 'purpose' => 'booking_fee', 'status' => 'pending', 'amount' => 7500, 'currency' => 'NGN']);
$pay = mam_db_row('SELECT * FROM payments WHERE id = ?', [$pid]);

$res = mam_payment_settle($pay, ['x' => 1], '{"x":1}', 'PSK-1');
t_ok($res['ok'] && !$res['already'], 'booking-fee settle succeeds');
t_eq(mam_db_row('SELECT status FROM payments WHERE id = ?', [$pid])['status'], 'succeeded', 'payment marked succeeded');
t_ok(mam_db_row('SELECT booking_fee_paid_at FROM applications WHERE id = ?', [$aid])['booking_fee_paid_at'] !== null, 'booking_fee_paid_at stamped');
t_eq(mam_db_row('SELECT status FROM applications WHERE id = ?', [$aid])['status'], 'submitted', 'a booking fee does NOT mark the application paid (the bug fix)');

// Idempotent / race-safe: settling again is a no-op.
$again = mam_payment_settle(mam_db_row('SELECT * FROM payments WHERE id = ?', [$pid]), null, '{}', 'PSK-1');
t_ok($again['already'] === true, 're-settling an already-succeeded payment is a safe no-op');

// ── Tuition: marks paid + provisions the student portal ──
$cid2 = mam_db_insert('customers', ['email' => 't_set_tui@x.dev', 'first_name' => 'Tui', 'last_name' => 'Shun']);
$aid2 = mam_db_insert('applications', ['reference' => 'MAM-SET-TU', 'customer_id' => $cid2, 'pathway' => 'standard', 'status' => 'submitted', 'total_usd' => 300]);
$pid2 = mam_db_insert('payments', ['application_id' => $aid2, 'reference' => 'MAM-SET-TU', 'provider' => 'paystack', 'purpose' => 'tuition', 'status' => 'pending', 'amount' => 450000, 'currency' => 'NGN']);
$res2 = mam_payment_settle(mam_db_row('SELECT * FROM payments WHERE id = ?', [$pid2]), null, '{}', 'PSK-2');
t_eq(mam_db_row('SELECT status FROM applications WHERE id = ?', [$aid2])['status'], 'paid', 'a tuition payment marks the application paid');
// `portal_invite_emailed`, not `..._credentials_emailed`: settlement now sends a
// LINK to choose a password rather than a temporary password in an email.
// `portal_invite_failed` is a legitimate outcome here — the harness has no mailer
// — and is deliberately distinguishable from "already provisioned", because the
// two need different follow-up.
t_ok(in_array($res2['portal'], ['portal_invite_emailed', 'portal_invite_failed', 'portal_already_provisioned'], true),
    'tuition settle reports a portal outcome [' . (string) $res2['portal'] . ']');
t_ok(mam_db_row("SELECT id FROM users WHERE email = ?", ['t_set_tui@x.dev']) !== null, 'a student portal account was provisioned');

// ── Store: marks the order delivered + decrements stock ──
$sku = 'TST-SET-' . substr(bin2hex(random_bytes(3)), 0, 5);
mam_db_insert('store_items', ['sku' => $sku, 'slug' => $sku, 'name' => 'Test Score', 'goods_type' => 'digital', 'delivery_type' => 'download', 'availability' => 'in_stock', 'stock' => 5, 'price_usd' => 10, 'currency' => 'USD', 'file_url' => 'https://x/score.pdf', 'active' => 1]);
$items = json_encode([['sku' => $sku, 'name' => 'Test Score', 'qty' => 2, 'unit_usd' => 10, 'line_total' => 20, 'goods_type' => 'digital']]);
$oid = mam_db_insert('store_orders', ['reference' => 'ORD-SET-1', 'customer_email' => 'buyer@x.dev', 'customer_name' => 'Buyer', 'items_json' => $items, 'subtotal_usd' => 20, 'total_usd' => 20, 'status' => 'pending']);
$pid3 = mam_db_insert('payments', ['application_id' => null, 'reference' => 'ORD-SET-1', 'provider' => 'paystack', 'purpose' => 'store', 'status' => 'pending', 'amount' => 30000, 'currency' => 'NGN']);
mam_payment_settle(mam_db_row('SELECT * FROM payments WHERE id = ?', [$pid3]), null, '{}', 'PSK-3');
t_eq(mam_db_row('SELECT status FROM store_orders WHERE id = ?', [$oid])['status'], 'delivered', 'a digital store order is delivered on settle');
t_eq((int)mam_db_row('SELECT stock FROM store_items WHERE sku = ?', [$sku])['stock'], 3, 'store stock decremented by the ordered qty (5 - 2)');

// ── Store: an UNLIMITED (stock = -1) physical line must settle 'paid', never
//    'oversold', and must NOT be decremented. Regression: the oversold guard
//    used `stock >= 0`, so every unlimited physical order came up 0-rows and was
//    wrongly flagged oversold — paid but never fulfilled. ──
$skuU = 'TST-UNL-' . substr(bin2hex(random_bytes(3)), 0, 5);
mam_db_insert('store_items', ['sku' => $skuU, 'slug' => $skuU, 'name' => 'Unlimited Tee', 'goods_type' => 'physical', 'delivery_type' => 'shipping', 'availability' => 'in_stock', 'stock' => -1, 'price_usd' => 25, 'currency' => 'USD', 'active' => 1]);
$itemsU = json_encode([['sku' => $skuU, 'name' => 'Unlimited Tee', 'qty' => 3, 'unit_usd' => 25, 'line_total' => 75, 'goods_type' => 'physical']]);
$oidU = mam_db_insert('store_orders', ['reference' => 'ORD-SET-UNL', 'customer_email' => 'buyer2@x.dev', 'customer_name' => 'Buyer Two', 'shipping_address' => '1 Test St', 'items_json' => $itemsU, 'subtotal_usd' => 75, 'total_usd' => 75, 'status' => 'pending']);
$pidU = mam_db_insert('payments', ['application_id' => null, 'reference' => 'ORD-SET-UNL', 'provider' => 'paystack', 'purpose' => 'store', 'status' => 'pending', 'amount' => 112500, 'currency' => 'NGN']);
mam_payment_settle(mam_db_row('SELECT * FROM payments WHERE id = ?', [$pidU]), null, '{}', 'PSK-U');
t_eq(mam_db_row('SELECT status FROM store_orders WHERE id = ?', [$oidU])['status'], 'paid', 'an unlimited-stock physical order settles to paid (not oversold)');
t_eq((int)mam_db_row('SELECT stock FROM store_items WHERE sku = ?', [$skuU])['stock'], -1, 'unlimited stock (-1) is never decremented');

// ── Store: a FINITE physical line short on stock is still flagged 'oversold'
//    (the guard must keep protecting genuine short-stock orders). ──
$skuO = 'TST-OVS-' . substr(bin2hex(random_bytes(3)), 0, 5);
mam_db_insert('store_items', ['sku' => $skuO, 'slug' => $skuO, 'name' => 'Rare Vinyl', 'goods_type' => 'physical', 'delivery_type' => 'shipping', 'availability' => 'in_stock', 'stock' => 1, 'price_usd' => 40, 'currency' => 'USD', 'active' => 1]);
$itemsO = json_encode([['sku' => $skuO, 'name' => 'Rare Vinyl', 'qty' => 2, 'unit_usd' => 40, 'line_total' => 80, 'goods_type' => 'physical']]);
$oidO = mam_db_insert('store_orders', ['reference' => 'ORD-SET-OVS', 'customer_email' => 'buyer3@x.dev', 'customer_name' => 'Buyer Three', 'shipping_address' => '2 Test St', 'items_json' => $itemsO, 'subtotal_usd' => 80, 'total_usd' => 80, 'status' => 'pending']);
$pidO = mam_db_insert('payments', ['application_id' => null, 'reference' => 'ORD-SET-OVS', 'provider' => 'paystack', 'purpose' => 'store', 'status' => 'pending', 'amount' => 120000, 'currency' => 'NGN']);
mam_payment_settle(mam_db_row('SELECT * FROM payments WHERE id = ?', [$pidO]), null, '{}', 'PSK-O');
t_eq(mam_db_row('SELECT status FROM store_orders WHERE id = ?', [$oidO])['status'], 'oversold', 'a finite physical line short on stock is still flagged oversold');
t_eq((int)mam_db_row('SELECT stock FROM store_items WHERE sku = ?', [$skuO])['stock'], 1, 'an oversold finite line is not decremented below its real stock');

// ── Defensive guards: the settle helpers no-op (no crash) on a missing subject ──
t_ok(mam_payment_settle_tuition(0) === null, 'tuition settle with no application id is a safe no-op');
mam_payment_settle_booking(0); // must not throw
mam_payment_settle_store('NOPE-NO-ORDER'); // must not throw
t_ok(true, 'booking/store settle helpers no-op safely on a missing subject');

// ── Gated tuition lookup (for the /tuition page) ──
$cidT = mam_db_insert('customers', ['email' => 'tu_look@x.dev', 'first_name' => 'Tee', 'last_name' => 'You']);
$aidT = mam_db_insert('applications', ['reference' => 'MAM-TU-LK', 'customer_id' => $cidT, 'pathway' => 'certified-musician', 'status' => 'submitted', 'total_usd' => 230]);
t_ok(mam_tuition_status('MAM-TU-LK', 'nobody@x.dev') === null, 'tuition lookup with a mismatched email is null (not enumerable)');
t_ok(mam_tuition_status('MAM-NOPE', 'tu_look@x.dev') === null, 'tuition lookup with an unknown reference is null');
t_ok(mam_tuition_status('MAM-TU-LK', 'not-an-email') === null, 'tuition lookup with a bad email is null');
$ts = mam_tuition_status('MAM-TU-LK', 'TU_LOOK@x.dev'); // case-insensitive
t_ok(is_array($ts) && $ts['found'] === true, 'tuition lookup found with matching reference + email (case-insensitive)');
t_eq((int)$ts['amount_usd'], 230, 'payable tuition is the application total_usd');
t_ok($ts['payable'] === true && $ts['already_paid'] === false, 'an unpaid application is payable');
// A succeeded tuition payment flips it to already-paid.
mam_db_insert('payments', ['application_id' => $aidT, 'reference' => 'MAM-TU-LK', 'provider' => 'paystack', 'purpose' => 'tuition', 'status' => 'succeeded', 'amount' => 345000, 'currency' => 'NGN']);
$ts2 = mam_tuition_status('MAM-TU-LK', 'tu_look@x.dev');
t_ok($ts2['already_paid'] === true && $ts2['payable'] === false, 'a succeeded tuition payment marks it already-paid (not payable)');
