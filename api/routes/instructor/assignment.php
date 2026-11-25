<?php
/**
 * POST /api/instructor/assignment
 * Body: { student_id? , cohort_id? , title, instructions?, due_on? }
 *
 * Creates an assignment for one student, or fans out to every pupil in a
 * cohort (one row each, so completion is tracked per pupil). Each row carries
 * the cohort it was set to (assignments.cohort_id, v108), which is what puts it
 * on the cohort instructor's marking queue. Instructors may only assign to
 * students and cohorts they teach; admins to anyone.
 *
 * Cohort model only: a legacy class_id is no longer accepted (the class model
 * is retired; mam_legacy_carry() moved every class's homework to its cohort).
 */
declare(strict_types=1);

$u  = mam_require_role('instructor', 'admin');
$in = mam_json_input();

$title        = mam_str($in, 'title', 160);
$instructions = mam_str($in, 'instructions', 4000) ?: null;
$due          = mam_str($in, 'due_on', 10) ?: null;
// §1.3 — an optional reference recording the student can play against their take.
$refUrl       = mam_str($in, 'reference_url', 500) ?: null;
$refKind      = mam_str($in, 'reference_kind', 12) ?: null;
if ($refUrl !== null && !preg_match('#^https?://#i', $refUrl)) { $refUrl = null; $refKind = null; }
$studentId    = (int)($in['student_id'] ?? 0);
$cohortId     = (int)($in['cohort_id'] ?? 0);

if ($title === '') mam_fail('validation', 422, ['fields' => ['title']]);
if (!$studentId && !$cohortId) mam_fail('validation', 422, ['fields' => ['student_id', 'cohort_id']]);
if ($due !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due = null; // ignore malformed dates

$isAdmin = ($u['role'] === 'admin');
$targets = []; // student_id => cohort_id|null  (keyed → de-duplicated)

if ($cohortId) {
    // Fan out to a cohort's roster (course_enrollments). Ownership: the caller
    // teaches/owns the cohort (admins bypass).
    if (!$isAdmin && !mam_lms_instructor_owns_cohort((int)$u['id'], $cohortId)) mam_fail('forbidden', 403);
    if ($isAdmin && !mam_db_row('SELECT 1 FROM cohorts WHERE id = ?', [$cohortId])) mam_fail('cohort_not_found', 404);
    foreach (mam_lms_cohort_roster($cohortId) as $r) { $targets[(int)$r['student_id']] = $cohortId; }
    if (!$targets) mam_fail('cohort_empty', 422);
} else {
    $own = null;
    if (!$isAdmin) {
        // The instructor must actually teach this student: the enrolment's own
        // instructor, or the instructor of the cohort it sits in. That cohort
        // is stamped on the row so the work reaches the right marking queue.
        $own = mam_db_row(
            "SELECT ce.cohort_id FROM course_enrollments ce
               LEFT JOIN cohorts co ON co.id = ce.cohort_id
              WHERE ce.student_id = ? AND (ce.instructor_id = ? OR co.instructor_id = ?)
                AND ce.status IN ('active','completed')
              ORDER BY ce.status = 'active' DESC, ce.id DESC LIMIT 1",
            [$studentId, (int)$u['id'], (int)$u['id']]
        );
        if (!$own) mam_fail('forbidden', 403);
    } else {
        if (!mam_db_row("SELECT 1 FROM users WHERE id = ? AND role = 'student'", [$studentId])) mam_fail('student_not_found', 404);
    }
    $targets[$studentId] = ($own && $own['cohort_id'] !== null) ? (int)$own['cohort_id'] : null;
}

$count = 0;
foreach ($targets as $sid => $cid) {
    mam_db_insert('assignments', [
        'student_id'   => (int)$sid,
        'author_id'    => (int)$u['id'],
        'cohort_id'    => $cid,
        'title'        => $title,
        'instructions' => $instructions,
        'due_on'       => $due,
        'status'       => 'open',
        'reference_url'  => $refUrl,
        'reference_kind' => $refKind,
    ]);
    $count++;
}

mam_audit('assign', $cohortId ? 'cohort' : 'student', $cohortId ?: $studentId, ['count' => $count, 'title' => $title, 'via' => $cohortId ? 'cohort' : 'student']);
mam_ok(['created' => $count]);
