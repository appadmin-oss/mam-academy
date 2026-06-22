<?php
/**
 * GET /api/student/wallet — the signed-in student's store-credit balance and
 * transaction history. Read-only; balances are mutated only by server-side
 * ledger writes (admin credit, store redemption).
 */
declare(strict_types=1);

$u  = mam_require_user();
$id = (int)$u['id'];

$balance = mam_wallet_balance($id);
$rows = array_map(function (array $r): array {
    return [
        'id'           => (int)$r['id'],
        'amount_cents' => (int)$r['amount_cents'],
        'amount_usd'   => round((int)$r['amount_cents'] / 100, 2),
        'reason'       => (string)$r['reason'],
        'label'        => mam_wallet_reason_label((string)$r['reason']),
        'reference'    => $r['reference'],
        'note'         => $r['note'],
        'balance_usd'  => round((int)$r['balance_after_cents'] / 100, 2),
        'created_at'   => $r['created_at'],
    ];
}, mam_wallet_history($id, 100));

mam_ok([
    'balance_cents' => $balance,
    'balance_usd'   => round($balance / 100, 2),
    'transactions'  => $rows,
]);
