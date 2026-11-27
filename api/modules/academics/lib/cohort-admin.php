<?php
/**
 * The office's cohort operations — what an administrator does to a CLASS from
 * the console's Enrol & staffing screen (partials/admin-console/roster.php).
 *
 * Everything here is a thin, deliberate layer over lms.php, never a second
 * implementation of it. The rules it leans on (CLAUDE.md gotchas #2, #3, #10,
 * #14) live where they always lived:
 *
 *   • capacity and the waitlist        → mam_lms_cohort_fill_count / _waitlist_add
 *   • the status machine and its audit → mam_lms_transition / mam_lms_withdraw
 *   • the safeguarding gate            → mam_lms_approve / mam_vetting_check_assignment
 *   • the withdrawal outcome + refund  → mam_calendar_withdrawal_outcome
 *
 * What did NOT exist, and is the reason for this file, is enrolling somebody
 * into a SPECIFIC cohort. mam_lms_add_course() resolves the cohort itself from
 * (course, level, programme), which is right for payment settlement and wrong
 * for an office putting a child into "Piano · Saturday 10:00" rather than the
 * Wednesday group the tuple would pick. Transfer, withdraw-with-a-reason,
 * changing the instructor with the gate's verdict shown first, and closing a
 * cohort are the other gaps.
 *
 * Every refusal is a RuntimeException whose message is a CODE; the words an
 * operator reads for each code are in mam_cohort_words(), one table, so the
 * screen, the handler and the test all agree about what a refusal means.
 */
declare(strict_types=1);

/** Seat-occupying enrolment statuses — the same set capacity counts. */
const MAM_COHORT_LIVE = ['pending', 'active'];

/** Shortest acceptable withdrawal reason. A reason is what the next person reads. */
const MAM_COHORT_REASON_MIN = 3;

/** The sentence an operator reads for each refusal code. */
function mam_cohort_words(string $code, array $ctx = []): string {
    $w = [
        'cohort_not_found'     => 'That cohort no longer exists.',
        'cohort_closed'        => 'That cohort is closed. Reopen it before enrolling anybody into it.',
        'no_course_mapping'    => 'That cohort is not linked to a course, so nobody can be enrolled into it. Link it to a course first.',
        'student_not_found'    => 'That student could not be found.',
        'not_a_student'        => 'That account is not a student account.',
        'already_in_cohort'    => 'Already enrolled in this cohort.',
        'already_enrolled'     => 'Already enrolled in this course' . (isset($ctx['where']) && $ctx['where'] !== '' ? ' (' . $ctx['where'] . ')' : '')
                                . '. Transfer them from that enrolment instead — two live enrolments in one course would put the student in two classes.',
        'already_waiting'      => 'Already on this cohort\'s waitlist.',
        'cohort_full'          => 'That cohort is full.',
        'target_full'          => 'The cohort you are moving them to is full. A transfer never ends on a waitlist, because that would leave the student in no class at all — make room first or pick another cohort.',
        'same_cohort'          => 'They are already in that cohort.',
        'enrollment_not_found' => 'That enrolment no longer exists.',
        'not_live'             => 'That enrolment has already ended, so there is nothing to move or withdraw.',
        'reason_required'      => 'Say why, in a few words. The reason is recorded on the student\'s enrolment history.',
        'illegal_transition'   => 'That change is not allowed from the enrolment\'s current status.',
        'bad_status'           => 'Choose one of the listed changes.',
        'not_graded'           => 'No marks are on record yet — score the student in the Gradebook before finalising.',
        'instructor_not_found' => 'That person is not an active instructor.',
        'vetting_required'     => 'The safeguarding gate refused that instructor' . (isset($ctx['reason']) && $ctx['reason'] !== '' ? ': ' . $ctx['reason'] : '.'),
        'has_students'         => 'This cohort still has ' . (int) ($ctx['n'] ?? 0) . ' student' . (((int) ($ctx['n'] ?? 0)) === 1 ? '' : 's')
                                . ' enrolled. Move or withdraw them first — closing a class with children in it would leave them on a roster nobody teaches.',
        'has_waitlist'         => 'This cohort has ' . (int) ($ctx['n'] ?? 0) . ' student' . (((int) ($ctx['n'] ?? 0)) === 1 ? '' : 's')
                                . ' waiting. Place or remove them first; closing it would strike them off silently.',
        'not_archived'         => 'That cohort is not closed.',
    ];
    return $w[$code] ?? ('Could not do that (' . $code . ').');
}

