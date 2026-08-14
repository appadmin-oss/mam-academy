<?php
/**
 * Progress report system — lesson records, enrollment refs, and parent-facing data.
 *
 * Enrollment refs are stable human-readable student IDs:
 *   KTAMS program:  JE/MAM/YY/NNN   (e.g. JE/MAM/26/001)
 *   All others:     MAM/YY/NNN       (e.g. MAM/26/007)
 *
 * Parents look up /parent?id=MAM/26/007 or use a one-time token link.
 * Tokens are random + stored; no signing needed since they're long (32 bytes hex).
 */
declare(strict_types=1);

// ─── Enrollment Reference ───────────────────────────────────────────────────

/**
 * Generate and store a permanent enrollment ref for a student.
 * Idempotent: if already set, returns the existing ref without changing it.
 * KTAMS students (enrolled in a KTAMS program course) get the JE/MAM prefix.
 */
function mam_enrollment_ref_generate(int $userId): string {
    $user = mam_db_row('SELECT enrollment_ref FROM users WHERE id = ?', [$userId]);
    if (!$user) throw new \RuntimeException('user_not_found');
    if (!empty($user['enrollment_ref'])) return (string)$user['enrollment_ref'];

    $isKtams = (bool)mam_db_row(
        "SELECT 1 FROM course_enrollments ce
           JOIN cohorts co ON co.id = ce.cohort_id
           JOIN program_courses pc ON pc.course_id = co.course_id
           JOIN programs p ON p.id = pc.program_id
          WHERE ce.student_id = ? AND p.code = 'ktams' LIMIT 1",
        [$userId]
    );

    $prefix  = $isKtams ? 'JE/MAM' : 'MAM';
    $yy      = date('y');
    $pattern = $prefix . '/' . $yy . '/%';

    $serialOffset = strlen($prefix . '/' . $yy . '/') + 1; // SUBSTR is 1-indexed
    $last = mam_db_row(
        "SELECT MAX(CAST(SUBSTR(enrollment_ref, ?) AS INTEGER)) AS max_serial FROM users WHERE enrollment_ref LIKE ?",
        [$serialOffset, $pattern]
    );

    $serial = 1;
    if ($last && $last['max_serial'] !== null) {
        $serial = (int)$last['max_serial'] + 1;
    }

    $ref = $prefix . '/' . $yy . '/' . str_pad((string)$serial, 3, '0', STR_PAD_LEFT);
    mam_db()->prepare("UPDATE users SET enrollment_ref = ? WHERE id = ? AND (enrollment_ref IS NULL OR enrollment_ref = '')")
             ->execute([$ref, $userId]);
    mam_audit('enrollment_ref_generate', 'user', $userId, ['ref' => $ref]);
    return $ref;
}

/**
 * Look up an active student_id by their enrollment ref.
 * Returns null if the ref does not match a live student account.
 */
function mam_enrollment_ref_lookup(string $ref): ?int {
    $row = mam_db_row(
        "SELECT id FROM users WHERE enrollment_ref = ? AND role = 'student' AND status = 'active'",
        [trim($ref)]
    );
    return $row ? (int)$row['id'] : null;
}

// ─── Progress Tokens ────────────────────────────────────────────────────────

/** Generate and store a new progress report token for a student. */
function mam_progress_token_create(int $studentId, int $createdBy, string $label = '', ?string $expiresAt = null): string {
    $token = bin2hex(random_bytes(24)); // 48-char hex
    mam_db_insert('progress_report_tokens', [
        'student_id' => $studentId,
        'token'      => $token,
        'label'      => $label !== '' ? mb_substr($label, 0, 200) : null,
        'scope'      => 'all',
        'expires_at' => $expiresAt,
        'created_by' => $createdBy,
    ]);
    mam_audit('progress_token_create', 'user', $studentId, ['label' => $label, 'by' => $createdBy]);
    return $token;
}

/** Validate a token and return its row, or null if invalid/expired. */
function mam_progress_token_resolve(string $token): ?array {
    $row = mam_db_row(
        "SELECT t.*, u.id AS student_id, u.name AS student_name, u.email AS student_email
           FROM progress_report_tokens t JOIN users u ON u.id = t.student_id
          WHERE t.token = ?",
        [$token]
    );
    if (!$row) return null;
    if ($row['expires_at'] !== null && $row['expires_at'] < date('Y-m-d H:i:s')) return null;
    try {
        mam_db()->prepare(
            "UPDATE progress_report_tokens SET accessed_count = accessed_count + 1, last_accessed_at = ? WHERE token = ?"
        )->execute([date('Y-m-d H:i:s'), $token]);
    } catch (Throwable $e) { /* non-fatal */ }
    return $row;
}

