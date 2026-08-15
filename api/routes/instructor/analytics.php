<?php
/**
 * GET /api/instructor/analytics — the caller's own cohort analytics (§5.3).
 */
declare(strict_types=1);
$u   = mam_require_role('instructor', 'admin');
$uid = (int) $u['id'];
mam_ok([
    'cohorts' => mam_analytics2_instructor_cohorts($uid),
    'at_risk' => mam_analytics2_instructor_at_risk($uid, 30),
    'arrival' => mam_analytics2_arrival($uid),
]);
