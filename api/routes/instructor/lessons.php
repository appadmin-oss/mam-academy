<?php
/**
 * The teacher's side of lessons (api/lib/lessons.php, v97).
 *
 *   GET  /api/instructor/lessons                       → {queue} — written answers waiting for this teacher
 *   GET  /api/instructor/lessons?student=N             → {student, courses} — that student's lessons, on the
 *                                                         courses THIS teacher teaches them (all, for an admin)
 *   POST /api/instructor/lessons {action:'mark', progress_id, awards:{qid: points}, comments?:{qid: text}, feedback?}
 *   POST /api/instructor/lessons {action:'tick', lesson_id, student_id, done: true|false}
 *
 * An instructor acts only on students they teach on that lesson's course — the
 * enrolment decides it (mam_lesson_instructor_teaches), not the request. An
 * admin reaches every student.
 */
declare(strict_types=1);

$u     = mam_require_user();
$uid   = (int) $u['id'];
$admin = mam_user_has_role($u, 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sid = (int) ($_GET['student'] ?? 0);
    if ($sid < 1) mam_ok(['queue' => mam_lesson_marking_queue($admin ? null : $uid)]);
    $stu = mam_db_row('SELECT id, name FROM users WHERE id = ?', [$sid]);
    if (!$stu) mam_fail('not_found', 404);
    $courses = [];
    foreach (mam_lesson_student_board($sid) as $c) {
        $cid = (int) (mam_db_row('SELECT id FROM courses WHERE code = ?', [$c['course_code']])['id'] ?? 0);
        // A teacher sees only the courses they teach this child; another teacher's course is not theirs to tick.
        if ($admin || mam_lesson_instructor_teaches($uid, $sid, $cid)) $courses[] = $c;
    }
    if (!$courses && !$admin && !mam_db_row(
        "SELECT 1 FROM course_enrollments ce LEFT JOIN cohorts co ON co.id = ce.cohort_id
          WHERE ce.student_id = ? AND (ce.instructor_id = ? OR co.instructor_id = ?) LIMIT 1", [$sid, $uid, $uid])) {
        mam_fail('not_your_student', 403);
    }
    mam_ok(['student' => ['id' => (int) $stu['id'], 'name' => (string) $stu['name']], 'courses' => $courses]);
}

$in = mam_json_input();
$action = (string) ($in['action'] ?? '');

$mayAct = static function (int $studentId, int $lessonId) use ($admin, $uid): void {
    $m = mam_db_row('SELECT course_id FROM modules WHERE id = ?', [$lessonId]);
    if (!$m) mam_fail('lesson_not_found', 404);
    if (!$admin && !mam_lesson_instructor_teaches($uid, $studentId, (int) $m['course_id'])) mam_fail('not_your_student', 403);
};

if ($action === 'mark') {
    $pid = (int) ($in['progress_id'] ?? 0);
    $p = $pid > 0 ? mam_db_row('SELECT module_id, student_id FROM module_progress WHERE id = ?', [$pid]) : null;
    if (!$p) mam_fail('not_found', 404);
    $mayAct((int) $p['student_id'], (int) $p['module_id']);
    try {
        mam_ok(mam_lesson_mark($uid, $pid, is_array($in['awards'] ?? null) ? $in['awards'] : [],
            is_array($in['comments'] ?? null) ? $in['comments'] : [], (string) ($in['feedback'] ?? '')));
    } catch (InvalidArgumentException $e) {
        mam_fail('validation', 422, ['message' => $e->getMessage()]);
    } catch (RuntimeException $e) {
        mam_fail($e->getMessage(), $e->getMessage() === 'not_found' ? 404 : 409);
    }
}

if ($action === 'tick') {
    $lid = (int) ($in['lesson_id'] ?? 0); $sid = (int) ($in['student_id'] ?? 0);
    if ($lid < 1 || $sid < 1) mam_fail('validation', 422, ['fields' => ['lesson_id', 'student_id']]);
    $mayAct($sid, $lid);
    try { mam_ok(['result' => mam_lesson_confirm($uid, $lid, $sid, !array_key_exists('done', $in) || !empty($in['done']))]); }
    catch (RuntimeException $e) {
        mam_fail($e->getMessage(), $e->getMessage() === 'not_found' ? 404 : 409,
            $e->getMessage() === 'done_online' ? ['message' => 'The student did this lesson online, so it stays theirs.'] : []);
    }
}

mam_fail('unknown_action', 400);
