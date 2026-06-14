<?php
/**
 * POST /api/instructor/feedback-draft
 * AI grading assist — draft feedback for a submission that the instructor then
 * EDITS and signs via /api/instructor/feedback (nothing is auto-saved). Body:
 * {submission_id}. Same ownership as /feedback (authored assignment; admins
 * any). Returns {enabled, draft}. Instructor/admin + CSRF + rate-limited.
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

if (!mam_ai_enabled('grading_feedback')) mam_ok(['enabled' => false, 'draft' => null]);

$d = mam_ai_draft_feedback($sid, (int)$u['id']);
mam_ok(['enabled' => true, 'draft' => $d ? $d['text'] : null]);
