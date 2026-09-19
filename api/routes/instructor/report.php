<?php
/**
 * POST /api/instructor/report → { ok, report_id, status }
 *   body: { student_id, comment, cohort_id? }
 *
 * The instructor's half of the termly report: the one paragraph only a person
 * can write. Everything else on that screen is derived and already held.
 *
 * ⚠️ THIS DOES NOT PUBLISH ANYTHING. `mam_report_build()` writes a DRAFT and
 * `mam_report_set_comment()` attaches the comment; a family sees nothing until
 * the Examinations panel calls `mam_report_ratify()`, which throws on an open
 * appeal, on a moderation sample below the floor, and on a report with no
 * scored criterion. The screen's own words are "Send to the Examinations
 * panel", not "publish", and that is the literal truth of what happens here.
 *
 * ⚠️ A RATIFIED REPORT IS NOT EDITABLE. `mam_report_set_comment()` throws
 * `already_ratified`, and that refusal belongs there rather than here — it is
 * the record a family has already been given.
 */
declare(strict_types=1);

$u  = mam_require_role('instructor', 'admin');
$me = (int) $u['id'];
$in = mam_json_input();

$studentId = (int) ($in['student_id'] ?? 0);
if ($studentId <= 0) mam_fail('validation', 422, ['fields' => ['student_id']]);

$comment = trim((string) ($in['comment'] ?? ''));
if (mb_strlen($comment) > 4000) mam_fail('validation', 422, ['fields' => ['comment']]);

/* Ownership, against the SAME dual-read list the Students and Termly report
   screens are built from (gotcha #2) — a fresh query here could admit a student
   those screens never showed, or refuse one they did. */
if (!mam_iportal_is_admin()) {
    $mine = array_map(fn($s) => (int) $s['id'], mam_iportal_students($me));
    if (!in_array($studentId, $mine, true)) mam_fail('forbidden', 403);
}

$cohortId = (int) ($in['cohort_id'] ?? 0);

try {
    /* Reuse an open draft rather than building a second one: the slot rule
       (`mam_report_slot_taken()`) is PHP's on both engines precisely because no
       UNIQUE index can state it, so a blind build would throw on the second
       save of the same report — which is what a save button does. */
    $existing = mam_db_row(
        "SELECT id, status FROM termly_reports
          WHERE student_id = ? AND status NOT IN ('withdrawn','ratified')
          ORDER BY id DESC LIMIT 1", [$studentId]);

    $reportId = $existing
        ? (int) $existing['id']
        : mam_report_build($studentId, $cohortId > 0 ? $cohortId : null, $me);

    mam_report_set_comment($reportId, $comment !== '' ? $comment : null, $me);
} catch (RuntimeException $e) {
    $m = $e->getMessage();
    if ($m === 'already_ratified') mam_fail('already_ratified', 409);
    if ($m === 'student_not_found' || $m === 'cohort_not_found') mam_fail($m, 404);
    mam_log('warn', 'iportal_report_save_failed', ['student' => $studentId, 'err' => $m]);
    mam_fail('report_failed', 422, ['detail' => $m]);
} catch (InvalidArgumentException $e) {
    mam_fail('validation', 422, ['fields' => ['student_id']]);
}

$row = mam_db_row('SELECT status FROM termly_reports WHERE id = ?', [$reportId]);
mam_ok(['ok' => true, 'report_id' => $reportId, 'status' => (string) ($row['status'] ?? 'draft')]);
