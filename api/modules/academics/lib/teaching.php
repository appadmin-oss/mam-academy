<?php
/**
 * Teaching — the ONE answer to "does this instructor teach this class / student?"
 *
 * THE RULE: who teaches a class lives in `cohort_staff` (schema v111): a LEAD,
 * any ASSISTANTS, and COVER teachers — a cover either for one session
 * (`session_id`) or for a date window. Every row has dates; ending an
 * assignment closes the row (ends_on + ended_reason) and never deletes it, so
 * past work keeps the teacher who marked it.
 *
 * One more source, narrower on purpose: an enrolment's own `instructor_id`
 * names the teacher of THAT student (a 1:1 placement, or the teacher picked at
 * approval). It reaches that one student and never the class.
 *
 * Before this (2026-11-28 classes audit) "teaches" was written ~41 times across
 * 19 files with six different rules. A cover teacher could open the video call
 * but not take the register; a teacher named on one student's enrolment owned
 * the whole class, and kept it after that student withdrew; changing a class's
 * teacher left the old one with access.
 *
 *   mam_teaches($instr, $cohort, $at, $purpose)          the class, at a moment
 *   mam_teaches_student($instr, $student, $course, ...)  one student
 *   mam_instructor_cohort_ids($instr, $at, $purpose)     every class they teach
 *   mam_teaching_cohort_sql($col, $instr, ...)           the same, as SQL
 *   mam_enrolled_in($student, $cohort)                    a teachable enrolment
 *   mam_cohort_set_lead / mam_cohort_staff_add / mam_cohort_staff_end   the only writers
 *
 * $purpose: 'teach' (current access: register, marking, roster) or 'history'
 * (any assignment ever: read-only past work). Admins are NOT special-cased
 * here — every caller keeps its own admin rule.
 *
 * Compatibility columns kept in step, still read by older code and reports:
 * cohorts.instructor_id = the current lead; class_sessions.substitute_id = the
 * cover for that session.
 */
declare(strict_types=1);

/** Enrolment statuses a teacher sees and marks. (Seats are lms.php's own rule.) */
const MAM_ENROLMENT_TEACHABLE = ['active', 'completed'];
const MAM_STAFF_ROLES = ['lead', 'assistant', 'cover'];

/** Today's Lagos date for $at (null = now). */
function mam_teaching_day(?int $at): string {
    return function_exists('mam_session_today') ? mam_session_today($at ?? time()) : gmdate('Y-m-d', $at ?? time());
}

/**
 * The staff rows that give $instructorId access at $at. A lead/assistant row
 * is live when its date window covers the Lagos day; a cover row tied to a
 * session is live on that session's day (its starts_on/ends_on carry it).
 * 'history' = every row ever.
 */
function mam_teaching_rows(int $instructorId, ?int $at = null, string $purpose = 'teach', ?int $cohortId = null): array {
    if ($instructorId <= 0) return [];
    $sql = 'SELECT id, cohort_id, role, session_id, starts_on, ends_on FROM cohort_staff WHERE instructor_id = ?';
    $args = [$instructorId];
    if ($cohortId !== null) { $sql .= ' AND cohort_id = ?'; $args[] = $cohortId; }
    if ($purpose !== 'history') {
        $day = mam_teaching_day($at);
        $sql .= ' AND (starts_on IS NULL OR starts_on <= ?) AND (ends_on IS NULL OR ends_on >= ?)';
        $args[] = $day; $args[] = $day;
    }
    try { $rows = mam_db_all($sql, $args); } catch (Throwable $e) { $rows = []; }
    /* Compatibility: a class whose cohorts.instructor_id names this instructor
       and that has NO lead row yet (written by a path that predates v111, or
       between the migration and the backfill) counts as theirs as lead. Once a
       lead row exists, cohort_staff alone decides — so a replaced lead loses
       access the moment mam_cohort_set_lead() ends their row. */
    $csql = "SELECT co.id AS cohort_id FROM cohorts co WHERE co.instructor_id = ?
              AND NOT EXISTS (SELECT 1 FROM cohort_staff x WHERE x.cohort_id = co.id AND x.role = 'lead')";
    $cargs = [$instructorId];
    if ($cohortId !== null) { $csql .= ' AND co.id = ?'; $cargs[] = $cohortId; }
    try {
        foreach (mam_db_all($csql, $cargs) as $c) {
            $rows[] = ['id' => null, 'cohort_id' => $c['cohort_id'], 'role' => 'lead', 'session_id' => null, 'starts_on' => null, 'ends_on' => null];
        }
    } catch (Throwable $e) {}
    return $rows;
}

