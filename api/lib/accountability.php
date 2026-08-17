<?php
/**
 * MAM Academy — accountability ledger (LMS 2.0, PDF §13-15, §37).
 * ─────────────────────────────────────────────────────────────
 * Records punctuality charges — a student's late arrival ($1), an instructor's
 * lateness ($2), a left-early, etc. — as an auditable ledger on the Part A
 * money model (canonical USD cents + a frozen fx rate). It is a RECORD, not a
 * transaction: nothing is auto-debited from a wallet. Charges can be waived
 * (the §15 exception decision) or marked paid/reversed, and every resolution is
 * audited.
 *
 * ── Charging is OFF by default, and now enforced in one place ───────────────
 * Both automatic charges are gated, both default off, and the gate lives inside
 * mam_accountability_charge() rather than in each caller. With charging off the
 * lateness is still RECORDED (status `recorded`, amount 0, never owed) — because
 * "we chose not to charge for this" and "this never happened" are different
 * facts and only one of them is true.
 *
 * The student and instructor gates are SEPARATE settings. See
 * mam_accountability_config() for why: one is a fee to a customer, the other is
 * a deduction from pay, and Nigerian labour law treats them very differently.
 */
declare(strict_types=1);

/**
 * §37's six ledger statuses. `pending` is the state a charge sits in while an
 * exception request is open — the charge exists and is visible, but it is not
 * yet owed, so a student who has asked for a waiver does not see a debt they
 * may never have to pay. `disputed` is the same shape for a request that was
 * rejected and is being contested. Both are non-terminal.
 */
/** `recorded` = the lateness is on file and NO money is owed (see
 *  mam_accountability_record_only). It is outside MAM_ACCT_OWED by design. */
const MAM_ACCT_RECORDED = 'recorded';
const MAM_ACCT_STATUSES = ['pending', 'outstanding', 'paid', 'waived', 'disputed', 'reversed', MAM_ACCT_RECORDED];

/** Statuses that count as money still owed. */
const MAM_ACCT_OWED = ['outstanding', 'disputed'];

/** §15's exception reason vocabulary. Free text goes in `detail`, not here. */
const MAM_ACCT_EXCEPTION_REASONS = [
    'approved_emergency'   => 'Approved emergency',
    'academy_technical'    => 'Academy technical problem',
    'academy_schedule'     => 'Academy schedule change',
    'instructor_reassign'  => 'Instructor reassignment',
    'approved_absence'     => 'Approved absence',
    'force_majeure'        => 'Force majeure',
    'other'                => 'Other approved reason',
];

/** Resolved configuration. */
/**
 * ── Why there are now TWO enable flags, not one ─────────────────────────────
 * A punctuality charge to a student and a punctuality deduction from an
 * instructor look symmetrical and are not. Nigeria's Labour Act s.5(1) says no
 * employer "shall make any deduction … from the wages … for or in respect of any
 * fines" except, for loss caused by the worker's wilful misconduct or neglect,
 * "with the prior consent in writing of an authorised labour officer". A routine
 * lateness deduction is a fine, not wilful-misconduct loss, and the National
 * Industrial Court has repeatedly struck down unilateral deductions. Whether the
 * Act binds MAM's instructors turns on whether they are workers or contractors
 * (s.91), which is a question for counsel — but the safe default is unambiguous.
 *
 * One shared flag meant an admin enabling student punctuality charges silently
 * switched on instructor wage deductions in the same click. They are now
 * separate settings, and the instructor one defaults off and stays off until
 * somebody turns it on deliberately.
 *
 * ⚠️ Do NOT collapse these back into one flag "for simplicity". The asymmetry is
 * the point: one is a fee to a customer, the other is a deduction from pay.
 */
function mam_accountability_config(): array {
    // Kept as the STUDENT flag for backward compatibility — it is the setting an
    // existing install already has a row for.
    $studentOn = (int) mam_setting_num('late_charge_enabled', 0) === 1;
    return [
        'enabled'            => $studentOn,          // legacy alias = student
        'student_enabled'    => $studentOn,
        'instructor_enabled' => (int) mam_setting_num('late_charge_instructor_enabled', 0) === 1,
        'student_usd'        => (float) mam_setting_num('late_charge_student_usd', 1),
        'instructor_usd'     => (float) mam_setting_num('late_charge_instructor_usd', 2),
    ];
}

