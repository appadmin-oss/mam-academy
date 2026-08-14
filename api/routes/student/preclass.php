<?php
/**
 * Student pre-class learning + teach-back (LMS 2.0, PDF §8-9).
 *
 *   GET  /api/student/preclass                         → {next: {session, module, prep, teachback}}
 *   POST /api/student/preclass {action:'prepare',   lesson_id, done?}
 *   POST /api/student/preclass {action:'teachback', lesson_id, kind, artifact_url?, body?}
 *
 * Evidence is a URL — a link (Drive/YouTube) or a first-party/Cloudinary upload.
 */
declare(strict_types=1);

$u   = mam_require_user();
$uid = (int) $u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    mam_ok(['next' => mam_preclass_next($uid)]);
}

$in     = mam_json_input();
$action = (string) ($in['action'] ?? '');
$lid    = (int) ($in['lesson_id'] ?? 0);
if ($lid < 1) mam_fail('validation', 422, ['fields' => ['lesson_id']]);

// The student must be enrolled in the module's cohort.
$lesson = mam_db_row('SELECT id, cohort_id FROM lessons WHERE id = ?', [$lid]);
if (!$lesson) mam_fail('lesson_not_found', 404);
$cohortId = $lesson['cohort_id'] !== null ? (int) $lesson['cohort_id'] : 0;
if ($cohortId > 0) {
    $ok = mam_db_row("SELECT 1 FROM course_enrollments WHERE cohort_id = ? AND student_id = ? AND status IN ('active','completed') LIMIT 1", [$cohortId, $uid]);
    if (!$ok) mam_fail('not_enrolled', 403);
}

try {
    if ($action === 'prepare') {
        $done = !array_key_exists('done', $in) || !empty($in['done']);
        mam_ok(['prep' => mam_preclass_mark_prepared($lid, $uid, $done)]);
    }
    if ($action === 'teachback') {
        $kind = mam_str($in, 'kind', 16) ?: 'written';
        $url  = array_key_exists('artifact_url', $in) ? (string) $in['artifact_url'] : null;
        $body = array_key_exists('body', $in) ? (string) $in['body'] : null;
        $tb   = mam_preclass_teachback_submit($lid, $uid, $cohortId ?: null, $kind, $url, $body);
        mam_ok(['teachback' => $tb], 201);
    }
    mam_fail('unknown_action', 400, ['action' => $action]);
} catch (\RuntimeException $e) {
    $code = $e->getMessage();
    $status = in_array($code, ['evidence_required', 'bad_url', 'validation'], true) ? 422
            : ($code === 'lesson_not_found' ? 404 : 409);
    mam_fail($code, $status);
}
