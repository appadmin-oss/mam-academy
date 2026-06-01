<?php
/**
 * POST /api/instructor/announce  { class_id, title?, body }
 * Posts an announcement to a class the caller teaches (admins: any class).
 * Enrolled students see it in their portal.
 */
declare(strict_types=1);

$u = mam_require_role('instructor', 'admin');
$in = mam_json_input();
$classId = (int)($in['class_id'] ?? 0);
$title = mam_str($in, 'title', 160) ?: null;
$body  = mam_str($in, 'body', 4000);

if (!$classId) mam_fail('validation', 422, ['fields' => ['class_id']]);
if ($body === '') mam_fail('validation', 422, ['fields' => ['body']]);

$cls = mam_db_row('SELECT id, instructor_id FROM classes WHERE id = ?', [$classId]);
if (!$cls) mam_fail('class_not_found', 404);
if ($u['role'] !== 'admin' && (int)$cls['instructor_id'] !== (int)$u['id']) mam_fail('forbidden', 403);

$id = mam_db_insert('announcements', [
    'class_id' => $classId,
    'author_id' => (int)$u['id'],
    'title' => $title,
    'body' => $body,
]);
mam_audit('announce', 'class', $classId, ['announcement_id' => $id]);
mam_ok(['announcement_id' => $id]);
