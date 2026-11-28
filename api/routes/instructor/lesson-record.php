<?php
/**
 * POST /api/instructor/lesson-record
 * Save lesson notes, topics covered, and learning outcomes for a class session.
 * Instructor must teach the cohort the session belongs to (via course_enrollments).
 *
 * {session_id, lesson_notes, topics, learning_outcomes}
 */
declare(strict_types=1);
$u = mam_require_role('instructor', 'admin');

$in        = mam_json_input();
$sessionId = (int)($in['session_id'] ?? 0);
if ($sessionId < 1) mam_fail('missing_field', 400, ['field' => 'session_id']);

$session = mam_db_row('SELECT id, cohort_id FROM class_sessions WHERE id = ?', [$sessionId]);
if (!$session) mam_fail('not_found', 404);

// Admin can write to any session; instructors only to their cohorts.
if (!in_array('admin', mam_user_roles($u), true)) {
    // The session's lesson notes belong to whoever teaches it: the class's staff
    // or the cover booked for it (teaching.php). Used to be "named on an
    // enrolment in the cohort", which refused the lead and the cover.
    if (!mam_teaches_session((int)$u['id'], $sessionId)) mam_fail('forbidden', 403);
}

mam_lesson_record_save(
    $sessionId,
    (string)($in['lesson_notes'] ?? ''),
    (string)($in['topics'] ?? ''),
    (string)($in['learning_outcomes'] ?? '')
);
mam_audit('lesson_record_save', 'class_session', $sessionId, ['by' => (int)$u['id']]);
mam_ok(['saved' => true]);