/** Does $instructorId teach cohort $cohortId at $at? */
function mam_teaches(int $instructorId, int $cohortId, ?int $at = null, string $purpose = 'teach'): bool {
    if ($instructorId <= 0 || $cohortId <= 0) return false;
    return (bool) mam_teaching_rows($instructorId, $at, $purpose, $cohortId);
}

/** Every cohort $instructorId teaches at $at (or ever, for 'history'). */
function mam_instructor_cohort_ids(int $instructorId, ?int $at = null, string $purpose = 'teach'): array {
    $ids = [];
    foreach (mam_teaching_rows($instructorId, $at, $purpose) as $r) $ids[(int) $r['cohort_id']] = true;
    return array_keys($ids);
}

/**
 * The same set as a SQL fragment, for list queries: [$sql, $args], e.g.
 * ['co.id IN (?,?)', [3, 7]] — or ['1 = 0', []] when they teach nothing.
 */
function mam_teaching_cohort_sql(string $cohortColumn, int $instructorId, ?int $at = null, string $purpose = 'teach'): array {
    $ids = mam_instructor_cohort_ids($instructorId, $at, $purpose);
    if (!$ids) return ['1 = 0', []];
    return [$cohortColumn . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

/**
 * "This enrolment row is one $instructorId teaches", as SQL for a query that
 * already has the enrolment as $alias: [$sql, $args] — the enrolment names them
 * (that student only) OR its cohort is one they teach. Replaces the
 * `(ce.instructor_id = ? OR co.instructor_id = ?)` idiom, which read the class's
 * teacher from the compatibility column and missed assistants and covers.
 */
function mam_teaching_enrolment_sql(string $alias, int $instructorId, ?int $at = null, string $purpose = 'teach'): array {
    [$cs, $ca] = mam_teaching_cohort_sql($alias . '.cohort_id', $instructorId, $at, $purpose);
    return ['(' . $alias . '.instructor_id = ? OR ' . $cs . ')', array_merge([$instructorId], $ca)];
}

/**
 * "This session is one $instructorId teaches", as SQL on a class_sessions alias:
 * every session of a class they lead or assist (or cover by date window), plus
 * the ONE session a session-cover is for. A cover therefore sees their session,
 * not the whole class's timetable.
 */
function mam_teaching_session_sql(string $csAlias, int $instructorId, ?int $at = null): array {
    $whole = []; $sessions = [];
    foreach (mam_teaching_rows($instructorId, $at, 'teach') as $r) {
        if ($r['role'] === 'cover' && $r['session_id'] !== null) $sessions[(int) $r['session_id']] = true;
        else $whole[(int) $r['cohort_id']] = true;
    }
    // A session-cover is also listed by the compatibility column, whose date the
    // row's window may not cover yet (a cover booked for next week).
    $parts = []; $args = [];
    if ($whole) { $parts[] = $csAlias . '.cohort_id IN (' . implode(',', array_fill(0, count($whole), '?')) . ')'; $args = array_merge($args, array_keys($whole)); }
    if ($sessions) { $parts[] = $csAlias . '.id IN (' . implode(',', array_fill(0, count($sessions), '?')) . ')'; $args = array_merge($args, array_keys($sessions)); }
    $parts[] = $csAlias . '.substitute_id = ?'; $args[] = $instructorId;
    return ['(' . implode(' OR ', $parts) . ')', $args];
}

/**
 * May $instructorId act on this SESSION (take its register, open its call)?
 * The cover booked for it (until that booking is ended), or anybody who teaches
 * the class at $at. A cover used to be able to open the call and not take the
 * register they were brought in for.
 */
function mam_teaches_session(int $instructorId, int $sessionId, ?int $at = null): bool {
    if ($instructorId <= 0 || $sessionId <= 0) return false;
    $s = mam_db_row('SELECT id, cohort_id, substitute_id FROM class_sessions WHERE id = ?', [$sessionId]);
    if (!$s) return false;
    if (mam_db_row("SELECT 1 FROM cohort_staff WHERE session_id = ? AND instructor_id = ? AND role = 'cover' AND ended_reason IS NULL",
                   [$sessionId, $instructorId])) return true;
    // Compatibility: a substitute set before v111 and not yet backfilled.
    if ((int) ($s['substitute_id'] ?? 0) === $instructorId
        && !mam_db_row("SELECT 1 FROM cohort_staff WHERE session_id = ? AND role = 'cover'", [$sessionId])) return true;
    return $s['cohort_id'] !== null && mam_teaches($instructorId, (int) $s['cohort_id'], $at);
}

/** Is $studentId enrolled in $cohortId on a teachable status? */
function mam_enrolled_in(int $studentId, int $cohortId): bool {
    $ph = implode(',', array_fill(0, count(MAM_ENROLMENT_TEACHABLE), '?'));
    return (bool) mam_db_row(
        "SELECT 1 FROM course_enrollments WHERE student_id = ? AND cohort_id = ? AND status IN ($ph) LIMIT 1",
        array_merge([$studentId, $cohortId], MAM_ENROLMENT_TEACHABLE));
}

/**
 * Does $instructorId teach $studentId (optionally on $courseId)?
 * True when the student has a teachable enrolment in a class the instructor
 * teaches, OR the enrolment itself names this instructor (that student only).
 * $vetted applies the safeguarding read gate (mam_vetting_may_see_child) the
 * way the readers that guard a child's record always have; 'warn' mode lets
 * everybody through, exactly as before.
 */
function mam_teaches_student(int $instructorId, int $studentId, ?int $courseId = null, string $purpose = 'teach', bool $vetted = false, ?int $at = null): bool {
    if ($instructorId <= 0 || $studentId <= 0) return false;
    $ph = implode(',', array_fill(0, count(MAM_ENROLMENT_TEACHABLE), '?'));
    $statusSql = $purpose === 'history' ? '1 = 1' : "ce.status IN ($ph)";
    $args = [$studentId];
    $sql = "SELECT ce.cohort_id, ce.instructor_id FROM course_enrollments ce WHERE ce.student_id = ? AND $statusSql";
    if ($purpose !== 'history') $args = array_merge($args, MAM_ENROLMENT_TEACHABLE);
    if ($courseId !== null) { $sql .= ' AND ce.course_id = ?'; $args[] = $courseId; }
    $hit = false;
    foreach (mam_db_all($sql, $args) as $e) {
        if ((int) ($e['instructor_id'] ?? 0) === $instructorId) { $hit = true; break; }
        if ($e['cohort_id'] !== null && mam_teaches($instructorId, (int) $e['cohort_id'], $at, $purpose)) { $hit = true; break; }
    }
    if (!$hit) return false;
    if ($vetted && function_exists('mam_vetting_may_see_child') && !mam_vetting_may_see_child($instructorId, $studentId)) return false;
    return true;
}

/** Every student $instructorId teaches now: [student_id => true]. Class rosters plus their 1:1 enrolments. */
function mam_instructor_student_ids(int $instructorId, ?int $courseId = null, ?int $at = null): array {
    $ph = implode(',', array_fill(0, count(MAM_ENROLMENT_TEACHABLE), '?'));
    [$cs, $ca] = mam_teaching_cohort_sql('ce.cohort_id', $instructorId, $at);
    $sql = "SELECT DISTINCT ce.student_id FROM course_enrollments ce
             WHERE ce.status IN ($ph) AND ($cs OR ce.instructor_id = ?)";
    $args = array_merge(MAM_ENROLMENT_TEACHABLE, $ca, [$instructorId]);
    if ($courseId !== null) { $sql .= ' AND ce.course_id = ?'; $args[] = $courseId; }
    $out = [];
    foreach (mam_db_all($sql, $args) as $r) $out[(int) $r['student_id']] = true;
    return $out;
}

/** A cohort's staff, with names. Current rows only unless $includeEnded. */
function mam_cohort_staff(int $cohortId, bool $includeEnded = false): array {
    $sql = "SELECT s.*, u.name AS instructor_name, u.email AS instructor_email, cs.scheduled_at AS session_at
              FROM cohort_staff s JOIN users u ON u.id = s.instructor_id
              LEFT JOIN class_sessions cs ON cs.id = s.session_id
             WHERE s.cohort_id = ?";
    $args = [$cohortId];
    if (!$includeEnded) { $sql .= ' AND (s.ends_on IS NULL OR s.ends_on >= ?)'; $args[] = mam_teaching_day(null); }
    $sql .= " ORDER BY CASE s.role WHEN 'lead' THEN 0 WHEN 'assistant' THEN 1 ELSE 2 END, s.ends_on IS NOT NULL, s.id";
    return mam_db_all($sql, $args);
}

/** The cohort's current lead instructor id, or null. */
function mam_cohort_lead_id(int $cohortId): ?int {
    $r = mam_db_row("SELECT instructor_id FROM cohort_staff WHERE cohort_id = ? AND role = 'lead'
                      AND (ends_on IS NULL OR ends_on >= ?) ORDER BY id DESC LIMIT 1", [$cohortId, mam_teaching_day(null)]);
    if ($r) return (int) $r['instructor_id'];
    $c = mam_db_row("SELECT instructor_id FROM cohorts co WHERE co.id = ? AND co.instructor_id IS NOT NULL
                      AND NOT EXISTS (SELECT 1 FROM cohort_staff x WHERE x.cohort_id = co.id AND x.role = 'lead')", [$cohortId]);
    return $c ? (int) $c['instructor_id'] : null;
}

/** Is $userId an active instructor (primary or secondary role)? */
function mam_teaching_is_instructor(int $userId): bool {
    $u = mam_db_row("SELECT id, role, status FROM users WHERE id = ?", [$userId]);
    return $u && ($u['status'] ?? '') === 'active' && (($u['role'] ?? '') === 'instructor'
        || mam_db_row("SELECT 1 AS x FROM user_roles WHERE user_id = ? AND role = 'instructor'", [$userId]));
}

/**
 * Make $instructorId the class's lead (null = no lead). The previous lead's row
 * is ENDED (yesterday, 'replaced'), not deleted, so their past work keeps them.
 * Runs the safeguarding assignment check (refuses under `enforce`, warns
 * otherwise). Pending/active enrolments that named the old lead (or nobody)
 * move to the new one; completed and withdrawn enrolments keep their history.
 * cohorts.instructor_id follows (compatibility). Audited.
 */
function mam_cohort_set_lead(int $cohortId, ?int $instructorId, ?int $by): array {
    $c = mam_db_row('SELECT id, course_id, level_id, instructor_id FROM cohorts WHERE id = ?', [$cohortId]);
    if (!$c) throw new RuntimeException('cohort_not_found');
    $old = mam_cohort_lead_id($cohortId) ?? ((int) ($c['instructor_id'] ?? 0) ?: null);
    $new = ($instructorId !== null && $instructorId > 0) ? $instructorId : null;
    $warning = '';
    if ($new !== null) {
        if (!mam_teaching_is_instructor($new)) throw new RuntimeException('instructor_not_found');
        if (function_exists('mam_vetting_check_assignment')) {
            $chk = mam_vetting_check_assignment($new, $cohortId);
            if (!$chk['allow']) throw new RuntimeException('vetting_required: ' . $chk['reason']);
            $warning = (string) $chk['reason'];
        }
    }
    $hasRow = $new !== null && (bool) mam_db_row("SELECT 1 FROM cohort_staff WHERE cohort_id = ? AND instructor_id = ? AND role = 'lead'
                                                     AND (ends_on IS NULL OR ends_on >= ?)", [$cohortId, $new, mam_teaching_day(null)]);
    if ($new === $old && ($new === null || $hasRow)) {
        return ['from' => $old, 'to' => $new, 'moved' => 0, 'warning' => $warning, 'changed' => false];
    }
    $today = mam_teaching_day(null);
    $yesterday = mam_teaching_day(time() - 86400);
    // A lead known only from cohorts.instructor_id (no row yet) gets one now,
    // ended below with the rest, so their past work stays theirs ('history').
    if ($old !== null && !mam_db_row("SELECT 1 FROM cohort_staff WHERE cohort_id = ? AND role = 'lead'", [$cohortId])) {
        mam_db_insert('cohort_staff', ['cohort_id' => $cohortId, 'instructor_id' => $old, 'role' => 'lead', 'created_at' => gmdate('Y-m-d H:i:s')]);
    }
    // End every current lead row (there should be one; heal if there are more).
    mam_db()->prepare("UPDATE cohort_staff SET ends_on = ?, ended_reason = 'replaced'
                        WHERE cohort_id = ? AND role = 'lead' AND (ends_on IS NULL OR ends_on >= ?)")
        ->execute([$yesterday, $cohortId, $today]);
    if ($new !== null) {
        mam_db_insert('cohort_staff', ['cohort_id' => $cohortId, 'instructor_id' => $new, 'role' => 'lead',
            'starts_on' => $today, 'assigned_by' => $by ?: null,
            'vetting_checked_at' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s')]);
    }
    mam_db_update('cohorts', ['instructor_id' => $new], ['id' => $cohortId]);
    $st = mam_db()->prepare("UPDATE course_enrollments SET instructor_id = ?
                              WHERE cohort_id = ? AND status IN ('pending','active')
                                AND (instructor_id IS NULL OR instructor_id = ?)");
    $st->execute([$new, $cohortId, (int) ($old ?? 0)]);
    $moved = $st->rowCount();
    if ($new !== null && !empty($c['course_id']) && function_exists('mam_lms_remember_instructor')) {
        mam_lms_remember_instructor($new, (int) $c['course_id'], $c['level_id'] !== null ? (int) $c['level_id'] : null);
    }
    mam_audit('cohort_lead_set', 'cohort', $cohortId, ['from' => $old, 'to' => $new, 'moved' => $moved,
        'gate_warning' => $warning ?: null, 'by' => $by]);
    return ['from' => $old, 'to' => $new, 'moved' => $moved, 'warning' => $warning, 'changed' => true];
}

/**
 * Add an assistant or a cover. A cover for one session takes `session_id`
 * (its dates are that session's Lagos day, and the session's substitute_id
 * follows for compatibility); otherwise a date window. Vetting is checked like
 * a lead. Returns the staff row id.
 */
function mam_cohort_staff_add(int $cohortId, int $instructorId, string $role, ?int $sessionId, ?string $startsOn, ?string $endsOn, ?int $by): int {
    if (!in_array($role, ['assistant', 'cover'], true)) throw new InvalidArgumentException('bad_role');
    if (!mam_db_row('SELECT id FROM cohorts WHERE id = ?', [$cohortId])) throw new RuntimeException('cohort_not_found');
    if (!mam_teaching_is_instructor($instructorId)) throw new RuntimeException('instructor_not_found');
    $norm = static function (?string $d): ?string {
        $d = trim((string) $d);
        if ($d === '') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw new InvalidArgumentException('bad_date');
        return $d;
    };
    $startsOn = $norm($startsOn); $endsOn = $norm($endsOn);
    if ($sessionId !== null && $sessionId > 0) {
        $sess = mam_db_row('SELECT id, cohort_id, scheduled_at FROM class_sessions WHERE id = ?', [$sessionId]);
        if (!$sess || (int) $sess['cohort_id'] !== $cohortId) throw new RuntimeException('session_not_in_cohort');
        $day = substr((string) $sess['scheduled_at'], 0, 10);   // scheduled_at is Lagos wall-clock
        $startsOn = $startsOn ?? $day; $endsOn = $endsOn ?? $day;
    } else {
        $sessionId = null;
        if ($role === 'cover' && ($startsOn === null || $endsOn === null)) throw new InvalidArgumentException('cover_needs_dates');
    }
    if ($startsOn !== null && $endsOn !== null && $endsOn < $startsOn) throw new InvalidArgumentException('bad_dates');
    if (function_exists('mam_vetting_check_assignment')) {
        $chk = mam_vetting_check_assignment($instructorId, $cohortId);
        if (!$chk['allow']) throw new RuntimeException('vetting_required: ' . $chk['reason']);
    }
    if ($sessionId !== null) {
        // One cover per session: a second replaces the first (ended, not deleted).
        mam_db()->prepare("UPDATE cohort_staff SET ends_on = ?, ended_reason = 'replaced'
                            WHERE session_id = ? AND role = 'cover' AND ended_reason IS NULL")
            ->execute([mam_teaching_day(time() - 86400), $sessionId]);
    }
    $id = (int) mam_db_insert('cohort_staff', ['cohort_id' => $cohortId, 'instructor_id' => $instructorId, 'role' => $role,
        'session_id' => $sessionId, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'assigned_by' => $by ?: null,
        'vetting_checked_at' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s')]);
    if ($sessionId !== null) mam_db_update('class_sessions', ['substitute_id' => $instructorId], ['id' => $sessionId]);
    mam_audit('cohort_staff_add', 'cohort', $cohortId, ['staff_id' => $id, 'instructor' => $instructorId,
        'role' => $role, 'session' => $sessionId, 'from' => $startsOn, 'to' => $endsOn, 'by' => $by]);
    return $id;
}

/** End a staff row today (never deleted). A lead is replaced through mam_cohort_set_lead(), not ended here. */
function mam_cohort_staff_end(int $staffId, string $reason, ?int $by): void {
    $r = mam_db_row('SELECT * FROM cohort_staff WHERE id = ?', [$staffId]);
    if (!$r) throw new RuntimeException('staff_not_found');
    if ($r['role'] === 'lead') throw new RuntimeException('use_set_lead');
    // Ends as of yesterday. A row that has not started yet (a cover for a future
    // session) then has an empty window — starts_on after ends_on — and is never live.
    $end = mam_teaching_day(time() - 86400);
    mam_db_update('cohort_staff', ['ends_on' => $end, 'ended_reason' => mb_substr(trim($reason) ?: 'ended', 0, 60)], ['id' => $staffId]);
    // A cover ended before its session: the session no longer has that substitute.
    if ($r['session_id'] !== null) {
        mam_db()->prepare('UPDATE class_sessions SET substitute_id = NULL WHERE id = ? AND substitute_id = ?')
            ->execute([(int) $r['session_id'], (int) $r['instructor_id']]);
    }
    mam_audit('cohort_staff_end', 'cohort', (int) $r['cohort_id'], ['staff_id' => $staffId, 'reason' => $reason, 'by' => $by]);
}

/**
 * Bring cohort_staff level with the compatibility columns: a lead row for every
 * cohort whose instructor_id has none, a cover row for every session with a
 * substitute. Idempotent; run by the job runner and by v111. Returns rows added.
 */
function mam_cohort_staff_backfill(): int {
    $n = 0;
    $today = mam_teaching_day(null);
    foreach (mam_db_all("SELECT co.id, co.instructor_id FROM cohorts co
                          WHERE co.instructor_id IS NOT NULL
                            AND EXISTS (SELECT 1 FROM users u WHERE u.id = co.instructor_id)
                            AND NOT EXISTS (SELECT 1 FROM cohort_staff x WHERE x.cohort_id = co.id AND x.role = 'lead'
                                             AND x.instructor_id = co.instructor_id AND (x.ends_on IS NULL OR x.ends_on >= ?))", [$today]) as $c) {
        mam_db()->prepare("UPDATE cohort_staff SET ends_on = ?, ended_reason = 'replaced'
                            WHERE cohort_id = ? AND role = 'lead' AND (ends_on IS NULL OR ends_on >= ?)")
            ->execute([mam_teaching_day(time() - 86400), (int) $c['id'], $today]);
        mam_db_insert('cohort_staff', ['cohort_id' => (int) $c['id'], 'instructor_id' => (int) $c['instructor_id'],
            'role' => 'lead', 'created_at' => gmdate('Y-m-d H:i:s')]);
        $n++;
    }
    foreach (mam_db_all("SELECT cs.id, cs.cohort_id, cs.substitute_id, cs.scheduled_at FROM class_sessions cs
                          WHERE cs.substitute_id IS NOT NULL AND cs.cohort_id IS NOT NULL
                            AND EXISTS (SELECT 1 FROM users u WHERE u.id = cs.substitute_id)
                            AND NOT EXISTS (SELECT 1 FROM cohort_staff x WHERE x.session_id = cs.id AND x.role = 'cover'
                                             AND x.instructor_id = cs.substitute_id AND x.ended_reason IS NULL)") as $s) {
        $day = substr((string) $s['scheduled_at'], 0, 10);
        mam_db_insert('cohort_staff', ['cohort_id' => (int) $s['cohort_id'], 'instructor_id' => (int) $s['substitute_id'],
            'role' => 'cover', 'session_id' => (int) $s['id'], 'starts_on' => $day ?: null, 'ends_on' => $day ?: null,
            'created_at' => gmdate('Y-m-d H:i:s')]);
        $n++;
    }
    return $n;
}
