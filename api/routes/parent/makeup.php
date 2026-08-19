<?php
/** GET /api/parent/makeup?student_id=N — make-up credits and the policy (§3.5). */
declare(strict_types=1);
$u   = mam_require_role('parent');
$sid = (int) ($_GET['student_id'] ?? 0);
if ($sid < 1) mam_fail('validation', 422, ['fields' => ['student_id']]);
try { mam_ok(mam_makeup_for_guardian((int) $u['id'], $sid)); }
catch (\RuntimeException $e) { mam_fail($e->getMessage(), 403); }
