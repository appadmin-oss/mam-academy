<?php
/**
 * First-party headshot storage (api/lib/uploads.php).
 *
 * Why this exists at all: the ONLY way a registration photo was ever persisted
 * was an unsigned browser upload to Cloudinary, and the widget decided whether
 * Cloudinary was configured by reading an ASYNC config that had often not
 * arrived yet. When it hadn't, the applicant saw "✓ Photo accepted", the form
 * submitted an empty headshotUrl, and the ID card printed faceless. These tests
 * cover the store that now guarantees a photo is never silently lost — including
 * the parts that decide what can be smuggled past it.
 */
declare(strict_types=1);
t_group('first-party headshot storage');

/** A real PNG of the requested size, as raw bytes. */
function t_png(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 180, 140, 100));
    ob_start();
    imagepng($im);
    $b = (string)ob_get_clean();
    imagedestroy($im);
    return $b;
}

/* ── Storing a real image ────────────────────────────────────────────────── */
$r = mam_upload_store_image(t_png(600, 800));
t_ok($r['ok'], 'a real PNG is stored');
t_ok(is_string($r['key']) && $r['key'] !== '', 'a storage key is returned');
t_ok(preg_match('#^[0-9]{6}/[0-9a-f]{32}$#', (string)$r['key']) === 1,
    'the key is <yyyymm>/<32 hex> — unguessable and month-bucketed');
$path = mam_upload_path((string)$r['key']);
t_ok($path !== null && is_file($path), 'the file exists on disk');
$mime = (string)(@getimagesize($path)['mime'] ?? '');
t_eq($mime, 'image/jpeg', 'it is stored as a JPEG we re-encoded ourselves');
t_eq($r['width'], 600, 'a photo under the cap keeps its width');

/* ── Downscaling, never upscaling ────────────────────────────────────────── */
$big = mam_upload_store_image(t_png(3000, 4000));
t_eq($big['width'], 1050, 'an oversized photo is downscaled on the long edge');
t_eq($big['height'], 1400, 'and keeps its aspect ratio');
$small = mam_upload_store_image(t_png(200, 260));
t_eq($small['width'], 200, 'a small photo is NOT upscaled (that only adds bulk)');

/* ── What must be refused ────────────────────────────────────────────────── */
// A renamed script is the classic upload attack: it cannot decode, so it cannot land.
$php = mam_upload_store_image('<?php system($_GET["c"]); ?>');
t_eq($php['ok'], false, 'a PHP payload masquerading as an image is refused');
t_eq($php['error'], 'not_an_image', 'and is reported as not an image');
t_eq(mam_upload_store_image('')['error'], 'empty_file', 'an empty upload is refused');
t_eq(mam_upload_store_image(str_repeat('A', MAM_UPLOAD_MAX_BYTES + 1))['error'], 'too_large',
    'an oversized upload is refused before it is decoded');

/* ── Path handling: the media route must not become a file reader ────────── */
t_eq(mam_upload_path('../../../../etc/passwd'), null, 'a traversal key is refused');
t_eq(mam_upload_path('202608/' . str_repeat('z', 32)), null, 'a non-hex key is refused');
t_eq(mam_upload_path('20268/' . str_repeat('a', 32)), null, 'a short month prefix is refused');
t_eq(mam_upload_path(''), null, 'an empty key is refused');
t_ok(mam_upload_path('202608/' . str_repeat('a', 32)) !== null, 'a well-formed key resolves');

/* ── URL round-trip, and the guard the validators rely on ────────────────── */
$url = mam_upload_url((string)$r['key']);
t_ok(strpos($url, '/api/media/headshot?k=') === 0, 'the URL is same-origin, served through the media route');
t_ok(mam_upload_is_own_url($url), 'our own URL is recognised');
t_eq(mam_upload_key_from_url($url), $r['key'], 'the key round-trips out of the URL');
// Things that must NOT be mistaken for ours, or the validator's first-party
// branch becomes a hole.
t_ok(!mam_upload_is_own_url('https://evil.example.com/api/media/headshot?k=202608/' . str_repeat('a', 32)),
    'an absolute URL on another host is not ours');
t_ok(!mam_upload_is_own_url('/api/media/headshot?k=../../etc/passwd'), 'a traversal key is not ours');
t_ok(!mam_upload_is_own_url('javascript:alert(1)'), 'a javascript: URL is not ours');
t_ok(!mam_upload_is_own_url('/api/media/headshot'), 'a URL with no key is not ours');

