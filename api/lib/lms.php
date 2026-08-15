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

/** Normalise a "who did this" id for the audit trail. `enrolment_events.actor_id`
 *  carries a users(id) FK, so a 0 / negative "no actor" sentinel would violate it
 *  and cost us the whole audit row — NULL is the correct way to say "system". */
function mam_lms_actor_id(?int $actorId): ?int {
    return ($actorId !== null && $actorId > 0) ? $actorId : null;
}

/** Append one row to the enrolment audit trail. Never throws (the audit must
 *  not break a write); a missing table on a pre-v18 install is simply skipped.
 *  A failure is logged rather than swallowed in silence — an audit row that
 *  vanishes without a trace is indistinguishable from one that was never due. */
function mam_lms_log_event(int $enrollmentId, ?string $from, string $to, ?int $actorId = null, ?string $reason = null): void {
    try {
        mam_db_insert('enrolment_events', [
            'enrollment_id' => $enrollmentId,
            'from_status'   => $from,
            'to_status'     => $to,
            'actor_id'      => mam_lms_actor_id($actorId),
            'reason'        => $reason !== null ? mb_substr($reason, 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        if (function_exists('mam_log')) {
            mam_log('warn', 'enrolment_event_failed', [
                'enrollment' => $enrollmentId, 'to' => $to, 'err' => $e->getMessage(),
            ]);
        }
    }
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

    $now   = date('Y-m-d H:i:s');
    $actor = mam_lms_actor_id($by);   // 0 == "system", and 0 is not a valid users(id)
    $data  = ['status' => $to, 'status_changed_at' => $now];
    // Keep the legacy approval columns in lockstep when an enrolment activates.
    if ($to === 'active') { $data['approved_at'] = $now; if ($actor !== null) $data['approved_by'] = $actor; }
    mam_db_update('course_enrollments', $data, ['id' => $enrollmentId]);

    mam_lms_log_event($enrollmentId, $from, $to, $actor, $reason);
    mam_audit('lms_transition', 'course_enrollment', $enrollmentId, ['from' => $from, 'to' => $to, 'reason' => $reason]);

    // Automation #9: issue ID card when enrolment activates.
    if ($to === 'active' && mam_automation_on('id_card_issue')) {
        mam_card_mint_for_enrollment($enrollmentId);
    }

    // Leaving a seat-occupying status frees capacity — drain the cohort's queue.
    // Capacity is enforced on enrol now, so without this a withdrawal would
    // leave an empty seat with people queued behind it until an admin noticed.
    if (!in_array($to, mam_lms_seat_statuses(), true)) {
        mam_lms_drain_waitlist_for_enrollment($enrollmentId, $by);
    }

    return ['ok' => true, 'changed' => true, 'from' => $from, 'to' => $to];
}

/**
 * A seat in this enrolment's cohort just came free — promote the next waiting
 * student into it. Governed by the `waitlist_autopromote` automation so an
 * academy that prefers to place people by hand can turn it off.
 *
 * Strictly best-effort: it is called from inside mam_lms_transition(), so it must
 * never throw (promote signals cohort_full / no_course_mapping /
 * enrolled_elsewhere by throwing) and never turn a successful withdrawal into an
 * error. Promotes at most one student — exactly the one seat that was vacated.
 */
function mam_lms_drain_waitlist_for_enrollment(int $enrollmentId, ?int $actorId = null): void {
    try {
        if (function_exists('mam_automation_on') && !mam_automation_on('waitlist_autopromote')) return;
        $row = mam_db_row('SELECT cohort_id FROM course_enrollments WHERE id = ?', [$enrollmentId]);
        $cohortId = (int)($row['cohort_id'] ?? 0);
        if ($cohortId <= 0) return;
        // Only if a seat is genuinely available and somebody is actually waiting.
        if (mam_lms_cohort_fill_count($cohortId)['available'] < 1) return;
        $promoted = mam_lms_waitlist_promote($cohortId, $actorId, false);
        if ($promoted !== null) {
            if (function_exists('mam_automation_touch')) mam_automation_touch('waitlist_autopromote');
            mam_audit('lms_waitlist_autopromote', 'cohort', $cohortId, ['student' => $promoted, 'freed_by' => $enrollmentId]);
        }
    } catch (Throwable $e) {
        // cohort_full / no_course_mapping / enrolled_elsewhere / anything else:
        // the seat simply stays open for an admin to place manually.
        if (function_exists('mam_log')) {
            mam_log('info', 'waitlist_autopromote_skip', ['enrollment' => $enrollmentId, 'reason' => $e->getMessage()]);
        }
    }
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
    // Automation spine (L3): record the event, and on a pass — if auto-promotion
    // is enabled — enqueue the progression job instead of doing it inline.
    if (function_exists('mam_event')) mam_event('enrolment.finalized', 'course_enrollment', $enrollmentId, ['outcome' => $res['outcome'] ?? null, 'to' => $to]);
    if ($to === 'completed' && mam_lms_auto_promote() && function_exists('mam_job_enqueue')) {
        mam_job_enqueue('lms_auto_progress', ['enrollment_id' => $enrollmentId]);
    }
    // Notify the student their result is in (best-effort, never-throw).
    if (function_exists('mam_notify')) {
        $ce = mam_db_row('SELECT ce.student_id, c.name AS course_name FROM course_enrollments ce JOIN courses c ON c.id = ce.course_id WHERE ce.id = ?', [$enrollmentId]);
        if ($ce) {
            $pass  = $to === 'completed';
            $title = $pass ? 'Course completed 🎉' : 'Your course result is published';
            $body  = trim((string)($ce['course_name'] ?? '') . ($pass ? ' — congratulations on passing!' : ' — see your gradebook for details.'));
            mam_notify((int)$ce['student_id'], 'enrolment.' . $to, $title, $body !== '' ? $body : null, '/portal/student/');
        }
    }
    return ['result' => $res, 'status' => $to, 'transition' => $t];
}

/** A student's course enrolments, with course/level/programme/instructor names
 *  and any finalized result. Newest first. */
/**
 * Announcements visible to a student — from any LEGACY class OR cohort they're
 * enrolled in (dual-read, P2). `class_title` carries the class OR cohort name so
 * the portal renders unchanged. Newest first, capped. The compound query aliases
 * `created_at` explicitly: a UNION's ORDER BY must reference a result-set column
 * name (SQLite rejects a bare `an.created_at` here).
 */
function mam_student_announcements(int $studentId, int $limit = 20): array {
    $limit = max(1, min(100, $limit));
    return mam_db_all(
        "SELECT an.id AS id, an.title, an.body, an.created_at AS created_at, c.title AS class_title, usr.name AS author
           FROM announcements an
           JOIN classes c ON c.id = an.class_id
           JOIN enrollments e ON e.class_id = c.id
           LEFT JOIN users usr ON usr.id = an.author_id
          WHERE e.student_id = ? AND e.status IN ('active','paused')
         UNION
         SELECT an.id AS id, an.title, an.body, an.created_at AS created_at, coh.title AS class_title, usr.name AS author
           FROM announcements an
           JOIN cohorts coh ON coh.id = an.cohort_id
           JOIN course_enrollments ce ON ce.cohort_id = coh.id
           LEFT JOIN users usr ON usr.id = an.author_id
          WHERE ce.student_id = ? AND ce.status IN ('active','completed')
          ORDER BY created_at DESC, id DESC LIMIT " . $limit,
        [$studentId, $studentId]
    );
}

/**
 * Learning resources visible to a student — from any LEGACY class OR cohort they
 * are enrolled in (dual-read, P2). `class_title` carries the class OR cohort name.
 * Same compound-query rules as mam_student_announcements(): alias the ORDER BY
 * columns explicitly and tiebreak on id.
 */
function mam_student_resources(int $studentId, int $limit = 50): array {
    $limit = max(1, min(200, $limit));
    return mam_db_all(
        "SELECT r.id AS id, r.title, r.url, r.type, r.created_at AS created_at, c.title AS class_title
           FROM class_resources r
           JOIN classes c ON c.id = r.class_id
           JOIN enrollments e ON e.class_id = c.id
          WHERE e.student_id = ? AND e.status IN ('active','paused')
         UNION
         SELECT r.id AS id, r.title, r.url, r.type, r.created_at AS created_at, coh.title AS class_title
           FROM class_resources r
           JOIN cohorts coh ON coh.id = r.cohort_id
           JOIN course_enrollments ce ON ce.cohort_id = coh.id
          WHERE ce.student_id = ? AND ce.status IN ('active','completed')
          ORDER BY created_at DESC, id DESC LIMIT " . $limit,
        [$studentId, $studentId]
    );
}

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
 * Find a student's LIVE (pending|active) enrolment in a course, deterministically.
 *
 * This is the canonical resolver for the "already_enrolled → find the row I was
 * going to create" recovery that payment settlement and the admin enrol paths
 * perform. Those used to run an unordered, level-blind `LIMIT 1`, which could
 * return (and then activate) the enrolment for a DIFFERENT level than the one
 * being paid for. Resolution order:
 *   - a level was asked for → that exact level, else a level-less enrolment in
 *     the same course (which is what a level-less add would have created);
 *     never another level's row.
 *   - no level asked for → the course's live enrolment, preferring the
 *     level-less row, newest first.
 * Returns the full row, or null when there is nothing live to recover.
 */
function mam_lms_find_enrollment(int $studentId, string $courseCode, ?string $levelCode = null): ?array {
    $course = mam_db_row('SELECT id FROM courses WHERE code = ?', [$courseCode]);
    if (!$course) return null;
    $courseId = (int)$course['id'];

    $levelId = null;
    if ($levelCode !== null && $levelCode !== '') {
        $l = mam_db_row('SELECT id FROM levels WHERE code = ?', [$levelCode]);
        if (!$l) return null;               // an unknown level can never match
        $levelId = (int)$l['id'];
    }

    if ($levelId !== null) {
        $row = mam_db_row(
            "SELECT * FROM course_enrollments
              WHERE student_id = ? AND course_id = ? AND level_id = ? AND status IN ('pending','active')
              ORDER BY id DESC LIMIT 1",
            [$studentId, $courseId, $levelId]
        );
        if ($row) return $row;
        return mam_db_row(
            "SELECT * FROM course_enrollments
              WHERE student_id = ? AND course_id = ? AND level_id IS NULL AND status IN ('pending','active')
              ORDER BY id DESC LIMIT 1",
            [$studentId, $courseId]
        ) ?: null;
    }

    return mam_db_row(
        "SELECT * FROM course_enrollments
          WHERE student_id = ? AND course_id = ? AND status IN ('pending','active')
          ORDER BY (level_id IS NULL) DESC, id DESC LIMIT 1",
        [$studentId, $courseId]
    ) ?: null;
}

/**
 * Add a course for a student. Resolves codes → ids, blocks a duplicate live
 * enrolment in the same course, respects the target cohort's capacity, and sets
 * the status per the auto-approve policy.
 *
 * Capacity: the (course, level, programme) cohort is resolved BEFORE anything is
 * written, so a full cohort diverts the student onto its waitlist instead of
 * over-filling it — self-service enrolment used to ignore capacity entirely,
 * which made the waitlist unreachable for students.
 *
 * Throws InvalidArgumentException('course_not_found') or
 * RuntimeException('already_enrolled'). Returns
 *   ['id'=>int,  'status'=>'pending'|'active', 'cohort_id'=>?int]           enrolled
 *   ['id'=>null, 'status'=>'waitlisted', 'position'=>int, 'cohort_id'=>int] diverted
 */
function mam_lms_add_course(int $studentId, string $courseCode, ?string $levelCode = null, ?string $programCode = null, ?int $requestedInstructorId = null): array {
    $course = mam_db_row('SELECT id FROM courses WHERE code = ? AND active = 1', [$courseCode]);
    if (!$course) throw new InvalidArgumentException('course_not_found');
    $courseId = (int)$course['id'];

    $levelId = null;
    if ($levelCode) { $r = mam_db_row('SELECT id FROM levels WHERE code = ?', [$levelCode]); if ($r) $levelId = (int)$r['id']; }
    $programId = null;
    if ($programCode) { $r = mam_db_row('SELECT id FROM programs WHERE code = ?', [$programCode]); if ($r) $programId = (int)$r['id']; }

    // Block a duplicate live enrolment in the same course. The guard is
    // level-aware in BOTH directions: a level-less add must not slip past an
    // existing levelled enrolment (the admin "assign course" and class-enrol
    // paths pass no level, and used to create a second, parallel enrolment),
    // and a levelled add must not slip past an existing level-less one.
    // Distinct levels of the same course remain legal — that is progression.
    // NOTE: compare level_id as a bare column (not wrapped in IFNULL) so the
    // column's INTEGER affinity coerces the bound parameter — PDO binds array
    // params as TEXT under EMULATE_PREPARES=false, and a function-wrapped
    // column loses affinity, making `IFNULL(level_id,0) = '1'` never match.
    if ($levelId === null) {
        $dup = mam_db_row(
            "SELECT id FROM course_enrollments
              WHERE student_id = ? AND course_id = ? AND status IN ('pending','active')",
            [$studentId, $courseId]
        );
    } else {
        $dup = mam_db_row(
            "SELECT id FROM course_enrollments
              WHERE student_id = ? AND course_id = ? AND status IN ('pending','active')
                AND (level_id IS NULL OR level_id = ?)",
            [$studentId, $courseId, $levelId]
        );
    }
    if ($dup) throw new RuntimeException('already_enrolled');

    // Resolve the target cohort up front so capacity can gate the write. Cohort
    // resolution stays best-effort: it must never block an enrolment, so a
    // failure here simply means "no capacity opinion" and the enrolment proceeds.
    $cohortId = null;
    try { $cohortId = mam_lms_ensure_cohort($courseId, $levelId, $programId); }
    catch (Throwable $e) { /* cohort linking is best-effort */ }

    if ($cohortId !== null) {
        $full = false;
        try { $full = mam_lms_cohort_fill_count($cohortId)['available'] < 1; }
        catch (Throwable $e) { /* no capacity opinion → enrol */ }
        if ($full) {
            $position = mam_lms_waitlist_add($cohortId, $studentId);
            mam_audit('lms_add_course_waitlisted', 'cohort', $cohortId, [
                'student' => $studentId, 'course' => $courseCode, 'position' => $position,
            ]);
            return ['id' => null, 'status' => 'waitlisted', 'position' => $position, 'cohort_id' => $cohortId];
        }
    }

    $auto = mam_lms_auto_approve();
    $id = mam_db_insert('course_enrollments', [
        'student_id'              => $studentId,
        'course_id'               => $courseId,
        'level_id'                => $levelId,
        'program_id'              => $programId,
        'requested_instructor_id' => $requestedInstructorId,
        'cohort_id'               => $cohortId,
        'status'                  => $auto ? 'active' : 'pending',
        'approved_at'             => $auto ? date('Y-m-d H:i:s') : null,
    ]);
    // Birth of the enrolment in the lifecycle audit (from = null = "created").
    mam_lms_log_event($id, null, $auto ? 'active' : 'pending', $auto ? $studentId : null, $auto ? 'auto_approved' : 'requested');
    mam_audit('lms_add_course', 'course_enrollment', $id, ['student' => $studentId, 'course' => $courseCode, 'auto' => $auto]);
    return ['id' => $id, 'status' => $auto ? 'active' : 'pending', 'cohort_id' => $cohortId];
}

/**
 * Admin: approve a pending enrolment and (optionally) assign an instructor.
 * instructor_assignments acts as the assignment MEMORY: an explicit pick is
 * remembered for the course(+level), and an approval without a pick defaults
 * from it — so the admin stops re-selecting the same instructor every time.
 */
function mam_lms_approve(int $enrollmentId, ?int $instructorId, ?int $adminId = null): void {
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
    // Welcome & orientation — governed by the 'enrol_welcome' automation
    // (admin ?v=automations). Default on; best-effort so a mail hiccup never
    // blocks the approval.
    if (!function_exists('mam_automation_on') || mam_automation_on('enrol_welcome')) {
        try {
            $row = mam_db_row(
                "SELECT u.name, u.email, c.name AS course_name
                   FROM course_enrollments ce
                   JOIN users u ON u.id = ce.student_id
                   LEFT JOIN courses c ON c.id = ce.course_id
                  WHERE ce.id = ?",
                [$enrollmentId]
            );
            if ($row && !empty($row['email']) && function_exists('mam_send') && function_exists('mam_email_enrolled')) {
                if (function_exists('mam_automation_touch')) mam_automation_touch('enrol_welcome');
                $loginUrl = rtrim((string)mam_env('APP_URL', ''), '/') . '/portal/login';
                mam_send((string)$row['email'], 'Welcome to your MAM Academy course',
                    mam_email_enrolled((string)($row['name'] ?? ''), (string)($row['course_name'] ?? 'your course'), '', $loginUrl),
                    'enrol_welcome', true);
            }
        } catch (\Throwable $e) { mam_log('warn', 'enrol_welcome_failed', ['enrollment' => $enrollmentId, 'err' => $e->getMessage()]); }
    }
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
        // array_key_exists, not isset: an EXPLICIT null means "no cap" (read as
        // unbounded by mam_lms_cohort_fill_count), which isset() would have
        // silently turned into the default 12.
        'capacity'        => array_key_exists('capacity', $d)
                                ? ($d['capacity'] === null ? null : (int)$d['capacity'])
                                : 12,
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

/* ───────────────────────── Sessions / scheduling (L2) ────────────────────
   A class_session is a scheduled meeting of a cohort — the first-class
   scheduling primitive the cohort grouping was built to carry. (Named
   class_sessions because the auth-session table already owns `sessions`.)
   Attendance re-homing onto sessions + the instructor scheduling UI follow in
   later, separately-tested increments. */

/** Legal session-status moves: scheduled → held|cancelled; both terminal. */
function mam_lms_session_transitions(): array {
    return ['scheduled' => ['held', 'cancelled'], 'held' => [], 'cancelled' => []];
}
function mam_lms_session_can_transition(string $from, string $to): bool {
    $g = mam_lms_session_transitions();
    return isset($g[$from]) && in_array($to, $g[$from], true);
}

/** Schedule a session for a cohort. Validates the cohort exists. Returns the id. */
function mam_lms_create_session(int $cohortId, array $d): int {
    if (!mam_db_row('SELECT id FROM cohorts WHERE id = ?', [$cohortId])) throw new RuntimeException('cohort_not_found');
    $kind = (string)($d['kind'] ?? 'class');
    if (!in_array($kind, ['class', 'exam', 'revision', 'makeup'], true)) $kind = 'class';
    $id = mam_db_insert('class_sessions', [
        'cohort_id'    => $cohortId,
        'title'        => isset($d['title']) ? (string)$d['title'] : null,
        'scheduled_at' => $d['scheduled_at'] ?? null,
        'duration_min' => isset($d['duration_min']) ? max(1, (int)$d['duration_min']) : 60,
        'kind'         => $kind,
        'location'     => $d['location'] ?? null,
        'room'         => $d['room'] ?? null,
        'status'       => 'scheduled',
        'notes'        => $d['notes'] ?? null,
        'created_by'   => isset($d['created_by']) ? (int)$d['created_by'] : null,
    ]);
    mam_audit('lms_session_create', 'class_session', $id, ['cohort' => $cohortId, 'kind' => $kind]);
    return $id;
}

/** Sessions for a cohort, soonest-first (undated last). */
function mam_lms_cohort_sessions(int $cohortId): array {
    return mam_db_all(
        "SELECT cs.*, co.title AS cohort_title
           FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id
          WHERE cs.cohort_id = ?
          ORDER BY (cs.scheduled_at IS NULL), cs.scheduled_at ASC, cs.id ASC",
        [$cohortId]
    );
}

/** One session, with its cohort title. */
function mam_lms_session(int $id): ?array {
    return mam_db_row(
        "SELECT cs.*, co.title AS cohort_title
           FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id WHERE cs.id = ?",
        [$id]
    );
}

/** Move a session scheduled → held|cancelled (enforced). Idempotent on the
 *  current status. Throws session_not_found / illegal_transition. */
function mam_lms_set_session_status(int $id, string $to, ?int $by = null): array {
    $row = mam_db_row('SELECT status FROM class_sessions WHERE id = ?', [$id]);
    if (!$row) throw new RuntimeException('session_not_found');
    $from = (string)$row['status'];
    if ($from === $to) return ['ok' => true, 'changed' => false, 'from' => $from, 'to' => $to];
    if (!mam_lms_session_can_transition($from, $to)) throw new RuntimeException('illegal_transition');
    mam_db_update('class_sessions', ['status' => $to], ['id' => $id]);
    mam_audit('lms_session_status', 'class_session', $id, ['from' => $from, 'to' => $to, 'by' => $by]);
    return ['ok' => true, 'changed' => true, 'from' => $from, 'to' => $to];
}

/** Mark (or re-mark) a student's attendance for a class session. Upserts one
 *  record per (session, student). Validates the session + status. Returns the
 *  row id. Throws session_not_found / invalid_status. */
function mam_lms_mark_attendance(int $sessionId, int $studentId, string $status, ?int $markedBy = null, ?string $notes = null): int {
    $sess = mam_db_row('SELECT id, cohort_id FROM class_sessions WHERE id = ?', [$sessionId]);
    if (!$sess) throw new RuntimeException('session_not_found');
    if (!in_array($status, ['present', 'absent', 'late', 'excused'], true)) throw new RuntimeException('invalid_status');
    // The student must actually be on this session's cohort roster. Without this,
    // an instructor who owns one cohort could POST an arbitrary student_id and
    // fabricate/overwrite attendance for a student in a different cohort.
    $onRoster = mam_db_row(
        "SELECT 1 AS x FROM course_enrollments
          WHERE cohort_id = ? AND student_id = ? AND status IN ('active','completed') LIMIT 1",
        [(int)$sess['cohort_id'], $studentId]
    );
    if (!$onRoster) throw new RuntimeException('not_on_roster');
    $now = date('Y-m-d H:i:s');
    $existing = mam_db_row('SELECT id FROM session_attendance WHERE session_id = ? AND student_id = ?', [$sessionId, $studentId]);
    if ($existing) {
        mam_db_update('session_attendance', ['status' => $status, 'notes' => $notes, 'marked_by' => $markedBy, 'updated_at' => $now], ['id' => (int)$existing['id']]);
        $id = (int)$existing['id'];
    } else {
        $id = mam_db_insert('session_attendance', ['session_id' => $sessionId, 'student_id' => $studentId, 'status' => $status, 'notes' => $notes, 'marked_by' => $markedBy]);
    }
    mam_audit('lms_attendance_mark', 'class_session', $sessionId, ['student' => $studentId, 'status' => $status]);
    return $id;
}

/** Attendance roster for a session (with student names) — the instructor mark sheet. */
function mam_lms_session_attendance(int $sessionId): array {
    return mam_db_all(
        "SELECT sa.id, sa.student_id, sa.status, sa.notes, sa.updated_at,
                u.name AS student_name, u.email AS student_email
           FROM session_attendance sa JOIN users u ON u.id = sa.student_id
          WHERE sa.session_id = ? ORDER BY u.name",
        [$sessionId]
    );
}

/** Present/absent/late/excused counts for a session. */
function mam_lms_session_attendance_summary(int $sessionId): array {
    $out = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
    foreach (mam_db_all('SELECT status, COUNT(*) AS n FROM session_attendance WHERE session_id = ? GROUP BY status', [$sessionId]) as $r) {
        if (isset($out[(string)$r['status']])) $out[(string)$r['status']] = (int)$r['n'];
    }
    return $out;
}

/** A student's session-based attendance (newest session first), optionally
 *  scoped to one cohort — the dual-read source that will replace the legacy
 *  class-attendance view in the portal. */
function mam_lms_student_session_attendance(int $studentId, ?int $cohortId = null): array {
    $sql = "SELECT sa.status, sa.notes, cs.title AS session_title, cs.scheduled_at, cs.kind,
                   co.id AS cohort_id, co.title AS cohort_title
              FROM session_attendance sa
              JOIN class_sessions cs ON cs.id = sa.session_id
              JOIN cohorts co        ON co.id = cs.cohort_id
             WHERE sa.student_id = ?";
    $args = [$studentId];
    if ($cohortId !== null) { $sql .= ' AND co.id = ?'; $args[] = $cohortId; }
    $sql .= ' ORDER BY (cs.scheduled_at IS NULL), cs.scheduled_at DESC, sa.id DESC';
    return mam_db_all($sql, $args);
}

/** Cohorts an instructor is responsible for: any cohort holding an active
 *  enrolment they teach, or one assigned directly to them. With counts. */
function mam_lms_instructor_cohorts(int $instructorId): array {
    return mam_db_all(
        "SELECT co.id, co.title, co.status, co.legacy_class_id, c.name AS course_name, l.name AS level_name,
                (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.cohort_id = co.id) AS enrollment_count,
                -- Cohort convergence (P2): a cohort backfilled from a legacy class
                -- carries legacy_class_id. Surface its class roster count so a
                -- backfilled cohort no longer misreads as '0 students'. Read-only
                -- fold — mirrors the admin Cohorts panel; the mark sheet still uses
                -- course_enrollments (legacy members are marked via My Classes).
                (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = co.legacy_class_id AND e.status IN ('active','paused')) AS legacy_count,
                (SELECT COUNT(*) FROM class_sessions cs WHERE cs.cohort_id = co.id) AS session_count
           FROM cohorts co
           LEFT JOIN courses c ON c.id = co.course_id
           LEFT JOIN levels l  ON l.id = co.level_id
          WHERE co.instructor_id = ?
             OR co.id IN (SELECT cohort_id FROM course_enrollments WHERE instructor_id = ? AND cohort_id IS NOT NULL)
          ORDER BY co.id DESC",
        [$instructorId, $instructorId]
    );
}

/** Whether an instructor is responsible for a cohort (teaches an enrolment in
 *  it, or it's assigned to them). Admins bypass this at the route layer. */
function mam_lms_instructor_owns_cohort(int $instructorId, int $cohortId): bool {
    return mam_db_row(
        "SELECT 1 FROM cohorts co
          WHERE co.id = ? AND (co.instructor_id = ?
            OR co.id IN (SELECT cohort_id FROM course_enrollments WHERE instructor_id = ? AND cohort_id IS NOT NULL))",
        [$cohortId, $instructorId, $instructorId]
    ) !== null;
}

/** Active/completed students enrolled in a cohort — the attendance roster. */
/** Who to teach / grade / mark in a cohort — DISTINCT students.
 *  The status set differs from mam_lms_seat_statuses() ON PURPOSE: a 'completed'
 *  student still belongs on the gradebook and historical mark sheets, but no
 *  longer occupies a seat for capacity; a 'pending' request holds a seat but is
 *  not yet teachable. Both queries count DISTINCT students so the two views can
 *  only differ by status, never by row multiplicity. */
function mam_lms_cohort_roster(int $cohortId): array {
    return mam_db_all(
        "SELECT DISTINCT u.id AS student_id, u.name AS student_name, u.email AS student_email
           FROM course_enrollments ce JOIN users u ON u.id = ce.student_id
          WHERE ce.cohort_id = ? AND ce.status IN ('active','completed')
          ORDER BY u.name",
        [$cohortId]
    );
}

/**
 * Cohort convergence (P2, WRITE-PATH) — reconcile a backfilled cohort's LEGACY
 * class roster into `course_enrollments`, so those students become fully
 * gradable + markable in the cohort tools (Gradebook / session mark-sheet),
 * not just visible as a count. This is the deliberate one-way reconciliation
 * the codebase otherwise avoids: strictly additive, idempotent, audited — it
 * never deletes, downgrades, or duplicates an enrolment.
 *
 * A row is created only for a legacy (active/paused) class member who has no
 * active/pending course enrolment in this cohort's (course, level); if they
 * already have a matching enrolment that isn't linked to the cohort, it is
 * linked rather than duplicated.
 *
 * Requires the cohort to map to a real course — `course_enrollments.course_id`
 * is NOT NULL, so a purely-legacy cohort must be course-mapped by an admin
 * first (returns reason 'no_course_mapping', converges nothing).
 *
 * @return array{converged:int,linked:int,skipped:int,total:int,reason:?string}
 */
function mam_lms_converge_cohort_enrollments(int $cohortId, ?int $actorId = null): array {
    $none = ['converged' => 0, 'linked' => 0, 'skipped' => 0, 'total' => 0];
    $co = mam_db_row('SELECT id, course_id, level_id, program_id, instructor_id, legacy_class_id FROM cohorts WHERE id = ?', [$cohortId]);
    if (!$co) throw new InvalidArgumentException('cohort_not_found');
    $legacyClassId = (int)($co['legacy_class_id'] ?? 0);
    if ($legacyClassId <= 0) return $none + ['reason' => 'not_backfilled'];
    $courseId = (int)($co['course_id'] ?? 0);
    if ($courseId <= 0) return $none + ['reason' => 'no_course_mapping'];

    $levelId   = $co['level_id']      !== null ? (int)$co['level_id']      : null;
    $programId = $co['program_id']    !== null ? (int)$co['program_id']    : null;
    $instrId   = $co['instructor_id'] !== null ? (int)$co['instructor_id'] : null;

    $legacy = mam_db_all(
        "SELECT DISTINCT e.student_id FROM enrollments e
          WHERE e.class_id = ? AND e.status IN ('active','paused')",
        [$legacyClassId]
    );
    $converged = 0; $linked = 0; $skipped = 0;
    foreach ($legacy as $row) {
        $sid = (int)$row['student_id'];
        // Existing active/pending enrolment in the SAME course+level? (bare
        // level_id compare so INTEGER affinity coerces the bound param — see the
        // note in mam_lms_add_course.)
        if ($levelId === null) {
            $existing = mam_db_row("SELECT id, cohort_id FROM course_enrollments WHERE student_id = ? AND course_id = ? AND level_id IS NULL AND status IN ('pending','active')", [$sid, $courseId]);
        } else {
            $existing = mam_db_row("SELECT id, cohort_id FROM course_enrollments WHERE student_id = ? AND course_id = ? AND level_id = ? AND status IN ('pending','active')", [$sid, $courseId, $levelId]);
        }
        if ($existing) {
            // Already enrolled — link it to this cohort if it's floating, else leave it.
            if (empty($existing['cohort_id'])) { mam_lms_link_enrollment_cohort((int)$existing['id'], $cohortId); $linked++; }
            else { $skipped++; }
            continue;
        }
        $id = mam_db_insert('course_enrollments', [
            'student_id'    => $sid,
            'course_id'     => $courseId,
            'level_id'      => $levelId,
            'program_id'    => $programId,
            'instructor_id' => $instrId,
            'cohort_id'     => $cohortId,
            'status'        => 'active',
            'approved_by'   => $actorId,
            'approved_at'   => date('Y-m-d H:i:s'),
        ]);
        mam_lms_log_event($id, null, 'active', $actorId, 'converged_from_legacy_class_' . $legacyClassId);
        $converged++;
    }
    if ($converged || $linked) {
        mam_audit('lms_cohort_converge', 'cohort', $cohortId, ['converged' => $converged, 'linked' => $linked, 'skipped' => $skipped, 'legacy_class_id' => $legacyClassId]);
    }
    return ['converged' => $converged, 'linked' => $linked, 'skipped' => $skipped, 'total' => $converged + $linked + $skipped, 'reason' => null];
}

/** The mark sheet for a session: every enrolled student with their attendance
 *  status for that session (null where not yet marked). */
function mam_lms_session_mark_sheet(int $sessionId, int $cohortId): array {
    $marks = [];
    foreach (mam_lms_session_attendance($sessionId) as $m) $marks[(int)$m['student_id']] = $m['status'];
    $rows = [];
    foreach (mam_lms_cohort_roster($cohortId) as $s) {
        $rows[] = [
            'student_id'    => (int)$s['student_id'],
            'student_name'  => $s['student_name'],
            'student_email' => $s['student_email'],
            'status'        => $marks[(int)$s['student_id']] ?? null,
        ];
    }
    return $rows;
}

/* ───────────────────────── Submissions & feedback (L2) ───────────────────
   A student submits an artifact (link/audio/video/pdf) against an assignment;
   the instructor leaves feedback (1:1 with a submission, editable) whose
   outcome drives the submission status. Resubmissions create new rows. */

/** A student submits an artifact for one of their assignments. Validates the
 *  assignment is theirs. Returns the submission id. Throws
 *  assignment_not_found / not_your_assignment. */
function mam_lms_submit(int $assignmentId, int $studentId, string $artifactUrl, array $opts = []): int {
    $a = mam_db_row('SELECT id, student_id FROM assignments WHERE id = ?', [$assignmentId]);
    if (!$a) throw new RuntimeException('assignment_not_found');
    if ((int)$a['student_id'] !== $studentId) throw new RuntimeException('not_your_assignment');
    // The artifact URL is later rendered into an instructor-facing <a href>. Only
    // http(s) links may be stored — reject javascript:/data:/other schemes at the
    // source so no unsafe scheme can reach the href (mirrors the resource route).
    if ($artifactUrl !== '' && !preg_match('#^https?://#i', $artifactUrl)) {
        throw new RuntimeException('invalid_url');
    }
    $kind = (string)($opts['artifact_kind'] ?? 'link');
    if (!in_array($kind, ['link', 'audio', 'video', 'pdf', 'image'], true)) $kind = 'link';
    $id = mam_db_insert('submissions', [
        'assignment_id' => $assignmentId,
        'student_id'    => $studentId,
        'artifact_url'  => $artifactUrl !== '' ? $artifactUrl : null,
        'artifact_kind' => $kind,
        'note'          => $opts['note'] ?? null,
        'status'        => 'submitted',
    ]);
    mam_audit('lms_submit', 'submission', $id, ['assignment' => $assignmentId, 'student' => $studentId]);
    return $id;
}

/** Instructor feedback on a submission (1:1, editable). outcome 'accepted' →
 *  submission accepted; 'revise' → needs_resubmission. Validates the submission
 *  + outcome. Returns ['ok','status','feedback_id']. Throws
 *  submission_not_found / invalid_outcome. */
function mam_lms_give_feedback(int $submissionId, int $instructorId, array $d): array {
    $sub = mam_db_row('SELECT id, student_id, assignment_id FROM submissions WHERE id = ?', [$submissionId]);
    if (!$sub) throw new RuntimeException('submission_not_found');
    $outcome = (string)($d['outcome'] ?? 'accepted');
    // §22 — accept, request a resubmission, reject, or assign remedial work.
    if (!in_array($outcome, ['accepted', 'revise', 'rejected', 'remedial'], true)) throw new RuntimeException('invalid_outcome');
    $now = date('Y-m-d H:i:s');
    $rubric = isset($d['rubric']) ? (is_string($d['rubric']) ? $d['rubric'] : json_encode($d['rubric'])) : null;
    $existing = mam_db_row('SELECT id FROM feedback WHERE submission_id = ?', [$submissionId]);
    if ($existing) {
        mam_db_update('feedback', ['instructor_id' => $instructorId, 'rubric_json' => $rubric, 'comments' => $d['comments'] ?? null, 'outcome' => $outcome, 'updated_at' => $now], ['id' => (int)$existing['id']]);
        $fid = (int)$existing['id'];
    } else {
        $fid = mam_db_insert('feedback', ['submission_id' => $submissionId, 'instructor_id' => $instructorId, 'rubric_json' => $rubric, 'comments' => $d['comments'] ?? null, 'outcome' => $outcome]);
    }
    $statusMap = ['accepted' => 'accepted', 'revise' => 'needs_resubmission', 'rejected' => 'rejected', 'remedial' => 'remedial'];
    $status = $statusMap[$outcome];
    mam_db_update('submissions', ['status' => $status, 'updated_at' => $now], ['id' => $submissionId]);
    mam_audit('lms_feedback', 'submission', $submissionId, ['outcome' => $outcome]);
    // Notify the student their work was reviewed (best-effort, never-throw).
    if (function_exists('mam_notify')) {
        $titleMap = ['accepted' => 'Your submission was accepted', 'revise' => 'Your submission needs another pass', 'rejected' => 'Your submission was not accepted', 'remedial' => 'Remedial work has been assigned'];
        $title = $titleMap[$outcome];
        $at = mam_db_row('SELECT title FROM assignments WHERE id = ?', [(int)$sub['assignment_id']]);
        $body = ($at && (string)$at['title'] !== '') ? 'Assignment: ' . $at['title'] : null;
        mam_notify((int)$sub['student_id'], 'submission.feedback', $title, $body, '/portal/student/');
    }
    return ['ok' => true, 'status' => $status, 'feedback_id' => $fid];
}

/* ── Timestamped feedback markers (Advanced Modernization §1.1) ─────────────── */

/** Markers on a submission (a comment pinned to a moment), earliest first. */
function mam_lms_markers(int $submissionId): array {
    return mam_db_all(
        "SELECT m.id, m.submission_id, m.at_seconds, m.comment, m.author_id, m.created_at, u.name AS author_name
           FROM submission_markers m
      LEFT JOIN users u ON u.id = m.author_id
          WHERE m.submission_id = ?
          ORDER BY m.at_seconds ASC, m.id ASC",
        [$submissionId]
    );
}

/** Add a timestamped marker. Returns the new marker id. */
function mam_lms_marker_add(int $submissionId, ?int $authorId, float $at, string $comment): int {
    if (!mam_db_row('SELECT id FROM submissions WHERE id = ?', [$submissionId])) throw new RuntimeException('submission_not_found');
    $comment = trim($comment);
    if ($comment === '') throw new InvalidArgumentException('comment_required');
    return mam_db_insert('submission_markers', [
        'submission_id' => $submissionId,
        'author_id'     => $authorId,
        'at_seconds'    => max(0.0, round($at, 3)),
        'comment'       => mb_substr($comment, 0, 2000),
    ]);
}

/** Delete a marker (only its author, or pass null authorId to force). */
function mam_lms_marker_delete(int $markerId, ?int $authorId = null): bool {
    $row = mam_db_row('SELECT author_id FROM submission_markers WHERE id = ?', [$markerId]);
    if (!$row) return false;
    if ($authorId !== null && (int)$row['author_id'] !== $authorId) throw new RuntimeException('forbidden');
    mam_db()->prepare('DELETE FROM submission_markers WHERE id = ?')->execute([$markerId]);
    return true;
}

/** Submissions for an assignment (newest first) with student name + feedback. */
function mam_lms_assignment_submissions(int $assignmentId): array {
    return mam_db_all(
        "SELECT s.*, u.name AS student_name, f.comments AS feedback_comments, f.outcome AS feedback_outcome, f.rubric_json
           FROM submissions s
           JOIN users u         ON u.id = s.student_id
           LEFT JOIN feedback f ON f.submission_id = s.id
          WHERE s.assignment_id = ? ORDER BY s.id DESC",
        [$assignmentId]
    );
}

/** A student's submissions (optionally for one assignment), newest first, with
 *  feedback + the assignment title. */
function mam_lms_student_submissions(int $studentId, ?int $assignmentId = null): array {
    $sql = "SELECT s.*, a.title AS assignment_title, f.comments AS feedback_comments,
                   f.outcome AS feedback_outcome, f.rubric_json, fu.name AS feedback_by
              FROM submissions s
              JOIN assignments a   ON a.id = s.assignment_id
              LEFT JOIN feedback f ON f.submission_id = s.id
              LEFT JOIN users fu   ON fu.id = f.instructor_id
             WHERE s.student_id = ?";
    $args = [$studentId];
    if ($assignmentId !== null) { $sql .= ' AND s.assignment_id = ?'; $args[] = $assignmentId; }
    $sql .= ' ORDER BY s.id DESC';
    return mam_db_all($sql, $args);
}

/** One submission with its feedback, assignment title (+ author), student name. */
function mam_lms_submission(int $id): ?array {
    return mam_db_row(
        "SELECT s.*, a.title AS assignment_title, a.author_id AS assignment_author_id,
                u.name AS student_name, f.comments AS feedback_comments, f.outcome AS feedback_outcome,
                f.rubric_json, f.instructor_id AS feedback_instructor_id
           FROM submissions s
           JOIN assignments a   ON a.id = s.assignment_id
           JOIN users u         ON u.id = s.student_id
           LEFT JOIN feedback f ON f.submission_id = s.id
          WHERE s.id = ?",
        [$id]
    );
}

/* ───────────────────────── Assessments & results ─────────────────────────
   Each course enrolment is graded across the weighted assessment_types
   (attendance/assignment/practical/continuous/final). The result is a
   weighted average of the per-type score-averages, normalised over whatever
   has been graded so far — so a partial gradebook shows a fair "current
   standing" and a complete one shows the final weighted mark. */

/** Pass mark (percent) — admin-editable. */
function mam_lms_pass_mark(): float { return mam_setting_num('lms_pass_mark', 50); }

/** Map a 0–100 percentage to a [letter, band]. The fail boundary tracks the
 *  admin-configurable pass mark, so the letter/band can never contradict the
 *  pass/fail outcome (mam_lms_compute_result derives that from the same mark).
 *  Previously the D/F cut was hard-coded at 50: with a pass mark of e.g. 60, a
 *  55% showed "D · pass" on an enrolment whose outcome was "fail". */
function mam_lms_grade(float $pct): array {
    if ($pct < mam_lms_pass_mark()) return ['F', 'fail'];
    if ($pct >= 80) return ['A', 'distinction'];
    if ($pct >= 70) return ['B', 'merit'];
    if ($pct >= 60) return ['C', 'credit'];
    return ['D', 'pass'];
}

/**
 * Recompute and persist an enrolment's result from its assessments.
 * Returns ['total','grade','outcome','band','graded_weight']; total is null
 * when nothing has been graded yet.
 */
function mam_lms_compute_result(int $enrollmentId, ?int $by = null): array {
    $rows = mam_db_all(
        "SELECT t.weight AS weight, AVG(a.score * 100.0 / NULLIF(a.max_score, 0)) AS avg_pct
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

/** One assessment row with its enrolment + type (for ownership checks and edits). */
function mam_lms_assessment(int $assessmentId): ?array {
    return mam_db_row(
        "SELECT a.id, a.enrollment_id, a.type_id, a.score, a.max_score, a.week, a.notes,
                t.code AS type_code, ce.instructor_id
           FROM assessments a
           JOIN course_enrollments ce ON ce.id = a.enrollment_id
           LEFT JOIN assessment_types t ON t.id = a.type_id
          WHERE a.id = ?",
        [$assessmentId]
    );
}

/** Correct a recorded assessment ("remark"): overwrite score/max/week/notes in
 *  place — NOT another averaged row — then recompute. Audits the before→after so
 *  a grade change is never silent. Returns ['enrollment_id','result']. */
function mam_lms_update_assessment(int $assessmentId, ?int $by, float $score, float $maxScore = 100.0, ?int $week = null, ?string $notes = null): array {
    $a = mam_lms_assessment($assessmentId);
    if (!$a) throw new InvalidArgumentException('assessment_not_found');
    if ($maxScore <= 0) $maxScore = 100.0;
    $score = max(0.0, min($score, $maxScore));
    $eid = (int)$a['enrollment_id'];
    mam_db_update('assessments', [
        'score' => $score, 'max_score' => $maxScore, 'week' => $week, 'notes' => $notes,
    ], ['id' => $assessmentId]);
    mam_audit('lms_assess_edit', 'course_enrollment', $eid, [
        'assessment_id' => $assessmentId, 'type' => $a['type_code'],
        'from' => ['score' => (float)$a['score'], 'max' => (float)$a['max_score']],
        'to'   => ['score' => $score, 'max' => $maxScore],
    ]);
    return ['enrollment_id' => $eid, 'result' => mam_lms_compute_result($eid, $by)];
}

/** Void (delete) a recorded assessment, then recompute. Audits the removed value.
 *  Returns ['enrollment_id','result']. */
function mam_lms_delete_assessment(int $assessmentId, ?int $by): array {
    $a = mam_lms_assessment($assessmentId);
    if (!$a) throw new InvalidArgumentException('assessment_not_found');
    $eid = (int)$a['enrollment_id'];
    mam_db()->prepare('DELETE FROM assessments WHERE id = ?')->execute([$assessmentId]);
    mam_audit('lms_assess_void', 'course_enrollment', $eid, [
        'assessment_id' => $assessmentId, 'type' => $a['type_code'], 'score' => (float)$a['score'],
    ]);
    return ['enrollment_id' => $eid, 'result' => mam_lms_compute_result($eid, $by)];
}

/** An instructor's active course enrolments with student/course + current result. */
function mam_lms_instructor_gradebook(int $instructorId): array {
    // Primary query — requires cohort_id column (added in v19).
    // Falls back to a cohort-free variant when the column is absent on
    // un-migrated MySQL; callers handle null cohort_id gracefully.
    try {
        return mam_db_all(
            "SELECT ce.id, ce.status, ce.cohort_id,
                    co.title AS cohort_title,
                    su.name AS student_name,
                    c.name AS course_name, c.kind AS course_kind, l.name AS level_name,
                    r.total_score, r.grade, r.outcome
               FROM course_enrollments ce
               JOIN users su        ON su.id = ce.student_id
               JOIN courses c       ON c.id = ce.course_id
               LEFT JOIN cohorts co ON co.id = ce.cohort_id
               LEFT JOIN levels l   ON l.id = ce.level_id
               LEFT JOIN results r  ON r.enrollment_id = ce.id
              WHERE ce.instructor_id = ? AND ce.status = 'active'
              ORDER BY co.title, su.name, c.name",
            [$instructorId]
        );
    } catch (\Throwable $e) {
        mam_log('warn', 'gradebook_primary_failed', ['err' => $e->getMessage()]);
        // Second attempt: drop cohort_id (column may be absent), keep results JOIN.
        try {
            return mam_db_all(
                "SELECT ce.id, ce.status, NULL AS cohort_id, NULL AS cohort_title,
                        su.name AS student_name,
                        c.name AS course_name, c.kind AS course_kind, l.name AS level_name,
                        r.total_score, r.grade, r.outcome
                   FROM course_enrollments ce
                   JOIN users su        ON su.id = ce.student_id
                   JOIN courses c       ON c.id = ce.course_id
                   LEFT JOIN levels l   ON l.id = ce.level_id
                   LEFT JOIN results r  ON r.enrollment_id = ce.id
                  WHERE ce.instructor_id = ? AND ce.status = 'active'
                  ORDER BY su.name, c.name",
                [$instructorId]
            );
        } catch (\Throwable $e2) {
            // Third attempt: results table also absent (un-migrated host).
            // Returns rows with null scores; the UI shows "—" until migration runs.
            mam_log('warn', 'gradebook_results_table_missing', ['err' => $e2->getMessage()]);
            return mam_db_all(
                "SELECT ce.id, ce.status, NULL AS cohort_id, NULL AS cohort_title,
                        su.name AS student_name,
                        c.name AS course_name, c.kind AS course_kind, l.name AS level_name,
                        NULL AS total_score, NULL AS grade, NULL AS outcome
                   FROM course_enrollments ce
                   JOIN users su ON su.id = ce.student_id
                   JOIN courses c ON c.id = ce.course_id
                   LEFT JOIN levels l ON l.id = ce.level_id
                  WHERE ce.instructor_id = ? AND ce.status = 'active'
                  ORDER BY su.name, c.name",
                [$instructorId]
            );
        }
    }
}

/** Every assessment recorded against an enrolment (newest first), with the
 *  grader's name so the student sees who marked each component and when. */
function mam_lms_enrollment_assessments(int $enrollmentId): array {
    return mam_db_all(
        "SELECT a.id, a.score, a.max_score, a.week, a.notes, a.created_at,
                t.code AS type_code, t.name AS type_name, t.weight,
                iu.name AS grader_name
           FROM assessments a
           LEFT JOIN assessment_types t ON t.id = a.type_id
           LEFT JOIN users iu ON iu.id = a.instructor_id
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

/**
 * Clone every authored week of a course from one level to another, upserting by
 * the target (course, level, week) so re-running is idempotent (no duplicate
 * weeks). Copies kind/title/content. Level code '' / 'general' / '_none' means
 * the level-agnostic syllabus. Returns {created, updated, total}. Throws on an
 * unknown course or a same-level clone.
 */
function mam_lms_clone_syllabus(string $courseCode, string $fromLevelCode, string $toLevelCode): array {
    $out = ['created' => 0, 'updated' => 0, 'total' => 0];
    if (trim($fromLevelCode) === trim($toLevelCode)) throw new InvalidArgumentException('same_level');
    $course = mam_db_row('SELECT id FROM courses WHERE code = ?', [$courseCode]);
    if (!$course) throw new InvalidArgumentException('course_not_found');
    $courseId = (int)$course['id'];
    $resolve = function (string $code): ?int {
        $code = trim($code);
        if ($code === '' || $code === 'general' || $code === '_none') return null;
        $r = mam_db_row('SELECT id FROM levels WHERE code = ?', [$code]);
        return $r ? (int)$r['id'] : null;
    };
    $fromId = $resolve($fromLevelCode);
    $toId   = $resolve($toLevelCode);
    $src = $fromId === null
        ? mam_db_all('SELECT week, kind, title, content FROM modules WHERE course_id = ? AND level_id IS NULL ORDER BY week', [$courseId])
        : mam_db_all('SELECT week, kind, title, content FROM modules WHERE course_id = ? AND level_id = ? ORDER BY week', [$courseId, $fromId]);
    foreach ($src as $m) {
        $week = (int)$m['week'];
        $ex = $toId === null
            ? mam_db_row('SELECT id FROM modules WHERE course_id = ? AND level_id IS NULL AND week = ?', [$courseId, $week])
            : mam_db_row('SELECT id FROM modules WHERE course_id = ? AND level_id = ? AND week = ?', [$courseId, $toId, $week]);
        mam_lms_save_module($ex ? (int)$ex['id'] : null, $courseId, $toId, $week, (string)$m['kind'], (string)$m['title'], $m['content'] !== null ? (string)$m['content'] : null);
        if ($ex) $out['updated']++; else $out['created']++;
        $out['total']++;
    }
    if ($out['total'] > 0) mam_audit('lms_clone_syllabus', 'course', $courseId, ['from' => $fromLevelCode, 'to' => $toLevelCode, 'total' => $out['total']]);
    return $out;
}

/* ── Curriculum proposals: faculty author, admin approves ──────────────────
   Instructors don't write `modules` directly; they submit a proposal for a
   week of a course/level they teach. An admin approves (→ upserted into
   `modules`) or rejects. Keeps the canonical curriculum admin-gated. */

/** Submit a curriculum proposal (status 'pending'). Returns the proposal id. */
function mam_lms_propose_module(int $courseId, ?int $levelId, int $week, string $kind, string $title, ?string $content, ?int $by): int {
    if (!in_array($kind, ['module', 'revision', 'exam'], true)) $kind = 'module';
    $id = mam_db_insert('module_proposals', [
        'course_id' => $courseId, 'level_id' => $levelId, 'week' => max(1, $week),
        'kind' => $kind, 'title' => $title, 'content' => $content,
        'proposed_by' => $by, 'status' => 'pending',
    ]);
    mam_audit('lms_module_propose', 'module_proposal', $id, ['course' => $courseId, 'week' => $week]);
    return $id;
}

/** Proposals submitted by one instructor (newest first). */
function mam_lms_instructor_proposals(int $instructorId, int $limit = 100): array {
    $limit = max(1, min(200, $limit));
    return mam_db_all(
        "SELECT mp.*, c.name AS course_name, l.name AS level_name, ru.name AS reviewer_name
           FROM module_proposals mp
           LEFT JOIN courses c ON c.id = mp.course_id
           LEFT JOIN levels l  ON l.id = mp.level_id
           LEFT JOIN users ru  ON ru.id = mp.reviewed_by
          WHERE mp.proposed_by = ? ORDER BY mp.id DESC LIMIT " . $limit,
        [$instructorId]
    );
}

/** Pending proposals awaiting admin review (with proposer + course names). */
function mam_lms_pending_proposals(int $limit = 200): array {
    $limit = max(1, min(500, $limit));
    return mam_db_all(
        "SELECT mp.*, c.name AS course_name, l.name AS level_name, pu.name AS proposer_name
           FROM module_proposals mp
           LEFT JOIN courses c ON c.id = mp.course_id
           LEFT JOIN levels l  ON l.id = mp.level_id
           LEFT JOIN users pu  ON pu.id = mp.proposed_by
          WHERE mp.status = 'pending' ORDER BY mp.id DESC LIMIT " . $limit
    );
}

/** Approve (→ upsert into `modules`) or reject a proposal. Idempotent: a
 *  non-pending proposal is left untouched. Returns the updated proposal row. */
function mam_lms_review_proposal(int $id, bool $approve, ?int $reviewer, ?string $note = null): array {
    $p = mam_db_row('SELECT * FROM module_proposals WHERE id = ?', [$id]);
    if (!$p) throw new InvalidArgumentException('proposal_not_found');
    if ($p['status'] !== 'pending') return $p; // already decided — no-op

    if ($approve) {
        $existing = mam_lms_find_module_week((int)$p['course_id'], $p['level_id'] !== null ? (int)$p['level_id'] : null, (int)$p['week']);
        mam_lms_save_module($existing, (int)$p['course_id'], $p['level_id'] !== null ? (int)$p['level_id'] : null,
            (int)$p['week'], (string)$p['kind'], (string)$p['title'], $p['content']);
    }
    mam_db_update('module_proposals', [
        'status' => $approve ? 'approved' : 'rejected',
        'review_note' => $note, 'reviewed_by' => $reviewer, 'reviewed_at' => date('Y-m-d H:i:s'),
    ], ['id' => $id]);
    mam_audit($approve ? 'lms_module_proposal_approve' : 'lms_module_proposal_reject', 'module_proposal', $id, ['week' => $p['week']]);
    return mam_db_row('SELECT * FROM module_proposals WHERE id = ?', [$id]);
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

/* ──────────────────── Standard curriculum: admin + import/export ──────────
   The curriculum IS the `modules` table (per course+level+week). These helpers
   give the admin a first-class authoring surface over it: a catalogue picker, a
   portable JSON/CSV export, and an idempotent bulk import that upserts by
   (course, level, week) so a syllabus can be version-controlled in a
   spreadsheet and re-imported to refine — never duplicating weeks. */

/** Courses/levels/programmes for the admin curriculum picker — includes
 *  INACTIVE rows (with an `active` flag) and ids, unlike the public catalogue. */
function mam_lms_admin_catalog(): array {
    return [
        'programs' => mam_db_all('SELECT id, code, name, weeks, revision_week, exam_week, active FROM programs ORDER BY sort_order, name'),
        'levels'   => mam_db_all('SELECT id, code, name, trinity_grades, sort_order FROM levels ORDER BY sort_order'),
        'courses'  => mam_db_all("SELECT id, code, name, kind, active FROM courses ORDER BY CASE WHEN kind = 'core' THEN 0 ELSE 1 END, sort_order, name"),
    ];
}

/**
 * Curriculum completeness matrix for the admin overview: authored-week counts
 * per (course × level), plus a level-agnostic ('_none') column. One grouped
 * query feeds the whole grid so admins see — and can jump to — every syllabus
 * at a glance. Returns ['courses'=>[{code,name,kind,active,levels{code=>n,_none=>n},total}], 'levels'=>[…], 'total'=>n, 'authored_cells'=>n].
 */
function mam_lms_curriculum_overview(): array {
    $cat = mam_lms_admin_catalog();
    $levelCode = [];
    foreach ($cat['levels'] as $l) $levelCode[(int)$l['id']] = (string)$l['code'];

    $counts = []; // [course_id][levelCode|'_none'] = n
    foreach (mam_db_all('SELECT course_id, level_id, COUNT(*) AS n FROM modules GROUP BY course_id, level_id') as $r) {
        $cid = (int)$r['course_id'];
        $key = $r['level_id'] !== null ? ($levelCode[(int)$r['level_id']] ?? ('#' . (int)$r['level_id'])) : '_none';
        $counts[$cid][$key] = (int)$r['n'];
    }

    $courses = []; $total = 0; $cells = 0;
    foreach ($cat['courses'] as $c) {
        $cid = (int)$c['id'];
        $row = ['code' => $c['code'], 'name' => $c['name'], 'kind' => $c['kind'], 'active' => (int)$c['active'], 'levels' => [], 'total' => 0];
        foreach ($cat['levels'] as $l) {
            $n = $counts[$cid][(string)$l['code']] ?? 0;
            $row['levels'][(string)$l['code']] = $n;
            $row['total'] += $n; if ($n > 0) $cells++;
        }
        $none = $counts[$cid]['_none'] ?? 0;
        $row['levels']['_none'] = $none; $row['total'] += $none; if ($none > 0) $cells++;
        $total += $row['total'];
        $courses[] = $row;
    }
    return ['courses' => $courses, 'levels' => $cat['levels'], 'total' => $total, 'authored_cells' => $cells];
}

/** The authored modules for a course (+level) as a clean, portable list —
 *  [{week,kind,title,content}], week-sorted. The unit of curriculum export. */
function mam_lms_export_modules(int $courseId, ?int $levelId): array {
    $out = [];
    foreach (mam_lms_course_modules($courseId, $levelId) as $m) {
        $out[] = [
            'week'    => (int)$m['week'],
            'kind'    => (string)$m['kind'],
            'title'   => (string)$m['title'],
            'content' => $m['content'] !== null ? (string)$m['content'] : null,
        ];
    }
    return $out;
}

/** Render an export list as CSV text (header + rows). Pure — uses an in-memory
 *  stream so quoting/escaping matches a spreadsheet exactly. */
function mam_lms_modules_to_csv(array $rows): string {
    $fh = fopen('php://temp', 'r+');
    // Pass the separator/enclosure/escape explicitly — PHP 8.4 deprecates relying
    // on the implicit escape default.
    fputcsv($fh, ['week', 'kind', 'title', 'content'], ',', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($fh, [$r['week'] ?? '', $r['kind'] ?? 'module', $r['title'] ?? '', $r['content'] ?? ''], ',', '"', '\\');
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return (string)$csv;
}

/** Parse CSV text into a list of associative rows keyed by a normalized header
 *  (lower-cased, non-alphanumerics → underscore). Tolerant of quoted fields and
 *  embedded newlines (fgetcsv) and a UTF-8 BOM. Returns [] on empty/headerless
 *  input. Pure — easy to unit-test. */
function mam_lms_parse_csv(string $csv): array {
    $csv = preg_replace("/^\xEF\xBB\xBF/", '', $csv); // strip UTF-8 BOM if present
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, (string)$csv);
    rewind($fh);
    $header = null; $rows = [];
    while (($cols = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        // Skip a fully blank line (fgetcsv yields [null] or a single empty cell).
        if ($cols === [null] || (count($cols) === 1 && trim((string)($cols[0] ?? '')) === '')) continue;
        if ($header === null) {
            $header = array_map(function ($h) {
                return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string)$h))), '_');
            }, $cols);
            continue;
        }
        $row = [];
        foreach ($header as $i => $key) { if ($key !== '') $row[$key] = $cols[$i] ?? null; }
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

/** Turn pasted/uploaded import text into module rows. Auto-detects JSON vs CSV
 *  unless $format forces it ('json'|'csv'). Understands a bare JSON array, a
 *  wrapped {"modules":[…]} export, or CSV. Returns [] when nothing parseable.
 *  Pure — shared by the API route and the admin UI so there's one import brain. */
function mam_lms_rows_from_text(string $text, string $format = ''): array {
    $text = trim($text);
    if ($text === '') return [];
    $looksJson = str_starts_with($text, '[') || str_starts_with($text, '{');
    if ($format === 'csv' || ($format !== 'json' && !$looksJson)) {
        return mam_lms_parse_csv($text);
    }
    $decoded = json_decode($text, true);
    if (!is_array($decoded)) return [];
    if (isset($decoded['modules']) && is_array($decoded['modules'])) return $decoded['modules'];
    return $decoded;
}

/**
 * Resolve a host to its public IP addresses, for the SSRF guard. Returns every
 * A/AAAA address — but ONLY if the host resolves AND every record is public.
 * If the host is unresolvable, or ANY record points at a loopback/private/
 * reserved range, returns [] so the caller fails closed (this also defeats a
 * DNS answer that mixes a public and a private address). For an IP literal,
 * validates the literal itself.
 */
function mam_lms_public_ips_for(string $host): array {
    $host = trim($host);
    if ($host === '') return [];
    // parse_url keeps IPv6 literals bracketed (e.g. "[::1]"); strip for testing.
    $bare = (strlen($host) > 1 && $host[0] === '[' && $host[-1] === ']') ? substr($host, 1, -1) : $host;

    // Ranges PHP's NO_PRIV/NO_RES flags don't cover: shared/CGNAT (RFC 6598),
    // "this host" 0.0.0.0/8, IETF protocol/benchmark/TEST-NET/reserved blocks.
    static $extraDeny = [
        '0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15',
        '192.0.2.0/24', '198.51.100.0/24', '203.0.113.0/24', '240.0.0.0/4',
        '::/128', '64:ff9b::/96', '2001:db8::/32',
    ];
    $isPublic = static function (string $ip) use ($extraDeny): bool {
        // Normalize an IPv4-mapped IPv6 literal (::ffff:169.254.169.254) to its
        // embedded v4 so it's classified by the v4 rules, not waved through as
        // "some IPv6 address" on PHP builds that don't fold it themselves.
        if (stripos($ip, '::ffff:') === 0) {
            $tail = substr($ip, 7);
            if (filter_var($tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ip = $tail;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        foreach ($extraDeny as $cidr) if (mam_ip_in_cidr($ip, $cidr)) return false;
        return true;
    };

    if (filter_var($bare, FILTER_VALIDATE_IP)) {     // an IP literal — no DNS to consult
        return $isPublic($bare) ? [$bare] : [];
    }

    $ips = [];
    $v4 = @gethostbynamel($host);                    // A records
    if (is_array($v4)) $ips = $v4;
    if (function_exists('dns_get_record')) {         // AAAA records
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) foreach ($aaaa as $r) if (!empty($r['ipv6'])) $ips[] = (string)$r['ipv6'];
    }
    $ips = array_values(array_unique($ips));
    if (!$ips) return [];                            // unresolvable
    foreach ($ips as $ip) if (!$isPublic($ip)) return []; // one private answer poisons the host
    return $ips;
}

/**
 * Fetch a remote curriculum DATA SOURCE (a published CSV/JSON URL — e.g. a
 * Google Sheets "publish to web" CSV, or any JSON endpoint) for import.
 *
 * SSRF hardening: http(s) only; redirects are followed MANUALLY (≤4) so every
 * hop is re-validated — a public URL can't 302 us onto an internal host such as
 * 169.254.169.254 (cloud metadata) or 10.x. For named hosts the validated
 * address is pinned with CURLOPT_RESOLVE, so curl can't re-resolve to a private
 * IP between our check and the connect (DNS rebinding). 8 s timeout, 2 MB cap.
 * Returns the body, or null on any failure (fails closed).
 */
function mam_lms_fetch_url(string $url): ?string {
    if (!function_exists('curl_init')) return null;
    $url = trim($url);
    $cap = 2 * 1024 * 1024;

    for ($hop = 0; $hop < 5; $hop++) {               // initial request + up to 4 redirects
        if (!preg_match('#^https?://#i', $url)) return null;
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host'])) return null;
        $host   = (string)$p['host'];
        $scheme = strtolower((string)($p['scheme'] ?? 'http'));
        $port   = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));

        // A public IP can still front non-HTTP services (:22, :6379, :25…).
        // A curriculum data source is plain web content — pin to the web ports.
        if (!in_array($port, [80, 443], true)) return null;

        $ips = mam_lms_public_ips_for($host);        // SSRF guard, applied to EVERY hop
        if (!$ips) return null;

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,         // we follow manually so each hop is validated + pinned
            CURLOPT_PROTOCOLS      => defined('CURLPROTO_HTTP') ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : 0,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'mam-academy/1.0 (+curriculum-import)',
            CURLOPT_HTTPHEADER     => ['Accept: text/csv, application/json, text/plain, */*'],
        ];
        // Pin the validated address(es) for a named host (literals are already
        // exact, so curl connects to precisely the IP we checked).
        if (!filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            $opts[CURLOPT_RESOLVE] = ["{$host}:{$port}:" . implode(',', $ips)];
        }

        $ch = curl_init();
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $loc  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL); // absolute target, even with FOLLOWLOCATION off
        curl_close($ch);

        if ($resp === false) return null;
        if ($code >= 300 && $code < 400) {           // re-validate the redirect target next iteration
            if ($loc === '') return null;
            $url = $loc;
            continue;
        }
        if ($code < 200 || $code >= 300) return null;
        return strlen((string)$resp) > $cap ? substr((string)$resp, 0, $cap) : (string)$resp;
    }
    return null;                                     // too many redirects
}

/** The module id authored for (course, level, week), or null. Affinity-safe on
 *  the nullable level_id (bare-column comparison, like the enrolment guards). */
function mam_lms_find_module_week(int $courseId, ?int $levelId, int $week): ?int {
    $row = $levelId === null
        ? mam_db_row('SELECT id FROM modules WHERE course_id = ? AND level_id IS NULL AND week = ?', [$courseId, $week])
        : mam_db_row('SELECT id FROM modules WHERE course_id = ? AND level_id = ? AND week = ?', [$courseId, $levelId, $week]);
    return $row ? (int)$row['id'] : null;
}

/**
 * Bulk import curriculum modules. Each row: { week, title, kind?, content?,
 * course_code?, level_code? }. A row's own course_code/level_code override the
 * defaults, so one file can populate several courses at once. Upserts by
 * (course, level, week) — re-importing refines an existing syllabus rather than
 * duplicating it. Validates per-row and never throws on a bad row (it's
 * collected in `errors`). Returns
 * ['created','updated','skipped','errors'=>[{row,reason}]].
 */
function mam_lms_import_modules(?int $defaultCourseId, ?int $defaultLevelId, array $rows): array {
    $created = 0; $updated = 0; $skipped = 0; $errors = [];
    $courseCache = []; $levelCache = []; // a multi-course file shouldn't re-query the same code

    $resolveCourse = function ($code) use (&$courseCache) {
        $code = trim((string)$code);
        if ($code === '') return null;
        if (!array_key_exists($code, $courseCache)) {
            $r = mam_db_row('SELECT id FROM courses WHERE code = ?', [$code]);
            $courseCache[$code] = $r ? (int)$r['id'] : null;
        }
        return $courseCache[$code];
    };
    $resolveLevel = function ($code) use (&$levelCache) {
        $code = trim((string)$code);
        if ($code === '') return null;
        if (!array_key_exists($code, $levelCache)) {
            $r = mam_db_row('SELECT id FROM levels WHERE code = ?', [$code]);
            $levelCache[$code] = $r ? (int)$r['id'] : null;
        }
        return $levelCache[$code];
    };

    foreach ($rows as $i => $row) {
        $n = $i + 1;
        if (!is_array($row)) { $errors[] = ['row' => $n, 'reason' => 'not_an_object']; $skipped++; continue; }

        // Course: a per-row code overrides the default selection.
        $courseId = $defaultCourseId;
        $cCode = $row['course_code'] ?? $row['course'] ?? null;
        if ($cCode !== null && trim((string)$cCode) !== '') {
            $courseId = $resolveCourse($cCode);
            if ($courseId === null) { $errors[] = ['row' => $n, 'reason' => 'course_not_found:' . trim((string)$cCode)]; $skipped++; continue; }
        }
        if ($courseId === null) { $errors[] = ['row' => $n, 'reason' => 'no_course']; $skipped++; continue; }

        // Level: a per-row code overrides; absent → fall back to the default.
        $levelId = $defaultLevelId;
        $lCode = $row['level_code'] ?? $row['level'] ?? null;
        if ($lCode !== null && trim((string)$lCode) !== '') {
            $levelId = $resolveLevel($lCode);
            if ($levelId === null) { $errors[] = ['row' => $n, 'reason' => 'level_not_found:' . trim((string)$lCode)]; $skipped++; continue; }
        }

        $week  = (int)($row['week'] ?? 0);
        $title = trim((string)($row['title'] ?? ''));
        if ($week < 1)     { $errors[] = ['row' => $n, 'reason' => 'bad_week']; $skipped++; continue; }
        if ($title === '') { $errors[] = ['row' => $n, 'reason' => 'empty_title']; $skipped++; continue; }
        $kind = (string)($row['kind'] ?? 'module');
        if (!in_array($kind, ['module', 'revision', 'exam'], true)) $kind = 'module';
        $content = (isset($row['content']) && trim((string)$row['content']) !== '') ? (string)$row['content'] : null;

        $existing = mam_lms_find_module_week($courseId, $levelId, $week);
        $data = ['course_id' => $courseId, 'level_id' => $levelId, 'week' => $week, 'kind' => $kind, 'title' => $title, 'content' => $content];
        if ($existing) { mam_db_update('modules', $data, ['id' => $existing]); $updated++; }
        else           { mam_db_insert('modules', $data);                     $created++; }
    }
    if ($created || $updated) mam_audit('lms_module_import', 'course', $defaultCourseId, ['created' => $created, 'updated' => $updated, 'skipped' => $skipped]);
    return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
}

/**
 * Seed the bundled starter curriculum (mam_lms_starter_curriculum, transcribed
 * from MAM's KTAMS & Grand Musicians II documents) so the academy ships with a
 * real, editable syllabus. Non-destructive by default: only weeks that don't
 * already exist for a (course, level) are written, so an educator's edits are
 * never clobbered. $force=true re-imports every week (reset to the starter).
 * Sets the `curriculum_seeded` flag. Returns a per-syllabus summary keyed by
 * "course/level".
 */
function mam_lms_seed_starter_curriculum(bool $force = false): array {
    if (!function_exists('mam_lms_starter_curriculum')) return [];
    $summary = [];
    foreach (mam_lms_starter_curriculum() as $syllabus) {
        $key = ($syllabus['course'] ?? '?') . '/' . ($syllabus['level'] ?? '?');
        $c = mam_db_row('SELECT id FROM courses WHERE code = ?', [$syllabus['course'] ?? '']);
        $l = mam_db_row('SELECT id FROM levels  WHERE code = ?', [$syllabus['level'] ?? '']);
        if (!$c || !$l) { $summary[$key] = ['error' => 'course_or_level_not_found']; continue; }
        $cid = (int)$c['id']; $lid = (int)$l['id'];
        $weeks = $syllabus['weeks'] ?? [];
        // Non-forced: skip weeks already authored, so we never overwrite edits.
        $rows = $force ? $weeks : array_values(array_filter($weeks, function ($w) use ($cid, $lid) {
            return mam_lms_find_module_week($cid, $lid, (int)($w['week'] ?? 0)) === null;
        }));
        $summary[$key] = $rows
            ? mam_lms_import_modules($cid, $lid, $rows)
            : ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    }
    if (function_exists('mam_setting_set')) { try { mam_setting_set('curriculum_seeded', '1'); } catch (Throwable $e) { /* flag is best-effort */ } }
    mam_audit('lms_seed_starter', 'course', null, ['force' => $force, 'syllabi' => count($summary)]);
    return $summary;
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

/* ═══════════════════════════════════════════════════════════════════════════
   Enterprise curriculum management — course CRUD, program CRUD, program-
   course linking, and full-curriculum export (schema v41).
   ═══════════════════════════════════════════════════════════════════════════ */

/** Create a new course. Code is normalised (lowercase, non-alnum → underscore).
 *  Throws RuntimeException('duplicate_code') on collision. Returns course id. */
function mam_lms_create_course(string $code, string $name, string $kind, ?string $description, int $sortOrder = 99): int {
    $code = strtolower(trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($code))), '_'));
    if ($code === '') throw new InvalidArgumentException('invalid_code');
    if (trim($name) === '') throw new InvalidArgumentException('empty_name');
    if (!in_array($kind, ['core', 'elective'], true)) $kind = 'elective';
    if (mam_db_row('SELECT id FROM courses WHERE code = ?', [$code])) throw new RuntimeException('duplicate_code');
    $id = mam_db_insert('courses', [
        'code' => $code, 'name' => trim($name), 'kind' => $kind,
        'description' => $description !== null && trim($description) !== '' ? trim($description) : null,
        'sort_order' => max(0, $sortOrder), 'active' => 1,
    ]);
    mam_audit('lms_course_create', 'course', $id, ['code' => $code, 'name' => $name, 'kind' => $kind]);
    return $id;
}

/** Update editable course fields (name, kind, description, sort_order, active). */
function mam_lms_update_course(int $courseId, array $data): void {
    $allowed = ['name', 'kind', 'description', 'sort_order', 'active'];
    $update  = [];
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $data)) continue;
        if ($k === 'kind' && !in_array($data[$k], ['core', 'elective'], true)) continue;
        if ($k === 'name' && trim((string)$data[$k]) === '') continue;
        $update[$k] = $data[$k];
    }
    if (!$update) return;
    mam_db_update('courses', $update, ['id' => $courseId]);
    mam_audit('lms_course_update', 'course', $courseId, $update);
}

