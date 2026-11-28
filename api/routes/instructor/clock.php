<?php
/**
 * Instructor clock-in/out + meeting view (LMS 2.0, PDF §10-11, 14).
 *
 *   GET  /api/instructor/clock?session_id=N       → {session, jitsi_url, instructors, checkins, reconciliation}
 *   POST /api/instructor/clock {action:'in'|'out', session_id}
 *
 * A late clock-in feeds the accountability ledger (§14). The Jitsi room link is
 * generated server-side; the portal clock-in is the official attendance record.
 */
declare(strict_types=1);

$u       = mam_require_role('instructor', 'admin');
$me      = (int) $u['id'];
$isAdmin = mam_user_has_role($u, 'admin');

$ownsSession = function (int $sessionId) use ($me, $isAdmin): bool {
    if (!mam_db_row('SELECT 1 FROM class_sessions WHERE id = ?', [$sessionId])) return false;
    // The class's teachers and the cover booked for this session (teaching.php);
    // a cover could not clock in for the session they were brought in to teach.
    return $isAdmin || mam_teaches_session($me, $sessionId);
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sid = (int) ($_GET['session_id'] ?? 0);
    if ($sid < 1) mam_fail('validation', 422, ['fields' => ['session_id']]);
    if (!$ownsSession($sid)) mam_fail('forbidden', 403);
    try { mam_ok(mam_meeting_session_view($sid)); }
    catch (\RuntimeException $e) { mam_fail($e->getMessage(), 404); }
}

$in     = mam_json_input();
$action = (string) ($in['action'] ?? '');
$sid    = (int) ($in['session_id'] ?? 0);
if ($sid < 1) mam_fail('validation', 422, ['fields' => ['session_id']]);
if (!$ownsSession($sid)) mam_fail('forbidden', 403);

try {
    if ($action === 'in')  mam_ok(['clock' => mam_meeting_clock_in($sid, $me)]);
    if ($action === 'out') mam_ok(['clock' => mam_meeting_clock_out($sid, $me)]);
    mam_fail('unknown_action', 400, ['action' => $action]);
} catch (\RuntimeException $e) {
    $code = $e->getMessage();
    mam_fail($code, $code === 'session_not_found' ? 404 : ($code === 'not_clocked_in' ? 409 : 400));
}
