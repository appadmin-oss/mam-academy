<?php
/**
 * MAM Academy — Upper Admin strategic intelligence (PDF §34-35, §44).
 * ─────────────────────────────────────────────────────────────
 * §34 asks for a management view that answers one question at a time —
 * "how is the academy doing?" — and then lets somebody walk down
 *
 *     Academy → Programme → Instrument → Grade → Instructor → Student
 *
 * until they find the thing that explains the number. The metrics themselves
 * already existed in `analytics2.php`; what did not was the ability to compute
 * them for a SCOPE. `mam_analytics2_academy_health()` answers only for the whole
 * academy, so "why is preparation down?" had no follow-up question.
 *
 * The design decision worth stating: every level of the drill-down returns the
 * SAME metric set, computed the same way. A view whose columns change as you
 * descend cannot be compared across levels, and the comparison is the entire
 * point — the manager is looking for the row that differs from its siblings.
 *
 * Scoping resolves to a set of cohort ids first, then every metric is computed
 * over the students in those cohorts. This means a filter combination that
 * matches nothing returns honest nulls rather than academy-wide figures.
 *
 * Read-only: this file owns no table and writes nothing.
 */
declare(strict_types=1);

/**
 * The §34 drill-down chain, in order. Each entry names the column the level
 * groups by and the filter key it sets when you descend through it.
 */
const MAM_UPPER_DIMENSIONS = [
    'programme'  => ['label' => 'Programme',  'filter' => 'program_id'],
    'instrument' => ['label' => 'Instrument', 'filter' => 'course_id'],
    'grade'      => ['label' => 'Grade',      'filter' => 'level_id'],
    'instructor' => ['label' => 'Instructor', 'filter' => 'instructor_id'],
    'student'    => ['label' => 'Student',    'filter' => 'student_id'],
];

/** The next dimension down from `$d`, or null at the bottom of the chain. */
function mam_upper_next_dimension(?string $d): ?string {
    $keys = array_keys(MAM_UPPER_DIMENSIONS);
    if ($d === null || $d === '') return $keys[0];
    $i = array_search($d, $keys, true);
    if ($i === false || $i + 1 >= count($keys)) return null;
    return $keys[$i + 1];
}

/**
 * Normalise a request into a filter. Only the four cohort-shaped keys and
 * `student_id` are honoured, and each must be a positive integer — the filter
 * is interpolated nowhere, but a whitelist keeps the drill-down from becoming
 * an arbitrary query surface as it grows.
 */
function mam_upper_filter(array $in): array {
    $out = [];
    foreach (['program_id', 'course_id', 'level_id', 'instructor_id', 'student_id'] as $k) {
        $v = (int) ($in[$k] ?? 0);
        if ($v > 0) $out[$k] = $v;
    }
    return $out;
}

/**
 * Cohort ids matching a filter. Returns null for "no restriction" (the whole
 * academy), which callers must distinguish from an empty array — the latter
 * means the filter matched nothing and every metric should be null, not
 * academy-wide.
 */
function mam_upper_scope_cohorts(array $filter): ?array {
    $where = []; $args = [];
    foreach (['program_id', 'course_id', 'level_id', 'instructor_id'] as $k) {
        if (isset($filter[$k])) { $where[] = "co.$k = ?"; $args[] = $filter[$k]; }
    }
    if (isset($filter['student_id'])) {
        $where[] = "co.id IN (SELECT cohort_id FROM course_enrollments WHERE student_id = ? AND cohort_id IS NOT NULL)";
        $args[] = $filter['student_id'];
    }
    if (!$where) return null;
    $rows = mam_db_all('SELECT id FROM cohorts co WHERE ' . implode(' AND ', $where), $args);
    return array_map(fn($r) => (int) $r['id'], $rows);
}

/**
 * A scalar over a scope. `{SCOPE}` in the template is replaced by the cohort
 * restriction, built from `$cohortCol` — the column in THAT query which holds
 * the cohort id (it differs per table: `cs.cohort_id`, `l.cohort_id`,
 * `ce.cohort_id`). The column name is supplied by this file only, never by a
 * request, and the ids themselves are bound.
 *
 * `$cohortIds === null` means academy-wide; an empty array short-circuits to
 * the default rather than emitting an `IN ()` that no driver accepts.
 */
function mam_upper_scalar(string $sql, string $cohortCol, ?array $cohortIds, float $default = 0.0): float {
    if ($cohortIds !== null && !$cohortIds) return $default;
    if ($cohortIds === null) {
        $sql  = str_replace('{SCOPE}', '1=1', $sql);
        $args = [];
    } else {
        $ph   = implode(',', array_fill(0, count($cohortIds), '?'));
        $sql  = str_replace('{SCOPE}', "$cohortCol IN ($ph)", $sql);
        $args = $cohortIds;
    }
    try {
        $row = mam_db_row($sql, $args);
        $v = $row ? reset($row) : null;
        return $v === null ? $default : (float) $v;
    } catch (\Throwable $e) {
        // A dashboard degrades rather than 500s — but it must not degrade
        // SILENTLY. Returning 0 for a broken query is indistinguishable from a
        // genuine zero, which is the exact failure this screen exists to avoid.
        // Log it so a mistyped column is discoverable instead of quietly
        // reporting an academy with no students.
        mam_log('error', 'upper_admin_metric_failed', [
            'error'  => $e->getMessage(),
            'column' => $cohortCol,
            'sql'    => preg_replace('/\s+/', ' ', substr($sql, 0, 160)),
        ]);
        return $default;
    }
}

