<?php
/**
 * Post-session progress reports — instructor submits a per-student report within
 * 2 hours of each held session; an email goes to the student immediately on
 * submission. Missing reports trigger an instructor_fine via the job queue.
 */
declare(strict_types=1);

/** Active progress-report form template (kind='progress_report', status='active'). */
function mam_progress_report_template(): ?array {
    $row = mam_db_row(
        "SELECT id, title, description, questions_json FROM surveys
          WHERE kind = 'progress_report' AND status = 'active'
          ORDER BY created_at DESC LIMIT 1"
    );
    if (!$row) return null;
    $row['questions'] = json_decode((string)$row['questions_json'], true) ?: [];
    unset($row['questions_json']);
    return $row;
}

/** Existing progress reports for a session, keyed by student_id. */
function mam_progress_reports_for_session(int $sessionId): array {
    $rows = mam_db_all(
        "SELECT pr.*, u.name AS student_name, u.email AS student_email
           FROM progress_reports pr
           JOIN users u ON u.id = pr.student_id
          WHERE pr.session_id = ?",
        [$sessionId]
    );
    $out = [];
    foreach ($rows as $r) {
        $r['answers'] = json_decode((string)($r['answers_json'] ?? '{}'), true) ?: [];
        unset($r['answers_json']);
        $out[(int)$r['student_id']] = $r;
    }
    return $out;
}

/**
 * Submit a progress report for one student in a session.
 * Sends the report email immediately. Idempotent: calling twice updates the row
 * and re-sends the email only if it was not sent before.
 */
function mam_progress_report_submit(
    int    $sessionId,
    int    $studentId,
    int    $instructorId,
    array  $answers,
    ?int   $formId    = null,
    ?string $narrative = null
): int {
    $student  = mam_db_row('SELECT id, name, email FROM users WHERE id = ?', [$studentId]);
    $session  = mam_db_row(
        "SELECT cs.*, co.title AS cohort_title, u.name AS instructor_name
           FROM class_sessions cs
           JOIN cohorts co ON co.id = cs.cohort_id
           LEFT JOIN users u ON u.id = co.instructor_id
          WHERE cs.id = ?",
        [$sessionId]
    );
    if (!$student || !$session) throw new \RuntimeException('not_found');

    $answersJson = json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = date('Y-m-d H:i:s');

    $existing = mam_db_row(
        'SELECT id, email_sent FROM progress_reports WHERE session_id = ? AND student_id = ?',
        [$sessionId, $studentId]
    );

    if ($existing) {
        mam_db_update('progress_reports', [
            'answers_json' => $answersJson,
            'narrative'    => $narrative,
            'form_id'      => $formId,
            'submitted_at' => $now,
        ], ['id' => (int)$existing['id']]);
        $reportId  = (int)$existing['id'];
        $emailSent = (bool)$existing['email_sent'];
    } else {
        $reportId  = mam_db_insert('progress_reports', [
            'session_id'    => $sessionId,
            'student_id'    => $studentId,
            'instructor_id' => $instructorId,
            'form_id'       => $formId,
            'answers_json'  => $answersJson,
            'narrative'     => $narrative,
            'submitted_at'  => $now,
        ]);
        $emailSent = false;
    }

    if (!$emailSent && !empty($student['email'])) {
        $sent = mam_progress_report_send_email($student, $session, $answers, $narrative);
        if ($sent) {
            mam_db_update('progress_reports', ['email_sent' => 1, 'email_sent_at' => $now], ['id' => $reportId]);
        }
    }

    mam_audit('progress_report_submit', 'class_session', $sessionId, [
        'student_id'    => $studentId,
        'instructor_id' => $instructorId,
        'report_id'     => $reportId,
    ]);
    return $reportId;
}

