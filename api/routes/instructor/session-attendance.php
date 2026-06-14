<?php
/**
 * /api/instructor/session-attendance  (LMS L2)
 *   GET  ?session_id=N  → {session, marks:[{student_id,name,status|null}], summary}
 *   POST {session_id, student_id, status, notes?}  → mark one student
 * Instructor/admin only. The session's cohort must be owned (admins bypass).
 */
declare(strict_types=1);

$u       = mam_require_role('instructor', 'admin');
$me      = (int)$u['id'];
$isAdmin = function_exists('mam_user_has_role') ? mam_user_has_role($u, 'admin') : ($u['role'] === 'admin');

$loadOwned = function (int $sessionId) use ($me, $isAdmin) {
    $sess = $sessionId > 0 ? mam_lms_session($sessionId) : null;
    if (!$sess) mam_fail('session_not_found', 404);
    $owns = $isAdmin ? true : mam_lms_instructor_owns_cohort($me, (int)$sess['cohort_id']);
    if (!$owns) mam_fail('forbidden', 403);
    return $sess;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $sid  = (int)($_GET['session_id'] ?? 0);
    $sess = $loadOwned($sid);
    mam_ok([
        'session' => $sess,
        'marks'   => mam_lms_session_mark_sheet($sid, (int)$sess['cohort_id']),
        'summary' => mam_lms_session_attendance_summary($sid),
    ]);
}

$in   = mam_json_input();
$sid  = (int)($in['session_id'] ?? 0);
$sess = $loadOwned($sid);
$studentId = (int)($in['student_id'] ?? 0);
if ($studentId <= 0) mam_fail('validation', 422, ['fields' => ['student_id']]);

try {
    mam_lms_mark_attendance($sid, $studentId, strtolower(trim((string)($in['status'] ?? ''))), $me, isset($in['notes']) ? (string)$in['notes'] : null);
    mam_ok(['marked' => true, 'summary' => mam_lms_session_attendance_summary($sid)]);
} catch (RuntimeException $e) {
    mam_fail($e->getMessage() === 'invalid_status' ? 'invalid_status' : 'mark_failed', 422, ['detail' => $e->getMessage()]);
}
