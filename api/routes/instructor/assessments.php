<?php
/**
 * GET /api/instructor/assessments?enrollment_id=N
 * The recorded marks for one course enrolment the instructor teaches (admins may
 * view any), so the gradebook can list them for correction (remark) or voiding.
 */
declare(strict_types=1);
$u = mam_require_role('instructor', 'admin');

$eid = (int)($_GET['enrollment_id'] ?? 0);
if ($eid <= 0) mam_fail('validation', 422, ['fields' => ['enrollment_id']]);

$ce = mam_db_row('SELECT id, instructor_id FROM course_enrollments WHERE id = ?', [$eid]);
if (!$ce) mam_fail('enrollment_not_found', 404);
if (!mam_user_has_role($u, 'admin') && (int)$ce['instructor_id'] !== (int)$u['id']) {
    mam_fail('forbidden', 403);
}

mam_ok([
    'assessments' => mam_lms_enrollment_assessments($eid),
    'types'       => mam_db_all('SELECT code, name, weight FROM assessment_types WHERE active = 1 ORDER BY sort_order'),
]);
