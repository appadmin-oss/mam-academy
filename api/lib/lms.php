<?php
/**
 * LMS — flexible course registration on top of the v16 academic model
 * (programs · levels · courses · course_enrollments · instructor_assignments).
 *
 * A student registers once, then adds courses over time from the dashboard.
 * Each add creates a course_enrollment that is either auto-activated or left
 * pending for an admin to approve + assign an instructor (admin policy via the
 * `lms_auto_approve` setting). Multi-instructor and multi-role are inherent:
 * enrollments reference courses, and instructor assignment is per-enrollment.
 */
declare(strict_types=1);

/** Public academic catalogue: active programmes, levels and courses (core first). */
function mam_lms_catalog(): array {
    return [
        'programs' => mam_db_all(
            'SELECT code, name, weeks, revision_week, exam_week, requires_core, certification
               FROM programs WHERE active = 1 ORDER BY sort_order'
        ),
        'levels' => mam_db_all('SELECT code, name, trinity_grades FROM levels ORDER BY sort_order'),
        'courses' => mam_db_all(
            "SELECT code, name, kind, description FROM courses WHERE active = 1
              ORDER BY CASE WHEN kind = 'core' THEN 0 ELSE 1 END, sort_order"
        ),
    ];
}

/** Whether new course enrolments skip the pending queue (admin setting). */
function mam_lms_auto_approve(): bool {
    return (int)mam_setting_num('lms_auto_approve', 0) === 1;
}

/* ─────────────────────────── Enrolment lifecycle ─────────────────────────
   An enrolment moves through an ENFORCED state machine, not free text. Every
   change is appended to enrolment_events (who/when/why) so the lifecycle is
   fully auditable. Legal moves:
       pending   → active | rejected | withdrawn
       active    → completed | failed | withdrawn
       completed | failed | withdrawn | rejected  → (terminal)
   The vocabulary is backwards-compatible with the existing 'pending'/'active'
   data; the terminal states formalise outcomes that were previously implicit
   (a passed/finished course, a drop-out, a declined request). */

/** The legal transition graph (terminal states have an empty allow-list). */
function mam_lms_transitions(): array {
    return [
        'pending'   => ['active', 'rejected', 'withdrawn'],
        'active'    => ['completed', 'failed', 'withdrawn'],
        'completed' => [],
        'failed'    => [],
        'withdrawn' => [],
        'rejected'  => [],
    ];
}

/** Pure predicate: is $from → $to a legal enrolment transition? */
function mam_lms_can_transition(string $from, string $to): bool {
    $g = mam_lms_transitions();
    return isset($g[$from]) && in_array($to, $g[$from], true);
}

/** Append one row to the enrolment audit trail. Never throws (the audit must
 *  not break a write); a missing table on a pre-v18 install is simply skipped. */
function mam_lms_log_event(int $enrollmentId, ?string $from, string $to, ?int $actorId = null, ?string $reason = null): void {
    try {
        mam_db_insert('enrolment_events', [
            'enrollment_id' => $enrollmentId,
            'from_status'   => $from,
            'to_status'     => $to,
            'actor_id'      => $actorId,
            'reason'        => $reason !== null ? mb_substr($reason, 0, 255) : null,
        ]);
    } catch (Throwable $e) { /* audit-only */ }
}

/**
 * Enforced enrolment-status transition. Validates that $to is reachable from
 * the current status, updates course_enrollments (+ status_changed_at, and the
 * legacy approved_* columns when activating), and appends an enrolment_events
 * audit row. Idempotent: a move to the current status is a no-op (no duplicate
 * event). Throws RuntimeException('enrollment_not_found') or
 * RuntimeException('illegal_transition'). Returns
 * ['ok'=>true,'changed'=>bool,'from'=>?string,'to'=>string].
 */
function mam_lms_transition(int $enrollmentId, string $to, ?int $by = null, ?string $reason = null): array {
    $row = mam_db_row('SELECT status FROM course_enrollments WHERE id = ?', [$enrollmentId]);
    if (!$row) throw new RuntimeException('enrollment_not_found');
    $from = (string)$row['status'];
    if ($from === $to) return ['ok' => true, 'changed' => false, 'from' => $from, 'to' => $to];
    if (!mam_lms_can_transition($from, $to)) throw new RuntimeException('illegal_transition');

    $now  = date('Y-m-d H:i:s');
    $data = ['status' => $to, 'status_changed_at' => $now];
    // Keep the legacy approval columns in lockstep when an enrolment activates.
    if ($to === 'active') { $data['approved_at'] = $now; if ($by) $data['approved_by'] = $by; }
    mam_db_update('course_enrollments', $data, ['id' => $enrollmentId]);

    mam_lms_log_event($enrollmentId, $from, $to, $by, $reason);
    mam_audit('lms_transition', 'course_enrollment', $enrollmentId, ['from' => $from, 'to' => $to, 'reason' => $reason]);
    return ['ok' => true, 'changed' => true, 'from' => $from, 'to' => $to];
}

