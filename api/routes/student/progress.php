<?php
/**
 * Student progress route — authenticated access to the student's own report.
 *
 * GET  /api/student/progress
 *   Returns the full progress report for the logged-in student, auto-generating
 *   their enrollment ref if one hasn't been assigned yet.
 *   Also returns their current tokens (for sharing with parents).
 *
 * POST /api/student/progress  { action: 'generate_token', label?, expires_days? }
 *   Generates a new shareable token link for the student's parent/guardian.
 *
 * POST /api/student/progress  { action: 'revoke_token', token_id }
 *   Revokes a previously generated token.
 */
declare(strict_types=1);
$u = mam_require_role('student');
$studentId = (int)$u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Auto-generate enrollment ref if missing
    if (empty($u['enrollment_ref'])) {
        try { mam_enrollment_ref_generate($studentId); } catch (Throwable $e) { /* non-fatal */ }
        $u = mam_db_row('SELECT enrollment_ref FROM users WHERE id = ?', [$studentId]);
    }

    $report = mam_progress_report_data($studentId);
    // Include own email (student is authenticated — no PII stripping needed)
    $report['tokens'] = mam_progress_tokens_for_student($studentId);
    mam_ok($report);
    exit;
}

$in     = mam_json_input();
$action = (string)($in['action'] ?? '');

if ($action === 'generate_token') {
    $label      = mb_substr(trim((string)($in['label'] ?? 'Guardian')), 0, 200);
    $days       = max(1, min(365, (int)($in['expires_days'] ?? 90)));
    $expiresAt  = date('Y-m-d H:i:s', strtotime("+{$days} days"));
    $token      = mam_progress_token_create($studentId, $studentId, $label, $expiresAt);
    $baseUrl    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                  . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    mam_ok(['token' => $token, 'url' => $baseUrl . '/learner-report?t=' . urlencode($token), 'expires_at' => $expiresAt]);
    exit;
}

if ($action === 'revoke_token') {
    $tokenId = (int)($in['token_id'] ?? 0);
    if ($tokenId < 1) mam_fail('missing_field', 400, ['field' => 'token_id']);
    mam_progress_token_revoke($tokenId, $studentId);
    mam_ok(['revoked' => true]);
    exit;
}

mam_fail('unknown_action', 400, ['action' => $action]);
