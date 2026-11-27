<?php
/**
 * MAM Academy — LMS 2.0 teaching helpers (Parts B & D).
 * ─────────────────────────────────────────────────────────────
 * Thin, tested domain functions over the v63–v65 additive tables:
 *   • assignment groups — one parent an author fans out into the existing
 *     per-student `assignments` rows;
 *   • gradebook v2 — reusable rubrics scored per submission (weighted, capped);
 *   • lessons + per-student progress;
 *   • submission files + resource version history;
 *   • scheduling core — instructor availability windows, rooms, and a signed
 *     read-only ICS calendar feed.
 *
 * Everything here is additive: none of it changes an existing code path, and
 * each table is inert until a caller uses it. Time values use PHP date()/
 * strtotime() (never datetime('now') inside a runtime query), matching the rest
 * of the codebase.
 */
declare(strict_types=1);

/* ── assignment groups ───────────────────────────────────────────────────── */

/** Create an assignment group (the fan-out parent). Returns its id. */
function mam_lms2_assignment_group_create(array $data): int {
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') throw new InvalidArgumentException('title_required');
    return mam_db_insert('assignment_groups', [
        'class_id'     => isset($data['class_id'])  ? (int) $data['class_id']  : null,
        'cohort_id'    => isset($data['cohort_id']) ? (int) $data['cohort_id'] : null,
        'author_id'    => isset($data['author_id']) ? (int) $data['author_id'] : null,
        'rubric_id'    => isset($data['rubric_id']) ? (int) $data['rubric_id'] : null,
        'title'        => $title,
        'instructions' => isset($data['instructions']) ? (string) $data['instructions'] : null,
        'due_on'       => isset($data['due_on']) && $data['due_on'] !== '' ? (string) $data['due_on'] : null,
    ]);
}

/**
 * Fan a group out into per-student assignment rows (the existing assignments
 * model). Idempotent: a student who already has a row for this group is
 * skipped, so re-running only fills the gaps. Returns the number created.
 */
function mam_lms2_assignment_group_fanout(int $groupId, array $studentIds): int {
    $g = mam_db_row('SELECT * FROM assignment_groups WHERE id = ?', [$groupId]);
    if (!$g) throw new RuntimeException('group_not_found');
    $created = 0;
    foreach (array_unique(array_map('intval', $studentIds)) as $sid) {
        if ($sid <= 0) continue;
        $exists = mam_db_row('SELECT id FROM assignments WHERE group_id = ? AND student_id = ?', [$groupId, $sid]);
        if ($exists) continue;
        mam_db_insert('assignments', [
            'student_id'   => $sid,
            'author_id'    => $g['author_id'] !== null ? (int) $g['author_id'] : null,
            'class_id'     => $g['class_id'] !== null ? (int) $g['class_id'] : null,
            'group_id'     => $groupId,
            'title'        => (string) $g['title'],
            'instructions' => $g['instructions'],
            'due_on'       => $g['due_on'],
            'status'       => 'open',
        ]);
        $created++;
    }
    return $created;
}

/** Groups (optionally scoped to a class or cohort) with their member counts. */
function mam_lms2_assignment_group_list(?int $classId = null, ?int $cohortId = null): array {
    $where = []; $args = [];
    if ($classId !== null)  { $where[] = 'ag.class_id = ?';  $args[] = $classId; }
    if ($cohortId !== null) { $where[] = 'ag.cohort_id = ?'; $args[] = $cohortId; }
    $sql = 'SELECT ag.*, (SELECT COUNT(*) FROM assignments a WHERE a.group_id = ag.id) AS member_count
              FROM assignment_groups ag';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY ag.id DESC';
    return mam_db_all($sql, $args);
}

/* ── gradebook v2: rubrics ───────────────────────────────────────────────── */

/**
 * Score a set of per-criterion points against a rubric. $scores maps
 * criterion_id → raw points; each is clamped to [0, max_points] and weighted.
 * Pure read (no writes). Returns total/max (weighted) and a percent.
 */
function mam_lms2_rubric_score(int $rubricId, array $scores): array {
    $criteria = mam_db_all('SELECT * FROM rubric_criteria WHERE rubric_id = ? ORDER BY sort_order, id', [$rubricId]);
    $total = 0.0; $max = 0.0; $per = [];
    foreach ($criteria as $c) {
        $cid    = (int) $c['id'];
        $maxPts = (float) $c['max_points'];
        $weight = (float) $c['weight'];
        $raw    = isset($scores[$cid]) ? (float) $scores[$cid] : 0.0;
        $clamped = max(0.0, min($maxPts, $raw));
        $total += $clamped * $weight;
        $max   += $maxPts  * $weight;
        $per[$cid] = ['points' => $clamped, 'max' => $maxPts, 'weight' => $weight];
    }
    return [
        'total_points' => round($total, 2),
        'max_points'   => round($max, 2),
        'percent'      => $max > 0 ? round(($total / $max) * 100, 1) : 0.0,
        'per_criterion'=> $per,
    ];
}

