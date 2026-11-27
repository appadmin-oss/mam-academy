<?php
/**
 * MAM Academy — paid class rescheduling (LMS 2.0 Part C).
 * ─────────────────────────────────────────────────────────────
 * A student requests to move one or more of their cohort's sessions. The
 * server — never the client — prices the request (a free allowance per term,
 * then a per-session or per-request fee), the student settles it from wallet
 * credit (or, via the provider seam, a card), an instructor/admin approves,
 * and the move is applied through the EXISTING session_history-audited writer
 * (mam_lms_reschedule_session) so the change history and conflict semantics
 * stay identical to a staff-initiated reschedule.
 *
 * Money is stored on the Part A model: fee_usd_cents is the canonical figure,
 * fx_rate_usd_ngn freezes the rate, and the wallet settlement records a
 * purpose='reschedule' payment so it flows through the same honest revenue KPI.
 *
 * State machine (status column on reschedule_requests):
 *
 *     created ─▶ pending_payment ─(pay)─▶ paid ─(approve)─▶ approved ─(apply)─▶ applied
 *                     │                    │                   │
 *                (fee == 0)                │              (decline)         (terminal)
 *                     └────────────────────┘                   ▼
 *                                          │               declined ──(refund if paid)
 *              (expiry sweep)              │
 *                     ▼                (cancel by requester, any pre-terminal)
 *                  expired                 ▼
 *                                       cancelled ──(refund if paid)
 *
 * The whole feature is gated behind the `reschedule_enabled` setting (0 by
 * default); every entry point calls mam_reschedule_enabled() first, so the
 * tables and routes are inert until an admin opts in.
 */
declare(strict_types=1);

const MAM_RESCHED_TERMINAL = ['applied', 'declined', 'cancelled', 'expired'];
/** Statuses that count against a student's free allowance / per-term cap
 *  (i.e. everything not abandoned). */
const MAM_RESCHED_COUNTED = ['pending_payment', 'paid', 'approved', 'applied'];

/** Master switch. When off, every entry point refuses. */
function mam_reschedule_enabled(): bool {
    return (int) mam_setting_num('reschedule_enabled', 0) === 1;
}

/** Resolved, typed configuration for the feature. */
function mam_reschedule_config(): array {
    $mode = (string) mam_setting_or_default('reschedule_fee_mode', 'per_session');
    if ($mode !== 'per_request') $mode = 'per_session';
    return [
        'enabled'          => mam_reschedule_enabled(),
        'fee_usd'          => (float) mam_setting_num('reschedule_fee_usd', 10),
        'fee_mode'         => $mode,
        'free_per_term'    => (int) mam_setting_num('reschedule_free_per_term', 1),
        'max_per_term'     => (int) mam_setting_num('reschedule_max_per_term', 3),
        'window_hours'     => (int) mam_setting_num('reschedule_window_hours', 24),
        'expiry_hours'     => (int) mam_setting_num('reschedule_payment_expiry_hours', 24),
        'approval_required'=> (int) mam_setting_num('reschedule_approval_required', 1) === 1,
    ];
}

/** Count of sessions this requester has already rescheduled in this cohort
 *  that still "count" (not cancelled/expired/declined). Drives the free
 *  allowance and the per-term cap. */
function mam_reschedule_used_count(int $requesterId, int $cohortId): int {
    $ph = implode(',', array_fill(0, count(MAM_RESCHED_COUNTED), '?'));
    $row = mam_db_row(
        "SELECT COALESCE(SUM(session_count),0) AS c FROM reschedule_requests
          WHERE requester_id = ? AND cohort_id = ? AND status IN ($ph)",
        array_merge([$requesterId, $cohortId], MAM_RESCHED_COUNTED)
    );
    return (int) ($row['c'] ?? 0);
}

/**
 * Server-authoritative price for rescheduling $sessionCount sessions in this
 * cohort, given what the requester has already used. Pure of side effects.
 * Returns fee in canonical USD cents plus the breakdown the UI shows, and a
 * `limit_exceeded` flag the caller must honour.
 */
