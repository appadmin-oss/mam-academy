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
 * The auto-charge on a late check-in is gated behind late_charge_enabled (off
 * by default) so switching it on is a deliberate, policy-backed choice.
 */
declare(strict_types=1);

const MAM_ACCT_STATUSES = ['outstanding', 'waived', 'paid', 'reversed'];

/** Resolved configuration. */
function mam_accountability_config(): array {
    return [
        'enabled'        => (int) mam_setting_num('late_charge_enabled', 0) === 1,
        'student_usd'    => (float) mam_setting_num('late_charge_student_usd', 1),
        'instructor_usd' => (float) mam_setting_num('late_charge_instructor_usd', 2),
    ];
}

/**
 * Record a charge. Idempotent per (subject, session, reason) when a session is
 * given, so re-processing the same late check-in never double-charges. Amount
 * defaults to the configured student/instructor rate; pass $amountUsd to
 * override. Returns the charge id (existing id if already recorded).
 */
function mam_accountability_charge(string $subjectType, int $subjectId, ?int $sessionId, string $reason, int $minutes = 0, ?float $amountUsd = null): int {
    if ($subjectId <= 0) throw new InvalidArgumentException('bad_subject');
    $subjectType = $subjectType === 'instructor' ? 'instructor' : 'student';
    $cfg = mam_accountability_config();
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
    mam_audit('accountability_charge', 'user', $subjectId, ['reason' => $reason, 'usd_cents' => $cents, 'session' => $sessionId]);
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
    $out = 0; $waived = 0; $paid = 0; $count = 0;
    foreach ($rows as $r) {
        $c = (int) $r['amount_usd_cents'];
        $count++;
        if ($r['status'] === 'outstanding') $out += $c;
        elseif ($r['status'] === 'waived')  $waived += $c;
        elseif ($r['status'] === 'paid')    $paid += $c;
    }
    return [
        'occurrences'         => $count,
        'outstanding_usd_cents' => $out,
        'waived_usd_cents'      => $waived,
        'paid_usd_cents'        => $paid,
        'charges'             => $rows,
    ];
}

/** Outstanding balance (USD cents) for a subject — cheap KPI read. */
function mam_accountability_outstanding_cents(string $subjectType, int $subjectId): int {
    $row = mam_db_row(
        "SELECT COALESCE(SUM(amount_usd_cents),0) AS c FROM accountability_charges
          WHERE subject_type = ? AND subject_id = ? AND status = 'outstanding'",
        [$subjectType, $subjectId]
    );
    return (int) ($row['c'] ?? 0);
}

/**
 * Resolve a charge to a terminal/decided status — 'waived' (the §15 exception
 * approval), 'paid', or 'reversed'. Records who decided and why. Returns the
 * updated row. Throws on unknown charge / illegal status.
 */
function mam_accountability_resolve(int $chargeId, int $adminId, string $status, ?string $note = null): array {
    if (!in_array($status, ['waived', 'paid', 'reversed'], true)) throw new InvalidArgumentException('bad_status');
    $row = mam_db_row('SELECT * FROM accountability_charges WHERE id = ?', [$chargeId]);
    if (!$row) throw new RuntimeException('not_found');
    if ($row['status'] !== 'outstanding') throw new RuntimeException('already_resolved');
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