/**
 * Persist a rubric-scored review of a submission. When $enrollmentId is given,
 * the rubric percent is ALSO fed into the gradebook as an assessment of type
 * $gradeTypeCode (default 'assignment') — this is the wiring that makes a
 * rubric score move the student's computed grade. Returns the review id.
 */
function mam_lms2_submission_review_save(int $submissionId, ?int $rubricId, ?int $reviewerId, array $scores, ?string $feedback = null, ?int $enrollmentId = null, string $gradeTypeCode = 'assignment'): int {
    $computed = $rubricId
        ? mam_lms2_rubric_score($rubricId, $scores)
        : ['total_points' => null, 'max_points' => null, 'percent' => null];
    $id = mam_db_insert('submission_reviews', [
        'submission_id' => $submissionId,
        'rubric_id'     => $rubricId,
        'reviewer_id'   => $reviewerId,
        'scores_json'   => json_encode($scores),
        'total_points'  => $computed['total_points'],
        'max_points'    => $computed['max_points'],
        'feedback'      => $feedback,
    ]);
    // Feed the gradebook: the rubric percent becomes a weighted assessment.
    if ($enrollmentId !== null && $enrollmentId > 0 && $computed['percent'] !== null && function_exists('mam_lms_record_assessment')) {
        mam_lms_record_assessment($enrollmentId, $gradeTypeCode, $reviewerId, (float) $computed['percent'], 100.0);
    }
    return $id;
}

/** Best-effort map a submission to the student's course_enrollment, via the
 *  assignment group's cohort. Returns the enrolment id or null if unresolved. */
function mam_lms2_submission_enrollment(int $submissionId): ?int {
    $row = mam_db_row(
        "SELECT s.student_id, ag.cohort_id
           FROM submissions s
           JOIN assignments a ON a.id = s.assignment_id
           LEFT JOIN assignment_groups ag ON ag.id = a.group_id
          WHERE s.id = ?",
        [$submissionId]
    );
    if (!$row) return null;
    if ($row['cohort_id'] !== null) {
        $ce = mam_db_row(
            "SELECT id FROM course_enrollments WHERE student_id = ? AND cohort_id = ? AND status IN ('active','completed') LIMIT 1",
            [(int) $row['student_id'], (int) $row['cohort_id']]
        );
        if ($ce) return (int) $ce['id'];
    }
    // Fallback: an unambiguous single active enrolment for the student.
    $all = mam_db_all(
        "SELECT id FROM course_enrollments WHERE student_id = ? AND status IN ('active','completed')",
        [(int) $row['student_id']]
    );
    return count($all) === 1 ? (int) $all[0]['id'] : null;
}

/** All active rubrics (optionally scoped to a cohort or the global pool) with
 *  their criteria, for a scoring UI. */
function mam_lms2_rubrics_with_criteria(?int $cohortId = null): array {
    $rubrics = $cohortId !== null
        ? mam_db_all("SELECT * FROM rubrics WHERE active = 1 AND (cohort_id = ? OR cohort_id IS NULL) ORDER BY id DESC", [$cohortId])
        : mam_db_all("SELECT * FROM rubrics WHERE active = 1 ORDER BY id DESC");
    foreach ($rubrics as &$r) {
        $r['criteria'] = mam_db_all(
            "SELECT id, label, max_points, weight, sort_order FROM rubric_criteria WHERE rubric_id = ? ORDER BY sort_order, id",
            [(int) $r['id']]
        );
    }
    unset($r);
    return $rubrics;
}

/** Create a rubric with its criteria. Returns the rubric id. */
function mam_lms2_rubric_create(string $title, array $criteria, ?int $cohortId = null, ?int $by = null): int {
    $title = trim($title);
    if ($title === '') throw new InvalidArgumentException('title_required');
    $clean = array_values(array_filter($criteria, fn($c) => trim((string) ($c['label'] ?? '')) !== ''));
    if (!$clean) throw new InvalidArgumentException('criteria_required');
    $rid = mam_db_insert('rubrics', ['title' => $title, 'cohort_id' => $cohortId, 'created_by' => $by, 'active' => 1]);
    $i = 0;
    foreach ($clean as $c) {
        mam_db_insert('rubric_criteria', [
            'rubric_id'  => $rid,
            'label'      => trim((string) $c['label']),
            'max_points' => max(1, (int) ($c['max_points'] ?? 10)),
            'weight'     => max(0.0, (float) ($c['weight'] ?? 1.0)),
            'sort_order' => $i++,
        ]);
    }
    return $rid;
}

