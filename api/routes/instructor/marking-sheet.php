<?php
/**
 * GET /api/instructor/marking-sheet?submission=N
 *   → { submission_id, student_name, artifact_url, artifact_kind,
 *       criteria:[{ key, label, max, kind, show_video, bands[] }],
 *       second_marker, audio_only_notice, publication_notice }
 *
 * ⚠️ `show_video` IS DECIDED HERE, not in the browser. The whole point of the
 * audio-only rule is that it does not depend on the marker's willingness to
 * keep it — a client-side flag is one devtools toggle away, and the instructor
 * most likely to flip it is the one the control exists for. The server also
 * withholds the artifact URL from a technical-only sheet, so the video is not
 * merely hidden: it is not sent.
 *
 * Access: the caller must teach the student whose submission this is. An
 * instructor may not open an arbitrary submission id by guessing.
 */
declare(strict_types=1);

$user = mam_require_user();
$submissionId = (int) ($_GET['submission'] ?? 0);
if ($submissionId <= 0) mam_fail('validation', 422, ['fields' => ['submission']]);

$sheet = mam_marking_sheet($submissionId);
if (!$sheet) mam_fail('not_found', 404);

/* Ownership. An admin sees everything; an instructor sees a submission only
   where they teach the student (the enrolment's instructor or its cohort's),
   or where the work is on their own marking queue — the same predicate
   mam_iportal_marking() lists by, so a row on the queue always opens. Cohort
   model only: the legacy class roster is retired. */
if (!mam_user_has_role($user, 'admin')) {
    $uid = (int) $user['id'];
    $studentId = (int) (mam_db_row('SELECT student_id FROM submissions WHERE id = ?', [$submissionId])['student_id'] ?? 0);
    $teaches = mam_teaches_student($uid, $studentId)   // teaching.php
        ?: mam_db_row(
        "SELECT 1 AS ok FROM submissions s JOIN assignments a ON a.id = s.assignment_id
          WHERE s.id = ? AND " . mam_iportal_assignment_mine_sql('a', $uid),
        array_merge([$submissionId], mam_iportal_assignment_mine_params($uid)));
    if (!$teaches) mam_fail('forbidden', 403);
}

/* The artifact travels only with the criteria that may use it. A sheet whose
   criteria are all technical gets no media URL at all — "hidden" in the DOM is
   not withheld, and the recording of a child is access-gated anyway. */
$anyVideo = false;
foreach ($sheet['criteria'] as $c) if (!empty($c['show_video'])) $anyVideo = true;
if (!$anyVideo) {
    $sheet['artifact_url'] = '';
    $sheet['artifact_withheld'] = 'audio_only';
}

mam_ok($sheet);
