<?php
/**
 * Admin accountability-ledger management (PDF §15 exception decision, §37).
 *
 *   GET  ?status=outstanding|waived|paid|reversed   → {charges:[…], counts:{…}}
 *   POST {action:'waive'|'paid'|'reversed', charge_id, note?}  → {charge:{…}}
 *
 * 'waive' is the §15 exception approval (charge dropped); every decision is
 * audited via mam_accountability_resolve().
 */
declare(strict_types=1);

$u = mam_require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = in_array($_GET['status'] ?? '', ['outstanding', 'waived', 'paid', 'reversed'], true)
        ? (string) $_GET['status'] : 'outstanding';
    $charges = mam_accountability_list($status);
    $counts = ['outstanding' => 0, 'waived' => 0, 'paid' => 0, 'reversed' => 0];
    foreach (mam_accountability_list('outstanding', 5000) as $_c) $counts['outstanding']++;
    mam_ok(['charges' => $charges, 'status' => $status, 'counts' => $counts]);
}

$in       = mam_json_input();
$action   = (string) ($in['action'] ?? '');
$chargeId = (int) ($in['charge_id'] ?? 0);
$note     = isset($in['note']) ? trim((string) $in['note']) : null;

if (!in_array($action, ['waive', 'paid', 'reversed'], true)) mam_fail('unknown_action', 400, ['action' => $action]);
if ($chargeId < 1) mam_fail('missing_field', 400, ['field' => 'charge_id']);

$status = $action === 'waive' ? 'waived' : $action;
try {
    $charge = mam_accountability_resolve($chargeId, (int) $u['id'], $status, $note);
    mam_ok(['charge' => $charge]);
} catch (\InvalidArgumentException $e) {
    mam_fail($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    mam_fail($e->getMessage(), 409);
}
