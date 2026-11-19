<?php
/**
 * GET /api/media/object?id=N[&download=1]
 *
 * The ONLY way a stored file is read. Who is asking is checked first
 * (mam_media_can_read: the owner, an admin, or whatever the file is attached
 * to decides — a class space's own visibility rule, a student's teachers and
 * guardians). Then a bucket file is a 302 to a link that lives ten minutes,
 * and a local file is streamed with Range support. A file that is missing,
 * unfinished or not yours is the same 404, so nothing here is an oracle.
 */
declare(strict_types=1);

$deny = static function (int $status, string $error): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $error]);
    if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
};

$u = mam_session_user();
if (!$u) { $deny(401, 'authentication_required'); return; }
$o = mam_db_row("SELECT * FROM media_objects WHERE id = ?", [(int) ($_GET['id'] ?? 0)]);
if (!$o || $o['status'] !== 'uploaded' || !mam_media_can_read($o, $u)) { $deny(404, 'not_found'); return; }

$url = mam_media_read_url($o, !empty($_GET['download']));
if ($url !== null) {
    header_remove('Content-Type');
    header('Cache-Control: private, no-store');
    header('Location: ' . $url, true, 302);
} else {
    mam_media_stream_local($o);
}
if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
