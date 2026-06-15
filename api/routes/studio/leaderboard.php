<?php
/**
 * GET /api/studio/leaderboard?tool=rhythm&limit=10
 * The opt-in cohort leaderboard for a scored tool (first names only). Any
 * signed-in user; includes the caller's own rank when they're a student who
 * has posted.
 */
declare(strict_types=1);

$u = mam_require_user();
$tool = (string)($_GET['tool'] ?? '');
if (!in_array($tool, mam_studio_tools(), true)) mam_fail('invalid_tool', 422);

$limit = (int)($_GET['limit'] ?? 10);
mam_ok([
    'tool'        => $tool,
    'leaderboard' => mam_studio_leaderboard($tool, $limit > 0 ? $limit : 10),
    'me'          => mam_studio_rank((int)$u['id'], $tool),
]);
