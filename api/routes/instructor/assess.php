<?php
/**
 * POST /api/instructor/assess
 * Record a mark:  { enrollment_id, type:"<code>", score, max_score?, week?, notes? }
 * Remark (edit):  { assessment_id, score, max_score?, week?, notes? }
 * Void (delete):  { assessment_id, action:"void" }
 *
 * Records / corrects / voids one weighted assessment for a course enrolment the
 * instructor teaches (admins may grade any), then returns the recomputed result.
 * Editing overwrites the row in place — it does NOT add another averaged mark —
 * so a mistaken score can be corrected, not just diluted. Every change is audited.
 */
declare(strict_types=1);
$u = mam_require_role('instructor', 'admin');

$in      = mam_json_input();
$isAdmin = mam_user_has_role($u, 'admin');

// ── Remark / void an existing assessment ──
$aid = (int)($in['assessment_id'] ?? 0);
if ($aid > 0) {
    $a = mam_lms_assessment($aid);
    if (!$a) mam_fail('assessment_not_found', 404);
    if (!$isAdmin && (int)$a['instructor_id'] !== (int)$u['id']) mam_fail('forbidden', 403);

    if (($in['action'] ?? '') === 'void') {
        $r = mam_lms_delete_assessment($aid, (int)$u['id']);
        mam_ok(['result' => $r['result'], 'voided' => true]);
    }

    $score = isset($in['score']) ? (float)$in['score'] : -1.0;
    if ($score < 0) mam_fail('validation', 422, ['fields' => ['score']]);
    // Preserve any field the remark omits rather than resetting it: a
    // correction that sends only {score} must not silently reset max_score to
    // 100 (which rescales the mark) or null out the week/notes.
    $max   = isset($in['max_score']) && (float)$in['max_score'] > 0 ? (float)$in['max_score'] : (float)($a['max_score'] ?? 100.0);
    if ($max <= 0) $max = 100.0;
    if ($score > $max) mam_fail('validation', 422, ['fields' => ['score'], 'detail' => 'Score cannot exceed the maximum.']);
    $week  = isset($in['week']) ? max(0, (int)$in['week']) : (($a['week'] ?? null) !== null ? (int)$a['week'] : null);
    $notes = array_key_exists('notes', $in) ? (mam_str($in, 'notes', 300) ?: null) : (($a['notes'] ?? null) !== null ? (string)$a['notes'] : null);
    $r = mam_lms_update_assessment($aid, (int)$u['id'], $score, $max, $week, $notes);
    mam_ok(['result' => $r['result'], 'edited' => true]);
}

// ── Record a new mark ──
$eid   = (int)($in['enrollment_id'] ?? 0);
$type  = mam_str($in, 'type', 32);
$score = isset($in['score']) ? (float)$in['score'] : -1.0;
$max   = isset($in['max_score']) && (float)$in['max_score'] > 0 ? (float)$in['max_score'] : 100.0;
$week  = isset($in['week']) ? max(0, (int)$in['week']) : null;
$notes = mam_str($in, 'notes', 300) ?: null;

if ($eid <= 0 || $type === '' || $score < 0) {
    mam_fail('validation', 422, ['fields' => ['enrollment_id', 'type', 'score']]);
}
// Reject an over-max score instead of silently clamping it — a 95 typed against
// a /20 component is a transcription error, not a 100%.
if ($score > $max) mam_fail('validation', 422, ['fields' => ['score'], 'detail' => 'Score cannot exceed the maximum.']);

$ce = mam_db_row('SELECT id, instructor_id FROM course_enrollments WHERE id = ?', [$eid]);
if (!$ce) mam_fail('enrollment_not_found', 404);
if (!$isAdmin && (int)$ce['instructor_id'] !== (int)$u['id']) {
    mam_fail('forbidden', 403);
}

try {
    $result = mam_lms_record_assessment($eid, $type, (int)$u['id'], $score, $max, $week, $notes);
} catch (InvalidArgumentException $e) {
    mam_fail('type_not_found', 404);
}
mam_ok(['result' => $result]);
