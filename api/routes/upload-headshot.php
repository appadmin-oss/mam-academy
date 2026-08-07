<?php
/**
 * POST /api/upload/headshot   { image: "data:image/jpeg;base64,…" }
 *
 * First-party headshot storage, so a registration photo is never silently lost.
 * Public by necessity — applicants are not signed in when they register — hence
 * CSRF + a tight rate limit, a size ceiling, and GD re-encoding that proves the
 * bytes really are an image (see api/lib/uploads.php).
 *
 * Returns { url } — a same-origin /api/media/headshot?k=… URL the form submits as
 * `headshotUrl` exactly as it would a Cloudinary one.
 */
declare(strict_types=1);

$in  = mam_json_input();
$raw = (string)($in['image'] ?? '');

if ($raw === '') mam_fail('missing_image', 422, ['detail' => 'No image supplied.']);

// Accept only a base64 image data URL; anything else is a client bug, not a photo.
if (!preg_match('#^data:image/(jpeg|jpg|png|webp|gif);base64,#i', $raw, $m)) {
    mam_fail('bad_image', 422, ['detail' => 'Expected a base64 image data URL.']);
}
$b64 = substr($raw, strpos($raw, ',') + 1);
// Guard the DECODED size before decoding: base64 is ~4/3 of the payload.
if (strlen($b64) > (int)(MAM_UPLOAD_MAX_BYTES * 1.4)) {
    mam_fail('too_large', 413, ['detail' => 'That photo is too large — please use one under 8 MB.']);
}
$bytes = base64_decode($b64, true);
if ($bytes === false) mam_fail('bad_image', 422, ['detail' => 'The image data could not be read.']);

$res = mam_upload_store_image($bytes);
if (!$res['ok']) {
    $human = [
        'too_large'        => 'That photo is too large — please use one under 8 MB.',
        'too_many_pixels'  => 'That image’s dimensions are too large.',
        'not_an_image'     => 'That file is not a readable image.',
        'unsupported_type' => 'Please upload a JPEG, PNG or WebP.',
        'store_unwritable' => 'The server could not save the photo. Please tell the academy.',
        'server_no_gd'     => 'The server cannot process images. Please tell the academy.',
    ][$res['error']] ?? 'The photo could not be saved.';
    mam_log('warn', 'headshot_store_failed', ['error' => $res['error']]);
    mam_fail($res['error'], 422, ['detail' => $human]);
}

mam_audit('upload', 'headshot', null, ['key' => $res['key'], 'bytes' => $res['bytes'], 'w' => $res['width'], 'h' => $res['height']]);
mam_ok(['url' => $res['url'], 'width' => $res['width'], 'height' => $res['height'], 'bytes' => $res['bytes']]);
