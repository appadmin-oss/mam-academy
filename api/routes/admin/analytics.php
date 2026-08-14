<?php
/**
 * GET /api/admin/analytics — LMS 2.0 academic intelligence (PDF §32-35):
 * academy health, per-instructor performance, and today's snapshot. Read-only.
 */
declare(strict_types=1);
mam_require_role('admin');
mam_ok([
    'health'     => mam_analytics2_academy_health(),
    'instructors'=> mam_analytics2_instructor_performance(),
    'today'      => mam_analytics2_today(),
]);
