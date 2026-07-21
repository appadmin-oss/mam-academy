<?php
/**
 * POST /api/attendance/session-checkin  { token }
 *
 * Student self-check-in via the instructor's cohort-session QR. Verifies the
 * signed, day-scoped session token, confirms the caller is enrolled in that
 * session's cohort, and marks them present in session_attendance (idempotent,
 * never downgrades an existing mark). Student-only, CSRF-protected, rate-limited.
 * The cohort-model twin of /api/attendance/checkin.
 */
declare(strict_types=1);

$u  = mam_require_role('student');
$in = mam_json_input();

$tok = mam_attendance_verify_session_token((string)($in['token'] ?? ''));
if (!$tok) mam_fail('invalid_or_expired', 422, ['detail' => 'This check-in code is invalid or has expired.']);

try {
    $res = mam_attendance_session_self_checkin((int)$u['id'], $tok['session_id']);
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
    if ($msg === 'not_enrolled')     mam_fail('not_enrolled', 403, ['detail' => "You're not enrolled in this cohort."]);
    if ($msg === 'session_not_found') mam_fail('session_not_found', 404, ['detail' => 'That session no longer exists.']);
    mam_fail($msg, 422);
}

mam_ok([
    'checked_in'    => true,
    'status'        => $res['status'],
    'already'       => $res['already'],
    'session_title' => $res['session_title'],
    'class_title'   => $res['cohort_title'], // attend.php renders `class_title` as the context label
    'date'          => $tok['date'],
]);