/** Create a new programme. Code is normalised. Throws RuntimeException('duplicate_code').
 *  Returns programme id. */
function mam_lms_create_program(string $code, string $name, int $weeks, ?int $revisionWeek, ?int $examWeek, bool $requiresCore, string $certification, int $sortOrder = 99): int {
    $code = strtolower(trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($code))), '_'));
    if ($code === '') throw new InvalidArgumentException('invalid_code');
    if (trim($name) === '') throw new InvalidArgumentException('empty_name');
    if (mam_db_row('SELECT id FROM programs WHERE code = ?', [$code])) throw new RuntimeException('duplicate_code');
    $validCerts = ['participation', 'completion', 'internal', 'internal_diploma'];
    if (!in_array($certification, $validCerts, true)) $certification = 'completion';
    $id = mam_db_insert('programs', [
        'code' => $code, 'name' => trim($name), 'weeks' => max(1, $weeks),
        'revision_week' => $revisionWeek, 'exam_week' => $examWeek,
        'requires_core' => $requiresCore ? 1 : 0, 'certification' => $certification,
        'sort_order' => max(0, $sortOrder), 'active' => 1,
    ]);
    mam_audit('lms_program_create', 'program', $id, ['code' => $code, 'name' => $name]);
    return $id;
}

/** Update editable programme fields. */
function mam_lms_update_program(int $programId, array $data): void {
    $allowed = ['name', 'weeks', 'revision_week', 'exam_week', 'requires_core', 'certification', 'sort_order', 'active'];
    $validCerts = ['participation', 'completion', 'internal', 'internal_diploma'];
    $update = [];
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $data)) continue;
        if ($k === 'name' && trim((string)$data[$k]) === '') continue;
        if ($k === 'certification' && !in_array($data[$k], $validCerts, true)) continue;
        $update[$k] = $data[$k];
    }
    if (!$update) return;
    mam_db_update('programs', $update, ['id' => $programId]);
    mam_audit('lms_program_update', 'program', $programId, $update);
}

