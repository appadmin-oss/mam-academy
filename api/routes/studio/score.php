<?php
/**
 * POST /api/studio/score  — opt-in: post a Rhythm/Pitch run to the leaderboard.
 * Body: {tool, score}. Student-only. Upserts the student's best and returns
 * their new best + rank. The student chooses to call this (it is not automatic).
 */
declare(strict_types=1);

$u = mam_require_role('student');
$in = mam_json_input();
$tool = (string)($in['tool'] ?? '');
$score = (int)($in['score'] ?? -1);

try {
    $res = mam_studio_submit_score((int)$u['id'], $tool, $score);
} catch (RuntimeException $e) {
    mam_fail($e->getMessage(), 422);
}
mam_ok(['result' => $res, 'rank' => mam_studio_rank((int)$u['id'], $tool)]);
