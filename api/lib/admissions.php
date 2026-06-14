<?php
/**
 * Admissions helpers (Application proposal — A1+). The applicant-facing view of
 * an application: a privacy-safe status lookup (reference + matching email) that
 * maps the raw application/payment state to a friendly stage + next step, so the
 * journey no longer dead-ends at "submitted".
 */
declare(strict_types=1);

/**
 * Map the raw application status + latest payment status to a friendly stage.
 * Returns ['stage','label','next'].
 */
function mam_application_stage(string $status, string $paymentStatus): array {
    $s    = strtolower(trim($status));
    $paid = strtolower(trim($paymentStatus)) === 'succeeded';

    if ($s === 'enrolled') {
        return ['stage' => 'enrolled', 'label' => 'Enrolled', 'next' => 'You\'re in — sign in to your student portal to begin.'];
    }
    if ($s === 'accepted') {
        return ['stage' => 'accepted', 'label' => 'Accepted', 'next' => 'Congratulations! Watch your email for enrolment and onboarding details.'];
    }
    if ($s === 'declined' || $s === 'rejected') {
        return ['stage' => 'closed', 'label' => 'Decision made', 'next' => 'Thank you for applying — please check your email for details.'];
    }
    if ($paid) {
        return ['stage' => 'confirmed', 'label' => 'Seat confirmed', 'next' => 'Your booking fee is in. We\'ll be in touch about your MAM Auditer assessment and the next steps.'];
    }
    return ['stage' => 'received', 'label' => 'Application received', 'next' => 'Confirm your seat with the one-time booking fee — use the link in your confirmation email, or reopen your application to pay.'];
}

/**
 * Privacy-safe applicant status lookup. Requires BOTH the reference and the
 * matching email (so applications aren't enumerable by reference alone).
 * Returns the friendly status array, or null when not found / mismatched.
 */
function mam_application_status(string $reference, string $email): ?array {
    $reference = trim($reference);
    $email     = strtolower(trim($email));
    if ($reference === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

    $row = mam_db_row(
        "SELECT a.reference, a.pathway, a.mode, a.status, a.created_at, a.total_usd,
                c.first_name,
                (SELECT pp.status FROM payments pp WHERE pp.application_id = a.id ORDER BY pp.id DESC LIMIT 1) AS payment_status
           FROM applications a
           JOIN customers c ON c.id = a.customer_id
          WHERE a.reference = ? AND LOWER(c.email) = ?",
        [$reference, $email]
    );
    if (!$row) return null;

    $stage = mam_application_stage((string)$row['status'], (string)($row['payment_status'] ?? ''));
    return [
        'reference'      => $row['reference'],
        'first_name'     => $row['first_name'],
        'pathway'        => $row['pathway'],
        'mode'           => $row['mode'],
        'status'         => $row['status'],
        'payment_status' => $row['payment_status'],
        'submitted_at'   => $row['created_at'],
        'stage'          => $stage['stage'],
        'stage_label'    => $stage['label'],
        'next_step'      => $stage['next'],
    ];
}

/* ── Server-side application drafts (A1: cross-device resume) ─────────────── */

/** Upsert a server-side draft keyed by the client's idempotency key. Best-effort
 *  (a draft save must never break the apply page); caps payload size. */
function mam_app_draft_save(string $draftKey, string $email, $payload): void {
    $draftKey = trim($draftKey);
    if ($draftKey === '' || strlen($draftKey) > 64) return;
    $json = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || strlen($json) > 200000) return; // sanity cap
    $email = strtolower(trim($email));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    $now = date('Y-m-d H:i:s');
    try {
        $existing = mam_db_row('SELECT id FROM application_drafts WHERE draft_key = ?', [$draftKey]);
        if ($existing) {
            mam_db_update('application_drafts', ['email' => $email ?: null, 'payload' => $json, 'updated_at' => $now], ['id' => (int)$existing['id']]);
        } else {
            mam_db_insert('application_drafts', ['draft_key' => $draftKey, 'email' => $email ?: null, 'payload' => $json, 'status' => 'draft']);
        }
    } catch (\Throwable $e) { /* best-effort */ }
}

/** Load a draft by key → ['draft_key','email','status','payload'(array|null)] or null. */
function mam_app_draft_load(string $draftKey): ?array {
    $row = mam_db_row('SELECT draft_key, email, payload, status FROM application_drafts WHERE draft_key = ?', [trim($draftKey)]);
    if (!$row) return null;
    $payload = $row['payload'] !== null ? json_decode((string)$row['payload'], true) : null;
    return ['draft_key' => $row['draft_key'], 'email' => $row['email'], 'status' => $row['status'], 'payload' => is_array($payload) ? $payload : null];
}

/** Signed, stable resume token for a draft key (HMAC over the app key). */
function mam_app_draft_token(string $draftKey): string {
    return substr(hash_hmac('sha256', 'draft:' . trim($draftKey), mam_app_key()), 0, 32);
}
/** Constant-time token check. */
function mam_app_draft_verify(string $draftKey, string $token): bool {
    return trim($draftKey) !== '' && hash_equals(mam_app_draft_token($draftKey), (string)$token);
}
/** Token-verified load — the resume path. Null on a bad/forged token. */
function mam_app_draft_load_verified(string $draftKey, string $token): ?array {
    return mam_app_draft_verify($draftKey, $token) ? mam_app_draft_load($draftKey) : null;
}
/** The magic-link resume URL emailed to the applicant. */
function mam_app_draft_resume_url(string $draftKey): string {
    $base = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');
    return $base . '/apply?resume=' . rawurlencode(trim($draftKey)) . '&t=' . mam_app_draft_token($draftKey);
}
/** Mark a draft submitted (called after a successful application submit). */
function mam_app_draft_mark_submitted(string $draftKey): void {
    if (trim($draftKey) === '') return;
    try { mam_db_update('application_drafts', ['status' => 'submitted', 'updated_at' => date('Y-m-d H:i:s')], ['draft_key' => trim($draftKey)]); } catch (\Throwable $e) {}
}
