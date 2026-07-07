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

/**
 * Record a completed MAM Auditer placement and (when the 'auditer_placement'
 * automation is on) act on it. This is the capture flow that `auditer_results`
 * was reserved for — previously the table had no writer.
 *
 * Writes the placement row, then — gated by the automation — ensures the
 * student account exists and enrols them into the recommended course at the
 * recommended level (which attaches that course's curriculum). Defaults on, so
 * a placement flows straight into a live enrolment; pause the automation to
 * capture the result without auto-enrolling.
 *
 * $opts: course_code, level_code, program_code, notes, dimensions[], audited_by.
 * Returns ['result_id','student_id','enrolled','enrollment_id','note'].
 * Throws only when the student account can't be resolved.
 */
function mam_auditer_record(int $customerId, string $recommendedPathway, array $opts = []): array {
    $out = ['result_id' => 0, 'student_id' => 0, 'enrolled' => false, 'enrollment_id' => null, 'note' => ''];
    $courseCode  = trim((string)($opts['course_code'] ?? ''));
    $levelCode   = trim((string)($opts['level_code'] ?? ''));
    $programCode = trim((string)($opts['program_code'] ?? '')) ?: null;
    $notes       = isset($opts['notes']) ? (mb_substr(trim((string)$opts['notes']), 0, 2000) ?: null) : null;
    $dimensions  = isset($opts['dimensions']) && is_array($opts['dimensions']) ? $opts['dimensions'] : null;
    $auditedBy   = isset($opts['audited_by']) && (int)$opts['audited_by'] > 0 ? (int)$opts['audited_by'] : null;

    // A placement implies the applicant is joining — resolve (provisioning if
    // needed) their student account. No email is sent here (that's the caller's
    // job on the payment path); this only needs the user row.
    if (!function_exists('mam_ensure_student_user')) throw new RuntimeException('student_unresolved');
    $prov = mam_ensure_student_user($customerId);
    $studentId = (int)($prov['user']['id'] ?? 0);
    if ($studentId <= 0) throw new RuntimeException('student_unresolved');
    $out['student_id'] = $studentId;

    // The capture: one authoritative placement row.
    $out['result_id'] = mam_db_insert('auditer_results', [
        'student_id'          => $studentId,
        'dimensions'          => $dimensions !== null ? json_encode($dimensions, JSON_UNESCAPED_SLASHES) : null,
        'recommended_pathway' => mb_substr($recommendedPathway, 0, 32),
        'notes'               => $notes,
        'audited_by'          => $auditedBy,
    ]);
    if (function_exists('mam_audit')) mam_audit('auditer_record', 'user', $studentId, ['pathway' => $recommendedPathway, 'level' => $levelCode ?: null]);

    // The action — assign the level, enrol, attach the curriculum.
    $automationOn = !function_exists('mam_automation_on') || mam_automation_on('auditer_placement');
    if (!$automationOn) { $out['note'] = 'automation_off'; return $out; }
    if ($courseCode === '' || !function_exists('mam_lms_add_course')) { $out['note'] = 'no_course'; return $out; }
    try {
        $enr = mam_lms_add_course($studentId, $courseCode, $levelCode ?: null, $programCode, null);
        $out['enrolled'] = true;
        $out['enrollment_id'] = (int)($enr['id'] ?? 0);
        $out['note'] = 'enrolled';
        if (function_exists('mam_automation_touch')) mam_automation_touch('auditer_placement');
    } catch (\RuntimeException $e) {
        // e.g. already_enrolled — the placement is still recorded.
        $out['note'] = $e->getMessage();
    } catch (\Throwable $e) {
        $out['note'] = 'enrol_failed';
        if (function_exists('mam_log')) mam_log('warn', 'auditer_enrol_failed', ['student' => $studentId, 'err' => $e->getMessage()]);
    }
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