function mam_reschedule_quote(int $requesterId, int $cohortId, int $sessionCount): array {
    $cfg  = mam_reschedule_config();
    $used = mam_reschedule_used_count($requesterId, $cohortId);
    $freeRemaining = max(0, $cfg['free_per_term'] - $used);
    $billable      = max(0, $sessionCount - $freeRemaining);

    if ($cfg['fee_mode'] === 'per_request') {
        $feeUsd = $billable > 0 ? $cfg['fee_usd'] : 0.0;
    } else { // per_session
        $feeUsd = $billable * $cfg['fee_usd'];
    }
    $feeCents = (int) round($feeUsd * 100);
    $rate     = mam_usd_to_ngn_rate(false); // read-only; freeze at quote/charge time

    return [
        'session_count'  => $sessionCount,
        'used'           => $used,
        'free_remaining' => $freeRemaining,
        'billable'       => $billable,
        'fee_mode'       => $cfg['fee_mode'],
        'fee_usd'        => $cfg['fee_usd'],
        'fee_usd_cents'  => $feeCents,
        'fee_currency'   => 'USD',
        'fx_rate_usd_ngn'=> $rate,
        'fee_ngn'        => mam_money_ngn_from_usd_cents($feeCents, $rate),
        'max_per_term'   => $cfg['max_per_term'],
        'limit_exceeded' => ($used + $sessionCount) > $cfg['max_per_term'],
    ];
}

/** True if the requester holds a non-withdrawn enrolment in this cohort. */
function mam_reschedule_can_request(int $requesterId, int $cohortId): bool {
    $row = mam_db_row(
        "SELECT id FROM course_enrollments
          WHERE student_id = ? AND cohort_id = ?
            AND status NOT IN ('withdrawn','cancelled','rejected','failed') LIMIT 1",
        [$requesterId, $cohortId]
    );
    return (bool) $row;
}

/**
 * Create a reschedule request. $moves is a list of ['session_id' => int,
 * 'new_scheduled_at' => 'Y-m-d H:i:s']. Validates the toggle, enrolment,
 * session ownership/state, the lead-time window, and the per-term cap; prices
 * it server-side; then writes the request + items + a 'created' event in one
 * transaction. Free/zero-fee requests skip straight to 'paid'. Throws a
 * RuntimeException whose message is a stable error code on any rejection.
 * Returns the created request row (via mam_reschedule_get).
 */
