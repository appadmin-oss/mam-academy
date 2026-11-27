<?php
/**
 * Application invoice lifecycle — auto-invoice when due, expire when overdue.
 *
 * An unpaid application carries a one-time processing-fee invoice. If it isn't
 * settled within `invoice_due_days` an auto-invoice reminder is emailed (once);
 * if it's still unpaid by `invoice_expiry_days` the application EXPIRES
 * (status → expired) and the applicant must start over — the full processing
 * fee applies afresh (an expired application can no longer be paid; see the
 * guard in payment-init.php).
 *
 * No schema change: timing derives from applications.created_at + settings, the
 * one-time reminder is deduped through application_events, and 'expired' rides
 * the existing admissions status machine. The cron tool (run-jobs.php) calls
 * mam_invoices_process() each pass.
 */
declare(strict_types=1);

/** Invoice policy (admin-tunable settings). */
function mam_invoice_policy(): array {
    return [
        'auto'        => (int)mam_setting_num('invoice_auto', 1) === 1,
        'due_days'    => max(1, (int)mam_setting_num('invoice_due_days', 3)),
        'expiry_days' => max(2, (int)mam_setting_num('invoice_expiry_days', 14)),
    ];
}

/** Has this application settled its processing fee (so it owes nothing)? */
function mam_invoice_paid(array $app): bool {
    if (in_array((string)($app['status'] ?? ''), ['paid', 'enrolled', 'accepted'], true)) return true;
    if (!empty($app['booking_fee_paid_at'])) return true;
    $appId = (int)($app['id'] ?? 0);
    if ($appId > 0 && mam_db_row("SELECT 1 AS x FROM payments WHERE application_id = ? AND status = 'succeeded' LIMIT 1", [$appId])) return true;
    return false;
}

/** Does the applicant map to a scholarship (sponsored) student? Such students
 *  are billing-exempt, so their applications never auto-invoice or expire. The
 *  link is application → customer email → user.student_type. */
function mam_invoice_is_scholarship(array $app): bool {
    $email = (string)($app['cust_email'] ?? $app['email'] ?? '');
    if ($email === '' && !empty($app['customer_id'])) {
        $c = mam_db_row('SELECT email FROM customers WHERE id = ?', [(int)$app['customer_id']]);
        $email = (string)($c['email'] ?? '');
    }
    if ($email === '') return false;
    $u = mam_db_row('SELECT student_type FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1', [$email]);
    return $u ? mam_user_is_scholarship($u) : false;
}

/** Does this application still owe its fee AND remain in the auto-expire path?
 *  Only a freshly-submitted, untouched, unpaid application qualifies — once
 *  admissions moves it along (in_review/accepted/…) it leaves the auto path.
 *  Scholarship students are exempt and never owe. */
function mam_invoice_owes(array $app): bool {
    if (mam_invoice_is_scholarship($app)) return false;
    return (string)($app['status'] ?? '') === 'submitted' && !mam_invoice_paid($app);
}

/** created_at + N days, as a unix ts (falls back to now on a bad/empty date). */
function mam_invoice_ts(?string $createdAt, int $days): int {
    $base = $createdAt ? (int)strtotime($createdAt) : 0;
    if ($base <= 0) $base = time();
    return $base + $days * 86400;
}

/** Whether the one-time due reminder has already been logged for this app. */
function mam_invoice_reminded(int $appId): bool {
    return mam_db_row("SELECT 1 AS x FROM application_events WHERE application_id = ? AND reason = 'invoice_reminded' LIMIT 1", [$appId]) !== null;
}

/**
 * The invoice state of one application:
 *   state ∈ paid | expired | active | due ; plus owes, due_at, expires_at,
 *   days_left, reminded.
 */
function mam_invoice_status(array $app, ?int $now = null): array {
    $now  = $now ?? time();
    $none = ['owes' => false, 'due_at' => null, 'expires_at' => null, 'days_left' => 0, 'reminded' => false];
    if ((string)($app['status'] ?? '') === 'expired') return ['state' => 'expired'] + $none + ['reminded' => true];
    if (mam_invoice_is_scholarship($app)) return ['state' => 'scholarship'] + $none;
    if (mam_invoice_paid($app)) return ['state' => 'paid'] + $none;
    if (!mam_invoice_owes($app)) return ['state' => 'active'] + $none;
    $pol     = mam_invoice_policy();
    $created = (string)($app['created_at'] ?? '');
    $dueAt   = mam_invoice_ts($created, $pol['due_days']);
    $expAt   = mam_invoice_ts($created, $pol['expiry_days']);
    return [
        'state'      => $now >= $dueAt ? 'due' : 'active',
        'owes'       => true,
        'due_at'     => date('Y-m-d H:i:s', $dueAt),
        'expires_at' => date('Y-m-d H:i:s', $expAt),
        'days_left'  => max(0, (int)ceil(($expAt - $now) / 86400)),
        'reminded'   => mam_invoice_reminded((int)($app['id'] ?? 0)),
    ];
}

