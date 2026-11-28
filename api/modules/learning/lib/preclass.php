<?php
/**
 * MAM Academy — pre-class learning + teach-back (LMS 2.0, PDF §8-9, 18-19).
 * ─────────────────────────────────────────────────────────────
 * Encodes the DISCOVER → DECLARE steps of the MAM learning cycle:
 *   • DISCOVER — a week module (a `lessons` row) carries objectives, prep
 *     instructions and resources; a class_session points at the module it
 *     delivers. The student's preparation status reuses lesson_progress
 *     (in_progress/completed = the 🟡/🟢 traffic light).
 *   • DECLARE — the student submits a teach-back (video/audio/written/practical)
 *     as evidence they understood the topic BEFORE instruction; the instructor
 *     reviews it and marks readiness (ready / clarify / more_prep, §18).
 *
 * Evidence is stored as a URL (artifact_url) so a link (Drive/YouTube) and a
 * first-party/Cloudinary upload share one path. All additive; nothing here
 * changes an existing flow.
 */
declare(strict_types=1);

const MAM_TEACHBACK_KINDS     = ['video', 'audio', 'written', 'practical'];
const MAM_TEACHBACK_DECISIONS = ['ready', 'clarify', 'more_prep'];

/** Basic evidence-URL acceptance: an http(s) link or a first-party path. */
function mam_preclass_valid_url(string $url): bool {
    $url = trim($url);
    if ($url === '' || strlen($url) > 1000) return false;
    if ($url[0] === '/') return true;                       // first-party (/api/media/…)
    return (bool) preg_match('~^https?://~i', $url);
}

/** Create or update a week module's pre-class content on a lesson. Returns the
 *  lesson id. Instructor/admin ownership is enforced by the caller. */
function mam_preclass_lesson_save(array $data, ?int $lessonId = null): int {
    $fields = [
        'title'              => isset($data['title']) ? trim((string) $data['title']) : null,
        'week_no'            => isset($data['week_no']) ? (int) $data['week_no'] : null,
        'objectives'         => isset($data['objectives']) ? (string) $data['objectives'] : null,
        'prep_instructions'  => isset($data['prep_instructions']) ? (string) $data['prep_instructions'] : null,
        'resources_json'     => isset($data['resources']) ? json_encode(array_values((array) $data['resources'])) : null,
        'teachback_required' => !empty($data['teachback_required']) ? 1 : 0,
    ];
    if ($lessonId) {
        $set = array_filter($fields, fn($v) => $v !== null);
        if ($set) mam_db_update('lessons', $set, ['id' => $lessonId]);
        return $lessonId;
    }
    if (($fields['title'] ?? '') === '') throw new InvalidArgumentException('title_required');
    return mam_db_insert('lessons', array_merge([
        'cohort_id' => isset($data['cohort_id']) ? (int) $data['cohort_id'] : null,
        'created_by'=> isset($data['created_by']) ? (int) $data['created_by'] : null,
        'published' => 1,
    ], array_filter($fields, fn($v) => $v !== null)));
}

/** Decode a lesson's resources_json into a clean list of {kind,url,title}. */
function mam_preclass_resources(?string $json): array {
    if (!$json) return [];
    $r = json_decode($json, true);
    return is_array($r) ? $r : [];
}

/**
 * The student's "YOUR NEXT CLASS" card (§7-8): the next scheduled session across
 * their cohorts, the module it delivers, their preparation status, and their
 * teach-back status. Null when there's no upcoming session.
 */
