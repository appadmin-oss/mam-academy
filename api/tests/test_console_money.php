<?php
/**
 * Refunds and bank-transfer confirmation in the console (HANDOFF §4y — these
 * buttons went with the retired dashboard). The handlers in admin/index.php
 * already existed; what is pinned here is the ONE decision both use
 * (mam_payment_admin_refusal) and that the Ledger screen reaches them.
 */
declare(strict_types=1);

t_group('money — what an admin may refund or confirm');

$CM = static fn(string $st) => ['status' => $st];
t_eq(mam_payment_admin_refusal($CM('succeeded'), 'payment_refund', 'Returned by transfer, ref 123'), null, 'money that was taken can be marked refunded');
t_ok(mam_payment_admin_refusal($CM('pending'), 'payment_refund', 'x refund') !== null, 'a charge that never went through cannot be "refunded"');
t_ok(mam_payment_admin_refusal($CM('refunded'), 'payment_refund', 'again please') !== null, 'nor refunded twice');
t_ok(mam_payment_admin_refusal($CM('succeeded'), 'payment_refund', '') !== null, 'a refund needs a reason — the audit row is the only record of why');

t_eq(mam_payment_admin_refusal($CM('pending_bank_transfer'), 'payment_confirm_bank', 'GTB 0042', true), null, 'a waiting transfer, with the statement reference and the tick, is confirmed');
t_ok(mam_payment_admin_refusal($CM('pending_bank_transfer'), 'payment_confirm_bank', 'GTB 0042', false) !== null, 'not without the tick that the money was seen');
t_ok(mam_payment_admin_refusal($CM('pending_bank_transfer'), 'payment_confirm_bank', '', true) !== null, 'nor without the reference');
t_ok(mam_payment_admin_refusal($CM('refunded'), 'payment_confirm_bank', 'GTB 0042', true) !== null,
    'a REFUNDED payment cannot be confirmed — the old handler flipped any unconfirmed row to succeeded and re-credited money already returned');
t_ok(mam_payment_admin_refusal($CM('failed'), 'payment_confirm_bank', 'GTB 0042', true) !== null, 'nor a failed one');

t_group('money — the console reaches the existing handlers');

$CM_adm = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
t_eq(substr_count($CM_adm, 'mam_payment_admin_refusal('), 2, 'both handlers ask the one decision');
$CM_led = (string) file_get_contents(dirname(__DIR__, 2) . '/partials/admin-console/ledger.php');
t_ok(str_contains($CM_led, "ac_form('payment_confirm_bank', 'ledger'") && str_contains($CM_led, "ac_form('payment_refund', 'ledger'"),
    'the Ledger screen posts to the existing actions, never a second implementation');
t_ok(str_contains($CM_led, "mam_admin_can(\$adminUser, 'settings')") && str_contains($CM_adm, "'payment_refund' => 'settings', 'payment_confirm_bank' => 'settings'"),
    'the forms are offered under the same capability the handlers demand');
t_ok(!str_contains($CM_led, "=== 'success'"), 'the ledger reads the status the gateway writes (`succeeded`), never `success`');
$CM_d = mam_console_ledger();
t_ok(array_key_exists('awaiting', $CM_d), 'the read model lists every waiting transfer, not only the latest sixty rows');
