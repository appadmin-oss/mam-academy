<?php
/**
 * POST /api/student/avatar  { avatar_url }
 *
 * Set (or clear, with an empty string) the signed-in student's portal photo.
 * The applicant's application headshot becomes the avatar automatically at
 * provisioning (mam_ensure_student_user); this lets a student swap it later.
 * Student-only, CSRF-protected. The URL must be one of our own stored headshots
 * or a secure https URL, and is length-bounded; it is rendered escaped as an
 * <img src> in the portal.
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

// Scheme guard: our own stored headshot, or a secure https URL — never
// javascript:/data:, which would be an injection vector even though we escape on
// render. The first-party case is what lets the portal's own uploader work; a
// student who uploads a photo would otherwise be told the URL was invalid.
if (!mam_upload_photo_url_ok($url)) {
    mam_fail('invalid_url', 422, ['detail' => 'Upload a photo, or give a secure https image URL.']);
}

mam_db_update('users', ['avatar_url' => $url], ['id' => (int)$u['id']]);
mam_audit('update', 'user', (int)$u['id'], ['avatar' => 'set']);
mam_ok(['avatar_url' => $url]);