function mam_reschedule_create(int $requesterId, int $cohortId, array $moves, ?string $reason, ?int $waivedBy = null, ?string $waivedReason = null): array {
    if (!mam_reschedule_enabled()) throw new RuntimeException('reschedule_disabled');
    if ($requesterId <= 0 || $cohortId <= 0) throw new RuntimeException('validation');
    if (!$moves) throw new RuntimeException('no_sessions');
    if (!mam_reschedule_can_request($requesterId, $cohortId)) throw new RuntimeException('not_enrolled');

    $cfg   = mam_reschedule_config();
    $now   = date('Y-m-d H:i:s');
    $minAt = date('Y-m-d H:i:s', time() + $cfg['window_hours'] * 3600);

    // Validate every move up front so a bad one rejects the whole request.
    $clean = [];
    foreach ($moves as $m) {
        $sid   = (int) ($m['session_id'] ?? 0);
        $newAt = trim((string) ($m['new_scheduled_at'] ?? ''));
        if ($sid <= 0 || $newAt === '') throw new RuntimeException('validation');
        $sess = mam_db_row('SELECT id, cohort_id, scheduled_at, status FROM class_sessions WHERE id = ?', [$sid]);
        if (!$sess) throw new RuntimeException('session_not_found');
        if ((int) $sess['cohort_id'] !== $cohortId) throw new RuntimeException('session_cohort_mismatch');
        if ((string) $sess['status'] !== 'scheduled') throw new RuntimeException('session_not_scheduled');
        // Lead-time window: the ORIGINAL session must be far enough out.
        if ((string) $sess['scheduled_at'] !== '' && (string) $sess['scheduled_at'] < $minAt) {
            throw new RuntimeException('window_closed');
        }
        if ($newAt <= $now) throw new RuntimeException('new_time_in_past');
        $clean[] = ['session_id' => $sid, 'old' => (string) $sess['scheduled_at'], 'new' => $newAt];
    }

    $waived = $waivedBy !== null;
    $quote  = mam_reschedule_quote($requesterId, $cohortId, count($clean));
    if ($quote['limit_exceeded']) throw new RuntimeException('limit_exceeded');

    $feeCents = $waived ? 0 : $quote['fee_usd_cents'];
    // fee==0 (free allowance or waiver) settles immediately; otherwise the
    // student must pay before an instructor sees it.
    $status   = $feeCents > 0 ? 'pending_payment' : 'paid';
    $expires  = $feeCents > 0 ? date('Y-m-d H:i:s', time() + $cfg['expiry_hours'] * 3600) : null;

    $db = mam_db();
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $reqId = mam_db_insert('reschedule_requests', [
            'requester_id'    => $requesterId,
            'cohort_id'       => $cohortId,
            'status'          => $status,
            'reason'          => $reason !== null && $reason !== '' ? $reason : null,
            'session_count'   => count($clean),
            'fee_usd_cents'   => $feeCents,
            'fee_currency'    => 'USD',
            'fx_rate_usd_ngn' => $quote['fx_rate_usd_ngn'],
            'waived'          => $waived ? 1 : 0,
            'waived_reason'   => $waived ? $waivedReason : null,
            'paid_at'         => $feeCents > 0 ? null : $now,
            'payment_provider'=> $feeCents > 0 ? null : ($waived ? 'waived' : 'free'),
            'expires_at'      => $expires,
        ]);
        foreach ($clean as $c) {
            mam_db_insert('reschedule_items', [
                'request_id'       => $reqId,
                'session_id'       => $c['session_id'],
                'old_scheduled_at' => $c['old'] !== '' ? $c['old'] : null,
                'new_scheduled_at' => $c['new'],
            ]);
        }
        mam_reschedule_event($reqId, 'created', $requesterId, [
            'sessions' => count($clean), 'fee_usd_cents' => $feeCents, 'waived' => $waived,
        ]);
        if ($feeCents === 0) {
            mam_reschedule_event($reqId, 'paid', $waivedBy ?? $requesterId, ['free' => !$waived, 'waived' => $waived]);
        }
        if ($own) $db->commit();
    } catch (\Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }

    if ($feeCents === 0) mam_reschedule_after_paid($reqId, $waivedBy ?? $requesterId);
    else mam_reschedule_notify_requester($reqId, 'Reschedule request created',
        'Your reschedule request is awaiting payment.');

    return mam_reschedule_get($reqId);
}

/** Record a state-transition event (audit trail). detail is JSON-encoded. */
function mam_reschedule_event(int $requestId, string $event, ?int $actorId, $detail = null): int {
    return mam_db_insert('reschedule_events', [
        'request_id' => $requestId,
        'event'      => $event,
        'actor_id'   => $actorId,
        'detail'     => $detail === null ? null : (is_string($detail) ? $detail : json_encode($detail)),
    ]);
}

/** Load a request with its items and events, or null. */
function mam_reschedule_get(int $requestId): ?array {
    $req = mam_db_row('SELECT * FROM reschedule_requests WHERE id = ?', [$requestId]);
    if (!$req) return null;
    $req['items']  = mam_db_all('SELECT * FROM reschedule_items WHERE request_id = ? ORDER BY id', [$requestId]);
    $req['events'] = mam_db_all('SELECT * FROM reschedule_events WHERE request_id = ? ORDER BY id', [$requestId]);
    return $req;
}

