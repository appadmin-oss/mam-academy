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
   where they teach the student — dual-read across BOTH enrolment models
   (gotcha #2), because a course-only student has no legacy class row and a
   legacy-only student has no course enrolment. */
if (!mam_user_has_role($user, 'admin')) {
    $studentId = (int) (mam_db_row('SELECT student_id FROM submissions WHERE id = ?', [$submissionId])['student_id'] ?? 0);
    $teaches = mam_db_row(
        "SELECT 1 AS ok FROM enrollments e
           JOIN classes c ON c.id = e.class_id
          WHERE e.student_id = ? AND c.instructor_id = ?
          LIMIT 1", [$studentId, (int) $user['id']])
        ?: mam_db_row(
        "SELECT 1 AS ok FROM course_enrollments ce
           LEFT JOIN cohorts co ON co.id = ce.cohort_id
          WHERE ce.student_id = ? AND (co.instructor_id = ? OR ce.instructor_id = ?)
          LIMIT 1", [$studentId, (int) $user['id'], (int) $user['id']]);
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