/** All tokens issued for a student (for admin management). */
function mam_progress_tokens_for_student(int $studentId): array {
    return mam_db_all(
        "SELECT t.id, t.token, t.label, t.scope, t.expires_at, t.accessed_count, t.last_accessed_at, t.created_at, u.name AS created_by_name
           FROM progress_report_tokens t LEFT JOIN users u ON u.id = t.created_by
          WHERE t.student_id = ? ORDER BY t.created_at DESC",
        [$studentId]
    );
}

/** Revoke (delete) a progress token. */
function mam_progress_token_revoke(int $tokenId, int $studentId): void {
    mam_db()->prepare('DELETE FROM progress_report_tokens WHERE id = ? AND student_id = ?')
             ->execute([$tokenId, $studentId]);
}

// ─── Report Data ────────────────────────────────────────────────────────────

/**
 * Assemble the full progress report for a student.
 * No auth check here — caller must have validated the token or be the student.
 *
 * Returns keys:
 *   student, enrollments, course_enrollments, class_summary,
 *   sessions, upcoming_sessions, assessments, results,
 *   certificates, announcements, practice_logs, generated_at
 */
function mam_progress_report_data(int $studentId): array {
    $student = mam_db_row(
        'SELECT id, name, email, avatar_url, enrollment_ref, created_at FROM users WHERE id = ?',
        [$studentId]
    );
    if (!$student) throw new \RuntimeException('student_not_found');

    // Resilience: the report stitches together many independent sections, some
    // living in tables/columns added by later migrations. If a deployment's DB
    // is behind on migrations (the #1 production gotcha — see DEPLOYMENT.md) one
    // missing column must not 500 the entire report; the section should just be
    // omitted. Every optional query runs through $soft(): it returns the given
    // fallback and logs a warning on any error, exactly as the student dashboard
    // already does. Core identity (above) stays strict — a missing student is a
    // real 404 for the caller.
    $soft = function (callable $fn, $default) {
        try { return $fn(); }
        catch (\Throwable $e) { mam_log('warn', 'report_section_failed', ['err' => $e->getMessage()]); return $default; }
    };

    // ── Legacy class enrollments ──────────────────────────────────────────
    $enrollments = $soft(fn () => mam_db_all(
        "SELECT e.id, e.status, e.enrolled_at AS created_at,
                cl.title AS class_name, cl.discipline AS instrument, cl.pathway AS level,
                cu.name AS instructor_name
           FROM enrollments e
           JOIN classes cl ON cl.id = e.class_id
           LEFT JOIN users cu ON cu.id = cl.instructor_id
          WHERE e.student_id = ? AND e.status = 'active'
          ORDER BY e.enrolled_at DESC",
        [$studentId]
    ), []);

    // ── LMS course enrollments ────────────────────────────────────────────
    $courseEnrollments = $soft(fn () => mam_db_all(
        "SELECT ce.id, ce.status, ce.created_at,
                c.name AS course_name, c.code AS course_code,
                l.name AS level_name, co.title AS cohort_title,
                iu.name AS instructor_name
           FROM course_enrollments ce
           JOIN courses c ON c.id = ce.course_id
           LEFT JOIN levels l ON l.id = ce.level_id
           LEFT JOIN cohorts co ON co.id = ce.cohort_id
           LEFT JOIN users iu ON iu.id = ce.instructor_id
          WHERE ce.student_id = ? AND ce.status IN ('active','completed')
          ORDER BY ce.created_at DESC",
        [$studentId]
    ), []);

    // ── Class-level attendance summary ────────────────────────────────────
    // One summary row per class/cohort so the parent dashboard can show
    // attendance rings and per-course stats without iterating every session.
    $legacySummary = $soft(fn () => mam_db_all(
        "SELECT cl.title AS class_name, cl.discipline AS instrument, cl.pathway AS level,
                u.name AS instructor_name, e.status,
                e.enrolled_at AS enrolled_at, 'legacy' AS type,
                COUNT(a.id) AS total_marked,
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS attended,
                SUM(CASE WHEN a.status = 'absent'  THEN 1 ELSE 0 END) AS absent,
                SUM(CASE WHEN a.status = 'late'    THEN 1 ELSE 0 END) AS late
           FROM enrollments e
           JOIN classes cl ON cl.id = e.class_id
           LEFT JOIN users u ON u.id = cl.instructor_id
           LEFT JOIN attendance a ON a.enrollment_id = e.id
          WHERE e.student_id = ?
          GROUP BY e.id
          ORDER BY e.enrolled_at DESC",
        [$studentId]
    ), []);

    $lmsSummary = $soft(fn () => mam_db_all(
        "SELECT co.title AS cohort_title, c.name AS course_name, l.name AS level_name,
                iu.name AS instructor_name, ce.status, ce.created_at AS enrolled_at, 'lms' AS type,
                (SELECT COUNT(*) FROM class_sessions cs2
                  WHERE cs2.cohort_id = ce.cohort_id AND cs2.status = 'held') AS total_sessions,
                (SELECT COUNT(*) FROM class_sessions cs2
                   JOIN session_attendance sa2 ON sa2.session_id = cs2.id AND sa2.student_id = ce.student_id
                  WHERE cs2.cohort_id = ce.cohort_id AND sa2.status IN ('present','late')) AS attended,
                (SELECT COUNT(*) FROM class_sessions cs2
                   JOIN session_attendance sa2 ON sa2.session_id = cs2.id AND sa2.student_id = ce.student_id
                  WHERE cs2.cohort_id = ce.cohort_id AND sa2.status = 'absent') AS absent
           FROM course_enrollments ce
           JOIN cohorts co ON co.id = ce.cohort_id
           JOIN courses c ON c.id = ce.course_id
           LEFT JOIN levels l ON l.id = ce.level_id
           LEFT JOIN users iu ON iu.id = ce.instructor_id
          WHERE ce.student_id = ? AND ce.status IN ('active','completed')
          ORDER BY ce.created_at DESC",
        [$studentId]
    ), []);

    // Normalise to a unified shape: total, attended, absent, rate
    $classSummary = [];
    foreach ($legacySummary as $row) {
        $total = (int)$row['total_marked'];
        $att   = (int)$row['attended'];
        $row['total_sessions'] = $total;
        $row['attendance_rate'] = $total > 0 ? round($att / $total * 100) : null;
        $classSummary[] = $row;
    }
    foreach ($lmsSummary as $row) {
        $total = (int)$row['total_sessions'];
        $att   = (int)$row['attended'];
        $row['attendance_rate'] = $total > 0 ? round($att / $total * 100) : null;
        $classSummary[] = $row;
    }

    // ── Past sessions with lesson notes ───────────────────────────────────
    $sessions = $soft(fn () => mam_db_all(
        "SELECT DISTINCT cs.id, cs.title, cs.scheduled_at, cs.duration_min, cs.kind,
                cs.lesson_notes, cs.topics, cs.learning_outcomes, cs.status,
                co.title AS cohort_title,
                sa.status AS attendance_status,
                iu.name AS instructor_name
           FROM class_sessions cs
           JOIN cohorts co ON co.id = cs.cohort_id
           JOIN course_enrollments ce ON ce.cohort_id = cs.cohort_id AND ce.student_id = ?
           LEFT JOIN session_attendance sa ON sa.session_id = cs.id AND sa.student_id = ?
           LEFT JOIN users iu ON iu.id = cs.created_by
          WHERE cs.scheduled_at <= ?
          ORDER BY cs.scheduled_at DESC
          LIMIT 60",
        // Compare against server-local "now" — scheduled_at is stored naive-local
        // (see mam_lms_bulk_create_sessions); gmdate() here would misclassify
        // sessions within the server's UTC offset as past/upcoming.
        [$studentId, $studentId, date('Y-m-d H:i:s')]
    ), []);

    // ── Upcoming sessions (next 60 days) ──────────────────────────────────
    $upcoming = $soft(fn () => mam_db_all(
        "SELECT DISTINCT cs.id, cs.title, cs.scheduled_at, cs.duration_min, cs.kind,
                co.title AS cohort_title, iu.name AS instructor_name
           FROM class_sessions cs
           JOIN cohorts co ON co.id = cs.cohort_id
           JOIN course_enrollments ce ON ce.cohort_id = cs.cohort_id AND ce.student_id = ?
           LEFT JOIN users iu ON iu.id = cs.created_by
          WHERE cs.scheduled_at > ? AND cs.status != 'cancelled'
            AND cs.scheduled_at <= ?
          ORDER BY cs.scheduled_at ASC",
        [$studentId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+60 days'))]
    ), []);

    // ── Assessments ───────────────────────────────────────────────────────
    $assessments = $soft(fn () => mam_db_all(
        "SELECT a.id, a.score, a.max_score, a.week, a.notes, a.created_at,
                t.name AS type_name, t.weight,
                iu.name AS grader_name, c.name AS course_name
           FROM assessments a
           JOIN course_enrollments ce ON ce.id = a.enrollment_id
           JOIN courses c ON c.id = ce.course_id
           LEFT JOIN assessment_types t ON t.id = a.type_id
           LEFT JOIN users iu ON iu.id = a.instructor_id
          WHERE ce.student_id = ?
          ORDER BY a.created_at DESC
          LIMIT 100",
        [$studentId]
    ), []);

    // ── Final course results ──────────────────────────────────────────────
    $results = $soft(fn () => mam_db_all(
        "SELECT r.total_score, r.grade, r.outcome, r.finalized_at AS issued_at,
                c.name AS course_name, l.name AS level_name
           FROM results r
           JOIN course_enrollments ce ON ce.id = r.enrollment_id
           JOIN courses c ON c.id = ce.course_id
           LEFT JOIN levels l ON l.id = ce.level_id
          WHERE ce.student_id = ?
          ORDER BY r.finalized_at DESC",
        [$studentId]
    ), []);

    // ── Certificates ──────────────────────────────────────────────────────
    $certificates = $soft(fn () => mam_db_all(
        "SELECT cert.id, cert.reference, cert.kind, cert.title, cert.issued_at,
                p.name AS program_name, l.name AS level_name
           FROM certificates cert
           LEFT JOIN programs p ON p.id = cert.program_id
           LEFT JOIN levels l ON l.id = cert.level_id
          WHERE cert.student_id = ?
          ORDER BY cert.issued_at DESC",
        [$studentId]
    ), []);

    // ── Announcements (dual-model: class + cohort) ────────────────────────
    $announcements = $soft(fn () => mam_db_all(
        "SELECT an.id, an.title, an.body, an.created_at, u.name AS author_name
           FROM announcements an
           LEFT JOIN users u ON u.id = an.author_id
          WHERE an.class_id IN (
                  SELECT class_id FROM enrollments WHERE student_id = ? AND status = 'active'
                )
             OR an.cohort_id IN (
                  SELECT cohort_id FROM course_enrollments WHERE student_id = ? AND status = 'active'
                )
          ORDER BY an.created_at DESC
          LIMIT 10",
        [$studentId, $studentId]
    ), []);

    // ── Practice logs ─────────────────────────────────────────────────────
    $practiceLogs = $soft(fn () => mam_db_all(
        "SELECT pl.minutes, pl.focus, pl.logged_on
           FROM practice_logs pl
          WHERE pl.student_id = ?
          ORDER BY pl.logged_on DESC
          LIMIT 30",
        [$studentId]
    ), []);

    // Roll up practice totals
    $totalPracticeMin = array_sum(array_column($practiceLogs, 'minutes'));

    return [
        'student'            => $student,
        'enrollments'        => $enrollments,
        'course_enrollments' => $courseEnrollments,
        'class_summary'      => $classSummary,
        'sessions'           => $sessions,
        'upcoming_sessions'  => $upcoming,
        'assessments'        => $assessments,
        'results'            => $results,
        'certificates'       => $certificates,
        'announcements'      => $announcements,
        'practice_logs'      => $practiceLogs,
        'total_practice_min' => $totalPracticeMin,
        'generated_at'       => date('Y-m-d H:i:s'),
    ];
}

// ─── Lesson Records ──────────────────────────────────────────────────────────

/**
 * Save lesson notes for a class session. Instructor-only — cohort membership
 * enforced by the caller.
 */
function mam_lesson_record_save(int $sessionId, string $lessonNotes, string $topics, string $learningOutcomes): void {
    mam_db_update('class_sessions', [
        'lesson_notes'      => mb_substr(trim($lessonNotes), 0, 8000) ?: null,
        'topics'            => mb_substr(trim($topics), 0, 2000) ?: null,
        'learning_outcomes' => mb_substr(trim($learningOutcomes), 0, 2000) ?: null,
    ], ['id' => $sessionId]);
}