/** Update a rubric's title/scope/active and REPLACE its criteria (§21 admin CRUD). */
function mam_lms2_rubric_update(int $rubricId, string $title, array $criteria, ?int $cohortId = null, bool $active = true): void {
    $title = trim($title);
    if ($title === '') throw new InvalidArgumentException('title_required');
    $clean = array_values(array_filter($criteria, fn($c) => trim((string) ($c['label'] ?? '')) !== ''));
    if (!$clean) throw new InvalidArgumentException('criteria_required');
    if (!mam_db_row('SELECT id FROM rubrics WHERE id = ?', [$rubricId])) throw new RuntimeException('not_found');
    mam_db_update('rubrics', ['title' => $title, 'cohort_id' => $cohortId, 'active' => $active ? 1 : 0], ['id' => $rubricId]);
    mam_db()->prepare('DELETE FROM rubric_criteria WHERE rubric_id = ?')->execute([$rubricId]);
    $i = 0;
    foreach ($clean as $c) {
        mam_db_insert('rubric_criteria', [
            'rubric_id'  => $rubricId,
            'label'      => trim((string) $c['label']),
            'max_points' => max(1, (int) ($c['max_points'] ?? 10)),
            'weight'     => max(0.0, (float) ($c['weight'] ?? 1.0)),
            'sort_order' => $i++,
        ]);
    }
}

/** Delete a rubric; its criteria cascade. */
function mam_lms2_rubric_delete(int $rubricId): void {
    mam_db()->prepare('DELETE FROM rubrics WHERE id = ?')->execute([$rubricId]);
}

/**
 * Admin listing: every rubric (active AND inactive) with its criteria and the
 * Programme→Instrument(course)→Grade(level) context of its assigned cohort, so
 * the admin console can author and assign rubrics per §21.
 */
function mam_lms2_rubrics_admin(): array {
    $rubrics = mam_db_all(
        "SELECT r.*, co.title AS cohort_title,
                c.name AS course_name, l.name AS level_name, p.name AS program_name
           FROM rubrics r
           LEFT JOIN cohorts  co ON co.id = r.cohort_id
           LEFT JOIN courses  c  ON c.id  = co.course_id
           LEFT JOIN levels   l  ON l.id  = co.level_id
           LEFT JOIN programs p  ON p.id  = co.program_id
          ORDER BY r.active DESC, r.id DESC"
    );
    foreach ($rubrics as &$r) {
        $r['criteria'] = mam_db_all(
            "SELECT id, label, max_points, weight, sort_order FROM rubric_criteria WHERE rubric_id = ? ORDER BY sort_order, id",
            [(int) $r['id']]
        );
    }
    unset($r);
    return $rubrics;
}

/**
 * Score an enrolment against a rubric directly in the gradebook: the rubric
 * percent is recorded as a weighted assessment of $typeCode via the existing
 * gradebook engine, and the recomputed result is returned. (No submission is
 * required — this is instructor-driven competency scoring per PDF §20.)
 */
function mam_lms2_rubric_grade(int $enrollmentId, int $rubricId, array $scores, ?int $by = null, string $typeCode = 'practical', ?string $feedback = null): array {
    $computed = mam_lms2_rubric_score($rubricId, $scores);
    $result   = mam_lms_record_assessment($enrollmentId, $typeCode, $by, (float) $computed['percent'], 100.0, null, $feedback);
    return ['computed' => $computed, 'result' => $result];
}

/* ── term GPA / trend / percentile (read-only aggregates over results) ────── */

/** 4.0-scale grade points for a letter grade. */
function mam_lms2_grade_points(?string $grade): float {
    switch (strtoupper((string) $grade)) {
        case 'A': return 4.0;
        case 'B': return 3.0;
        case 'C': return 2.0;
        case 'D': return 1.0;
        default:  return 0.0; // F / ungraded
    }
}

/**
 * A student's GPA across their finalised course results: the 4.0-scale GPA, the
 * mean percentage, the course count, and the grade distribution.
 */
function mam_lms2_student_gpa(int $studentId): array {
    $rows = mam_db_all(
        "SELECT r.grade, r.total_score
           FROM results r JOIN course_enrollments ce ON ce.id = r.enrollment_id
          WHERE ce.student_id = ? AND r.grade IS NOT NULL",
        [$studentId]
    );
    $n = count($rows);
    if ($n === 0) return ['gpa' => null, 'avg_score' => null, 'courses' => 0, 'distribution' => []];
    $pts = 0.0; $score = 0.0; $dist = [];
    foreach ($rows as $r) {
        $pts   += mam_lms2_grade_points($r['grade']);
        $score += (float) $r['total_score'];
        $g = strtoupper((string) $r['grade']);
        $dist[$g] = ($dist[$g] ?? 0) + 1;
    }
    return [
        'gpa'          => round($pts / $n, 2),
        'avg_score'    => round($score / $n, 1),
        'courses'      => $n,
        'distribution' => $dist,
    ];
}

/**
 * Term-over-term grade trend: mean score + GPA per cohort term, ordered by term.
 * Cohorts with no `term` are grouped under their title so the series is stable.
 */
