<?php
/**
 * GET /api/parent/reports?student_id=N — the RATIFIED termly reports for a
 * linked child (guardian redesign §3.3).
 *
 * The design writes this endpoint as `/api/guardian/reports`. It lives under
 * `/parent` because that is this repo's guardian namespace and role — a parallel
 * `/guardian` group would mean two middleware stacks to keep in step for one
 * audience, and `mw_role('parent')` is the check either way.
 *
 * Draft, moderating and withdrawn reports are not reachable here. That is the
 * whole point of the subsystem: a provisional mark a family can see and then have
 * overturned is worse than one that arrives a week late.
 */
declare(strict_types=1);
$u   = mam_require_role('parent');
$sid = (int) ($_GET['student_id'] ?? 0);
if ($sid < 1) mam_fail('validation', 422, ['fields' => ['student_id']]);
try {
    mam_ok(mam_report_for_guardian((int) $u['id'], $sid));
} catch (\RuntimeException $e) {
    mam_fail($e->getMessage(), $e->getMessage() === 'forbidden' ? 403 : 404);
}
