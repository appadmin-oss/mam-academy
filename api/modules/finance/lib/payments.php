<?php
/**
 * Payment settlement — the SINGLE source of truth for what a successful charge
 * does. Both the interactive verify endpoint and the provider webhooks call
 * mam_payment_settle() so they can never diverge (previously the webhooks blindly
 * set applications.status='paid' for every charge — wrong for booking fees, and
 * a no-op that lost store orders).
 *
 * Effects are purpose-specific:
 *   store       → mark order paid/delivered, decrement stock, email the receipt
 *   booking_fee → stamp booking_fee_paid_at, email the booking confirmation
 *                 (NEVER marks the application paid — admin enrols after Auditer)
 *   tuition     → status='paid', provision the student portal + email credentials
 *
 * Race-safe + idempotent: the succeeded flip is a conditional UPDATE guarded on
 * status<>'succeeded', so whichever of verify/webhook lands first wins and the
 * other no-ops (no double emails). Never throws — email/provision failures are
 * logged, not fatal.
 */
declare(strict_types=1);

/**
 * Merge an admin marker into an existing provider_response value.
 *
 * provider_response is a JSON column on MySQL/MariaDB. Plain-text appends
 * (e.g. concatenating a note string) are rejected with CONSTRAINT error 4025.
 * This helper decodes the existing value, wraps any legacy plain-text in a
 * JSON envelope (_raw key), merges in the $marker array, and returns valid JSON.
 */
/**
 * May an admin mark this payment refunded / confirm this bank transfer? Returns
 * null when yes, else the sentence the console shows. One decision for both
 * admin actions (admin/index.php), so the console form and the handler cannot
 * disagree about what is allowed.
 *
 *   · A REFUND is only of money that was taken (`succeeded`). Marking a pending
 *     or failed charge "refunded" records money going back that never came in.
 *   · A CONFIRMATION is only of a transfer still waiting (`pending_bank_transfer`).
 *     The handler used to flip ANY unconfirmed row to succeeded — a refunded or
 *     failed one included — which re-credited money already returned.
 *   · Both need a reason, because each is a money decision a person made off the
 *     platform and the audit row is the only record of why; a confirmation also
 *     needs the tick that the money was seen in the account.
 */
function mam_payment_admin_refusal(array $p, string $action, string $note, bool $checked = false): ?string {
    $status = (string) ($p['status'] ?? '');
    if (mb_strlen(trim($note)) < 3) {
        return $action === 'payment_refund' ? 'Say why it was refunded and how the money went back.' : 'Give the reference from the bank statement.';
    }
    if ($action === 'payment_refund') {
        if ($status === 'refunded') return 'That payment is already marked refunded.';
        if ($status !== 'succeeded') return 'Only money that was taken can be refunded — this payment is ' . str_replace('_', ' ', $status) . '.';
        return null;
    }
    if ($action === 'payment_confirm_bank') {
        if ($status === 'succeeded') return 'That transfer is already confirmed.';
        if ($status !== 'pending_bank_transfer') return 'Only a bank transfer that is still waiting can be confirmed — this payment is ' . str_replace('_', ' ', $status) . '.';
        if (!$checked) return 'Tick that you have seen the money arrive in the academy\'s account.';
        return null;
    }
    return 'Unknown action.';
}