/** Courses linked to a programme (ordered by sort_order), with authored-week
 *  count at the preferred level. N+1 is fine — at most ~20 courses per programme. */
function mam_lms_program_courses(int $programId): array {
    $rows = mam_db_all(
        "SELECT c.id AS course_id, c.code, c.name, c.kind, c.active,
                pc.level_code, pc.sort_order
           FROM program_courses pc
           JOIN courses c ON c.id = pc.course_id
          WHERE pc.program_id = ?
          ORDER BY pc.sort_order, c.sort_order, c.name",
        [$programId]
    );
    foreach ($rows as &$row) {
        $lc  = $row['level_code'];
        $cid = (int)$row['course_id'];
        if ($lc !== null && $lc !== '') {
            $lev = mam_db_row('SELECT id FROM levels WHERE code = ?', [$lc]);
            $lid = $lev ? (int)$lev['id'] : null;
        } else {
            $lid = null;
        }
        $n = $lid !== null
            ? mam_db_row('SELECT COUNT(*) AS n FROM modules WHERE course_id = ? AND level_id = ?', [$cid, $lid])
            : mam_db_row('SELECT COUNT(*) AS n FROM modules WHERE course_id = ? AND level_id IS NULL', [$cid]);
        $row['module_count'] = (int)($n['n'] ?? 0);
    }
    unset($row);
    return $rows;
}

