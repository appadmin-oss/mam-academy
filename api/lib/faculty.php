<?php
/**
 * Faculty (instructor) applications — approval-gated, single-use invites.
 *
 * Flow:
 *   1. Someone expresses interest in teaching (contact form) → a REQUEST row
 *      in `faculty_invites` (status 'requested'). Nothing is emailed yet.
 *   2. An admin APPROVES the request (or invites someone directly) → the row
 *      gains a single-use nonce + a 7-day expiry, and the application-form
 *      link is emailed (status 'invited').
 *   3. The candidate opens /instructor-apply?token=… — the token is
 *      HMAC-signed over (invite id + nonce) so it can't be guessed or
 *      tampered with, and the DB row is the single source of truth for
 *      "already used" / "expired".
 *   4. Submitting the form stores the application, CONSUMES the invite
 *      (status 'used' — the link is never reusable), and directs the
 *      candidate to book their faculty interview appointment.
 */
declare(strict_types=1);

const MAM_FACULTY_INVITE_DAYS = 7;

function mam_faculty_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function mam_faculty_unb64(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/'), true); }

/** Where a submitted candidate books their interview. */
function mam_faculty_book_url(): string {
    return rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/book';
}

/** The application-form URL for an invite row (must be approved/invited). */
function mam_faculty_apply_url(array $invite): string {
    $raw = (int)$invite['id'] . '|' . (string)$invite['nonce'];
    $sig = hash_hmac('sha256', $raw, mam_app_key());
    $token = mam_faculty_b64($raw . '|' . $sig);
    return rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/instructor-apply?token=' . urlencode($token);
}

/**
 * Record teaching interest (status 'requested'; nothing emailed). One open
 * request per email — repeat interest refreshes the existing row.
 * Returns the invite row id.
 */
function mam_faculty_request(string $email, ?string $name = null, string $source = 'contact'): int {
    $email = strtolower(trim($email));
    $open = mam_db_row("SELECT id FROM faculty_invites WHERE email = ? AND status IN ('requested','invited')", [$email]);
    if ($open) return (int)$open['id'];
    $id = mam_db_insert('faculty_invites', ['email' => $email, 'name' => $name ?: null, 'source' => $source, 'status' => 'requested']);
    mam_audit('faculty_request', 'faculty_invite', $id, ['email' => $email, 'source' => $source]);
    return $id;
}

/**
 * Approve a request (or mint a direct invite): assigns the single-use nonce +
 * 7-day expiry and returns the fresh row (email the link with
 * mam_faculty_apply_url). Re-approving an 'invited' row re-issues a NEW nonce
 * (the old link stops working) and resets the clock.
 */
function mam_faculty_approve(int $inviteId, ?int $by = null): ?array {
    $inv = mam_db_row('SELECT * FROM faculty_invites WHERE id = ?', [$inviteId]);
    if (!$inv || $inv['status'] === 'used') return null;
    mam_db_update('faculty_invites', [
        'status'     => 'invited',
        'nonce'      => bin2hex(random_bytes(16)),
        'expires_at' => date('Y-m-d H:i:s', time() + MAM_FACULTY_INVITE_DAYS * 86400),
        'invited_by' => $by,
        'invited_at' => date('Y-m-d H:i:s'),
    ], ['id' => $inviteId]);
    mam_audit('faculty_approve', 'faculty_invite', $inviteId, ['by' => $by]);
    return mam_db_row('SELECT * FROM faculty_invites WHERE id = ?', [$inviteId]);
}

/** Dismiss a request (no email, link never issued). */
function mam_faculty_dismiss(int $inviteId, ?int $by = null): void {
    mam_db_update('faculty_invites', ['status' => 'dismissed'], ['id' => $inviteId]);
    mam_audit('faculty_dismiss', 'faculty_invite', $inviteId, ['by' => $by]);
}

/**
 * Verify a form token → the live invite row, or a string reason:
 * 'invalid' (bad signature/shape), 'used' (single-use — already submitted),
 * 'expired'. The DB row decides state; the HMAC only proves authenticity.
 * @return array|string
 */
function mam_faculty_verify_token(?string $token) {
    if (!$token) return 'invalid';
    $parts = explode('|', mam_faculty_unb64($token));
    if (count($parts) !== 3) return 'invalid';
    [$id, $nonce, $sig] = $parts;
    if (!hash_equals(hash_hmac('sha256', $id . '|' . $nonce, mam_app_key()), $sig)) return 'invalid';
    $inv = mam_db_row('SELECT * FROM faculty_invites WHERE id = ?', [(int)$id]);
    if (!$inv || (string)$inv['nonce'] !== $nonce || $inv['status'] === 'dismissed' || $inv['status'] === 'requested') return 'invalid';
    if ($inv['status'] === 'used') return 'used';
    if (!$inv['expires_at'] || strtotime((string)$inv['expires_at']) < time()) return 'expired';
    return $inv;
}

/** Consume an invite after a successful submission — the link is done forever. */
function mam_faculty_consume(int $inviteId): void {
    mam_db_update('faculty_invites', ['status' => 'used', 'used_at' => date('Y-m-d H:i:s')], ['id' => $inviteId]);
    mam_audit('faculty_invite_used', 'faculty_invite', $inviteId, []);
}

/** Pending requests awaiting admin approval. */
function mam_faculty_pending_requests(int $limit = 100): array {
    $limit = max(1, min(200, $limit));
    return mam_db_all("SELECT * FROM faculty_invites WHERE status = 'requested' ORDER BY id DESC LIMIT " . $limit);
}

/** Compose + send the invite email for an approved invite row. */
function mam_faculty_send_invite(array $invite): bool {
    $link  = mam_faculty_apply_url($invite);
    $first = htmlspecialchars(trim(explode(' ', trim((string)($invite['name'] ?? '')))[0] ?: 'there'));
    return mam_send((string)$invite['email'], 'Your MAM Academy instructor application form',
        mam_email_layout(['header' => 'MAM ACADEMY', 'preheader' => 'Complete your instructor application — details + headshot.',
            'reason' => 'You received this because MAM Academy approved your request to apply as an instructor.',
            'body' => "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px'>You're invited to apply, $first</h2>"
                . "<p style='margin:0 0 10px'>Complete our instructor application — your teaching disciplines, experience, a short bio, links/CV and a professional headshot — so the faculty panel can review you properly.</p>"
                . "<p style='margin:0'>This link is personal, works <strong>once</strong>, and expires in " . MAM_FACULTY_INVITE_DAYS . " days.</p>"
                . mam_email_button($link, 'Complete your application')]),
        'faculty_invite', true);
}

/**
 * Validate + normalise an instructor application. Never throws.
 * @return array{errors: string[], data: array<string,mixed>}
 */
function mam_faculty_validate(array $in): array {
    $errors = [];
    $name = trim((string)($in['full_name'] ?? ''));
    $phone = trim((string)($in['phone'] ?? ''));
    $disciplines = trim((string)($in['disciplines'] ?? ''));
    $years = isset($in['experience_years']) ? max(0, min(60, (int)$in['experience_years'])) : null;
    $bio = trim((string)($in['bio'] ?? ''));
    $links = trim((string)($in['links'] ?? ''));
    $cv = trim((string)($in['cv_url'] ?? ''));
    $shot = trim((string)($in['headshot_url'] ?? ''));

    if ($name === '' || mb_strlen($name) > 160) $errors[] = 'full_name';
    if ($disciplines === '' || mb_strlen($disciplines) > 300) $errors[] = 'disciplines';
    if (mb_strlen($bio) < 40 || mb_strlen($bio) > 4000) $errors[] = 'bio';
    if ($phone !== '' && mb_strlen($phone) > 40) $errors[] = 'phone';
    if (mb_strlen($links) > 1000) $errors[] = 'links';
    // URL fields must be https and sane, when provided.
    foreach ([['cv_url', $cv], ['headshot_url', $shot]] as [$k, $v]) {
        if ($v !== '' && !preg_match('#^https://[^\s"\'<>]{4,500}$#i', $v)) $errors[] = $k;
    }

    return ['errors' => $errors, 'data' => [
        'full_name' => $name, 'phone' => $phone ?: null, 'disciplines' => $disciplines,
        'experience_years' => $years, 'bio' => $bio, 'links' => $links ?: null,
        'cv_url' => $cv ?: null, 'headshot_url' => $shot ?: null,
    ]];
}

/** Store a validated application (one live application per email — resubmits update it). */
function mam_faculty_application_create(string $email, array $data, ?string $ip = null): int {
    $email = strtolower(trim($email));
    $existing = mam_db_row("SELECT id FROM instructor_applications WHERE email = ? AND status IN ('submitted','reviewing')", [$email]);
    if ($existing) {
        mam_db_update('instructor_applications', $data + ['ip' => $ip], ['id' => (int)$existing['id']]);
        mam_audit('faculty_apply_update', 'instructor_application', (int)$existing['id'], ['email' => $email]);
        return (int)$existing['id'];
    }
    $id = mam_db_insert('instructor_applications', $data + ['email' => $email, 'status' => 'submitted', 'ip' => $ip]);
    mam_audit('faculty_apply', 'instructor_application', $id, ['email' => $email]);
    return $id;
}