function mam_lms2_student_grade_trend(int $studentId): array {
    $rows = mam_db_all(
        "SELECT COALESCE(NULLIF(co.term,''), co.title, 'Unknown') AS term_label,
                r.grade, r.total_score
           FROM results r
           JOIN course_enrollments ce ON ce.id = r.enrollment_id
           LEFT JOIN cohorts co ON co.id = ce.cohort_id
          WHERE ce.student_id = ? AND r.total_score IS NOT NULL",
        [$studentId]
    );
    $buckets = [];
    foreach ($rows as $r) {
        $k = (string) $r['term_label'];
        if (!isset($buckets[$k])) $buckets[$k] = ['term' => $k, 'sum' => 0.0, 'gp' => 0.0, 'n' => 0];
        $buckets[$k]['sum'] += (float) $r['total_score'];
        $buckets[$k]['gp']  += mam_lms2_grade_points($r['grade']);
        $buckets[$k]['n']++;
    }
    $out = [];
    foreach ($buckets as $b) {
        $out[] = ['term' => $b['term'], 'avg_score' => round($b['sum'] / $b['n'], 1), 'gpa' => round($b['gp'] / $b['n'], 2), 'courses' => $b['n']];
    }
    usort($out, fn($a, $b) => strcmp($a['term'], $b['term']));
    return $out;
}

/**
 * A student's percentile within a cohort by finalised total_score: the
 * percentile (share of peers scoring at or below them), 1-based rank, cohort
 * size, and the student's own score. Null when the student has no result there.
 */
function mam_lms2_cohort_percentile(int $studentId, int $cohortId): array {
    $scores = mam_db_all(
        "SELECT ce.student_id, r.total_score
           FROM results r JOIN course_enrollments ce ON ce.id = r.enrollment_id
          WHERE ce.cohort_id = ? AND r.total_score IS NOT NULL",
        [$cohortId]
    );
    $mine = null; $vals = [];
    foreach ($scores as $s) {
        $v = (float) $s['total_score'];
        $vals[] = $v;
        if ((int) $s['student_id'] === $studentId) $mine = $v;
    }
    $size = count($vals);
    if ($mine === null || $size === 0) return ['percentile' => null, 'rank' => null, 'cohort_size' => $size, 'score' => $mine];
    $atOrBelow = 0; $above = 0;
    foreach ($vals as $v) { if ($v <= $mine) $atOrBelow++; if ($v > $mine) $above++; }
    return [
        'percentile'  => round($atOrBelow / $size * 100, 1),
        'rank'        => $above + 1, // 1 = top scorer
        'cohort_size' => $size,
        'score'       => round($mine, 1),
    ];
}

/* ── lessons + progress ──────────────────────────────────────────────────── */

/**
 * Upsert a student's progress on a lesson (unique per lesson+student). percent
 * is clamped to 0–100; reaching 100 marks it completed and stamps completed_at
 * unless a status is given. Returns the resulting row.
 */
function mam_lms2_lesson_progress_set(int $lessonId, int $studentId, int $percent, ?string $status = null): array {
    if ($lessonId <= 0 || $studentId <= 0) throw new InvalidArgumentException('validation');
    $percent  = max(0, min(100, $percent));
    $now      = date('Y-m-d H:i:s');
    $explicit = $status !== null;                 // caller forced a status?
    if (!$explicit) $status = $percent >= 100 ? 'completed' : 'in_progress';
    $completedAt = $status === 'completed' ? $now : null;

    $existing = mam_db_row('SELECT id, status, completed_at FROM lesson_progress WHERE lesson_id = ? AND student_id = ?', [$lessonId, $studentId]);
    if ($existing) {
        // Completion is sticky: once a lesson is done, a later partial update
        // (e.g. re-watching) keeps it completed and preserves the timestamp —
        // only an explicit status change can un-complete it.
        if (!$explicit && (string) $existing['status'] === 'completed') {
            $status      = 'completed';
            $completedAt = (string) $existing['completed_at'];
        } elseif ($completedAt === null && $existing['completed_at'] !== null) {
            $completedAt = (string) $existing['completed_at'];
        }
        mam_db_update('lesson_progress', [
            'status' => $status, 'percent' => $percent, 'completed_at' => $completedAt, 'updated_at' => $now,
        ], ['id' => (int) $existing['id']]);
    } else {
        mam_db_insert('lesson_progress', [
            'lesson_id' => $lessonId, 'student_id' => $studentId, 'status' => $status,
            'percent' => $percent, 'completed_at' => $completedAt, 'updated_at' => $now,
        ]);
    }
    return mam_db_row('SELECT * FROM lesson_progress WHERE lesson_id = ? AND student_id = ?', [$lessonId, $studentId]);
}