/**
 * Settle a pending_payment request from the student's wallet. Debits
 * fee_usd_cents (wallet is USD-denominated cents), records a purpose=reschedule
 * payment on the Part A money model so it counts as honest revenue, flips the
 * request to 'paid', and runs the post-payment finalisation. Throws a stable
 * error code on any rejection (disabled / not found / wrong owner / wrong state
 * / expired / insufficient_credit).
 */
function mam_reschedule_pay_wallet(int $requestId, int $userId): array {
    if (!mam_reschedule_enabled()) throw new RuntimeException('reschedule_disabled');
    $req = mam_db_row('SELECT * FROM reschedule_requests WHERE id = ?', [$requestId]);
    if (!$req) throw new RuntimeException('not_found');
    if ((int) $req['requester_id'] !== $userId) throw new RuntimeException('forbidden');
    if ((string) $req['status'] !== 'pending_payment') throw new RuntimeException('illegal_transition');
    if ($req['expires_at'] !== null && (string) $req['expires_at'] < date('Y-m-d H:i:s')) {
        throw new RuntimeException('expired');
    }
    $feeCents = (int) $req['fee_usd_cents'];
    if ($feeCents <= 0) throw new RuntimeException('nothing_to_pay');

    $payRef = 'RSCH-' . $requestId . '-' . bin2hex(random_bytes(4));
    $now    = date('Y-m-d H:i:s');
    $rate   = $req['fx_rate_usd_ngn'] !== null ? (float) $req['fx_rate_usd_ngn'] : mam_usd_to_ngn_rate(false);

    $db  = mam_db();
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        // Debit first — throws insufficient_credit and aborts the txn if short.
        mam_wallet_post($userId, -$feeCents, 'reschedule_fee', $userId, $payRef,
            'Reschedule request #' . $requestId);
        mam_db_insert('payments', [
            'application_id'   => null,
            'reference'        => $payRef,
            'provider'         => 'wallet',
            'purpose'          => 'reschedule',
            'status'           => 'succeeded',
            'amount'           => $feeCents / 100.0,
            'currency'         => 'USD',
            'amount_minor'     => $feeCents,
            'amount_usd_cents' => $feeCents,
            'fx_rate_usd_ngn'  => $rate,
            'fx_backfilled'    => 0,
            'verified_at'      => $now,
        ]);
        mam_db_update('reschedule_requests', [
            'status' => 'paid', 'paid_at' => $now, 'payment_ref' => $payRef,
            'payment_provider' => 'wallet', 'updated_at' => $now,
        ], ['id' => $requestId]);
        mam_reschedule_event($requestId, 'paid', $userId, ['provider' => 'wallet', 'ref' => $payRef, 'usd_cents' => $feeCents]);
        if ($own) $db->commit();
    } catch (\Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        if ($e->getMessage() === 'insufficient_credit') throw new RuntimeException('insufficient_credit');
        throw $e;
    }

    mam_reschedule_after_paid($requestId, $userId);
    return mam_reschedule_get($requestId);
}

/**
 * Provider settlement seam — flips pending_payment → paid for a card/bank
 * payment settled elsewhere (a future payment-init purpose=reschedule + webhook
 * would call this once the payments row is 'succeeded'). Kept minimal and
 * separate from the wallet path so both share the same post-payment finalise.
 */
function mam_reschedule_mark_paid(int $requestId, string $payRef, string $provider = 'provider'): array {
    if (!mam_reschedule_enabled()) throw new RuntimeException('reschedule_disabled');
    $req = mam_db_row('SELECT * FROM reschedule_requests WHERE id = ?', [$requestId]);
    if (!$req) throw new RuntimeException('not_found');
    if ((string) $req['status'] !== 'pending_payment') throw new RuntimeException('illegal_transition');
    $now = date('Y-m-d H:i:s');
    mam_db_update('reschedule_requests', [
        'status' => 'paid', 'paid_at' => $now, 'payment_ref' => $payRef,
        'payment_provider' => $provider, 'updated_at' => $now,
    ], ['id' => $requestId]);
    mam_reschedule_event($requestId, 'paid', null, ['provider' => $provider, 'ref' => $payRef]);
    mam_reschedule_after_paid($requestId, (int) $req['requester_id']);
    return mam_reschedule_get($requestId);
}

