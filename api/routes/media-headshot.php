<?php
/**
 * GET /api/media/headshot?k=<key>
 *
 * Streams a stored headshot. The files live outside the web root (api/storage,
 * which `Require all denied` blocks) precisely so nothing there can be executed;
 * this route is the only way back out.
 *
 * The key grammar is enforced by mam_upload_path() — an unvalidated key is how a
 * media endpoint turns into an arbitrary-file reader. Content-Type is fixed to
 * image/jpeg because that is the only thing we ever write.
 */
declare(strict_types=1);

$key  = (string)($_GET['k'] ?? '');
$path = mam_upload_path($key);

if ($path === null || !is_file($path)) {
    // Same answer for a malformed key and a missing file: no enumeration signal.
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
    return;
}

$mtime = (int)@filemtime($path);
$etag  = '"' . substr(hash('sha256', $key . '|' . $mtime), 0, 24) . '"';

// The key is content-addressed by randomness, so a stored photo never changes:
// immutable caching is safe and keeps a 200-card studio page cheap.
header_remove('Content-Type');
header('Content-Type: image/jpeg');
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
// Never let a stored upload be treated as a document by the browser.
header('Content-Disposition: inline; filename="headshot.jpg"');

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
    return;
}

readfile($path);
// Claim the response so bootstrap's shutdown handler does not append a JSON
// envelope to the image bytes.
if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
