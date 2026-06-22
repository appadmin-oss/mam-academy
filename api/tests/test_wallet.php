<?php
/** Internal wallet (#12) — ledger balance, server-validated debits, history,
 *  and the store-redemption money move (atomic claim + debit + settle). */
declare(strict_types=1);
t_group('wallet ledger');

$rnd = fn() => bin2hex(random_bytes(3));
$email = 'wallet_' . $rnd() . '@x.dev';
$uid = mam_db_insert('users', ['email' => $email, 'name' => 'Wal Let', 'role' => 'student', 'password_hash' => 'x']);

t_eq(mam_wallet_balance($uid), 0, 'a new wallet starts at zero');

t_eq(mam_wallet_post($uid, 5000, 'admin_credit', null, null, 'welcome'), 5000, 'crediting $50 yields a 5000c balance');
t_eq(mam_wallet_balance($uid), 5000, 'balance reflects the credit');
t_eq(mam_wallet_post($uid, -2000, 'admin_debit', null, null, 'adjust'), 3000, 'debiting $20 yields 3000c');

// A debit can never overdraw — and a failed debit must not move the balance.
$threw = false;
try { mam_wallet_post($uid, -9999, 'admin_debit'); } catch (\RuntimeException $e) { $threw = ($e->getMessage() === 'insufficient_credit'); }
t_ok($threw, 'an overdrawing debit throws insufficient_credit');
t_eq(mam_wallet_balance($uid), 3000, 'a rejected debit leaves the balance untouched');

$zeroThrew = false;
try { mam_wallet_post($uid, 0, 'noop'); } catch (\InvalidArgumentException $e) { $zeroThrew = true; }
t_ok($zeroThrew, 'a zero-amount post is rejected');

$hist = mam_wallet_history($uid, 10);
t_eq(count($hist), 2, 'history records only successful transactions (rejected debits leave no row)');
t_eq((int)$hist[0]['amount_cents'], -2000, 'newest history row is the latest debit');
t_eq((int)$hist[0]['balance_after_cents'], 3000, 'each row stamps the running balance');

t_group('wallet store redemption');
// Fund the wallet and create a pending store order owned by this student.
mam_wallet_post($uid, 5000, 'admin_credit', null, null, 'top-up');   // balance now 8000c
$ref = 'STORE-' . strtoupper($rnd());
$orderId = mam_db_insert('store_orders', [
    'reference' => $ref, 'customer_email' => $email, 'customer_name' => 'Wal Let',
    'items_json' => '[]', 'subtotal_usd' => 25, 'tax_usd' => 0, 'shipping_usd' => 0,
    'total_usd' => 25, 'status' => 'pending',
]);

// Mirror the redeem route's money move: atomic claim → debit → settle.
$claimed = mam_db()->prepare("UPDATE store_orders SET status='paid' WHERE reference=? AND status='pending'");
$claimed->execute([$ref]);
t_eq($claimed->rowCount(), 1, 'the pending→paid claim succeeds exactly once');
mam_wallet_post($uid, -2500, 'store_redeem', $uid, $ref, 'Store order ' . $ref);
mam_payment_settle_store($ref);

t_eq(mam_wallet_balance($uid), 5500, 'redeeming a $25 order debits the wallet to 5500c');
$o = mam_db_row('SELECT status FROM store_orders WHERE reference = ?', [$ref]);
t_eq($o['status'], 'delivered', 'a digital/empty order is delivered after redemption');

// A second claim on the same order finds nothing pending (no double-spend).
$again = mam_db()->prepare("UPDATE store_orders SET status='paid' WHERE reference=? AND status='pending'");
$again->execute([$ref]);
t_eq($again->rowCount(), 0, 'the order can no longer be claimed (double-spend blocked)');
