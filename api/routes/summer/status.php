<?php
/**
 * GET /api/summer/status?ref=SUM-...&email=...
 * Public status lookup for summer programme applications.
 * Both reference and matching email are required (prevents enumeration).
 */
declare(strict_types=1);

$ref   = trim((string)($_GET['ref']   ?? ''));
$email = trim((string)($_GET['email'] ?? ''));

$st = mam_summer_application_status($ref, $email);
if (!$st) mam_fail('not_found', 404);

mam_ok(['application' => $st]);