/** Send the progress report email to the student. Returns true on success. */
function mam_progress_report_send_email(array $student, array $session, array $answers, ?string $narrative): bool {
    if (!function_exists('mam_send')) return false;
    $name        = htmlspecialchars((string)($student['name'] ?? 'Student'), ENT_QUOTES);
    $cohort      = htmlspecialchars((string)($session['cohort_title'] ?? ''), ENT_QUOTES);
    $instructor  = htmlspecialchars((string)($session['instructor_name'] ?? 'Your instructor'), ENT_QUOTES);
    $sessionDate = htmlspecialchars(substr((string)($session['scheduled_at'] ?? $session['created_at'] ?? ''), 0, 16), ENT_QUOTES);
    $appUrl      = rtrim((string)(function_exists('mam_env') ? mam_env('APP_URL', 'https://academy.mamcentre.org') : 'https://academy.mamcentre.org'), '/');

    // Build answers HTML
    $answersHtml = '';
    foreach ($answers as $q => $a) {
        if (is_array($a)) $a = implode(', ', $a);
        $answersHtml .= '<tr><td style="padding:8px 12px;font-weight:600;color:#3D2B1F;width:40%;vertical-align:top;border-bottom:1px solid #EDE8DF">'
            . htmlspecialchars((string)$q, ENT_QUOTES)
            . '</td><td style="padding:8px 12px;color:#5C4033;border-bottom:1px solid #EDE8DF">'
            . nl2br(htmlspecialchars((string)$a, ENT_QUOTES))
            . '</td></tr>';
    }

    $narrativeBlock = $narrative
        ? '<div style="margin:18px 0;padding:16px 18px;background:#FFF8EE;border-left:4px solid #E08A1A;border-radius:0 8px 8px 0">'
          . '<p style="margin:0 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#B8721B;font-weight:700">Instructor note</p>'
          . '<p style="margin:0;color:#3D2B1F;line-height:1.65">' . nl2br(htmlspecialchars($narrative, ENT_QUOTES)) . '</p></div>'
        : '';

    $inner = "<h2 style='font-family:Georgia,serif;font-size:22px;color:#1A120B;margin:0 0 6px'>Session Progress Report</h2>"
        . "<p style='margin:0 0 18px;color:#7B5E52;font-size:14px'>{$cohort} · {$sessionDate}</p>"
        . "<p style='color:#3D2B1F;line-height:1.7'>Hi {$name}, here is your progress report from <strong>{$instructor}</strong> for your recent session.</p>"
        . $narrativeBlock
        . ($answersHtml
            ? "<table style='width:100%;border-collapse:collapse;margin:18px 0;font-size:14px'>{$answersHtml}</table>"
            : '')
        . "<p style='color:#7B5E52;font-size:13px;margin-top:22px'>To view your full progress history, <a href='{$appUrl}/portal/student/' style='color:#B8721B'>sign in to your student portal</a>.</p>";

    $body = function_exists('mam_email_shell')
        ? mam_email_shell('Progress Report', $inner)
        : $inner;

    return mam_send(
        (string)$student['email'],
        'Your Session Progress Report — ' . ($session['cohort_title'] ?? 'MAM Academy'),
        $body,
        'progress_report',
        true
    );
}

/**
 * Check a session for missing progress reports and apply fines.
 * Called by the `progress_report_deadline` job 2 hrs after each held session.
 * Returns ['checked', 'missing', 'fined'].
 */
function mam_progress_report_deadline_check(int $sessionId): array {
    $session = mam_db_row(
        'SELECT cs.cohort_id, co.instructor_id FROM class_sessions cs
           JOIN cohorts co ON co.id = cs.cohort_id WHERE cs.id = ?',
        [$sessionId]
    );
    if (!$session) return ['checked' => 0, 'missing' => 0, 'fined' => 0];

    $students = mam_db_all(
        "SELECT DISTINCT ce.student_id FROM course_enrollments ce
          WHERE ce.cohort_id = ? AND ce.status = 'active'",
        [(int)$session['cohort_id']]
    );

    $instructorId = (int)($session['instructor_id'] ?? 0);
    $checked = count($students);
    $missing = 0; $fined = 0;

    foreach ($students as $s) {
        $sid = (int)$s['student_id'];
        $exists = mam_db_row(
            'SELECT id FROM progress_reports WHERE session_id = ? AND student_id = ?',
            [$sessionId, $sid]
        );
        if (!$exists) {
            $missing++;
            if ($instructorId > 0) {
                // Apply fine only if not already applied for this session+instructor
                $alreadyFined = mam_db_row(
                    "SELECT id FROM instructor_fines WHERE session_id = ? AND instructor_id = ? AND reason = 'late_progress_report'",
                    [$sessionId, $instructorId]
                );
                if (!$alreadyFined) {
                    mam_db_insert('instructor_fines', [
                        'instructor_id'    => $instructorId,
                        'session_id'       => $sessionId,
                        'reason'           => 'late_progress_report',
                        'amount_kobo'      => 0, // admin sets amount; this is a flag
                        'status'           => 'pending',
                        // Currency-safe tag (v61): fines are naira-denominated;
                        // canonical USD tracks amount_kobo (0 while it's just a
                        // flag) at the rate frozen now.
                        'currency'         => 'NGN',
                        'amount_usd_cents' => 0,
                        'fx_rate_usd_ngn'  => function_exists('mam_usd_to_ngn_rate') ? mam_usd_to_ngn_rate(false) : null,
                    ]);
                    $fined++;
                    if (function_exists('mam_notify')) {
                        mam_notify($instructorId, 'progress_report_fine',
                            'Progress Report Overdue',
                            'A progress report for session #' . $sessionId . ' was not submitted within 2 hours. A fine has been recorded.'
                        );
                    }
                }
            }
        }
    }
    return ['checked' => $checked, 'missing' => $missing, 'fined' => $fined];
}

