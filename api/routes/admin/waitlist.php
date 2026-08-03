<?php
/**
 * /api/admin/waitlist  — Cohort waitlist management (admin only)
 *
 *   GET  ?cohort_id=N                       → {cohort, fill_count, waitlist:[…]}
 *   POST {action:'promote', cohort_id}       → {promoted: student_id|null}
 *   POST {action:'remove',  cohort_id, student_id} → {removed: true}
 *   POST {action:'add',     cohort_id, student_id} → {position: N}
 */
declare(strict_types=1);
$u = mam_require_role('admin');
$me = (int)$u['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if ($cohortId < 1) mam_fail('missing_field', 400, ['field' => 'cohort_id']);
    $cohort = mam_lms_cohort($cohortId);
    if (!$cohort) mam_fail('not_found', 404);
    mam_ok([
        'cohort'     => $cohort,
        'fill_count' => mam_lms_cohort_fill_count($cohortId),
        'waitlist'   => mam_lms_waitlist_for_cohort($cohortId),
    ]);
}

$in     = mam_json_input();
$action = strtolower(trim((string)($in['action'] ?? '')));

try {
    if ($action === 'promote') {
        $cohortId  = (int)($in['cohort_id'] ?? 0);
        if ($cohortId < 1) mam_fail('missing_field', 400, ['field' => 'cohort_id']);
        $force = (bool)($in['force'] ?? false);
        $promoted = mam_lms_waitlist_promote($cohortId, $me, $force);
        mam_ok(['promoted' => $promoted]);
    }

    if ($action === 'remove') {
        $cohortId  = (int)($in['cohort_id']  ?? 0);
        $studentId = (int)($in['student_id'] ?? 0);
        if ($cohortId < 1 || $studentId < 1) mam_fail('missing_field', 400, ['field' => 'cohort_id/student_id']);
        mam_lms_waitlist_remove($cohortId, $studentId);
        mam_ok(['removed' => true]);
    }

    if ($action === 'add') {
        $cohortId  = (int)($in['cohort_id']  ?? 0);
        $studentId = (int)($in['student_id'] ?? 0);
        if ($cohortId < 1 || $studentId < 1) mam_fail('missing_field', 400, ['field' => 'cohort_id/student_id']);
        if (!mam_db_row('SELECT id FROM users WHERE id = ? AND role = ?', [$studentId, 'student'])) mam_fail('not_found', 404);
        $pos = mam_lms_waitlist_add($cohortId, $studentId);
        mam_ok(['position' => $pos]);
    }

    mam_fail('unknown_action', 400, ['action' => $action]);
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
    if ($msg === 'cohort_not_found') mam_fail('cohort_not_found', 404);
    if ($msg === 'already_enrolled') mam_fail('already_enrolled', 409);
    if ($msg === 'cohort_full') mam_fail('cohort_full', 409, ['hint' => 'Cohort is at capacity — pass force to override.']);
    // Promote refuses rather than silently consuming a queue entry; surface why.
    if ($msg === 'no_course_mapping') mam_fail('no_course_mapping', 409, ['hint' => 'Map this cohort to a course before promoting — nothing can be enrolled without one.']);
    if ($msg === 'enrolled_elsewhere') mam_fail('enrolled_elsewhere', 409, ['hint' => 'That student already has a live enrolment for this course in another cohort. Move or withdraw it first; they stay queued.']);
    mam_fail('error', 400, ['detail' => $msg]);
}
