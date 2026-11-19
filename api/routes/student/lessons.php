<?php
/**
 * A student's lessons (api/lib/lessons.php v97; lessons-learn.php and
 * lesson-exams.php v98).
 *
 *   GET  /api/student/lessons            → {courses, certificates, exams} — every course they study, where they
 *                                          stand, their tier, certificates and recent exams
 *   GET  /api/student/lessons?id=N       → {lesson} — opens (starts) it; the student's copy, never the answers
 *   GET  /api/student/lessons?exam=N     → {exam} — one sitting, as its student sees it
 *   POST {action:'submit', lesson_id, answers:{question_id: answer}}  → {progress, review, passed, pct, pending}
 *   POST {action:'note', lesson_id, note}                 private notes — nobody else reads them
 *   POST {action:'save', lesson_id, saved: bool}          saved for later
 *   POST {action:'feedback', lesson_id, feel, body?}      got it / mostly / lost
 *   POST {action:'exam_start', lesson_id, pledged: true}  → {exam}
 *   POST {action:'trial_start', course_id, pledged: true} → {exam}
 *   POST {action:'exam_save', exam_id, answers}           while the clock runs
 *   POST {action:'exam_submit', exam_id, answers}         → {exam}
 *   POST {action:'exam_appeal', exam_id, text}
 *   POST {action:'helper', lesson_id, question}           → {answer, left, held}
 *
 * Access is decided by the library on every call — published, and on a course
 * the student is enrolled in at a level their placement opens — never by what
 * the page showed.
 */
declare(strict_types=1);

$u   = mam_require_user();
$uid = (int) $u['id'];

/** Every refusal the libraries throw, as an API error with a sentence a student can act on. */
$refuse = static function (Throwable $e): void {
    if ($e instanceof InvalidArgumentException) mam_fail('validation', 422, ['message' => $e->getMessage()]);
    $c = $e->getMessage();
    $map = [
        'not_found'            => [404, 'That was not found.'],
        'not_yours'            => [403, 'That lesson is not on one of your courses.'],
        'no_questions'         => [409, 'This lesson has no questions to answer.'],
        'pledge_required'      => [422, 'Tick the box to say the work will be your own.'],
        'not_open'             => [409, 'The course trial opens when you reach the Master tier.'],
        'cooldown'             => [409, 'You can sit the course trial once a week.'],
        'not_enough_questions' => [409, 'This course does not have enough questions for a trial yet.'],
        'closed'               => [409, 'That exam has already been handed in.'],
        'time_up'              => [409, 'Time is up — what you saved has been marked.'],
        'not_marked'           => [409, 'You can appeal once the exam has been marked.'],
        'already_appealed'     => [409, 'You have already appealed this result.'],
        'too_late'             => [409, 'The time to appeal this result has passed.'],
        'helper_off'           => [409, 'The study helper is not switched on.'],
        'exam_open'            => [409, 'The study helper is off while you have an exam open.'],
        'daily_limit'          => [429, 'That is all the helper questions for today. Ask your teacher, or try again tomorrow.'],
        'helper_failed'        => [502, 'The helper could not answer just now. Try again in a minute.'],
    ];
    [$status, $msg] = $map[$c] ?? [400, 'That could not be done.'];
    mam_fail($c === 'not_yours' ? 'not_your_lesson' : ($c === 'not_found' ? 'lesson_not_found' : $c), $status, ['message' => $msg]);
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        if (($ex = (int) ($_GET['exam'] ?? 0)) > 0) mam_ok(['exam' => mam_exam_view($uid, $ex)]);
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) mam_ok(['courses' => mam_lesson_student_board($uid), 'certificates' => mam_lesson_certificates($uid),
            'exams' => mam_exam_history($uid, 10), 'open_exam' => ($o = mam_exam_open_sitting($uid)) ? (int) $o['id'] : null]);
        mam_ok(['lesson' => mam_lesson_open($uid, $id)]);
    } catch (Throwable $e) { $refuse($e); }
}

$in     = mam_json_input();
$action = (string) ($in['action'] ?? '');
$lid    = (int) ($in['lesson_id'] ?? 0);
$exid   = (int) ($in['exam_id'] ?? 0);
$answers = is_array($in['answers'] ?? null) ? $in['answers'] : [];

/** The note/save/feedback actions are on a lesson the student may open. */
$mine = static function () use ($uid, $lid): void {
    $l = $lid > 0 ? mam_lesson_get($lid, false) : null;
    if (!$l) mam_fail('lesson_not_found', 404);
    if (!mam_lesson_student_can_open($uid, $l)) mam_fail('not_your_lesson', 403);
};

try {
    switch ($action) {
        case 'submit':
            if ($lid < 1) mam_fail('validation', 422, ['fields' => ['lesson_id']]);
            try { mam_ok(mam_lesson_submit($uid, $lid, $answers)); }
            catch (InvalidArgumentException $e) { mam_fail('incomplete', 422, ['message' => $e->getMessage()]); }
        case 'note':
            $mine(); mam_lesson_note_save($uid, $lid, (string) ($in['note'] ?? '')); mam_ok(['saved' => true]);
        case 'save':
            $mine(); mam_lesson_save_for_later($uid, $lid, !empty($in['saved'])); mam_ok(['saved' => !empty($in['saved'])]);
        case 'feedback':
            $mine(); mam_lesson_feedback_set($uid, $lid, (string) ($in['feel'] ?? ''), (string) ($in['body'] ?? ''));
            mam_ok(['feedback' => mam_lesson_feedback_mine($uid, $lid)]);
        case 'exam_start':
            mam_ok(['exam' => mam_exam_view($uid, mam_exam_start_session($uid, $lid, ($in['pledged'] ?? null) === true))]);
        case 'trial_start':
            mam_ok(['exam' => mam_exam_view($uid, mam_exam_start_trial($uid, (int) ($in['course_id'] ?? 0), ($in['pledged'] ?? null) === true))]);
        case 'exam_save':
            mam_exam_save($uid, $exid, $answers); mam_ok(['saved' => true]);
        case 'exam_submit':
            mam_ok(['exam' => mam_exam_submit($uid, $exid, $answers)]);
        case 'exam_appeal':
            mam_exam_appeal($uid, $exid, (string) ($in['text'] ?? '')); mam_ok(['exam' => mam_exam_view($uid, $exid)]);
        case 'helper':
            mam_ok(mam_lesson_helper_ask($uid, $lid, (string) ($in['question'] ?? '')));
    }
} catch (InvalidArgumentException | RuntimeException $e) { $refuse($e); }
mam_fail('unknown_action', 400);
