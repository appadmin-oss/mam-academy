<?php
/**
 * Data-subject rights (NDPA / GDPR) — per-person EXPORT + ERASURE.
 *
 * The privacy policy already promises export + delete; this is the machinery.
 * Keyed by email, which resolves to a customer record (applications/payments)
 * and/or a user account (enrolments/results/submissions/practice/notifications).
 *
 * EXPORT is a Data Subject Access Request bundle — everything we hold about the
 * person, minus secrets (password hashes, tokens) which are not their data.
 *
 * ERASURE anonymises identifying fields and deletes free-form PII, while
 * PRESERVING records there is a lawful basis to keep — financial/payment rows
 * (tax/accounting) and the audit log (security/legal). This keeps referential
 * integrity intact (no orphaned FKs) and is idempotent: running it twice is
 * safe. It is destructive and must be gated to a super-admin + confirmed.
 */
declare(strict_types=1);

/** Best-effort SELECT returning rows; never throws (a missing table → []). */
function mam_dr_rows(string $sql, array $args = []): array {
    try { return mam_db_all($sql, $args); } catch (\Throwable $e) { return []; }
}

/**
 * Assemble everything we hold about a person (by email). Returns null if the
 * email matches neither a customer nor a user. Secrets are never included.
 */
function mam_datarights_export(string $email): ?array {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

    $customer = mam_db_row("SELECT id, email, first_name, last_name, phone, address, lga, state, country, age_range, dob, created_at FROM customers WHERE LOWER(email) = ?", [$email]);
    $user     = mam_db_row("SELECT id, email, name, phone, role, status, last_login, created_at FROM users WHERE LOWER(email) = ?", [$email]);
    if (!$customer && !$user) return null;

    $bundle = [
        'generated_at' => date('c'),
        'subject'      => $email,
        'customer'     => $customer ?: null,
        'user'         => $user ?: null,
    ];

    if ($customer) {
        $cid = (int)$customer['id'];
        $bundle['applications'] = mam_dr_rows("SELECT id, reference, pathway, mode, discipline, experience, goals, status, total_usd, created_at FROM applications WHERE customer_id = ? ORDER BY id", [$cid]);
        $bundle['payments']     = mam_dr_rows("SELECT p.id, p.reference, p.provider, p.status, p.amount, p.currency, p.purpose, p.created_at FROM payments p JOIN applications a ON a.id = p.application_id WHERE a.customer_id = ? ORDER BY p.id", [$cid]);
    }
    if ($user) {
        $uid = (int)$user['id'];
        $bundle['enrolments']    = mam_dr_rows("SELECT ce.id, c.name AS course, ce.status, ce.created_at, r.total_score, r.grade, r.outcome FROM course_enrollments ce LEFT JOIN courses c ON c.id = ce.course_id LEFT JOIN results r ON r.enrollment_id = ce.id WHERE ce.student_id = ? ORDER BY ce.id", [$uid]);
        $bundle['submissions']   = mam_dr_rows("SELECT s.id, a.title AS assignment, s.artifact_kind, s.note, s.status, s.created_at FROM submissions s LEFT JOIN assignments a ON a.id = s.assignment_id WHERE s.student_id = ? ORDER BY s.id", [$uid]);
        $bundle['practice_logs'] = mam_dr_rows("SELECT id, minutes, focus, notes, logged_on, created_at FROM practice_logs WHERE student_id = ? ORDER BY id", [$uid]);
        $bundle['attendance']    = mam_dr_rows("SELECT id, session_id, status, notes, created_at FROM session_attendance WHERE student_id = ? ORDER BY id", [$uid]);
        $bundle['notifications'] = mam_dr_rows("SELECT id, type, title, body, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY id", [$uid]);
    }
    $bundle['messages']   = mam_dr_rows("SELECT id, name, email, phone, topic, message, status, created_at FROM messages WHERE LOWER(email) = ? ORDER BY id", [$email]);
    $bundle['newsletter'] = mam_dr_rows("SELECT id, email, source, status, created_at FROM newsletter WHERE LOWER(email) = ? ORDER BY id", [$email]);

    return $bundle;
}

/**
 * Erase a person's personal data (by email). Anonymises identifying fields and
 * deletes free-form PII; PRESERVES financial + audit records (lawful basis).
 * Idempotent. Returns a summary of what changed. $by = acting admin id (audited).
 */
function mam_datarights_erase(string $email, ?int $by = null): array {
    $email = strtolower(trim($email));
    $summary = ['ok' => false, 'customer' => 0, 'user' => 0, 'messages' => 0, 'newsletter' => 0, 'practice_notes' => 0, 'submission_notes' => 0, 'attendance_notes' => 0];
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $summary['error'] = 'invalid_email'; return $summary; }

    $customer = mam_db_row("SELECT id FROM customers WHERE LOWER(email) = ?", [$email]);
    $user     = mam_db_row("SELECT id FROM users WHERE LOWER(email) = ?", [$email]);
    if (!$customer && !$user) { $summary['error'] = 'not_found'; return $summary; }

    try {
        if ($customer) {
            $cid = (int)$customer['id'];
            mam_db_update('customers', [
                'email'      => 'erased-c' . $cid . '@deleted.invalid',
                'first_name' => 'Erased', 'last_name' => 'User',
                'phone'      => null, 'address' => null, 'lga' => null, 'state' => null, 'dob' => null,
            ], ['id' => $cid]);
            $summary['customer'] = 1;
        }
        if ($user) {
            $uid = (int)$user['id'];
            mam_db_update('users', [
                'email'         => 'erased-u' . $uid . '@deleted.invalid',
                'name'          => 'Erased User',
                'phone'         => null,
                'status'        => 'suspended',
                'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                'updated_at'    => date('Y-m-d H:i:s'),
            ], ['id' => $uid]);
            // Sessions are secrets, not the subject's data — revoke them.
            try { mam_db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$uid]); } catch (\Throwable $e) {}
            // Null free-form notes the subject authored / that describe them.
            try { mam_db()->prepare("UPDATE practice_logs SET notes = NULL WHERE student_id = ? AND notes IS NOT NULL")->execute([$uid]); $summary['practice_notes'] = 1; } catch (\Throwable $e) {}
            try { mam_db()->prepare("UPDATE submissions SET note = NULL WHERE student_id = ? AND note IS NOT NULL")->execute([$uid]); $summary['submission_notes'] = 1; } catch (\Throwable $e) {}
            try { mam_db()->prepare("UPDATE session_attendance SET notes = NULL WHERE student_id = ? AND notes IS NOT NULL")->execute([$uid]); $summary['attendance_notes'] = 1; } catch (\Throwable $e) {}
            $summary['user'] = 1;
        }
        // Free-form PII with no retention basis → delete outright.
        try { $st = mam_db()->prepare("DELETE FROM messages WHERE LOWER(email) = ?"); $st->execute([$email]); $summary['messages'] = $st->rowCount(); } catch (\Throwable $e) {}
        try { $st = mam_db()->prepare("DELETE FROM newsletter WHERE LOWER(email) = ?"); $st->execute([$email]); $summary['newsletter'] = $st->rowCount(); } catch (\Throwable $e) {}

        if (function_exists('mam_audit')) mam_audit('datarights_erase', 'customer', $customer ? (int)$customer['id'] : 0, ['by' => $by, 'had_user' => (bool)$user]);
        $summary['ok'] = true;
    } catch (\Throwable $e) {
        if (function_exists('mam_log')) mam_log('error', 'datarights_erase_failed', ['err' => $e->getMessage()]);
        $summary['error'] = 'exception';
    }
    return $summary;
}
