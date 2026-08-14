<?php
/**
 * Instructor/admin reschedule decision queue (LMS 2.0 Part C).
 *
 *   GET  /api/instructor/reschedule[?status=paid]      → {requests:[…]} awaiting a decision
 *   POST /api/instructor/reschedule {action:'approve', request_id, note?}  → approves + applies
 *   POST /api/instructor/reschedule {action:'decline', request_id, note?}  → declines (+ refund if paid)
 *
 * An instructor may only act on requests for cohorts they teach; an admin may
 * act on any. Approval applies the moves through the existing audited session
 * writer. Feature-gated behind reschedule_enabled.
 */
declare(strict_types=1);

$u    = mam_require_user();
$me   = (int) $u['id'];
$isAdmin = function_exists('mam_user_has_role') && mam_user_has_role($u, 'admin');

if (!mam_reschedule_enabled()) mam_fail('reschedule_disabled', 404);

$statusFor = function (string $code): int {
    switch ($code) {
        case 'forbidden':                        return 403;
        case 'not_found':                        return 404;
        case 'illegal_transition':               return 409;
        case 'validation':                       return 422;
        case 'reschedule_disabled':              return 404;
        default:                                 return 400;
    }
};

/** The request must belong to a cohort this user may act on. */
$assertOwnership = function (int $requestId) use ($me, $isAdmin): void {
    $row = mam_db_row(
        'SELECT c.instructor_id FROM reschedule_requests rr JOIN cohorts c ON c.id = rr.cohort_id WHERE rr.id = ?',
        [$requestId]
    );
    if (!$row) mam_fail('not_found', 404);
    if (!$isAdmin && (int) ($row['instructor_id'] ?? 0) !== $me) mam_fail('forbidden', 403);
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = in_array($_GET['status'] ?? '', ['paid', 'approved', 'applied', 'declined'], true)
        ? (string) $_GET['status'] : 'paid';
    if ($isAdmin) {
        $reqs = mam_db_all(
            "SELECT rr.*, u.name AS requester_name, c.title AS cohort_title
               FROM reschedule_requests rr
               JOIN cohorts c ON c.id = rr.cohort_id
               LEFT JOIN users u ON u.id = rr.requester_id
              WHERE rr.status = ? ORDER BY rr.id DESC",
            [$status]
        );
    } else {
        $reqs = mam_reschedule_list_for_instructor($me, $status);
    }
    foreach ($reqs as &$rq) {
        $rq['items'] = mam_db_all('SELECT * FROM reschedule_items WHERE request_id = ? ORDER BY id', [(int) $rq['id']]);
    }
    unset($rq);
    mam_ok(['requests' => $reqs, 'status' => $status]);
}

// ── POST ─────────────────────────────────────────────────────────────────
$in     = mam_json_input();
$action = (string) ($in['action'] ?? '');
$rid    = (int) ($in['request_id'] ?? 0);
$note   = isset($in['note']) ? trim((string) $in['note']) : null;
if ($rid < 1) mam_fail('validation', 422, ['fields' => ['request_id']]);
if (!in_array($action, ['approve', 'decline'], true)) mam_fail('unknown_action', 400, ['action' => $action]);

$assertOwnership($rid);

try {
    if ($action === 'approve') {
        mam_ok(['request' => mam_reschedule_approve($rid, $me, $note)]);
    }
    mam_ok(['request' => mam_reschedule_decline($rid, $me, $note)]);
} catch (\RuntimeException $e) {
    $code = $e->getMessage();
    mam_fail($code, $statusFor($code));
}