/** All programmes (ordered), each with their linked courses. */
function mam_lms_all_programs_with_courses(): array {
    $programs = mam_db_all('SELECT * FROM programs ORDER BY sort_order, name');
    foreach ($programs as &$p) {
        $p['courses'] = mam_lms_program_courses((int)$p['id']);
    }
    unset($p);
    return $programs;
}

/** Link a course to a programme with a preferred level and display order.
 *  Upserts — re-linking updates level_code + sort_order. */
function mam_lms_link_course_to_program(int $courseId, int $programId, ?string $levelCode, int $sortOrder = 99): void {
    if (!mam_db_row('SELECT id FROM courses WHERE id = ?', [$courseId]))  throw new InvalidArgumentException('course_not_found');
    if (!mam_db_row('SELECT id FROM programs WHERE id = ?', [$programId])) throw new InvalidArgumentException('program_not_found');
    $lc = ($levelCode !== null && trim($levelCode) !== '') ? trim($levelCode) : null;
    if (mam_db_row('SELECT 1 FROM program_courses WHERE program_id = ? AND course_id = ?', [$programId, $courseId])) {
        mam_db()->prepare('UPDATE program_courses SET level_code = ?, sort_order = ? WHERE program_id = ? AND course_id = ?')
                ->execute([$lc, $sortOrder, $programId, $courseId]);
    } else {
        mam_db()->prepare('INSERT INTO program_courses (program_id, course_id, level_code, sort_order) VALUES (?, ?, ?, ?)')
                ->execute([$programId, $courseId, $lc, $sortOrder]);
    }
    mam_audit('lms_link_course_program', 'program', $programId, ['course' => $courseId, 'level' => $lc]);
}

