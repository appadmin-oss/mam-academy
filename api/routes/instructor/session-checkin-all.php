<?php
/**
 * /api/instructor/session-checkin-all  — the group ("general") check-in.
 *
 *   POST {session_id, status?='present', overwrite?=false, notes?}
 *     → {marked, skipped, failed, roster}
 *
 * The summer camp does not scan a card per child: a group arrives together, the
 * instructor marks the session in one action, then corrects the exceptions. The
 * same button is useful for any class where a register is faster than a queue.
 *
 * Ownership is checked exactly as session-attendance.php checks it — an
 * instructor may only mark a cohort they teach, admins bypass. A bulk endpoint
 * with a weaker gate than its single-row sibling would be the way around that
 * sibling.
 */
declare(strict_types=1);

$u       = mam_require_role('instructor', 'admin');
$me      = (int) $u['id'];
$isAdmin = function_exists('mam_user_has_role') ? mam_user_has_role($u, 'admin') : ($u['role'] === 'admin');

$in   = mam_json_input();
$sid  = (int) ($in['session_id'] ?? 0);
$sess = $sid > 0 ? mam_lms_session($sid) : null;
if (!$sess) mam_fail('session_not_found', 404);
if (!$isAdmin && !mam_lms_instructor_owns_cohort($me, (int) $sess['cohort_id'])) mam_fail('forbidden', 403);

$status = strtolower(trim((string) ($in['status'] ?? 'present')));
// `absent` is deliberately allowed: marking a cancelled-at-the-door session is a
// real thing an instructor needs. `overwrite` is not the default — an instructor
// who already recorded somebody late must not lose it to a later group press.
$overwrite = !empty($in['overwrite']);

try {
    $res = mam_lms_mark_attendance_all($sid, $status, $me, !$overwrite,
        isset($in['notes']) ? (string) $in['notes'] : null);
} catch (\Throwable $e) {
    $code = $e->getMessage();
    mam_fail(in_array($code, ['invalid_status', 'session_not_found'], true) ? $code : 'mark_failed',
        $code === 'session_not_found' ? 404 : 422, ['detail' => $code]);
}

mam_ok([
    'session_id' => $sid,
    'status'     => $status,
    'marked'     => $res['marked'],
    'skipped'    => $res['skipped'],
    'roster'     => $res['roster'],
    // Named, not just counted: "three failed" is not actionable and the whole
    // point of not aborting is that the instructor can fix the three.
    'failed'     => $res['failed'],
    'summary'    => mam_lms_session_attendance_summary($sid),
]);
