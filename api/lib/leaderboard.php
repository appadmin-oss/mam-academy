<?php
/**
 * MAM Academy — opt-in effort leaderboards (Advanced Modernization §3.4).
 * ─────────────────────────────────────────────────────────────
 * Light social motivation that can't become shaming: ranked by EFFORT
 * (this-week practice minutes), not talent; first-name + last-initial only;
 * OFF by default; and a student appears only with data consent, so minors are
 * guardian-consented. Scoped to the student's own cohort(s).
 */
declare(strict_types=1);

function mam_leaderboard_optin_get(int $userId): bool {
    $r = mam_db_row('SELECT leaderboard_opt_in FROM users WHERE id = ?', [$userId]);
    return $r ? (bool) $r['leaderboard_opt_in'] : false;
}

function mam_leaderboard_optin_set(int $userId, bool $on): void {
    mam_db_update('users', ['leaderboard_opt_in' => $on ? 1 : 0], ['id' => $userId]);
}

/** A student appears only if opted-in AND data-consented (guardian-consented). */
function mam_leaderboard_eligible(int $userId): bool {
    if (!mam_leaderboard_optin_get($userId)) return false;
    if (function_exists('mam_consent_allows') && !mam_consent_allows($userId, 'data')) return false;
    return true;
}

/** Privacy-safe display: first name + last initial. */
function mam_leaderboard_display_name(?string $name): string {
    $name = trim((string) $name);
    if ($name === '') return 'A student';
    $parts = preg_split('/\s+/', $name);
    $first = $parts[0];
    $lastInit = (count($parts) > 1) ? ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.' : '';
    return $first . $lastInit;
}

/**
 * Effort leaderboard for a student's cohort(s): this-week practice minutes
 * among opted-in, consented peers. Returns ranked rows with is_me flagged.
 */
function mam_leaderboard_for_student(int $studentId, ?string $today = null, int $limit = 20): array {
    $today   = $today ?: date('Y-m-d');
    $weekAgo = date('Y-m-d', strtotime($today . ' -6 days'));
    $cohorts = function_exists('mam_checkin_student_cohorts') ? mam_checkin_student_cohorts($studentId) : [];
    if (!$cohorts) return [];
    $ph = implode(',', array_fill(0, count($cohorts), '?'));
    $peers = mam_db_all(
        "SELECT DISTINCT ce.student_id AS id, u.name
           FROM course_enrollments ce JOIN users u ON u.id = ce.student_id
          WHERE ce.cohort_id IN ($ph) AND ce.status IN ('active','completed') AND u.status = 'active'",
        $cohorts
    );
    $rows = [];
    foreach ($peers as $p) {
        $pid = (int) $p['id'];
        if (!mam_leaderboard_eligible($pid)) continue;
        $min = (int) (mam_db_row("SELECT COALESCE(SUM(minutes),0) AS m FROM practice_logs WHERE student_id = ? AND logged_on >= ? AND logged_on <= ?", [$pid, $weekAgo, $today])['m'] ?? 0);
        $rows[] = ['id' => $pid, 'name' => mam_leaderboard_display_name($p['name']), 'minutes' => $min];
    }
    usort($rows, function ($a, $b) { return $b['minutes'] <=> $a['minutes'] ?: strcmp($a['name'], $b['name']); });
    $out = []; $rank = 0;
    foreach (array_slice($rows, 0, $limit) as $r) {
        $rank++;
        $out[] = ['rank' => $rank, 'name' => $r['name'], 'minutes' => $r['minutes'], 'is_me' => $r['id'] === $studentId];
    }
    return $out;
}
