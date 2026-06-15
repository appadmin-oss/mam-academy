<?php
/**
 * GET /api/tuition/status?ref=...&email=...
 * Privacy-safe tuition lookup for the gated tuition page. BOTH the reference and
 * the matching application email are required, so tuition isn't enumerable by
 * reference alone. Returns {found:false} for not-found / mismatch / bad input
 * (no enumeration), else the payable amount + already_paid state. Rate-limited.
 */
declare(strict_types=1);

$st = mam_tuition_status((string)($_GET['ref'] ?? ''), (string)($_GET['email'] ?? ''));
if ($st === null) mam_ok(['found' => false]);
mam_ok($st);
