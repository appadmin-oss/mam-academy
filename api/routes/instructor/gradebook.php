<?php
/** GET /api/instructor/gradebook — the instructor's active course enrolments
 *  (with current result) plus the weighted assessment components. */
declare(strict_types=1);
$u = mam_require_role('instructor', 'admin');
mam_ok([
    'enrollments' => mam_lms_instructor_gradebook((int)$u['id']),
    'types'       => mam_db_all('SELECT code, name, weight FROM assessment_types WHERE active = 1 ORDER BY sort_order'),
    'pass_mark'   => mam_lms_pass_mark(),
]);