/** The audit trail for an enrolment, oldest first (the lifecycle as it happened). */
function mam_lms_enrolment_events(int $enrollmentId): array {
    return mam_db_all(
        "SELECT ev.id, ev.from_status, ev.to_status, ev.reason, ev.created_at,
                u.name AS actor_name
           FROM enrolment_events ev
           LEFT JOIN users u ON u.id = ev.actor_id
          WHERE ev.enrollment_id = ? ORDER BY ev.id ASC",
        [$enrollmentId]
    );
}

/** Withdraw (cancel / drop) an enrolment: pending|active → withdrawn. */
function mam_lms_withdraw(int $enrollmentId, ?int $by = null, ?string $reason = 'withdrawn'): array {
    return mam_lms_transition($enrollmentId, 'withdrawn', $by, $reason);
}

/**
 * Finalize an active enrolment from its gradebook: compute the result, then
 * move active → completed (met the pass mark) or active → failed. Refuses to
 * finalize an ungraded enrolment (RuntimeException('not_graded')) so a course
 * is never closed as "failed" purely for a lack of marks. Returns
 * ['result'=>…, 'status'=>'completed'|'failed', 'transition'=>…].
 */
function mam_lms_finalize(int $enrollmentId, ?int $by = null): array {
    $res = mam_lms_compute_result($enrollmentId, $by);
    if ($res['total'] === null) throw new RuntimeException('not_graded');
    $to = ($res['outcome'] ?? '') === 'pass' ? 'completed' : 'failed';
    $t  = mam_lms_transition($enrollmentId, $to, $by, 'finalized:' . ($res['outcome'] ?? 'none'));
    return ['result' => $res, 'status' => $to, 'transition' => $t];
}

/** A student's course enrolments, with course/level/programme/instructor names
 *  and any finalized result. Newest first. */
function mam_lms_student_courses(int $studentId): array {
    return mam_db_all(
        "SELECT ce.id, ce.status, ce.created_at, ce.approved_at,
                c.code AS course_code, c.name AS course_name, c.kind AS course_kind,
                l.code AS level_code, l.name AS level_name,
                p.code AS program_code, p.name AS program_name,
                ins.name AS instructor_name,
                r.total_score, r.grade, r.outcome
           FROM course_enrollments ce
           JOIN courses c        ON c.id = ce.course_id
           LEFT JOIN levels l    ON l.id = ce.level_id
           LEFT JOIN programs p  ON p.id = ce.program_id
           LEFT JOIN users ins   ON ins.id = ce.instructor_id
           LEFT JOIN results r   ON r.enrollment_id = ce.id
          WHERE ce.student_id = ?
          ORDER BY ce.created_at DESC",
        [$studentId]
    );
}

/**
 * Add a course for a student. Resolves codes → ids, blocks duplicate
 * active/pending enrolments in the same course+level, and sets the status per
 * the auto-approve policy. Throws InvalidArgumentException('course_not_found')
 * or RuntimeException('already_enrolled'). Returns ['id','status'].
 */
function mam_lms_add_course(int $studentId, string $courseCode, ?string $levelCode = null, ?string $programCode = null, ?int $requestedInstructorId = null): array {
    $course = mam_db_row('SELECT id FROM courses WHERE code = ? AND active = 1', [$courseCode]);
    if (!$course) throw new InvalidArgumentException('course_not_found');

    $levelId = null;
    if ($levelCode) { $r = mam_db_row('SELECT id FROM levels WHERE code = ?', [$levelCode]); if ($r) $levelId = (int)$r['id']; }
    $programId = null;
    if ($programCode) { $r = mam_db_row('SELECT id FROM programs WHERE code = ?', [$programCode]); if ($r) $programId = (int)$r['id']; }

    // Block a duplicate active/pending enrolment in the same course+level.
    // NOTE: compare level_id as a bare column (not wrapped in IFNULL) so the
    // column's INTEGER affinity coerces the bound parameter — PDO binds array
    // params as TEXT under EMULATE_PREPARES=false, and a function-wrapped
    // column loses affinity, making `IFNULL(level_id,0) = '1'` never match.
    if ($levelId === null) {
        $dup = mam_db_row(
            "SELECT id FROM course_enrollments
              WHERE student_id = ? AND course_id = ? AND level_id IS NULL AND status IN ('pending','active')",
            [$studentId, (int)$course['id']]
        );
    } else {
        $dup = mam_db_row(
            "SELECT id FROM course_enrollments
              WHERE student_id = ? AND course_id = ? AND level_id = ? AND status IN ('pending','active')",
            [$studentId, (int)$course['id'], $levelId]
        );
    }
    if ($dup) throw new RuntimeException('already_enrolled');

    $auto = mam_lms_auto_approve();
    $id = mam_db_insert('course_enrollments', [
        'student_id'              => $studentId,
        'course_id'               => (int)$course['id'],
        'level_id'                => $levelId,
        'program_id'              => $programId,
        'requested_instructor_id' => $requestedInstructorId,
        'status'                  => $auto ? 'active' : 'pending',
        'approved_at'             => $auto ? date('Y-m-d H:i:s') : null,
    ]);
    // Birth of the enrolment in the lifecycle audit (from = null = "created").
    mam_lms_log_event($id, null, $auto ? 'active' : 'pending', $auto ? $studentId : null, $auto ? 'auto_approved' : 'requested');
    // Dual-write: link the enrolment to its (course, level, programme) cohort so
    // the cohort becomes the unifying grouping for new enrolments. cohort_id is
    // nullable + currently unread, so this is additive; it must never block an
    // enrolment, hence the soft guard.
    try { mam_db_update('course_enrollments', ['cohort_id' => mam_lms_ensure_cohort((int)$course['id'], $levelId, $programId)], ['id' => $id]); }
    catch (Throwable $e) { /* cohort linking is best-effort */ }
    mam_audit('lms_add_course', 'course_enrollment', $id, ['student' => $studentId, 'course' => $courseCode, 'auto' => $auto]);
    return ['id' => $id, 'status' => $auto ? 'active' : 'pending'];
}