/** After a request is settled: either notify the instructor to decide, or —
 *  when approval isn't required — auto-approve and apply immediately. */
function mam_reschedule_after_paid(int $requestId, int $actorId): void {
    $cfg = mam_reschedule_config();
    if (!$cfg['approval_required']) {
        // Auto-approve + apply. A conflict/illegal move throws; the request
        // stays 'paid' and the error surfaces to the caller.
        mam_reschedule_apply($requestId, $actorId, true);
        return;
    }
    mam_reschedule_notify_instructor($requestId, 'Reschedule request to review',
        'A paid reschedule request is awaiting your approval.');
}

/**
 * Instructor/admin approves a paid request, then applies the moves. Kept as one
 * call because approval IS the decision to execute; apply() does the actual
 * session moves and terminal transition. Throws on wrong state.
 */
function mam_reschedule_approve(int $requestId, int $actorId, ?string $note = null): array {
    if (!mam_reschedule_enabled()) throw new RuntimeException('reschedule_disabled');
    $req = mam_db_row('SELECT status FROM reschedule_requests WHERE id = ?', [$requestId]);
    if (!$req) throw new RuntimeException('not_found');
    if ((string) $req['status'] !== 'paid') throw new RuntimeException('illegal_transition');
    $now = date('Y-m-d H:i:s');
    mam_db_update('reschedule_requests', [
        'status' => 'approved', 'decided_by' => $actorId, 'decided_at' => $now,
        'decision_note' => $note, 'updated_at' => $now,
    ], ['id' => $requestId]);
    mam_reschedule_event($requestId, 'approved', $actorId, $note !== null ? ['note' => $note] : null);
    return mam_reschedule_apply($requestId, $actorId);
}

/**
 * Apply the moves of an approved (or, when $fromPaid, a just-paid auto-approved)
 * request through the existing audited reschedule writer, then mark the request
 * 'applied'. Each move re-validates the session is still 'scheduled' at apply
 * time (it may have been cancelled/held since approval). Conflicts are recorded
 * on the event but do not block — matching the staff reschedule route, where
 * conflicts warn rather than veto.
 */
function mam_reschedule_apply(int $requestId, int $actorId, bool $fromPaid = false): array {
    $req = mam_db_row('SELECT * FROM reschedule_requests WHERE id = ?', [$requestId]);
    if (!$req) throw new RuntimeException('not_found');
    $allowed = $fromPaid ? ['paid', 'approved'] : ['approved'];
    if (!in_array((string) $req['status'], $allowed, true)) throw new RuntimeException('illegal_transition');

    $items = mam_db_all('SELECT * FROM reschedule_items WHERE request_id = ? ORDER BY id', [$requestId]);
    $now   = date('Y-m-d H:i:s');
    $allConflicts = [];

    $db  = mam_db();
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        foreach ($items as $it) {
            $sid   = (int) $it['session_id'];
            $newAt = (string) $it['new_scheduled_at'];
            $sess  = mam_db_row('SELECT id, cohort_id, duration_min, room, status FROM class_sessions WHERE id = ?', [$sid]);
            if (!$sess || (string) $sess['status'] !== 'scheduled') {
                // Session vanished or is no longer movable — skip it, note it.
                mam_reschedule_event($requestId, 'apply_skipped', $actorId, ['session_id' => $sid, 'reason' => 'not_scheduled']);
                continue;
            }
            // Non-blocking conflict record (instructor already approved).
            $co = mam_db_row('SELECT instructor_id FROM cohorts WHERE id = ?', [(int) $sess['cohort_id']]);
            $instructorId = $co && $co['instructor_id'] ? (int) $co['instructor_id'] : 0;
            $room = isset($sess['room']) && $sess['room'] !== '' ? (string) $sess['room'] : null;
            if (function_exists('mam_lms_detect_conflicts') && ($instructorId > 0 || $room !== null)) {
                $conf = mam_lms_detect_conflicts($instructorId, $newAt, (int) ($sess['duration_min'] ?? 60), $sid, $room);
                if ($conf) $allConflicts[$sid] = $conf;
            }
            mam_lms_reschedule_session($sid, $newAt, 'Reschedule request #' . $requestId, $actorId);
            mam_db_update('reschedule_items', ['applied' => 1], ['id' => (int) $it['id']]);
        }
        mam_db_update('reschedule_requests', [
            'status' => 'applied', 'applied_at' => $now, 'updated_at' => $now,
        ], ['id' => $requestId]);
        mam_reschedule_event($requestId, 'applied', $actorId,
            $allConflicts ? ['conflicts' => $allConflicts] : null);
        if ($own) $db->commit();
    } catch (\Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    mam_reschedule_notify_requester($requestId, 'Reschedule approved',
        'Your class reschedule has been applied.');
    return mam_reschedule_get($requestId);
}