/** Split "code: detail" into [code, detail] — mam_lms_approve throws 'vetting_required: …'. */
function mam_cohort_code(Throwable $e): array {
    $m = $e->getMessage();
    $p = strpos($m, ':');
    if ($p === false) return [$m, ''];
    return [trim(substr($m, 0, $p)), trim(substr($m, $p + 1))];
}

function mam_cohort_row(int $cohortId): array {
    $c = mam_db_row('SELECT id, title, status, course_id, level_id, program_id, instructor_id, capacity
                       FROM cohorts WHERE id = ?', [$cohortId]);
    if (!$c) throw new RuntimeException('cohort_not_found');
    return $c;
}

/** Is this account a student by ANY role it holds (a student may also be staff). */
function mam_cohort_is_student(int $userId): bool {
    $u = mam_db_row('SELECT id, role FROM users WHERE id = ?', [$userId]);
    if (!$u) throw new RuntimeException('student_not_found');
    if (($u['role'] ?? '') === 'student') return true;
    return (bool) mam_db_row("SELECT 1 AS x FROM user_roles WHERE user_id = ? AND role = 'student'", [$userId]);
}

/**
 * The live enrolment that would collide with putting $studentId into $cohort —
 * the SAME level-aware rule mam_lms_add_course() applies, in both directions:
 * a level-less cohort collides with any live enrolment in the course, a levelled
 * one with that level or a level-less enrolment. Distinct levels stay legal,
 * because that is progression. $ignore excludes the enrolment a transfer is
 * about to withdraw.
 */
function mam_cohort_duplicate(array $cohort, int $studentId, ?int $ignore = null): ?array {
    $sql = "SELECT ce.id, ce.cohort_id, c.title AS cohort_title
              FROM course_enrollments ce LEFT JOIN cohorts c ON c.id = ce.cohort_id
             WHERE ce.student_id = ? AND ce.course_id = ? AND ce.status IN ('pending','active')";
    $args = [$studentId, (int) $cohort['course_id']];
    if ($cohort['level_id'] !== null) {
        // Bare column compare, so INTEGER affinity coerces the bound parameter
        // (the same note as mam_lms_add_course()).
        $sql .= ' AND (ce.level_id IS NULL OR ce.level_id = ?)';
        $args[] = (int) $cohort['level_id'];
    }
    if ($ignore !== null) { $sql .= ' AND ce.id <> ?'; $args[] = $ignore; }
    return mam_db_row($sql . ' ORDER BY ce.id LIMIT 1', $args) ?: null;
}

/**
 * What WOULD happen if this student were enrolled into this cohort, without
 * doing it — so the screen can say "will join the waitlist (full)" or "already
 * enrolled in Piano · Wednesday" beside the button rather than after the click.
 * Returns ['ok'=>bool, 'will'=>'enrol'|'waitlist'|null, 'code'=>?string, 'words'=>string].
 */
function mam_cohort_enrol_preview(int $cohortId, int $studentId): array {
    try {
        $c = mam_cohort_row($cohortId);
        if (($c['status'] ?? '') !== 'active') throw new RuntimeException('cohort_closed');
        if (empty($c['course_id'])) throw new RuntimeException('no_course_mapping');
        if (!mam_cohort_is_student($studentId)) throw new RuntimeException('not_a_student');
        $dup = mam_cohort_duplicate($c, $studentId);
        if ($dup) {
            if ((int) $dup['cohort_id'] === $cohortId) throw new RuntimeException('already_in_cohort');
            return ['ok' => false, 'will' => null, 'code' => 'already_enrolled',
                    'words' => mam_cohort_words('already_enrolled', ['where' => (string) ($dup['cohort_title'] ?? '')])];
        }
        if (mam_db_row("SELECT 1 AS x FROM class_waitlist WHERE cohort_id = ? AND student_id = ? AND status = 'waiting'", [$cohortId, $studentId])) {
            throw new RuntimeException('already_waiting');
        }
        $fill = mam_lms_cohort_fill_count($cohortId);
        if ($fill['available'] < 1) {
            return ['ok' => true, 'will' => 'waitlist', 'code' => null,
                    'words' => 'Full — joins the waitlist at position ' . ($fill['waitlist_count'] + 1) . '.'];
        }
        return ['ok' => true, 'will' => 'enrol', 'code' => null, 'words' => 'A seat is free.'];
    } catch (RuntimeException $e) {
        [$code] = mam_cohort_code($e);
        return ['ok' => false, 'will' => null, 'code' => $code, 'words' => mam_cohort_words($code)];
    }
}

/**
 * Write the enrolment row and run it through approval. Not public: callers are
 * mam_cohort_enrol() and mam_cohort_transfer(), which validate first.
 *
 * Approval goes through mam_lms_approve() — the safeguarding choke point, the
 * welcome mail, the member number and the ID card all hang off it. When the gate
 * refuses the cohort's instructor (enforce mode) the student is STILL enrolled,
 * active, with no instructor attached: "enrolled, awaiting a cleared teacher" is
 * actionable and a refused placement is not (CLAUDE.md #10).
 */
function mam_cohort_insert_enrolment(array $c, int $studentId, ?int $actorId, string $why): array {
    $actor = mam_lms_actor_id($actorId);
    $id = mam_db_insert('course_enrollments', [
        'student_id' => $studentId,
        'course_id'  => (int) $c['course_id'],
        'level_id'   => $c['level_id'] !== null ? (int) $c['level_id'] : null,
        'program_id' => $c['program_id'] !== null ? (int) $c['program_id'] : null,
        'cohort_id'  => (int) $c['id'],
        'status'     => 'pending',
    ]);
    mam_lms_log_event($id, null, 'pending', $actor, $why);

    $instr = (int) ($c['instructor_id'] ?? 0);
    $withheld = null;
    try {
        mam_lms_approve($id, $instr > 0 ? $instr : null, $actor);
    } catch (RuntimeException $e) {
        [$code, $detail] = mam_cohort_code($e);
        if ($code !== 'vetting_required') throw $e;
        $withheld = $detail;
        mam_lms_transition($id, 'active', $actor, 'approved · instructor not attached (safeguarding gate)');
    }
    // mam_lms_approve() falls back to the instructor REMEMBERED for the course
    // when given none. On a cohort with no teacher that would attach somebody
    // the cohort does not have — and who would then not follow when the cohort
    // is given its own. The cohort decides; its absence is shown on the screen.
    if ($instr <= 0) mam_db_update('course_enrollments', ['instructor_id' => null], ['id' => $id]);
    $after = mam_db_row('SELECT ce.instructor_id, u.name FROM course_enrollments ce
                           LEFT JOIN users u ON u.id = ce.instructor_id WHERE ce.id = ?', [$id]);
    return ['id' => $id, 'status' => 'active', 'instructor' => $after['name'] ?? null,
            'instructor_withheld' => $withheld];
}

/**
 * Enrol a student into THIS cohort. Capacity-aware: a full cohort queues the
 * student on its waitlist (when $waitlistIfFull) instead of over-filling it.
 * Returns ['status'=>'active', 'id'=>N, 'instructor'=>?, 'instructor_withheld'=>?]
 * or ['status'=>'waitlisted', 'id'=>null, 'position'=>N].
 */
function mam_cohort_enrol(int $cohortId, int $studentId, ?int $actorId, bool $waitlistIfFull = true): array {
    $c = mam_cohort_row($cohortId);
    if (($c['status'] ?? '') !== 'active') throw new RuntimeException('cohort_closed');
    if (empty($c['course_id'])) throw new RuntimeException('no_course_mapping');
    if (!mam_cohort_is_student($studentId)) throw new RuntimeException('not_a_student');
    $dup = mam_cohort_duplicate($c, $studentId);
    if ($dup) {
        if ((int) $dup['cohort_id'] === $cohortId) throw new RuntimeException('already_in_cohort');
        throw new RuntimeException('already_enrolled: ' . (string) ($dup['cohort_title'] ?? ''));
    }

    if (mam_lms_cohort_fill_count($cohortId)['available'] < 1) {
        if (!$waitlistIfFull) throw new RuntimeException('cohort_full');
        if (mam_db_row("SELECT 1 AS x FROM class_waitlist WHERE cohort_id = ? AND student_id = ? AND status = 'waiting'", [$cohortId, $studentId])) {
            throw new RuntimeException('already_waiting');
        }
        $pos = mam_lms_waitlist_add($cohortId, $studentId);
        mam_audit('console_cohort_waitlist', 'cohort', $cohortId, ['student' => $studentId, 'position' => $pos]);
        return ['status' => 'waitlisted', 'id' => null, 'position' => $pos];
    }

    $r = mam_cohort_insert_enrolment($c, $studentId, $actorId, 'enrolled by the office');
    mam_audit('console_cohort_enrol', 'course_enrollment', $r['id'], [
        'cohort' => $cohortId, 'student' => $studentId, 'instructor_withheld' => $r['instructor_withheld'],
    ]);
    return $r;
}

/** The withdrawal outcome TODAY, or null — never throws (an unauthored calendar must not stop anything). */
function mam_cohort_outcome_now(): ?array {
    if (!function_exists('mam_calendar_withdrawal_outcome')) return null;
    try { return mam_calendar_withdrawal_outcome(); }
    catch (Throwable $e) { mam_log('info', 'cohort_outcome_skip', ['err' => $e->getMessage()]); return null; }
}

/** "Withdrawn (W), week 6 of Toni 2026, refund 50%" — the outcome as one line. */
function mam_cohort_outcome_line(?array $wo): string {
    if (!$wo) return 'No term is authored for today, so no withdrawal outcome or refund is computed — the academy decides';
    $kind = ['dropped' => 'Dropped (no mark on the record)', 'withdrawn' => 'Withdrawn (W, no academic penalty)',
             'graded' => 'Past the withdrawal deadline — the enrolment stands and is graded',
             'undetermined' => 'Outcome undetermined (no withdrawal deadline recorded)'][$wo['outcome']] ?? (string) $wo['outcome'];
    $line = $kind . ' · week ' . ($wo['week'] ?? '?') . ' of ' . (string) ($wo['term']['label'] ?? 'this term');
    $line .= $wo['refund_percent'] !== null ? ' · refund ' . (int) $wo['refund_percent'] . '%' : ' · no refund schedule applies';
    return $line;
}

function mam_cohort_live_enrolment(int $enrollmentId): array {
    $e = mam_db_row('SELECT id, student_id, cohort_id, course_id, level_id, status FROM course_enrollments WHERE id = ?', [$enrollmentId]);
    if (!$e) throw new RuntimeException('enrollment_not_found');
    if (!in_array((string) $e['status'], MAM_COHORT_LIVE, true)) throw new RuntimeException('not_live');
    return $e;
}

/**
 * Withdraw with a reason. mam_lms_withdraw() records the calendar's outcome and
 * refund percentage into the transition reason (enrolment_events) and frees the
 * seat, which drains the waitlist. Returns ['outcome'=>?array, 'line'=>string].
 */
function mam_cohort_withdraw(int $enrollmentId, ?int $actorId, string $reason): array {
    $reason = trim($reason);
    if (mb_strlen($reason) < MAM_COHORT_REASON_MIN) throw new RuntimeException('reason_required');
    mam_cohort_live_enrolment($enrollmentId);
    $wo = mam_cohort_outcome_now();
    mam_lms_withdraw($enrollmentId, mam_lms_actor_id($actorId), mb_substr($reason, 0, 160));
    mam_audit('console_cohort_withdraw', 'course_enrollment', $enrollmentId, [
        'reason' => $reason, 'outcome' => $wo['outcome'] ?? null, 'refund_percent' => $wo['refund_percent'] ?? null,
    ]);
    return ['outcome' => $wo, 'line' => mam_cohort_outcome_line($wo)];
}

/**
 * Move a student from one cohort to another in ONE step: withdraw the old
 * enrolment and enrol into the new cohort, inside one transaction, so a failure
 * half-way never leaves the student in no class. Everything that can refuse is
 * checked BEFORE anything is written. The target must have a free seat — a
 * transfer that ended on a waitlist would leave the student nowhere.
 *
 * The old enrolment's withdrawal still consults the calendar, so the record
 * carries what kind of leaving it was and the refund percentage that applies;
 * the caller shows that line to the operator.
 */
function mam_cohort_transfer(int $enrollmentId, int $toCohortId, ?int $actorId, string $reason): array {
    $reason = trim($reason);
    if (mb_strlen($reason) < MAM_COHORT_REASON_MIN) throw new RuntimeException('reason_required');
    $e = mam_cohort_live_enrolment($enrollmentId);
    if ((int) $e['cohort_id'] === $toCohortId) throw new RuntimeException('same_cohort');
    $to = mam_cohort_row($toCohortId);
    if (($to['status'] ?? '') !== 'active') throw new RuntimeException('cohort_closed');
    if (empty($to['course_id'])) throw new RuntimeException('no_course_mapping');
    $sid = (int) $e['student_id'];
    $dup = mam_cohort_duplicate($to, $sid, $enrollmentId);
    if ($dup) {
        if ((int) $dup['cohort_id'] === $toCohortId) throw new RuntimeException('already_in_cohort');
        throw new RuntimeException('already_enrolled: ' . (string) ($dup['cohort_title'] ?? ''));
    }
    // A seat the student already holds in the target (impossible — same_cohort)
    // aside, the target needs one free seat of its own.
    if (mam_lms_cohort_fill_count($toCohortId)['available'] < 1) throw new RuntimeException('target_full');

    $wo = mam_cohort_outcome_now();
    $toTitle = (string) ($to['title'] ?? ('cohort #' . $toCohortId));
    $db = mam_db();
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        mam_lms_withdraw($enrollmentId, mam_lms_actor_id($actorId), mb_substr('transfer to ' . $toTitle . ' · ' . $reason, 0, 160));
        $r = mam_cohort_insert_enrolment($to, $sid, $actorId, 'transferred in from enrolment #' . $enrollmentId);
        if ($own) $db->commit();
    } catch (Throwable $ex) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $ex;
    }
    mam_audit('console_cohort_transfer', 'course_enrollment', $r['id'], [
        'from_enrollment' => $enrollmentId, 'from_cohort' => (int) $e['cohort_id'], 'to_cohort' => $toCohortId,
        'student' => $sid, 'reason' => $reason, 'outcome' => $wo['outcome'] ?? null,
        'refund_percent' => $wo['refund_percent'] ?? null,
    ]);
    return $r + ['from' => $enrollmentId, 'outcome' => $wo, 'line' => mam_cohort_outcome_line($wo), 'to_title' => $toTitle];
}

