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
