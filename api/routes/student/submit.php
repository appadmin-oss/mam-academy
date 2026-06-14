<?php
/**
 * POST /api/student/submit
 * Submit an artifact (link/audio/video/pdf/image URL) for one of YOUR
 * assignments. Body: {assignment_id, artifact_url, artifact_kind?, note?}.
 * Resubmitting creates a new submission. Student only + CSRF.
 */
declare(strict_types=1);

$u   = mam_require_role('student');
$in  = mam_json_input();
$aid = (int)($in['assignment_id'] ?? 0);
$url = trim((string)($in['artifact_url'] ?? ''));
if ($aid <= 0) mam_fail('validation', 422, ['fields' => ['assignment_id']]);
if ($url === '') mam_fail('validation', 422, ['fields' => ['artifact_url']]);

try {
    $id = mam_lms_submit($aid, (int)$u['id'], $url, [
        'artifact_kind' => (string)($in['artifact_kind'] ?? 'link'),
        'note'          => isset($in['note']) ? (string)$in['note'] : null,
    ]);
    mam_ok(['submitted' => true, 'submission_id' => $id]);
} catch (RuntimeException $e) {
    $m = $e->getMessage();
    mam_fail($m, $m === 'assignment_not_found' ? 404 : 403);
}
