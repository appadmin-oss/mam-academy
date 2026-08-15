<?php
/**
 * GET /api/verify-certificate?ref=…  (public, rate-limited)
 *
 * Resolves a certificate reference to the public-safe facts a QR-code verifier
 * needs (Advanced Modernization §6.4): validity, who it was issued to, the
 * credential and the issue date. Never returns anything beyond the credential.
 */
declare(strict_types=1);

$ref = strtoupper(trim((string)($_GET['ref'] ?? '')));
if ($ref === '') mam_fail('missing_ref', 422, ['fields' => ['ref']]);

$c = function_exists('mam_lms_certificate') ? mam_lms_certificate($ref) : null;
if (!$c) { mam_ok(['valid' => false]); }

mam_ok([
    'valid'         => true,
    'reference'     => $c['reference'],
    'student_name'  => $c['student_name'],
    'title'         => $c['title'],
    'program_name'  => $c['program_name'] ?? null,
    'level_name'    => $c['level_name'] ?? null,
    'certification' => $c['trinity_grades'] ?? null,
    'issued_at'     => $c['issued_at'] ?? null,
]);