/** Is a MONETARY charge permitted for this subject type right now? */
function mam_accountability_charging_on(string $subjectType): bool {
    $cfg = mam_accountability_config();
    return $subjectType === 'instructor' ? $cfg['instructor_enabled'] : $cfg['student_enabled'];
}

/**
 * Record a charge. Idempotent per (subject, session, reason) when a session is
 * given, so re-processing the same late check-in never double-charges. Amount
 * defaults to the configured student/instructor rate; pass $amountUsd to
 * override. Returns the charge id (existing id if already recorded).
 */
function mam_accountability_charge(string $subjectType, int $subjectId, ?int $sessionId, string $reason, int $minutes = 0, ?float $amountUsd = null, bool $adminInitiated = false): int {
    if ($subjectId <= 0) throw new InvalidArgumentException('bad_subject');
    $subjectType = $subjectType === 'instructor' ? 'instructor' : 'student';
    $cfg = mam_accountability_config();

    /* The gate is enforced HERE, not only at the call sites.
       It was read into $cfg and then never checked: the two callers
       (checkin.php, meetings.php) each tested $cfg['enabled'] themselves, so the
       behaviour was correct but the guarantee was theirs to keep. Any third
       caller — a new route, a cron, a well-meaning admin action — would have
       imposed money with nothing to stop it. A rule about charging people is
       worth defending in the function that does the charging.

       $adminInitiated is the deliberate exception: an administrator entering an
       agreed charge by hand is a different act from the system imposing one, and
       it stays available with the automatic path switched off. It is audited
       distinctly below. */
    if (!$adminInitiated && !mam_accountability_charging_on($subjectType)) {
        // Not an error — the academy has chosen not to charge. Record the
        // lateness so the accountability record is still complete, and return
        // that row so callers keep a usable id.
        return mam_accountability_record_only($subjectType, $subjectId, $sessionId, $reason, $minutes);
    }

    if ($amountUsd === null) $amountUsd = $subjectType === 'instructor' ? $cfg['instructor_usd'] : $cfg['student_usd'];

    // Idempotency: one charge per (subject, session, reason).
    if ($sessionId !== null) {
        $existing = mam_db_row(
            'SELECT id FROM accountability_charges WHERE subject_id = ? AND session_id = ? AND reason = ?',
            [$subjectId, $sessionId, $reason]
        );
        if ($existing) return (int) $existing['id'];
    }

    $rate = function_exists('mam_usd_to_ngn_rate') ? mam_usd_to_ngn_rate(false) : null;
    $cents = function_exists('mam_money_to_minor') ? mam_money_to_minor($amountUsd) : (int) round($amountUsd * 100);
    $id = mam_db_insert('accountability_charges', [
        'subject_type'     => $subjectType,
        'subject_id'       => $subjectId,
        'session_id'       => $sessionId,
        'reason'           => $reason,
        'minutes'          => max(0, $minutes),
        'amount_usd_cents' => $cents,
        'currency'         => 'USD',
        'fx_rate_usd_ngn'  => $rate,
        'status'           => 'outstanding',
    ]);
    mam_audit('accountability_charge', 'user', $subjectId, ['reason' => $reason, 'usd_cents' => $cents,
        'session' => $sessionId, 'admin_initiated' => $adminInitiated]);
    if (function_exists('mam_notify') && $subjectType === 'student') {
        mam_notify($subjectId, 'accountability',
            'Late arrival recorded',
            'A ' . $minutes . '-minute late arrival was recorded with a $' . number_format($amountUsd, 2) . ' punctuality charge.', null);
    }
    if ($sessionId !== null && function_exists('mam_acadmail_late_notice')) {
        try { mam_acadmail_late_notice($subjectType, $subjectId, $sessionId, $minutes, $cents); } catch (\Throwable $e) { /* best-effort */ }
    }
    return $id;
}