/**
 * Admin: approve a pending enrolment and (optionally) assign an instructor.
 * instructor_assignments acts as the assignment MEMORY: an explicit pick is
 * remembered for the course(+level), and an approval without a pick defaults
 * from it — so the admin stops re-selecting the same instructor every time.
 */
function mam_lms_approve(int $enrollmentId, ?int $instructorId, int $adminId): void {
    $e = mam_db_row('SELECT course_id, level_id FROM course_enrollments WHERE id = ?', [$enrollmentId]);
    if ($e) {
        $courseId = (int)$e['course_id'];
        $levelId  = $e['level_id'] !== null ? (int)$e['level_id'] : null;
        if ($instructorId) mam_lms_remember_instructor($instructorId, $courseId, $levelId);
        else $instructorId = mam_lms_default_instructor($courseId, $levelId);
    }
    // Assign the instructor first, then run the enforced transition pending →
    // active (which stamps approved_by/at and writes the lifecycle event). The
    // transition is a no-op if the enrolment is already active — e.g. an admin
    // re-opening it only to (re)assign an instructor.
    if ($instructorId) mam_db_update('course_enrollments', ['instructor_id' => $instructorId], ['id' => $enrollmentId]);
    mam_lms_transition($enrollmentId, 'active', $adminId, 'approved');
    mam_audit('lms_approve', 'course_enrollment', $enrollmentId, ['instructor' => $instructorId]);
}

/** The remembered instructor for a course (+level): exact course+level match
 *  first, then a level-agnostic assignment. Null when nothing is on file. */
function mam_lms_default_instructor(int $courseId, ?int $levelId): ?int {
    $row = null;
    if ($levelId !== null) {
        $row = mam_db_row('SELECT instructor_id FROM instructor_assignments WHERE course_id = ? AND level_id = ? ORDER BY id DESC LIMIT 1', [$courseId, $levelId]);
    }
    if (!$row) {
        $row = mam_db_row('SELECT instructor_id FROM instructor_assignments WHERE course_id = ? AND level_id IS NULL ORDER BY id DESC LIMIT 1', [$courseId]);
    }
    return $row ? (int)$row['instructor_id'] : null;
}

/** Remember that an instructor teaches a course (+level). Idempotent; failure
 *  is swallowed — the memory must never block an approval. */
function mam_lms_remember_instructor(int $instructorId, int $courseId, ?int $levelId): void {
    try {
        $dup = $levelId !== null
            ? mam_db_row('SELECT id FROM instructor_assignments WHERE instructor_id = ? AND course_id = ? AND level_id = ?', [$instructorId, $courseId, $levelId])
            : mam_db_row('SELECT id FROM instructor_assignments WHERE instructor_id = ? AND course_id = ? AND level_id IS NULL', [$instructorId, $courseId]);
        if (!$dup) mam_db_insert('instructor_assignments', ['instructor_id' => $instructorId, 'course_id' => $courseId, 'level_id' => $levelId]);
    } catch (Throwable $e) { /* memory only */ }
}

/** Everyone who can teach (primary role OR user_roles). For the assign dropdown. */
function mam_lms_instructors(): array {
    return mam_db_all(
        "SELECT id, name, email FROM users
          WHERE status = 'active' AND (role = 'instructor' OR role = 'admin'
                OR id IN (SELECT user_id FROM user_roles WHERE role = 'instructor'))
          ORDER BY name"
    );
}

/* ─────────────────────────────── Cohorts ─────────────────────────────────
   A cohort is the single "scheduled run of a course" that unifies the legacy
   classes/enrollments world (attendance, resources) with the v16
   course_enrollments world (gradebook, results). v19 introduces the table and
   backfills one cohort per legacy class; the read-path cutover follows in a
   later, separately-tested increment (dual-read discipline). */

/** Idempotently create one cohort per legacy `classes` row (keyed by
 *  legacy_class_id). Driver-aware (INSERT OR IGNORE / INSERT IGNORE). Safe to
 *  re-run; returns how many cohorts were created this pass. */
