<?php
/**
 * Admin accountability-ledger management (PDF §15 exception decisions, §37).
 *
 *   GET  ?status=pending|outstanding|paid|waived|disputed|reversed
 *        → {charges:[…], exceptions:[…], counts:{…}}
 *   POST {action:'waive'|'paid'|'reversed'|'disputed', charge_id, note?}
 *   POST {action:'approve_exception'|'reject_exception', request_id, note?}
 *
 * Approving an exception waives the charge; rejecting restores it to
 * `outstanding` — §15's "Rejected — Charge Maintained". Every decision is
 * audited, and the subject is notified either way.
 */
declare(strict_types=1);

// Read access is authorised by mw_admin_cap() on the route. The WRITE path
// below additionally needs a named actor, so it calls
// mam_admin_require_actor() at the point it starts mutating.

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = in_array($_GET['status'] ?? '', MAM_ACCT_STATUSES, true)
        ? (string) $_GET['status'] : 'outstanding';
    $counts = [];
    foreach (MAM_ACCT_STATUSES as $s) {
        $counts[$s] = (int) (mam_db_row(
            'SELECT COUNT(*) AS c FROM accountability_charges WHERE status = ?', [$s]
        )['c'] ?? 0);
    }
    mam_ok([
        'charges'    => mam_accountability_list($status),
        'exceptions' => mam_accountability_exception_queue(),
        'reasons'    => MAM_ACCT_EXCEPTION_REASONS,
        'statuses'   => MAM_ACCT_STATUSES,
        'status'     => $status,
        'counts'     => $counts,
    ]);
}

$u = mam_admin_require_actor();   // writes record who performed them

$in     = mam_json_input();
$action = (string) ($in['action'] ?? '');
$note   = isset($in['note']) ? trim((string) $in['note']) : null;

try {
    if ($action === 'approve_exception' || $action === 'reject_exception') {
        $requestId = (int) ($in['request_id'] ?? 0);
        if ($requestId < 1) mam_fail('missing_field', 400, ['field' => 'request_id']);
        $res = mam_accountability_decide_exception($requestId, (int) $u['id'], $action === 'approve_exception', $note);
        mam_ok($res);
    }

    if (!in_array($action, ['waive', 'paid', 'reversed', 'disputed'], true)) {
        mam_fail('unknown_action', 400, ['action' => $action]);
    }
    $chargeId = (int) ($in['charge_id'] ?? 0);
    if ($chargeId < 1) mam_fail('missing_field', 400, ['field' => 'charge_id']);
    $status = $action === 'waive' ? 'waived' : $action;
    mam_ok(['charge' => mam_accountability_resolve($chargeId, (int) $u['id'], $status, $note)]);
} catch (\InvalidArgumentException $e) {
    mam_fail($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    mam_fail($e->getMessage(), 409);
}
