<?php
/**
 * GET /api/student/mastery — the signed-in student's MAM Mastery Score™ (§24)
 * with its §23 level, per-component breakdown and evidence coverage.
 *
 * Attendance and punctuality come back in their own key, never folded into the
 * score — §24 is explicit that musical ability must not be confused with
 * disciplinary performance.
 */
declare(strict_types=1);

$u = mam_require_user();
mam_ok([
    'mastery' => mam_mastery_score((int) $u['id']),
    'levels'  => MAM_MASTERY_LEVELS,
]);
