<?php
/**
 * POST /api/student/add-course
 * Body: { course: "<code>", level?: "<code>", program?: "<code>" }
 * Lets a registered student add a new course at any time (Piano now, Voice
 * later) without re-applying. Status is pending|active per the admin policy.
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
    mam_fail('already_enrolled', 409);
}
mam_ok($res);
