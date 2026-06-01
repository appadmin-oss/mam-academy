<?php
/**
 * POST /api/student/practice-log  { minutes, focus?, notes?, logged_on? }
 * Persists one practice session for the signed-in student (server-side, so it
 * survives across devices). Returns updated streak/week/total stats.
 */
declare(strict_types=1);

$u = mam_require_role('student');
$in = mam_json_input();
$minutes = max(1, min(1440, (int)($in['minutes'] ?? 0)));
$focus   = mam_str($in, 'focus', 120) ?: null;
$notes   = mam_str($in, 'notes', 500) ?: null;
$on      = mam_str($in, 'logged_on', 20);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) $on = date('Y-m-d');

$id = mam_db_insert('practice_logs', [
    'student_id' => (int)$u['id'],
    'minutes' => $minutes,
    'focus' => $focus,
    'notes' => $notes,
    'logged_on' => $on,
]);
mam_ok(['log_id' => $id] + mam_practice_stats((int)$u['id']));
