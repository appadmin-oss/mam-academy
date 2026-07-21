<?php
/**
 * GET /api/drive/file?rid=<class_resources.id>
 *
 * Access-gated proxy that streams a Drive-backed resource's bytes back through
 * the site (#5), so files can stay private in Drive yet remain fetchable by
 * exactly the people who may see the resource. The Drive file id is recovered
 * from the resource's stored URL; visibility is enforced per role:
 *   - admin: any resource
 *   - instructor: a resource on a class/cohort they teach
 *   - student: a resource delivered to them by the dual-read resources feed
 * Any authenticated user may call it, but only for a resource they can see.
 */
declare(strict_types=1);

$u = mam_require_role('student', 'instructor', 'admin');
$rid = (int)($_GET['rid'] ?? 0);
if ($rid <= 0) mam_fail('validation', 422, ['fields' => ['rid']]);

$res = mam_db_row('SELECT id, class_id, cohort_id, url, title FROM class_resources WHERE id = ?', [$rid]);
if (!$res) mam_fail('not_found', 404);

$fileId = mam_drive_id_from_url((string)$res['url']);
if ($fileId === null) mam_fail('not_a_drive_file', 404, ['detail' => 'This resource is a plain link, not a Drive-hosted file.']);

// ── Visibility ──
$roles   = function_exists('mam_user_roles') ? mam_user_roles($u) : [$u['role'] ?? ''];
$isAdmin = in_array('admin', $roles, true);
$allowed = $isAdmin;
if (!$allowed && in_array('instructor', $roles, true)) {
    if (!empty($res['cohort_id'])) $allowed = mam_lms_instructor_owns_cohort((int)$u['id'], (int)$res['cohort_id']);
    elseif (!empty($res['class_id'])) $allowed = mam_db_row('SELECT 1 FROM classes WHERE id = ? AND instructor_id = ?', [(int)$res['class_id'], (int)$u['id']]) !== null;
}
if (!$allowed && in_array('student', $roles, true)) {
    foreach (mam_student_resources((int)$u['id'], 200) as $r) { if ((int)$r['id'] === $rid) { $allowed = true; break; } }
}
if (!$allowed) mam_fail('forbidden', 403);

$got = mam_drive_fetch($fileId);
if (!$got['ok']) mam_fail('fetch_failed', 502, ['detail' => $got['error']]);

if (!headers_sent()) {
    $mime = strtolower((string)($got['mime'] ?: 'application/octet-stream'));
    // Content types that execute script when opened as a top-level document.
    // Never serve these inline from our own origin — a crafted SVG/HTML/XML
    // upload would otherwise run JS on the academy origin (stored XSS). Force a
    // download and neutralise the declared type for them.
    $active = strpos($mime, 'html') !== false || in_array($mime, [
        'image/svg+xml', 'image/svg', 'text/xml', 'application/xml',
        'application/xhtml+xml', 'text/html',
    ], true);
    $serveType   = $active ? 'application/octet-stream' : ($got['mime'] ?: 'application/octet-stream');
    $disposition = $active ? 'attachment' : 'inline';
    header('Content-Type: ' . $serveType);
    header('Content-Length: ' . $got['size']);
    // Inline (for inert media/PDF) or attachment (for active types); filename sanitised.
    $fname = preg_replace('/[^A-Za-z0-9._ -]/', '', (string)($got['name'] ?: $res['title'] ?: 'file')) ?: 'file';
    header('Content-Disposition: ' . $disposition . '; filename="' . $fname . '"');
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    // Belt-and-braces: sandbox the proxied bytes so they can neither execute
    // script nor load subresources, whatever the declared type (defends rows
    // uploaded before the mime allowlist was tightened).
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox");
}
echo $got['data'];
exit;
