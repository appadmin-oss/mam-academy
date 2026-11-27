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

/**
 * May this URL be stored as somebody's photo (card face, portal avatar)?
 *
 * Exactly two shapes are acceptable, and the order matters:
 *   1. one of OUR OWN stored headshots — same-origin, bytes we re-encoded;
 *   2. an absolute https:// URL — a hosted image (Cloudinary and the like).
 *
 * Everything else is refused, which is what keeps `javascript:`, `data:` and
 * plain-http out of an attribute that is rendered as an <img src>. The first
 * clause is the one that was missing: a photo could be uploaded and stored
 * successfully and then be rejected on the way onto the card, which left the
 * only working path "paste a link to somewhere else".
 */
function mam_upload_photo_url_ok(string $url): bool
{
    $url = trim($url);
    if ($url === '') return false;
    if (strlen($url) > 500) return false;
    if (mam_upload_is_own_url($url)) return true;
    return (bool)filter_var($url, FILTER_VALIDATE_URL)
        && (bool)preg_match('#^https://[^\s"\'<>]+$#i', $url);
}

/** The storage key inside one of our URLs, or null. */
function mam_upload_key_from_url(string $url): ?string
{
    if (!preg_match('#[?&]k=([^&]+)#', trim($url), $m)) return null;
    $key = rawurldecode($m[1]);
    return mam_upload_path($key) !== null ? $key : null;
}

/* ────────────────────────────────────────────────────────────────────────────
   AUDIO TAKES (Advanced Modernization §1.4)

   A student records themselves playing over a backing track and submits the
   take. Audio cannot ride the headshot path: the property that makes the image
   store safe is that GD RE-ENCODES the bytes, so a renamed .php simply fails to
   decode and never lands. There is no equivalent re-encoder for audio on shared
   hosting (no ffmpeg), so the safety comes from three other things instead:

     1. Container sniffing. The bytes must BEGIN with a known audio/video
        container signature. A PHP script, an HTML page or an ELF binary does
        not, so it is refused before anything is written.
     2. Files live outside the web root (api/storage is `Require all denied`),
        under an extension we choose — never one the client supplied. Nothing
        there is reachable by URL, let alone executable.
     3. The only way back out is a gated proxy that pins Content-Type,
        sends nosniff, and checks that the caller is allowed to hear it.

   That is the same shape as the Drive proxy, and it is why the stored extension
   is derived from the SNIFFED container rather than from the upload's filename.
   ──────────────────────────────────────────────────────────────────────────── */

const MAM_TAKE_MAX_BYTES = 24 * 1024 * 1024;   // ~15 min of opus at 128kbps

/** Where takes live. Outside the web root, like every other upload. */
function mam_take_dir(): string
{
    $base = MAM_STORAGE . '/uploads/takes';
    if (!is_dir($base)) @mkdir($base, 0775, true);
    return $base;
}

/**
 * Sniff an audio container from its leading bytes.
 * Returns ['ext'=>string,'mime'=>string] or null when it is not audio we accept.
 *
 * Deliberately narrow: these are the containers a browser MediaRecorder
 * actually produces (webm/ogg on Chromium and Firefox, mp4/m4a on Safari) plus
 * wav for anything that hands us raw PCM. Widening this list widens what can be
 * stored, so it is a security decision, not a convenience one.
 */
function mam_take_sniff(string $bytes): ?array
{
    if (strlen($bytes) < 12) return null;
    // WebM / Matroska — EBML header.
    if (substr($bytes, 0, 4) === "\x1A\x45\xDF\xA3") return ['ext' => 'webm', 'mime' => 'audio/webm'];
    // Ogg (Opus/Vorbis).
    if (substr($bytes, 0, 4) === 'OggS')             return ['ext' => 'ogg',  'mime' => 'audio/ogg'];
    // RIFF/WAVE — must be BOTH, or any RIFF container would pass.
    if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WAVE') return ['ext' => 'wav', 'mime' => 'audio/wav'];
    // ISO-BMFF (mp4/m4a): 4-byte size, then 'ftyp'.
    if (substr($bytes, 4, 4) === 'ftyp')             return ['ext' => 'm4a',  'mime' => 'audio/mp4'];
    // Bare MP3: an ID3 tag, or an MPEG frame sync.
    if (substr($bytes, 0, 3) === 'ID3')              return ['ext' => 'mp3',  'mime' => 'audio/mpeg'];
    if ((ord($bytes[0]) === 0xFF) && ((ord($bytes[1]) & 0xE0) === 0xE0)) return ['ext' => 'mp3', 'mime' => 'audio/mpeg'];
    return null;
}

/**
 * Resolve a take key to a path. Same grammar as headshots plus the extension,
 * which is why the extension is part of the key: without it the proxy would
 * have to guess, and guessing is how a media endpoint starts reading files it
 * was never meant to.
 */
function mam_take_path(string $key): ?string
{
    if (!preg_match('#^([0-9]{6})/([0-9a-f]{32})\.(webm|ogg|wav|m4a|mp3)$#', $key)) return null;
    return mam_take_dir() . '/' . $key;
}

/** The MIME to serve a key as — derived from the key, never from the client. */
function mam_take_mime(string $key): ?string
{
    if (!preg_match('#\.(webm|ogg|wav|m4a|mp3)$#', $key, $m)) return null;
    return ['webm' => 'audio/webm', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
            'm4a'  => 'audio/mp4',  'mp3' => 'audio/mpeg'][$m[1]] ?? null;
}

/**
 * Validate and store a recorded take.
 * @return array{ok:bool,key:?string,url:?string,error:?string,bytes:?int,mime:?string}
 */
function mam_take_store(string $bytes): array
{
    $fail = fn(string $e) => ['ok' => false, 'key' => null, 'url' => null, 'error' => $e, 'bytes' => null, 'mime' => null];

    if ($bytes === '')                        return $fail('empty');
    if (strlen($bytes) > MAM_TAKE_MAX_BYTES)  return $fail('too_large');
    $sniff = mam_take_sniff($bytes);
    if ($sniff === null)                      return $fail('not_audio');

    $key = date('Ym') . '/' . bin2hex(random_bytes(16)) . '.' . $sniff['ext'];
    $path = mam_take_path($key);
    if ($path === null)                       return $fail('bad_key');   // unreachable; belt and braces
    if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true)) return $fail('store_unwritable');

    // Temp file + rename, so a partial upload is never served.
    $tmp = $path . '.part';
    if (@file_put_contents($tmp, $bytes) === false) return $fail('store_unwritable');
    if (!@rename($tmp, $path)) { @unlink($tmp); return $fail('store_unwritable'); }

    return ['ok' => true, 'key' => $key, 'url' => mam_take_url($key), 'error' => null,
            'bytes' => strlen($bytes), 'mime' => $sniff['mime']];
}

/** Same-origin URL for a stored take. */
function mam_take_url(string $key): string
{
    return '/api/media/take?k=' . rawurlencode($key);
}

/** Is this one of our own take URLs? */
function mam_take_is_own_url(string $url): bool
{
    $url = trim($url);
    if ($url === '') return false;
    $path = parse_url($url, PHP_URL_PATH);
    if ($path !== '/api/media/take') return false;
    return mam_take_key_from_url($url) !== null;
}

/** The storage key inside one of our take URLs, or null. */
function mam_take_key_from_url(string $url): ?string
{
    if (!preg_match('#[?&]k=([^&]+)#', trim($url), $m)) return null;
    $key = rawurldecode($m[1]);
    return mam_take_path($key) !== null ? $key : null;
}
