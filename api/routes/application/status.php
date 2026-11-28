<?php
/**
 * GET /api/application/status?ref=...&email=...
 * Public applicant status lookup. BOTH the reference and the matching email are
 * required (so applications can't be enumerated by reference). Rate-limited.
 * Returns the friendly status, or 404 when not found / email mismatch.
 */
declare(strict_types=1);

$ref   = (string)($_GET['ref'] ?? '');
$email = (string)($_GET['email'] ?? '');

// A MAM Scholars Initiative reference (SCH-…) is looked up in its own table.
$st = stripos(trim($ref), 'SCH-') === 0
    ? mam_scholarship_status_view($ref, $email)
    : mam_application_status($ref, $email);
if (!$st) mam_fail('not_found', 404);

mam_ok(['application' => $st]);