/**
 * Cron sweep: auto-invoice due applications (once) and expire overdue ones.
 * Returns ['scanned','invoiced','expired']. Never throws — email + transitions
 * are best-effort so one bad row can't stall the queue.
 */
function mam_invoices_process(?int $now = null): array {
    $out = ['scanned' => 0, 'invoiced' => 0, 'expired' => 0];
    $pol = mam_invoice_policy();
    if (!$pol['auto']) return $out;
    $now = $now ?? time();

    $rows = mam_db_all(
        "SELECT a.*, c.email AS cust_email, c.first_name, c.last_name
           FROM applications a LEFT JOIN customers c ON c.id = a.customer_id
          WHERE a.status = 'submitted' ORDER BY a.id"
    );
    foreach ($rows as $app) {
        if (!mam_invoice_owes($app)) continue;
        $out['scanned']++;
        $appId   = (int)$app['id'];
        $created = (string)($app['created_at'] ?? '');

        if ($now >= mam_invoice_ts($created, $pol['expiry_days'])) {
            $changed = false;
            try {
                $res = mam_admissions_transition($appId, 'expired', null, 'invoice_expired');
                $changed = !empty($res['changed']);
            } catch (\Throwable $e) {
                mam_log('warn', 'invoice_expire_failed', ['app' => $appId, 'err' => $e->getMessage()]);
            }
            // Only email (and count) on a real transition — otherwise a row that
            // failed to flip stays 'submitted' and would re-send every pass.
            if ($changed) { $out['expired']++; mam_invoice_email_expired($app); }
            continue;
        }
        if ($now >= mam_invoice_ts($created, $pol['due_days']) && !mam_invoice_reminded($appId)) {
            mam_invoice_email_due($app);
            try {
                mam_db_insert('application_events', ['application_id' => $appId, 'from_status' => 'submitted', 'to_status' => 'submitted', 'reason' => 'invoice_reminded']);
            } catch (\Throwable $e) {}
            $out['invoiced']++;
        }
    }
    if ($out['invoiced'] || $out['expired']) mam_audit('invoices_processed', 'application', null, $out);
    return $out;
}

/** Best-effort "your invoice is due" auto-invoice email. */
function mam_invoice_email_due(array $app): void {
    try {
        $to = (string)($app['cust_email'] ?? $app['email'] ?? '');
        if ($to === '' || !function_exists('mam_send') || !function_exists('mam_email_layout')) return;
        $ref  = (string)($app['reference'] ?? '');
        $pol  = mam_invoice_policy();
        $name = trim((string)($app['first_name'] ?? ''));
        $left = max(1, $pol['expiry_days'] - $pol['due_days']);
        $body = mam_email_layout(['preheader' => 'Your application invoice is awaiting payment.', 'body' =>
            '<p style="margin:0 0 14px">Hi ' . htmlspecialchars($name !== '' ? $name : 'there') . ',</p>'
          . '<p style="margin:0 0 14px">Your application <strong>' . htmlspecialchars($ref) . '</strong> is awaiting its one-time processing fee. '
          . 'Please complete payment to keep your place — the application will <strong>expire in about ' . $left . ' day(s)</strong> if it stays unpaid, '
          . 'after which a fresh application (with the full processing fee) would be required.</p>'
          . '<p style="margin:0"><a href="https://academy.mamcentre.org/status?ref=' . urlencode($ref) . '" style="color:#B8721B;font-weight:700">Pay &amp; track your application →</a></p>']);
        mam_send($to, 'Action needed: your MAM Academy application invoice', $body, 'invoice_due', true);
    } catch (\Throwable $e) { /* never break the sweep */ }
}

/** Best-effort "application expired — please restart" email. */
function mam_invoice_email_expired(array $app): void {
    try {
        $to = (string)($app['cust_email'] ?? $app['email'] ?? '');
        if ($to === '' || !function_exists('mam_send') || !function_exists('mam_email_layout')) return;
        $ref  = (string)($app['reference'] ?? '');
        $name = trim((string)($app['first_name'] ?? ''));
        $body = mam_email_layout(['preheader' => 'Your application has expired.', 'body' =>
            '<p style="margin:0 0 14px">Hi ' . htmlspecialchars($name !== '' ? $name : 'there') . ',</p>'
          . '<p style="margin:0 0 14px">Application <strong>' . htmlspecialchars($ref) . '</strong> has expired because the processing fee wasn\'t received in time. '
          . 'You\'re very welcome to apply again — please note a new application includes the full processing fee.</p>'
          . '<p style="margin:0"><a href="https://academy.mamcentre.org/apply" style="color:#B8721B;font-weight:700">Start a new application →</a></p>']);
        mam_send($to, 'Your MAM Academy application has expired', $body, 'invoice_expired', true);
    } catch (\Throwable $e) { /* never break the sweep */ }
}
