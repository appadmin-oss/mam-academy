<?php
/**
 * GET /api/student/submissions?assignment_id=N
 * The signed-in student's submissions (+ feedback), optionally scoped to one
 * assignment. Read-only, student only.
 */
declare(strict_types=1);

$u   = mam_require_role('student');
$aid = (int)($_GET['assignment_id'] ?? 0);

$subs = mam_lms_student_submissions((int)$u['id'], $aid > 0 ? $aid : null);
// Attach timestamped feedback markers (§1.1) for audio submissions so the
// student's practice player can pin the instructor's notes onto the waveform.
foreach ($subs as &$s) {
    if (($s['artifact_kind'] ?? '') === 'audio') { $s['markers'] = mam_lms_markers((int)$s['id']); }
}
unset($s);

mam_ok(['submissions' => $subs]);
