<?php
/**
 * Growth and accountability (schema v102): where a student stood each week,
 * the goals they are working to, and one timeline the student, their family
 * and their teachers all read.
 *
 * Before this the mastery score was only ever "now" — nobody could answer
 * "is she getting better?", which is the question every family asks. So:
 *
 *   · A weekly SNAPSHOT (mam_growth_snapshot_all, from the job runner) of the
 *     published mastery band and its coverage. Stored, never recomputed on
 *     read: a week's standing must not move when a mark is corrected months
 *     later — the correction shows up in the week it was made.
 *   · The TIMELINE is weekly: practice minutes, classes attended, lessons
 *     finished, work handed in and its marks, the band where there is one.
 *     The band is the published one (mam_mastery_publishable), so a
 *     withheld composite stays withheld here too.
 *   · GOALS are a student's own promises, or a teacher's suggestions, with
 *     progress worked out from the data where it can be (practice minutes a
 *     week, lessons finished, work handed in) and ticked by a person where
 *     it cannot. A goal is never a ranking and never compared with a
 *     classmate's.
 *   · Teachers now see practice and streaks too — they could not before.
 */
declare(strict_types=1);

const MAM_GOAL_KINDS = [
    'practice_week' => 'Practise minutes each week',
    'lessons'       => 'Finish lessons',
    'handins'       => 'Hand in class work',
    'custom'        => 'Something else',
];

/** May this person see this student's growth? Themselves, a teacher of theirs, a guardian whose scope reaches reports, an admin. */
function mam_growth_can_view(int $studentId, array $user): bool {
    if ((int) $user['id'] === $studentId || mam_user_has_role($user, 'admin')) return true;
    if (mam_user_has_role($user, 'parent') && function_exists('mam_parent_scope_allows')) return mam_parent_scope_allows((int) $user['id'], $studentId, 'reports');
    return function_exists('mam_media_student_reader') && mam_user_has_role($user, 'instructor') && mam_media_student_reader($studentId, $user);
}

/* ─── Snapshots ─────────────────────────────────────────────────────────── */

/** The Monday a date falls in, as Y-m-d (weeks start on Monday, in Lagos). */
function mam_growth_week(?string $date = null): string {
    $d = $date ?: (function_exists('mam_calendar_today') ? mam_calendar_today() : gmdate('Y-m-d'));
    $ts = strtotime($d . ' 12:00 UTC');
    return gmdate('Y-m-d', $ts - (((int) gmdate('N', $ts)) - 1) * 86400);
}

/** Take this week's snapshot for one student. Idempotent within the week: the latest of the week stands. */
function mam_growth_snapshot(int $studentId, ?string $week = null): void {
    $week ??= mam_growth_week();
    if (function_exists('mam_mastery_forget')) mam_mastery_forget($studentId);
    $m = mam_mastery_publishable(mam_mastery_score($studentId));
    $band = is_array($m['band'] ?? null) ? $m['band'] : null;
    $subs = [];
    foreach ((array) ($m['components'] ?? []) as $c) if (isset($c['key'], $c['pct']) && $c['pct'] !== null) $subs[(string) $c['key']] = round((float) $c['pct'], 1);
    $row = ['coverage_pct' => (float) ($m['coverage_pct'] ?? 0), 'band_low' => $band ? (int) ($band[0] ?? $band['low'] ?? 0) : null,
            'band_high' => $band ? (int) ($band[1] ?? $band['high'] ?? 0) : null, 'level_label' => (string) ($m['level_label'] ?? ''),
            'components' => $subs ? json_encode($subs) : null, 'taken_at' => gmdate('Y-m-d H:i:s')];
    if (mam_db_row('SELECT 1 FROM mastery_snapshots WHERE student_id = ? AND week_of = ?', [$studentId, $week])) {
        mam_db_update('mastery_snapshots', $row, ['student_id' => $studentId, 'week_of' => $week]);
    } else {
        mam_db_insert('mastery_snapshots', $row + ['student_id' => $studentId, 'week_of' => $week]);
    }
}

