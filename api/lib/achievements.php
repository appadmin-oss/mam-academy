<?php
/**
 * MAM Academy — achievements & the guardian "wins" feed.
 * ─────────────────────────────────────────────────────────────
 * Advanced Modernization §3.1 (warm, specific badges tied to real progress),
 * §3.5 (a gentle stream of a child's positive moments for guardians).
 *
 * Badges are awarded once per (student, code) from the data the academy
 * already captures — attendance, submissions, practice, results. Evaluation
 * is idempotent and best-effort; it never blocks the triggering action.
 */
declare(strict_types=1);

/** code => [title, icon] for every badge MAM can award. */
function mam_ach_defs(): array {
    return [
        'first_submission' => ['First submission in',   'paper-plane'],
        'first_accepted'   => ['First accepted piece',  'circle-check'],
        'streak_7'         => ['7 sessions in a row',    'fire'],
        'streak_30'        => ['30-session streak',      'fire-flame-curved'],
        'practice_10h'     => ['10 hours practised',     'clock'],
        'practice_50h'     => ['50 hours practised',     'stopwatch'],
        'level_pass'       => ['Passed a course',        'graduation-cap'],
    ];
}

/** Earned badges for a student (newest first). */
function mam_ach_list(int $studentId): array {
    return mam_db_all(
        "SELECT code, title, awarded_at FROM achievements WHERE student_id = ? ORDER BY awarded_at DESC, id DESC",
        [$studentId]
    );
}

/** Award a badge once. Returns true only if it was newly granted. */
function mam_ach_award(int $studentId, string $code, string $title): bool {
    try {
        if (mam_db_row('SELECT id FROM achievements WHERE student_id = ? AND code = ?', [$studentId, $code])) return false;
        mam_db_insert('achievements', ['student_id' => $studentId, 'code' => $code, 'title' => $title]);
    } catch (\Throwable $e) {
        return false; // unique-race or missing table → treat as already-awarded
    }
    if (function_exists('mam_notify')) {
        mam_notify($studentId, 'achievement', 'New achievement: ' . $title, 'You earned a badge — well done!', '/portal/student/');
    }
    return true;
}

/**
 * Evaluate all badge conditions for a student and award any newly earned.
 * Returns the full current badge list. Safe to call on any read.
 */
function mam_ach_evaluate(int $studentId): array {
    $defs = mam_ach_defs();
    $has  = function (string $sql) use ($studentId) {
        try { return (bool) mam_db_row($sql, [$studentId]); } catch (\Throwable $e) { return false; }
    };
    if ($has("SELECT id FROM submissions WHERE student_id = ? LIMIT 1"))                 mam_ach_award($studentId, 'first_submission', $defs['first_submission'][0]);
    if ($has("SELECT id FROM submissions WHERE student_id = ? AND status = 'accepted' LIMIT 1")) mam_ach_award($studentId, 'first_accepted', $defs['first_accepted'][0]);

    $streak = function_exists('mam_checkin_streak') ? mam_checkin_streak($studentId, 1) : 0;
    if ($streak >= 7)  mam_ach_award($studentId, 'streak_7',  $defs['streak_7'][0]);
    if ($streak >= 30) mam_ach_award($studentId, 'streak_30', $defs['streak_30'][0]);

    $mins = 0;
    try { $mins = (int) (mam_db_row("SELECT COALESCE(SUM(minutes),0) AS m FROM practice_logs WHERE student_id = ?", [$studentId])['m'] ?? 0); } catch (\Throwable $e) {}
    if ($mins >= 600)  mam_ach_award($studentId, 'practice_10h', $defs['practice_10h'][0]);
    if ($mins >= 3000) mam_ach_award($studentId, 'practice_50h', $defs['practice_50h'][0]);

    if ($has("SELECT r.id FROM results r JOIN course_enrollments ce ON ce.id = r.enrollment_id WHERE ce.student_id = ? AND r.outcome = 'pass' LIMIT 1")) {
        mam_ach_award($studentId, 'level_pass', $defs['level_pass'][0]);
    }
    return mam_ach_list($studentId);
}

/** Attach the icon for a badge code (for the UI). */
function mam_ach_icon(string $code): string {
    $d = mam_ach_defs();
    return $d[$code][1] ?? 'award';
}

/**
 * §3.5 — the guardian "wins" feed: a gentle, reverse-chronological stream of a
 * child's positive moments, assembled from existing engines. Never negative.
 */
function mam_wins_feed(int $studentId, int $limit = 15): array {
    $wins = [];
    $soft = function (string $sql) use ($studentId) { try { return mam_db_all($sql, [$studentId]); } catch (\Throwable $e) { return []; } };

    foreach ($soft("SELECT title, awarded_at AS at FROM achievements WHERE student_id = ? ORDER BY awarded_at DESC LIMIT 10") as $r) {
        $wins[] = ['icon' => 'trophy', 'text' => 'Earned "' . $r['title'] . '"', 'at' => $r['at']];
    }
    foreach ($soft("SELECT minutes, created_at AS at FROM practice_logs WHERE student_id = ? ORDER BY created_at DESC LIMIT 10") as $r) {
        $wins[] = ['icon' => 'music', 'text' => 'Practised ' . (int) $r['minutes'] . ' min', 'at' => $r['at']];
    }
    foreach ($soft("SELECT a.title AS t, COALESCE(f.updated_at, f.created_at) AS at
                      FROM feedback f JOIN submissions s ON s.id = f.submission_id
                 LEFT JOIN assignments a ON a.id = s.assignment_id
                     WHERE s.student_id = ? AND f.outcome = 'accepted'
                     ORDER BY COALESCE(f.updated_at, f.created_at) DESC LIMIT 10") as $r) {
        $wins[] = ['icon' => 'circle-check', 'text' => 'Work accepted: ' . ($r['t'] ?: 'a submission'), 'at' => $r['at']];
    }
    foreach ($soft("SELECT cs.title AS t, cs.scheduled_at AS at
                      FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id
                     WHERE sa.student_id = ? AND sa.status IN ('present','late')
                     ORDER BY cs.scheduled_at DESC LIMIT 10") as $r) {
        $wins[] = ['icon' => 'calendar-check', 'text' => 'Attended ' . ($r['t'] ?: 'a session'), 'at' => $r['at']];
    }
    usort($wins, fn($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
    return array_slice($wins, 0, max(1, $limit));
}
