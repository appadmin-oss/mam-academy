<?php
/**
 * POST /api/instructor/resource  { class_id, title, url, type? }
 * Adds a learning resource (link / material) to a class the caller teaches.
 * Enrolled students see it in their portal. type: link|sheet|video|doc.
 */
declare(strict_types=1);

$u = mam_require_role('instructor', 'admin');
$in = mam_json_input();
$classId = (int)($in['class_id'] ?? 0);
$title = mam_str($in, 'title', 200);
$url   = mam_str($in, 'url', 500);
$type  = mam_str($in, 'type', 16) ?: 'link';
if (!in_array($type, ['link', 'sheet', 'video', 'doc'], true)) $type = 'link';

if (!$classId)        mam_fail('validation', 422, ['fields' => ['class_id']]);
if ($title === '')    mam_fail('validation', 422, ['fields' => ['title']]);
// URL must be a real http(s) link so we never store javascript: / data: URIs.
if ($url === '' || !preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    mam_fail('validation', 422, ['fields' => ['url']]);
}

$cls = mam_db_row('SELECT id, instructor_id FROM classes WHERE id = ?', [$classId]);
if (!$cls) mam_fail('class_not_found', 404);
if ($u['role'] !== 'admin' && (int)$cls['instructor_id'] !== (int)$u['id']) mam_fail('forbidden', 403);

$id = mam_db_insert('class_resources', [
    'class_id'  => $classId,
    'title'     => $title,
    'url'       => $url,
    'type'      => $type,
    'posted_by' => (int)$u['id'],
]);
mam_audit('resource_add', 'class', $classId, ['resource_id' => $id]);
mam_ok(['resource_id' => $id]);