/** Remove a course from a programme. */
function mam_lms_unlink_course_from_program(int $courseId, int $programId): void {
    mam_db()->prepare('DELETE FROM program_courses WHERE program_id = ? AND course_id = ?')->execute([$programId, $courseId]);
    mam_audit('lms_unlink_course_program', 'program', $programId, ['course' => $courseId]);
}

/** Full curriculum data for a programme: each linked course with its complete
 *  week-by-week outline at the course's preferred level. Returns null for an
 *  unknown programme code. */
function mam_lms_full_program_curriculum(string $programCode): ?array {
    $prog = mam_db_row('SELECT * FROM programs WHERE code = ?', [$programCode]);
    if (!$prog) return null;
    $pc = mam_lms_program_courses((int)$prog['id']);
    $courses = [];
    foreach ($pc as $row) {
        $outline = mam_lms_course_outline((string)$row['code'], $row['level_code'] ?: null, $programCode);
        if ($outline) {
            $courses[] = array_merge(['preferred_level' => $row['level_code']], $outline);
        }
    }
    return ['program' => $prog, 'courses' => $courses, 'total_courses' => count($courses)];
}

/* ─────────────────────────── Enterprise scheduling (v45) ──────────────────
   Waitlist, bulk session generation, conflict detection, reschedule audit,
   and substitute instructor — the production-grade scheduling primitives. */

