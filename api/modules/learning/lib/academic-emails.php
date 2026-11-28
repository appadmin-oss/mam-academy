<?php
/**
 * MAM Academy — event-driven academic email engine (LMS 2.0, PDF §28).
 * ─────────────────────────────────────────────────────────────
 * The seven §28 emails, on the existing mail spine (mam_send + outbox retry,
 * the events log for dedup, run-jobs for sweeps):
 *   1 pre-class reminder   (sweep)     5 absence notice       (sweep)
 *   2 attendance confirm   (on check-in)  6 preparation alert  (sweep)
 *   3 student late notice   (on charge)   7 weekly report      (sweep)
 *   4 instructor late notice(on charge)
 *
 * Gated behind academic_emails_enabled (OFF by default — turning on outbound
 * parent/student mail is a deliberate choice). Every send is deduped through a
 * per-(type, session, recipient) event so a sweep never double-sends, and the
 * whole layer is best-effort: a failure never breaks the triggering action.
 */
declare(strict_types=1);

function mam_acadmail_enabled(): bool {
    return (int) mam_setting_num('academic_emails_enabled', 0) === 1;
}
/** Has this exact email (type keyed to a session) already gone to this user? */
function mam_acadmail_sent(string $type, int $userId): bool {
    try { return (bool) mam_db_row('SELECT 1 FROM events WHERE type = ? AND entity_id = ? LIMIT 1', [$type, $userId]); }
    catch (\Throwable $e) { return true; } // fail closed — never risk a duplicate
}

/**
 * Dispatch one academic email and mark it sent (dedup) whether or not SMTP
 * succeeds — the outbox handles retries, so we must not re-dispatch. Returns
 * true if a dispatch was attempted, false if gated/duplicate/no address.
 */
function mam_acadmail_dispatch(string $type, int $userId, string $subject, string $bodyHtml, string $context): bool {
    if (!mam_acadmail_enabled()) return false;
    if ($userId <= 0) return false;
    if (mam_acadmail_sent($type, $userId)) return false;
    $u = mam_db_row('SELECT email, name FROM users WHERE id = ?', [$userId]);
    if (!$u || empty($u['email'])) return false;
    try { mam_send((string) $u['email'], $subject, $bodyHtml, $context, true); }
    catch (\Throwable $e) { /* outbox/logging handles it */ }
    mam_event($type, 'user', $userId, ['subject' => $subject]);
    return true;
}

/* ── event-triggered (2, 3, 4) ────────────────────────────────────────────── */

/** #2 — attendance confirmation, on a student check-in. */
function mam_acadmail_attendance_confirmation(int $studentId, int $sessionId, string $status): bool {
    $s = mam_db_row('SELECT title, scheduled_at FROM class_sessions WHERE id = ?', [$sessionId]);
    $title = $s ? (string) $s['title'] : 'your class';
    $body = '<p>This confirms attendance was recorded for <strong>' . htmlspecialchars($title, ENT_QUOTES) . '</strong>.</p>'
          . '<p>Status: <strong>' . htmlspecialchars($status, ENT_QUOTES) . '</strong></p>';
    return mam_acadmail_dispatch('acadmail.attend.s' . $sessionId, $studentId, 'MAM Academy — Class Attendance Confirmation', $body, 'academic');
}

/** #3/#4 — late notice, on a punctuality charge. */
function mam_acadmail_late_notice(string $subjectType, int $subjectId, int $sessionId, int $minutes, int $usdCents): bool {
    $who = $subjectType === 'instructor' ? 'Instructor Punctuality Notice' : 'Late Attendance Notice';
    $body = '<p>A late arrival of <strong>' . (int) $minutes . ' minute(s)</strong> was recorded.</p>'
          . '<p>Accountability amount: <strong>$' . number_format($usdCents / 100, 2) . '</strong>.</p>';
    return mam_acadmail_dispatch('acadmail.late.s' . $sessionId, $subjectId, 'MAM Academy — ' . $who, $body, 'academic');
}

/* ── swept (1, 5, 6, 7) — driven by run-jobs; each idempotent + gated ──────── */

/** Enrolled students of a session's cohort (id, name). */
function mam_acadmail_roster(int $cohortId): array {
    return mam_db_all("SELECT u.id, u.name FROM course_enrollments ce JOIN users u ON u.id = ce.student_id WHERE ce.cohort_id = ? AND ce.status IN ('active','completed')", [$cohortId]);
}

/** #1 — pre-class reminder for sessions within the next $hours. */
function mam_acadmail_preclass_reminder_sweep(int $hours = 24, int $limit = 100): int {
    if (!mam_acadmail_enabled()) return 0;
    $now = mam_session_now(); $until = mam_session_wall(time() + $hours * 3600); // vs scheduled_at (Lagos)
    $sent = 0;
    try {
        $sessions = mam_db_all("SELECT id, cohort_id, title, scheduled_at FROM class_sessions WHERE status = 'scheduled' AND scheduled_at > ? AND scheduled_at <= ? ORDER BY scheduled_at LIMIT " . max(1, $limit), [$now, $until]);
    } catch (\Throwable $e) { return 0; }
    foreach ($sessions as $s) {
        $body = '<p>Your next class <strong>' . htmlspecialchars((string) $s['title'], ENT_QUOTES) . '</strong> is scheduled for '
              . htmlspecialchars((string) $s['scheduled_at'], ENT_QUOTES) . '.</p><p>Please complete your preparation and teach-back before class.</p>';
        foreach (mam_acadmail_roster((int) $s['cohort_id']) as $stu) {
            if (mam_acadmail_dispatch('acadmail.preclass.s' . (int) $s['id'], (int) $stu['id'], 'MAM Academy — Prepare Before Your Next Class', $body, 'academic')) $sent++;
        }
    }
    return $sent;
}

