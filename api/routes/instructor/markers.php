<?php
/**
 * Timestamped feedback markers (Advanced Modernization §1.1).
 *
 *   GET  /api/instructor/markers?submission_id=…            → list markers
 *   POST /api/instructor/markers {submission_id, at, comment} → add
 *   POST /api/instructor/markers {action:'delete', id}        → delete own
 *
 * The instructor must own the submission's assignment (admins bypass).
 */
declare(strict_types=1);

$u       = mam_require_role('instructor', 'admin');
$isAdmin = function_exists('mam_user_has_role') ? mam_user_has_role($u, 'admin') : ($u['role'] === 'admin');

$guard = function (int $sid) use ($u, $isAdmin) {
    $sub = mam_lms_submission($sid);
    if (!$sub) mam_fail('submission_not_found', 404);
    if (!$isAdmin && (int)($sub['assignment_author_id'] ?? 0) !== (int)$u['id']) mam_fail('forbidden', 403);
    return $sub;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $sid = (int)($_GET['submission_id'] ?? 0);
    $guard($sid);
    mam_ok(['markers' => mam_lms_markers($sid)]);
}

$in     = mam_json_input();
$action = (string)($in['action'] ?? 'add');

if ($action === 'delete') {
    $mid = (int)($in['id'] ?? 0);
    $row = mam_db_row('SELECT submission_id FROM submission_markers WHERE id = ?', [$mid]);
    if (!$row) mam_fail('not_found', 404);
    $guard((int)$row['submission_id']);
    mam_lms_marker_delete($mid, null);
    mam_ok(['markers' => mam_lms_markers((int)$row['submission_id'])]);
}

$sid = (int)($in['submission_id'] ?? 0);
$guard($sid);
try {
    mam_lms_marker_add($sid, (int)$u['id'], (float)($in['at'] ?? 0), (string)($in['comment'] ?? ''));
} catch (\Throwable $e) {
    mam_fail($e->getMessage() === 'comment_required' ? 'comment_required' : 'marker_failed', 422, ['detail' => $e->getMessage()]);
}
mam_ok(['markers' => mam_lms_markers($sid)]);
