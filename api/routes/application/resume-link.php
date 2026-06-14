<?php
/**
 * POST /api/application/resume-link  {draft_key, email}
 * Emails a magic resume link IF a draft exists for that key AND the email
 * matches the one stored on the draft. Always returns ok (never leaks whether a
 * draft exists). CSRF + tightly rate-limited.
 */
declare(strict_types=1);

$in    = mam_json_input();
$key   = trim((string)($in['draft_key'] ?? ''));
$email = strtolower(trim((string)($in['email'] ?? '')));
if ($key === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) mam_fail('validation', 422);

$d = mam_app_draft_load($key);
if ($d && strtolower((string)($d['email'] ?? '')) === $email) {
    mam_send($email, 'Resume your MAM Academy application', mam_email_resume_link(mam_app_draft_resume_url($key)), 'application_resume_link', true);
}

mam_ok(['ok' => true]);