/** Students enrolled in a cohort (for the progress report form — who to report on). */
function mam_progress_report_students_for_session(int $sessionId): array {
    $session = mam_db_row('SELECT cohort_id FROM class_sessions WHERE id = ?', [$sessionId]);
    if (!$session) return [];
    return mam_db_all(
        "SELECT u.id, u.name, u.email, u.enrollment_ref
           FROM course_enrollments ce
           JOIN users u ON u.id = ce.student_id
          WHERE ce.cohort_id = ? AND ce.status = 'active'
          ORDER BY u.name",
        [(int)$session['cohort_id']]
    );
}

/**
 * Student: all progress reports submitted for the student across all sessions,
 * newest first. Returns session date, cohort, instructor, narrative, answers.
 */
function mam_student_session_reports(int $studentId): array {
    $rows = mam_db_all(
        "SELECT pr.id, pr.submitted_at, pr.narrative, pr.answers_json,
                cs.scheduled_at AS session_at, cs.kind AS session_kind,
                co.title AS cohort_title,
                u.name  AS instructor_name
           FROM progress_reports pr
           JOIN class_sessions cs ON cs.id = pr.session_id
           JOIN cohorts co        ON co.id = cs.cohort_id
           JOIN users u           ON u.id  = pr.instructor_id
          WHERE pr.student_id = ?
          ORDER BY pr.submitted_at DESC",
        [$studentId]
    );
    foreach ($rows as &$r) {
        $r['answers'] = json_decode((string)($r['answers_json'] ?? '{}'), true) ?: [];
        unset($r['answers_json']);
    }
    unset($r);
    return $rows;
}

/**
 * Resolve or waive an instructor fine.
 * @param string $newStatus 'resolved' | 'waived'
 * @return array Updated fine row.
 */
function mam_fine_resolve(int $fineId, int $adminId, string $newStatus, string $note): array {
    if (!in_array($newStatus, ['resolved', 'waived'], true)) {
        throw new \InvalidArgumentException("Invalid fine status: $newStatus");
    }
    $fine = mam_db_row('SELECT id, status FROM instructor_fines WHERE id = ?', [$fineId]);
    if (!$fine) throw new \RuntimeException('Fine not found');
    if ($fine['status'] !== 'pending') throw new \RuntimeException('Fine already ' . $fine['status']);

    mam_db()->prepare(
        "UPDATE instructor_fines
            SET status = ?, resolved_at = ?, resolved_by = ?, resolution_note = ?
          WHERE id = ?"
    )->execute([$newStatus, gmdate('Y-m-d H:i:s'), $adminId, $note !== '' ? $note : null, $fineId]);

    mam_audit($newStatus === 'waived' ? 'fine_waived' : 'fine_resolved', 'instructor_fines', $fineId, [
        'admin_id' => $adminId,
        'note'     => $note,
    ]);
    return mam_db_row('SELECT * FROM instructor_fines WHERE id = ?', [$fineId]) ?: [];
}

/** Admin: list fines with instructor + session details. */
function mam_instructor_fines(int $limit = 100, string $status = ''): array {
    $where = $status !== '' ? "WHERE f.status = " . mam_db()->quote($status) : '';
    return mam_db_all(
        "SELECT f.*, u.name AS instructor_name, u.email AS instructor_email,
                cs.scheduled_at AS session_at, co.title AS cohort_title
           FROM instructor_fines f
           JOIN users u ON u.id = f.instructor_id
           LEFT JOIN class_sessions cs ON cs.id = f.session_id
           LEFT JOIN cohorts co ON co.id = cs.cohort_id
           $where
           ORDER BY f.created_at DESC LIMIT " . max(1, min(500, $limit))
    );
}
