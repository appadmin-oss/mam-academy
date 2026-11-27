<?php
/**
 * Uploads to the file store (api/modules/integrations/lib/media-store.php, schema v99).
 *
 *   POST /api/media/sign {purpose, name, type, size, meta?}  → {id, kind, provider, max, upload:{url, method, headers}}
 *   PUT  /api/media/put?id=N&t=…   (no bucket only) the file's bytes, straight to this host
 *   POST /api/media/done {id, meta?}                          → {file}  — checked against what was signed
 *
 * The browser compresses BEFORE it signs (assets/js/media-upload.js), so the
 * size signed is the size of the compressed file.
 */
declare(strict_types=1);

$u   = mam_require_user();
$uid = (int) $u['id'];
$step = (string) ($GLOBALS['mam_media_step'] ?? 'sign');

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!mam_media_put_token_ok((string) ($_GET['t'] ?? ''), $id, $uid)) mam_fail('upload_expired', 403, ['message' => 'That upload link has expired. Try again.']);
    $in = fopen('php://input', 'rb');
    try { mam_media_local_receive($id, $uid, $in); }
    catch (RuntimeException $e) {
        $m = ['too_large' => 'The file is larger than was agreed.', 'size_mismatch' => 'The file did not arrive whole. Try again.', 'not_found' => 'That upload was not found.'];
        mam_fail($e->getMessage(), $e->getMessage() === 'not_found' ? 404 : 422, ['message' => $m[$e->getMessage()] ?? 'The file could not be stored.']);
    } finally { if (is_resource($in)) fclose($in); }
    mam_ok(['stored' => true]);
}

$in = mam_json_input();
$meta = is_array($in['meta'] ?? null) ? $in['meta'] : [];
if ($step === 'done') {
    try {
        $o = mam_media_finish((int) ($in['id'] ?? 0), $uid, $meta);
        mam_ok(['file' => mam_media_public_view($o)]);
    } catch (RuntimeException $e) {
        $m = ['not_uploaded' => 'The file did not arrive. Try again.', 'size_mismatch' => 'The file did not arrive whole. Try again.',
              'not_an_image' => 'That is not a picture we can read.', 'not_found' => 'That upload was not found.'];
        mam_fail($e->getMessage(), 422, ['message' => $m[$e->getMessage()] ?? 'The file could not be checked.']);
    }
}

try {
    $r = mam_media_begin($uid, (string) ($in['purpose'] ?? ''), (string) ($in['name'] ?? ''), (string) ($in['type'] ?? ''), (int) ($in['size'] ?? 0), $meta);
    mam_ok($r);
} catch (InvalidArgumentException $e) {
    mam_fail('upload_refused', 422, ['message' => $e->getMessage()]);
}