/**
 * The status changes the office may make to a course enrolment from a roster
 * row. Withdrawal is its own action (it needs a reason and shows an outcome);
 * completed/failed come from the gradebook via mam_lms_finalize(), never typed,
 * so a course is never closed as failed for a lack of marks.
 */
function mam_cohort_status_changes(string $from): array {
    return [
        'pending' => ['active' => 'Approve', 'rejected' => 'Reject'],
        'active'  => ['finalise' => 'Finalise from marks'],
    ][$from] ?? [];
}

function mam_cohort_set_status(int $enrollmentId, string $to, ?int $actorId): array {
    $e = mam_db_row('SELECT id, status, cohort_id, instructor_id FROM course_enrollments WHERE id = ?', [$enrollmentId]);
    if (!$e) throw new RuntimeException('enrollment_not_found');
    $from = (string) $e['status'];
    if (!isset(mam_cohort_status_changes($from)[$to])) throw new RuntimeException('bad_status');
    $actor = mam_lms_actor_id($actorId);
    if ($to === 'active') {
        // Approve through the gate, with the cohort's instructor if the
        // enrolment has none of its own.
        $instr = (int) ($e['instructor_id'] ?? 0);
        if ($instr <= 0 && (int) $e['cohort_id'] > 0) {
            $instr = (int) (mam_db_row('SELECT instructor_id FROM cohorts WHERE id = ?', [(int) $e['cohort_id']])['instructor_id'] ?? 0);
        }
        try { mam_lms_approve($enrollmentId, $instr > 0 ? $instr : null, $actor); }
        catch (RuntimeException $ex) {
            [$code] = mam_cohort_code($ex);
            if ($code !== 'vetting_required') throw $ex;
            mam_lms_transition($enrollmentId, 'active', $actor, 'approved · instructor not attached (safeguarding gate)');
        }
        // Same rule as an office enrolment: no remembered stand-in for a cohort with no teacher.
        if ($instr <= 0) mam_db_update('course_enrollments', ['instructor_id' => null], ['id' => $enrollmentId]);
        $res = ['to' => 'active'];
    } elseif ($to === 'finalise') {
        $fin = mam_lms_finalize($enrollmentId, $actor);
        $res = ['to' => (string) $fin['status']];
    } else {
        mam_lms_transition($enrollmentId, $to, $actor, 'set by the office');
        $res = ['to' => $to];
    }
    mam_audit('console_enrolment_status', 'course_enrollment', $enrollmentId, ['from' => $from, 'to' => $res['to']]);
    return $res + ['from' => $from];
}

