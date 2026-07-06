<?php
/**
 * Curriculum authoring for faculty (admin-approved).
 *   GET  /api/instructor/curriculum?cohort_id=N  → { outline, proposals }
 *        the course outline for a cohort the instructor teaches + this
 *        instructor's own proposals.
 *   POST /api/instructor/curriculum { cohort_id, week, title, kind?, content? }
 *        submit a proposal for that cohort's (course, level). Admin approves it
 *        into the live curriculum; nothing is written to `modules` here.
 */
declare(strict_types=1);
$u = mam_require_role('instructor', 'admin');
$isAdmin = mam_user_has_role($u, 'admin');

$ownsCohort = function (int $cohortId) use ($u, $isAdmin): array {
    $co = mam_lms_cohort($cohortId);
    if (!$co) mam_fail('cohort_not_found', 404);
    if (!$isAdmin && !mam_lms_instructor_owns_cohort((int)$u['id'], $cohortId)) mam_fail('forbidden', 403);
    return $co;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cid = (int)($_GET['cohort_id'] ?? 0);
    if ($cid <= 0) mam_fail('validation', 422, ['fields' => ['cohort_id']]);
    $co = $ownsCohort($cid);
    // mam_lms_course_outline takes CODES; resolve them from the cohort's FKs.
    $courseCode  = (string)(mam_db_row('SELECT code FROM courses WHERE id = ?', [(int)$co['course_id']])['code'] ?? '');
    $levelCode   = $co['level_id'] !== null ? (mam_db_row('SELECT code FROM levels WHERE id = ?', [(int)$co['level_id']])['code'] ?? null) : null;
    $programCode = $co['program_id'] !== null ? (mam_db_row('SELECT code FROM programs WHERE id = ?', [(int)$co['program_id']])['code'] ?? null) : null;
    $outline = $courseCode !== '' ? mam_lms_course_outline($courseCode, $levelCode, $programCode) : null;
    mam_ok([
        'cohort'    => ['id' => (int)$co['id'], 'course_name' => $co['course_name'], 'level_name' => $co['level_name']],
        'outline'   => $outline,
        'proposals' => mam_lms_instructor_proposals((int)$u['id']),
    ]);
}

// POST — submit a proposal.
$in    = mam_json_input();
$cid   = (int)($in['cohort_id'] ?? 0);
$week  = (int)($in['week'] ?? 0);
$title = mam_str($in, 'title', 200);
$kind  = mam_str($in, 'kind', 16) ?: 'module';
$body  = mam_str($in, 'content', 4000) ?: null;

if ($cid <= 0 || $week <= 0 || $title === '') {
    mam_fail('validation', 422, ['fields' => ['cohort_id', 'week', 'title']]);
}
$co = $ownsCohort($cid);
$pid = mam_lms_propose_module((int)$co['course_id'], $co['level_id'] !== null ? (int)$co['level_id'] : null, $week, $kind, $title, $body, (int)$u['id']);
mam_ok(['proposal_id' => $pid, 'status' => 'pending']);