/**
 * Record a lateness WITHOUT charging for it.
 *
 * This is the accountability the research recommends in place of a fine: the
 * event is on the record, visible to the office, available to an appraisal or a
 * conversation — and no money moves. Status `recorded` is deliberately outside
 * MAM_ACCT_OWED, so nothing anywhere treats it as a debt: it does not appear in
 * an outstanding total, cannot be "paid", and needs no waiver.
 *
 * Why not just skip the write? Because "we chose not to charge for this" and
 * "this never happened" are different facts, and only one of them is true. An
 * academy that later reviews punctuality needs the record; a student or
 * instructor disputing a pattern needs it too.
 *
 * Idempotent on (subject, session, reason), like the charging path.
 */
function mam_accountability_record_only(string $subjectType, int $subjectId, ?int $sessionId, string $reason, int $minutes = 0): int {
    if ($subjectId <= 0) throw new InvalidArgumentException('bad_subject');
    $subjectType = $subjectType === 'instructor' ? 'instructor' : 'student';

    if ($sessionId !== null) {
        $existing = mam_db_row(
            'SELECT id FROM accountability_charges WHERE subject_id = ? AND session_id = ? AND reason = ?',
            [$subjectId, $sessionId, $reason]
        );
        if ($existing) return (int) $existing['id'];
    }
    $id = mam_db_insert('accountability_charges', [
        'subject_type'     => $subjectType,
        'subject_id'       => $subjectId,
        'session_id'       => $sessionId,
        'reason'           => $reason,
        'minutes'          => max(0, $minutes),
        'amount_usd_cents' => 0,
        'currency'         => 'USD',
        'status'           => MAM_ACCT_RECORDED,
        'note'             => 'Recorded for accountability; no charge applied.',
    ]);
    mam_audit('accountability_record', 'user', $subjectId,
        ['reason' => $reason, 'session' => $sessionId, 'minutes' => $minutes, 'charged' => false]);
    /* Deliberately quieter than the charging path: a student is told when money
       is owed, because they have to do something about it. Being told "your
       lateness is on file, you owe nothing" on every occurrence is a nag with no
       action attached, and for a child it is the labelling the research warns
       about. The office sees it; the record exists; nobody is dunned for £0. */
    return $id;
}

/** A subject's ledger: outstanding/waived/paid totals (in USD cents) + rows. */
function mam_accountability_ledger(string $subjectType, int $subjectId, int $limit = 100): array {
    $limit = max(1, min(500, $limit));
    $rows = mam_db_all(
        "SELECT ac.*, cs.title AS session_title, cs.scheduled_at
           FROM accountability_charges ac
           LEFT JOIN class_sessions cs ON cs.id = ac.session_id
          WHERE ac.subject_type = ? AND ac.subject_id = ?
          ORDER BY ac.id DESC LIMIT " . $limit,
        [$subjectType, $subjectId]
    );
    $out = 0; $waived = 0; $paid = 0; $pending = 0; $count = 0;
    $ids = [];
    foreach ($rows as $r) {
        $c = (int) $r['amount_usd_cents'];
        $count++;
        $ids[] = (int) $r['id'];
        if (in_array($r['status'], MAM_ACCT_OWED, true)) $out += $c;
        elseif ($r['status'] === 'waived')  $waived += $c;
        elseif ($r['status'] === 'paid')    $paid += $c;
        elseif ($r['status'] === 'pending') $pending += $c;
    }
    // Attach the open exception request (if any) to each charge, so the ledger
    // shows "waiver requested" rather than a bare status the student can't act on.
    if ($ids) {
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $exc = mam_db_all("SELECT * FROM accountability_exceptions WHERE charge_id IN ($ph) ORDER BY id DESC", $ids);
        $byCharge = [];
        foreach ($exc as $e) { $byCharge[(int) $e['charge_id']] ??= $e; }
        foreach ($rows as &$r) { $r['exception'] = $byCharge[(int) $r['id']] ?? null; }
        unset($r);
    }
    return [
        'occurrences'           => $count,
        'outstanding_usd_cents' => $out,
        'waived_usd_cents'      => $waived,
        'paid_usd_cents'        => $paid,
        'pending_usd_cents'     => $pending,
        'charges'               => $rows,
    ];
}

