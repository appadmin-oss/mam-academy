<?php
/**
 * A student's lessons (api/lib/lessons.php, v97).
 *
 *   GET  /api/student/lessons            → {courses: [...]} — every course they study, lessons and where they stand
 *   GET  /api/student/lessons?id=N       → {lesson} — opens (starts) it; the student's copy, never the answers
 *   POST /api/student/lessons {action:'submit', lesson_id, answers:{question_id: answer}}
 *        → {progress, review, passed, pct, pending}
 *
 * Access is decided by the library on every call — published, and on a course
 * the student is enrolled in at that level — never by what the page showed.
 */
declare(strict_types=1);

$u   = mam_require_user();
$uid = (int) $u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) mam_ok(['courses' => mam_lesson_student_board($uid)]);
    try { mam_ok(['lesson' => mam_lesson_open($uid, $id)]); }
    catch (RuntimeException $e) { mam_fail($e->getMessage() === 'not_found' ? 'lesson_not_found' : 'not_your_lesson', $e->getMessage() === 'not_found' ? 404 : 403); }
}

$in = mam_json_input();
if ((string) ($in['action'] ?? '') !== 'submit') mam_fail('unknown_action', 400);
$lid = (int) ($in['lesson_id'] ?? 0);
if ($lid < 1) mam_fail('validation', 422, ['fields' => ['lesson_id']]);
$answers = is_array($in['answers'] ?? null) ? $in['answers'] : [];
try {
    mam_ok(mam_lesson_submit($uid, $lid, $answers));
} catch (InvalidArgumentException $e) {
    mam_fail('incomplete', 422, ['message' => $e->getMessage()]);
} catch (RuntimeException $e) {
    $code = $e->getMessage();
    mam_fail($code === 'no_questions' ? 'no_questions' : ($code === 'not_found' ? 'lesson_not_found' : 'not_your_lesson'),
        $code === 'not_found' ? 404 : ($code === 'no_questions' ? 409 : 403));
}
