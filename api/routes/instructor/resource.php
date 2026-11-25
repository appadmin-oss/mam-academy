<?php
/**
 * POST /api/instructor/resource  { cohort_id, title, url, type? }
 * Adds a learning resource (link / material) to a cohort the caller teaches
 * (admins: any). The cohort's enrolled students see it in their portal.
 * type: link|sheet|video|doc.
 *
 * Cohort model only. A legacy class_id is no longer accepted: the class model
 * is retired and mam_legacy_carry() moved every class's resources onto its
 * cohort.
 */
declare(strict_types=1);

$u = mam_require_role('instructor', 'admin');
$in = mam_json_input();
$cohortId = (int)($in['cohort_id'] ?? 0);
$title = mam_str($in, 'title', 200);
$url   = mam_str($in, 'url', 500);
$type  = mam_str($in, 'type', 16) ?: 'link';
$isAdmin = ($u['role'] === 'admin');
if (!in_array($type, ['link', 'sheet', 'video', 'doc'], true)) $type = 'link';

if (!$cohortId)     mam_fail('validation', 422, ['fields' => ['cohort_id']]);
if ($title === '')  mam_fail('validation', 422, ['fields' => ['title']]);
// URL must be a real http(s) link so we never store javascript: / data: URIs.
if ($url === '' || !preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    mam_fail('validation', 422, ['fields' => ['url']]);
}

if (!$isAdmin && !mam_lms_instructor_owns_cohort((int)$u['id'], $cohortId)) mam_fail('forbidden', 403);
if ($isAdmin && !mam_db_row('SELECT 1 FROM cohorts WHERE id = ?', [$cohortId])) mam_fail('cohort_not_found', 404);

$id = mam_db_insert('class_resources', [
    'cohort_id' => $cohortId,
    'title'     => $title,
    'url'       => $url,
    'type'      => $type,
    'posted_by' => (int)$u['id'],
]);
mam_audit('resource_add', 'cohort', $cohortId, ['resource_id' => $id]);
mam_ok(['resource_id' => $id]);
