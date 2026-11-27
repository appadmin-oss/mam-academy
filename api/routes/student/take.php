<?php
/**
 * POST /api/student/take  { assignment_id, audio: "data:audio/webm;base64,…", note? }
 *   → { url, submission_id, bytes }
 *
 * A student records themselves over a backing track and submits the take
 * (Advanced Modernization §1.4). The recording is stored first-party (see the
 * AUDIO TAKES block in api/modules/integrations/lib/uploads.php) and attached to the existing
 * submissions model as artifact_kind='audio' — so instructor review, feedback,
 * rubric scoring and the guardian feed all keep working with no changes.
 *
 * Student-only and enrolment-checked through the assignment: takes are the
 * student's own performance, and letting one student attach audio to another's
 * assignment would put a recording of a minor in a stranger's review queue.
 */
declare(strict_types=1);

$u   = mam_require_role('student');
$uid = (int) $u['id'];
$in  = mam_json_input();

$assignmentId = (int) ($in['assignment_id'] ?? 0);
$raw          = (string) ($in['audio'] ?? '');

if ($assignmentId < 1) mam_fail('validation', 422, ['fields' => ['assignment_id']]);
if ($raw === '')       mam_fail('missing_audio', 422, ['detail' => 'No recording supplied.']);

// The assignment must be this student's own.
$asg = mam_db_row('SELECT id, student_id, title FROM assignments WHERE id = ?', [$assignmentId]);
if (!$asg) mam_fail('not_found', 404);
if ((int) $asg['student_id'] !== $uid) mam_fail('forbidden', 403);

if (!preg_match('#^data:(audio|video)/[a-z0-9.+-]+;base64,#i', $raw)) {
    mam_fail('bad_audio', 422, ['detail' => 'Expected a base64 audio data URL.']);
}
$b64 = substr($raw, strpos($raw, ',') + 1);
// Check the ENCODED length before decoding: base64 inflates by ~4/3, so this
// refuses an oversized payload without first materialising it in memory.
if (strlen($b64) > (int) (MAM_TAKE_MAX_BYTES * 1.4)) {
    mam_fail('too_large', 413, ['detail' => 'That recording is too long — please keep takes under 15 minutes.']);
}
$bytes = base64_decode($b64, true);
if ($bytes === false) mam_fail('bad_audio', 422, ['detail' => 'The recording could not be read.']);

$res = mam_take_store($bytes);
if (!$res['ok']) {
    $human = [
        'empty'            => 'The recording was empty.',
        'too_large'        => 'That recording is too long — please keep takes under 15 minutes.',
        'not_audio'        => 'That file is not a recording we can accept.',
        'store_unwritable' => 'The server could not save your take. Please tell the academy.',
    ][$res['error']] ?? 'The take could not be saved.';
    if (function_exists('mam_log')) mam_log('warn', 'take_store_failed', ['error' => $res['error'], 'student' => $uid]);
    mam_fail($res['error'], 422, ['detail' => $human]);
}

$submissionId = mam_lms_submit($assignmentId, $uid, $res['url'], [
    'artifact_kind' => 'audio',
    'note'          => isset($in['note']) ? mb_substr(trim((string) $in['note']), 0, 2000) : null,
]);

if (function_exists('mam_audit')) {
    mam_audit('take_submitted', 'submission', (int) $submissionId, ['assignment' => $assignmentId, 'bytes' => $res['bytes']]);
}

mam_ok(['url' => $res['url'], 'submission_id' => (int) $submissionId, 'bytes' => $res['bytes']]);
