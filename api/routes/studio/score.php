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

// Server-authoritative input hygiene. The score is a client-attested practice
// result (a mic-driven run the server can't recompute), so at minimum require a
// well-formed number and never silently coerce junk (e.g. "abc" → 0, or a float
// → a truncated int) into a leaderboard row. Range + tool + role are enforced by
// mam_studio_submit_score and the route middleware.
$rawScore = $in['score'] ?? null;
if (!is_numeric($rawScore)) mam_fail('invalid_score', 422, ['detail' => 'score must be a number between 0 and 100']);
$score = (int) round((float) $rawScore);

try {
    $res = mam_studio_submit_score((int)$u['id'], $tool, $score);
} catch (RuntimeException $e) {
    mam_fail($e->getMessage(), 422);
}
mam_ok(['result' => $res, 'rank' => mam_studio_rank((int)$u['id'], $tool)]);