/** The enrolment statuses that OCCUPY a seat in a cohort (capacity accounting).
 *  A finished ('completed') place is free again; a pending request still holds
 *  one. Kept beside mam_lms_cohort_roster()'s set so the two never silently
 *  drift apart — see the note there. */
function mam_lms_seat_statuses(): array { return ['active', 'pending']; }

/** How full a cohort currently is.
 *  Counts DISTINCT students, not enrolment rows: one student holding two rows in
 *  the same cohort (possible via the admin link/converge paths) occupies ONE
 *  seat, and counting rows made capacity drift away from the roster.
 *  @return array{enrolled:int,capacity:int,available:int,waitlist_count:int,is_full:bool} */
function mam_lms_cohort_fill_count(int $cohortId): array {
    $co = mam_db_row('SELECT capacity FROM cohorts WHERE id = ?', [$cohortId]);
    if (!$co) throw new RuntimeException('cohort_not_found');
    $enrolled = (int)(mam_db_row(
        "SELECT COUNT(DISTINCT student_id) AS n FROM course_enrollments
          WHERE cohort_id = ? AND status IN ('active','pending')",
        [$cohortId]
    )['n'] ?? 0);
    $capacity  = $co['capacity'] !== null ? (int)$co['capacity'] : 999;
    $waitlist  = (int)(mam_db_row(
        "SELECT COUNT(*) AS n FROM class_waitlist WHERE cohort_id = ? AND status = 'waiting'",
        [$cohortId]
    )['n'] ?? 0);
    $available = max(0, $capacity - $enrolled);
    return [
        'enrolled'       => $enrolled,
        'capacity'       => $capacity,
        'available'      => $available,
        'waitlist_count' => $waitlist,
        'is_full'        => $available === 0,
    ];
}

