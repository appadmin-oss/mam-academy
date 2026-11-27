<?php
/**
 * MAM Academy — practice challenges (Advanced Modernization §3.3).
 * ─────────────────────────────────────────────────────────────
 * A time-boxed personal goal ("practise 4× this week", "log 150 minutes").
 * Progress is derived from practice_logs in the window, so a challenge can
 * never disagree with the practice tab. Structure and momentum, no pressure.
 */
declare(strict_types=1);

function mam_challenge_metrics(): array { return ['sessions', 'minutes']; }

/** Create a personal challenge. Returns the new id. */
function mam_challenge_create(int $studentId, string $title, string $metric, int $target, string $startsOn, string $endsOn): int {
    $title = trim($title);
    if ($title === '') throw new InvalidArgumentException('title_required');
    if (!in_array($metric, mam_challenge_metrics(), true)) $metric = 'sessions';
    $target = max(1, $target);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsOn) || $endsOn < $startsOn) {
        throw new InvalidArgumentException('bad_dates');
    }
    return mam_db_insert('practice_challenges', [
        'student_id' => $studentId,
        'title'      => mb_substr($title, 0, 160),
        'metric'     => $metric,
        'target'     => $target,
        'starts_on'  => $startsOn,
        'ends_on'    => $endsOn,
    ]);
}

/** Progress (sessions logged or minutes summed) within the challenge window. */
function mam_challenge_progress(array $ch): int {
    $sid = (int) $ch['student_id'];
    $col = (($ch['metric'] ?? 'sessions') === 'minutes') ? 'COALESCE(SUM(minutes),0)' : 'COUNT(*)';
    $r = mam_db_row(
        "SELECT $col AS v FROM practice_logs WHERE student_id = ? AND logged_on >= ? AND logged_on <= ?",
        [$sid, $ch['starts_on'], $ch['ends_on']]
    );
    return (int) ($r['v'] ?? 0);
}

/** A student's challenges, newest first, each decorated with progress/pct/state. */
function mam_challenge_list(int $studentId, ?string $today = null): array {
    $today = $today ?: date('Y-m-d');
    $rows = mam_db_all("SELECT * FROM practice_challenges WHERE student_id = ? ORDER BY ends_on DESC, id DESC", [$studentId]);
    foreach ($rows as &$c) {
        $prog = mam_challenge_progress($c);
        $tgt  = max(1, (int) $c['target']);
        $c['progress'] = $prog;
        $c['pct']      = min(100, (int) round($prog / $tgt * 100));
        $c['active']   = ($c['starts_on'] <= $today && $c['ends_on'] >= $today);
        $c['done']     = $prog >= $tgt;
    }
    unset($c);
    return $rows;
}

/** Delete a challenge the student owns. */
function mam_challenge_delete(int $id, int $studentId): bool {
    $row = mam_db_row("SELECT student_id FROM practice_challenges WHERE id = ?", [$id]);
    if (!$row) return false;
    if ((int) $row['student_id'] !== $studentId) throw new RuntimeException('forbidden');
    mam_db()->prepare("DELETE FROM practice_challenges WHERE id = ?")->execute([$id]);
    return true;
}
