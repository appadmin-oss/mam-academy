<?php
/**
 * Paystack scenario guards (schema v106).
 *
 * The audit (2026-11-22) fixed what was wrong; this is what stops the next set
 * of things going wrong. Each guard is a function here so verify, the webhook,
 * the reconcile sweep and init all decide the same way, and so the suite can
 * test the decision without calling Paystack.
 *
 *   1  test/live mismatch     mam_paystack_mode(), mam_paystack_domain_ok()
 *   2  retry after closing    payment_attempts, mam_payment_resolve_ref(),
 *                             mam_paystack_start_attempt()
 *   3  double-click           the open attempt inside MAM_PAY_ATTEMPT_REUSE_SEC
 *   4  no longer payable      mam_payment_target_closed() at init AND at settle
 *   5  amount sanity          mam_paystack_amount_problem()
 *   6  refunds and disputes   mam_paystack_handle_gateway_event()
 *   7  missed webhooks        mam_paystack_reconcile_sweep()
 *   8  verify-time checks     mam_paystack_tx_check() (amount in kobo, NGN,
 *                             reference belongs to this payment)
 *
 * Nothing here calls mam_fail(): admin/index.php and run-jobs.php reach these
 * functions too. They return a decision; a route turns it into an answer.
 */
declare(strict_types=1);

/** A double-click (or a second tab) within this many seconds reuses the open checkout. */
const MAM_PAY_ATTEMPT_REUSE_SEC = 120;

/** What payments.gateway_state may hold. NULL is the normal case. */
const MAM_PAY_GATEWAY_STATES = ['refund_due', 'refund_pending', 'refunded', 'refund_failed', 'disputed', 'dispute_won', 'dispute_lost'];

/** Application states that can no longer take money. */
const MAM_PAY_CLOSED_APP_STATES = ['cancelled', 'expired', 'rejected', 'declined', 'withdrawn'];

/** Store order states that can no longer take money. */
const MAM_PAY_CLOSED_ORDER_STATES = ['cancelled', 'refunded', 'void'];

/** The largest single card charge init will open, in naira, unless the setting says otherwise. */
const MAM_PAY_DEFAULT_MAX_NGN = 5000000;

/* ── 1. Test or live ─────────────────────────────────────────────────────── */

/** 'live' or 'test' from the secret key's prefix; null when it says neither. */
function mam_paystack_mode(): ?string {
    $k = trim((string) mam_env('PAYSTACK_SECRET_KEY', ''));
    if (str_starts_with($k, 'sk_live_')) return 'live';
    if (str_starts_with($k, 'sk_test_')) return 'test';
    return null;
}

/**
 * Does this transaction belong to the mode this install takes money in? A test
 * charge (Paystack's `domain: "test"`) must never settle a live install's
 * payment, and a live charge must never land on a test install. When either side
 * is unknown there is nothing to compare, so it passes.
 */
function mam_paystack_domain_ok(?array $tx): bool {
    $mode = mam_paystack_mode();
    $domain = strtolower(trim((string) ($tx['domain'] ?? '')));
    if ($mode === null || $domain === '') return true;
    return $domain === $mode;
}

/* ── Events: what happened beyond settling ──────────────────────────────── */

