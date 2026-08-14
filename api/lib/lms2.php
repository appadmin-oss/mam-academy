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
function mam_lms2_rooms(bool $activeOnly = true): array {
    return mam_db_all('SELECT * FROM rooms' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name');
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
