<?php
/**
 * /api/instructor/sessions  (cohort scheduling — LMS L2)
 *   GET                         → {cohorts:[…]}  (the instructor's cohorts)
 *   GET ?cohort_id=N            → {cohort, sessions:[…]}  (must be owned)
 *   POST {action:'create', cohort_id, title?, scheduled_at?, duration_min?, kind?, location?}
 *   POST {action:'status', session_id, status:'held'|'cancelled'}
 * Instructor/admin only. Admins bypass cohort ownership; instructors must teach
 * (or be assigned to) the cohort. Illegal moves → 409.
 */
declare(strict_types=1);

$u       = mam_require_role('instructor', 'admin');
$me      = (int)$u['id'];
$isAdmin = function_exists('mam_user_has_role') ? mam_user_has_role($u, 'admin') : ($u['role'] === 'admin');

$ownsCohort = function (int $cohortId) use ($me, $isAdmin): bool {
    return $isAdmin ? (mam_db_row('SELECT 1 FROM cohorts WHERE id = ?', [$cohortId]) !== null)
                    : mam_lms_instructor_owns_cohort($me, $cohortId);
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if ($cohortId <= 0) {
        mam_ok(['cohorts' => $isAdmin ? mam_lms_cohorts() : mam_lms_instructor_cohorts($me)]);
    }
    if (!$ownsCohort($cohortId)) mam_fail('forbidden', 403);
    mam_ok(['cohort' => mam_lms_cohort($cohortId), 'sessions' => mam_lms_cohort_sessions($cohortId)]);
}

$in     = mam_json_input();
$action = strtolower(trim((string)($in['action'] ?? 'create')));

try {
    if ($action === 'status') {
        $sid = (int)($in['session_id'] ?? 0);
        $sess = $sid > 0 ? mam_lms_session($sid) : null;
        if (!$sess) mam_fail('session_not_found', 404);
        if (!$ownsCohort((int)$sess['cohort_id'])) mam_fail('forbidden', 403);
        $t = mam_lms_set_session_status($sid, strtolower(trim((string)($in['status'] ?? ''))), $me);
        mam_ok(['ok' => true, 'status' => $t['to']]);
    }

    // default: create
    $cohortId = (int)($in['cohort_id'] ?? 0);
    if ($cohortId <= 0) mam_fail('validation', 422, ['fields' => ['cohort_id']]);
    if (!$ownsCohort($cohortId)) mam_fail('forbidden', 403);
    $id = mam_lms_create_session($cohortId, [
        'title'        => isset($in['title']) ? (string)$in['title'] : null,
        'scheduled_at' => isset($in['scheduled_at']) ? (string)$in['scheduled_at'] : null,
        'duration_min' => isset($in['duration_min']) ? (int)$in['duration_min'] : 60,
        'kind'         => (string)($in['kind'] ?? 'class'),
        'location'     => isset($in['location']) ? (string)$in['location'] : null,
        'created_by'   => $me,
    ]);
    mam_ok(['created' => true, 'session_id' => $id]);
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
    if ($msg === 'cohort_not_found' || $msg === 'session_not_found') mam_fail($msg, 404);
    mam_fail('illegal_transition', 409, ['detail' => $msg]);
}
