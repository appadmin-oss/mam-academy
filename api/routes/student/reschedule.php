<?php
/**
 * Student paid-rescheduling endpoint (LMS 2.0 Part C).
 *
 *   GET  /api/student/reschedule                     → {enabled, config, requests:[…]}
 *   GET  /api/student/reschedule?action=quote&cohort_id=&count=
 *                                                    → server-priced quote
 *   POST /api/student/reschedule {action:'quote',  cohort_id, session_ids:[…]}
 *   POST /api/student/reschedule {action:'create', cohort_id, moves:[{session_id,new_scheduled_at}], reason?}
 *   POST /api/student/reschedule {action:'pay',    request_id}   (settle from wallet)
 *   POST /api/student/reschedule {action:'cancel', request_id}
 *
 * All pricing and validation are server-authoritative (see api/modules/academics/lib/reschedule.php);
 * the client never supplies a fee. Feature-gated behind reschedule_enabled.
 */
declare(strict_types=1);

$u   = mam_require_user();
$uid = (int) $u['id'];

if (!mam_reschedule_enabled()) mam_fail('reschedule_disabled', 404);

/** Map a domain RuntimeException code to an HTTP status. */
$statusFor = function (string $code): int {
    switch ($code) {
        case 'validation': case 'no_sessions': case 'new_time_in_past': return 422;
        case 'forbidden': case 'not_enrolled':                          return 403;
        case 'not_found': case 'session_not_found':                     return 404;
        case 'insufficient_credit':                                     return 402;
        case 'reschedule_disabled':                                     return 404;
        case 'limit_exceeded': case 'window_closed': case 'expired':
        case 'illegal_transition': case 'nothing_to_pay':
        case 'session_not_scheduled': case 'session_cohort_mismatch':   return 409;
        default:                                                        return 400;
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string) ($_GET['action'] ?? '');
    if ($action === 'quote') {
        $cohortId = (int) ($_GET['cohort_id'] ?? 0);
        $count    = max(1, (int) ($_GET['count'] ?? 1));
        if ($cohortId < 1) mam_fail('validation', 422, ['fields' => ['cohort_id']]);
        mam_ok(['quote' => mam_reschedule_quote($uid, $cohortId, $count)]);
    }
    // Default: the student's own requests (with items) + resolved config.
    $reqs = mam_reschedule_list_for_student($uid);
    foreach ($reqs as &$rq) {
        $rq['items'] = mam_db_all('SELECT * FROM reschedule_items WHERE request_id = ? ORDER BY id', [(int) $rq['id']]);
    }
    unset($rq);
    $cfg = mam_reschedule_config();
    mam_ok([
        'enabled'  => true,
        'config'   => [
            'fee_usd'       => $cfg['fee_usd'],
            'fee_mode'      => $cfg['fee_mode'],
            'free_per_term' => $cfg['free_per_term'],
            'max_per_term'  => $cfg['max_per_term'],
            'window_hours'  => $cfg['window_hours'],
        ],
        'requests' => $reqs,
    ]);
}

// ── POST ─────────────────────────────────────────────────────────────────
$in     = mam_json_input();
$action = (string) ($in['action'] ?? '');

try {
    if ($action === 'quote') {
        $cohortId = (int) ($in['cohort_id'] ?? 0);
        $ids      = is_array($in['session_ids'] ?? null) ? $in['session_ids'] : [];
        if ($cohortId < 1) mam_fail('validation', 422, ['fields' => ['cohort_id']]);
        mam_ok(['quote' => mam_reschedule_quote($uid, $cohortId, max(1, count($ids)))]);
    }

    if ($action === 'create') {
        $cohortId = (int) ($in['cohort_id'] ?? 0);
        $moves    = is_array($in['moves'] ?? null) ? $in['moves'] : [];
        $reason   = isset($in['reason']) ? trim((string) $in['reason']) : null;
        if ($cohortId < 1 || !$moves) mam_fail('validation', 422, ['fields' => ['cohort_id', 'moves']]);
        $req = mam_reschedule_create($uid, $cohortId, $moves, $reason);
        mam_ok(['request' => $req], 201);
    }

    if ($action === 'pay') {
        $rid = (int) ($in['request_id'] ?? 0);
        if ($rid < 1) mam_fail('validation', 422, ['fields' => ['request_id']]);
        mam_ok(['request' => mam_reschedule_pay_wallet($rid, $uid)]);
    }

    if ($action === 'cancel') {
        $rid = (int) ($in['request_id'] ?? 0);
        if ($rid < 1) mam_fail('validation', 422, ['fields' => ['request_id']]);
        mam_ok(['request' => mam_reschedule_cancel($rid, $uid)]);
    }

    mam_fail('unknown_action', 400, ['action' => $action]);
} catch (\RuntimeException $e) {
    $code = $e->getMessage();
    mam_fail($code, $statusFor($code));
}
