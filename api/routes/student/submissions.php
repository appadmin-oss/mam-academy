<?php
/**
 * GET /api/student/submissions?assignment_id=N
 * The signed-in student's submissions (+ feedback), optionally scoped to one
 * assignment. Read-only, student only.
 */
declare(strict_types=1);

$u   = mam_require_role('student');
$aid = (int)($_GET['assignment_id'] ?? 0);

mam_ok(['submissions' => mam_lms_student_submissions((int)$u['id'], $aid > 0 ? $aid : null)]);
