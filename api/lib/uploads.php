<?php
/**
 * First-party image storage for registration headshots.
 *
 * Why this exists: every photo path in the app used to store a URL string and
 * nothing else, so the ONLY way a headshot was ever persisted was an unsigned
 * upload straight from the applicant's browser to Cloudinary. With Cloudinary
 * unconfigured the widget told the applicant "✓ Photo accepted", uploaded
 * nothing, and submitted an empty headshotUrl — the academy got a registration
 * with no photograph and printed a faceless ID card. A photo the applicant was
 * told we had must actually be somewhere.
 *
 * Files live OUTSIDE the web root (api/storage/uploads/…, which `Require all
 * denied` already blocks) and are served back through `GET /api/media/headshot`.
 * That is deliberate: an upload directory the web server will execute is the
 * classic way a photo form becomes remote code execution. Nothing here is ever
 * executed — bytes are re-encoded by GD and streamed back with a fixed
 * Content-Type.
 *
 * Cloudinary is still used when it IS configured (better delivery, transforms);
 * this is the floor that guarantees a photo is never silently lost.
 */
declare(strict_types=1);

/** Hard ceiling on a decoded upload. A headshot has no business being larger,
 *  and an unbounded decode is a memory-exhaustion vector on shared hosting. */
const MAM_UPLOAD_MAX_BYTES = 8 * 1024 * 1024;   // 8 MB of source bytes
const MAM_UPLOAD_MAX_PIXELS = 40000000;         // 40 MP — guards decompression bombs
const MAM_UPLOAD_LONG_EDGE = 1400;              // stored size; ample for a 74mm card at 350 DPI

/** Absolute path of the headshot store, created on demand. */
function mam_upload_dir(): string
{
    $base = MAM_STORAGE . '/uploads/headshots';
    if (!is_dir($base)) @mkdir($base, 0775, true);
    return $base;
}

/**
 * A storage key: `<yyyymm>/<32 hex>`. The month prefix keeps directories from
 * growing without bound; the random half means a key cannot be guessed or
 * enumerated, so a leaked key exposes one photo rather than the whole intake.
 */
function mam_upload_key_generate(): string
{
    return date('Ym') . '/' . bin2hex(random_bytes(16));
}

/**
 * Resolve a key to a path, refusing anything that isn't exactly the key grammar.
 * Never concatenate caller input into a path without this: `../` in a key is how
 * a media endpoint becomes an arbitrary-file reader.
 */
function mam_upload_path(string $key): ?string
{
    if (!preg_match('#^[0-9]{6}/[0-9a-f]{32}$#', $key)) return null;
    return mam_upload_dir() . '/' . $key . '.jpg';
}

/**
 * Validate, normalise and store an image.
 *
 * Re-encoding through GD rather than trusting the bytes is the point: it proves
 * the file really is an image (a renamed .php simply fails to decode), drops any
 * EXIF payload, and normalises orientation and size. What lands on disk is always
 * a plain baseline JPEG we produced ourselves.
 *
 * @param string $bytes  raw image bytes (from a data: URL or a multipart file)
 * @return array{ok:bool,key:?string,url:?string,error:?string,width:?int,height:?int,bytes:?int}
 */
function mam_upload_store_image(string $bytes): array
{
    $fail = static fn(string $e): array => ['ok' => false, 'key' => null, 'url' => null, 'error' => $e, 'width' => null, 'height' => null, 'bytes' => null];

    if ($bytes === '') return $fail('empty_file');
    if (strlen($bytes) > MAM_UPLOAD_MAX_BYTES) return $fail('too_large');
    if (!function_exists('imagecreatefromstring')) return $fail('server_no_gd');

    // Dimensions BEFORE decoding, so a decompression bomb is rejected without
    // allocating for it.
    $info = @getimagesizefromstring($bytes);
    if (!$info || empty($info[0]) || empty($info[1])) return $fail('not_an_image');
    [$w, $h] = [(int)$info[0], (int)$info[1]];
    if ($w * $h > MAM_UPLOAD_MAX_PIXELS) return $fail('too_many_pixels');
    if (!in_array((int)$info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        return $fail('unsupported_type');
    }

    $src = @imagecreatefromstring($bytes);
    if (!$src) return $fail('not_an_image');

    // Downscale the long edge; never upscale a small photo (that only adds bulk).
    $long = max($w, $h);
    $scale = $long > MAM_UPLOAD_LONG_EDGE ? MAM_UPLOAD_LONG_EDGE / $long : 1.0;
    $tw = max(1, (int)round($w * $scale));
    $th = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($tw, $th);
    if (!$dst) { imagedestroy($src); return $fail('server_error'); }
    // Flatten onto white: a transparent PNG headshot would otherwise composite to
    // black once JPEG drops the alpha channel.
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $tw, $th, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagedestroy($src);

    $key  = mam_upload_key_generate();
    $path = mam_upload_path($key);
    if ($path === null) { imagedestroy($dst); return $fail('server_error'); }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { imagedestroy($dst); return $fail('store_unwritable'); }

    ob_start();
    $ok = imagejpeg($dst, null, 88);
    $jpeg = (string)ob_get_clean();
    imagedestroy($dst);
    if (!$ok || $jpeg === '') return $fail('encode_failed');

    // Write via a temp file + rename so a partially written photo is never served.
    $tmp = $path . '.part';
    if (@file_put_contents($tmp, $jpeg) === false) return $fail('store_unwritable');
    if (!@rename($tmp, $path)) { @unlink($tmp); return $fail('store_unwritable'); }

    return [
        'ok'     => true,
        'key'    => $key,
        'url'    => mam_upload_url($key),
        'error'  => null,
        'width'  => $tw,
        'height' => $th,
        'bytes'  => strlen($jpeg),
    ];
}

/** The public, same-origin URL for a stored headshot. */
function mam_upload_url(string $key): string
{
    return '/api/media/headshot?k=' . rawurlencode($key);
}

/** Is this one of our own stored-headshot URLs? Used by the form validators, which
 *  otherwise only accept an https Cloudinary URL and would drop these. */
function mam_upload_is_own_url(string $url): bool
{
    return (bool)preg_match('#^/api/media/headshot\?k=[0-9]{6}(/|%2F)[0-9a-f]{32}$#i', trim($url));
}

/** The storage key inside one of our URLs, or null. */
function mam_upload_key_from_url(string $url): ?string
{
    if (!preg_match('#[?&]k=([^&]+)#', trim($url), $m)) return null;
    $key = rawurldecode($m[1]);
    return mam_upload_path($key) !== null ? $key : null;
}
