<?php
/**
 * POST /api/instructor/attendance-status — record or correct a student's
 * attendance for a session against the full §12 vocabulary, with the §16 mode.
 *
 *   {session_id, student_id, status, mode?, note?}
 *
 * Unlike a self check-in this IS allowed to downgrade a mark: it is the human
 * override, which is why every use is audited. The caller must teach the
 * session's cohort.
 */
declare(strict_types=1);

$u   = mam_require_role('instructor', 'admin');
$uid = (int) $u['id'];

$in        = mam_json_input();
$sessionId = (int) ($in['session_id'] ?? 0);
$studentId = (int) ($in['student_id'] ?? 0);
$status    = (string) ($in['status'] ?? '');
$mode      = isset($in['mode']) && $in['mode'] !== '' ? (string) $in['mode'] : null;
$note      = isset($in['note']) ? trim((string) $in['note']) : null;

if ($sessionId < 1) mam_fail('missing_field', 400, ['field' => 'session_id']);
if ($studentId < 1) mam_fail('missing_field', 400, ['field' => 'student_id']);
if (!in_array($status, MAM_ATTENDANCE_STATUSES, true)) {
    mam_fail('invalid_status', 422, ['allowed' => MAM_ATTENDANCE_STATUSES]);
}

// An instructor may only mark sessions in a cohort they teach; an admin may
// mark any. Checked here rather than in the lib, which has no notion of caller.
if ((string) $u['role'] !== 'admin') {
    $ok = mam_db_row(
        "SELECT 1 FROM class_sessions cs
           JOIN cohorts co ON co.id = cs.cohort_id
          WHERE cs.id = ? AND (co.instructor_id = ? OR cs.substitute_id = ?) LIMIT 1",
        [$sessionId, $uid, $uid]
    );
    if (!$ok) mam_fail('forbidden', 403);
}

try {
    mam_ok(['attendance' => mam_checkin_set_status($sessionId, $studentId, $status, $mode, $uid, $note)]);
} catch (\InvalidArgumentException $e) {
    mam_fail($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    mam_fail($e->getMessage(), 404);
}
