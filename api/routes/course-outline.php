<?php
/**
 * GET /api/course/outline?course=<code>&level=<code>&program=<code>
 * Returns a course's week-by-week outline (programme plan + authored modules):
 * wk 1–10 modules · wk 11 revision · wk 12 exam (or the crash 6-week shape).
 * Public/curriculum data — rate-limited, no PII.
 */
declare(strict_types=1);

$course  = mam_str($_GET, 'course', 48);
$level   = mam_str($_GET, 'level', 32) ?: null;
$program = mam_str($_GET, 'program', 32) ?: null;
if ($course === '') mam_fail('validation', 422, ['fields' => ['course']]);

$outline = mam_lms_course_outline($course, $level, $program);
if (!$outline) mam_fail('course_not_found', 404);
mam_ok($outline);
