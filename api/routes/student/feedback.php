<?php
/**
 * GET  /api/student/feedback        → student's own feedback history
 * POST /api/student/feedback        → submit new feedback
 */
declare(strict_types=1);
$u = mam_require_role('student');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    mam_ok(['feedback' => mam_feedback_by_student((int)$u['id'])]);
    exit;
}

$in      = mam_json_input();
$rating  = (int)($in['rating'] ?? 0);
$body    = trim((string)($in['body'] ?? ''));
$subject = trim((string)($in['subject'] ?? '')) ?: null;
$cat     = trim((string)($in['category'] ?? 'general'));
$anon    = !empty($in['anonymous']);
$enrId   = isset($in['enrollment_id'])        && (int)$in['enrollment_id']        > 0 ? (int)$in['enrollment_id']        : null;
$ceId    = isset($in['course_enrollment_id']) && (int)$in['course_enrollment_id'] > 0 ? (int)$in['course_enrollment_id'] : null;

if ($rating < 1 || $rating > 5)  mam_fail('bad_rating', 422, ['field' => 'rating']);
if ($body === '')                  mam_fail('empty_body',  422, ['field' => 'body']);

try {
    $id = mam_feedback_submit((int)$u['id'], $rating, $cat, $body, $subject, $enrId, $ceId, $anon);
    mam_notify((int)$u['id'], 'feedback_received', 'Feedback received', 'Thank you — your feedback helps us improve.');
    mam_ok(['id' => $id]);
} catch (\InvalidArgumentException $e) {
    mam_fail($e->getMessage(), 422);
}
