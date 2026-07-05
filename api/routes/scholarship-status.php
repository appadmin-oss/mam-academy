<?php
/**
 * GET /api/scholarship/status?reference=SCH-...&email=...
 *
 * Public, gated by reference + matching email (mirrors application/status). Read-
 * only; surfaces only the applicant's own scholarship status, no PII of others.
 */
declare(strict_types=1);

$reference = mam_str($_GET, 'reference', 64);
$email     = mam_str($_GET, 'email', 190);

$st = mam_scholarship_status($reference, $email);
if (!$st) mam_fail('not_found', 404, ['detail' => 'No scholarship application matches that reference and email.']);

mam_ok($st);
