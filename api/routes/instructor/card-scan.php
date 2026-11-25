<?php
/**
 * POST /api/instructor/card-scan
 *
 * Instructor scans a student's ID card QR code to mark session attendance.
 * The payload is the raw QR string from the card; the instructor also supplies
 * the session_id of the cohort session they are running. (A legacy class_id is
 * no longer accepted: the class model is retired, its classes carried into
 * cohorts by mam_legacy_carry(), and the old attendance table is not written.)
 *
 * Response:
 *   { result: 'marked'|'already'|'revoked'|'expired'|'invalid'|'not_enrolled',
 *     student: { name, photo_url }|null,
 *     session_title: string|null }
 */
declare(strict_types=1);

$input     = mam_json_input();
$payload   = trim((string)($input['payload'] ?? ''));
$sessionId = isset($input['session_id']) ? (int)$input['session_id'] : null;
$scanBy    = (int)mam_require_role('instructor', 'admin')['id']; // DB-backed session; route gated by mw_role('instructor','admin')

if ($payload === '') mam_fail('invalid_params', 422);
if (!$sessionId) mam_fail('invalid_params', 422);

$card = mam_card_verify_payload($payload);

// Log the scan attempt regardless of outcome.
$logResult = 'invalid';
$logCardId = null;

if (!$card) {
    // Invalid payload or HMAC mismatch → reject.
    mam_db_insert('card_scans', [
        'card_id'    => null,
        'raw_serial' => substr($payload, 0, 20),
        'session_id' => $sessionId,
        'scanned_by' => $scanBy,
        'result'     => 'invalid',
        'scanned_at' => date('Y-m-d H:i:s'),
    ]);
    mam_ok(['result' => 'invalid', 'student' => null, 'session_title' => null]);
}

$logCardId = (int)$card['id'];

// EXPIRED card → reject. The HMAC still verifies (the payload is static and the
// card is genuine), so without this check a lapsed card marks attendance forever —
// which is exactly what the expiry date on the face is supposed to prevent. Logged
// under its own result so the scan history distinguishes "lapsed" from "revoked".
if (mam_card_is_expired($card)) {
    mam_db_insert('card_scans', [
        'card_id'    => $logCardId,
        'raw_serial' => (string)$card['serial'],
        'session_id' => $sessionId,
        'scanned_by' => $scanBy,
        'result'     => 'expired',
        'scanned_at' => date('Y-m-d H:i:s'),
    ]);
    mam_ok([
        'result'        => 'expired',
        'student'       => null,
        'session_title' => null,
        'expired_on'    => (string)$card['expires_at'],
    ]);
}

// Revoked card → reject immediately.
if ((string)$card['status'] !== 'active') {
    mam_db_insert('card_scans', [
        'card_id'    => $logCardId,
        'raw_serial' => (string)$card['serial'],
        'session_id' => $sessionId,
        'scanned_by' => $scanBy,
        'result'     => 'revoked',
        'scanned_at' => date('Y-m-d H:i:s'),
    ]);
    mam_ok(['result' => 'revoked', 'student' => null, 'session_title' => null]);
}

// Resolve card holder identity (user_id may be null for summer-only students).
$cardUserId = $card['user_id'] !== null ? (int)$card['user_id'] : null;

// The cohort session being run.
$sess = mam_db_row(
    "SELECT cs.id, cs.cohort_id, cs.title AS session_title, co.title AS cohort_title
       FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id WHERE cs.id = ?",
    [$sessionId]
);
if (!$sess) {
    mam_ok(['result' => 'invalid', 'student' => null, 'session_title' => null]);
}

$sessionTitle = (string)$sess['session_title'];

if ($cardUserId === null) {
    // Summer-only student has no course_enrollment — can't mark cohort attendance.
    mam_db_insert('card_scans', [
        'card_id'    => $logCardId,
        'raw_serial' => (string)$card['serial'],
        'session_id' => $sessionId,
        'scanned_by' => $scanBy,
        'result'     => 'not_enrolled',
        'scanned_at' => date('Y-m-d H:i:s'),
    ]);
    mam_ok(['result' => 'not_enrolled', 'student' => null, 'session_title' => $sessionTitle]);
}

// Enrolment gate: student must be on the cohort roster.
$onRoster = mam_db_row(
    "SELECT 1 FROM course_enrollments WHERE cohort_id = ? AND student_id = ? AND status IN ('active','completed') LIMIT 1",
    [(int)$sess['cohort_id'], $cardUserId]
);
if (!$onRoster) {
    mam_db_insert('card_scans', [
        'card_id'    => $logCardId,
        'raw_serial' => (string)$card['serial'],
        'session_id' => $sessionId,
        'scanned_by' => $scanBy,
        'result'     => 'not_enrolled',
        'scanned_at' => date('Y-m-d H:i:s'),
    ]);
    mam_ok(['result' => 'not_enrolled', 'student' => null, 'session_title' => $sessionTitle]);
}

// Idempotent mark.
$existing = mam_db_row(
    'SELECT id, status FROM session_attendance WHERE session_id = ? AND student_id = ?',
    [$sessionId, $cardUserId]
);

$scanResult = 'marked';
if ($existing) {
    $scanResult = 'already';
} else {
    try {
        mam_db_insert('session_attendance', [
            'session_id' => $sessionId,
            'student_id' => $cardUserId,
            'status'     => 'present',
            'notes'      => 'Instructor card scan',
            'marked_by'  => $scanBy,
            // Its own method, not 'instructor': a card presented at a door
            // is evidence the student was physically there, which a register
            // mark is not. Collapsing them loses the distinction the scan
            // exists to create.
            'method'     => 'card',
        ]);
        mam_audit('checkin', 'class_session', $sessionId, [
            'cohort_id' => (int)$sess['cohort_id'],
            'via'       => 'card',
            'card_id'   => $logCardId,
        ]);
    } catch (\Throwable $e) {
        // Lost the race with a concurrent scan (uq_sess_att already holds the
        // row) — that's an idempotent re-scan, not a 500.
        $scanResult = 'already';
    }
}

mam_db_insert('card_scans', [
    'card_id'    => $logCardId,
    'raw_serial' => (string)$card['serial'],
    'session_id' => $sessionId,
    'scanned_by' => $scanBy,
    'result'     => $scanResult,
    'scanned_at' => date('Y-m-d H:i:s'),
]);

$student = null;
$u = mam_db_row('SELECT name, avatar_url FROM users WHERE id = ?', [$cardUserId]);
if ($u) $student = ['name' => (string)$u['name'], 'photo_url' => $u['avatar_url'] ?? null];

mam_ok(['result' => $scanResult, 'student' => $student, 'session_title' => $sessionTitle]);