/** A student's completion summary across a cohort's published lessons. */
function mam_lms2_lesson_progress_summary(int $studentId, int $cohortId): array {
    $total = (int) (mam_db_row('SELECT COUNT(*) AS c FROM lessons WHERE cohort_id = ? AND published = 1', [$cohortId])['c'] ?? 0);
    $done  = (int) (mam_db_row(
        "SELECT COUNT(*) AS c FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
          WHERE l.cohort_id = ? AND l.published = 1 AND lp.student_id = ? AND lp.status = 'completed'",
        [$cohortId, $studentId]
    )['c'] ?? 0);
    return ['total' => $total, 'completed' => $done, 'percent' => $total > 0 ? round(($done / $total) * 100, 1) : 0.0];
}

/* ── module status + carry-forward (PDF §6-7, 29-30) ──────────────────────── */

/** The §7 module status vocabulary. */
const MAM_MODULE_STATUSES = [
    'not_started', 'available', 'preparation_pending', 'in_progress', 'submitted',
    'under_assessment', 'developing', 'proficient', 'advanced', 'mastered',
    'carried_forward', 'remedial', 'completed', 'exempted',
];

/** Current module state for a student (the lesson_progress row), or a
 *  not_started default. */
function mam_module_state(int $lessonId, int $studentId): array {
    $row = mam_db_row('SELECT * FROM lesson_progress WHERE lesson_id = ? AND student_id = ?', [$lessonId, $studentId]);
    if ($row) return $row;
    return ['lesson_id' => $lessonId, 'student_id' => $studentId, 'status' => 'not_started', 'percent' => 0, 'mastery_pct' => null, 'attempts' => 0];
}

/**
 * Set a module's status (and optionally mastery %) for a student, upserting the
 * lesson_progress row. Records week_started on first movement and week_completed
 * when a terminal-mastery status is set. Throws on an unknown status.
 */
function mam_module_status_set(int $lessonId, int $studentId, string $status, ?float $masteryPct = null, ?int $week = null): array {
    if (!in_array($status, MAM_MODULE_STATUSES, true)) throw new InvalidArgumentException('bad_status');
    if ($lessonId <= 0 || $studentId <= 0) throw new InvalidArgumentException('validation');
    $now = date('Y-m-d H:i:s');
    $terminal = in_array($status, ['mastered', 'completed', 'proficient', 'advanced', 'exempted'], true);
    $existing = mam_db_row('SELECT id, week_started, completed_at FROM lesson_progress WHERE lesson_id = ? AND student_id = ?', [$lessonId, $studentId]);
    $fields = [
        'status'      => $status,
        'mastery_pct' => $masteryPct !== null ? round($masteryPct, 1) : ($existing['mastery_pct'] ?? null),
        'updated_at'  => $now,
    ];
    if ($masteryPct !== null) $fields['percent'] = (int) round($masteryPct);
    if ($terminal) { $fields['completed_at'] = $now; if ($week !== null) $fields['week_completed'] = $week; }
    if ($existing) {
        if (!$existing['week_started'] && $week !== null) $fields['week_started'] = $week;
        mam_db_update('lesson_progress', $fields, ['id' => (int) $existing['id']]);
    } else {
        mam_db_insert('lesson_progress', array_merge([
            'lesson_id' => $lessonId, 'student_id' => $studentId, 'percent' => $fields['percent'] ?? 0,
            'week_started' => $week, 'week_scheduled' => $week,
        ], $fields));
    }
    return mam_module_state($lessonId, $studentId);
}

/**
 * Carry a module forward to a later week / date (§29-30). APPENDS a
 * module_carryforward event capturing the prior status/mastery/week (nothing is
 * deleted), then marks the module 'carried_forward', bumps attempts, and sets
 * the new deadline. Returns ['event_id','state'].
 */
function mam_module_carry_forward(int $lessonId, int $studentId, ?int $toWeek, ?string $toDate, ?string $reason, ?int $by = null): array {
    if ($lessonId <= 0 || $studentId <= 0) throw new InvalidArgumentException('validation');
    if (!mam_db_row('SELECT id FROM lessons WHERE id = ?', [$lessonId])) throw new RuntimeException('lesson_not_found');
    $prev = mam_module_state($lessonId, $studentId);
    $now  = date('Y-m-d H:i:s');
    $eventId = mam_db_insert('module_carryforward', [
        'lesson_id' => $lessonId, 'student_id' => $studentId,
        'from_week' => $prev['week_scheduled'] ?? ($prev['week_started'] ?? null),
        'to_week'   => $toWeek, 'to_date' => $toDate,
        'reason'    => $reason,
        'previous_status'  => (string) ($prev['status'] ?? 'not_started'),
        'previous_mastery' => $prev['mastery_pct'] ?? null,
        'created_by' => $by,
    ]);
    $attempts = (int) ($prev['attempts'] ?? 0) + 1;
    $existing = mam_db_row('SELECT id FROM lesson_progress WHERE lesson_id = ? AND student_id = ?', [$lessonId, $studentId]);
    $fields = ['status' => 'carried_forward', 'attempts' => $attempts, 'due_at' => $toDate, 'updated_at' => $now];
    if ($existing) mam_db_update('lesson_progress', $fields, ['id' => (int) $existing['id']]);
    else mam_db_insert('lesson_progress', array_merge(['lesson_id' => $lessonId, 'student_id' => $studentId, 'percent' => 0], $fields));
    mam_audit('module_carry_forward', 'lesson', $lessonId, ['student' => $studentId, 'to_week' => $toWeek, 'attempt' => $attempts]);
    if (function_exists('mam_notify')) {
        mam_notify($studentId, 'module', 'Module carried forward',
            'Your module has been carried forward' . ($toWeek ? ' to week ' . $toWeek : '') . '. Keep going — your progress is saved.', null);
    }
    return ['event_id' => $eventId, 'state' => mam_module_state($lessonId, $studentId)];
}

