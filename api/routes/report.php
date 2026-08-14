<?php
/**
 * GET /api/report?t={token} — public progress report (no auth).
 * Token validated via progress_report_tokens; expired tokens return 404.
 * Returns sanitised student progress data for the parent-facing report page.
 */
declare(strict_types=1);

$token = trim((string)($_GET['t'] ?? ''));
if ($token === '') mam_fail('missing_token', 400);

$tokenRow = mam_progress_token_resolve($token);
if (!$tokenRow) mam_fail('invalid_or_expired_token', 404);

$studentId = (int)$tokenRow['student_id'];
try {
    $report = mam_progress_report_data($studentId);
} catch (\Throwable $e) {
    // Per-section failures already degrade internally (see mam_progress_report_data);
    // reaching here means a core failure (no such student, or the identity read
    // itself failed on a badly-behind DB). Return a clean 404 rather than a bare 500.
    mam_log('warn', 'report_unavailable', ['err' => $e->getMessage()]);
    mam_fail('report_unavailable', 404);
}

// Strip PII not needed by parents: remove full email from public data
unset($report['student']['email']);
mam_ok($report);
