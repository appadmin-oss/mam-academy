<?php
/**
 * POST /api/instructor/assignment-delete
 * Body: { id: int }
 *
 * Deletes one assignment row. Instructors may only delete assignments they
 * authored; admins may delete any. (Class fan-outs create one row per pupil,
 * so deleting removes exactly the row shown in the list.)
 */
declare(strict_types=1);

$u  = mam_require_role('instructor', 'admin');
$in = mam_json_input();

$id = (int)($in['id'] ?? 0);
if ($id <= 0) mam_fail('validation', 422, ['fields' => ['id']]);

$row = mam_db_row('SELECT id, author_id, title FROM assignments WHERE id = ?', [$id]);
if (!$row) mam_fail('assignment_not_found', 404);

$isAdmin = mam_user_has_role($u, 'admin');
if (!$isAdmin && (int)$row['author_id'] !== (int)$u['id']) mam_fail('forbidden', 403);

mam_db()->prepare('DELETE FROM assignments WHERE id = ?')->execute([$id]);
mam_audit('assignment_delete', 'assignment', $id, ['title' => $row['title']]);
mam_ok(['deleted' => 1]);
