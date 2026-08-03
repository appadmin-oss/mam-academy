<?php
declare(strict_types=1);

$in = mam_json_input();
$studentId = (int)($in['student_id'] ?? 0);
$classId = (int)($in['class_id'] ?? 0);
$applicationId = isset($in['application_id']) ? (int)$in['application_id'] : null;
if (!$studentId || !$classId) mam_fail('validation', 422, ['fields' => ['student_id', 'class_id']]);

$admin   = mam_current_user();
$adminId = (int)($admin['id'] ?? 0);

$student = mam_db_row("SELECT id, name, email FROM users WHERE id = ? AND role = 'student'", [$studentId]);
$class   = mam_db_row('SELECT id, title, schedule, instructor_id, discipline FROM classes WHERE id = ?', [$classId]);
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

// Dual-write: also ensure a course_enrollments record exists so the student
// appears in the instructor's gradebook and can be assessed. Uses the class's
// discipline as the course code and class's instructor_id as the assignee.
// Skipped silently if the discipline doesn't map to an active course.
if (!empty($class['discipline'])) {
    $ceId = 0;
    $ceWaitlist = null;
    try {
        $ceRow = mam_lms_add_course($studentId, (string)$class['discipline']);
        // A full course cohort diverts to its waitlist ('id' is null then). The
        // LEGACY class enrolment above still stands — only the course/gradebook
        // side is queued — so report it rather than letting it pass unnoticed.
        if (($ceRow['status'] ?? '') === 'waitlisted') {
            $ceWaitlist = ['cohort_id' => (int)$ceRow['cohort_id'], 'position' => (int)$ceRow['position']];
        }
        $ceId = (int)($ceRow['id'] ?? 0);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'already_enrolled') {
            // Payment settle already created one; find it (deterministically —
            // the old unordered lookup could return another level's enrolment).
            $existing = mam_lms_find_enrollment($studentId, (string)$class['discipline']);
            $ceId = $existing ? (int)$existing['id'] : 0;
        }
        // course_not_found → class discipline doesn't map to a course yet; skip
    } catch (\Throwable $e) {
        mam_log('warn', 'enroll_ce_skip', ['student' => $studentId, 'discipline' => $class['discipline'], 'err' => $e->getMessage()]);
    }

    if ($ceId > 0 && !empty($class['instructor_id'])) {
        $instrId = (int)$class['instructor_id'];
        try {
            // approve() sets instructor_id + transitions pending → active.
            mam_lms_approve($ceId, $instrId, $adminId);
        } catch (\Throwable $e) {
            // Already active (e.g. auto-approved) — just set the instructor.
            try { mam_db_update('course_enrollments', ['instructor_id' => $instrId], ['id' => $ceId]); }
            catch (\Throwable $e2) {}
        }
    }
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
mam_ok(['enrollment_id' => $id, 'course_waitlist' => $ceWaitlist ?? null]);