/** #5 — absence notice for students with no attendance on a recently-held session. */
function mam_acadmail_absence_sweep(int $lookbackHours = 24, int $limit = 100): int {
    if (!mam_acadmail_enabled()) return 0;
    $since = mam_session_wall(time() - $lookbackHours * 3600); $now = mam_session_now(); // vs scheduled_at (Lagos)
    $sent = 0;
    try {
        $sessions = mam_db_all("SELECT id, cohort_id, title FROM class_sessions WHERE status IN ('held','scheduled') AND scheduled_at >= ? AND scheduled_at <= ? LIMIT " . max(1, $limit), [$since, $now]);
    } catch (\Throwable $e) { return 0; }
    foreach ($sessions as $s) {
        foreach (mam_acadmail_roster((int) $s['cohort_id']) as $stu) {
            $has = mam_db_row('SELECT 1 FROM session_attendance WHERE session_id = ? AND student_id = ? LIMIT 1', [(int) $s['id'], (int) $stu['id']]);
            if ($has) continue;
            $body = '<p>Our records show no attendance for <strong>' . htmlspecialchars((string) $s['title'], ENT_QUOTES) . '</strong>.</p><p>Please contact the academy if this is in error.</p>';
            if (mam_acadmail_dispatch('acadmail.absence.s' . (int) $s['id'], (int) $stu['id'], 'MAM Academy — Attendance Alert', $body, 'academic')) $sent++;
        }
    }
    return $sent;
}

/** #6 — preparation alert for imminent sessions where prep is incomplete. */
function mam_acadmail_prep_alert_sweep(int $hours = 6, int $limit = 100): int {
    if (!mam_acadmail_enabled()) return 0;
    $now = mam_session_now(); $until = mam_session_wall(time() + $hours * 3600); // vs scheduled_at (Lagos)
    $sent = 0;
    try {
        $sessions = mam_db_all("SELECT id, cohort_id, title, lesson_id FROM class_sessions WHERE status = 'scheduled' AND lesson_id IS NOT NULL AND scheduled_at > ? AND scheduled_at <= ? LIMIT " . max(1, $limit), [$now, $until]);
    } catch (\Throwable $e) { return 0; }
    foreach ($sessions as $s) {
        foreach (mam_acadmail_roster((int) $s['cohort_id']) as $stu) {
            $lp = mam_db_row('SELECT status FROM lesson_progress WHERE lesson_id = ? AND student_id = ?', [(int) $s['lesson_id'], (int) $stu['id']]);
            if ($lp && (string) $lp['status'] === 'completed') continue;
            $body = '<p>Your preparation for <strong>' . htmlspecialchars((string) $s['title'], ENT_QUOTES) . '</strong> is not yet complete.</p><p>Please finish it before class.</p>';
            if (mam_acadmail_dispatch('acadmail.prep.s' . (int) $s['id'], (int) $stu['id'], 'MAM Academy — Pre-Class Preparation Pending', $body, 'academic')) $sent++;
        }
    }
    return $sent;
}

/**
 * Run all four swept academic emails (§28) and return per-type counts. Each
 * sweep is itself gated + idempotent, so this is safe to call from the jobs
 * runner or the admin "run sweeps" button; returns zeros when the layer is off.
 */
function mam_acadmail_run_all_sweeps(): array {
    return [
        'enabled'  => mam_acadmail_enabled(),
        'preclass' => mam_acadmail_preclass_reminder_sweep(),
        'absence'  => mam_acadmail_absence_sweep(),
        'prep'     => mam_acadmail_prep_alert_sweep(),
        'weekly'   => mam_acadmail_weekly_report_sweep(),
    ];
}

/** #7 — weekly learning report, once per ISO week per active student. */
function mam_acadmail_weekly_report_sweep(int $limit = 500): int {
    if (!mam_acadmail_enabled()) return 0;
    $week = date('oW'); // ISO year+week — the per-week dedup bucket
    $sent = 0;
    try {
        $students = mam_db_all("SELECT DISTINCT student_id FROM course_enrollments WHERE status IN ('active','completed') LIMIT " . max(1, $limit));
    } catch (\Throwable $e) { return 0; }
    foreach ($students as $row) {
        $sid = (int) $row['student_id'];
        $att = function_exists('mam_checkin_attendance_rate') ? mam_checkin_attendance_rate($sid) : null;
        // A child with no sessions yet has no attendance rate. Printing "0%"
        // told a guardian their child had attended nothing, which was not what
        // had happened — say so plainly instead.
        $body = '<p>Here is this week\'s summary.</p><p>Attendance: <strong>'
              . ($att === null ? 'no sessions yet' : $att . '%') . '</strong></p>';
        if (mam_acadmail_dispatch('acadmail.weekly.' . $week, $sid, 'MAM Academy — Weekly Learning Report', $body, 'academic')) $sent++;
    }
    return $sent;
}
