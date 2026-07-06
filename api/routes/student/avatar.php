<?php
/**
 * POST /api/student/avatar  { avatar_url }
 *
 * Set (or clear, with an empty string) the signed-in student's portal photo.
 * The applicant's application headshot becomes the avatar automatically at
 * provisioning (mam_ensure_student_user); this lets a student swap it later.
 * Student-only, CSRF-protected. The URL is scheme-guarded (https only) and
 * length-bounded; it is rendered escaped as an <img src> in the portal.
 */
declare(strict_types=1);

$u  = mam_require_role('student');
$in = mam_json_input();
$url = trim((string)($in['avatar_url'] ?? ''));

if ($url === '') {
    mam_db_update('users', ['avatar_url' => null], ['id' => (int)$u['id']]);
    mam_audit('update', 'user', (int)$u['id'], ['avatar' => 'cleared']);
    mam_ok(['avatar_url' => null]);
}

// Scheme guard: only a secure https URL may become an avatar (never javascript:/
// data: — which would be an injection vector even though we escape on render).
if (strlen($url) > 500 || !preg_match('#^https://[^\s"\'<>]+$#i', $url)) {
    mam_fail('invalid_url', 422, ['detail' => 'A secure https image URL is required.']);
}

mam_db_update('users', ['avatar_url' => $url], ['id' => (int)$u['id']]);
mam_audit('update', 'user', (int)$u['id'], ['avatar' => 'set']);
mam_ok(['avatar_url' => $url]);
