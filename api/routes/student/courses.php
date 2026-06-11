<?php
/** GET /api/student/courses — the signed-in student's course enrolments. */
declare(strict_types=1);
$u = mam_require_role('student');
mam_ok(['courses' => mam_lms_student_courses((int)$u['id'])]);