/** Record an event against a payment. Never throws: it runs where money already moved. */
function mam_payment_event(?int $paymentId, string $reference, string $kind, array $detail = []): void {
    try {
        mam_db_insert('payment_events', [
            'payment_id' => $paymentId ?: null,
            'reference'  => $reference !== '' ? $reference : null,
            'kind'       => $kind,
            'detail'     => $detail ? (string) json_encode($detail, JSON_UNESCAPED_SLASHES) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        mam_log('error', 'payment_event_write_failed', ['ref' => $reference, 'kind' => $kind, 'err' => $e->getMessage()]);
    }
    mam_audit('payment_' . $kind, 'payment', $paymentId ?: null, ['ref' => $reference] + $detail);
}

/** Set a payment's gateway state (one of MAM_PAY_GATEWAY_STATES, or null to clear). */
function mam_payment_set_gateway_state(int $paymentId, ?string $state): void {
    if ($state !== null && !in_array($state, MAM_PAY_GATEWAY_STATES, true)) {
        throw new InvalidArgumentException('bad_gateway_state');
    }
    mam_db_update('payments', ['gateway_state' => $state, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $paymentId]);
}

/** Tell every active administrator. Best-effort: a notification failing must not undo a payment. */
function mam_payment_tell_office(string $title, string $body): void {
    try {
        if (!function_exists('mam_notify')) return;
        $admins = mam_db_all("SELECT DISTINCT u.id FROM users u LEFT JOIN user_roles r ON r.user_id = u.id
                               WHERE u.status = 'active' AND (u.role = 'admin' OR r.role = 'admin')");
        foreach ($admins as $a) mam_notify((int) $a['id'], 'payment', $title, $body, '/admin/console.php?v=invoices');
    } catch (\Throwable $e) {
        mam_log('warn', 'payment_office_notify_failed', ['err' => $e->getMessage()]);
    }
}

/* ── 2/3. Attempts: one payment, many gateway references ─────────────────── */

/**
 * The payments row a reference belongs to: its own reference, or one of its
 * attempts. The row comes back with `_charged_ref` set to the reference that was
 * looked up — the one the gateway knows the charge by.
 */
function mam_payment_resolve_ref(string $ref): ?array {
    $ref = trim($ref);
    if ($ref === '') return null;
    $p = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$ref]);
    if (!$p) {
        $a = mam_db_row('SELECT payment_id FROM payment_attempts WHERE reference = ?', [$ref]);
        if ($a) $p = mam_db_row('SELECT * FROM payments WHERE id = ?', [(int) $a['payment_id']]);
    }
    if ($p) $p['_charged_ref'] = $ref;
    return $p ?: null;
}

/** Every gateway reference this payment has been charged under (its own first). */
function mam_payment_charge_refs(array $payment): array {
    $refs = [(string) $payment['reference']];
    foreach (mam_db_all('SELECT reference FROM payment_attempts WHERE payment_id = ? ORDER BY id', [(int) $payment['id']]) as $r) {
        if (!in_array((string) $r['reference'], $refs, true)) $refs[] = (string) $r['reference'];
    }
    return $refs;
}

/** The newest attempt still worth reusing: opened within the window and carrying a checkout link. */
function mam_payment_open_attempt(int $paymentId, int $windowSec = MAM_PAY_ATTEMPT_REUSE_SEC): ?array {
    $a = mam_db_row('SELECT * FROM payment_attempts WHERE payment_id = ? ORDER BY id DESC LIMIT 1', [$paymentId]);
    if (!$a || (string) ($a['authorization_url'] ?? '') === '') return null;
    $age = time() - (int) strtotime((string) $a['created_at']);
    return $age <= $windowSec ? $a : null;
}

/**
 * The reference the next attempt opens under: the payment's own reference for
 * the first attempt (so the common case is unchanged), then `<ref>-A2`, `-A3`…
 * Paystack refuses a reference it has already seen, which is what made a retry
 * after closing the checkout fail.
 */
function mam_payment_next_attempt_ref(array $payment): string {
    $n = (int) (mam_db_row('SELECT COUNT(*) n FROM payment_attempts WHERE payment_id = ?', [(int) $payment['id']])['n'] ?? 0);
    return $n === 0 ? (string) $payment['reference'] : ((string) $payment['reference'] . '-A' . ($n + 1));
}

/** Claim an attempt reference. False when somebody else claimed it first (UNIQUE). */
function mam_payment_claim_attempt(int $paymentId, string $ref): bool {
    try {
        mam_db_insert('payment_attempts', ['payment_id' => $paymentId, 'reference' => $ref, 'created_at' => date('Y-m-d H:i:s')]);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Open (or reuse) a Paystack checkout for a payment. `$initializer` and
 * `$verifier` default to the real gateway; the suite passes fakes.
 *
 * Returns ['reference', 'authorization_url', 'access_code', 'reused' => bool,
 *          'settled' => bool, 'error' => ?string].
 *  - A second press inside MAM_PAY_ATTEMPT_REUSE_SEC gets the SAME checkout:
 *    no second charge is opened (guard 3). Two presses at once race for the same
 *    attempt reference; the UNIQUE index lets one win and the other reuses it.
 *  - Otherwise a fresh attempt reference (guard 2). If Paystack still says the
 *    reference is a duplicate, the charge under it is checked first: paid → it
 *    is settled and the caller is told; not paid → one more fresh attempt.
 */
function mam_paystack_start_attempt(array $payment, string $email, int $amountKobo, array $metadata = [],
                                    ?string $callbackPath = null, ?callable $initializer = null, ?callable $verifier = null): array {
    $initializer = $initializer ?? 'mam_paystack_initialize';
    $verifier    = $verifier ?? 'mam_paystack_verify';
    $pid = (int) $payment['id'];
    $out = ['reference' => null, 'authorization_url' => null, 'access_code' => null, 'reused' => false, 'settled' => false, 'error' => null];

    if ($open = mam_payment_open_attempt($pid)) {
        return ['reference' => (string) $open['reference'], 'authorization_url' => (string) $open['authorization_url'],
                'access_code' => $open['access_code'] !== null ? (string) $open['access_code'] : null,
                'reused' => true, 'settled' => false, 'error' => null];
    }

    for ($try = 0; $try < 2; $try++) {
        $ref = mam_payment_next_attempt_ref($payment);
        if (!mam_payment_claim_attempt($pid, $ref)) {
            // A simultaneous press claimed this reference a moment ago: give back its checkout once it has one.
            for ($w = 0; $w < 10; $w++) {
                $a = mam_db_row('SELECT * FROM payment_attempts WHERE reference = ?', [$ref]);
                if ($a && (string) ($a['authorization_url'] ?? '') !== '') {
                    return ['reference' => $ref, 'authorization_url' => (string) $a['authorization_url'],
                            'access_code' => $a['access_code'] !== null ? (string) $a['access_code'] : null,
                            'reused' => true, 'settled' => false, 'error' => null];
                }
                usleep(150000);
            }
            continue;
        }
        $meta = $metadata + ['payment_reference' => (string) $payment['reference'], 'attempt_reference' => $ref];
        try {
            $resp = $initializer($email, $amountKobo, $ref, $meta, $callbackPath);
        } catch (\Throwable $e) {
            mam_log('error', 'paystack_attempt_init_failed', ['ref' => $ref, 'err' => $e->getMessage()]);
            $out['error'] = 'init_failed';
            return $out;
        }
        $body = (array) ($resp['data'] ?? []);
        if (!empty($body['status'])) {
            $url = (string) ($body['data']['authorization_url'] ?? '');
            $code = isset($body['data']['access_code']) ? (string) $body['data']['access_code'] : null;
            mam_db_update('payment_attempts', ['authorization_url' => $url !== '' ? $url : null, 'access_code' => $code], ['reference' => $ref]);
            return ['reference' => $ref, 'authorization_url' => $url, 'access_code' => $code, 'reused' => false, 'settled' => false, 'error' => null];
        }
        $msg = strtolower((string) ($body['message'] ?? ''));
        mam_log('error', 'paystack_init_rejected', ['ref' => $ref, 'http' => $resp['code'] ?? 0, 'message' => (string) ($body['message'] ?? '')]);
        if (strpos($msg, 'duplicate') === false) { $out['error'] = 'paystack_init_failed'; return $out; }

        // Paystack has seen this reference. Was it paid?
        try {
            $v = $verifier($ref);
            $tx = $v['data']['data'] ?? null;
            if (is_array($tx)) {
                $r = mam_paystack_apply_tx($payment, $tx, 'init_duplicate');
                if (($r['outcome'] ?? '') === 'settled' || ($r['outcome'] ?? '') === 'already') {
                    $out['reference'] = $ref; $out['settled'] = true; return $out;
                }
            }
        } catch (\Throwable $e) {
            mam_log('warn', 'paystack_duplicate_verify_failed', ['ref' => $ref, 'err' => $e->getMessage()]);
        }
        // Not paid: the loop opens one more fresh attempt.
    }
    $out['error'] = $out['error'] ?? 'paystack_init_failed';
    return $out;
}

/**
 * A guardian's checkout for this invoice opened within the reuse window and
 * still pending: the payment row's reference, its open checkout link and its
 * kobo. `$feeMinor` null means "the balance" and matches any amount.
 */
function mam_fee_recent_checkout(int $payerId, int $invoiceId, ?int $feeMinor): ?array {
    $p = mam_db_row("SELECT * FROM payments WHERE purpose = 'fee' AND status = 'pending' AND fee_payer_id = ? AND fee_invoice_id = ?"
                    . ($feeMinor !== null ? ' AND fee_minor = ?' : '') . ' ORDER BY id DESC LIMIT 1',
                    $feeMinor !== null ? [$payerId, $invoiceId, $feeMinor] : [$payerId, $invoiceId]);
    if (!$p) return null;
    $a = mam_payment_open_attempt((int) $p['id']);
    return $a ? ['reference' => (string) $a['reference'], 'authorization_url' => (string) $a['authorization_url'], 'amount_minor' => (int) $p['amount_minor']] : null;
}

/* ── 4. No longer payable ──────────────────────────────────────────────── */

/**
 * Why a payment's target can no longer take money, or null when it can.
 * Asked at init (refuse to open a charge) and at settle (credit nothing new to a
 * closed target; the money is recorded as refund due).
 */
function mam_payment_target_closed(array $payment): ?string {
    $purpose = (string) ($payment['purpose'] ?? 'tuition');
    if ($purpose === 'store') {
        $o = mam_db_row('SELECT status FROM store_orders WHERE reference = ?', [(string) $payment['reference']]);
        if (!$o) return 'order_not_found';
        return in_array((string) $o['status'], MAM_PAY_CLOSED_ORDER_STATES, true) ? 'order_' . $o['status'] : null;
    }
    if ($purpose === 'fee') {
        $i = mam_db_row('SELECT status, total_minor, paid_minor FROM fee_invoices WHERE id = ?', [(int) ($payment['fee_invoice_id'] ?? 0)]);
        if (!$i) return 'invoice_not_found';
        if (!in_array((string) $i['status'], ['issued', 'part_paid'], true)) return 'invoice_' . $i['status'];
        return ((int) $i['total_minor'] - (int) $i['paid_minor']) <= 0 ? 'invoice_paid' : null;
    }
    $a = mam_db_row('SELECT a.status, c.email FROM applications a LEFT JOIN customers c ON c.id = a.customer_id WHERE a.id = ?',
                    [(int) ($payment['application_id'] ?? 0)]);
    if (!$a) return 'application_not_found';
    // An erased applicant's address is an RFC 2606 .invalid tombstone (CLAUDE.md, erasure).
    if (preg_match('/\.invalid$/i', (string) ($a['email'] ?? ''))) return 'application_erased';
    return in_array((string) $a['status'], MAM_PAY_CLOSED_APP_STATES, true) ? 'application_' . $a['status'] : null;
}

/** The sentence a page shows for a closed target. */
function mam_payment_closed_message(string $reason): string {
    if (str_starts_with($reason, 'application_')) return 'This application is closed, so it cannot be paid. Please contact the academy if you think this is wrong.';
    if (str_starts_with($reason, 'order_')) return 'This order has been cancelled, so it cannot be paid.';
    if ($reason === 'invoice_paid') return 'This invoice is already paid in full.';
    if (str_starts_with($reason, 'invoice_')) return 'This invoice can no longer be paid online.';
    return 'This payment can no longer be made.';
}

/**
 * Money arrived for a closed target, or a second charge arrived for a payment
 * already settled under another attempt. Nothing new is credited; the payment
 * is marked refund_due, the event recorded, and the office told.
 */
function mam_payment_flag_refund_due(array $payment, string $why, array $detail = []): void {
    $pid = (int) $payment['id'];
    try { mam_payment_set_gateway_state($pid, 'refund_due'); } catch (\Throwable $e) { mam_log('error', 'refund_due_flag_failed', ['ref' => $payment['reference'], 'err' => $e->getMessage()]); }
    mam_payment_event($pid, (string) $payment['reference'], 'refund_due', ['why' => $why] + $detail);
    mam_log('warn', 'payment_refund_due', ['ref' => $payment['reference'], 'why' => $why] + $detail);
    mam_payment_tell_office('A payment needs refunding', 'Payment ' . $payment['reference'] . ': ' . $why . '. Nothing was credited for it. Refund it from the Paystack dashboard and record it.');
}

/* ── 5. Amount sanity ──────────────────────────────────────────────────── */

/** The ceiling, in kobo. Setting `paystack_max_charge_ngn` (whole naira); MAM_PAY_DEFAULT_MAX_NGN otherwise. */
function mam_paystack_max_kobo(): int {
    $ngn = function_exists('mam_setting_num') ? (float) mam_setting_num('paystack_max_charge_ngn', MAM_PAY_DEFAULT_MAX_NGN) : MAM_PAY_DEFAULT_MAX_NGN;
    if ($ngn <= 0) $ngn = MAM_PAY_DEFAULT_MAX_NGN;
    return (int) round($ngn * 100);
}

/** Why a server-computed charge must not be opened, or null when it may. */
function mam_paystack_amount_problem(int $amountKobo, string $currency): ?string {
    if (strtoupper(trim($currency)) !== 'NGN') return 'currency_not_supported';
    if ($amountKobo <= 0) return 'zero_amount';
    if ($amountKobo < 100) return 'amount_too_small';          // Paystack refuses under ₦1
    if ($amountKobo > mam_paystack_max_kobo()) return 'amount_too_large';
    return null;
}

/* ── 8. What a transaction must be before it settles anything ─────────── */

/**
 * Check a gateway transaction against the payment it claims to pay.
 * Returns ['ok' => bool, 'terminal' => bool, 'reason' => ?string].
 * `terminal` = the charge has finished (succeeded or failed), so a refusal may be
 * recorded; a charge still in flight is neither ok nor terminal.
 */
function mam_paystack_tx_check(array $payment, ?array $tx): array {
    if (!is_array($tx)) return ['ok' => false, 'terminal' => false, 'reason' => 'no_transaction'];
    $status = strtolower((string) ($tx['status'] ?? ''));
    if (!in_array($status, ['success', 'failed', 'reversed', 'abandoned'], true)) {
        return ['ok' => false, 'terminal' => false, 'reason' => 'pending'];
    }
    if ($status !== 'success') return ['ok' => false, 'terminal' => true, 'reason' => 'status_' . $status];
    // Reference ownership: the charge must be under this payment's own reference or one of its attempts.
    $txRef = (string) ($tx['reference'] ?? '');
    if ($txRef !== '' && !in_array($txRef, mam_payment_charge_refs($payment), true)) {
        return ['ok' => false, 'terminal' => true, 'reason' => 'reference_mismatch'];
    }
    if (!mam_paystack_domain_ok($tx)) return ['ok' => false, 'terminal' => true, 'reason' => 'mode_mismatch'];
    if (!mam_payment_amount_ok($payment, (float) ($tx['amount'] ?? 0), (string) ($tx['currency'] ?? ''))) {
        return ['ok' => false, 'terminal' => true, 'reason' => 'amount_or_currency'];
    }
    return ['ok' => true, 'terminal' => true, 'reason' => null];
}

/**
 * The one decision verify, the webhook, the duplicate-reference recovery and
 * the reconcile sweep all make about a gateway transaction.
 * Returns ['outcome' => settled|already|duplicate_charge|refused|pending|refund_due, 'reason' => ?string, 'settle' => ?array].
 */
function mam_paystack_apply_tx(array $payment, array $tx, string $source): array {
    $chk = mam_paystack_tx_check($payment, $tx);
    $ref = (string) $payment['reference'];
    $txRef = (string) ($tx['reference'] ?? $ref);
    if (!$chk['ok']) {
        if ($chk['reason'] === 'mode_mismatch') {
            mam_log('error', 'paystack_mode_mismatch', ['ref' => $ref, 'tx_ref' => $txRef, 'domain' => $tx['domain'] ?? null, 'mode' => mam_paystack_mode(), 'source' => $source]);
            mam_payment_event((int) $payment['id'], $ref, 'mode_mismatch', ['domain' => (string) ($tx['domain'] ?? ''), 'mode' => (string) mam_paystack_mode(), 'tx_ref' => $txRef, 'source' => $source]);
        }
        return ['outcome' => $chk['terminal'] ? 'refused' : 'pending', 'reason' => $chk['reason'], 'settle' => null];
    }

    $fresh = mam_db_row('SELECT * FROM payments WHERE id = ?', [(int) $payment['id']]) ?: $payment;
    if ((string) $fresh['status'] === 'succeeded') {
        // Settled already. Under the SAME charge this is a repeat; under a DIFFERENT
        // attempt the family paid twice, and the second payment is owed back.
        $settledBy = (string) ($fresh['provider_reference'] ?? '');
        if ($settledBy !== '' && $settledBy !== $txRef) {
            mam_payment_flag_refund_due($fresh, 'paid twice: a second card payment arrived after this was settled', ['second_ref' => $txRef, 'settled_by' => $settledBy, 'source' => $source]);
            return ['outcome' => 'duplicate_charge', 'reason' => 'paid_twice', 'settle' => null];
        }
        return ['outcome' => 'already', 'reason' => null, 'settle' => null];
    }

    $raw = (string) (json_encode(mam_paystack_redact($tx)) ?: '{}');
    $settle = mam_payment_settle($fresh, $tx, $raw, $txRef);
    if (!empty($settle['refund_due'])) return ['outcome' => 'refund_due', 'reason' => $settle['refund_due'], 'settle' => $settle];
    if (!empty($settle['already'])) {
        // Lost the compare-and-set to another caller between the read and the claim.
        $after = mam_db_row('SELECT provider_reference FROM payments WHERE id = ?', [(int) $payment['id']]);
        if ($after && (string) $after['provider_reference'] !== '' && (string) $after['provider_reference'] !== $txRef) {
            mam_payment_flag_refund_due($fresh, 'paid twice: a second card payment arrived after this was settled', ['second_ref' => $txRef, 'settled_by' => (string) $after['provider_reference'], 'source' => $source]);
            return ['outcome' => 'duplicate_charge', 'reason' => 'paid_twice', 'settle' => $settle];
        }
        return ['outcome' => 'already', 'reason' => null, 'settle' => $settle];
    }
    return ['outcome' => 'settled', 'reason' => null, 'settle' => $settle];
}

/* ── 6. Refunds and disputes ────────────────────────────────────────────── */

/** The transaction reference a refund or dispute event is about. */
function mam_paystack_event_tx_ref(array $data): string {
    foreach ([$data['transaction_reference'] ?? null, $data['transaction']['reference'] ?? null, $data['reference'] ?? null] as $r) {
        if (is_string($r) && trim($r) !== '') return trim($r);
    }
    return '';
}

/**
 * Handle a refund or dispute webhook (already signature-checked and deduplicated
 * by the route). Records it against the payment, sets its gateway state, tells
 * the office. Never unenrols, cancels or reverses anything on its own: those are
 * a person's decisions. Returns ['handled' => bool, 'state' => ?string, 'reason' => ?string].
 */
function mam_paystack_handle_gateway_event(string $eventType, array $data): array {
    $map = [
        'refund.pending'         => 'refund_pending',
        'refund.processing'      => 'refund_pending',
        'refund.processed'       => 'refunded',
        'refund.failed'          => 'refund_failed',
        'charge.dispute.create'  => 'disputed',
        'charge.dispute.remind'  => 'disputed',
        'charge.dispute.resolve' => null,   // decided from the resolution below
    ];
    if (!array_key_exists($eventType, $map)) return ['handled' => false, 'state' => null, 'reason' => 'not_a_gateway_event'];
    $txRef = mam_paystack_event_tx_ref($data);
    $payment = $txRef !== '' ? mam_payment_resolve_ref($txRef) : null;
    if (!$payment) {
        mam_log('warn', 'paystack_event_unmatched', ['event' => $eventType, 'tx_ref' => $txRef]);
        mam_payment_event(null, $txRef, 'unmatched_' . str_replace('.', '_', $eventType), ['event' => $eventType]);
        return ['handled' => true, 'state' => null, 'reason' => 'payment_not_found'];
    }
    $state = $map[$eventType];
    if ($eventType === 'charge.dispute.resolve') {
        $res = strtolower((string) ($data['resolution'] ?? ''));
        // Paystack: "merchant-accepted" = the merchant accepted the claim (money returned);
        // "declined" = the claim was declined (the merchant keeps it).
        $state = $res === 'declined' ? 'dispute_won' : ($res === 'merchant-accepted' ? 'dispute_lost' : 'disputed');
    }
    $pid = (int) $payment['id'];
    mam_payment_set_gateway_state($pid, $state);
    // Money actually returned. gateway_state = 'refunded' takes it out of
    // Collected; the fee credit it made is voided so the invoice balance comes
    // back. The payment row stays settled and what it paid for is left to a
    // person (section 6's rule) — mam_payment_refund_apply()'s 'gateway' scope.
    if ($state === 'refunded' && in_array((string) $payment['status'], ['succeeded', 'refunded'], true)) {
        try { mam_payment_refund_apply($payment, 'Paystack refund processed', null, 'gateway'); }
        catch (\Throwable $e) { mam_log('error', 'paystack_refund_apply_failed', ['ref' => (string) $payment['reference'], 'err' => $e->getMessage()]); }
    }
    $kind = str_replace('.', '_', $eventType);
    mam_payment_event($pid, (string) $payment['reference'], $kind, [
        'tx_ref' => $txRef, 'amount' => (int) ($data['amount'] ?? ($data['transaction']['amount'] ?? 0)),
        'status' => (string) ($data['status'] ?? ''), 'resolution' => (string) ($data['resolution'] ?? ''),
    ]);
    $titles = [
        'refund_pending' => 'A refund is being processed', 'refunded' => 'A refund went through', 'refund_failed' => 'A refund failed',
        'disputed' => 'A card payment is disputed', 'dispute_won' => 'A dispute was resolved in the academy\'s favour', 'dispute_lost' => 'A dispute was lost: the money went back',
    ];
    mam_payment_tell_office($titles[$state] ?? 'Payment update', 'Payment ' . $payment['reference'] . ' (' . ($payment['purpose'] ?? 'payment') . '). '
        . 'Nobody has been unenrolled or cancelled automatically; decide what follows.');
    return ['handled' => true, 'state' => $state, 'reason' => null];
}

/* ── 7. Missed webhooks ────────────────────────────────────────────────── */

/**
 * Re-verify card payments left pending: older than `$minAgeMin`, oldest first,
 * at most `$batch` per run. A paid one settles through the normal path; one
 * still unpaid after `$abandonHours` is marked abandoned. Never throws.
 * `$verifier` defaults to the real gateway; the suite passes a fake.
 * Returns ['checked', 'settled', 'abandoned', 'errors', 'skipped' => ?string].
 */
function mam_paystack_reconcile_sweep(int $batch = 25, int $minAgeMin = 30, int $abandonHours = 24, ?callable $verifier = null): array {
    $out = ['checked' => 0, 'settled' => 0, 'abandoned' => 0, 'errors' => 0, 'skipped' => null];
    try {
        if (function_exists('mam_automation_on') && !mam_automation_on('payment_reconcile')) { $out['skipped'] = 'automation_off'; return $out; }
        if ($verifier === null && !mam_paystack_configured()) { $out['skipped'] = 'paystack_not_configured'; return $out; }
        $verifier = $verifier ?? 'mam_paystack_verify';
        $cut = date('Y-m-d H:i:s', time() - $minAgeMin * 60);
        $rows = mam_db_all("SELECT * FROM payments WHERE provider = 'paystack' AND status = 'pending' AND created_at <= ?
                             ORDER BY created_at ASC LIMIT " . max(1, min(200, $batch)), [$cut]);
        foreach ($rows as $p) {
            $out['checked']++;
            $paid = false;
            foreach (mam_payment_charge_refs($p) as $ref) {
                try {
                    $v = $verifier($ref);
                    $tx = $v['data']['data'] ?? null;
                    if (!is_array($tx)) continue;
                    $r = mam_paystack_apply_tx($p, $tx, 'reconcile');
                    if (in_array($r['outcome'], ['settled', 'already', 'refund_due'], true)) { $paid = true; if ($r['outcome'] !== 'already') $out['settled']++; break; }
                } catch (\Throwable $e) {
                    $out['errors']++;
                    mam_log('warn', 'paystack_reconcile_verify_failed', ['ref' => $ref, 'err' => $e->getMessage()]);
                }
            }
            if (!$paid && (time() - (int) strtotime((string) $p['created_at'])) >= $abandonHours * 3600) {
                $st = mam_db()->prepare("UPDATE payments SET status = 'abandoned', updated_at = ? WHERE id = ? AND status = 'pending'");
                $st->execute([date('Y-m-d H:i:s'), (int) $p['id']]);
                if ($st->rowCount() > 0) { $out['abandoned']++; mam_payment_event((int) $p['id'], (string) $p['reference'], 'abandoned', ['after_hours' => $abandonHours]); }
            }
        }
        if (function_exists('mam_automation_touch') && $out['checked'] > 0) mam_automation_touch('payment_reconcile');
    } catch (\Throwable $e) {
        $out['errors']++;
        mam_log('error', 'paystack_reconcile_sweep_failed', ['err' => $e->getMessage()]);
    }
    return $out;
}
