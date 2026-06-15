<?php
/**
 * Studio leaderboard (S2) — opt-in cohort competition over the scored tools.
 *
 * One row per (user, tool) holds the student's BEST score; submission is
 * explicit (the student chooses to post a run). Reads minimise PII — only a
 * first name is ever exposed on the board. Everything is best-effort where it
 * touches a request path, but the service throws on clearly invalid input so
 * the route can return a clean 4xx.
 */
declare(strict_types=1);

/** Tools that keep a 0-100 score and therefore a leaderboard. */
function mam_studio_tools(): array { return ['rhythm', 'pitchgame']; }

/** First name (or initial) only — never the full identity on a public-ish board. */
function mam_studio_display_name(?string $name): string {
    $name = trim((string)$name);
    if ($name === '') return 'Student';
    $first = trim(explode(' ', $name)[0]);
    return $first !== '' ? $first : 'Student';
}

/**
 * Submit a run for a user. Upserts their BEST for the tool and bumps plays.
 * Returns ['best','improved','plays']. Throws invalid_tool / invalid_score.
 */
function mam_studio_submit_score(int $userId, string $tool, int $score): array {
    if ($userId <= 0) throw new RuntimeException('invalid_user');
    if (!in_array($tool, mam_studio_tools(), true)) throw new RuntimeException('invalid_tool');
    if ($score < 0 || $score > 100) throw new RuntimeException('invalid_score');

    $row = mam_db_row('SELECT id, best_score, plays FROM tool_scores WHERE user_id = ? AND tool = ?', [$userId, $tool]);
    $now = date('Y-m-d H:i:s');
    if (!$row) {
        mam_db_insert('tool_scores', ['user_id' => $userId, 'tool' => $tool, 'best_score' => $score, 'plays' => 1]);
        return ['best' => $score, 'improved' => true, 'plays' => 1];
    }
    $best = max((int)$row['best_score'], $score);
    $improved = $score > (int)$row['best_score'];
    mam_db_update('tool_scores', ['best_score' => $best, 'plays' => (int)$row['plays'] + 1, 'updated_at' => $now], ['id' => (int)$row['id']]);
    return ['best' => $best, 'improved' => $improved, 'plays' => (int)$row['plays'] + 1];
}

/** Top N for a tool (best score desc, then earliest to reach it). First names only. */
function mam_studio_leaderboard(string $tool, int $limit = 10): array {
    if (!in_array($tool, mam_studio_tools(), true)) return [];
    $rows = mam_db_all(
        "SELECT ts.user_id, ts.best_score, ts.plays, u.name
           FROM tool_scores ts JOIN users u ON u.id = ts.user_id
          WHERE ts.tool = ? AND u.status = 'active'
          ORDER BY ts.best_score DESC, ts.updated_at ASC
          LIMIT " . max(1, min(100, $limit)),
        [$tool]
    );
    $out = []; $rank = 0;
    foreach ($rows as $r) {
        $rank++;
        $out[] = ['rank' => $rank, 'name' => mam_studio_display_name($r['name']), 'best_score' => (int)$r['best_score'], 'plays' => (int)$r['plays']];
    }
    return $out;
}

/** A user's own best + 1-based rank for a tool, or null if they haven't posted. */
function mam_studio_rank(int $userId, string $tool): ?array {
    if ($userId <= 0 || !in_array($tool, mam_studio_tools(), true)) return null;
    $me = mam_db_row('SELECT best_score, plays FROM tool_scores WHERE user_id = ? AND tool = ?', [$userId, $tool]);
    if (!$me) return null;
    $ahead = (int)(mam_db_row('SELECT COUNT(*) AS n FROM tool_scores WHERE tool = ? AND best_score > ?', [$tool, (int)$me['best_score']])['n'] ?? 0);
    return ['rank' => $ahead + 1, 'best_score' => (int)$me['best_score'], 'plays' => (int)$me['plays']];
}
