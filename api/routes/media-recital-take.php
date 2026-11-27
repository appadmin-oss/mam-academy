<?php
/**
 * GET /api/media/recital-take?item=<recital_item_id>
 *
 * Streams a PUBLISHED recital recording (the listen-back gallery).
 *
 * This is a second door onto the same audio files that /api/media/take serves,
 * with a deliberately different lock — see the gallery block in
 * api/modules/community/lib/recital.php. In short: /api/media/take asks "is this YOUR recording
 * or your child's?", and this route asks "was this performance published, and
 * are you part of the academy?". Neither weakens the other: a recording that
 * fails the gallery rules is still reachable by its own student through the
 * private route, and one that passes them is still never public.
 *
 * The item id is the handle rather than the storage key, so a leaked key from
 * anywhere else cannot be replayed here — the caller must name a performance,
 * and the performance is what carries the publication rules.
 */
declare(strict_types=1);

$itemId = (int) ($_GET['item'] ?? 0);

$deny = function (int $status, string $error) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $error]);
    if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
};

if ($itemId < 1) { $deny(404, 'not_found'); return; }

$u = mam_session_user();
// Signed in is the audience test. A recording of a child never goes on the
// open web, however completed the recital or however freely the family
// consented — so this is 401 before anything else is even looked up.
if (!$u) { $deny(401, 'authentication_required'); return; }

if (!mam_recital_can_hear($u, $itemId)) {
    // One answer for "not published", "no consent", "recital not finished" and
    // "no such item". Distinguishing them would let a caller probe which
    // families declined consent, which is precisely the thing consent protects.
    $deny(404, 'not_found');
    return;
}

$row = mam_db_row(
    'SELECT s.artifact_url FROM recital_items ri JOIN submissions s ON s.id = ri.submission_id WHERE ri.id = ?',
    [$itemId]
);
$key = $row ? mam_take_key_from_url((string) $row['artifact_url']) : null;
$path = $key !== null ? mam_take_path($key) : null;
if ($path === null || !is_file($path)) { $deny(404, 'not_found'); return; }

header_remove('Content-Type');
header('Content-Type: ' . (mam_take_mime($key) ?? 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($path));
// Private: the answer depends on who asked and on a consent flag that can be
// withdrawn, so no shared cache may hold it and no browser may keep it past
// the session.
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="performance.' . pathinfo($key, PATHINFO_EXTENSION) . '"');

readfile($path);
if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
