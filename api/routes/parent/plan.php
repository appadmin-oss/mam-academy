<?php
/**
 * POST /api/parent/plan {invoice_id, instalments}
 *
 * "Pay in 3 instalments instead." A guardian REQUESTS; the office approves, so
 * `approved_by` is deliberately left null here — a plan a family can grant itself
 * is a payment term, not a plan.
 */
declare(strict_types=1);
$u  = mam_require_role('parent');
$in = mam_json_input();
$invId = (int) ($in['invoice_id'] ?? 0);
$n     = (int) ($in['instalments'] ?? 3);
if ($invId < 1) mam_fail('validation', 422, ['fields' => ['invoice_id']]);

$inv = mam_db_row('SELECT student_id FROM fee_invoices WHERE id = ?', [$invId]);
if (!$inv) mam_fail('invoice_not_found', 404);
if (!mam_parent_can_view((int) $u['id'], (int) $inv['student_id'])) mam_fail('forbidden', 403);
if (!mam_parent_scope_allows((int) $u['id'], (int) $inv['student_id'], 'payments')) mam_fail('forbidden', 403);

try { mam_ok(['plan' => mam_fee_plan_create($invId, $n, (int) $u['id'], null)]); }
catch (\InvalidArgumentException $e) { mam_fail('validation', 422, ['reason' => $e->getMessage()]); }
catch (\RuntimeException $e) { mam_fail($e->getMessage(), 409); }
