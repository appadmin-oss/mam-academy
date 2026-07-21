<?php
/**
 * POST /api/instructor/announce  { class_id? , cohort_id? , title?, body }
 * Posts an announcement to a legacy class OR a cohort the caller teaches
 * (admins: any). Enrolled students — in either model — see it in their portal.
 */
declare(strict_types=1);

$u = mam_require_role('instructor', 'admin');
$in = mam_json_input();
$classId  = (int)($in['class_id'] ?? 0);
$cohortId = (int)($in['cohort_id'] ?? 0);
$title = mam_str($in, 'title', 160) ?: null;
$body  = mam_str($in, 'body', 4000);
$isAdmin = ($u['role'] === 'admin');

if (!$classId && !$cohortId) mam_fail('validation', 422, ['fields' => ['class_id', 'cohort_id']]);
if ($body === '') mam_fail('validation', 422, ['fields' => ['body']]);

if ($cohortId) {
    // Dual-read (P2): announce to a cohort's students (course_enrollments).
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
}

$cls = mam_db_row('SELECT id, instructor_id FROM classes WHERE id = ?', [$classId]);
if (!$cls) mam_fail('class_not_found', 404);
if (!$isAdmin && (int)$cls['instructor_id'] !== (int)$u['id']) mam_fail('forbidden', 403);

$id = mam_db_insert('announcements', [
    'class_id' => $classId,
    'author_id' => (int)$u['id'],
    'title' => $title,
    'body' => $body,
]);
mam_audit('announce', 'class', $classId, ['announcement_id' => $id]);
mam_ok(['announcement_id' => $id]);