/** Carry-forward history for a module+student, newest first, + current state. */
function mam_module_history(int $lessonId, int $studentId): array {
    return [
        'state'   => mam_module_state($lessonId, $studentId),
        'history' => mam_db_all(
            'SELECT * FROM module_carryforward WHERE lesson_id = ? AND student_id = ? ORDER BY id DESC',
            [$lessonId, $studentId]
        ),
    ];
}

/* ── submission files + resource versions ────────────────────────────────── */

/** Attach a file to a submission. Returns the file id. */
function mam_lms2_submission_add_file(int $submissionId, string $url, string $kind = 'link', ?string $filename = null, ?int $sizeBytes = null): int {
    if ($url === '') throw new InvalidArgumentException('url_required');
    return mam_db_insert('submission_files', [
        'submission_id' => $submissionId, 'url' => $url, 'kind' => $kind,
        'filename' => $filename, 'size_bytes' => $sizeBytes,
    ]);
}

/** Append a new version of a class resource; auto-increments the version no. */
function mam_lms2_resource_version_add(int $resourceId, string $url, ?string $title = null, ?string $note = null, ?int $by = null): int {
    if ($url === '') throw new InvalidArgumentException('url_required');
    $next = 1 + (int) (mam_db_row('SELECT COALESCE(MAX(version),0) AS v FROM resource_versions WHERE resource_id = ?', [$resourceId])['v'] ?? 0);
    return mam_db_insert('resource_versions', [
        'resource_id' => $resourceId, 'version' => $next, 'title' => $title,
        'url' => $url, 'note' => $note, 'created_by' => $by,
    ]);
}

/* ── scheduling: rooms + availability ────────────────────────────────────── */

function mam_lms2_room_create(string $name, ?int $capacity = null, ?string $location = null): int {
    if (trim($name) === '') throw new InvalidArgumentException('name_required');
    return mam_db_insert('rooms', ['name' => $name, 'capacity' => $capacity, 'location' => $location]);
}
function mam_lms2_session_set_room(int $sessionId, ?int $roomId): void {
    mam_db_update('class_sessions', ['room_id' => $roomId], ['id' => $sessionId]);
}

/**
 * Is an instructor available for a [datetime, +duration) slot? A slot is
 * available when it falls fully within one active availability window for that
 * weekday (respecting any effective_from/to range). An instructor with NO
 * availability rows is treated as unconstrained (returns true) so the feature
 * never blocks scheduling until availability is actually configured.
 */
function mam_lms2_instructor_available(int $instructorId, string $datetime, int $durationMin = 60): bool {
    $rows = mam_db_all('SELECT * FROM instructor_availability WHERE instructor_id = ? AND active = 1', [$instructorId]);
    if (!$rows) return true; // unconstrained until configured

    $startTs = strtotime($datetime);
    if ($startTs === false) return false;
    $endTs   = $startTs + $durationMin * 60;
    $dow     = (int) date('w', $startTs);          // 0=Sun … 6=Sat
    $date    = date('Y-m-d', $startTs);
    $slotStart = date('H:i:s', $startTs);
    // End time as H:i:s on the same day; a slot crossing midnight can't fit a
    // single same-day window, so it simply won't match (correctly unavailable).
    $slotEnd = date('H:i:s', $endTs);

    foreach ($rows as $r) {
        if ((int) $r['day_of_week'] !== $dow) continue;
        if ($r['effective_from'] !== null && $r['effective_from'] !== '' && $date < (string) $r['effective_from']) continue;
        if ($r['effective_to']   !== null && $r['effective_to']   !== '' && $date > (string) $r['effective_to'])   continue;
        if ($slotStart >= (string) $r['start_time'] && $slotEnd <= (string) $r['end_time']) return true;
    }
    return false;
}

/* ── calendar feed (signed, read-only ICS) ───────────────────────────────── */