/**
 * The §34 headline metrics for a scope: preparation, attendance, teach-back
 * completion, average mastery, module completion, students, and the number
 * requiring intervention. Percentages are null (not 0) where there is nothing
 * to measure — "no data" and "zero percent" are different findings and a
 * management dashboard must not conflate them.
 */
function mam_upper_health(array $filter = []): array {
    $c = mam_upper_scope_cohorts($filter);

    $pct = function (float $num, float $den): ?float {
        return $den > 0 ? round($num / $den * 100, 1) : null;
    };

    $attTotal = mam_upper_scalar(
        "SELECT COUNT(*) AS v FROM session_attendance sa
           JOIN class_sessions cs ON cs.id = sa.session_id WHERE {SCOPE}", 'cs.cohort_id', $c);
    $attGood = mam_upper_scalar(
        "SELECT COUNT(*) AS v FROM session_attendance sa
           JOIN class_sessions cs ON cs.id = sa.session_id
          WHERE sa.status IN ('present','late','left_early') AND {SCOPE}", 'cs.cohort_id', $c);

    $prepTotal = mam_upper_scalar(
        "SELECT COUNT(*) AS v FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id WHERE {SCOPE}", 'l.cohort_id', $c);
    $prepDone = mam_upper_scalar(
        "SELECT COUNT(*) AS v FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
          WHERE (lp.status = 'completed' OR lp.percent >= 100) AND {SCOPE}", 'l.cohort_id', $c);
    $modDone = mam_upper_scalar(
        "SELECT COUNT(*) AS v FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
          WHERE lp.status IN ('completed','mastered','proficient','advanced','exempted') AND {SCOPE}", 'l.cohort_id', $c);

    $tbTotal = mam_upper_scalar(
        "SELECT COUNT(*) AS v FROM teachbacks tb JOIN lessons l ON l.id = tb.lesson_id WHERE {SCOPE}", 'l.cohort_id', $c);
    $tbDone = mam_upper_scalar(
        "SELECT COUNT(*) AS v FROM teachbacks tb JOIN lessons l ON l.id = tb.lesson_id
          WHERE tb.status = 'reviewed' AND {SCOPE}", 'l.cohort_id', $c);

    // AVG over an empty set is NULL — exactly the answer we want, so a sentinel
    // distinguishes it from a genuine average of zero.
    $m = mam_upper_scalar(
        "SELECT AVG(r.total_score) AS v FROM results r
           JOIN course_enrollments ce ON ce.id = r.enrollment_id
          WHERE r.total_score IS NOT NULL AND {SCOPE}", 'ce.cohort_id', $c, -1.0);
    $mastery = $m < 0 ? null : round($m, 1);

    $students = (int) mam_upper_scalar(
        "SELECT COUNT(DISTINCT ce.student_id) AS v FROM course_enrollments ce
          WHERE ce.status IN ('active','completed') AND {SCOPE}", 'ce.cohort_id', $c);

    $intervention = (int) mam_upper_scalar(
        "SELECT COUNT(DISTINCT lp.student_id) AS v FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
          WHERE (lp.status IN ('remedial','carried_forward') OR lp.attempts >= 2) AND {SCOPE}", 'l.cohort_id', $c);

    return [
        'students'              => $students,
        'preparation_pct'       => $pct($prepDone, $prepTotal),
        'attendance_pct'        => $pct($attGood, $attTotal),
        'teachback_pct'         => $pct($tbDone, $tbTotal),
        'avg_mastery'           => $mastery,
        'module_completion_pct' => $pct($modDone, $prepTotal),
        'intervention'          => $intervention,
        'scope'                 => $filter,
        'cohorts'               => $c === null ? null : count($c),
    ];
}

/**
 * One row per member of `$dimension` within `$filter`, each carrying the same
 * metric set as `mam_upper_health()` so a manager can compare siblings and
 * descend into the outlier.
 *
 * Rows are sorted by the weakest average mastery first — the point of this
 * screen is to find the problem, so the problem should not be on page two.
 * Members with no mastery data sort last rather than appearing to be the worst.
 */
function mam_upper_breakdown(string $dimension, array $filter = [], int $limit = 100): array {
    if (!isset(MAM_UPPER_DIMENSIONS[$dimension])) throw new InvalidArgumentException('bad_dimension');
    $limit = max(1, min(500, $limit));
    $key   = MAM_UPPER_DIMENSIONS[$dimension]['filter'];

    // Enumerate the members present in this scope, then measure each one.
    $members = [];
    if ($dimension === 'student') {
        $c = mam_upper_scope_cohorts($filter);
        if ($c !== null && !$c) return [];
        $where = $c === null ? '' : ' AND ce.cohort_id IN (' . implode(',', array_fill(0, count($c), '?')) . ')';
        $rows = mam_db_all(
            "SELECT DISTINCT u.id, u.name, u.email FROM course_enrollments ce JOIN users u ON u.id = ce.student_id
              WHERE ce.status IN ('active','completed')" . $where . " ORDER BY u.name LIMIT " . $limit,
            $c === null ? [] : $c
        );
        foreach ($rows as $r) $members[] = ['id' => (int) $r['id'], 'label' => (string) ($r['name'] ?: $r['email'])];
    } else {
        $join = [
            'programme'  => ['programs p ON p.id = co.program_id',  'p.id', 'p.name'],
            'instrument' => ['courses  p ON p.id = co.course_id',   'p.id', 'p.name'],
            'grade'      => ['levels   p ON p.id = co.level_id',    'p.id', 'p.name'],
            'instructor' => ['users    p ON p.id = co.instructor_id', 'p.id', 'p.name'],
        ][$dimension];
        $where = []; $args = [];
        foreach (['program_id', 'course_id', 'level_id', 'instructor_id'] as $k) {
            if (isset($filter[$k])) { $where[] = "co.$k = ?"; $args[] = $filter[$k]; }
        }
        $sql = "SELECT DISTINCT {$join[1]} AS id, {$join[2]} AS label FROM cohorts co JOIN {$join[0]}"
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . " ORDER BY label LIMIT " . $limit;
        foreach (mam_db_all($sql, $args) as $r) {
            $members[] = ['id' => (int) $r['id'], 'label' => (string) ($r['label'] ?: ('#' . $r['id']))];
        }
    }

    $out = [];
    foreach ($members as $m) {
        $scoped = $filter; $scoped[$key] = $m['id'];
        $out[] = array_merge(['id' => $m['id'], 'label' => $m['label'], 'dimension' => $dimension], mam_upper_health($scoped));
    }
    // Weakest first, but "no data" is not weakness — those sort to the end.
    usort($out, function ($a, $b) {
        $am = $a['avg_mastery']; $bm = $b['avg_mastery'];
        if ($am === null && $bm === null) return strcasecmp($a['label'], $b['label']);
        if ($am === null) return 1;
        if ($bm === null) return -1;
        return $am <=> $bm;
    });
    return $out;
}

/**
 * A human breadcrumb for the current filter, so the screen can say what it is
 * showing. Names are resolved from the ids; an id that no longer resolves is
 * shown as such rather than dropped, which would silently widen the scope in
 * the reader's mind.
 */
function mam_upper_breadcrumb(array $filter): array {
    $crumbs = [['label' => 'Academy', 'filter' => []]];
    $sofar = [];
    $lookup = [
        'program_id'    => ['programs', 'name', 'Programme'],
        'course_id'     => ['courses',  'name', 'Instrument'],
        'level_id'      => ['levels',   'name', 'Grade'],
        'instructor_id' => ['users',    'name', 'Instructor'],
        'student_id'    => ['users',    'name', 'Student'],
    ];
    foreach ($lookup as $k => [$table, $col, $label]) {
        if (!isset($filter[$k])) continue;
        $sofar[$k] = $filter[$k];
        $row = mam_db_row("SELECT $col AS n FROM $table WHERE id = ?", [$filter[$k]]);
        $crumbs[] = [
            'label'  => $row ? (string) $row['n'] : ($label . ' #' . $filter[$k] . ' (not found)'),
            'kind'   => $label,
            'filter' => $sofar,
        ];
    }
    return $crumbs;
}

/**
 * The whole §34/§35 payload for one screen: the scoped headline, the
 * breadcrumb, the next drill-down level, and §35's instructor comparison.
 */
function mam_upper_dashboard(array $filter = [], ?string $dimension = null): array {
    $dimension = $dimension && isset(MAM_UPPER_DIMENSIONS[$dimension]) ? $dimension : mam_upper_next_dimension(null);
    return [
        'health'      => mam_upper_health($filter),
        'breadcrumb'  => mam_upper_breadcrumb($filter),
        'dimension'   => $dimension,
        'dimensions'  => MAM_UPPER_DIMENSIONS,
        'breakdown'   => mam_upper_breakdown($dimension, $filter),
        'instructors' => function_exists('mam_analytics2_instructor_performance') ? mam_analytics2_instructor_performance() : [],
        'today'       => function_exists('mam_analytics2_today') ? mam_analytics2_today() : null,
        'generated_at'=> date('Y-m-d H:i:s'),
    ];
}
