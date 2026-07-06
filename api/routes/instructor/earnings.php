<?php
/**
 * GET /api/instructor/earnings
 * The instructor's earnings wallet — balance + ledger. Earnings are recorded by
 * the academy admin (there is no self top-up); this is a read-only statement.
 */
declare(strict_types=1);
$u = mam_require_role('instructor', 'admin');

$cents = mam_wallet_balance((int)$u['id']);
$rows  = mam_wallet_history((int)$u['id'], 100);
foreach ($rows as &$r) { $r['reason_label'] = mam_wallet_reason_label((string)$r['reason']); }
unset($r);

mam_ok([
    'balance_cents' => $cents,
    'balance_usd'   => round($cents / 100, 2),
    'ledger'        => $rows,
]);