/* ── §15 exception management ────────────────────────────────────────────── */

/**
 * Request an exception against a charge (§15). The charge moves to `pending`
 * for the duration — the subject should not be shown a debt while the academy
 * is still deciding whether it is owed. One open request per charge; asking
 * again while one is open returns the existing request rather than queueing a
 * second decision for the same fact.
 *
 * `$requestedBy` is the actor, which may be the subject themselves, their
 * instructor, or an administrator raising it on their behalf.
 */
function mam_accountability_request_exception(int $chargeId, ?int $requestedBy, string $reasonCode, ?string $detail = null): array {
    if (!isset(MAM_ACCT_EXCEPTION_REASONS[$reasonCode])) throw new InvalidArgumentException('bad_reason');
    $charge = mam_db_row('SELECT * FROM accountability_charges WHERE id = ?', [$chargeId]);
    if (!$charge) throw new RuntimeException('not_found');
    if (in_array((string) $charge['status'], ['waived', 'paid', 'reversed'], true)) throw new RuntimeException('already_resolved');

    $open = mam_db_row("SELECT * FROM accountability_exceptions WHERE charge_id = ? AND status = 'pending' ORDER BY id DESC", [$chargeId]);
    if ($open) return ['request' => $open, 'already' => true, 'charge' => $charge];

    $id = mam_db_insert('accountability_exceptions', [
        'charge_id'    => $chargeId,
        'requested_by' => $requestedBy ?: null,
        'reason_code'  => $reasonCode,
        'detail'       => $detail !== null && $detail !== '' ? mb_substr($detail, 0, 1000) : null,
        'status'       => 'pending',
    ]);
    mam_db_update('accountability_charges', ['status' => 'pending'], ['id' => $chargeId]);
    mam_audit('accountability_exception_request', 'accountability_charge', $chargeId, ['reason' => $reasonCode, 'by' => $requestedBy]);
    return ['request' => mam_db_row('SELECT * FROM accountability_exceptions WHERE id = ?', [$id]), 'already' => false,
            'charge' => mam_db_row('SELECT * FROM accountability_charges WHERE id = ?', [$chargeId])];
}

/**
 * Decide an open exception (§15). Approving waives the charge; rejecting
 * restores it to `outstanding` — the charge is maintained, exactly as §15
 * words it. Every decision is logged and the subject is notified, because a
 * silent rejection is how a ledger loses trust.
 */
function mam_accountability_decide_exception(int $requestId, int $adminId, bool $approve, ?string $note = null): array {
    $req = mam_db_row('SELECT * FROM accountability_exceptions WHERE id = ?', [$requestId]);
    if (!$req) throw new RuntimeException('not_found');
    if ((string) $req['status'] !== 'pending') throw new RuntimeException('already_decided');
    $chargeId = (int) $req['charge_id'];
    $charge   = mam_db_row('SELECT * FROM accountability_charges WHERE id = ?', [$chargeId]);
    if (!$charge) throw new RuntimeException('not_found');

    $now = date('Y-m-d H:i:s');
    mam_db_update('accountability_exceptions', [
        'status'        => $approve ? 'approved' : 'rejected',
        'decided_by'    => $adminId,
        'decided_at'    => $now,
        'decision_note' => $note,
    ], ['id' => $requestId]);

    if ($approve) {
        mam_db_update('accountability_charges', [
            'status' => 'waived', 'resolved_by' => $adminId, 'resolved_at' => $now,
            'resolution_note' => $note ?? ('Exception approved: ' . (MAM_ACCT_EXCEPTION_REASONS[$req['reason_code']] ?? 'reason not recorded')),
        ], ['id' => $chargeId]);
    } else {
        mam_db_update('accountability_charges', ['status' => 'outstanding'], ['id' => $chargeId]);
    }
    mam_audit('accountability_exception_' . ($approve ? 'approved' : 'rejected'), 'accountability_charge', $chargeId,
        ['request' => $requestId, 'by' => $adminId, 'note' => $note]);

    if (function_exists('mam_notify') && (string) $charge['subject_type'] === 'student') {
        mam_notify((int) $charge['subject_id'], 'accountability',
            $approve ? 'Exception approved — charge waived' : 'Exception rejected — charge maintained',
            $approve
                ? 'Your punctuality charge has been waived.'
                : 'Your exception request was not approved, so the punctuality charge stands. Speak to the office if you would like to dispute it.',
            null);
    }
    return [
        'request' => mam_db_row('SELECT * FROM accountability_exceptions WHERE id = ?', [$requestId]),
        'charge'  => mam_db_row('SELECT * FROM accountability_charges WHERE id = ?', [$chargeId]),
    ];
}

