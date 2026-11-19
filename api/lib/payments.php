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

    // Atomic, race-safe claim: only one caller flips pending→succeeded.
    try {
        $st = mam_db()->prepare(
            "UPDATE payments SET status='succeeded', provider_reference=?, provider_response=?, verified_at=?, updated_at=? WHERE reference=? AND status<>'succeeded'"
        );
        $now = date('Y-m-d H:i:s');
        $st->execute([$providerRef, $rawResponse, $now, $now, $ref]);
        if ($st->rowCount() === 0) { $out['already'] = true; return $out; } // someone else settled it
    } catch (\Throwable $e) {
        mam_log('error', 'settle_claim_failed', ['ref' => $ref, 'err' => $e->getMessage()]);
        $out['ok'] = false; return $out;
    }

    mam_audit('payment_settled', 'payment', (int)($payment['id'] ?? 0), ['ref' => $ref, 'purpose' => $purpose]);

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
