<?php
/**
 * GET  /api/parent/bursary?student_id=N — the published bands + own applications.
 * POST /api/parent/bursary {student_id, income_band, household_size?, dependants_in_academy?}
 *
 * Assessed on a declared income BAND, never a written case for hardship, and
 * applying never affects an admission decision.
 */
declare(strict_types=1);
$u = mam_require_role('parent');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $sid = (int) ($_GET['student_id'] ?? 0);
    if ($sid < 1) mam_fail('validation', 422, ['fields' => ['student_id']]);
    try {
        mam_ok([
            'bands' => mam_bursary_bands(),
            'applications' => mam_bursary_for_guardian((int) $u['id'], $sid),
            'assurance' => 'Applying never affects an admission decision, and we will not ask you '
                         . 'to write about your circumstances.',
        ]);
    } catch (\RuntimeException $e) { mam_fail($e->getMessage(), 403); }
}

$in  = mam_json_input();
$sid = (int) ($in['student_id'] ?? 0);
$band = (string) ($in['income_band'] ?? '');
if ($sid < 1 || $band === '') mam_fail('validation', 422, ['fields' => ['student_id', 'income_band']]);
try {
    mam_ok(['application_id' => mam_bursary_apply($sid, (int) $u['id'], $band, $in), 'status' => 'submitted']);
} catch (\InvalidArgumentException $e) { mam_fail('validation', 422, ['reason' => $e->getMessage()]); }
catch (\RuntimeException $e) { mam_fail($e->getMessage(), $e->getMessage() === 'forbidden' ? 403 : 409); }
