<?php
/**
 * Instructor module status + carry-forward (LMS 2.0, PDF §6-7, 29-30).
 *
 *   GET  /api/instructor/module?lesson_id=&student_id=          → {state, history}
 *   POST /api/instructor/module {action:'status', lesson_id, student_id, status, mastery_pct?, week?}
 *   POST /api/instructor/module {action:'carry_forward', lesson_id, student_id, to_week?, to_date?, reason?}
 */
declare(strict_types=1);

$u       = mam_require_role('instructor', 'admin');
$me      = (int) $u['id'];
$isAdmin = mam_user_has_role($u, 'admin');

/** The lesson must belong to a cohort this user teaches (admins any). */
$ownsLesson = function (int $lessonId) use ($me, $isAdmin): bool {
    $row = mam_db_row('SELECT co.instructor_id FROM lessons l JOIN cohorts co ON co.id = l.cohort_id WHERE l.id = ?', [$lessonId]);
    if (!$row) return false;
    return $isAdmin || (int) ($row['instructor_id'] ?? 0) === $me;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lid = (int) ($_GET['lesson_id'] ?? 0);
    $sid = (int) ($_GET['student_id'] ?? 0);
    if ($lid < 1 || $sid < 1) mam_fail('validation', 422, ['fields' => ['lesson_id', 'student_id']]);
    if (!$ownsLesson($lid)) mam_fail('forbidden', 403);
    mam_ok(mam_module_history($lid, $sid));
}

$in     = mam_json_input();
$action = (string) ($in['action'] ?? '');
$lid    = (int) ($in['lesson_id'] ?? 0);
$sid    = (int) ($in['student_id'] ?? 0);
if ($lid < 1 || $sid < 1) mam_fail('validation', 422, ['fields' => ['lesson_id', 'student_id']]);
if (!$ownsLesson($lid)) mam_fail('forbidden', 403);

try {
    if ($action === 'status') {
        $status  = mam_str($in, 'status', 24);
        $mastery = array_key_exists('mastery_pct', $in) && $in['mastery_pct'] !== '' ? (float) $in['mastery_pct'] : null;
        $week    = array_key_exists('week', $in) && $in['week'] !== '' ? (int) $in['week'] : null;
        mam_ok(['state' => mam_module_status_set($lid, $sid, $status, $mastery, $week)]);
    }
    if ($action === 'carry_forward') {
        $toWeek = array_key_exists('to_week', $in) && $in['to_week'] !== '' ? (int) $in['to_week'] : null;
        $toDate = array_key_exists('to_date', $in) && $in['to_date'] !== '' ? (string) $in['to_date'] : null;
        $reason = array_key_exists('reason', $in) ? (mam_str($in, 'reason', 255) ?: null) : null;
        mam_ok(mam_module_carry_forward($lid, $sid, $toWeek, $toDate, $reason, $me));
    }
    mam_fail('unknown_action', 400, ['action' => $action]);
} catch (\InvalidArgumentException $e) {
    mam_fail($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    mam_fail($e->getMessage(), 409);
}