function mam_lms_backfill_cohorts(): int {
    $before = (int)(mam_db_row('SELECT COUNT(*) AS n FROM cohorts')['n'] ?? 0);
    $ignore = mam_db_driver() === 'mysql' ? 'INSERT IGNORE INTO' : 'INSERT OR IGNORE INTO';
    mam_db()->exec(
        $ignore . " cohorts (instructor_id, title, discipline, pathway, mode, capacity, meeting_pattern, starts_on, status, legacy_class_id, created_at)
           SELECT instructor_id, title, discipline, pathway, mode, COALESCE(capacity,12), schedule, starts_on, COALESCE(status,'active'), id, created_at FROM classes"
    );
    $after = (int)(mam_db_row('SELECT COUNT(*) AS n FROM cohorts')['n'] ?? 0);
    $n = max(0, $after - $before);
    if ($n) mam_audit('lms_cohort_backfill', 'cohort', null, ['created' => $n]);
    return $n;
}

/** All cohorts (newest first), with names resolved from the normalized FKs
 *  where set; the carried legacy `title`/`discipline` cover migrated classes. */
function mam_lms_cohorts(): array {
    return mam_db_all(
        "SELECT co.*, c.name AS course_name, l.name AS level_name, p.name AS program_name, u.name AS instructor_name,
                (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.cohort_id = co.id) AS enrollment_count
           FROM cohorts co
           LEFT JOIN courses c  ON c.id = co.course_id
           LEFT JOIN levels l   ON l.id = co.level_id
           LEFT JOIN programs p ON p.id = co.program_id
           LEFT JOIN users u    ON u.id = co.instructor_id
          ORDER BY co.id DESC LIMIT 500"
    );
}

/** Fetch one cohort by id (with resolved names). */
function mam_lms_cohort(int $id): ?array {
    return mam_db_row(
        "SELECT co.*, c.name AS course_name, l.name AS level_name, p.name AS program_name, u.name AS instructor_name,
                (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.cohort_id = co.id) AS enrollment_count
           FROM cohorts co
           LEFT JOIN courses c  ON c.id = co.course_id
           LEFT JOIN levels l   ON l.id = co.level_id
           LEFT JOIN programs p ON p.id = co.program_id
           LEFT JOIN users u    ON u.id = co.instructor_id
          WHERE co.id = ?", [$id]
    );
}

/** Create a cohort. All FKs are optional, so a cohort can be fully normalized
 *  (course/level/programme) or descriptive (legacy-style title only). */
function mam_lms_create_cohort(array $d): int {
    $id = mam_db_insert('cohorts', [
        'course_id'       => isset($d['course_id'])     ? (int)$d['course_id']     : null,
        'level_id'        => isset($d['level_id'])      ? (int)$d['level_id']      : null,
        'program_id'      => isset($d['program_id'])    ? (int)$d['program_id']    : null,
        'instructor_id'   => isset($d['instructor_id']) ? (int)$d['instructor_id'] : null,
        'title'           => isset($d['title']) ? (string)$d['title'] : null,
        'discipline'      => $d['discipline'] ?? null,
        'pathway'         => $d['pathway'] ?? null,
        'mode'            => $d['mode'] ?? null,
        'term'            => $d['term'] ?? null,
        'capacity'        => isset($d['capacity']) ? (int)$d['capacity'] : 12,
        'meeting_pattern' => $d['meeting_pattern'] ?? null,
        'starts_on'       => $d['starts_on'] ?? null,
        'ends_on'         => $d['ends_on'] ?? null,
        'status'          => $d['status'] ?? 'active',
    ]);
    mam_audit('lms_cohort_create', 'cohort', $id, ['title' => $d['title'] ?? null]);
    return $id;
}

/** Link a course-enrolment to a cohort — the dual-write that begins unifying
 *  the two systems. Validates both exist; throws enrollment_not_found /
 *  cohort_not_found. */
function mam_lms_link_enrollment_cohort(int $enrollmentId, int $cohortId): void {
    if (!mam_db_row('SELECT id FROM course_enrollments WHERE id = ?', [$enrollmentId])) throw new RuntimeException('enrollment_not_found');
    if (!mam_db_row('SELECT id FROM cohorts WHERE id = ?', [$cohortId])) throw new RuntimeException('cohort_not_found');
    mam_db_update('course_enrollments', ['cohort_id' => $cohortId], ['id' => $enrollmentId]);
    mam_audit('lms_cohort_link', 'course_enrollment', $enrollmentId, ['cohort' => $cohortId]);
}

/**
 * Find (or create) the open, system-managed cohort for a (course, level,
 * programme) tuple — the default grouping a new enrolment is linked to. System
 * cohorts have legacy_class_id IS NULL (a migrated legacy class is never reused
 * as an auto-target). NULL level/programme are matched as NULL (affinity-safe,
 * like the duplicate guard). Returns the cohort id.
 *
 * Note: find-then-create has a tiny concurrency window; under SQLite's single
 * writer it's a non-issue, and on MySQL a rare duplicate-for-a-tuple is a
 * data-quality nit (dedupe-able later), not a correctness bug.
 */
