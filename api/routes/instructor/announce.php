<?php
/**
 * POST /api/instructor/announce  { cohort_id, title?, body }
 * Posts an announcement to a cohort the caller teaches (admins: any). The
 * cohort's enrolled students see it in their portal.
 *
 * Cohort model only. A legacy class_id is no longer accepted: the class model
 * is retired and mam_legacy_carry() moved every class's announcements onto its
 * cohort.
 */
declare(strict_types=1);

$u = mam_require_role('instructor', 'admin');
$in = mam_json_input();
$cohortId = (int)($in['cohort_id'] ?? 0);
$title = mam_str($in, 'title', 160) ?: null;
$body  = mam_str($in, 'body', 4000);
$isAdmin = ($u['role'] === 'admin');

if (!$cohortId) mam_fail('validation', 422, ['fields' => ['cohort_id']]);
if ($body === '') mam_fail('validation', 422, ['fields' => ['body']]);

if (!$isAdmin && !mam_lms_instructor_owns_cohort((int)$u['id'], $cohortId)) mam_fail('forbidden', 403);
if ($isAdmin && !mam_db_row('SELECT 1 FROM cohorts WHERE id = ?', [$cohortId])) mam_fail('cohort_not_found', 404);

$id = mam_db_insert('announcements', [
    'cohort_id' => $cohortId,
    'author_id' => (int)$u['id'],
    'title' => $title,
    'body' => $body,
]);
mam_audit('announce', 'cohort', $cohortId, ['announcement_id' => $id]);
mam_ok(['announcement_id' => $id]);
