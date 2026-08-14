<?php
/** GET /api/parent/child?student_id=N — §26 detail for one linked child. */
declare(strict_types=1);
$u   = mam_require_role('parent');
$sid = (int) ($_GET['student_id'] ?? 0);
if ($sid < 1) mam_fail('validation', 422, ['fields' => ['student_id']]);
try {
    mam_ok(mam_parent_child_detail((int) $u['id'], $sid));
} catch (\RuntimeException $e) {
    mam_fail($e->getMessage(), $e->getMessage() === 'forbidden' ? 403 : 404);
}
