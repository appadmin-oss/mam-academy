<?php
declare(strict_types=1);

$u = mam_require_role('instructor', 'admin');

$in = mam_json_input();
$enrollmentId = (int)($in['enrollment_id'] ?? 0);
$date = mam_str($in, 'session_date', 20) ?: date('Y-m-d');
$status = mam_str($in, 'status', 16);
$notes = mam_str($in, 'notes', 500) ?: null;

if (!in_array($status, ['present', 'absent', 'late', 'excused'], true)) {
    mam_fail('validation', 422, ['fields' => ['status']]);
}

// Verify the enrollment belongs to one of this instructor's classes
$check = mam_db_row(
    'SELECT e.id FROM enrollments e JOIN classes c ON c.id = e.class_id WHERE e.id = ? AND (c.instructor_id = ? OR ? = "admin")',
    [$enrollmentId, (int)$u['id'], $u['role']]
);
if (!$check) mam_fail('forbidden', 403);

$id = mam_db_insert('attendance', [
    'enrollment_id' => $enrollmentId,
    'session_date'  => $date,
    'status'        => $status,
    'notes'         => $notes,
    'marked_by'     => (int)$u['id'],
]);

mam_audit('mark', 'attendance', $id, ['enrollment_id' => $enrollmentId, 'status' => $status]);
mam_ok(['attendance_id' => $id]);
