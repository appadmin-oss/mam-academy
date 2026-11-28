<?php
/**
 * GET  /api/instructor/progress-report?session_id=N
 *   → {session, students:[…], reports:{student_id: report}, template}
 *
 * POST /api/instructor/progress-report
 *   {session_id, student_id, answers:{label:value,…}, narrative?}
 *   → {ok, report_id, email_sent}
 *
 * Instructor must teach the cohort; admins can access any session.
 */
declare(strict_types=1);

$u       = mam_require_role('instructor', 'admin');
$me      = (int)$u['id'];
$isAdmin = in_array('admin', mam_user_roles($u), true);

$ownsSession = function (int $sessionId) use ($me, $isAdmin): bool {
    if ($isAdmin) return (bool)mam_db_row('SELECT 1 FROM class_sessions WHERE id = ?', [$sessionId]);
    // The class's teachers and the cover booked for this session (teaching.php) —
    // they were refused the report they are expected to write — or a teacher
    // named on a student's own enrolment in that class (a 1:1 placement).
    if (mam_teaches_session($me, $sessionId)) return true;
    return (bool)mam_db_row(
        "SELECT 1 FROM class_sessions cs
           JOIN course_enrollments ce ON ce.cohort_id = cs.cohort_id
          WHERE cs.id = ? AND ce.instructor_id = ? AND ce.status IN ('active','pending') LIMIT 1",
        [$sessionId, $me]
    );
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $sessionId = (int)($_GET['session_id'] ?? 0);
    if ($sessionId < 1) mam_fail('missing_field', 400, ['field' => 'session_id']);
    if (!$ownsSession($sessionId)) mam_fail('forbidden', 403);

    $session  = mam_lms_session($sessionId);
    $students = mam_progress_report_students_for_session($sessionId);
    $reports  = mam_progress_reports_for_session($sessionId);
    $template = mam_progress_report_template();

    mam_ok([
        'session'  => $session,
        'students' => $students,
        'reports'  => $reports,
        'template' => $template,
    ]);
}

$in        = mam_json_input();
$sessionId = (int)($in['session_id'] ?? 0);
$studentId = (int)($in['student_id'] ?? 0);
$answers   = (isset($in['answers']) && is_array($in['answers'])) ? $in['answers'] : [];
$narrative = isset($in['narrative']) ? trim((string)$in['narrative']) : null;

if ($sessionId < 1) mam_fail('missing_field', 400, ['field' => 'session_id']);
if ($studentId < 1) mam_fail('missing_field', 400, ['field' => 'student_id']);
if (!$ownsSession($sessionId)) mam_fail('forbidden', 403);

// Confirm student is in the cohort
$student = mam_db_row(
    "SELECT u.id FROM users u
       JOIN course_enrollments ce ON ce.student_id = u.id
       JOIN class_sessions cs     ON cs.cohort_id  = ce.cohort_id
      WHERE cs.id = ? AND u.id = ? AND ce.status = 'active' LIMIT 1",
    [$sessionId, $studentId]
);
if (!$student) mam_fail('student_not_in_session', 404);

try {
    $reportId = mam_progress_report_submit($sessionId, $studentId, $me, $answers, null, $narrative ?: null);
    $report   = mam_db_row('SELECT email_sent FROM progress_reports WHERE id = ?', [$reportId]);
    mam_ok(['ok' => true, 'report_id' => $reportId, 'email_sent' => (bool)($report['email_sent'] ?? false)]);
} catch (\RuntimeException $e) {
    mam_fail($e->getMessage(), 404);
}