function mam_preclass_next(int $studentId): ?array {
    $cohorts = function_exists('mam_checkin_student_cohorts') ? mam_checkin_student_cohorts($studentId) : [];
    if (!$cohorts) {
        $rows = mam_db_all("SELECT cohort_id FROM course_enrollments WHERE student_id = ? AND cohort_id IS NOT NULL AND status IN ('active','completed')", [$studentId]);
        $cohorts = array_map(fn($r) => (int) $r['cohort_id'], $rows);
    }
    if (!$cohorts) return null;
    $ph = implode(',', array_fill(0, count($cohorts), '?'));
    $sess = mam_db_row(
        "SELECT cs.id, cs.title, cs.scheduled_at, cs.lesson_id, co.title AS cohort_title
           FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id
          WHERE cs.cohort_id IN ($ph) AND cs.status = 'scheduled' AND cs.scheduled_at >= ?
          ORDER BY cs.scheduled_at ASC LIMIT 1",
        array_merge($cohorts, [mam_session_now()])
    );
    if (!$sess) return null;

    $module = null; $prep = null; $teachback = null;
    if ($sess['lesson_id']) {
        $lid = (int) $sess['lesson_id'];
        $l = mam_db_row('SELECT * FROM lessons WHERE id = ?', [$lid]);
        if ($l) {
            $module = [
                'lesson_id'          => $lid,
                'title'              => $l['title'],
                'week_no'            => $l['week_no'] !== null ? (int) $l['week_no'] : null,
                'objectives'         => $l['objectives'],
                'prep_instructions'  => $l['prep_instructions'],
                'resources'          => mam_preclass_resources($l['resources_json'] ?? null),
                'teachback_required' => (int) ($l['teachback_required'] ?? 0) === 1,
            ];
            $lp = mam_db_row('SELECT status, percent FROM lesson_progress WHERE lesson_id = ? AND student_id = ?', [$lid, $studentId]);
            $prep = $lp ? (string) $lp['status'] : 'not_started';
            $teachback = mam_preclass_teachback_for($lid, $studentId);
        }
    }
    return [
        'session'   => ['id' => (int) $sess['id'], 'title' => $sess['title'], 'scheduled_at' => $sess['scheduled_at'], 'cohort_title' => $sess['cohort_title']],
        'module'    => $module,
        'prep'      => $prep,
        'teachback' => $teachback,
    ];
}

/** Mark a student's preparation for a module (DISCOVER complete). Reuses the
 *  lesson_progress upsert; 'completed' = 🟢, 'in_progress' = 🟡. */
function mam_preclass_mark_prepared(int $lessonId, int $studentId, bool $done = true): array {
    return mam_lms2_lesson_progress_set($lessonId, $studentId, $done ? 100 : 50, $done ? 'completed' : 'in_progress');
}

/** The student's current teach-back for a module, or null. */
function mam_preclass_teachback_for(int $lessonId, int $studentId): ?array {
    return mam_db_row('SELECT * FROM teachbacks WHERE lesson_id = ? AND student_id = ?', [$lessonId, $studentId]);
}

/**
 * Submit (or resubmit) a teach-back for a module — the DECLARE step. One row per
 * (lesson, student); resubmitting replaces the evidence and resets the review.
 * Requires either an evidence URL or a written body. Throws stable error codes.
 */
function mam_preclass_teachback_submit(int $lessonId, int $studentId, ?int $cohortId, string $kind, ?string $artifactUrl, ?string $body): array {
    if ($lessonId <= 0 || $studentId <= 0) throw new RuntimeException('validation');
    $kind = in_array($kind, MAM_TEACHBACK_KINDS, true) ? $kind : 'written';
    $artifactUrl = $artifactUrl !== null ? trim($artifactUrl) : '';
    $body = $body !== null ? trim($body) : '';
    if ($artifactUrl === '' && $body === '') throw new RuntimeException('evidence_required');
    if ($artifactUrl !== '' && !mam_preclass_valid_url($artifactUrl)) throw new RuntimeException('bad_url');
    if (!mam_db_row('SELECT id FROM lessons WHERE id = ?', [$lessonId])) throw new RuntimeException('lesson_not_found');

    $existing = mam_db_row('SELECT id FROM teachbacks WHERE lesson_id = ? AND student_id = ?', [$lessonId, $studentId]);
    $now = date('Y-m-d H:i:s');
    $row = [
        'kind'         => $kind,
        'artifact_url' => $artifactUrl !== '' ? $artifactUrl : null,
        'body'         => $body !== '' ? $body : null,
        'status'       => 'submitted',
        'reviewer_id'  => null, 'decision' => null, 'understanding' => null, 'feedback' => null, 'reviewed_at' => null,
    ];
    if ($existing) {
        mam_db_update('teachbacks', $row, ['id' => (int) $existing['id']]);
    } else {
        mam_db_insert('teachbacks', array_merge($row, ['lesson_id' => $lessonId, 'student_id' => $studentId, 'cohort_id' => $cohortId, 'created_at' => $now]));
    }
    mam_audit('teachback_submit', 'lesson', $lessonId, ['student' => $studentId, 'kind' => $kind]);
    return mam_preclass_teachback_for($lessonId, $studentId);
}

/** The six §19 teach-back assessment dimensions, each scored 0–5. */
const MAM_TEACHBACK_DIMENSIONS = ['understanding', 'accuracy', 'vocabulary', 'explanation', 'application', 'confidence'];

