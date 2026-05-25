<?php
declare(strict_types=1);

$in = mam_json_input();
$studentId = (int)($in['student_id'] ?? 0);
$classId = (int)($in['class_id'] ?? 0);
$applicationId = isset($in['application_id']) ? (int)$in['application_id'] : null;
if (!$studentId || !$classId) mam_fail('validation', 422, ['fields' => ['student_id', 'class_id']]);

$student = mam_db_row('SELECT id FROM users WHERE id = ? AND role = "student"', [$studentId]);
$class = mam_db_row('SELECT id FROM classes WHERE id = ?', [$classId]);
if (!$student || !$class) mam_fail('not_found', 404);

try {
    $id = mam_db_insert('enrollments', [
        'student_id' => $studentId, 'class_id' => $classId,
        'application_id' => $applicationId, 'status' => 'active',
    ]);
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'UNIQUE')) mam_fail('already_enrolled', 409);
    throw $e;
}

mam_audit('enroll', 'student', $studentId, ['class_id' => $classId]);
mam_ok(['enrollment_id' => $id]);
