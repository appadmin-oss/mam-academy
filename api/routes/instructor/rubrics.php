<?php
/**
 * Instructor rubric management + rubric-grid scoring (LMS 2.0, PDF §20-21).
 *
 *   GET  /api/instructor/rubrics                     → {rubrics:[…criteria…], types:[…]}
 *   POST /api/instructor/rubrics {action:'create', title, cohort_id?, criteria:[{label,max_points,weight}]}
 *   POST /api/instructor/rubrics {action:'grade', enrollment_id, rubric_id, scores:{critId:pts}, type_code?, feedback?}
 *
 * 'grade' scores an enrolment the instructor teaches (admins any) against a
 * rubric; the weighted percent is recorded as a gradebook assessment and the
 * recomputed result is returned.
 */
declare(strict_types=1);

$u       = mam_require_role('instructor', 'admin');
$me      = (int) $u['id'];
$isAdmin = mam_user_has_role($u, 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    mam_ok([
        'rubrics' => mam_lms2_rubrics_with_criteria(),
        'types'   => mam_db_all('SELECT code, name, weight FROM assessment_types WHERE active = 1 ORDER BY sort_order'),
    ]);
}

$in     = mam_json_input();
$action = (string) ($in['action'] ?? '');

if ($action === 'create') {
    $title    = mam_str($in, 'title', 190);
    $criteria = is_array($in['criteria'] ?? null) ? $in['criteria'] : [];
    $cohortId = isset($in['cohort_id']) && (int) $in['cohort_id'] > 0 ? (int) $in['cohort_id'] : null;
    if ($title === '' || !$criteria) mam_fail('validation', 422, ['fields' => ['title', 'criteria']]);
    try {
        $rid = mam_lms2_rubric_create($title, $criteria, $cohortId, $me);
    } catch (\InvalidArgumentException $e) {
        mam_fail($e->getMessage(), 422);
    }
    mam_audit('rubric_create', 'rubric', $rid, ['by' => $me]);
    $all = mam_lms2_rubrics_with_criteria();
    mam_ok(['rubric_id' => $rid, 'rubrics' => $all], 201);
}

if ($action === 'grade') {
    $enrollmentId = (int) ($in['enrollment_id'] ?? 0);
    $rubricId     = (int) ($in['rubric_id'] ?? 0);
    $scores       = is_array($in['scores'] ?? null) ? $in['scores'] : [];
    $typeCode     = mam_str($in, 'type_code', 24) ?: 'practical';
    $feedback     = array_key_exists('feedback', $in) ? (mam_str($in, 'feedback', 500) ?: null) : null;
    if ($enrollmentId < 1 || $rubricId < 1 || !$scores) mam_fail('validation', 422, ['fields' => ['enrollment_id', 'rubric_id', 'scores']]);

    // Ownership: the enrolment must be one this instructor teaches (admins any).
    $ce = mam_db_row('SELECT instructor_id FROM course_enrollments WHERE id = ?', [$enrollmentId]);
    if (!$ce) mam_fail('enrollment_not_found', 404);
    if (!$isAdmin && (int) ($ce['instructor_id'] ?? 0) !== $me) mam_fail('forbidden', 403);

    // Normalise scores to int keys (JSON object keys arrive as strings).
    $normScores = [];
    foreach ($scores as $k => $v) $normScores[(int) $k] = (float) $v;

    try {
        $res = mam_lms2_rubric_grade($enrollmentId, $rubricId, $normScores, $me, $typeCode, $feedback);
    } catch (\InvalidArgumentException $e) {
        mam_fail($e->getMessage(), 422);
    }
    mam_audit('rubric_grade', 'course_enrollment', $enrollmentId, ['rubric' => $rubricId, 'percent' => $res['computed']['percent']]);
    mam_ok(['computed' => $res['computed'], 'result' => $res['result']]);
}

mam_fail('unknown_action', 400, ['action' => $action]);
