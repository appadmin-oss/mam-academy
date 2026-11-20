<?php
/**
 * GET /api/parent/collectors?student_id=N — who may collect this child
 * (safeguarding.php, v104). READ-ONLY: a family asks the academy to change it,
 * because a list a guardian could edit from a phone is a list anybody holding
 * that phone could edit. Standard and Full access (the 'attendance' grant —
 * the same people who see whether the child was there); never Billing only.
 */
declare(strict_types=1);
$u   = mam_require_role('parent');
$sid = (int) ($_GET['student_id'] ?? 0);
if ($sid < 1) mam_fail('validation', 422, ['fields' => ['student_id']]);
if (!mam_parent_scope_allows((int) $u['id'], $sid, 'attendance')) mam_fail('forbidden', 403);
mam_ok(['collectors' => array_map(static fn ($c) => ['name' => (string) $c['name'], 'relation' => (string) ($c['relation'] ?? ''), 'phone' => (string) ($c['phone'] ?? '')],
    mam_collectors_list($sid)), 'max' => MAM_COLLECTORS_MAX]);
