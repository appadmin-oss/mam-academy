<?php
/**
 * POST /api/attendance/checkin  { token }
 *
 * Student self-check-in via the instructor's session QR. Verifies the signed,
 * day-scoped token, confirms the caller is enrolled in that class, and marks
 * them present (idempotent). Student-only, CSRF-protected, rate-limited.
 */
declare(strict_types=1);

$u  = mam_require_role('student');
$in = mam_json_input();

$tok = mam_attendance_verify_token((string)($in['token'] ?? ''));
if (!$tok) mam_fail('invalid_or_expired', 422, ['detail' => 'This check-in code is invalid or has expired.']);

try {
    $res = mam_attendance_self_checkin((int)$u['id'], $tok['class_id'], $tok['date']);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'not_enrolled') mam_fail('not_enrolled', 403, ['detail' => "You're not enrolled in this class."]);
    mam_fail($e->getMessage(), 422);
}

mam_ok([
    'checked_in'  => true,
    'status'      => $res['status'],
    'already'     => $res['already'],
    'class_title' => $res['class_title'],
    'date'        => $tok['date'],
]);
