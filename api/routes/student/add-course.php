<?php
/**
 * POST /api/student/add-course
 * Body: { course: "<code>", level?: "<code>", program?: "<code>" }
 * Lets a registered student add a new course at any time (Piano now, Voice
 * later) without re-applying. Status is pending|active per the admin policy —
 * or 'waitlisted' (with a queue position) when the course's cohort is full.
 */
declare(strict_types=1);
$u = mam_require_role('student');

$in      = mam_json_input();
$course  = mam_str($in, 'course', 48);
$level   = mam_str($in, 'level', 32) ?: null;
$program = mam_str($in, 'program', 32) ?: null;
if ($course === '') mam_fail('validation', 422, ['fields' => ['course']]);

try {
    $res = mam_lms_add_course((int)$u['id'], $course, $level, $program);
} catch (InvalidArgumentException $e) {
    mam_fail('course_not_found', 404);
} catch (RuntimeException $e) {
    // Map precisely: blanketing every RuntimeException as 'already_enrolled'
    // told the student a falsehood whenever anything else went wrong.
    $msg = $e->getMessage();
    if ($msg === 'already_enrolled') {
        mam_fail('already_enrolled', 409, ['detail' => 'You already have a live enrolment in this course.']);
    }
    mam_log('warn', 'add_course_failed', ['student' => (int)$u['id'], 'course' => $course, 'err' => $msg]);
    mam_fail('enrol_failed', 400, ['detail' => $msg]);
}
mam_ok($res);
