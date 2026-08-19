<?php
/** GET /api/parent/fees?student_id=N — the Fees screen for one child (§3.4). */
declare(strict_types=1);
$u   = mam_require_role('parent');
$sid = (int) ($_GET['student_id'] ?? 0);
if ($sid < 1) mam_fail('validation', 422, ['fields' => ['student_id']]);
try { mam_ok(mam_fee_statement((int) $u['id'], $sid)); }
catch (\RuntimeException $e) { mam_fail($e->getMessage(), $e->getMessage() === 'forbidden' ? 403 : 404); }