/**
 * Instructor/admin declines a paid or approved request. If money was taken, it
 * is refunded to the student's wallet and the settlement payment is marked
 * 'refunded' so it drops out of the revenue KPI. Terminal.
 */
function mam_reschedule_decline(int $requestId, int $actorId, ?string $note = null): array {
    if (!mam_reschedule_enabled()) throw new RuntimeException('reschedule_disabled');
    $req = mam_db_row('SELECT * FROM reschedule_requests WHERE id = ?', [$requestId]);
    if (!$req) throw new RuntimeException('not_found');
    if (!in_array((string) $req['status'], ['paid', 'approved'], true)) throw new RuntimeException('illegal_transition');
    mam_reschedule_terminate($req, 'declined', $actorId, $note);
    mam_reschedule_notify_requester($requestId, 'Reschedule declined',
        'Your reschedule request was declined' . ($note ? ': ' . $note : '.') );
    return mam_reschedule_get($requestId);
}

/**
 * Requester cancels their own request before it is applied. Refunds if paid.
 * Terminal.
 */
function mam_reschedule_cancel(int $requestId, int $userId): array {
    if (!mam_reschedule_enabled()) throw new RuntimeException('reschedule_disabled');
    $req = mam_db_row('SELECT * FROM reschedule_requests WHERE id = ?', [$requestId]);
    if (!$req) throw new RuntimeException('not_found');
    if ((int) $req['requester_id'] !== $userId) throw new RuntimeException('forbidden');
    if (!in_array((string) $req['status'], ['pending_payment', 'paid', 'approved'], true)) {
        throw new RuntimeException('illegal_transition');
    }
    mam_reschedule_terminate($req, 'cancelled', $userId, null);
    return mam_reschedule_get($requestId);
}

/** Shared terminal transition (declined/cancelled) with wallet refund of a
 *  wallet-settled fee and revenue-safe payment reversal. */
