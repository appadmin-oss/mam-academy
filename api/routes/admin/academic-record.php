<?php
/**
 * GET /api/admin/academic-record?student_id=N — the unified §43 student
 * academic record: all fourteen sections in one payload.
 *
 * Admin-only. It aggregates the student's entire academic file, including
 * communications and accountability, so it sits behind the `users` capability
 * rather than plain `view`.
 */
declare(strict_types=1);

// Access is already enforced by mw_admin_cap('users') on the route, which is
// the stronger check. Re-checking the role here would only add a
// mam_require_user() that 401s a Basic-Auth break-glass operator.

$studentId = (int) ($_GET['student_id'] ?? 0);
if ($studentId < 1) mam_fail('missing_field', 400, ['field' => 'student_id']);

try {
    mam_ok(['record' => mam_academic_record($studentId)]);
} catch (\RuntimeException $e) {
    mam_fail($e->getMessage(), 404);
}
