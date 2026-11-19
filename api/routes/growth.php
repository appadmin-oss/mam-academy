<?php
/**
 * Growth and goals (api/lib/growth.php, v102).
 *
 *   GET  /api/growth[?student=N]                → {growth}  (yourself by default; a teacher, guardian or admin names the student)
 *   POST {action:'goal', student?, kind, title, target?, due_on?}
 *   POST {action:'goal_close', goal_id, status:'achieved'|'dropped'|'active'}
 */
declare(strict_types=1);
$u = mam_require_user();
$sid = (int) ($_GET['student'] ?? 0) ?: (int) $u['id'];
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') mam_ok(['growth' => mam_growth_view($sid, $u)]);
    $in = mam_json_input();
    $sid = (int) ($in['student'] ?? 0) ?: (int) $u['id'];
    switch ((string) ($in['action'] ?? '')) {
        case 'goal': mam_ok(['id' => mam_goal_save($sid, $in, $u), 'goals' => mam_goals_for($sid)]);
        case 'goal_close':
            mam_goal_close((int) ($in['goal_id'] ?? 0), (string) ($in['status'] ?? ''), $u);
            mam_ok(['done' => true]);
    }
    mam_fail('unknown_action', 400);
} catch (InvalidArgumentException $e) {
    mam_fail('validation', 422, ['message' => $e->getMessage()]);
} catch (RuntimeException $e) {
    mam_fail('not_found', 404, ['message' => 'That student was not found.']);
}