/** Add a student to the waitlist for a cohort. Idempotent if already waiting.
 *  Returns position (1-based). Throws cohort_not_found / already_enrolled. */
function mam_lms_waitlist_add(int $cohortId, int $studentId): int {
    if (!mam_db_row('SELECT id FROM cohorts WHERE id = ?', [$cohortId])) throw new RuntimeException('cohort_not_found');
    if (mam_db_row(
        "SELECT id FROM course_enrollments WHERE cohort_id = ? AND student_id = ? AND status IN ('active','pending')",
        [$cohortId, $studentId]
    )) throw new RuntimeException('already_enrolled');

    // The read-MAX(position)-then-insert below must be atomic: two concurrent
    // joins would otherwise compute the same position and corrupt the ordering.
    // Wrap it in a transaction (house pattern — see wallet.php), tolerating an
    // outer transaction the caller may already hold.
    $db  = mam_db();
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $existing = mam_db_row(
            'SELECT id, position, status FROM class_waitlist WHERE cohort_id = ? AND student_id = ?',
            [$cohortId, $studentId]
        );
        if ($existing && (string)$existing['status'] === 'waiting') {
            if ($own) $db->commit();
            return (int)$existing['position'];
        }

        $nextPos = (int)(mam_db_row(
            "SELECT IFNULL(MAX(position), 0) + 1 AS p FROM class_waitlist WHERE cohort_id = ? AND status = 'waiting'",
            [$cohortId]
        )['p'] ?? 1);
        $now = date('Y-m-d H:i:s');
        if ($existing) {
            mam_db_update('class_waitlist',
                ['position' => $nextPos, 'status' => 'waiting', 'updated_at' => $now],
                ['id' => (int)$existing['id']]
            );
        } else {
            mam_db_insert('class_waitlist', [
                'student_id' => $studentId,
                'cohort_id'  => $cohortId,
                'position'   => $nextPos,
                'status'     => 'waiting',
            ]);
        }
        if ($own) $db->commit();
    } catch (\Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    mam_audit('lms_waitlist_add', 'cohort', $cohortId, ['student' => $studentId, 'position' => $nextPos]);
    return $nextPos;
}

/** Remove a student from the waiting list. Re-numbers remaining positions. */
function mam_lms_waitlist_remove(int $cohortId, int $studentId): void {
    $row = mam_db_row(
        "SELECT id, position FROM class_waitlist WHERE cohort_id = ? AND student_id = ? AND status = 'waiting'",
        [$cohortId, $studentId]
    );
    if (!$row) return;
    mam_db_update('class_waitlist',
        ['status' => 'withdrawn', 'updated_at' => date('Y-m-d H:i:s')],
        ['id' => (int)$row['id']]
    );
    $pos = (int)$row['position'];
    mam_db()->prepare(
        "UPDATE class_waitlist SET position = position - 1 WHERE cohort_id = ? AND status = 'waiting' AND position > ?"
    )->execute([$cohortId, $pos]);
    mam_audit('lms_waitlist_remove', 'cohort', $cohortId, ['student' => $studentId]);
}

/**
 * Promote the next waiting student into the cohort.
 *
 * Returns the promoted student_id, or null ONLY when the waitlist is empty.
 * Every other outcome throws, so a caller can never mistake a silent no-op for
 * success (both of these used to consume the waitlist entry, or look like an
 * empty list, while enrolling nobody):
 *   'cohort_full'        — at capacity and $force not set
 *   'no_course_mapping'  — the cohort maps to no course, so nothing can be
 *                          enrolled; the student stays queued
 *   'enrolled_elsewhere' — the student's live enrolment for this course+level
 *                          belongs to a DIFFERENT cohort; the student stays
 *                          queued rather than being quietly dropped
 */
function mam_lms_waitlist_promote(int $cohortId, ?int $actorId = null, bool $force = false): ?int {
    $next = mam_db_row(
        "SELECT id, student_id, position FROM class_waitlist WHERE cohort_id = ? AND status = 'waiting' ORDER BY position ASC LIMIT 1",
        [$cohortId]
    );
    if (!$next) return null;
    $studentId = (int)$next['student_id'];
    $co = mam_db_row('SELECT course_id, level_id, program_id, instructor_id FROM cohorts WHERE id = ?', [$cohortId]);
    if (!$co) throw new RuntimeException('cohort_not_found');
    if (!$co['course_id']) throw new RuntimeException('no_course_mapping');
    if (!$force && mam_lms_cohort_fill_count($cohortId)['available'] < 1) {
        throw new RuntimeException('cohort_full');
    }
    $courseId  = (int)$co['course_id'];
    $levelId   = $co['level_id']      !== null ? (int)$co['level_id']      : null;
    $programId = $co['program_id']    !== null ? (int)$co['program_id']    : null;
    $instrId   = $co['instructor_id'] !== null ? (int)$co['instructor_id'] : null;

    // Enrol the promoted student directly into THIS cohort. (The old code called
    // mam_lms_add_course with the cohort_id in the courseCode slot — a type/arg
    // mismatch that fatally errored under strict_types AND would have resolved
    // the wrong cohort. We mirror the converge path instead.) Bare level_id
    // compare so INTEGER affinity coerces the bound param (see mam_lms_add_course).
    if ($levelId === null) {
        $existing = mam_db_row("SELECT id, cohort_id FROM course_enrollments WHERE student_id = ? AND course_id = ? AND level_id IS NULL AND status IN ('pending','active')", [$studentId, $courseId]);
    } else {
        $existing = mam_db_row("SELECT id, cohort_id FROM course_enrollments WHERE student_id = ? AND course_id = ? AND level_id = ? AND status IN ('pending','active')", [$studentId, $courseId, $levelId]);
    }
    if ($existing) {
        // Already enrolled in this course+level. A floating enrolment is adopted
        // by this cohort; one that already belongs to ANOTHER cohort is left
        // alone and reported — silently marking the waitlist entry 'promoted'
        // there would strike the student off the queue without enrolling them.
        if (empty($existing['cohort_id'])) {
            mam_lms_link_enrollment_cohort((int)$existing['id'], $cohortId);
        } elseif ((int)$existing['cohort_id'] !== $cohortId) {
            throw new RuntimeException('enrolled_elsewhere');
        }
        // else: already in this cohort — fall through and clear the queue entry.
    } else {
        $enrId = mam_db_insert('course_enrollments', [
            'student_id'    => $studentId,
            'course_id'     => $courseId,
            'level_id'      => $levelId,
            'program_id'    => $programId,
            'instructor_id' => $instrId,
            'cohort_id'     => $cohortId,
            'status'        => 'active',
            'approved_by'   => $actorId,
            'approved_at'   => date('Y-m-d H:i:s'),
        ]);
        mam_lms_log_event($enrId, null, 'active', $actorId, 'waitlist_promoted');
    }
    $now = date('Y-m-d H:i:s');
    // Stamp notified_at as we tell them — the column existed from v45 but nothing
    // ever wrote it, so a promotion was silent and students were never informed
    // a place had opened.
    mam_db_update('class_waitlist', ['status' => 'promoted', 'notified_at' => $now, 'updated_at' => $now], ['id' => (int)$next['id']]);
    // Close the gap left by the promoted entry — same renumbering rule as
    // mam_lms_waitlist_remove(), anchored on the vacated position so a queue
    // that does not start at 1 can never be pushed to 0 or below.
    mam_db()->prepare(
        "UPDATE class_waitlist SET position = position - 1 WHERE cohort_id = ? AND status = 'waiting' AND position > ?"
    )->execute([$cohortId, (int)$next['position']]);
    mam_lms_notify_waitlist_promoted($cohortId, $studentId);
    mam_audit('lms_waitlist_promote', 'cohort', $cohortId, ['student' => $studentId, 'by' => $actorId]);
    return $studentId;
}

/** Tell a student a place opened up for them. Best-effort and never throws — a
 *  mail or notification hiccup must not roll back a completed promotion. */
function mam_lms_notify_waitlist_promoted(int $cohortId, int $studentId): void {
    try {
        $co    = mam_db_row('SELECT title FROM cohorts WHERE id = ?', [$cohortId]);
        $what  = trim((string)($co['title'] ?? '')) ?: 'your class';
        if (function_exists('mam_notify')) {
            mam_notify($studentId, 'waitlist.promoted', 'A place opened up 🎉',
                'You\'re off the waitlist for ' . $what . ' — you\'re now enrolled.', '/portal/student/');
        }
        $u = mam_db_row('SELECT name, email FROM users WHERE id = ?', [$studentId]);
        if ($u && !empty($u['email']) && function_exists('mam_send') && function_exists('mam_email_enrolled')) {
            $loginUrl = rtrim((string)mam_env('APP_URL', ''), '/') . '/portal/login';
            mam_send((string)$u['email'], 'A place opened up — you\'re enrolled',
                mam_email_enrolled((string)($u['name'] ?? ''), $what, '', $loginUrl),
                'waitlist_promoted', true);
        }
    } catch (Throwable $e) {
        if (function_exists('mam_log')) mam_log('warn', 'waitlist_notify_failed', ['cohort' => $cohortId, 'student' => $studentId, 'err' => $e->getMessage()]);
    }
}

/** Waitlist entry for one student in one cohort. Null if not on list. */
function mam_lms_waitlist_status(int $cohortId, int $studentId): ?array {
    return mam_db_row(
        'SELECT position, status, created_at FROM class_waitlist WHERE cohort_id = ? AND student_id = ?',
        [$cohortId, $studentId]
    ) ?: null;
}