/** Mint (or reuse) an active calendar token for a user. Returns the token. */
function mam_lms2_calendar_token_mint(int $userId, string $scope = 'sessions'): string {
    if ($userId <= 0) throw new InvalidArgumentException('bad_user');
    $existing = mam_db_row('SELECT token FROM calendar_tokens WHERE user_id = ? AND scope = ? AND active = 1', [$userId, $scope]);
    if ($existing) return (string) $existing['token'];
    $token = bin2hex(random_bytes(24));
    mam_db_insert('calendar_tokens', ['user_id' => $userId, 'token' => $token, 'scope' => $scope]);
    return $token;
}

/** A user's upcoming scheduled sessions — both those they're enrolled in (via
 *  cohort) and those they teach. Used to build the ICS feed. */
function mam_lms2_user_upcoming_sessions(int $userId, string $since, int $limit = 200): array {
    $limit = max(1, min(500, $limit));
    return mam_db_all(
        "SELECT cs.id, cs.title, cs.scheduled_at, cs.duration_min, cs.location, cs.room, co.title AS cohort_title
           FROM class_sessions cs
           JOIN cohorts co ON co.id = cs.cohort_id
          WHERE cs.status = 'scheduled' AND cs.scheduled_at >= ?
            AND ( co.instructor_id = ?
                  OR cs.cohort_id IN (SELECT cohort_id FROM course_enrollments
                                       WHERE student_id = ? AND cohort_id IS NOT NULL
                                         AND status NOT IN ('withdrawn','cancelled','rejected','failed')) )
          ORDER BY cs.scheduled_at ASC LIMIT " . $limit,
        [$since, $userId, $userId]
    );
}

/** Escape a text value for an ICS property (RFC 5545 §3.3.11). */
function mam_lms2_ics_escape(string $s): string {
    return str_replace(["\\", "\n", ",", ";"], ["\\\\", "\\n", "\\,", "\\;"], $s);
}

/**
 * Resolve a calendar token to its owner's ICS feed of upcoming sessions, or
 * null if the token is unknown/inactive. Stamps last_used_at. Session times are
 * stored as UTC 'Y-m-d H:i:s' and emitted as UTC (…Z).
 */