/* ── The validators must ACCEPT a first-party photo ──────────────────────── */
// This is the regression that would silently reproduce the original bug: a photo
// that stored fine and then got dropped on the way into the database.
$base = [
    'firstName' => 'Ada', 'lastName' => 'Lovelace', 'email' => 'ada.up@example.com',
    'phone' => '+2348000000000', 'dob' => '2010-05-05', 'policySignedName' => 'Anne Byron',
    'guardianName' => 'Anne Byron', 'guardianPhone' => '+2348000000001',
    'policyAgreed' => 1,
];
t_eq(mam_validate_summer_application($base + ['headshotUrl' => $url])['headshotUrl'], $url,
    'the summer validator keeps a first-party headshot URL');
t_eq(mam_validate_summer_application($base + ['headshotUrl' => 'https://res.cloudinary.com/demo/image/upload/x.jpg'])['headshotUrl'],
    'https://res.cloudinary.com/demo/image/upload/x.jpg', 'and still keeps a Cloudinary URL');
t_eq(mam_validate_summer_application($base + ['headshotUrl' => 'http://insecure.example.com/x.jpg'])['headshotUrl'], '',
    'a plain http headshot is still dropped');

/* ── The card face renders the stored photo ──────────────────────────────── */
require_once dirname(MAM_API_ROOT) . '/partials/id-card-face.php';
t_eq(mam_card_face_fields(['photo_url' => $url])['photo'], $url, 'the card face accepts a first-party photo');
$face = mam_card_face_html(['holder_name' => 'Ada Lovelace', 'photo_url' => $url]);
t_ok(strpos($face, 'mam-card__initials') === false, 'a card with a stored photo does NOT fall back to initials');
t_ok(strpos($face, 'img src="/api/media/headshot') !== false, 'the photo is rendered as an img');

/* ── Which URLs may become somebody's photo ──────────────────────────────────
   The shared predicate behind the card face and the portal avatar. Both used to
   demand `https://`, which meant a photo could be stored successfully and then be
   refused on the way onto the card — leaving "paste a link to an image hosted
   somewhere else" as the only route that worked. */
t_ok(mam_upload_photo_url_ok($url), 'our own stored headshot is an acceptable photo URL');
t_ok(mam_upload_photo_url_ok('https://res.cloudinary.com/demo/image/upload/x.jpg'), 'so is an https URL');
t_eq(mam_upload_photo_url_ok('http://insecure.example.com/x.jpg'), false, 'plain http is refused');
t_eq(mam_upload_photo_url_ok('javascript:alert(1)'), false, 'a javascript: URL is refused');
t_eq(mam_upload_photo_url_ok('data:image/png;base64,AAAA'), false, 'a data: URL is refused');
t_eq(mam_upload_photo_url_ok('/api/media/headshot?k=../../etc/passwd'), false, 'a traversal key is refused');
t_eq(mam_upload_photo_url_ok('/etc/passwd'), false, 'an arbitrary same-origin path is refused');
t_eq(mam_upload_photo_url_ok(''), false, 'an empty URL is refused');
t_eq(mam_upload_photo_url_ok('https://x.example.com/' . str_repeat('a', 500)), false,
    'an over-long URL is refused before it can be truncated into the column');

/* ── …and that a stored photo can actually be put on a card ──────────────── */
// This is the regression the admin hit: upload worked, then Save said the URL was
// invalid, so there was no way to fix a card photo without an external image host.
$pcUser = mam_db_insert('users', [
    'name' => 'Photo Card', 'email' => 'photo.card@example.com',
    'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'role' => 'student', 'status' => 'active',
]);
$pcCard = mam_card_mint($pcUser, null, 'student', '');
$set = mam_card_set_photo((int)$pcCard['id'], $url, true);
t_eq($set['photo_url'], $url, 'a first-party photo can be set on a card');
t_eq((string)mam_db_row('SELECT avatar_url FROM users WHERE id = ?', [$pcUser])['avatar_url'], $url,
    'and mirrored to the holder’s portal avatar');
try { mam_card_set_photo((int)$pcCard['id'], 'javascript:alert(1)', false); t_ok(false, 'a javascript: photo is refused'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'invalid_photo_url', 'a javascript: photo is refused'); }
