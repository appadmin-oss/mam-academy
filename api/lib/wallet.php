<?php
/**
 * Internal wallet (#12) — an append-only credit ledger.
 *
 * Decided scope: credits are INTERNAL value, not real money. They are
 * admin-issued only and spendable on the store only — there is no Paystack/
 * Flutterwave top-up or withdrawal path. Balances are denominated in USD cents
 * (the platform's accounting unit) and shown as a "$X.XX store credit".
 *
 * The ledger is the single source of truth: balance = SUM(amount_cents). Every
 * mutation is a signed row that also records the running balance_after for
 * audit, and a debit can NEVER drive the balance negative (server-validated).
 * Balances are never editable client-side — only through these functions.
 */
declare(strict_types=1);

/** Current balance in cents (source of truth: the ledger sum). */
function mam_wallet_balance(int $userId): int {
    if ($userId <= 0) return 0;
    $r = mam_db_row('SELECT COALESCE(SUM(amount_cents), 0) AS bal FROM wallet_ledger WHERE user_id = ?', [$userId]);
    return (int)($r['bal'] ?? 0);
}

/**
 * Low-level ledger write. Assumes the caller manages any surrounding
 * transaction. Validates that a debit can't overdraw. Returns the new balance.
 * Does NOT audit (callers / mam_wallet_post do).
 */
function mam_wallet_apply(int $userId, int $amountCents, string $reason, ?int $by = null, ?string $ref = null, ?string $note = null): int {
    if ($userId <= 0)       throw new InvalidArgumentException('bad_user');
    if ($amountCents === 0) throw new InvalidArgumentException('zero_amount');
    $after = mam_wallet_balance($userId) + $amountCents;
    if ($after < 0) throw new RuntimeException('insufficient_credit');
    mam_db_insert('wallet_ledger', [
        'user_id'             => $userId,
        'amount_cents'        => $amountCents,
        'reason'              => $reason,
        'reference'           => $ref,
        'note'                => $note,
        'balance_after_cents' => $after,
        'created_by'          => $by,
    ]);
    return $after;
}

/**
 * Post a transaction standalone (admin credit/adjust). Wraps mam_wallet_apply in
 * a transaction when one isn't already open (so it nests safely inside the store
 * redeem flow), and audits. Returns the new balance in cents.
 */
function mam_wallet_post(int $userId, int $amountCents, string $reason, ?int $by = null, ?string $ref = null, ?string $note = null): int {
    $db  = mam_db();
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $after = mam_wallet_apply($userId, $amountCents, $reason, $by, $ref, $note);
        if ($own) $db->commit();
    } catch (\Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    mam_audit('wallet_' . ($amountCents >= 0 ? 'credit' : 'debit'), 'user', $userId, [
        'amount_cents' => $amountCents, 'reason' => $reason, 'ref' => $ref, 'balance_after' => $after,
    ]);
    return $after;
}

/** Recent ledger rows, newest first. */
function mam_wallet_history(int $userId, int $limit = 50): array {
    if ($userId <= 0) return [];
    $limit = max(1, min(200, $limit));
    return mam_db_all(
        'SELECT id, amount_cents, reason, reference, note, balance_after_cents, created_at
           FROM wallet_ledger WHERE user_id = ? ORDER BY id DESC LIMIT ?',
        [$userId, $limit]
    );
}

/** A friendly label for a ledger reason code (UI). */
function mam_wallet_reason_label(string $reason): string {
    static $map = [
        'admin_credit'  => 'Credit issued',
        'admin_debit'   => 'Adjustment',
        'store_redeem'  => 'Store purchase',
        'store_refund'  => 'Store refund',
    ];
    return $map[$reason] ?? ucfirst(str_replace('_', ' ', $reason));
}