function mam_lms_ensure_cohort(int $courseId, ?int $levelId, ?int $programId): int {
    $where = 'course_id = ? AND legacy_class_id IS NULL AND status = ?';
    $args  = [$courseId, 'active'];
    if ($levelId === null)   { $where .= ' AND level_id IS NULL'; }   else { $where .= ' AND level_id = ?';   $args[] = $levelId; }
    if ($programId === null) { $where .= ' AND program_id IS NULL'; } else { $where .= ' AND program_id = ?'; $args[] = $programId; }
    $row = mam_db_row("SELECT id FROM cohorts WHERE $where ORDER BY id LIMIT 1", $args);
    if ($row) return (int)$row['id'];

    $c = mam_db_row('SELECT name FROM courses WHERE id = ?', [$courseId]);
    $title = $c ? (string)$c['name'] : ('Course #' . $courseId);
    if ($levelId !== null) { $l = mam_db_row('SELECT name FROM levels WHERE id = ?', [$levelId]); if ($l) $title .= ' — ' . $l['name']; }
    return mam_lms_create_cohort(['course_id' => $courseId, 'level_id' => $levelId, 'program_id' => $programId, 'title' => $title]);
}

/* ───────────────────────── Assessments & results ─────────────────────────
   Each course enrolment is graded across the weighted assessment_types
   (attendance/assignment/practical/continuous/final). The result is a
   weighted average of the per-type score-averages, normalised over whatever
   has been graded so far — so a partial gradebook shows a fair "current
   standing" and a complete one shows the final weighted mark. */

/** Pass mark (percent) — admin-editable. */
function mam_lms_pass_mark(): float { return mam_setting_num('lms_pass_mark', 50); }

/** Map a 0–100 percentage to a [letter, band]. */
function mam_lms_grade(float $pct): array {
    if ($pct >= 80) return ['A', 'distinction'];
    if ($pct >= 70) return ['B', 'merit'];
    if ($pct >= 60) return ['C', 'credit'];
    if ($pct >= 50) return ['D', 'pass'];
    return ['F', 'fail'];
}

/**
 * Recompute and persist an enrolment's result from its assessments.
 * Returns ['total','grade','outcome','band','graded_weight']; total is null
 * when nothing has been graded yet.
 */
function mam_lms_compute_result(int $enrollmentId, ?int $by = null): array {
    $rows = mam_db_all(
        "SELECT t.weight AS weight, AVG(a.score * 100.0 / a.max_score) AS avg_pct
           FROM assessments a JOIN assessment_types t ON t.id = a.type_id
          WHERE a.enrollment_id = ? GROUP BY t.id, t.weight",
        [$enrollmentId]
    );
    $wsum = 0.0; $acc = 0.0;
    foreach ($rows as $r) { $w = (float)$r['weight']; $wsum += $w; $acc += $w * (float)$r['avg_pct']; }
    if ($wsum <= 0) return ['total' => null, 'grade' => null, 'outcome' => null, 'band' => null, 'graded_weight' => 0];

    $total = round($acc / $wsum, 1);
    [$grade, $band] = mam_lms_grade($total);
    $outcome = $total >= mam_lms_pass_mark() ? 'pass' : 'fail';
    $now = date('Y-m-d H:i:s');
    mam_db_upsert(
        'results',
        ['enrollment_id' => $enrollmentId, 'total_score' => $total, 'grade' => $grade, 'outcome' => $outcome, 'finalized_by' => $by, 'finalized_at' => $now],
        ['enrollment_id'],
        ['total_score' => $total, 'grade' => $grade, 'outcome' => $outcome, 'finalized_by' => $by, 'finalized_at' => $now]
    );
    return ['total' => $total, 'grade' => $grade, 'outcome' => $outcome, 'band' => $band, 'graded_weight' => (int)round($wsum)];
}

/** Record one assessment score for an enrolment, then recompute its result.
 *  Throws InvalidArgumentException('type_not_found') on an unknown type code. */
function mam_lms_record_assessment(int $enrollmentId, string $typeCode, ?int $instructorId, float $score, float $maxScore = 100.0, ?int $week = null, ?string $notes = null): array {
    $type = mam_db_row('SELECT id FROM assessment_types WHERE code = ? AND active = 1', [$typeCode]);
    if (!$type) throw new InvalidArgumentException('type_not_found');
    if ($maxScore <= 0) $maxScore = 100.0;
    $score = max(0.0, min($score, $maxScore));
    mam_db_insert('assessments', [
        'enrollment_id' => $enrollmentId,
        'type_id'       => (int)$type['id'],
        'instructor_id' => $instructorId,
        'week'          => $week,
        'score'         => $score,
        'max_score'     => $maxScore,
        'notes'         => $notes,
    ]);
    mam_audit('lms_assess', 'course_enrollment', $enrollmentId, ['type' => $typeCode, 'score' => $score]);
    return mam_lms_compute_result($enrollmentId, $instructorId);
}