/** The job runner's weekly pass over every active student. */
function mam_growth_snapshot_all(int $limit = 2000): int {
    $n = 0; $week = mam_growth_week();
    foreach (mam_db_all("SELECT DISTINCT u.id FROM users u WHERE u.role = 'student' AND u.status = 'active'
                          AND NOT EXISTS (SELECT 1 FROM mastery_snapshots s WHERE s.student_id = u.id AND s.week_of = ?) LIMIT " . max(1, $limit), [$week]) as $r) {
        try { mam_growth_snapshot((int) $r['id'], $week); $n++; } catch (Throwable $e) { mam_log('warn', 'growth_snapshot_failed', ['student' => (int) $r['id'], 'err' => $e->getMessage()]); }
    }
    return $n;
}

/* ─── The timeline ──────────────────────────────────────────────────────── */

/**
 * The last $weeks weeks, oldest first, each with what happened. Missing data
 * is null, never zero: a week with no class scheduled has no attendance rate.
 */
function mam_growth_timeline(int $studentId, int $weeks = 12): array {
    $weeks = max(4, min(52, $weeks));
    $thisWeek = mam_growth_week();
    $start = gmdate('Y-m-d', strtotime($thisWeek . ' UTC') - ($weeks - 1) * 7 * 86400);
    $series = [];
    for ($i = 0; $i < $weeks; $i++) {
        $w = gmdate('Y-m-d', strtotime($start . ' UTC') + $i * 7 * 86400);
        $series[$w] = ['week' => $w, 'practice_min' => 0, 'classes' => null, 'attended' => null, 'lessons' => 0, 'handins' => 0,
                       'marks' => [], 'band' => null, 'coverage' => null];
    }
    $wk = static fn(?string $d) => $d ? mam_growth_week(substr($d, 0, 10)) : null;
    foreach (mam_db_all('SELECT minutes, logged_on FROM practice_logs WHERE student_id = ? AND logged_on >= ?', [$studentId, $start]) as $r) {
        $w = $wk((string) $r['logged_on']); if (isset($series[$w])) $series[$w]['practice_min'] += (int) $r['minutes'];
    }
    foreach (mam_db_all("SELECT cs.scheduled_at, sa.status FROM class_sessions cs
                          JOIN course_enrollments ce ON ce.cohort_id = cs.cohort_id AND ce.student_id = ? AND ce.status IN ('active','completed')
                          LEFT JOIN session_attendance sa ON sa.session_id = cs.id AND sa.student_id = ce.student_id
                         WHERE cs.scheduled_at >= ? AND cs.scheduled_at <= ? AND COALESCE(cs.status,'scheduled') NOT IN ('cancelled','canceled')",
                        [$studentId, $start, gmdate('Y-m-d H:i:s')]) as $r) {
        $w = $wk((string) $r['scheduled_at']); if (!isset($series[$w])) continue;
        $series[$w]['classes'] = (int) $series[$w]['classes'] + 1;
        $series[$w]['attended'] = (int) $series[$w]['attended'] + (in_array($r['status'], ['present', 'late', 'left_early'], true) ? 1 : 0);
    }
    foreach (mam_db_all("SELECT completed_at FROM module_progress WHERE student_id = ? AND completed_at >= ?", [$studentId, $start]) as $r) {
        $w = $wk((string) $r['completed_at']); if (isset($series[$w])) $series[$w]['lessons']++;
    }
    foreach (mam_db_all("SELECT created_at, score, max_score, reviewed_at FROM space_posts WHERE author_id = ? AND kind = 'submission' AND status <> 'removed' AND created_at >= ?", [$studentId, $start]) as $r) {
        $w = $wk((string) $r['created_at']); if (!isset($series[$w])) continue;
        $series[$w]['handins']++;
        if ($r['reviewed_at'] && (int) $r['max_score'] > 0) $series[$w]['marks'][] = (int) round((int) $r['score'] / (int) $r['max_score'] * 100);
    }
    foreach (mam_db_all('SELECT week_of, band_low, band_high, coverage_pct FROM mastery_snapshots WHERE student_id = ? AND week_of >= ?', [$studentId, $start]) as $r) {
        if (!isset($series[$r['week_of']])) continue;
        $series[$r['week_of']]['band'] = $r['band_low'] !== null ? [(int) $r['band_low'], (int) $r['band_high']] : null;
        $series[$r['week_of']]['coverage'] = (float) $r['coverage_pct'];
    }
    $out = array_values($series);
    foreach ($out as &$w) $w['mark_avg'] = $w['marks'] ? (int) round(array_sum($w['marks']) / count($w['marks'])) : null;
    unset($w);
    return ['weeks' => $out, 'summary' => mam_growth_summary($out), 'practice' => function_exists('mam_practice_stats') ? mam_practice_stats($studentId) : null];
}

/**
 * Plain sentences comparing the last four weeks with the four before — what
 * a family actually wants to know. Only said where both halves have data.
 */
function mam_growth_summary(array $weeks): array {
    $n = count($weeks); if ($n < 8) return [];
    $recent = array_slice($weeks, $n - 4); $before = array_slice($weeks, $n - 8, 4);
    $sum = static fn(array $ws, string $k) => array_sum(array_map(static fn($w) => (int) ($w[$k] ?? 0), $ws));
    $out = [];
    $pr = $sum($recent, 'practice_min'); $pb = $sum($before, 'practice_min');
    if ($pr || $pb) $out[] = ['key' => 'practice', 'up' => $pr >= $pb, 'text' => 'Practice: ' . $pr . ' minutes in the last four weeks, ' . ($pr >= $pb ? 'up from ' : 'down from ') . $pb . '.'];
    $cr = $sum($recent, 'classes'); $ar = $sum($recent, 'attended'); $cb = $sum($before, 'classes'); $abf = $sum($before, 'attended');
    if ($cr > 0) $out[] = ['key' => 'attendance', 'up' => $cb === 0 || $ar / $cr >= $abf / max(1, $cb), 'text' => 'Classes: at ' . $ar . ' of ' . $cr . ' in the last four weeks' . ($cb ? ', ' . $abf . ' of ' . $cb . ' before.' : '.')];
    $lr = $sum($recent, 'lessons');
    if ($lr || $sum($before, 'lessons')) $out[] = ['key' => 'lessons', 'up' => $lr >= $sum($before, 'lessons'), 'text' => 'Lessons finished: ' . $lr . ' in the last four weeks.'];
    $bands = array_values(array_filter(array_map(static fn($w) => $w['band'], $weeks)));
    if (count($bands) >= 2) {
        $first = $bands[0]; $last = $bands[count($bands) - 1];
        $out[] = ['key' => 'band', 'up' => $last[0] >= $first[0], 'text' => 'Where they stand: ' . $first[0] . '–' . $first[1] . ' then, ' . $last[0] . '–' . $last[1] . ' now.'];
    }
    return $out;
}

/* ─── Goals ─────────────────────────────────────────────────────────────── */

function mam_goal_save(int $studentId, array $in, array $actor): int {
    if (!mam_growth_can_view($studentId, $actor) || mam_user_has_role($actor, 'parent')) throw new RuntimeException('forbidden');
    $kind = isset(MAM_GOAL_KINDS[$in['kind'] ?? '']) ? (string) $in['kind'] : 'custom';
    $title = trim((string) ($in['title'] ?? ''));
    if ($title === '') $title = MAM_GOAL_KINDS[$kind];
    $target = $kind === 'custom' ? null : (int) ($in['target'] ?? 0);
    if ($kind !== 'custom' && ($target < 1 || $target > 10000)) throw new InvalidArgumentException('Say how many.');
    $due = trim((string) ($in['due_on'] ?? ''));
    if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) throw new InvalidArgumentException('Give the date as a date.');
    if ((int) (mam_db_row("SELECT COUNT(*) AS n FROM student_goals WHERE student_id = ? AND status = 'active'", [$studentId])['n'] ?? 0) >= 5) {
        throw new InvalidArgumentException('Five goals at once is plenty. Finish or drop one first.');
    }
    $id = (int) mam_db_insert('student_goals', ['student_id' => $studentId, 'kind' => $kind, 'title' => mb_substr($title, 0, 160),
        'target' => $target, 'due_on' => $due ?: null, 'status' => 'active', 'set_by' => (int) $actor['id'],
        'created_at' => gmdate('Y-m-d H:i:s')]);
    mam_audit('goal_set', 'user', $studentId, ['goal' => $id, 'kind' => $kind, 'by' => (int) $actor['id']]);
    if ((int) $actor['id'] !== $studentId) { try { mam_notify($studentId, 'goal', 'Your teacher suggested a goal', $title, null); } catch (Throwable $e) {} }
    return $id;
}

