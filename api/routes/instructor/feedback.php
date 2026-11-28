<?php
/**
 * POST /api/instructor/feedback
 * Leave or edit feedback on a submission (1:1, editable). Body:
 * {submission_id, outcome:'accepted'|'revise', comments?, rubric?}. You must
 * have authored the submission's assignment (admins: any). Instructor/admin + CSRF.
 */
declare(strict_types=1);

$u       = mam_require_role('instructor', 'admin');
$isAdmin = function_exists('mam_user_has_role') ? mam_user_has_role($u, 'admin') : ($u['role'] === 'admin');
$in      = mam_json_input();
$sid     = (int)($in['submission_id'] ?? 0);
if ($sid <= 0) mam_fail('validation', 422, ['fields' => ['submission_id']]);

$sub = mam_lms_submission($sid);
if (!$sub) mam_fail('submission_not_found', 404);
if (!$isAdmin && (int)($sub['assignment_author_id'] ?? 0) !== (int)$u['id']) mam_fail('forbidden', 403);

// The marking sheet sends its rubric as {criterion: mark|null}. A save with a
// criterion left unmarked is refused here, BEFORE anything is written, so the
// submission stays on the marking queue (UX quick win 7).
$rubricIn = $in['rubric'] ?? null;
if (is_array($rubricIn) && $rubricIn !== array_values($rubricIn) || $rubricIn === []) {
    $sheet = function_exists('mam_marking_sheet') ? mam_marking_sheet($sid) : [];
    $why = mam_marking_rubric_refusal((array) $rubricIn, $sheet['criteria'] ?? []);
    if ($why !== null) {
        mam_fail($why, 422, ['message' => $why === 'no_marks'
            ? 'Give every criterion a mark, or choose “Not assessed” for it, before saving.'
            : 'A mark is outside the range for its criterion.']);
    }
}

try {
    $r = mam_lms_give_feedback($sid, (int)$u['id'], [
        'outcome'  => (string)($in['outcome'] ?? 'accepted'),
        'comments' => isset($in['comments']) ? (string)$in['comments'] : null,
        'rubric'   => $in['rubric'] ?? null,
    ]);
    mam_ok(['ok' => true, 'status' => $r['status']]);
} catch (RuntimeException $e) {
    mam_fail($e->getMessage() === 'invalid_outcome' ? 'invalid_outcome' : 'feedback_failed', 422, ['detail' => $e->getMessage()]);
}
