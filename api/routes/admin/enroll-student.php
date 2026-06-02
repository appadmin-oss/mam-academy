<?php
declare(strict_types=1);

$in = mam_json_input();
$studentId = (int)($in['student_id'] ?? 0);
$classId = (int)($in['class_id'] ?? 0);
$applicationId = isset($in['application_id']) ? (int)$in['application_id'] : null;
if (!$studentId || !$classId) mam_fail('validation', 422, ['fields' => ['student_id', 'class_id']]);

$student = mam_db_row("SELECT id, name, email FROM users WHERE id = ? AND role = 'student'", [$studentId]);
$class = mam_db_row('SELECT id, title, schedule FROM classes WHERE id = ?', [$classId]);
if (!$student || !$class) mam_fail('not_found', 404);

try {
    $id = mam_db_insert('enrollments', [
        'student_id' => $studentId, 'class_id' => $classId,
        'application_id' => $applicationId, 'status' => 'active',
    ]);
} catch (PDOException $e) {
    $msg = $e->getMessage();
    // SQLite: "UNIQUE constraint failed", MySQL: "Duplicate entry" / SQLSTATE 23000
    if (str_contains($msg, 'UNIQUE') || str_contains($msg, 'Duplicate') || ($e->getCode() === '23000')) {
        mam_fail('already_enrolled', 409);
    }
    throw $e;
}

if (!empty($student['email'])) {
    $loginUrl = rtrim((string)mam_env('APP_URL', ''), '/') . '/portal/login';
    try {
        mam_send($student['email'], 'You\'ve been enrolled — MAM Academy',
            mam_email_enrolled((string)($student['name'] ?? ''), (string)($class['title'] ?? ''), (string)($class['schedule'] ?? ''), $loginUrl),
            'student_enrolled', true);
    } catch (Throwable $e) { mam_log('warn', 'mail_build_failed', ['context' => 'student_enrolled', 'err' => $e->getMessage()]); }
}

mam_audit('enroll', 'student', $studentId, ['class_id' => $classId]);
mam_ok(['enrollment_id' => $id]);
