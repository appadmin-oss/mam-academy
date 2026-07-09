<?php
/**
 * POST /api/instructor/assignment
 * Body: { student_id? , class_id? , cohort_id? , title, instructions?, due_on? }
 *
 * Creates an assignment for one student, or fans out to every active pupil in a
 * legacy class OR a cohort (one row each, so completion is tracked per pupil).
 * Assignments are per-student (assignments.student_id), so a cohort/course-only
 * student can be assigned work even without a legacy class (P2 dual-read).
 * Instructors may only assign to their own students/classes/cohorts; admins to
 * anyone.
 */
declare(strict_types=1);

$u  = mam_require_role('instructor', 'admin');
$in = mam_json_input();

$title        = mam_str($in, 'title', 160);
$instructions = mam_str($in, 'instructions', 4000) ?: null;
$due          = mam_str($in, 'due_on', 10) ?: null;
$studentId    = (int)($in['student_id'] ?? 0);
$classId      = (int)($in['class_id'] ?? 0);
$cohortId     = (int)($in['cohort_id'] ?? 0);

if ($title === '') mam_fail('validation', 422, ['fields' => ['title']]);
if (!$studentId && !$classId && !$cohortId) mam_fail('validation', 422, ['fields' => ['student_id', 'class_id', 'cohort_id']]);
if ($due !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due = null; // ignore malformed dates

$isAdmin = ($u['role'] === 'admin');
$targets = []; // student_id => class_id|null  (keyed → de-duplicated)

if ($classId) {
    $cls = mam_db_row('SELECT id, instructor_id FROM classes WHERE id = ?', [$classId]);
    if (!$cls) mam_fail('class_not_found', 404);
    if (!$isAdmin && (int)$cls['instructor_id'] !== (int)$u['id']) mam_fail('forbidden', 403);
    foreach (mam_db_all("SELECT student_id FROM enrollments WHERE class_id = ? AND status = 'active'", [$classId]) as $r) {
        $targets[(int)$r['student_id']] = $classId;
    }
    if (!$targets) mam_fail('class_empty', 422);
} elseif ($cohortId) {
    // Fan out to a cohort's roster (course_enrollments). Ownership: the caller
    // teaches/owns the cohort (admins bypass). class_id stays null — the
    // assignment is per-student regardless of model.
    if (!$isAdmin && !mam_lms_instructor_owns_cohort((int)$u['id'], $cohortId)) mam_fail('forbidden', 403);
    if ($isAdmin && !mam_db_row('SELECT 1 FROM cohorts WHERE id = ?', [$cohortId])) mam_fail('cohort_not_found', 404);
    foreach (mam_lms_cohort_roster($cohortId) as $r) { $targets[(int)$r['student_id']] = null; }
    if (!$targets) mam_fail('cohort_empty', 422);
} else {
    if (!$isAdmin) {
        // The instructor must actually teach this student — in EITHER model.
        $own = mam_db_row(
            "SELECT 1 FROM enrollments e JOIN classes c ON c.id = e.class_id
              WHERE e.student_id = ? AND c.instructor_id = ? AND e.status = 'active' LIMIT 1",
            [$studentId, (int)$u['id']]
        );
        if (!$own) {
            $own = mam_db_row(
                "SELECT 1 FROM course_enrollments ce
                  WHERE ce.student_id = ? AND ce.instructor_id = ? AND ce.status IN ('active','completed') LIMIT 1",
                [$studentId, (int)$u['id']]
            );
        }
        if (!$own) mam_fail('forbidden', 403);
    } else {
        if (!mam_db_row("SELECT 1 FROM users WHERE id = ? AND role = 'student'", [$studentId])) mam_fail('student_not_found', 404);
    }
    $targets[$studentId] = $classId ?: null;
}

$count = 0;
foreach ($targets as $sid => $cid) {
    mam_db_insert('assignments', [
        'student_id'   => (int)$sid,
        'author_id'    => (int)$u['id'],
        'class_id'     => $cid,
        'title'        => $title,
        'instructions' => $instructions,
        'due_on'       => $due,
        'status'       => 'open',
    ]);
    $count++;
}

mam_audit('assign', 'student', $classId ?: ($cohortId ?: $studentId), ['count' => $count, 'title' => $title, 'via' => $classId ? 'class' : ($cohortId ? 'cohort' : 'student')]);
mam_ok(['created' => $count]);