function mam_reschedule_terminate(array $req, string $status, ?int $actorId, ?string $note): void {
    $requestId = (int) $req['id'];
    $now = date('Y-m-d H:i:s');
    $feeCents = (int) $req['fee_usd_cents'];
    $wasPaid  = (string) $req['status'] === 'paid' || (string) $req['status'] === 'approved';
    $refunded = false;

    $db  = mam_db();
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        mam_db_update('reschedule_requests', [
            'status' => $status, 'decided_by' => $actorId, 'decided_at' => $now,
            'decision_note' => $note, 'updated_at' => $now,
        ], ['id' => $requestId]);
        mam_reschedule_event($requestId, $status, $actorId, $note !== null ? ['note' => $note] : null);

        // Refund only real money: a wallet settlement of a positive fee.
        if ($wasPaid && $feeCents > 0 && (string) $req['payment_provider'] === 'wallet') {
            $refundRef = 'RSCHR-' . $requestId . '-' . bin2hex(random_bytes(4));
            mam_wallet_post((int) $req['requester_id'], $feeCents, 'reschedule_refund', $actorId, $refundRef,
                'Refund for reschedule request #' . $requestId);
            // Drop the original settlement out of revenue.
            if ($req['payment_ref']) {
                mam_db_update('payments', ['status' => 'refunded', 'updated_at' => $now],
                    ['reference' => (string) $req['payment_ref']]);
            }
            mam_reschedule_event($requestId, 'refunded', $actorId, ['usd_cents' => $feeCents, 'ref' => $refundRef]);
            $refunded = true;
        }
        if ($own) $db->commit();
    } catch (\Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    if ($refunded) mam_reschedule_notify_requester($requestId, 'Reschedule refunded',
        'Your reschedule fee has been refunded to your wallet.');
}

/**
 * Sweep pending_payment requests whose payment window has lapsed → expired.
 * Never paid, so no refund. Returns the number expired. For the jobs runner.
 */
function mam_reschedule_expire_due(): int {
    if (!mam_reschedule_enabled()) return 0;
    $now  = date('Y-m-d H:i:s');
    $due  = mam_db_all(
        "SELECT id FROM reschedule_requests WHERE status = 'pending_payment' AND expires_at IS NOT NULL AND expires_at < ?",
        [$now]
    );
    $n = 0;
    foreach ($due as $r) {
        $id = (int) $r['id'];
        mam_db_update('reschedule_requests', ['status' => 'expired', 'updated_at' => $now], ['id' => $id]);
        mam_reschedule_event($id, 'expired', null, null);
        $n++;
    }
    return $n;
}

/** Requests a student has raised, newest first (optionally status-filtered). */
function mam_reschedule_list_for_student(int $userId, ?string $status = null): array {
    if ($status !== null && $status !== '') {
        return mam_db_all(
            'SELECT * FROM reschedule_requests WHERE requester_id = ? AND status = ? ORDER BY id DESC',
            [$userId, $status]
        );
    }
    return mam_db_all('SELECT * FROM reschedule_requests WHERE requester_id = ? ORDER BY id DESC', [$userId]);
}

/** Paid requests awaiting a decision for cohorts this instructor teaches. */
function mam_reschedule_list_for_instructor(int $instructorId, string $status = 'paid'): array {
    return mam_db_all(
        "SELECT rr.*, u.name AS requester_name, c.title AS cohort_title
           FROM reschedule_requests rr
           JOIN cohorts c ON c.id = rr.cohort_id
           LEFT JOIN users u ON u.id = rr.requester_id
          WHERE c.instructor_id = ? AND rr.status = ?
          ORDER BY rr.id DESC",
        [$instructorId, $status]
    );
}

/* ── notifications (best-effort; never block the money path) ─────────────── */
function mam_reschedule_notify_requester(int $requestId, string $title, string $body): void {
    if (!function_exists('mam_notify')) return;
    $req = mam_db_row('SELECT requester_id FROM reschedule_requests WHERE id = ?', [$requestId]);
    if ($req && (int) $req['requester_id'] > 0) {
        mam_notify((int) $req['requester_id'], 'reschedule', $title, $body, null);
    }
}
function mam_reschedule_notify_instructor(int $requestId, string $title, string $body): void {
    if (!function_exists('mam_notify')) return;
    $row = mam_db_row(
        'SELECT c.instructor_id FROM reschedule_requests rr JOIN cohorts c ON c.id = rr.cohort_id WHERE rr.id = ?',
        [$requestId]
    );
    if ($row && (int) ($row['instructor_id'] ?? 0) > 0) {
        mam_notify((int) $row['instructor_id'], 'reschedule', $title, $body, null);
    }
}
