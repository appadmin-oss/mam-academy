<?php
/**
 * GET /api/instructor/submissions?assignment_id=N
 * Submissions (+ feedback) for an assignment you authored. Admins: any.
 * Instructor/admin only.
 */
declare(strict_types=1);

$u       = mam_require_role('instructor', 'admin');
$isAdmin = function_exists('mam_user_has_role') ? mam_user_has_role($u, 'admin') : ($u['role'] === 'admin');
$aid     = (int)($_GET['assignment_id'] ?? 0);
if ($aid <= 0) mam_fail('validation', 422, ['fields' => ['assignment_id']]);

$a = mam_db_row('SELECT id, author_id, title FROM assignments WHERE id = ?', [$aid]);
if (!$a) mam_fail('assignment_not_found', 404);
if (!$isAdmin && (int)$a['author_id'] !== (int)$u['id']) mam_fail('forbidden', 403);

mam_ok(['assignment' => $a, 'submissions' => mam_lms_assignment_submissions($aid)]);