/**
 * Instructor review of a teach-back (§18–19): records the readiness decision,
 * the six 0–5 competency dimensions (all optional) and feedback. $dimensions is
 * a map like ['understanding'=>4,'accuracy'=>3,…]; unknown keys are ignored and
 * each value is clamped to 0–5. Returns the updated row.
 */
function mam_preclass_teachback_review(int $teachbackId, int $reviewerId, string $decision, ?int $understanding = null, ?string $feedback = null, array $dimensions = []): array {
    if (!in_array($decision, MAM_TEACHBACK_DECISIONS, true)) throw new InvalidArgumentException('bad_decision');
    $tb = mam_db_row('SELECT id FROM teachbacks WHERE id = ?', [$teachbackId]);
    if (!$tb) throw new RuntimeException('not_found');
    // Back-compat: a bare $understanding arg folds into the dimensions map.
    if ($understanding !== null && !array_key_exists('understanding', $dimensions)) $dimensions['understanding'] = $understanding;
    $clamp = function ($v) { return ($v === null || $v === '') ? null : max(0, min(5, (int) $v)); };
    $set = ['status' => 'reviewed', 'reviewer_id' => $reviewerId, 'decision' => $decision,
            'feedback' => $feedback, 'reviewed_at' => date('Y-m-d H:i:s')];
    foreach (MAM_TEACHBACK_DIMENSIONS as $dim) {
        $set[$dim] = array_key_exists($dim, $dimensions) ? $clamp($dimensions[$dim]) : null;
    }
    mam_db_update('teachbacks', $set, ['id' => $teachbackId]);
    mam_audit('teachback_review', 'teachback', $teachbackId, ['by' => $reviewerId, 'decision' => $decision]);
    $row = mam_db_row('SELECT student_id, lesson_id FROM teachbacks WHERE id = ?', [$teachbackId]);
    if ($row && function_exists('mam_notify')) {
        mam_notify((int) $row['student_id'], 'teachback', 'Teach-back reviewed',
            'Your instructor reviewed your teach-back: ' . str_replace('_', ' ', $decision) . '.', null);
    }
    return mam_db_row('SELECT * FROM teachbacks WHERE id = ?', [$teachbackId]);
}

/** Submitted teach-backs awaiting review across an instructor's cohorts. */
function mam_preclass_instructor_queue(int $instructorId, string $status = 'submitted'): array {
    return mam_db_all(
        "SELECT tb.*, u.name AS student_name, l.title AS module_title, co.title AS cohort_title
           FROM teachbacks tb
           JOIN lessons l ON l.id = tb.lesson_id
           JOIN cohorts co ON co.id = l.cohort_id
           LEFT JOIN users u ON u.id = tb.student_id
          WHERE co.instructor_id = ? AND tb.status = ?
          ORDER BY tb.created_at ASC",
        [$instructorId, $status]
    );
}

/**
 * The §18 per-session pre-class view: the session's enrolled roster with each
 * student's preparation + teach-back status for the session's module.
 */
function mam_preclass_session_view(int $sessionId): array {
    $sess = mam_db_row('SELECT id, cohort_id, lesson_id, title, scheduled_at FROM class_sessions WHERE id = ?', [$sessionId]);
    if (!$sess) throw new RuntimeException('session_not_found');
    $lid = $sess['lesson_id'] ? (int) $sess['lesson_id'] : 0;
    $roster = mam_db_all(
        "SELECT u.id AS student_id, u.name AS student_name
           FROM course_enrollments ce JOIN users u ON u.id = ce.student_id
          WHERE ce.cohort_id = ? AND ce.status IN ('active','completed')
          ORDER BY u.name",
        [(int) $sess['cohort_id']]
    );
    foreach ($roster as &$r) {
        $sid = (int) $r['student_id'];
        if ($lid) {
            $lp = mam_db_row('SELECT status FROM lesson_progress WHERE lesson_id = ? AND student_id = ?', [$lid, $sid]);
            $r['prep'] = $lp ? (string) $lp['status'] : 'not_started';
            $tb = mam_db_row('SELECT status, kind, decision FROM teachbacks WHERE lesson_id = ? AND student_id = ?', [$lid, $sid]);
            $r['teachback'] = $tb ?: null;
        } else {
            $r['prep'] = null; $r['teachback'] = null;
        }
    }
    unset($r);
    return ['session' => $sess, 'lesson_id' => $lid ?: null, 'roster' => $roster];
}
