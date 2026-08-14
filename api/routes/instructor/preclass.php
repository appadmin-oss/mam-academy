<?php
/**
 * Instructor pre-class command centre + teach-back review (LMS 2.0, §17-19).
 *
 *   GET  /api/instructor/preclass                      → {queue:[…]} teach-backs to review
 *   GET  /api/instructor/preclass?session_id=N         → {session, roster:[…prep/teachback…]}
 *   POST /api/instructor/preclass {action:'review',      teachback_id, decision, understanding?, feedback?}
 *   POST /api/instructor/preclass {action:'lesson_save', lesson_id?, cohort_id, title, week_no?, objectives?, prep_instructions?, resources?, teachback_required?}
 *   POST /api/instructor/preclass {action:'link_session', session_id, lesson_id}
 */
declare(strict_types=1);

$u       = mam_require_role('instructor', 'admin');
$me      = (int) $u['id'];
$isAdmin = mam_user_has_role($u, 'admin');

/** True if this user may act on the given cohort. */
$ownsCohort = function (int $cohortId) use ($me, $isAdmin): bool {
    if ($isAdmin) return true;
    $c = mam_db_row('SELECT instructor_id FROM cohorts WHERE id = ?', [$cohortId]);
    return $c && (int) ($c['instructor_id'] ?? 0) === $me;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sid = (int) ($_GET['session_id'] ?? 0);
    if ($sid > 0) {
        try { $view = mam_preclass_session_view($sid); }
        catch (\RuntimeException $e) { mam_fail($e->getMessage(), 404); }
        if (!$ownsCohort((int) $view['session']['cohort_id'])) mam_fail('forbidden', 403);
        mam_ok($view);
    }
    // Review queue. Admins see all submitted; instructors see their cohorts'.
    if ($isAdmin) {
        $queue = mam_db_all(
            "SELECT tb.*, u.name AS student_name, l.title AS module_title, co.title AS cohort_title
               FROM teachbacks tb JOIN lessons l ON l.id = tb.lesson_id JOIN cohorts co ON co.id = l.cohort_id
               LEFT JOIN users u ON u.id = tb.student_id
              WHERE tb.status = 'submitted' ORDER BY tb.created_at ASC"
        );
    } else {
        $queue = mam_preclass_instructor_queue($me);
    }
    mam_ok(['queue' => $queue]);
}

$in     = mam_json_input();
$action = (string) ($in['action'] ?? '');

try {
    if ($action === 'review') {
        $tid = (int) ($in['teachback_id'] ?? 0);
        if ($tid < 1) mam_fail('validation', 422, ['fields' => ['teachback_id']]);
        // Ownership via lesson → cohort.
        $own = mam_db_row('SELECT co.instructor_id FROM teachbacks tb JOIN lessons l ON l.id = tb.lesson_id JOIN cohorts co ON co.id = l.cohort_id WHERE tb.id = ?', [$tid]);
        if (!$own) mam_fail('not_found', 404);
        if (!$isAdmin && (int) ($own['instructor_id'] ?? 0) !== $me) mam_fail('forbidden', 403);
        $decision      = mam_str($in, 'decision', 16);
        $understanding = array_key_exists('understanding', $in) ? (int) $in['understanding'] : null;
        $feedback      = array_key_exists('feedback', $in) ? (mam_str($in, 'feedback', 500) ?: null) : null;
        // §19 — the six competency dimensions (each optional, 0–5).
        $dimensions = [];
        foreach (MAM_TEACHBACK_DIMENSIONS as $dim) {
            if (array_key_exists($dim, $in) && $in[$dim] !== '' && $in[$dim] !== null) $dimensions[$dim] = (int) $in[$dim];
        }
        mam_ok(['teachback' => mam_preclass_teachback_review($tid, $me, $decision, $understanding, $feedback, $dimensions)]);
    }

    if ($action === 'lesson_save') {
        $cohortId = (int) ($in['cohort_id'] ?? 0);
        $lessonId = (int) ($in['lesson_id'] ?? 0);
        if ($lessonId > 0) {
            $l = mam_db_row('SELECT cohort_id FROM lessons WHERE id = ?', [$lessonId]);
            if (!$l) mam_fail('lesson_not_found', 404);
            $cohortId = $cohortId ?: (int) $l['cohort_id'];
        }
        if ($cohortId < 1 || !$ownsCohort($cohortId)) mam_fail('forbidden', 403);
        $data = [
            'cohort_id'          => $cohortId,
            'created_by'         => $me,
            'title'              => $in['title'] ?? null,
            'week_no'            => $in['week_no'] ?? null,
            'objectives'         => $in['objectives'] ?? null,
            'prep_instructions'  => $in['prep_instructions'] ?? null,
            'resources'          => $in['resources'] ?? null,
            'teachback_required' => $in['teachback_required'] ?? null,
        ];
        $id = mam_preclass_lesson_save($data, $lessonId ?: null);
        mam_ok(['lesson_id' => $id], $lessonId ? 200 : 201);
    }

    if ($action === 'link_session') {
        $sid = (int) ($in['session_id'] ?? 0);
        $lid = (int) ($in['lesson_id'] ?? 0);
        if ($sid < 1 || $lid < 1) mam_fail('validation', 422, ['fields' => ['session_id', 'lesson_id']]);
        $sess = mam_db_row('SELECT cohort_id FROM class_sessions WHERE id = ?', [$sid]);
        if (!$sess) mam_fail('session_not_found', 404);
        if (!$ownsCohort((int) $sess['cohort_id'])) mam_fail('forbidden', 403);
        mam_db_update('class_sessions', ['lesson_id' => $lid], ['id' => $sid]);
        mam_ok(['linked' => true]);
    }

    mam_fail('unknown_action', 400, ['action' => $action]);
} catch (\InvalidArgumentException $e) {
    mam_fail($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    mam_fail($e->getMessage(), 409);
}
