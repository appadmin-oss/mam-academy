<?php
/**
 * GET /api/learner-lookup?ref=MAM/26/001&email=parent@example.com
 *
 * Public endpoint — no login required. Parents enter their child's enrollment
 * reference PLUS the email on the student's account to retrieve the progress
 * report. Two factors are required because the enrollment ref is sequential and
 * therefore guessable (MAM/26/001, /002, …) — the ref alone would let anyone
 * enumerate every learner's academic record. This mirrors the reference+email
 * gate already used by application/tuition/scholarship status lookups.
 *
 * Rate-limited tightly (5/min/IP). A wrong ref and a wrong email both return the
 * same `not_found` so the endpoint can't be used as an oracle to confirm which
 * refs exist. The student's email is stripped from the returned payload.
 */
declare(strict_types=1);

$ref   = trim((string)($_GET['ref'] ?? ''));
$email = strtolower(trim((string)($_GET['email'] ?? '')));
if ($ref === '')   mam_fail('missing_field', 400, ['field' => 'ref']);
if ($email === '') mam_fail('missing_field', 400, ['field' => 'email']);

// Validate format (MAM/YY/NNN or JE/MAM/YY/NNN) — reject obviously wrong input
// before hitting the DB. This blocks random noise without leaking ref existence.
if (!preg_match('#^(?:JE/)?MAM/\d{2}/\d{3,}$#', $ref)) {
    mam_fail('invalid_ref', 400, ['hint' => 'Expected format: MAM/YY/NNN or JE/MAM/YY/NNN']);
}

$studentId = mam_enrollment_ref_lookup($ref);
if (!$studentId) mam_fail('not_found', 404);

// Second factor: the supplied email must match the student's account email.
// Same not_found on mismatch — do not distinguish "bad ref" from "bad email".
$acct = mam_db_row('SELECT email FROM users WHERE id = ?', [$studentId]);
if (!$acct || strcasecmp($email, trim((string)($acct['email'] ?? ''))) !== 0) {
    mam_fail('not_found', 404);
}

$report = mam_progress_report_data($studentId);
unset($report['student']['email']);

mam_ok($report);