/** All waiting students for a cohort (admin view). */
function mam_lms_waitlist_for_cohort(int $cohortId): array {
    return mam_db_all(
        "SELECT wl.id, wl.position, wl.status, wl.created_at, wl.notified_at,
                u.id AS student_id, u.name AS student_name, u.email AS student_email
           FROM class_waitlist wl JOIN users u ON u.id = wl.student_id
          WHERE wl.cohort_id = ? AND wl.status = 'waiting'
          ORDER BY wl.position ASC",
        [$cohortId]
    );
}

/** All cohorts a student is currently waiting for. */
function mam_lms_student_waitlists(int $studentId): array {
    return mam_db_all(
        "SELECT wl.cohort_id, wl.position, wl.status, wl.created_at,
                co.title AS cohort_title, c.name AS course_name, l.name AS level_name
           FROM class_waitlist wl
           JOIN cohorts co       ON co.id = wl.cohort_id
           LEFT JOIN courses c   ON c.id  = co.course_id
           LEFT JOIN levels  l   ON l.id  = co.level_id
          WHERE wl.student_id = ? AND wl.status = 'waiting'
          ORDER BY wl.created_at ASC",
        [$studentId]
    );
}

/** Bulk-create sessions for a cohort from a recurrence rule. Returns created IDs.
 *
 *  Rule keys: start_date, end_date, day_of_week (int|int[], 0=Sun..6=Sat),
 *  time_of_day (HH:MM), duration_min, kind, title_prefix, location, room,
 *  skip_dates (string[]).  Hard-capped at 104 sessions per call.
 *
 *  Returns ['session_ids' => int[], 'conflicts' => array[]] — conflicts is a
 *  warn-only list of proposed slots that overlap an existing session on the
 *  same instructor or room (creation is not blocked; the UI surfaces them). */
function mam_lms_bulk_create_sessions(int $cohortId, array $rule, int $actorId): array {
    $co = mam_db_row('SELECT id, instructor_id FROM cohorts WHERE id = ?', [$cohortId]);
    if (!$co) throw new RuntimeException('cohort_not_found');
    try {
        $start = new DateTimeImmutable($rule['start_date'] ?? 'now');
        $end   = new DateTimeImmutable($rule['end_date']   ?? 'now');
    } catch (\Throwable $e) {
        throw new InvalidArgumentException('start_date and end_date must be valid dates');
    }
    if ($end < $start) throw new InvalidArgumentException('end_date must be >= start_date');

    $rawDays = $rule['day_of_week'] ?? [];
    if (!is_array($rawDays)) $rawDays = [$rawDays];
    $days = array_values(array_unique(
        array_filter(array_map('intval', $rawDays), fn($d) => $d >= 0 && $d <= 6)
    ));
    if (empty($days)) throw new InvalidArgumentException('at least one day_of_week required');

    $timeOfDay   = preg_match('/^\d{2}:\d{2}$/', (string)($rule['time_of_day'] ?? '')) ? (string)$rule['time_of_day'] : '09:00';
    $durationMin = isset($rule['duration_min']) ? max(1, (int)$rule['duration_min']) : 60;
    $kind        = (string)($rule['kind'] ?? 'class');
    if (!in_array($kind, ['class', 'exam', 'revision', 'makeup'], true)) $kind = 'class';
    $titlePrefix = isset($rule['title_prefix']) ? trim((string)$rule['title_prefix']) : '';
    $location    = (isset($rule['location']) && $rule['location'] !== '') ? (string)$rule['location'] : null;
    $room        = (isset($rule['room'])     && $rule['room']     !== '') ? (string)$rule['room']     : null;
    $skipDates   = isset($rule['skip_dates']) && is_array($rule['skip_dates'])
        ? array_fill_keys($rule['skip_dates'], true) : [];

    $instructorId = $co['instructor_id'] !== null ? (int)$co['instructor_id'] : 0;
    $ids = [];
    $conflicts = [];
    $cur = $start;
    $num = 1;
    while ($cur <= $end && count($ids) < 104) {
        $dow = (int)$cur->format('w');
        $ds  = $cur->format('Y-m-d');
        if (in_array($dow, $days, true) && !isset($skipDates[$ds])) {
            $scheduledAt = $ds . ' ' . $timeOfDay . ':00';
            // Warn (don't block) on instructor/room clashes for this slot. Run
            // BEFORE inserting so sibling slots in this same batch aren't flagged.
            if ($instructorId > 0 || $room !== null) {
                $hit = mam_lms_detect_conflicts($instructorId, $scheduledAt, $durationMin, null, $room);
                if ($hit) $conflicts[] = ['date' => $ds, 'scheduled_at' => $scheduledAt, 'with' => $hit];
            }
            $id = mam_lms_create_session($cohortId, [
                'title'        => $titlePrefix !== '' ? $titlePrefix . ' ' . $num : null,
                'scheduled_at' => $scheduledAt,
                'duration_min' => $durationMin,
                'kind'         => $kind,
                'location'     => $location,
                'room'         => $room,
                'created_by'   => $actorId,
            ]);
            $ids[] = $id;
            $num++;
        }
        $cur = $cur->modify('+1 day');
    }
    mam_audit('lms_bulk_create_sessions', 'cohort', $cohortId, ['count' => count($ids), 'conflicts' => count($conflicts), 'by' => $actorId]);
    return ['session_ids' => $ids, 'conflicts' => $conflicts];
}

/** Preview dates (Y-m-d) that a bulk rule would generate — no DB writes. */
function mam_lms_bulk_sessions_preview(array $rule): array {
    try {
        $start = new DateTimeImmutable($rule['start_date'] ?? 'now');
        $end   = new DateTimeImmutable($rule['end_date']   ?? 'now');
    } catch (\Throwable $e) { return []; }
    if ($end < $start) return [];
    $rawDays = $rule['day_of_week'] ?? [];
    if (!is_array($rawDays)) $rawDays = [$rawDays];
    $days = array_values(array_unique(array_filter(array_map('intval', $rawDays), fn($d) => $d >= 0 && $d <= 6)));
    $skipDates = isset($rule['skip_dates']) && is_array($rule['skip_dates'])
        ? array_fill_keys($rule['skip_dates'], true) : [];
    $dates = [];
    $cur = $start;
    while ($cur <= $end && count($dates) < 104) {
        $ds = $cur->format('Y-m-d');
        if (in_array((int)$cur->format('w'), $days, true) && !isset($skipDates[$ds])) $dates[] = $ds;
        $cur = $cur->modify('+1 day');
    }
    return $dates;
}

/** Detect scheduling conflicts (overlapping scheduled sessions).
 *  Flags a session that overlaps in time AND clashes on a resource:
 *    - the same instructor (as cohort owner, creator, OR substitute), or
 *    - the same $room (when a room is given).
 *  Returns array of conflicting sessions — empty = safe to schedule. */
function mam_lms_detect_conflicts(int $instructorId, string $scheduledAt, int $durationMin, ?int $excludeSessionId = null, ?string $room = null): array {
    if (!$scheduledAt) return [];
    $endAt = date('Y-m-d H:i:s', strtotime($scheduledAt) + $durationMin * 60);
    $sessEndExpr = mam_db_driver() === 'mysql'
        ? 'DATE_ADD(cs.scheduled_at, INTERVAL cs.duration_min MINUTE)'
        : "datetime(cs.scheduled_at, '+' || cs.duration_min || ' minutes')";

    // Resource-clash clause: same instructor (owner/creator/substitute) or, when
    // a room is supplied, the same room (so two cohorts can't share a room+time).
    $room = ($room !== null && trim($room) !== '') ? trim($room) : null;
    $clash = 'co.instructor_id = ? OR cs.created_by = ? OR cs.substitute_id = ?';
    $args  = [$instructorId, $instructorId, $instructorId];
    if ($room !== null) { $clash .= ' OR (cs.room IS NOT NULL AND cs.room = ?)'; $args[] = $room; }

    $sql  = "SELECT cs.id, cs.title, cs.scheduled_at, cs.duration_min, cs.room, co.title AS cohort_title
               FROM class_sessions cs
               JOIN cohorts co ON co.id = cs.cohort_id
              WHERE ($clash)
                AND cs.status = 'scheduled'
                AND cs.scheduled_at IS NOT NULL
                AND cs.scheduled_at < ?
                AND $sessEndExpr > ?";
    $args[] = $endAt;
    $args[] = $scheduledAt;
    if ($excludeSessionId !== null) { $sql .= ' AND cs.id != ?'; $args[] = $excludeSessionId; }
    return mam_db_all($sql, $args);
}

/** Reschedule a session: move scheduled_at and write an audit entry.
 *  Throws session_not_found | illegal_transition (must be status=scheduled). */
function mam_lms_reschedule_session(int $sessionId, string $newScheduledAt, ?string $reason, int $actorId): void {
    $sess = mam_db_row('SELECT id, scheduled_at, status FROM class_sessions WHERE id = ?', [$sessionId]);
    if (!$sess) throw new RuntimeException('session_not_found');
    if ((string)$sess['status'] !== 'scheduled') throw new RuntimeException('illegal_transition');
    $old = $sess['scheduled_at'];
    mam_db_update('class_sessions', ['scheduled_at' => $newScheduledAt], ['id' => $sessionId]);
    mam_db_insert('session_history', [
        'session_id'    => $sessionId,
        'field_changed' => 'scheduled_at',
        'old_value'     => $old,
        'new_value'     => $newScheduledAt,
        'reason'        => $reason,
        'changed_by'    => $actorId,
    ]);
    mam_audit('lms_session_reschedule', 'class_session', $sessionId, ['from' => $old, 'to' => $newScheduledAt, 'by' => $actorId]);
}

/** Assign or clear the substitute instructor for a session. */
function mam_lms_set_session_substitute(int $sessionId, ?int $subId, int $actorId): void {
    $sess = mam_db_row('SELECT id, substitute_id FROM class_sessions WHERE id = ?', [$sessionId]);
    if (!$sess) throw new RuntimeException('session_not_found');
    mam_db_update('class_sessions', ['substitute_id' => $subId], ['id' => $sessionId]);
    mam_db_insert('session_history', [
        'session_id'    => $sessionId,
        'field_changed' => 'substitute_id',
        'old_value'     => $sess['substitute_id'] !== null ? (string)$sess['substitute_id'] : null,
        'new_value'     => $subId !== null ? (string)$subId : null,
        'reason'        => null,
        'changed_by'    => $actorId,
    ]);
    mam_audit('lms_session_substitute', 'class_session', $sessionId, ['sub' => $subId, 'by' => $actorId]);
}

/** Full change history for a session, newest first. */
function mam_lms_session_history(int $sessionId): array {
    return mam_db_all(
        "SELECT sh.*, u.name AS changed_by_name
           FROM session_history sh LEFT JOIN users u ON u.id = sh.changed_by
          WHERE sh.session_id = ? ORDER BY sh.changed_at DESC",
        [$sessionId]
    );
}

/** All sessions across cohorts in a date range — admin schedule overview.
 *  Returns sessions enriched with cohort/instructor/substitute info. */
function mam_lms_schedule_range(string $from, string $to): array {
    return mam_db_all(
        "SELECT cs.id, cs.title, cs.scheduled_at, cs.duration_min, cs.kind, cs.status, cs.room, cs.location,
                co.id AS cohort_id, co.title AS cohort_title,
                c.name AS course_name, l.name AS level_name,
                u.name AS instructor_name,
                su.name AS substitute_name
           FROM class_sessions cs
           JOIN cohorts co       ON co.id  = cs.cohort_id
           LEFT JOIN courses c   ON c.id   = co.course_id
           LEFT JOIN levels  l   ON l.id   = co.level_id
           LEFT JOIN users   u   ON u.id   = co.instructor_id
           LEFT JOIN users   su  ON su.id  = cs.substitute_id
          WHERE cs.scheduled_at >= ? AND cs.scheduled_at <= ?
          ORDER BY cs.scheduled_at ASC, cs.id ASC",
        [$from . ' 00:00:00', $to . ' 23:59:59']
    );
}

/** Update a cohort's structured schedule fields. */
function mam_lms_update_cohort_schedule(int $cohortId, array $data): void {
    if (!mam_db_row('SELECT id FROM cohorts WHERE id = ?', [$cohortId])) throw new RuntimeException('cohort_not_found');
    $upd = [];
    if (array_key_exists('schedule_days', $data))    $upd['schedule_days']    = $data['schedule_days']    !== '' ? (string)$data['schedule_days']    : null;
    if (array_key_exists('schedule_time', $data))    $upd['schedule_time']    = preg_match('/^\d{2}:\d{2}$/', (string)($data['schedule_time'] ?? '')) ? (string)$data['schedule_time'] : null;
    if (array_key_exists('session_duration', $data)) $upd['session_duration'] = max(1, (int)$data['session_duration']);
    if (!empty($upd)) mam_db_update('cohorts', $upd, ['id' => $cohortId]);
}
