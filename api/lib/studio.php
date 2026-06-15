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

/** Trinity-aligned band for a 0-100 score (mirrors the Rhythm grading). */
function mam_studio_band(int $score): string {
    if ($score >= 85) return 'Distinction';
    if ($score >= 70) return 'Merit';
    if ($score >= 60) return 'Pass';
    return 'Keep practising';
}

/**
 * Server-rendered shareable score card as an SVG (S2). Crisp at any size, no
 * font dependency, safe to serve to anyone (it's a vanity asset). All inputs are
 * clamped and XML-escaped: tool → allowlist label, score → 0-100, name → first
 * name only (PII-min). 1200×630 for social embeds.
 */
function mam_studio_share_svg(string $tool, int $score, string $name = ''): string {
    $labels = ['rhythm' => 'Rhythm Tap', 'pitchgame' => 'Pitch Game'];
    $toolLabel = $labels[$tool] ?? 'Practice Studio';
    $score = max(0, min(100, $score));
    $band  = mam_studio_band($score);
    $first = mam_studio_display_name($name);
    $esc = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8'); };
    $who = $first !== 'Student' ? ($esc($first) . ' scored') : 'I scored';

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630" role="img" aria-label="' . $esc($who . ' ' . $score . ' on ' . $toolLabel) . '">'
        . '<defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="#120D08"/><stop offset="1" stop-color="#2E2015"/></linearGradient>'
        . '<linearGradient id="gold" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#E08A1A"/><stop offset="1" stop-color="#FBBF24"/></linearGradient></defs>'
        . '<rect width="1200" height="630" fill="url(#bg)"/>'
        . '<rect x="40" y="40" width="1120" height="550" rx="28" fill="none" stroke="#3a2a18" stroke-width="2"/>'
        . '<text x="80" y="130" font-family="Georgia,serif" font-size="34" fill="#FBBF24" font-weight="bold" letter-spacing="3">MAM ACADEMY</text>'
        . '<text x="80" y="180" font-family="Arial,sans-serif" font-size="28" fill="#cbb89e">' . $esc($toolLabel) . ' · Practice Studio</text>'
        . '<text x="80" y="300" font-family="Arial,sans-serif" font-size="40" fill="#FBF7F3">' . $who . '</text>'
        . '<text x="80" y="450" font-family="Arial,sans-serif" font-size="200" fill="url(#gold)" font-weight="900">' . $score . '<tspan font-size="70" fill="#cbb89e">/100</tspan></text>'
        . '<rect x="80" y="495" width="' . (200 + strlen($band) * 22) . '" height="60" rx="30" fill="url(#gold)"/>'
        . '<text x="110" y="535" font-family="Arial,sans-serif" font-size="34" fill="#1A120B" font-weight="800">' . $esc($band) . '</text>'
        . '<text x="1120" y="560" text-anchor="end" font-family="Arial,sans-serif" font-size="24" fill="#8a7355">mam-academy · practice that counts</text>'
        . '</svg>';
}

/** A user's own best + 1-based rank for a tool, or null if they haven't posted. */
function mam_studio_rank(int $userId, string $tool): ?array {
    if ($userId <= 0 || !in_array($tool, mam_studio_tools(), true)) return null;
    $me = mam_db_row('SELECT best_score, plays FROM tool_scores WHERE user_id = ? AND tool = ?', [$userId, $tool]);
    if (!$me) return null;
    $ahead = (int)(mam_db_row('SELECT COUNT(*) AS n FROM tool_scores WHERE tool = ? AND best_score > ?', [$tool, (int)$me['best_score']])['n'] ?? 0);
    return ['rank' => $ahead + 1, 'best_score' => (int)$me['best_score'], 'plays' => (int)$me['plays']];
}
