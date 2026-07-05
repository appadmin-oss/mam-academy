<?php
/**
 * QR self-check-in for class attendance (portal brief #9/#10).
 *
 * The instructor displays a per-session QR (a signed, day-scoped token); an
 * enrolled student scans it and is marked present for that session — no manual
 * roll-call. This is a thin, secure layer OVER the existing legacy attendance
 * model (enrollments + classes + attendance); it reuses the same idempotent
 * (enrollment_id, session_date) write the instructor roll-call uses, so the two
 * never diverge. It does NOT touch the newer course_enrollments/cohort model.
 *
 * Security:
 *   - The token is HMAC-signed with APP_KEY (mam_app_key) over class + date +
 *     expiry, so it can't be forged and can't be replayed after the session day.
 *   - Check-in only ever marks a student who is actually enrolled in that class,
 *     and is idempotent — a double-scan is one 'present' row, never a duplicate.
 *   - It only marks the CALLER present (self check-in); it can't mark others.
 */
declare(strict_types=1);

/** base64url without padding. */
function mam_attendance_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function mam_attendance_unb64(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/'), true); }

/**
 * Mint a signed check-in token for a class + session date. Valid until the end
 * of that date (a session's QR works for that day only).
 */
function mam_attendance_token(int $classId, ?string $date = null): string {
    $date = $date ?: date('Y-m-d');
    $exp  = (int)strtotime($date . ' 23:59:59');
    $raw  = $classId . ':' . $date . ':' . $exp;
    $sig  = hash_hmac('sha256', $raw, mam_app_key());
    return mam_attendance_b64($raw . '|' . $sig);
}

/**
 * Verify a token. Returns ['class_id','date','exp'] or null (bad signature,
 * malformed, or expired). Constant-time signature comparison.
 *
 * @return array{class_id:int,date:string,exp:int}|null
 */
function mam_attendance_verify_token(?string $token): ?array {
    $token = trim((string)$token);
    if ($token === '' || strlen($token) > 512) return null;
    $decoded = mam_attendance_unb64($token);
    if ($decoded === '' || strpos($decoded, '|') === false) return null;
    [$raw, $sig] = explode('|', $decoded, 2);
    $expected = hash_hmac('sha256', $raw, mam_app_key());
    if (!hash_equals($expected, $sig)) return null;
    $parts = explode(':', $raw);
    if (count($parts) !== 3) return null;
    [$classId, $date, $exp] = $parts;
    if (!ctype_digit((string)$classId) || (int)$classId <= 0) return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
    if (!ctype_digit((string)$exp)) return null;
    if (time() > (int)$exp) return null; // session day has passed
    return ['class_id' => (int)$classId, 'date' => $date, 'exp' => (int)$exp];
}

/**
 * Mark the given student present for a class session via self-check-in.
 * Idempotent per (enrollment, date). Throws RuntimeException('not_enrolled')
 * when the student has no active enrolment in that class.
 *
 * @return array{status:string,enrollment_id:int,already:bool,class_title:?string}
 */
function mam_attendance_self_checkin(int $studentUserId, int $classId, string $date): array {
    if ($studentUserId <= 0 || $classId <= 0) throw new RuntimeException('invalid_request');

    $enr = mam_db_row(
        "SELECT id FROM enrollments WHERE student_id = ? AND class_id = ? AND status = 'active'",
        [$studentUserId, $classId]
    );
    if (!$enr) throw new RuntimeException('not_enrolled');
    $eid = (int)$enr['id'];

    $cls = mam_db_row('SELECT title FROM classes WHERE id = ?', [$classId]);
    $title = $cls ? (string)$cls['title'] : null;

    $existing = mam_db_row('SELECT id, status FROM attendance WHERE enrollment_id = ? AND session_date = ?', [$eid, $date]);
    if ($existing) {
        // Already recorded (a re-scan, or the instructor already marked them).
        // Leave an instructor's explicit mark intact; never downgrade.
        return ['status' => (string)$existing['status'], 'enrollment_id' => $eid, 'already' => true, 'class_title' => $title];
    }

    mam_db_insert('attendance', [
        'enrollment_id' => $eid,
        'session_date'  => $date,
        'status'        => 'present',
        'notes'         => 'Self check-in (QR)',
        'marked_by'     => $studentUserId,
    ]);
    mam_audit('checkin', 'attendance', $eid, ['class_id' => $classId, 'date' => $date, 'via' => 'qr']);
    return ['status' => 'present', 'enrollment_id' => $eid, 'already' => false, 'class_title' => $title];
}
