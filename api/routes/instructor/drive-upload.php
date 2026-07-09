<?php
/**
 * POST /api/instructor/drive-upload
 * Body: { name, mime, data (base64), title?, class_id? , cohort_id? }
 *
 * Uploads a file to Google Drive via the Apps Script gateway (#5, no OAuth),
 * then records it as a learning resource on a legacy class OR a cohort the
 * caller teaches — so it flows through the converged dual-read resources
 * surface (course-only students see it too). Returns the new resource + the
 * fetchable Drive URL. Instructor/admin only, CSRF-protected, rate-limited.
 */
declare(strict_types=1);

$u = mam_require_role('instructor', 'admin');
if (!mam_drive_enabled()) mam_fail('drive_disabled', 503, ['detail' => 'File upload is not configured. An admin must set up the Drive Apps Script (drive_webapp_url + drive_shared_secret).']);

$in = mam_json_input();
$isAdmin  = ($u['role'] === 'admin');
$classId  = (int)($in['class_id'] ?? 0);
$cohortId = (int)($in['cohort_id'] ?? 0);
$name     = mam_str($in, 'name', 200);
$mime     = mam_str($in, 'mime', 100);
$title    = mam_str($in, 'title', 200) ?: $name;
$b64      = (string)($in['data'] ?? '');

if (!$classId && !$cohortId) mam_fail('validation', 422, ['fields' => ['class_id', 'cohort_id']]);
if ($name === '' || $b64 === '') mam_fail('validation', 422, ['fields' => ['name', 'data']]);

// Ownership FIRST — never upload for a target the caller can't post to.
if ($cohortId) {
    if (!$isAdmin && !mam_lms_instructor_owns_cohort((int)$u['id'], $cohortId)) mam_fail('forbidden', 403);
    if ($isAdmin && !mam_db_row('SELECT 1 FROM cohorts WHERE id = ?', [$cohortId])) mam_fail('cohort_not_found', 404);
} else {
    $cls = mam_db_row('SELECT id, instructor_id FROM classes WHERE id = ?', [$classId]);
    if (!$cls) mam_fail('class_not_found', 404);
    if (!$isAdmin && (int)$cls['instructor_id'] !== (int)$u['id']) mam_fail('forbidden', 403);
}

$bytes = base64_decode($b64, true);
if ($bytes === false || $bytes === '') mam_fail('validation', 422, ['fields' => ['data'], 'detail' => 'File data must be base64.']);

$up = mam_drive_upload($name, $mime, $bytes, ['user_id' => (int)$u['id']]);
if (!$up['ok']) {
    $code = in_array($up['error'], ['too_large', 'mime_not_allowed', 'empty_file'], true) ? 422 : 502;
    mam_fail('upload_failed', $code, ['detail' => $up['error']]);
}

// Map the Drive mime to the resource type vocabulary (link|sheet|video|doc).
$type = 'doc';
if (strpos($mime, 'video/') === 0) $type = 'video';
elseif (strpos($mime, 'spreadsheetml') !== false) $type = 'sheet';

$row = ['title' => $title, 'url' => $up['url'], 'type' => $type, 'posted_by' => (int)$u['id']];
if ($cohortId) $row['cohort_id'] = $cohortId; else $row['class_id'] = $classId;
$rid = mam_db_insert('class_resources', $row);
mam_audit('resource_add', $cohortId ? 'cohort' : 'class', $cohortId ?: $classId, ['resource_id' => $rid, 'drive_file_id' => $up['file_id'], 'via' => 'drive']);

mam_ok(['resource_id' => (int)$rid, 'url' => $up['url'], 'download_url' => $up['download_url'], 'file_id' => $up['file_id'], 'type' => $type]);
