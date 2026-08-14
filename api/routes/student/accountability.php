<?php
/**
 * GET /api/student/accountability — the signed-in student's punctuality ledger
 * (occurrences, outstanding/waived/paid totals, and the individual charges).
 * Read-only (PDF §13, §37).
 */
declare(strict_types=1);

$u = mam_require_user();
mam_ok(['ledger' => mam_accountability_ledger('student', (int) $u['id'])]);