/** How far along a goal is: worked out from the data where it can be. */
function mam_goal_progress(array $g): array {
    $sid = (int) $g['student_id']; $from = substr((string) $g['created_at'], 0, 10);
    $now = null;
    switch ($g['kind']) {
        case 'practice_week':
            $wk = mam_growth_week();
            $now = (int) (mam_db_row('SELECT COALESCE(SUM(minutes),0) AS n FROM practice_logs WHERE student_id = ? AND logged_on >= ?', [$sid, $wk])['n'] ?? 0);
            break;
        case 'lessons':
            $now = (int) (mam_db_row('SELECT COUNT(*) AS n FROM module_progress WHERE student_id = ? AND completed_at >= ?', [$sid, $from])['n'] ?? 0);
            break;
        case 'handins':
            $now = (int) (mam_db_row("SELECT COUNT(*) AS n FROM space_posts WHERE author_id = ? AND kind = 'submission' AND status <> 'removed' AND created_at >= ?", [$sid, $from])['n'] ?? 0);
            break;
    }
    $target = $g['target'] !== null ? (int) $g['target'] : null;
    return ['now' => $now, 'target' => $target, 'pct' => $target && $now !== null ? (int) min(100, round($now / $target * 100)) : null,
            'met' => $target !== null && $now !== null && $now >= $target];
}

