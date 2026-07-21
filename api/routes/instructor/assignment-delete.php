<?php
/**
 * POST /api/instructor/assignment-delete
 * Body: { id: int, force?: bool }
 *
 * Deletes one assignment row and its submissions (ON DELETE CASCADE). Instructors
 * may only delete their own; admins may delete any.
 *
 * If the assignment has submissions and `force` is not true, the route returns
 * 409 with { error: "has_submissions", submission_count: N } so the caller can
 * show a confirmation prompt before re-posting with force=true.
 */
declare(strict_types=1);

$u  = mam_require_role('instructor', 'admin');
$in = mam_json_input();

$id    = (int)($in['id'] ?? 0);
$force = !empty($in['force']);
if ($id <= 0) mam_fail('validation', 422, ['fields' => ['id']]);

$row = mam_db_row('SELECT id, author_id, title FROM assignments WHERE id = ?', [$id]);
if (!$row) mam_fail('assignment_not_found', 404);

$isAdmin = mam_user_has_role($u, 'admin');
if (!$isAdmin && (int)$row['author_id'] !== (int)$u['id']) mam_fail('forbidden', 403);

if (!$force) {
    $subCount = (int)(mam_db_row('SELECT COUNT(*) AS n FROM submissions WHERE assignment_id = ?', [$id])['n'] ?? 0);
    if ($subCount > 0) {
        mam_fail('has_submissions', 409, [
            'detail'           => 'This assignment has ' . $subCount . ' submission(s). Delete anyway by re-sending with force=true.',
            'submission_count' => $subCount,
        ]);
    }
}

mam_db()->prepare('DELETE FROM assignments WHERE id = ?')->execute([$id]);
mam_audit('assignment_delete', 'assignment', $id, ['title' => $row['title'], 'forced' => $force]);
mam_ok(['deleted' => 1]);
