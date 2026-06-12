<?php
/**
 * POST /api/instructor/assess
 * Body: { enrollment_id:int, type:"<code>", score:number, max_score?:number,
 *         week?:int, notes?:string }
 * Records one weighted assessment score for a course enrolment the instructor
 * teaches (admins may grade any), then returns the recomputed result.
 */
declare(strict_types=1);
$u = mam_require_role('instructor', 'admin');

$in    = mam_json_input();
$eid   = (int)($in['enrollment_id'] ?? 0);
$type  = mam_str($in, 'type', 32);
$score = isset($in['score']) ? (float)$in['score'] : -1.0;
$max   = isset($in['max_score']) && (float)$in['max_score'] > 0 ? (float)$in['max_score'] : 100.0;
$week  = isset($in['week']) ? max(0, (int)$in['week']) : null;
$notes = mam_str($in, 'notes', 300) ?: null;

if ($eid <= 0 || $type === '' || $score < 0) {
    mam_fail('validation', 422, ['fields' => ['enrollment_id', 'type', 'score']]);
}

$ce = mam_db_row('SELECT id, instructor_id FROM course_enrollments WHERE id = ?', [$eid]);
if (!$ce) mam_fail('enrollment_not_found', 404);
if (!mam_user_has_role($u, 'admin') && (int)$ce['instructor_id'] !== (int)$u['id']) {
    mam_fail('forbidden', 403);
}

try {
    $result = mam_lms_record_assessment($eid, $type, (int)$u['id'], $score, $max, $week, $notes);
} catch (InvalidArgumentException $e) {
    mam_fail('type_not_found', 404);
}
mam_ok(['result' => $result]);