function mam_goals_for(int $studentId): array {
    $out = [];
    foreach (mam_db_all("SELECT g.*, u.name AS set_by_name FROM student_goals g LEFT JOIN users u ON u.id = g.set_by
                          WHERE g.student_id = ? AND (g.status = 'active' OR g.updated_at >= ?) ORDER BY g.status, g.id DESC",
                        [$studentId, gmdate('Y-m-d H:i:s', time() - 60 * 86400)]) as $g) {
        $p = mam_goal_progress($g);
        // A goal measured from data closes itself when it is met — the student does not have to come back and say so.
        if ($g['status'] === 'active' && $p['met'] && $g['kind'] !== 'practice_week') {
            mam_db_update('student_goals', ['status' => 'achieved', 'achieved_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => (int) $g['id']]);
            $g['status'] = 'achieved';
        }
        $out[] = ['id' => (int) $g['id'], 'kind' => (string) $g['kind'], 'title' => (string) $g['title'], 'status' => (string) $g['status'],
            'due_on' => $g['due_on'], 'set_by' => (int) $g['set_by'] === (int) $g['student_id'] ? 'you' : (string) ($g['set_by_name'] ?? 'a teacher'),
            'progress' => $p];
    }
    return $out;
}

/** Mark a goal done or dropped. The student or a teacher of theirs. */
function mam_goal_close(int $goalId, string $status, array $actor): void {
    $g = mam_db_row('SELECT * FROM student_goals WHERE id = ?', [$goalId]);
    if (!$g || !mam_growth_can_view((int) $g['student_id'], $actor) || mam_user_has_role($actor, 'parent')) throw new RuntimeException('not_found');
    if (!in_array($status, ['achieved', 'dropped', 'active'], true)) throw new InvalidArgumentException('Done, dropped or still going.');
    mam_db_update('student_goals', ['status' => $status, 'achieved_at' => $status === 'achieved' ? gmdate('Y-m-d H:i:s') : null,
        'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $goalId]);
    mam_audit('goal_' . $status, 'user', (int) $g['student_id'], ['goal' => $goalId, 'by' => (int) $actor['id']]);
}

/** Everything one growth screen needs: the timeline, goals, and — for a teacher only — the adaptive suggestion per course. */
function mam_growth_view(int $studentId, array $viewer): array {
    if (!mam_growth_can_view($studentId, $viewer)) throw new RuntimeException('not_found');
    $u = mam_db_row('SELECT id, name FROM users WHERE id = ?', [$studentId]);
    $out = ['student' => ['id' => $studentId, 'name' => (string) ($u['name'] ?? '')], 'timeline' => mam_growth_timeline($studentId), 'goals' => mam_goals_for($studentId),
            'can_set_goals' => !mam_user_has_role($viewer, 'parent') || (int) $viewer['id'] === $studentId, 'goal_kinds' => MAM_GOAL_KINDS];
    if (mam_user_has_role($viewer, 'instructor') || mam_user_has_role($viewer, 'admin')) {
        $out['suggestions'] = [];
        foreach (mam_db_all("SELECT DISTINCT ce.course_id, c.name FROM course_enrollments ce JOIN courses c ON c.id = ce.course_id
                              WHERE ce.student_id = ? AND ce.status IN ('active','completed')", [$studentId]) as $c) {
            if (function_exists('mam_adaptive_suggestion')) $out['suggestions'][] = ['course' => (string) $c['name']] + mam_adaptive_suggestion($studentId, (int) $c['course_id']);
        }
    }
    return $out;
}
