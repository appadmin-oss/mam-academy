<?php
/**
 * Faculty (instructor) applications.
 *
 * When someone expresses interest in teaching (contact form topic, or an admin
 * invite), we email them a signed link to the instructor application form,
 * which collects their full details + headshot. Submissions land in
 * `instructor_applications` for admin review; approval leads to an instructor
 * account (created in admin, which emails credentials).
 *
 * The invite link carries an HMAC token (APP_KEY-signed, TTL-bound) — same
 * scheme as the attendance QR tokens — so the form is only reachable by the
 * person we invited, without needing a DB row per invite.
 */
declare(strict_types=1);

const MAM_FACULTY_INVITE_DAYS = 14;

function mam_faculty_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function mam_faculty_unb64(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/'), true); }

/** Mint a signed invite token for an email (valid MAM_FACULTY_INVITE_DAYS). */
function mam_faculty_invite_token(string $email, ?int $ttlDays = null): string {
    $email = strtolower(trim($email));
    $exp   = time() + (($ttlDays ?? MAM_FACULTY_INVITE_DAYS) * 86400);
    $raw   = $email . '|' . $exp;
    $sig   = hash_hmac('sha256', $raw, mam_app_key());
    return mam_faculty_b64($raw . '|' . $sig);
}

/** Verify an invite token → the invited email, or null (bad/expired/tampered). */
function mam_faculty_verify_token(?string $token): ?string {
    if (!$token) return null;
    $raw = mam_faculty_unb64($token);
    $parts = explode('|', $raw);
    if (count($parts) !== 3) return null;
    [$email, $exp, $sig] = $parts;
    $want = hash_hmac('sha256', $email . '|' . $exp, mam_app_key());
    if (!hash_equals($want, $sig)) return null;
    if ((int)$exp < time()) return null;
    return $email !== '' ? $email : null;
}

/** The application form URL for an invite. */
function mam_faculty_apply_url(string $token): string {
    return rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/instructor-apply?token=' . urlencode($token);
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
