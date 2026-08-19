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

    try {
        mam_db_insert('attendance', [
            'enrollment_id' => $eid,
            'session_date'  => $date,
            'status'        => 'present',
            'notes'         => 'Self check-in (QR)',
            'marked_by'     => $studentUserId,
        ]);
        mam_audit('checkin', 'attendance', $eid, ['class_id' => $classId, 'date' => $date, 'via' => 'qr']);
    } catch (\Throwable $e) {
        // Concurrent scan won the (enrollment_id, session_date) race — the row
        // exists now (idx_attendance_unique), so this is an idempotent re-scan.
        $cur = mam_db_row('SELECT status FROM attendance WHERE enrollment_id = ? AND session_date = ?', [$eid, $date]);
        return ['status' => (string)($cur['status'] ?? 'present'), 'enrollment_id' => $eid, 'already' => true, 'class_title' => $title];
    }
    return ['status' => 'present', 'enrollment_id' => $eid, 'already' => false, 'class_title' => $title];
}

/* ─────────────────────────────────────────────────────────────────────────
   Cohort SESSION self-check-in (P2 convergence).
   The same signed-QR pattern as above, but over the NEWER cohort model: the
   token is scoped to a class_session, and check-in writes session_attendance
   (enrolment-gated via course_enrollments, idempotent, never downgrades an
   existing mark). A distinct raw prefix ('sess') and its own verify function
   keep the two token grammars from ever being confused.
   ───────────────────────────────────────────────────────────────────────── */

/** Mint a signed check-in token for a class_session, valid until end of `date`. */
function mam_attendance_session_token(int $sessionId, ?string $date = null): string {
    $date = $date ?: date('Y-m-d');
    $exp  = (int)strtotime($date . ' 23:59:59');
    $raw  = 'sess:' . $sessionId . ':' . $date . ':' . $exp;
    $sig  = hash_hmac('sha256', $raw, mam_app_key());
    return mam_attendance_b64($raw . '|' . $sig);
}

/**
 * Verify a session token. Returns ['session_id','date','exp'] or null.
 * Constant-time signature check; rejects the legacy class-token grammar.
 *
 * @return array{session_id:int,date:string,exp:int}|null
 */
function mam_attendance_verify_session_token(?string $token): ?array {
    $token = trim((string)$token);
    if ($token === '' || strlen($token) > 512) return null;
    $decoded = mam_attendance_unb64($token);
    if ($decoded === '' || strpos($decoded, '|') === false) return null;
    [$raw, $sig] = explode('|', $decoded, 2);
    $expected = hash_hmac('sha256', $raw, mam_app_key());
    if (!hash_equals($expected, $sig)) return null;
    $parts = explode(':', $raw);
    if (count($parts) !== 4 || $parts[0] !== 'sess') return null;
    [, $sessionId, $date, $exp] = $parts;
    if (!ctype_digit((string)$sessionId) || (int)$sessionId <= 0) return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
    if (!ctype_digit((string)$exp)) return null;
    if (time() > (int)$exp) return null;
    return ['session_id' => (int)$sessionId, 'date' => $date, 'exp' => (int)$exp];
}

/**
 * Mark the caller present for a cohort session via self-check-in. Enrolment is
 * gated on an active/completed course_enrollment in the session's cohort. Writes
 * session_attendance idempotently and NEVER downgrades an existing mark (e.g.
 * an instructor's absent/late stands). Throws RuntimeException('not_enrolled').
 *
 * @return array{status:string,already:bool,session_title:?string,cohort_title:?string}
 */
function mam_attendance_session_self_checkin(int $studentUserId, int $sessionId): array {
    if ($studentUserId <= 0 || $sessionId <= 0) throw new RuntimeException('invalid_request');

    $sess = mam_db_row(
        "SELECT cs.id, cs.cohort_id, cs.title AS session_title, co.title AS cohort_title
           FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id WHERE cs.id = ?",
        [$sessionId]
    );
    if (!$sess) throw new RuntimeException('session_not_found');

    $onRoster = mam_db_row(
        "SELECT 1 FROM course_enrollments WHERE cohort_id = ? AND student_id = ? AND status IN ('active','completed') LIMIT 1",
        [(int)$sess['cohort_id'], $studentUserId]
    );
    if (!$onRoster) throw new RuntimeException('not_enrolled');

    $meta = ['session_title' => $sess['session_title'], 'cohort_title' => $sess['cohort_title']];

    $existing = mam_db_row('SELECT id, status FROM session_attendance WHERE session_id = ? AND student_id = ?', [$sessionId, $studentUserId]);
    if ($existing) {
        // A re-scan, or the instructor already marked them — never downgrade.
        return ['status' => (string)$existing['status'], 'already' => true] + $meta;
    }

    try {
        mam_db_insert('session_attendance', [
            'session_id' => $sessionId,
            'student_id' => $studentUserId,
            'status'     => 'present',
            'notes'      => 'Self check-in (QR)',
            'marked_by'  => $studentUserId,
            // Set EXPLICITLY. The column defaults to 'instructor', so leaving it
            // out recorded every scan as a hand-marked register — the note said
            // "Self check-in (QR)" in prose while the one column built to tell
            // the two apart said otherwise, and nothing queries prose. It
            // matters in a dispute: "I scanned in" is answerable from this
            // column and from nowhere else.
            'method'     => 'qr',
        ]);
        mam_audit('checkin', 'class_session', $sessionId, ['cohort_id' => (int)$sess['cohort_id'], 'via' => 'qr']);
    } catch (\Throwable $e) {
        // Concurrent scan won the uq_sess_att race — the row exists now, so this
        // is an idempotent re-check-in, not an error.
        $cur = mam_db_row('SELECT status FROM session_attendance WHERE session_id = ? AND student_id = ?', [$sessionId, $studentUserId]);
        return ['status' => (string)($cur['status'] ?? 'present'), 'already' => true] + $meta;
    }
    return ['status' => 'present', 'already' => false] + $meta;
}
