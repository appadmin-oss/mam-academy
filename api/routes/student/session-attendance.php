<?php
/**
 * GET /api/student/session-attendance
 * The signed-in student's cohort session attendance — the LMS L2 dual-read view
 * (alongside the legacy class attendance in the dashboard payload). Read-only.
 */
declare(strict_types=1);

$u = mam_require_role('student');

mam_ok(['attendance' => mam_lms_student_session_attendance((int)$u['id'])]);
