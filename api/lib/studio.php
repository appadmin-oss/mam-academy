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

    // Star rating + a short seal label from the Trinity band.
    $stars = ['Distinction' => 3, 'Merit' => 2, 'Pass' => 1][$band] ?? 0;
    $sealLabel = ['Distinction' => 'DISTINCTION', 'Merit' => 'MERIT', 'Pass' => 'PASS'][$band] ?? 'KEEP GOING';
    $sealSize  = strlen($sealLabel) > 6 ? 30 : 42;
    $starRow = '';
    for ($i = 0; $i < 3; $i++) $starRow .= '<tspan fill="' . ($i < $stars ? '#FBBF24' : '#4a3a24') . '">&#9733;</tspan>';
    $toolUpper = $esc(strtoupper($toolLabel));

    return '<?xml version="1.0" encoding="UTF-8"?>'
      . '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630" role="img" aria-label="' . $esc($who . ' ' . $score . ' / 100 — ' . $band . ' on ' . $toolLabel) . '">'
      . '<defs>'
      .   '<linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#1B130B"/><stop offset="1" stop-color="#0E0A06"/></linearGradient>'
      .   '<linearGradient id="gold" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#E08A1A"/><stop offset="1" stop-color="#FBBF24"/></linearGradient>'
      .   '<linearGradient id="goldv" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#FBBF24"/><stop offset="1" stop-color="#E08A1A"/></linearGradient>'
      .   '<radialGradient id="sealg" cx="0.5" cy="0.38" r="0.62"><stop offset="0" stop-color="#2A1D10"/><stop offset="1" stop-color="#140E08"/></radialGradient>'
      . '</defs>'
      . '<rect width="1200" height="630" fill="url(#bg)"/>'
      // decorative concentric rings, bottom-right
      . '<g opacity="0.06" stroke="#FBBF24" fill="none" stroke-width="2"><circle cx="1090" cy="580" r="120"/><circle cx="1090" cy="580" r="185"/><circle cx="1090" cy="580" r="250"/></g>'
      // card frame + top gold accent
      . '<rect x="28" y="28" width="1144" height="574" rx="26" fill="none" stroke="#3a2a18" stroke-width="2"/>'
      . '<rect x="28" y="28" width="1144" height="6" rx="3" fill="url(#gold)"/>'
      // logo lockup (equalizer mark + wordmark)
      . '<g transform="translate(72,68)"><rect width="58" height="58" rx="13" fill="#1A120B" stroke="url(#gold)" stroke-width="2"/>'
      .   '<rect x="13" y="32" width="6" height="14" rx="3" fill="url(#gold)"/><rect x="23" y="22" width="6" height="24" rx="3" fill="url(#gold)"/>'
      .   '<rect x="33" y="28" width="6" height="18" rx="3" fill="url(#gold)"/><rect x="43" y="18" width="6" height="28" rx="3" fill="url(#gold)"/></g>'
      . '<text x="148" y="96" font-family="Georgia,Times New Roman,serif" font-size="30" font-weight="bold" fill="#FFFFFF" letter-spacing="2">MAM ACADEMY</text>'
      . '<text x="150" y="119" font-family="Arial,Helvetica,sans-serif" font-size="13" fill="#FBBF24" letter-spacing="6">PRACTICE STUDIO</text>'
      // achievement (left column)
      . '<text x="72" y="248" font-family="Arial,Helvetica,sans-serif" font-size="22" fill="#cbb89e" letter-spacing="4">' . $toolUpper . '</text>'
      . '<text x="72" y="300" font-family="Georgia,Times New Roman,serif" font-size="36" fill="#FBF7F3">' . $who . '</text>'
      . '<text x="68" y="452" font-family="Arial,Helvetica,sans-serif" font-weight="900" font-size="172" fill="url(#goldv)">' . $score . '<tspan font-size="54" font-weight="700" fill="#8a7355"> /100</tspan></text>'
      . '<text x="76" y="512" font-family="Arial,Helvetica,sans-serif" font-size="46" letter-spacing="8">' . $starRow . '</text>'
      // award seal (right column)
      . '<g transform="translate(958,288)">'
      .   '<circle r="150" fill="url(#sealg)" stroke="url(#gold)" stroke-width="6"/><circle r="128" fill="none" stroke="#E08A1A" stroke-width="1.5" opacity="0.65"/>'
      .   '<text x="0" y="-72" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="15" fill="#FBBF24" letter-spacing="5">RESULT</text>'
      .   '<text x="0" y="14" text-anchor="middle" font-family="Georgia,Times New Roman,serif" font-weight="bold" font-size="' . $sealSize . '" fill="url(#gold)" letter-spacing="2">' . $esc($sealLabel) . '</text>'
      .   '<text x="0" y="66" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="34" letter-spacing="8">' . $starRow . '</text>'
      .   '<text x="0" y="104" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="13" fill="#8a7355" letter-spacing="3">TRINITY-ALIGNED</text>'
      . '</g>'
      // footer
      . '<line x1="72" y1="552" x2="1128" y2="552" stroke="#3a2a18" stroke-width="1.5"/>'
      . '<text x="72" y="584" font-family="Arial,Helvetica,sans-serif" font-size="21" fill="#cbb89e">academy.mamcentre.org</text>'
      . '<text x="1128" y="584" text-anchor="end" font-family="Arial,Helvetica,sans-serif" font-size="21" font-weight="700" fill="#FBBF24">Train like a complete musician &#8594;</text>'
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