/** An instructor's active course enrolments with student/course + current result. */
function mam_lms_instructor_gradebook(int $instructorId): array {
    return mam_db_all(
        "SELECT ce.id, ce.status,
                su.name AS student_name, su.email AS student_email,
                c.name AS course_name, c.kind AS course_kind, l.name AS level_name,
                r.total_score, r.grade, r.outcome
           FROM course_enrollments ce
           JOIN users su       ON su.id = ce.student_id
           JOIN courses c      ON c.id = ce.course_id
           LEFT JOIN levels l  ON l.id = ce.level_id
           LEFT JOIN results r ON r.enrollment_id = ce.id
          WHERE ce.instructor_id = ? AND ce.status = 'active'
          ORDER BY su.name, c.name",
        [$instructorId]
    );
}

/** Every assessment recorded against an enrolment (newest first). */
function mam_lms_enrollment_assessments(int $enrollmentId): array {
    return mam_db_all(
        "SELECT a.id, a.score, a.max_score, a.week, a.notes, a.created_at,
                t.code AS type_code, t.name AS type_name, t.weight
           FROM assessments a LEFT JOIN assessment_types t ON t.id = a.type_id
          WHERE a.enrollment_id = ? ORDER BY a.created_at DESC",
        [$enrollmentId]
    );
}

/* ───────────────────────────── Certificates ──────────────────────────────
   Each programme issues a tier of certificate (programs.certification):
   crash → participation · standard → completion · certified → internal ·
   grand → internal + diploma transcript. Issued from a passed, completed
   enrolment; web-viewable at /api/certificate/:ref like the invoice page. */

/** Human title for a certificate kind. */
function mam_lms_cert_kind_title(string $kind): string {
    switch ($kind) {
        case 'participation':    return 'Certificate of Participation';
        case 'completion':       return 'Certificate of Completion';
        case 'internal':         return 'Internal Certification';
        case 'internal_diploma': return 'Internal Certification & Diploma Transcript';
        default:                 return 'Certificate of Achievement';
    }
}

/**
 * Issue a certificate for (student, programme, level). Idempotent on
 * (student, programme, level, kind) — re-issuing returns the existing one.
 * Returns ['reference','issued'].
 */
function mam_lms_issue_certificate(int $studentId, ?int $programId, ?int $levelId, ?int $issuedBy = null): array {
    $kind = 'completion';
    if ($programId) {
        $p = mam_db_row('SELECT certification FROM programs WHERE id = ?', [$programId]);
        if ($p && !empty($p['certification'])) $kind = (string)$p['certification'];
    }
    // Duplicate guard — compare level_id as a bare column / IS NULL (affinity-safe).
    $where = 'student_id = ? AND kind = ?'; $args = [$studentId, $kind];
    if ($programId !== null) { $where .= ' AND program_id = ?'; $args[] = $programId; } else { $where .= ' AND program_id IS NULL'; }
    if ($levelId   !== null) { $where .= ' AND level_id = ?';   $args[] = $levelId;   } else { $where .= ' AND level_id IS NULL'; }
    $existing = mam_db_row("SELECT reference FROM certificates WHERE $where", $args);
    if ($existing) return ['reference' => (string)$existing['reference'], 'issued' => false];

    $ref = 'CERT-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
    mam_db_insert('certificates', [
        'reference' => $ref, 'student_id' => $studentId, 'program_id' => $programId,
        'level_id' => $levelId, 'kind' => $kind, 'title' => mam_lms_cert_kind_title($kind),
        'issued_by' => $issuedBy,
    ]);
    mam_audit('lms_certify', 'certificate', $studentId, ['ref' => $ref, 'kind' => $kind]);
    return ['reference' => $ref, 'issued' => true];
}

/** Issue the programme certificate for a passed, completed enrolment.
 *  Returns the issue result, or null if the enrolment hasn't passed. */
function mam_lms_issue_for_enrollment(int $enrollmentId, ?int $issuedBy = null): ?array {
    $e = mam_db_row(
        "SELECT ce.student_id, ce.program_id, ce.level_id, r.outcome
           FROM course_enrollments ce LEFT JOIN results r ON r.enrollment_id = ce.id
          WHERE ce.id = ?", [$enrollmentId]
    );
    if (!$e || ($e['outcome'] ?? '') !== 'pass' || $e['program_id'] === null) return null;
    return mam_lms_issue_certificate((int)$e['student_id'], (int)$e['program_id'], $e['level_id'] !== null ? (int)$e['level_id'] : null, $issuedBy);
}

/** A student's issued certificates (with programme/level names), newest first. */
function mam_lms_student_certificates(int $studentId): array {
    return mam_db_all(
        "SELECT c.reference, c.kind, c.title, c.issued_at,
                p.name AS program_name, l.name AS level_name
           FROM certificates c
           LEFT JOIN programs p ON p.id = c.program_id
           LEFT JOIN levels l   ON l.id = c.level_id
          WHERE c.student_id = ? ORDER BY c.issued_at DESC",
        [$studentId]
    );
}

