<?php
/**
 * GET /api/student/curriculum[?program=CODE]
 * Returns the full curriculum for the student's enrolled programme.
 * Falls back to an explicit ?program= override (useful for browsing).
 */
declare(strict_types=1);

$u = mam_require_role('student');

// Explicit programme override from query string
$code = trim((string)($_GET['program'] ?? ''));

// Auto-detect from the student's most recent active course enrollment
if ($code === '') {
    $row = mam_db_row(
        "SELECT p.code FROM course_enrollments ce
           JOIN programs p ON p.id = ce.program_id
          WHERE ce.student_id = ? AND ce.status IN ('active','pending') AND ce.program_id IS NOT NULL
          ORDER BY ce.created_at DESC LIMIT 1",
        [(int)$u['id']]
    );
    if ($row) $code = (string)$row['code'];
}

if ($code === '') {
    mam_ok(['curriculum' => null]);
    exit;
}

$curriculum = mam_lms_full_program_curriculum($code);
mam_ok(['curriculum' => $curriculum]);
