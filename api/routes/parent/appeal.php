<?php
/**
 * POST /api/parent/appeal {report_id, reason, competency_code?}
 *
 * A guardian appeals a mark. The design gives them three months from
 * ratification (MAM_REPORT_APPEAL_WINDOW_DAYS), adjudicated in Africa/Lagos so a
 * diaspora guardian does not lose a day to their own clock.
 *
 * Rate-limited as a request, not as a failure: an appeal is a costly action to
 * process whether or not it succeeds, which is the case mw_rate_limit() is for.
 */
declare(strict_types=1);
$u  = mam_require_role('parent');
$in = mam_json_input();
$rid = (int) ($in['report_id'] ?? 0);
$reason = trim((string) ($in['reason'] ?? ''));
if ($rid < 1 || $reason === '') mam_fail('validation', 422, ['fields' => ['report_id', 'reason']]);

$code = isset($in['competency_code']) && $in['competency_code'] !== '' ? (string) $in['competency_code'] : null;
try {
    $id = mam_report_appeal_open($rid, (int) $u['id'], 'parent', $reason, $code);
    mam_ok(['appeal_id' => $id, 'status' => 'open']);
} catch (\InvalidArgumentException $e) {
    mam_fail('validation', 422, ['reason' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    $m = $e->getMessage();
    mam_fail($m, $m === 'forbidden' ? 403 : ($m === 'report_not_found' ? 404 : 409));
}
