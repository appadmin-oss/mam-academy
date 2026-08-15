<?php
/**
 * The signed-in student's punctuality ledger (PDF §13, §15, §37).
 *
 *   GET  → {ledger:{…}, exception_reasons:{code:label}}
 *   POST {action:'request_exception', charge_id, reason_code, detail?}
 *
 * §15 says an instructor OR an administrator may raise an exception; a student
 * may raise one against their OWN charge, which is checked here — the lib does
 * not know who is asking, so ownership is enforced at the edge.
 */
declare(strict_types=1);

$u   = mam_require_user();
$uid = (int) $u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    mam_ok([
        'ledger'            => mam_accountability_ledger('student', $uid),
        'exception_reasons' => MAM_ACCT_EXCEPTION_REASONS,
    ]);
}

$in     = mam_json_input();
$action = (string) ($in['action'] ?? '');
if ($action !== 'request_exception') mam_fail('unknown_action', 400, ['action' => $action]);

$chargeId = (int) ($in['charge_id'] ?? 0);
$reason   = (string) ($in['reason_code'] ?? '');
$detail   = isset($in['detail']) ? trim((string) $in['detail']) : null;
if ($chargeId < 1) mam_fail('missing_field', 400, ['field' => 'charge_id']);

$charge = mam_db_row('SELECT subject_type, subject_id FROM accountability_charges WHERE id = ?', [$chargeId]);
if (!$charge) mam_fail('not_found', 404);
if ((string) $charge['subject_type'] !== 'student' || (int) $charge['subject_id'] !== $uid) mam_fail('forbidden', 403);

try {
    $res = mam_accountability_request_exception($chargeId, $uid, $reason, $detail);
    mam_ok(['request' => $res['request'], 'already' => $res['already'], 'ledger' => mam_accountability_ledger('student', $uid)]);
} catch (\InvalidArgumentException $e) {
    mam_fail($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    mam_fail($e->getMessage(), 409);
}