/** Fetch one certificate by reference, joined with names — for the public view. */
function mam_lms_certificate(string $reference): ?array {
    return mam_db_row(
        "SELECT c.*, u.name AS student_name, p.name AS program_name, p.weeks,
                l.name AS level_name, l.trinity_grades
           FROM certificates c
           JOIN users u         ON u.id = c.student_id
           LEFT JOIN programs p ON p.id = c.program_id
           LEFT JOIN levels l   ON l.id = c.level_id
          WHERE c.reference = ?",
        [$reference]
    );
}

/* ──────────────────────── Modules / weekly content ───────────────────────
   A programme's shape is data (programs.weeks / revision_week / exam_week), so
   a 12-week course runs wk 1–10 modules · wk 11 revision · wk 12 exam, while a
   6-week crash runs wk 1–5 modules · wk 6 exam — no code change. Modules are
   authored per course+level; the outline frames them with the programme plan. */

/** Canonical week plan for a programme: [['week'=>1,'kind'=>'module'], …].
 *  Defaults to a 12-week shape when no programme is given. */
function mam_lms_program_week_plan(?int $programId): array {
    $weeks = 12; $rev = 11; $exam = 12;
    if ($programId) {
        $p = mam_db_row('SELECT weeks, revision_week, exam_week FROM programs WHERE id = ?', [$programId]);
        if ($p) {
            $weeks = max(1, (int)$p['weeks']);
            $rev   = $p['revision_week'] !== null ? (int)$p['revision_week'] : null;
            $exam  = $p['exam_week'] !== null ? (int)$p['exam_week'] : $weeks;
        }
    }
    $plan = [];
    for ($w = 1; $w <= $weeks; $w++) {
        $kind = 'module';
        if ($w === $exam) $kind = 'exam';
        elseif ($rev !== null && $w === $rev) $kind = 'revision';
        $plan[] = ['week' => $w, 'kind' => $kind];
    }
    return $plan;
}

/** Authored module rows for a course at a level (level_id NULL = level-agnostic). */
function mam_lms_course_modules(int $courseId, ?int $levelId): array {
    if ($levelId !== null) {
        return mam_db_all('SELECT id, week, title, kind, content FROM modules WHERE course_id = ? AND level_id = ? ORDER BY week', [$courseId, $levelId]);
    }
    return mam_db_all('SELECT id, week, title, kind, content FROM modules WHERE course_id = ? AND level_id IS NULL ORDER BY week', [$courseId]);
}

/** Full week-by-week outline for a course (at a level, within a programme):
 *  the programme plan merged with any authored module title/content. */
function mam_lms_course_outline(string $courseCode, ?string $levelCode = null, ?string $programCode = null): ?array {
    $course = mam_db_row('SELECT id, code, name FROM courses WHERE code = ? AND active = 1', [$courseCode]);
    if (!$course) return null;

    $level = $levelCode ? mam_db_row('SELECT id, name, trinity_grades FROM levels WHERE code = ?', [$levelCode]) : null;
    $levelId = $level ? (int)$level['id'] : null;
    $program = $programCode ? mam_db_row('SELECT id, name, weeks FROM programs WHERE code = ?', [$programCode]) : null;
    $programId = $program ? (int)$program['id'] : null;

    $authored = [];
    foreach (mam_lms_course_modules((int)$course['id'], $levelId) as $m) $authored[(int)$m['week']] = $m;

    $plan = mam_lms_program_week_plan($programId);
    $weeks = [];
    foreach ($plan as $p) {
        $a = $authored[$p['week']] ?? null;
        $weeks[] = [
            'week'     => $p['week'],
            'kind'     => $a['kind'] ?? $p['kind'],
            'title'    => $a['title'] ?? null,
            'content'  => $a['content'] ?? null,
            'authored' => $a !== null,
            'id'       => $a !== null ? (int)$a['id'] : null,
        ];
        unset($authored[$p['week']]);
    }
    // Defensive: surface any authored weeks beyond the plan length.
    foreach ($authored as $w => $a) {
        $weeks[] = ['week' => (int)$w, 'kind' => $a['kind'], 'title' => $a['title'], 'content' => $a['content'], 'authored' => true, 'id' => (int)$a['id']];
    }
    usort($weeks, fn ($x, $y) => $x['week'] <=> $y['week']);

    return [
        'course'  => ['code' => $course['code'], 'name' => $course['name']],
        'level'   => $level ? ['code' => $levelCode, 'name' => $level['name'], 'trinity_grades' => $level['trinity_grades']] : null,
        'program' => $program ? ['code' => $programCode, 'name' => $program['name'], 'weeks' => (int)$program['weeks']] : null,
        'weeks'   => $weeks,
    ];
}

/** Create or update a module row (admin authoring). Returns the row id. */
function mam_lms_save_module(?int $id, int $courseId, ?int $levelId, int $week, string $kind, string $title, ?string $content): int {
    if (!in_array($kind, ['module', 'revision', 'exam'], true)) $kind = 'module';
    $data = ['course_id' => $courseId, 'level_id' => $levelId, 'week' => max(1, $week), 'kind' => $kind, 'title' => $title, 'content' => $content];
    if ($id) { mam_db_update('modules', $data, ['id' => $id]); return $id; }
    $newId = mam_db_insert('modules', $data);
    mam_audit('lms_module_save', 'module', $newId, ['course' => $courseId, 'week' => $week]);
    return $newId;
}