function mam_payment_response_merge(?string $existing, array $marker): string {
    $obj = [];
    if ($existing !== null && $existing !== '') {
        $decoded = json_decode($existing, true);
        $obj = is_array($decoded) ? $decoded : ['_raw' => $existing];
    }
    foreach ($marker as $k => $v) {
        $obj[$k] = $v;
    }
    return (string)json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * @param array       $payment      the payments row (must have reference, purpose, application_id, amount)
 * @param array|null  $providerData the verified provider payload (for storage)
 * @param string      $rawResponse  raw provider response JSON (for audit)
 * @param string|null $providerRef  the provider's own reference
 * @return array ['ok'=>bool,'purpose'=>string,'already'=>bool,'portal'=>?string]
 */
function mam_payment_settle(array $payment, ?array $providerData, string $rawResponse, ?string $providerRef = null): array {
    $ref     = (string)$payment['reference'];
    $purpose = (string)($payment['purpose'] ?? 'tuition');
    $out = ['ok' => true, 'purpose' => $purpose, 'already' => false, 'portal' => null];

    /* Atomic, race-safe claim — and, in the SAME transaction, a `settle_effects`
       job. The claim used to commit on its own and the effects (crediting a fee
       invoice, fulfilling an order, enrolling the student) ran after it with
       nothing holding them: a crash or an exception in between left money taken
       and nothing granted, and a retried webhook saw `already` and did nothing
       more. Now the job is written with the claim, so the effects are either
       done or queued, never lost: the request runs them at once, and if that
       throws, the job runner (run-jobs.php) retries them. */
    $db = mam_db();
    $ownTx = !$db->inTransaction();
    $jobId = 0;
    try {
        if ($ownTx) $db->beginTransaction();
        $st = $db->prepare(
            "UPDATE payments SET status='succeeded', provider_reference=?, provider_response=?, verified_at=?, updated_at=? WHERE reference=? AND status<>'succeeded' AND status<>'refunded'"
        );
        $now = date('Y-m-d H:i:s');
        $st->execute([$providerRef, $rawResponse, $now, $now, $ref]);
        if ($st->rowCount() === 0) {
            if ($ownTx) $db->rollBack();
            $out['already'] = true; return $out; // someone else settled it
        }
        $jobId = (int) mam_db_insert('jobs', [
            'kind' => 'settle_effects', 'payload' => json_encode(['ref' => $ref], JSON_UNESCAPED_SLASHES),
            'run_after' => $now, 'status' => 'pending',
        ]);
        if ($ownTx) $db->commit();
    } catch (\Throwable $e) {
        if ($ownTx && $db->inTransaction()) { try { $db->rollBack(); } catch (\Throwable $x) {} }
        mam_log('error', 'settle_claim_failed', ['ref' => $ref, 'err' => $e->getMessage()]);
        $out['ok'] = false; return $out;
    }

    mam_audit('payment_settled', 'payment', (int)($payment['id'] ?? 0), ['ref' => $ref, 'purpose' => $purpose]);

    // Apply the effects now; on failure the queued job finishes them later.
    try {
        $out = array_merge($out, mam_payment_settle_effects($ref, $providerData));
        if ($jobId > 0) mam_db_update('jobs', ['status' => 'done', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $jobId]);
    } catch (\Throwable $e) {
        mam_log('error', 'settle_effects_deferred', ['ref' => $ref, 'job' => $jobId, 'err' => $e->getMessage()]);
        $out['effects_pending'] = true;
    }
    return $out;
}

/**
 * What a settled payment GRANTS: fee credit, store fulfilment, the booking stamp,
 * tuition (paid + account + enrolment). Run by mam_payment_settle() straight
 * after the claim, and by the `settle_effects` job when that fails. Every
 * branch is idempotent — running it twice grants nothing twice — and an
 * exception means "not done, try again", never "done".
 *
 * @return array ['portal'=>?string, 'refund_due'=>?string]
 */
function mam_payment_settle_effects(string $ref, ?array $providerData = null): array {
    $payment = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$ref]);
    if (!$payment) throw new RuntimeException('payment_not_found');
    if ((string) $payment['status'] !== 'succeeded') return []; // refunded or reversed since: nothing to grant
    $purpose = (string) ($payment['purpose'] ?? 'tuition');
    if ($providerData === null && !empty($payment['provider_response'])) {
        $d = json_decode((string) $payment['provider_response'], true);
        if (is_array($d)) $providerData = $d;
    }
    $out = [];

    // Money for a target that can no longer take it (an application cancelled,
    // expired or erased; an order cancelled): the payment is recorded as received,
    // because it was, but NOTHING is credited, enrolled or fulfilled. It is marked
    // refund due for the office (payment-guards.php, guard 4). Fees decide this
    // themselves: a void or already-paid invoice turns the money into a
    // fee_online_unapplied row in mam_payment_settle_fee().
    if ($purpose !== 'fee' && function_exists('mam_payment_target_closed')) {
        $why = mam_payment_target_closed($payment);
        if ($why !== null && !str_ends_with($why, '_not_found')) {
            mam_payment_flag_refund_due($payment, 'paid after it closed (' . $why . ')', ['provider_ref' => (string) ($payment['provider_reference'] ?? '')]);
            $out['refund_due'] = $why;
            return $out;
        }
    }

    if ($purpose === 'store') {
        mam_payment_settle_store($ref);
    } elseif ($purpose === 'fee') {
        // A fee invoice paid online (fees.php). Before the tuition fallback,
        // which would otherwise read a NULL application_id as application 0.
        mam_payment_settle_fee($payment, $providerData);
    } elseif ($purpose === 'booking_fee') {
        mam_payment_settle_booking((int)$payment['application_id']);
    } else { // tuition
        $out['portal'] = mam_payment_settle_tuition((int)$payment['application_id']);
    }
    return $out;
}

