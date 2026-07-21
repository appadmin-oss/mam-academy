<?php
/**
 * /api/student/waitlist  — Student waitlist management
 *
 *   GET                                   → {waitlists:[…]}  (all cohorts this student waits for)
 *   GET ?cohort_id=N                      → {status:{position,status,created_at}|null, fill_count}
 *   POST {action:'join', cohort_id}       → {position:N}
 *   POST {action:'leave', cohort_id}      → {left:true}
 */
declare(strict_types=1);
$u  = mam_require_role('student');
$me = (int)$u['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if ($cohortId > 0) {
        if (!mam_db_row('SELECT id FROM cohorts WHERE id = ?', [$cohortId])) mam_fail('not_found', 404);
        mam_ok([
            'status'     => mam_lms_waitlist_status($cohortId, $me),
            'fill_count' => mam_lms_cohort_fill_count($cohortId),
        ]);
    }
    mam_ok(['waitlists' => mam_lms_student_waitlists($me)]);
}

$in     = mam_json_input();
$action = strtolower(trim((string)($in['action'] ?? '')));

try {
    if ($action === 'join') {
        $cohortId = (int)($in['cohort_id'] ?? 0);
        if ($cohortId < 1) mam_fail('missing_field', 400, ['field' => 'cohort_id']);
        if (!mam_db_row('SELECT id FROM cohorts WHERE id = ?', [$cohortId])) mam_fail('not_found', 404);
        $pos = mam_lms_waitlist_add($cohortId, $me);
        mam_ok(['position' => $pos]);
    }

    if ($action === 'leave') {
        $cohortId = (int)($in['cohort_id'] ?? 0);
        if ($cohortId < 1) mam_fail('missing_field', 400, ['field' => 'cohort_id']);
        mam_lms_waitlist_remove($cohortId, $me);
        mam_ok(['left' => true]);
    }

    mam_fail('unknown_action', 400, ['action' => $action]);
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
    if ($msg === 'cohort_not_found') mam_fail('cohort_not_found', 404);
    if ($msg === 'already_enrolled') mam_fail('already_enrolled', 409, ['detail' => 'You are already enrolled in this class']);
    mam_fail('error', 400, ['detail' => $msg]);
}