function mam_lms_delete_module(int $id): void {
    mam_db()->prepare('DELETE FROM modules WHERE id = ?')->execute([$id]);
    mam_audit('lms_module_delete', 'module', $id, []);
}

/** Create the missing week rows for a course+level per the programme plan.
 *  Returns how many were created. Lets an admin scaffold a syllabus in a click. */
function mam_lms_scaffold_modules(int $courseId, ?int $levelId, ?int $programId): int {
    $have = [];
    foreach (mam_lms_course_modules($courseId, $levelId) as $m) $have[(int)$m['week']] = true;
    $n = 0;
    foreach (mam_lms_program_week_plan($programId) as $p) {
        if (isset($have[$p['week']])) continue;
        $title = $p['kind'] === 'exam' ? 'Final Examination' : ($p['kind'] === 'revision' ? 'Revision' : 'Week ' . $p['week']);
        mam_db_insert('modules', ['course_id' => $courseId, 'level_id' => $levelId, 'week' => $p['week'], 'kind' => $p['kind'], 'title' => $title, 'content' => null]);
        $n++;
    }
    if ($n) mam_audit('lms_module_scaffold', 'course', $courseId, ['created' => $n]);
    return $n;
}

/* ───────────────────────────── Progression ───────────────────────────────
   Passing a course's examination advances the student to the SAME course at
   the next level (Pre-Certification → Certificate → Pre-Diploma → Diploma).
   Admin policy: lms_auto_promote (default off → admin advances manually). */

/** Whether a passed course auto-advances to the next level on completion. */
function mam_lms_auto_promote(): bool { return (int)mam_setting_num('lms_auto_promote', 0) === 1; }

/** The level after $code in the progression, or null at the top (Diploma). */
function mam_lms_next_level(string $code): ?array {
    $order = ['precert', 'cert', 'prediploma', 'diploma'];
    $i = array_search($code, $order, true);
    if ($i === false || $i + 1 >= count($order)) return null;
    return mam_db_row('SELECT id, code, name, trinity_grades FROM levels WHERE code = ?', [$order[$i + 1]]);
}

/**
 * Advance a passed enrolment to the same course at the next level. Idempotent
 * (won't duplicate an existing pending/active enrolment at the next level), and
 * a no-op for un-passed enrolments or those already at Diploma. New status
 * follows the add-course policy (lms_auto_approve). Returns
 * ['enrollment_id','level','created'] or null when not eligible.
 */
function mam_lms_promote_enrollment(int $enrollmentId, ?int $by = null): ?array {
    $e = mam_db_row(
        "SELECT ce.student_id, ce.course_id, ce.program_id, l.code AS level_code, r.outcome
           FROM course_enrollments ce
           LEFT JOIN levels l  ON l.id = ce.level_id
           LEFT JOIN results r ON r.enrollment_id = ce.id
          WHERE ce.id = ?", [$enrollmentId]
    );
    if (!$e || ($e['outcome'] ?? '') !== 'pass' || empty($e['level_code'])) return null;
    $next = mam_lms_next_level((string)$e['level_code']);
    if (!$next) return null;

    $dup = mam_db_row(
        "SELECT id FROM course_enrollments
          WHERE student_id = ? AND course_id = ? AND level_id = ? AND status IN ('pending','active')",
        [(int)$e['student_id'], (int)$e['course_id'], (int)$next['id']]
    );
    if ($dup) return ['enrollment_id' => (int)$dup['id'], 'level' => (string)$next['code'], 'created' => false];

    $auto = mam_lms_auto_approve();
    $id = mam_db_insert('course_enrollments', [
        'student_id'  => (int)$e['student_id'],
        'course_id'   => (int)$e['course_id'],
        'level_id'    => (int)$next['id'],
        'program_id'  => $e['program_id'] !== null ? (int)$e['program_id'] : null,
        'status'      => $auto ? 'active' : 'pending',
        'approved_by' => $auto ? $by : null,
        'approved_at' => $auto ? date('Y-m-d H:i:s') : null,
    ]);
    mam_lms_log_event($id, null, $auto ? 'active' : 'pending', $by, 'promoted_from:' . $enrollmentId);
    // Dual-write: link the promoted enrolment to its next-level cohort (best-effort).
    try { mam_db_update('course_enrollments', ['cohort_id' => mam_lms_ensure_cohort((int)$e['course_id'], (int)$next['id'], $e['program_id'] !== null ? (int)$e['program_id'] : null)], ['id' => $id]); }
    catch (Throwable $e2) { /* best-effort */ }
    mam_audit('lms_promote', 'course_enrollment', $id, ['from' => $enrollmentId, 'to_level' => $next['code']]);
    return ['enrollment_id' => $id, 'level' => (string)$next['code'], 'created' => true];
}