/**
 * The safeguarding gate's verdict for putting $instructorId on this cohort,
 * computed WITHOUT writing anything — the preview the screen shows before the
 * operator presses Save. mam_vetting_check_assignment() audits every gap it
 * sees, which is right at the moment of assignment and wrong on a page load.
 * Returns ['verdict'=>'clear'|'not_required'|'warn'|'refuse'|'off', 'allow'=>bool,
 *          'words'=>string, 'touches_minor'=>bool, 'mode'=>string].
 */
function mam_cohort_instructor_check(int $cohortId, int $instructorId): array {
    $mode = function_exists('mam_vetting_mode') ? mam_vetting_mode() : 'off';
    if ($mode === 'off') {
        return ['verdict' => 'off', 'allow' => true, 'mode' => $mode, 'touches_minor' => false,
                'words' => 'The safeguarding gate is switched off on this install, so nothing is checked.'];
    }
    $minor = mam_vetting_cohort_has_minor($cohortId);
    $gate = mam_vetting_may_teach_minors($instructorId);
    if (!empty($gate['ok'])) {
        return ['verdict' => 'clear', 'allow' => true, 'mode' => $mode, 'touches_minor' => $minor,
                'words' => 'Cleared to teach children. Every required check is on file and current.'];
    }
    $why = mam_vetting_explain($gate);
    if (!$minor) {
        return ['verdict' => 'not_required', 'allow' => true, 'mode' => $mode, 'touches_minor' => false,
                'words' => 'Not cleared to teach children (' . $why . '), but this cohort has no child in it, so the gate does not apply.'];
    }
    if ($mode === 'enforce') {
        return ['verdict' => 'refuse', 'allow' => false, 'mode' => $mode, 'touches_minor' => true,
                'words' => 'Will be refused: ' . $why . ' This cohort has a child in it and the gate is enforcing.'];
    }
    return ['verdict' => 'warn', 'allow' => true, 'mode' => $mode, 'touches_minor' => true,
            'words' => 'Will be allowed and the gap recorded: ' . $why . ' The gate is warning, not enforcing.'];
}

