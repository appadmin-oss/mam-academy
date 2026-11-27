<?php
/**
 * The teacher's side of lessons (api/modules/learning/lib/lessons.php, v97).
 *
 *   GET  /api/instructor/lessons                       → {queue} — written answers waiting for this teacher
 *   GET  /api/instructor/lessons?student=N             → {student, courses} — that student's lessons, on the
 *                                                         courses THIS teacher teaches them (all, for an admin)
 *   POST /api/instructor/lessons {action:'mark', progress_id, awards:{qid: points}, comments?:{qid: text}, feedback?}
 *   POST /api/instructor/lessons {action:'tick', lesson_id, student_id, done: true|false}
 *   GET  ?exams=1                → {exams} — exam sittings with written answers or an appeal waiting
 *   GET  ?placement=COURSE_ID    → {students, levels} — where each student works on that course, and their tier
 *   POST {action:'exam_mark', exam_id, awards, comments?, appeal_outcome?}
 *   POST {action:'place', student_id, course_id, level_id, reason?}   lesson level — never the class or the price
 *   POST {action:'material', lesson_id, title, url, kind?, guide?}    sent to the curriculum office for approval
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
    if (!empty($_GET['exams'])) mam_ok(['exams' => mam_exam_teacher_queue($admin ? null : $uid)]);
    if (($pc = (int) ($_GET['placement'] ?? 0)) > 0) {
        mam_ok(['students' => mam_lesson_placement_list($admin ? null : $uid, $pc),
            'levels' => mam_db_all('SELECT id, name FROM levels ORDER BY sort_order, id')]);
    }
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
    mam_ok(['student' => ['id' => (int) $stu['id'], 'name' => (string) $stu['name']], 'courses' => $courses,
        'levels' => mam_db_all('SELECT id, name FROM levels ORDER BY sort_order, id')]);
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

if ($action === 'exam_mark') {
    $eid = (int) ($in['exam_id'] ?? 0);
    $s = $eid > 0 ? mam_db_row('SELECT student_id, course_id FROM lesson_sittings WHERE id = ?', [$eid]) : null;
    if (!$s) mam_fail('not_found', 404);
    if (!$admin && !mam_lesson_instructor_teaches($uid, (int) $s['student_id'], (int) $s['course_id'])) mam_fail('not_your_student', 403);
    $outcome = array_key_exists('appeal_outcome', $in) && trim((string) $in['appeal_outcome']) !== '' ? (string) $in['appeal_outcome'] : null;
    try {
        mam_exam_teacher_mark($uid, $eid, is_array($in['awards'] ?? null) ? $in['awards'] : [],
            is_array($in['comments'] ?? null) ? $in['comments'] : [], $outcome);
        mam_ok(['marked' => true]);
    } catch (InvalidArgumentException $e) { mam_fail('validation', 422, ['message' => $e->getMessage()]); }
    catch (RuntimeException $e) { mam_fail($e->getMessage(), 404); }
}

if ($action === 'place') {
    $sid = (int) ($in['student_id'] ?? 0); $cid = (int) ($in['course_id'] ?? 0); $lv = (int) ($in['level_id'] ?? 0);
    if ($sid < 1 || $cid < 1 || $lv < 1) mam_fail('validation', 422, ['fields' => ['student_id', 'course_id', 'level_id']]);
    if (!$admin && !mam_lesson_instructor_teaches($uid, $sid, $cid)) mam_fail('not_your_student', 403);
    try { mam_ok(mam_lesson_place($uid, $sid, $cid, $lv, (string) ($in['reason'] ?? ''))); }
    catch (InvalidArgumentException $e) { mam_fail('validation', 422, ['message' => 'Choose a level.']); }
    catch (RuntimeException $e) { mam_fail($e->getMessage(), 409, ['message' => 'That student is not enrolled on this course.']); }
}

if ($action === 'material') {
    $lid = (int) ($in['lesson_id'] ?? 0);
    $m = $lid > 0 ? mam_db_row('SELECT course_id FROM modules WHERE id = ?', [$lid]) : null;
    if (!$m) mam_fail('lesson_not_found', 404);
    // A teacher sends material for a course they teach; the office decides whether it goes live.
    if (!$admin && !mam_db_row("SELECT 1 FROM course_enrollments ce LEFT JOIN cohorts co ON co.id = ce.cohort_id
            WHERE ce.course_id = ? AND ce.status IN ('active','completed') AND (ce.instructor_id = ? OR co.instructor_id = ?) LIMIT 1",
            [(int) $m['course_id'], $uid, $uid])) {
        mam_fail('not_your_course', 403);
    }
    try { mam_ok(['material_id' => mam_lesson_material_submit($uid, $lid, $in), 'status' => 'pending']); }
    catch (InvalidArgumentException $e) { mam_fail('validation', 422, ['message' => $e->getMessage()]); }
}

mam_fail('unknown_action', 400);
