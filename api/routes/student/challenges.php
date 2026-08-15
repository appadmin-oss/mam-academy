<?php
/**
 * Student practice challenges (Advanced Modernization §3.3).
 *
 *   GET  /api/student/challenges                                   → {challenges, metrics}
 *   POST /api/student/challenges {action:'add', title, metric, target, starts_on, ends_on}
 *   POST /api/student/challenges {action:'delete', id}
 */
declare(strict_types=1);

$u   = mam_require_role('student');
$uid = (int) $u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    mam_ok(['challenges' => mam_challenge_list($uid), 'metrics' => mam_challenge_metrics()]);
}

$in     = mam_json_input();
$action = (string) ($in['action'] ?? 'add');
try {
    if ($action === 'add') {
        mam_challenge_create($uid, (string) ($in['title'] ?? ''), (string) ($in['metric'] ?? 'sessions'), (int) ($in['target'] ?? 1), (string) ($in['starts_on'] ?? ''), (string) ($in['ends_on'] ?? ''));
    } elseif ($action === 'delete') {
        if (!mam_challenge_delete((int) ($in['id'] ?? 0), $uid)) mam_fail('not_found', 404);
    } else {
        mam_fail('unknown_action', 400, ['action' => $action]);
    }
} catch (\InvalidArgumentException $e) {
    mam_fail($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    mam_fail($e->getMessage(), $e->getMessage() === 'forbidden' ? 403 : 400);
}

mam_ok(['challenges' => mam_challenge_list($uid)]);
