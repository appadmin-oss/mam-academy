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
/* Through mam_mastery_publishable(): this route used to return the engine's whole
 * array, `raw_score` included, so the precise composite the coverage floor exists
 * to withhold arrived in the student's own browser. See mastery.php for the two
 * keys it strips and why the bounds are only stripped when the score is withheld. */
mam_ok([
    'mastery' => mam_mastery_publishable(mam_mastery_score_cached((int) $u['id'])),
    'levels'  => MAM_MASTERY_LEVELS,
]);