/** The job handler: finish a settled payment's effects. True = done. */
function mam_payment_settle_effects_job(array $p): bool {
    $ref = (string) ($p['ref'] ?? '');
    if ($ref === '') return true; // nothing to do — a malformed row must not retry forever
    mam_payment_settle_effects($ref);
    return true;
}

/**
 * Settled payments whose effects never completed: their `settle_effects` job is
 * still pending or has failed. Shown on the console (System health) with a
 * "Finish" button that runs the effects again. A job row is written with every
 * claim since 2026-11-28; payments settled before then are covered by
 * mam_payment_effects_missing_legacy().
 */
function mam_payment_settle_backlog(int $limit = 50): array {
    $rows = mam_db_all(
        "SELECT j.id AS job_id, j.status AS job_status, j.attempts, j.last_error, j.payload, j.created_at
           FROM jobs j WHERE j.kind = 'settle_effects' AND j.status IN ('pending','failed')
          ORDER BY j.id LIMIT " . max(1, (int) $limit));
    $out = [];
    foreach ($rows as $r) {
        $ref = (string) ((json_decode((string) $r['payload'], true) ?: [])['ref'] ?? '');
        $p = $ref !== '' ? mam_db_row('SELECT id, reference, purpose, provider, amount_minor, currency, status, verified_at FROM payments WHERE reference = ?', [$ref]) : null;
        $out[] = ['job_id' => (int) $r['job_id'], 'job_status' => $r['job_status'], 'attempts' => (int) $r['attempts'],
                  'last_error' => $r['last_error'], 'reference' => $ref, 'payment' => $p, 'queued_at' => $r['created_at']];
    }
    return $out;
}

/**
 * Settled payments (before the job existed) whose effect is visibly missing:
 * tuition whose application is not paid/enrolled, a booking fee with no stamp,
 * a fee payment with no fee_payments row and no unapplied row, a store order
 * still pending. A list for a person to look at; "Finish" queues the job.
 */
function mam_payment_effects_missing_legacy(int $limit = 50): array {
    $lim = max(1, (int) $limit);
    $q = static fn(string $sql) => mam_db_all($sql . ' LIMIT ' . $lim);
    $out = [];
    foreach ($q("SELECT p.reference, p.purpose FROM payments p JOIN applications a ON a.id = p.application_id
                  WHERE p.status = 'succeeded' AND COALESCE(p.purpose,'tuition') = 'tuition'
                    AND a.status NOT IN ('paid','enrolled','cancelled','expired') AND a.identity_hold IS NULL") as $r) $out[] = $r + ['missing' => 'application not marked paid'];
    foreach ($q("SELECT p.reference, p.purpose FROM payments p JOIN applications a ON a.id = p.application_id
                  WHERE p.status = 'succeeded' AND p.purpose = 'booking_fee' AND a.booking_fee_paid_at IS NULL") as $r) $out[] = $r + ['missing' => 'booking not stamped'];
    foreach ($q("SELECT p.reference, p.purpose FROM payments p
                  WHERE p.status = 'succeeded' AND p.purpose = 'fee' AND p.fee_invoice_id IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM fee_payments fp WHERE fp.reference = p.reference)
                    AND NOT EXISTS (SELECT 1 FROM fee_online_unapplied u WHERE u.reference = p.reference)") as $r) $out[] = $r + ['missing' => 'fee invoice not credited'];
    foreach ($q("SELECT p.reference, p.purpose FROM payments p JOIN store_orders o ON o.reference = p.reference
                  WHERE p.status = 'succeeded' AND p.purpose = 'store' AND o.status = 'pending'") as $r) $out[] = $r + ['missing' => 'store order not fulfilled'];
    return $out;
}

/** Queue (or re-queue) the effects job for a settled payment. Returns the job id. */
function mam_payment_settle_requeue(string $ref): int {
    $p = mam_db_row("SELECT reference FROM payments WHERE reference = ? AND status = 'succeeded'", [$ref]);
    if (!$p) throw new RuntimeException('not_settled');
    $open = mam_db_row("SELECT id FROM jobs WHERE kind = 'settle_effects' AND status IN ('pending','failed') AND payload = ?",
        [json_encode(['ref' => $ref], JSON_UNESCAPED_SLASHES)]);
    if ($open) {
        mam_db_update('jobs', ['status' => 'pending', 'attempts' => 0, 'run_after' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $open['id']]);
        return (int) $open['id'];
    }
    return mam_job_enqueue('settle_effects', ['ref' => $ref]);
}

/**
 * The console's "Finish": queue the effects job for a settled payment and run
 * THAT job now (not the whole queue). Idempotent — every effect checks whether
 * it already happened. Returns ['ok' => bool, 'job_id' => int, 'error' => ?string].
 */
function mam_payment_settle_finish(string $ref): array {
    $jobId = mam_payment_settle_requeue($ref);
    $now = date('Y-m-d H:i:s');
    try {
        mam_payment_settle_effects_job(['ref' => $ref]);
        mam_db_update('jobs', ['status' => 'done', 'last_error' => null, 'updated_at' => $now], ['id' => $jobId]);
        return ['ok' => true, 'job_id' => $jobId, 'error' => null];
    } catch (\Throwable $e) {
        $job = mam_db_row('SELECT attempts FROM jobs WHERE id = ?', [$jobId]);
        mam_db_update('jobs', ['status' => 'pending', 'attempts' => (int) ($job['attempts'] ?? 0) + 1,
            'last_error' => mb_substr($e->getMessage(), 0, 255), 'updated_at' => $now], ['id' => $jobId]);
        mam_log('error', 'settle_finish_failed', ['ref' => $ref, 'err' => $e->getMessage()]);
        return ['ok' => false, 'job_id' => $jobId, 'error' => $e->getMessage()];
    }
}

/**
 * Did the gateway take at least what this payment row asked for, in naira?
 * `$paidKobo` is the gateway's own integer. Compared against `amount_minor`
 * (kobo, an integer) where the row has one, because `amount * 100` is float
 * arithmetic on a DECIMAL and can land a hair above an exact charge — 1234.56
 * becomes 123456.00000000001 — which would fail a payment that was exact. The
 * fee path charges to the kobo, so it is the first to be able to hit that.
 */
function mam_payment_amount_ok(array $payment, float $paidKobo, string $currency): bool {
    if (strtoupper($currency) !== 'NGN') return false;
    $expected = isset($payment['amount_minor']) && $payment['amount_minor'] !== null && (int) $payment['amount_minor'] > 0
        ? (int) $payment['amount_minor']
        : (int) round((float) $payment['amount'] * 100);
    return (int) round($paidKobo) >= $expected;
}

/**
 * Privacy-safe tuition lookup for the gated tuition page. Requires BOTH the
 * reference and the matching application email (so tuition isn't enumerable by
 * reference). Returns null when not found / mismatched / bad input. The payable
 * tuition is the application's server-computed total_usd. already_paid is true
 * once the application is paid/enrolled OR a tuition payment has succeeded.
 */
function mam_tuition_status(string $ref, string $email): ?array {
    $ref = trim($ref);
    $email = strtolower(trim($email));
    if ($ref === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    $row = mam_db_row(
        "SELECT a.id, a.reference, a.status, a.total_usd, a.pathway, c.first_name
           FROM applications a JOIN customers c ON c.id = a.customer_id
          WHERE a.reference = ? AND LOWER(c.email) = ?",
        [$ref, $email]
    );
    if (!$row) return null;
    $amountUsd = (float)$row['total_usd'];
    $paid = in_array((string)$row['status'], ['paid', 'enrolled'], true);
    if (!$paid) {
        $p = mam_db_row("SELECT 1 AS x FROM payments WHERE application_id = ? AND purpose = 'tuition' AND status = 'succeeded' LIMIT 1", [(int)$row['id']]);
        $paid = (bool)$p;
    }
    $rate = function_exists('mam_usd_to_ngn_rate') ? mam_usd_to_ngn_rate() : 0;
    return [
        'found'        => true,
        'name'         => (string)($row['first_name'] ?? ''),
        'reference'    => (string)$row['reference'],
        'pathway'      => (string)($row['pathway'] ?? ''),
        'amount_usd'   => $amountUsd,
        'amount_ngn'   => $rate > 0 ? (int)round($amountUsd * $rate) : 0,
        'already_paid' => $paid,
        'payable'      => (!$paid && $amountUsd > 0),
    ];
}

/** Store: mark the order paid/delivered, decrement stock once, email the receipt. */
function mam_payment_settle_store(string $ref): void {
    $order = mam_db_row('SELECT * FROM store_orders WHERE reference = ?', [$ref]);
    if (!$order) { mam_log('warn', 'settle_store_no_order', ['ref' => $ref]); return; }
    // Idempotent: fulfilled once. A re-run (the settle_effects job) must not
    // decrement stock or send a receipt twice. Status alone cannot say so — the
    // wallet path claims the order as 'paid' BEFORE calling this — so the
    // fulfilment is recorded as an event and checked here.
    if (in_array((string) $order['status'], ['delivered', 'oversold', 'shipped', 'cancelled', 'refunded'], true)) return;
    if (mam_db_row("SELECT 1 FROM events WHERE type = 'store.order_fulfilled' AND entity = 'store_order' AND entity_id = ?", [(int) $order['id']])) return;
    mam_event('store.order_fulfilled', 'store_order', (int) $order['id'], ['ref' => $ref]);
    $items = json_decode((string)$order['items_json'], true) ?: [];
    $hasPhysical = false;
    foreach ($items as $i) if (($i['goods_type'] ?? '') === 'physical') { $hasPhysical = true; break; }
    mam_db_update('store_orders', [
        'status'       => $hasPhysical ? 'paid' : 'delivered',
        'delivered_at' => $hasPhysical ? null : date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ], ['id' => (int)$order['id']]);
    // Stock is only checked at checkout, so two buyers can both take the last
    // unit and both pay. The guarded decrement below fulfils only what's really
    // in stock; if a physical line can't be satisfied (0 rows affected), the
    // order is oversold — flag it loudly for the admin to refund/restock rather
    // than silently marking it delivered.
    $oversold = [];
    foreach ($items as $i) {
        $sku = (string)($i['sku'] ?? '');
        $qty = max(1, (int)($i['qty'] ?? 1));
        if ($sku === '') continue;
        try {
            // stock = -1 means UNLIMITED: it must never be decremented and can
            // never be oversold. The guard `stock >= ?` (qty is >= 1) already
            // skips the UPDATE for an unlimited (-1) row, so 0 rows affected
            // there is expected — NOT an oversell. Previously `stock >= 0` in the
            // WHERE clause meant every unlimited physical line hit the 0-row
            // branch and got wrongly flagged, marking the whole paid order
            // 'oversold' and never fulfilling it.
            $st = mam_db()->prepare(
                "UPDATE store_items SET stock = stock - ?,
                        availability = CASE WHEN stock - ? <= 0 THEN 'sold_out' ELSE availability END,
                        updated_at = ?
                  WHERE sku = ? AND stock >= ?"
            );
            $st->execute([$qty, $qty, date('Y-m-d H:i:s'), $sku, $qty]);
            if ($st->rowCount() === 0 && ($i['goods_type'] ?? '') === 'physical') {
                // Only a FINITE line that came up short is a real oversell.
                $cur = mam_db_row('SELECT stock FROM store_items WHERE sku = ?', [$sku]);
                if ($cur && (int)$cur['stock'] >= 0) $oversold[] = $sku . '×' . $qty;
            }
        } catch (\Throwable $e) { mam_log('warn', 'store_stock_decrement_failed', ['sku' => $sku, 'err' => $e->getMessage()]); }
    }
    if ($oversold) {
        mam_db_update('store_orders', ['status' => 'oversold', 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int)$order['id']]);
        mam_log('error', 'store_order_oversold', ['ref' => $ref, 'lines' => $oversold, 'hint' => 'Paid order cannot be fulfilled from stock — refund or restock.']);
        mam_audit('store_oversold', 'store_order', (int)$order['id'], ['ref' => $ref, 'lines' => $oversold]);
    }
    $links = [];
    foreach ($items as $i) {
        if (($i['goods_type'] ?? '') !== 'digital') continue;
        $r = mam_db_row('SELECT name, file_url FROM store_items WHERE sku = ?', [(string)$i['sku']]);
        if ($r && !empty($r['file_url'])) $links[] = ['name' => $r['name'], 'url' => (string)$r['file_url']];
    }
    // Governed by the 'store_receipt' automation (admin ?v=automations).
    // Default on — pausing it settles the order silently (no receipt email).
    if (!function_exists('mam_automation_on') || mam_automation_on('store_receipt')) {
        if (function_exists('mam_automation_touch')) mam_automation_touch('store_receipt');
        try {
            mam_send((string)$order['customer_email'], 'MAM Academy Store — Order ' . $order['reference'] . ' confirmed',
                mam_email_store_receipt($order, $items, $links), 'store_order_confirmed', true);
        } catch (\Throwable $e) { mam_log('warn', 'store_email_failed', ['err' => $e->getMessage()]); }
    }
}

/** Booking fee: stamp the paid time + email the confirmation. Does NOT enrol. */
function mam_payment_settle_booking(int $appId): void {
    if ($appId <= 0) return;
    // Idempotent: stamped once, emailed once.
    $stamped = mam_db_row('SELECT booking_fee_paid_at FROM applications WHERE id = ?', [$appId]);
    if ($stamped && !empty($stamped['booking_fee_paid_at'])) return;
    mam_db_update('applications', ['booking_fee_paid_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $appId]);
    try {
        $app = mam_db_row('SELECT * FROM applications WHERE id = ?', [$appId]);
        $customer = $app ? mam_db_row('SELECT * FROM customers WHERE id = ?', [(int)$app['customer_id']]) : null;
        if ($customer && !empty($customer['email'])) {
            mam_send((string)$customer['email'], 'Your MAM Academy seat is reserved — pick your time',
                mam_email_booking_confirmed($app, $customer), 'booking_confirmed', true);
        }
    } catch (\Throwable $e) { mam_log('warn', 'booking_email_failed', ['err' => $e->getMessage()]); }
}

/** Tuition: mark paid, provision the student portal, email credentials. Returns a notice tag. */
function mam_payment_settle_tuition(int $appId): ?string {
    if ($appId <= 0) return null;
    mam_db_update('applications', ['status' => 'paid', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $appId]);
    $app = mam_db_row('SELECT * FROM applications WHERE id = ?', [$appId]);
    if (!$app || !$app['customer_id']) return null;
    // Held for identity review (schema v109): the email belongs to a different
    // person, so making an account from it would put this applicant into theirs.
    // The money is recorded (status 'paid' above); the office finishes the
    // placement from the backlog. A settled payment never fails over this.
    if (function_exists('mam_application_identity_held') && mam_application_identity_held($app) !== null) {
        mam_log('warn', 'settle_identity_held', ['app_id' => $appId]);
        return 'identity_review';
    }
    // Governed by the 'payment_provision' automation (admin ?v=automations).
    // Default on — turning it off marks the application paid but leaves portal
    // provisioning + the welcome email to a manual step.
    if (function_exists('mam_automation_on') && !mam_automation_on('payment_provision')) {
        return 'portal_provision_automation_off';
    }
    if (function_exists('mam_automation_touch')) mam_automation_touch('payment_provision');
    try {
        $provision  = mam_ensure_student_user((int)$app['customer_id']);
        $studentId  = (int)($provision['user']['id'] ?? 0);
        if ($studentId > 0 && function_exists('mam_application_link_guardian')) mam_application_link_guardian($app, $studentId);

        // Generate the stable enrollment ref (MAM/YY/NNN) — used for QR codes,
        // attendance lookup and the learner-report page. Idempotent: skipped if already set.
        if ($studentId > 0 && function_exists('mam_enrollment_ref_generate')) {
            try { mam_enrollment_ref_generate($studentId); } catch (\Throwable $e) { /* non-fatal */ }
        }

        // Complete the enrolment — the WHOLE chain, not part of it.
        //
        // This used to call mam_lms_add_course() + mam_lms_approve() directly,
        // which enrolled the student in a course and stopped. Three gaps, all of
        // which showed up on the live data as a paid student nobody was
        // teaching:
        //
        //   • No programme was passed, so the cohort resolved on
        //     (course, level, NULL) while the admin's own "Complete enrolment"
        //     screen resolves on (course, level, programme) — the same applicant
        //     landed in a different class depending on which path settled them.
        //   • The instructor was written to the ENROLMENT only.
        //     mam_lms_instructor_cohorts() keys off cohort.instructor_id OR
        //     enrolment.instructor_id, so the roster was visible, but the class
        //     itself stayed teacher-less and every later student on it inherited
        //     nothing.
        //   • The waitlisted branch returns id === null. `(int)$ceRow['id']`
        //     read that as 0 and quietly did nothing further — a paid applicant
        //     whose class was full got silence.
        //
        // mam_enrol_complete() is the one implementation of account → course →
        // cohort → instructor → application status, and it is the same code the
        // admin screen runs, so both paths cannot drift apart again.
        //
        // Gated by its own automation: provisioning gives someone a login,
        // enrolling puts them in a class, and an academy may want the first
        // without the second.
        if ($studentId > 0 && function_exists('mam_enrol_complete')
            && (!function_exists('mam_automation_on') || mam_automation_on('payment_enrol'))) {
            if (function_exists('mam_automation_touch')) mam_automation_touch('payment_enrol');
            try {
                $done = mam_enrol_complete($appId, [], null);   // null actor: a system action, never 0 (FK)
                if ($done['ok']) {
                    mam_audit('auto_enroll', 'application', $appId, [
                        'student_id' => $studentId,
                        'course'     => $done['plan']['course']['code'] ?? null,
                        'cohort_id'  => $done['plan']['cohort']['id'] ?? null,
                        'via'        => 'payment',
                    ]);
                } else {
                    // NOT a payment failure, and never treated as one. The money
                    // is taken, the account exists, and the application is
                    // 'paid' — what could not be resolved automatically is
                    // exactly what mam_enrol_incomplete() lists and the admin's
                    // "Needs your attention" queue already surfaces, with the
                    // one field in question asked rather than the whole record.
                    // The live reasons are real: `discipline` holds instruments
                    // that are not courses ("violin", "clarinet"), and an erased
                    // applicant must never be rebuilt by an automatic path.
                    mam_log('info', 'auto_enroll_needs_admin', [
                        'app'      => $appId,
                        'student_id' => $studentId,
                        'reason'   => $done['error'],
                        'blockers' => array_column($done['plan']['blockers'] ?? [], 'field'),
                    ]);
                }
            } catch (\Throwable $e) {
                // Same rule: a settled payment must never fail because the
                // placement could not be worked out.
                mam_log('warn', 'auto_enroll_failed', ['app' => $appId, 'err' => $e->getMessage()]);
            }
        }

        if (empty($provision['created'])) return 'portal_already_provisioned';

        // A LINK to choose a password, not the password itself. This used to email
        // a temporary one in a dashed box and tell the family to change it on first
        // sign-in — which meant a plaintext credential sitting in an inbox (usually
        // a parent's shared one) that could not be revoked once sent, and taught
        // exactly the habit phishing relies on. The provisioner no longer makes one.
        $inv = mam_auth_send_invite((int) $provision['user']['id'], 'student', 'portal_welcome');
        // A failed mint is reported, never silent: "the family never got the link"
        // is the support question this produces, and the answer has to be in the log.
        if (!$inv) {
            mam_log('warn', 'portal_invite_not_sent', ['app' => $appId, 'user' => (int) $provision['user']['id']]);
            return 'portal_invite_failed';
        }
        return 'portal_invite_emailed';
    } catch (\Throwable $e) {
        mam_log('warn', 'student_provision_failed', ['app' => $appId, 'err' => $e->getMessage()]);
        return null;
    }
}

/**
 * A refund, from whichever side it is learned of (the office pressing Refund,
 * or Paystack's refund.processed): the payment becomes 'refunded' — which every
 * money figure excludes — and what it paid for is reversed. Idempotent.
 *
 * A FEE payment used to be the gap: the admin refund reversed store orders,
 * booking fees and applications, but an online fee payment has no
 * application, so its fee_payments row stayed 'recorded' and the invoice still
 * read paid; the webhook only set gateway_state and left status 'succeeded', so
 * refunded money stayed in Collected.
 */
function mam_payment_refund_apply(array $p, string $reason, ?int $actorId = null, string $scope = 'admin'): array {
    $reversed = [];
    $purpose = (string) ($p['purpose'] ?? 'tuition');
    if ($scope === 'gateway') {
        // A refund the GATEWAY reports: the payment's own row keeps its settled
        // status (gateway_state = 'refunded' is what takes it out of Collected),
        // and what it paid for — a place, a booking, an order — stays a person's
        // decision. Only the money ledger follows the money: a fee credit is
        // voided so the invoice balance comes back.
        if ($purpose === 'fee') $reversed = mam_payment_refund_fee_credit($p, $reason, $actorId);
        mam_audit('payment_refund_applied', 'payment', (int) $p['id'], ['purpose' => $purpose, 'reason' => $reason, 'reversed' => $reversed, 'by' => $actorId, 'scope' => 'gateway']);
        return $reversed;
    }
    if ((string) $p['status'] !== 'refunded') {
        mam_db_update('payments', ['status' => 'refunded', 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $p['id']]);
    }
    if ($purpose === 'store') {
        $o = mam_db_row('SELECT id, status FROM store_orders WHERE reference = ?', [$p['reference']]);
        if ($o && $o['status'] !== 'refunded') { mam_db_update('store_orders', ['status' => 'refunded', 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $o['id']]); $reversed[] = 'store_order'; }
    } elseif ($purpose === 'fee') {
        $reversed = mam_payment_refund_fee_credit($p, $reason, $actorId);
    } elseif ($purpose === 'booking_fee' && !empty($p['application_id'])) {
        mam_db_update('applications', ['booking_fee_paid_at' => null], ['id' => (int) $p['application_id']]);
        $reversed[] = 'booking';
    } elseif (!empty($p['application_id'])) {
        mam_db_update('applications', ['status' => 'in_review'], ['id' => (int) $p['application_id']]);
        $reversed[] = 'application';
    }
    mam_audit('payment_refund_applied', 'payment', (int) $p['id'], ['purpose' => $purpose, 'reason' => $reason, 'reversed' => $reversed, 'by' => $actorId, 'scope' => 'admin']);
    return $reversed;
}

/** Void the fee credit a refunded online fee payment made, so the invoice balance comes back. Idempotent. */
function mam_payment_refund_fee_credit(array $p, string $reason, ?int $actorId = null): array {
    $reversed = [];
    foreach (mam_db_all("SELECT id FROM fee_payments WHERE reference = ? AND status = 'recorded'", [$p['reference']]) as $fp) {
        mam_fee_payment_void((int) $fp['id'], 'refunded: ' . ($reason !== '' ? $reason : 'payment refunded'), $actorId);
        $reversed[] = 'fee_payment#' . (int) $fp['id'];
    }
    foreach (mam_db_all("SELECT id FROM fee_online_unapplied WHERE reference = ? AND status = 'open'", [$p['reference']]) as $u) {
        mam_db_update('fee_online_unapplied', ['status' => 'refunded'], ['id' => (int) $u['id']]);
        $reversed[] = 'unapplied#' . (int) $u['id'];
    }
    return $reversed;
}
