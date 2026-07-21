<?php
/**
 * Admin progress report token management.
 *
 * GET  ?student_id=N              → list tokens for a student
 * POST action=generate            {student_id, label?, expires_at?} → {token, url}
 * POST action=revoke              {token_id, student_id}            → {revoked: true}
 */
declare(strict_types=1);
$u = mam_require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $studentId = (int)($_GET['student_id'] ?? 0);
    if ($studentId < 1) mam_fail('missing_field', 400, ['field' => 'student_id']);
    $student = mam_db_row('SELECT id, name, email FROM users WHERE id = ?', [$studentId]);
    if (!$student) mam_fail('not_found', 404);
    mam_ok([
        'student' => $student,
        'tokens'  => mam_progress_tokens_for_student($studentId),
    ]);
    exit;
}

$in     = mam_json_input();
$action = (string)($in['action'] ?? '');

if ($action === 'generate_ref') {
    $studentId = (int)($in['student_id'] ?? 0);
    if ($studentId < 1) mam_fail('missing_field', 400, ['field' => 'student_id']);
    if (!mam_db_row('SELECT id FROM users WHERE id = ? AND role = ?', [$studentId, 'student'])) mam_fail('not_found', 404);
    $ref = mam_enrollment_ref_generate($studentId);
    mam_ok(['enrollment_ref' => $ref]);
    exit;
}

if ($action === 'backfill_refs') {
    // Generate enrollment refs for all active students who don't have one yet.
    $missing = mam_db_all(
        "SELECT id FROM users WHERE role = 'student' AND status = 'active'
           AND (enrollment_ref IS NULL OR enrollment_ref = '')
         ORDER BY id LIMIT 500"
    );
    $generated = 0; $errors = 0;
    foreach ($missing as $row) {
        try { mam_enrollment_ref_generate((int)$row['id']); $generated++; }
        catch (\Throwable $e) { $errors++; mam_log('warn', 'ref_backfill_failed', ['id' => $row['id'], 'err' => $e->getMessage()]); }
    }
    mam_audit('enrollment_ref_backfill', 'users', 0, ['generated' => $generated, 'errors' => $errors]);
    mam_ok(['generated' => $generated, 'errors' => $errors, 'total_missing' => count($missing)]);
    exit;
}

if ($action === 'generate') {
    $studentId = (int)($in['student_id'] ?? 0);
    if ($studentId < 1) mam_fail('missing_field', 400, ['field' => 'student_id']);
    if (!mam_db_row('SELECT id FROM users WHERE id = ? AND role = ?', [$studentId, 'student'])) mam_fail('not_found', 404);
    $label = trim((string)($in['label'] ?? ''));
    $expiresAt = isset($in['expires_at']) && is_string($in['expires_at']) && $in['expires_at'] !== '' ? $in['expires_at'] : null;
    $token = mam_progress_token_create($studentId, (int)$u['id'], $label, $expiresAt);
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    mam_ok(['token' => $token, 'url' => $baseUrl . '/learner-report?t=' . urlencode($token)]);
    exit;
}

if ($action === 'revoke') {
    $tokenId   = (int)($in['token_id']   ?? 0);
    $studentId = (int)($in['student_id'] ?? 0);
    if ($tokenId < 1 || $studentId < 1) mam_fail('missing_field', 400, ['field' => 'token_id']);
    mam_progress_token_revoke($tokenId, $studentId);
    mam_ok(['revoked' => true]);
    exit;
}

mam_fail('unknown_action', 400, ['action' => $action]);
