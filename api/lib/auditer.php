<?php
/**
 * MAM Auditer placement validity.
 *
 * The Auditer (the paid placement assessment, marked by an application's
 * booking_fee_paid_at) is valid for ONE YEAR and can only be done ONCE until it
 * expires — so a customer can't pay for a redundant assessment while a valid
 * placement stands. ENROLLED STUDENTS are exempt: their placement never expires.
 *
 * Validity is per CUSTOMER (the most recent Auditer across all their
 * applications), not per application — applying again doesn't reset the clock.
 * Derived entirely from existing data (booking_fee_paid_at + enrolments), so no
 * schema change.
 */
declare(strict_types=1);

const MAM_AUDITER_VALID_DAYS = 365;

/**
 * A customer's Auditer status:
 *   ['state','done_at','expires_at','valid','is_student','can_book']
 * state: 'student' (exempt) | 'valid' (done, in date) | 'expired' | 'none'.
 * can_book: may they (re)take the Auditer now? false while a placement stands.
 */
function mam_auditer_status(int $customerId): array {
    $out = ['state' => 'none', 'done_at' => null, 'expires_at' => null, 'valid' => false, 'is_student' => false, 'can_book' => true];
    if ($customerId <= 0) return $out;

    // Enrolled-student exemption: the customer's email maps to a user with a
    // real (active/completed) course enrolment.
    try {
        $cust = mam_db_row('SELECT email FROM customers WHERE id = ?', [$customerId]);
        if ($cust && !empty($cust['email'])) {
            $r = mam_db_row(
                "SELECT 1 AS x FROM users u JOIN course_enrollments ce ON ce.student_id = u.id
                  WHERE LOWER(u.email) = LOWER(?) AND ce.status IN ('active','completed') LIMIT 1",
                [(string)$cust['email']]
            );
            $out['is_student'] = (bool)$r;
        }
    } catch (\Throwable $e) { /* best-effort */ }

    // When they last did the Auditer (paid the booking fee on any application).
    try {
        $row = mam_db_row("SELECT MAX(booking_fee_paid_at) AS d FROM applications WHERE customer_id = ? AND booking_fee_paid_at IS NOT NULL", [$customerId]);
        $out['done_at'] = ($row && $row['d']) ? (string)$row['d'] : null;
    } catch (\Throwable $e) { /* best-effort */ }

    if ($out['is_student']) {
        // Exempt — placement never lapses; no need to re-book.
        $out['state'] = 'student';
        $out['valid'] = true;
        $out['can_book'] = false;
        return $out;
    }
    if ($out['done_at'] === null) {
        $out['state'] = 'none';   // never taken — free to book
        return $out;
    }
    $expires = date('Y-m-d H:i:s', strtotime((string)$out['done_at']) + MAM_AUDITER_VALID_DAYS * 86400);
    $out['expires_at'] = $expires;
    $out['valid']      = strtotime($expires) > time();
    $out['state']      = $out['valid'] ? 'valid' : 'expired';
    $out['can_book']   = !$out['valid']; // once-until-expiration: re-book only after it lapses
    return $out;
}

/** A short, friendly sentence describing the Auditer status (for UI/email). */
function mam_auditer_message(array $st): string {
    switch ($st['state']) {
        case 'student':
            return 'As an enrolled student your MAM Auditer placement stays on file — no reassessment needed.';
        case 'valid':
            $d = $st['expires_at'] ? date('j M Y', strtotime((string)$st['expires_at'])) : '';
            return 'You already completed your MAM Auditer — it\'s valid' . ($d ? ' until ' . $d : ' for a year') . ', so there\'s no need to pay or sit it again.';
        case 'expired':
            return 'Your previous MAM Auditer has expired (it\'s valid for one year). You can book a fresh assessment.';
        default:
            return 'Reserve your one-time MAM Auditer placement assessment.';
    }
}
