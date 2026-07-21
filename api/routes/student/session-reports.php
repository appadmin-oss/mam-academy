<?php
/**
 * Student: per-session progress reports submitted by their instructor.
 *
 * GET → {reports:[{id, submitted_at, session_at, session_kind,
 *                  cohort_title, instructor_name, narrative, answers}]}
 */
declare(strict_types=1);
$u = mam_require_role('student');
$reports = mam_student_session_reports((int)$u['id']);
mam_ok(['reports' => $reports]);