/**
 * Put an instructor on a cohort (or take them off, with null). The gate runs at
 * the moment of assignment and refuses in enforce mode. Enrolments that carried
 * the OLD cohort instructor, or none, follow the cohort; an enrolment with its
 * own different instructor (a deliberate one-off) is left alone.
 */
function mam_cohort_set_instructor(int $cohortId, ?int $instructorId, ?int $actorId): array {
    $c = mam_cohort_row($cohortId);
    $old = (int) ($c['instructor_id'] ?? 0);
    $new = ($instructorId !== null && $instructorId > 0) ? $instructorId : null;
    $warning = '';
    if ($new !== null) {
        $u = mam_db_row("SELECT id, role, status FROM users WHERE id = ?", [$new]);
        $isInstr = $u && ($u['status'] ?? '') === 'active' && (($u['role'] ?? '') === 'instructor'
            || mam_db_row("SELECT 1 AS x FROM user_roles WHERE user_id = ? AND role = 'instructor'", [$new]));
        if (!$isInstr) throw new RuntimeException('instructor_not_found');
        $chk = mam_vetting_check_assignment($new, $cohortId);
        if (!$chk['allow']) throw new RuntimeException('vetting_required: ' . $chk['reason']);
        $warning = (string) $chk['reason'];
    }
    if ($new === $old || ($new === null && $old <= 0)) {
        return ['from' => $old ?: null, 'to' => $new, 'moved' => 0, 'warning' => $warning, 'changed' => false];
    }
    mam_db_update('cohorts', ['instructor_id' => $new], ['id' => $cohortId]);
    $st = mam_db()->prepare(
        "UPDATE course_enrollments SET instructor_id = ?
          WHERE cohort_id = ? AND status IN ('pending','active')
            AND (instructor_id IS NULL OR instructor_id = ?)");
    $st->execute([$new, $cohortId, $old]);
    $moved = $st->rowCount();
    if ($new !== null && !empty($c['course_id'])) {
        mam_lms_remember_instructor($new, (int) $c['course_id'], $c['level_id'] !== null ? (int) $c['level_id'] : null);
    }
    mam_audit('console_cohort_instructor', 'cohort', $cohortId, [
        'from' => $old ?: null, 'to' => $new, 'moved' => $moved, 'gate_warning' => $warning ?: null,
        'by' => mam_lms_actor_id($actorId),
    ]);
    return ['from' => $old ?: null, 'to' => $new, 'moved' => $moved, 'warning' => $warning, 'changed' => true];
}

/**
 * Close a cohort. Refused while anybody is enrolled (pending or active) or
 * waiting: closing a class with children in it leaves them on a roster nobody
 * teaches, and closing one with a queue strikes the queue off without a word.
 */
function mam_cohort_archive(int $cohortId, ?int $actorId): void {
    $c = mam_cohort_row($cohortId);
    $n = (int) (mam_db_row("SELECT COUNT(DISTINCT student_id) AS n FROM course_enrollments
                             WHERE cohort_id = ? AND status IN ('pending','active')", [$cohortId])['n'] ?? 0);
    if ($n > 0) throw new RuntimeException('has_students: ' . $n);
    $w = (int) (mam_db_row("SELECT COUNT(*) AS n FROM class_waitlist WHERE cohort_id = ? AND status = 'waiting'", [$cohortId])['n'] ?? 0);
    if ($w > 0) throw new RuntimeException('has_waitlist: ' . $w);
    if (($c['status'] ?? '') === 'archived') return;
    mam_db_update('cohorts', ['status' => 'archived'], ['id' => $cohortId]);
    mam_audit('console_cohort_archive', 'cohort', $cohortId, ['from' => $c['status'], 'by' => mam_lms_actor_id($actorId)]);
}

function mam_cohort_reopen(int $cohortId, ?int $actorId): void {
    $c = mam_cohort_row($cohortId);
    if (($c['status'] ?? '') === 'active') return;
    mam_db_update('cohorts', ['status' => 'active'], ['id' => $cohortId]);
    mam_audit('console_cohort_reopen', 'cohort', $cohortId, ['from' => $c['status'], 'by' => mam_lms_actor_id($actorId)]);
}

/**
 * Find students by name, email or member number. LIKE wildcards in the query
 * are escaped with '!' — a backslash escape is a syntax error on MariaDB
 * (CLAUDE.md, MySQL defect #4).
 */
function mam_cohort_find_students(string $q, int $limit = 12): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)) . '%';
    return mam_db_all(
        "SELECT u.id, u.name, u.email, u.enrollment_ref, u.status, u.avatar_url
           FROM users u
          WHERE (u.role = 'student' OR EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = 'student'))
            AND u.status IN ('active','roster')
            AND (LOWER(u.name) LIKE ? ESCAPE '!' OR LOWER(u.email) LIKE ? ESCAPE '!' OR LOWER(COALESCE(u.enrollment_ref,'')) LIKE ? ESCAPE '!')
          ORDER BY u.name LIMIT " . max(1, min(50, $limit)),
        [$like, $like, $like]);
}

/** The operator's sentence for any exception these functions throw. */
function mam_cohort_error_words(Throwable $e): string {
    [$code, $detail] = mam_cohort_code($e);
    $ctx = match ($code) {
        'already_enrolled' => ['where' => $detail],
        'vetting_required' => ['reason' => $detail],
        'has_students', 'has_waitlist' => ['n' => (int) $detail],
        default => [],
    };
    if (!($e instanceof RuntimeException) && !($e instanceof InvalidArgumentException)) {
        mam_log('warn', 'cohort_admin_error', ['err' => $e->getMessage()]);
        return 'Something went wrong and nothing was changed. The error has been logged.';
    }
    return mam_cohort_words($code, $ctx);
}
