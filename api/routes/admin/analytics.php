<?php
/**
 * GET /api/admin/analytics — LMS 2.0 academic intelligence (PDF §32-35):
 * academy health, per-instructor performance, and today's snapshot. Read-only.
 */
declare(strict_types=1);
// Authorised by mw_admin_cap() on the route, which is the stronger check.
// Re-checking the role here would only add a mam_require_user() that 401s a
// Basic-Auth break-glass operator, who legitimately has no session.
mam_ok([
    'health'     => mam_analytics2_academy_health(),
    'instructors'=> mam_analytics2_instructor_performance(),
    'today'      => mam_analytics2_today(),
]);
