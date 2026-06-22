<?php
/**
 * POST /api/admin/wallet-credit  {user_id, amount_usd, note?}
 * Issue (or adjust) a student's internal store credit. Positive amount_usd adds
 * credit; negative deducts it (never below zero — server-validated). Audited.
 * Admin-issued only — this is the sole way credits enter the system.
 */
declare(strict_types=1);

$me  = mam_current_user();
$in  = mam_json_input();
$uid = (int)($in['user_id'] ?? 0);
$amountUsd = (float)($in['amount_usd'] ?? 0);
$note = mam_str($in, 'note', 255) ?: null;

if ($uid <= 0) mam_fail('validation', 422, ['fields' => ['user_id']]);
if (!is_finite($amountUsd) || $amountUsd === 0.0) mam_fail('validation', 422, ['fields' => ['amount_usd'], 'detail' => 'Amount must be a non-zero number of US dollars.']);
if (abs($amountUsd) > 100000) mam_fail('amount_too_large', 422);

$target = mam_db_row('SELECT id, role FROM users WHERE id = ?', [$uid]);
if (!$target) mam_fail('user_not_found', 404);
if (!mam_user_has_role($target, 'student')) mam_fail('not_a_student', 422, ['detail' => 'Wallet credit applies to students.']);

$cents  = (int) round($amountUsd * 100);
$reason = $cents >= 0 ? 'admin_credit' : 'admin_debit';

try {
    $balance = mam_wallet_post($uid, $cents, $reason, $me ? (int)$me['id'] : null, null, $note);
} catch (\RuntimeException $e) {
    if ($e->getMessage() === 'insufficient_credit') mam_fail('insufficient_credit', 409, ['detail' => 'That deduction would overdraw the wallet.']);
    throw $e;
}

mam_ok(['ok' => true, 'balance_cents' => $balance, 'balance_usd' => round($balance / 100, 2)]);