function mam_lms2_ics_for_token(string $token): ?string {
    $token = trim($token);
    if ($token === '') return null;
    $row = mam_db_row('SELECT * FROM calendar_tokens WHERE token = ? AND active = 1', [$token]);
    if (!$row) return null;
    $userId = (int) $row['user_id'];
    mam_db_update('calendar_tokens', ['last_used_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);

    $since = date('Y-m-d H:i:s', time() - 86400); // include today
    $sessions = mam_lms2_user_upcoming_sessions($userId, $since);

    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//MAM Academy//LMS 2.0//EN', 'CALSCALE:GREGORIAN'];
    foreach ($sessions as $s) {
        $startTs = strtotime((string) $s['scheduled_at']);
        if ($startTs === false) continue;
        $endTs = $startTs + ((int) ($s['duration_min'] ?? 60)) * 60;
        $title = (string) ($s['title'] !== '' && $s['title'] !== null ? $s['title'] : ($s['cohort_title'] ?? 'Class session'));
        $loc   = trim((string) (($s['room'] ?? '') !== '' ? $s['room'] : ($s['location'] ?? '')));
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:mam-session-' . (int) $s['id'] . '@mamacademy';
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lines[] = 'DTSTART:' . gmdate('Ymd\THis\Z', $startTs);
        $lines[] = 'DTEND:' . gmdate('Ymd\THis\Z', $endTs);
        $lines[] = 'SUMMARY:' . mam_lms2_ics_escape($title);
        if ($loc !== '') $lines[] = 'LOCATION:' . mam_lms2_ics_escape($loc);
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
}

/**
 * What an instructor OWES right now — the one thing their dashboard never said.
 *
 * The overview showed what had happened (attendance breakdown, class fill,
 * recent marks) and nothing about what was outstanding. That gap has a cost
 * attached: `progress_report_deadline` fires two hours after a session is
 * marked held and records the missing reports as overdue, so an instructor's
 * first news of an obligation was a notification telling them they had already
 * missed it.
 *
 * Two decisions worth keeping:
 *
 *  • **The deadline is read from the queued job, not recomputed.** A session can
 *    be marked held long after it was scheduled, so deriving "+2 hours from
 *    scheduled_at" would show a deadline the enforcement does not use. Reading
 *    `jobs.run_after` means the dashboard and the fine cannot disagree about
 *    when a report is late.
 *  • **Every branch is best-effort.** A dashboard that 500s because one count
 *    failed is worse than one that shows the rest; each block degrades to empty
 *    and the caller renders what survived.
 */
function mam_lms2_instructor_worklist(int $instructorId, int $limit = 8): array
{
    $soft = static function (callable $fn, $default) {
        try { return $fn(); } catch (\Throwable $e) {
            if (function_exists('mam_log')) mam_log('warn', 'instructor_worklist_block_failed', ['err' => $e->getMessage()]);
            return $default;
        }
    };
    $now = date('Y-m-d H:i:s');

    // The next session they are due to teach, from either model: a cohort they
    // own, or one where they are the substitute.
    $next = $soft(static fn () => mam_db_row(
        "SELECT cs.id, cs.title, cs.scheduled_at, cs.duration_min, cs.mode, cs.meeting_link,
                co.title AS cohort_title, co.id AS cohort_id
           FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id
          WHERE (co.instructor_id = ? OR cs.substitute_id = ?)
            AND cs.status = 'scheduled' AND cs.scheduled_at >= ?
          ORDER BY cs.scheduled_at ASC LIMIT 1",
        [$instructorId, $instructorId, $now]
    ), null);
    if ($next) {
        $mins = (int) round((strtotime((string) $next['scheduled_at']) - strtotime($now)) / 60);
        $next['minutes_until'] = $mins;
        $next['starts_today']  = substr((string) $next['scheduled_at'], 0, 10) === substr($now, 0, 10);
    }

    // Held sessions whose progress reports are still outstanding. The roster is
    // the cohort's ACTIVE course enrolments — the same set the deadline check
    // counts, so the two never disagree about how many are missing.
    $reports = $soft(static fn () => mam_db_all(
        "SELECT cs.id, cs.title, cs.scheduled_at, co.title AS cohort_title,
                (SELECT COUNT(DISTINCT ce.student_id) FROM course_enrollments ce
                  WHERE ce.cohort_id = cs.cohort_id AND ce.status = 'active') AS roster,
                (SELECT COUNT(DISTINCT pr.student_id) FROM progress_reports pr
                  WHERE pr.session_id = cs.id) AS done,
                (SELECT j.run_after FROM jobs j
                  WHERE j.kind = 'progress_report_deadline'
                    AND j.payload LIKE '%\"session_id\":' || cs.id || '%'
                  ORDER BY j.id DESC LIMIT 1) AS deadline_at
           FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id
          WHERE co.instructor_id = ? AND cs.status = 'held'
          ORDER BY cs.scheduled_at DESC LIMIT " . max(1, min(50, $limit * 3)),
        [$instructorId]
    ), []);

    $due = [];
    foreach ($reports as $r) {
        $missing = max(0, (int) $r['roster'] - (int) $r['done']);
        if ($missing === 0) continue;                 // nothing owed for this one
        if ((int) $r['roster'] === 0) continue;       // no roster: nothing to report on
        $deadline = $r['deadline_at'] ?: null;
        $hoursLeft = $deadline ? (int) floor((strtotime((string) $deadline) - strtotime($now)) / 3600) : null;
        $due[] = [
            'session_id'   => (int) $r['id'],
            'title'        => (string) ($r['title'] ?? 'Session'),
            'cohort_title' => (string) ($r['cohort_title'] ?? ''),
            'scheduled_at' => (string) $r['scheduled_at'],
            'missing'      => $missing,
            'roster'       => (int) $r['roster'],
            'deadline_at'  => $deadline,
            'hours_left'   => $hoursLeft,
            // `overdue` is DERIVED, so a report that falls due at midnight reads
            // as overdue on the next page load rather than the next cron run —
            // the same rule as card expiry and vetting validity.
            'overdue'      => $deadline !== null && $deadline < $now,
        ];
        if (count($due) >= $limit) break;
    }

    // Held sessions with no attendance marked at all. Attendance is the input to
    // everything downstream — mastery, the guardian strip, the absence sweep —
    // so an unmarked session is not a small omission.
    $unmarked = $soft(static fn () => mam_db_all(
        "SELECT cs.id, cs.title, cs.scheduled_at, co.title AS cohort_title
           FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id
          WHERE co.instructor_id = ? AND cs.status = 'held'
            AND NOT EXISTS (SELECT 1 FROM session_attendance sa WHERE sa.session_id = cs.id)
          ORDER BY cs.scheduled_at DESC LIMIT " . max(1, min(50, $limit)),
        [$instructorId]
    ), []);

    // Work handed in and not yet looked at.
    $toReview = $soft(static fn () => (int) (mam_db_row(
        "SELECT COUNT(*) c FROM submissions s
           JOIN assignments a ON a.id = s.assignment_id
          WHERE a.author_id = ? AND s.status IN ('submitted','resubmitted')",
        [$instructorId]
    )['c'] ?? 0), 0);

    $overdue = 0;
    foreach ($due as $d) if ($d['overdue']) $overdue++;

    return [
        'next_session'    => $next ?: null,
        'reports_due'     => $due,
        'reports_overdue' => $overdue,
        'unmarked'        => $unmarked,
        'to_review'       => $toReview,
        // One number for the nav badge: everything genuinely awaiting this person.
        'total'           => count($due) + count($unmarked) + ($toReview > 0 ? 1 : 0),
    ];
}
