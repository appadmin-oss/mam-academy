<?php
/**
 * GET /api/student/upcoming-sessions
 * Returns the next scheduled class sessions for the student's cohort enrollments.
 */
declare(strict_types=1);

$u = mam_require_role('student');

$sessions = mam_db_all(
    "SELECT cs.id, cs.title AS session_title, cs.scheduled_at, cs.duration_min, cs.kind, cs.location, cs.notes,
            coh.title AS cohort_title, coh.id AS cohort_id,
            c.name AS course_name
       FROM class_sessions cs
       JOIN cohorts coh ON coh.id = cs.cohort_id
       JOIN course_enrollments ce ON ce.cohort_id = coh.id
       LEFT JOIN courses c ON c.id = coh.course_id
      WHERE ce.student_id = ? AND ce.status = 'active'
        AND cs.scheduled_at >= ?
      ORDER BY cs.scheduled_at ASC
      LIMIT 20",
    // scheduled_at is Lagos wall-clock; CURRENT_TIMESTAMP is the engine's UTC.
    [(int)$u['id'], mam_session_now()]
);

mam_ok(['sessions' => $sessions]);