/** Open exception requests, oldest first — this is a queue, not a feed. */
function mam_accountability_exception_queue(int $limit = 100): array {
    $limit = max(1, min(500, $limit));
    return mam_db_all(
        "SELECT ex.*, ac.subject_type, ac.subject_id, ac.reason AS charge_reason, ac.minutes,
                ac.amount_usd_cents, ac.session_id, u.name AS subject_name, cs.title AS session_title
           FROM accountability_exceptions ex
           JOIN accountability_charges ac ON ac.id = ex.charge_id
           LEFT JOIN users u ON u.id = ac.subject_id
           LEFT JOIN class_sessions cs ON cs.id = ac.session_id
          WHERE ex.status = 'pending'
          ORDER BY ex.id ASC LIMIT " . $limit
    );
}

/** Outstanding balance (USD cents) for a subject — cheap KPI read. */
function mam_accountability_outstanding_cents(string $subjectType, int $subjectId): int {
    $ph  = implode(',', array_fill(0, count(MAM_ACCT_OWED), '?'));
    $row = mam_db_row(
        "SELECT COALESCE(SUM(amount_usd_cents),0) AS c FROM accountability_charges
          WHERE subject_type = ? AND subject_id = ? AND status IN ($ph)",
        array_merge([$subjectType, $subjectId], MAM_ACCT_OWED)
    );
    return (int) ($row['c'] ?? 0);
}

/**
 * Resolve a charge to a terminal/decided status — 'waived' (the §15 exception
 * approval), 'paid', or 'reversed'. Records who decided and why. Returns the
 * updated row. Throws on unknown charge / illegal status.
 */
function mam_accountability_resolve(int $chargeId, int $adminId, string $status, ?string $note = null): array {
    if (!in_array($status, ['waived', 'paid', 'reversed', 'disputed'], true)) throw new InvalidArgumentException('bad_status');
    $row = mam_db_row('SELECT * FROM accountability_charges WHERE id = ?', [$chargeId]);
    if (!$row) throw new RuntimeException('not_found');
    // `disputed` is non-terminal, so a disputed charge can still be settled;
    // the three terminal statuses cannot be moved again.
    if (!in_array((string) $row['status'], ['outstanding', 'disputed', 'pending'], true)) throw new RuntimeException('already_resolved');
    $now = date('Y-m-d H:i:s');
    mam_db_update('accountability_charges', [
        'status' => $status, 'resolved_by' => $adminId, 'resolved_at' => $now, 'resolution_note' => $note,
    ], ['id' => $chargeId]);
    mam_audit('accountability_' . $status, 'accountability_charge', $chargeId, ['by' => $adminId, 'note' => $note]);
    if (function_exists('mam_notify') && $status === 'waived' && $row['subject_type'] === 'student') {
        mam_notify((int) $row['subject_id'], 'accountability', 'Charge waived',
            'A punctuality charge on your account was waived.', null);
    }
    return mam_db_row('SELECT * FROM accountability_charges WHERE id = ?', [$chargeId]);
}

/** All charges of a status (admin queue), newest first, with subject names. */
function mam_accountability_list(string $status = 'outstanding', int $limit = 200): array {
    $limit = max(1, min(500, $limit));
    return mam_db_all(
        "SELECT ac.*, u.name AS subject_name, cs.title AS session_title, cs.scheduled_at
           FROM accountability_charges ac
           LEFT JOIN users u ON u.id = ac.subject_id
           LEFT JOIN class_sessions cs ON cs.id = ac.session_id
          WHERE ac.status = ?
          ORDER BY ac.id DESC LIMIT " . $limit,
        [$status]
    );
}
