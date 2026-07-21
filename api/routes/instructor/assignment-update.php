<?php
/**
 * POST /api/instructor/assignment-update
 * Body: { id: int, title?: string, instructions?: string, due_on?: string|null }
 *
 * Edits an existing assignment's editable fields. Instructors may only edit
 * their own assignments; admins may edit any. Completed/submitted assignments
 * can still be edited (e.g. to extend a due date or clarify instructions).
 * Only the fields included in the body are updated.
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

$updates = [];
if (array_key_exists('title', $in)) {
    $t = mam_str($in, 'title', 160);
    if ($t === '') mam_fail('validation', 422, ['fields' => ['title'], 'detail' => 'Title cannot be blank.']);
    $updates['title'] = $t;
}
if (array_key_exists('instructions', $in)) {
    $updates['instructions'] = mam_str($in, 'instructions', 4000) ?: null;
}
if (array_key_exists('due_on', $in)) {
    $d = $in['due_on'];
    $updates['due_on'] = ($d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d)) ? $d : null;
}

if (!$updates) mam_ok(['updated' => 0]);

mam_db_update('assignments', $updates, ['id' => $id]);
mam_audit('assignment_update', 'assignment', $id, $updates);
mam_ok(['updated' => 1]);
