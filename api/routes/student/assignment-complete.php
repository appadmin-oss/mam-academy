<?php
/**
 * POST /api/student/assignment-complete  { assignment_id, done? }
 * Marks one of the student's own assignments done (default) or re-opens it.
 */
declare(strict_types=1);

$u  = mam_require_role('student');
$in = mam_json_input();

$id   = (int)($in['assignment_id'] ?? 0);
$done = array_key_exists('done', $in) ? (bool)$in['done'] : true;
if (!$id) mam_fail('validation', 422, ['fields' => ['assignment_id']]);

$a = mam_db_row('SELECT id, student_id FROM assignments WHERE id = ?', [$id]);
if (!$a || (int)$a['student_id'] !== (int)$u['id']) mam_fail('not_found', 404);

mam_db_update('assignments', [
    'status'       => $done ? 'done' : 'open',
    'completed_at' => $done ? date('Y-m-d H:i:s') : null,
], ['id' => $id]);

mam_audit('assignment_complete', 'assignment', $id, ['done' => $done]);
mam_ok(['id' => $id, 'status' => $done ? 'done' : 'open']);
