<?php
/**
 * Stored files: a PRIVATE S3-compatible bucket (Cloudflare R2 by default), or
 * this host's own disk when no bucket is set (schema v99, `media_objects`).
 *
 * Ported from NGG's object-store.php, with one deliberate difference: NGG's
 * bucket is PUBLIC and a file is read at a plain URL. Here most files are
 * videos of children playing, so the bucket is private and every read goes
 * through `GET /api/media/object?id=N`, which checks who is asking and only then
 * redirects to a link that lasts MAM_BUCKET_GET_TTL seconds. A link that leaks
 * (a forwarded email, a screenshot of the address bar) is dead in ten minutes.
 *
 * An upload never passes through PHP when a bucket is set: the browser asks for
 * a presigned PUT (one key, one content type, one exact byte length, fifteen
 * minutes), sends the file straight to the bucket, then says it is done and the
 * server checks the stored size with a HEAD before the file counts. That is
 * what lets a ten-minute recital video through a host whose post_max_size is
 * 16 MB. The browser compresses first (assets/js/media-upload.js), so what
 * arrives is a 720p H.264 file a fifth of the phone's original.
 *
 * Without a bucket the same three steps run against this host: the PUT goes to
 * `/api/media/put` with a signed token, capped at MAM_MEDIA_LOCAL_MAX, and the
 * file lands outside the web root. It is the floor, not the design — an
 * academy with more than a few classes posting video should set a bucket.
 *
 * Keys are chosen HERE (purpose, month, 24 random hex), never by a caller, and
 * the content type comes from an allow-list keyed by extension: nothing is ever
 * stored as HTML, SVG or script, so the bucket cannot host a page.
 */
declare(strict_types=1);

const MAM_BUCKET_PUT_TTL = 900;            // a presigned upload lasts 15 minutes
const MAM_BUCKET_GET_TTL = 600;            // a presigned read lasts 10 minutes
const MAM_MEDIA_LOCAL_MAX = 64 * 1024 * 1024;   // this host's own ceiling when there is no bucket
const MAM_MEDIA_PENDING_HOURS = 24;        // an upload never finished is swept after this

/** Extension → stored content type, per media kind. The type is OURS, never the browser's. */
const MAM_MEDIA_TYPES = [
    'video' => ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm'],
    'audio' => ['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav',
                'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'weba' => 'audio/webm', 'opus' => 'audio/ogg'],
    'image' => ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'],
    'pdf'   => ['pdf' => 'application/pdf'],
    'doc'   => ['docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'txt' => 'text/plain', 'mid' => 'audio/midi', 'midi' => 'audio/midi', 'mxl' => 'application/vnd.recordare.musicxml',
                'musicxml' => 'application/vnd.recordare.musicxml+xml'],
];

/**
 * What each purpose may store, how large, and how it is read. Sizes are AFTER
 * the browser's compression: a 720p video at about 2.3 Mbps is ~17 MB a
 * minute, so 300 MB is a fifteen-minute performance with room to spare.
 */
function mam_media_purposes(): array {
    $mb = 1024 * 1024;
    return [
        'community' => ['kinds' => ['video', 'audio', 'image', 'pdf'], 'max' => ['video' => 300 * $mb, 'audio' => 60 * $mb, 'image' => 12 * $mb, 'pdf' => 25 * $mb]],
        'take'      => ['kinds' => ['video', 'audio'],                 'max' => ['video' => 300 * $mb, 'audio' => 60 * $mb]],
        'material'  => ['kinds' => ['pdf', 'image', 'video', 'audio', 'doc'], 'max' => ['video' => 300 * $mb, 'audio' => 60 * $mb, 'image' => 12 * $mb, 'pdf' => 40 * $mb, 'doc' => 25 * $mb]],
        'evidence'  => ['kinds' => ['video', 'audio', 'image', 'pdf'], 'max' => ['video' => 300 * $mb, 'audio' => 60 * $mb, 'image' => 12 * $mb, 'pdf' => 25 * $mb]],
    ];
}

/* ─── The bucket: config ────────────────────────────────────────────────── */

/** Bucket settings: the console's saved value first, the environment second. */
function mam_bucket_cfg(): array {
    $get = static fn(string $k, string $env, string $def = ''): string =>
        trim((string) mam_setting($k, (string) mam_env($env, $def)));
    return [
        'endpoint'   => rtrim($get('storage_endpoint', 'STORAGE_ENDPOINT'), '/'),
        'region'     => $get('storage_region', 'STORAGE_REGION', 'auto') ?: 'auto',
        'bucket'     => trim($get('storage_bucket', 'STORAGE_BUCKET'), ' /'),
        'access_key' => $get('storage_access_key', 'STORAGE_ACCESS_KEY'),
        'secret_key' => $get('storage_secret_key', 'STORAGE_SECRET_KEY'),
        'folder'     => (static function (string $f): string { return preg_match('#^[A-Za-z0-9_-]{1,40}$#', $f) ? $f : 'mam'; })
                        ($get('storage_folder', 'STORAGE_FOLDER', 'mam')),
    ];
}

/** Every field set and the endpoint is https. */
function mam_bucket_configured(): bool {
    if (isset($GLOBALS['__mam_bucket_cfg_mock'])) return true;
    $c = mam_bucket_cfg();
    foreach (['endpoint', 'bucket', 'access_key', 'secret_key'] as $k) if ($c[$k] === '') return false;
    return (bool) preg_match('#^https://[^/\s]+#i', $c['endpoint']);
}

/** The test seam replaces the whole config; production reads settings. */
function mam_bucket_cfg_live(): array {
    return isset($GLOBALS['__mam_bucket_cfg_mock']) && is_array($GLOBALS['__mam_bucket_cfg_mock'])
        ? $GLOBALS['__mam_bucket_cfg_mock'] + ['region' => 'auto', 'folder' => 'mam'] : mam_bucket_cfg();
}

/* ─── SigV4, pure (AWS's own published examples are the tests) ──────────── */

/** RFC 3986 encoding as SigV4 wants it; '/' kept in a path. */
function mam_bucket_uri_encode(string $s, bool $keepSlash = false): string {
    $e = rawurlencode($s);
    return $keepSlash ? str_replace('%2F', '/', $e) : $e;
}

function mam_bucket_signing_key(string $secret, string $date, string $region, string $service = 's3'): string {
    $k = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
    $k = hash_hmac('sha256', $region, $k, true);
    $k = hash_hmac('sha256', $service, $k, true);
    return hash_hmac('sha256', 'aws4_request', $k, true);
}

/**
 * A presigned URL (query auth). Signed headers must then be sent exactly —
 * that is how an upload is held to one content type and one byte length.
 * $query carries extra signed parameters (a download's response-content-*).
 */
function mam_bucket_presign(string $method, string $url, array $headers, int $expires, string $accessKey, string $secret,
                            string $region, int $now, array $query = []): string {
    $p = parse_url($url);
    $host = strtolower((string) $p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
    $path = (string) ($p['path'] ?? '/');
    $amzDate = gmdate('Ymd\THis\Z', $now); $date = substr($amzDate, 0, 8);
    $scope = $date . '/' . $region . '/s3/aws4_request';
    $hdrs = ['host' => $host];
    foreach ($headers as $k => $v) $hdrs[strtolower((string) $k)] = trim((string) $v);
    ksort($hdrs);
    $signed = implode(';', array_keys($hdrs));
    $q = ['X-Amz-Algorithm' => 'AWS4-HMAC-SHA256', 'X-Amz-Credential' => $accessKey . '/' . $scope, 'X-Amz-Date' => $amzDate,
          'X-Amz-Expires' => (string) $expires, 'X-Amz-SignedHeaders' => $signed] + $query;
    ksort($q, SORT_STRING);
    $qs = implode('&', array_map(static fn($k, $v) => mam_bucket_uri_encode((string) $k) . '=' . mam_bucket_uri_encode((string) $v), array_keys($q), $q));
    $canon = $method . "\n" . $path . "\n" . $qs . "\n"
        . implode('', array_map(static fn($k, $v) => $k . ':' . $v . "\n", array_keys($hdrs), $hdrs)) . "\n" . $signed . "\nUNSIGNED-PAYLOAD";
    $sts = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canon);
    $sig = hash_hmac('sha256', $sts, mam_bucket_signing_key($secret, $date, $region), true);
    return $p['scheme'] . '://' . $host . $path . '?' . $qs . '&X-Amz-Signature=' . bin2hex($sig);
}

/** Header auth for one server-side request: the headers to send, Authorization last. */
function mam_bucket_auth_headers(string $method, string $url, string $body, array $extra, string $accessKey, string $secret,
                                 string $region, int $now): array {
    $p = parse_url($url);
    $host = strtolower((string) $p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
    $amzDate = gmdate('Ymd\THis\Z', $now); $date = substr($amzDate, 0, 8);
    $hash = hash('sha256', $body);
    $hdrs = ['host' => $host, 'x-amz-content-sha256' => $hash, 'x-amz-date' => $amzDate];
    foreach ($extra as $k => $v) $hdrs[strtolower((string) $k)] = trim((string) $v);
    ksort($hdrs);
    $signed = implode(';', array_keys($hdrs));
    $canon = $method . "\n" . (string) ($p['path'] ?? '/') . "\n" . (string) ($p['query'] ?? '') . "\n"
        . implode('', array_map(static fn($k, $v) => $k . ':' . $v . "\n", array_keys($hdrs), $hdrs)) . "\n" . $signed . "\n" . $hash;
    $scope = $date . '/' . $region . '/s3/aws4_request';
    $sts = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canon);
    $sig = bin2hex(hash_hmac('sha256', $sts, mam_bucket_signing_key($secret, $date, $region), true));
    $send = [];
    foreach ($hdrs as $k => $v) if ($k !== 'host') $send[] = $k . ': ' . $v;
    $send[] = 'Authorization: AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $sig;
    return $send;
}

/** Path-style URL of one object: endpoint/bucket/key — every S3-compatible store accepts it. */
function mam_bucket_object_url(string $key): string {
    $c = mam_bucket_cfg_live();
    return $c['endpoint'] . '/' . mam_bucket_uri_encode($c['bucket']) . '/' . mam_bucket_uri_encode($key, true);
}

/**
 * One server-side request with header auth. Returns
 * ['code' => int, 'headers' => [lower => value], 'body' => string, 'error' => string].
 * Test seam: $GLOBALS['__mam_bucket_http_mock'].
 */
function mam_bucket_http(string $method, string $key, string $body = '', array $extra = []): array {
    if (isset($GLOBALS['__mam_bucket_http_mock']) && is_callable($GLOBALS['__mam_bucket_http_mock'])) {
        return ($GLOBALS['__mam_bucket_http_mock'])($method, $key, $body, $extra) + ['headers' => [], 'body' => '', 'error' => ''];
    }
    if (!mam_bucket_configured() || !function_exists('curl_init')) return ['code' => 0, 'headers' => [], 'body' => '', 'error' => 'not_configured'];
    $c = mam_bucket_cfg_live();
    $url = mam_bucket_object_url($key);
    $send = mam_bucket_auth_headers($method, $url, $body, $extra, $c['access_key'], $c['secret_key'], $c['region'], time());
    $got = [];
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $send, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$got): int {
                $i = strpos($line, ':');
                if ($i !== false) $got[strtolower(trim(substr($line, 0, $i)))] = trim(substr($line, $i + 1));
                return strlen($line);
            }];
    if ($method === 'HEAD') $opt[CURLOPT_NOBODY] = true;
    if ($body !== '') $opt[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($ch, $opt);
    $out = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return ['code' => $code, 'headers' => $got, 'body' => is_string($out) ? $out : '', 'error' => $err];
}

/**
 * The health check: write a tiny file, read it back WITH a presigned link the
 * way a viewer would, delete it. Each step names its own failure, because
 * "storage failed" tells nobody what to fix.
 */
function mam_bucket_health(): array {
    if (!mam_bucket_configured()) return ['pass' => false, 'detail' => 'No bucket is set. Files are kept on this host, up to '
        . (int) (MAM_MEDIA_LOCAL_MAX / 1048576) . ' MB each.'];
    $key = mam_bucket_cfg_live()['folder'] . '/_health/' . bin2hex(random_bytes(6)) . '.txt';
    $put = mam_bucket_http('PUT', $key, 'mam storage check', ['content-type' => 'text/plain']);
    $code = (int) $put['code'];
    if ($code < 200 || $code >= 300) {
        $why = $code === 403 ? 'the keys were refused — check both keys and that they may write to this bucket'
            : ($code === 404 ? 'there is no bucket of that name at this endpoint'
            : ($code === 0 ? 'the endpoint did not answer' . ($put['error'] ? ' (' . $put['error'] . ')' : '') : 'HTTP ' . $code));
        return ['pass' => false, 'detail' => 'Could not write a test file: ' . $why . '.'];
    }
    $head = mam_bucket_http('HEAD', $key);
    $del = mam_bucket_http('DELETE', $key);
    if ((int) $head['code'] !== 200) return ['pass' => false, 'detail' => 'A test file was written but could not be read back (HTTP ' . (int) $head['code'] . ').'];
    return ['pass' => true, 'detail' => 'Wrote a test file, read it back and '
        . (in_array((int) $del['code'], [200, 204], true) ? 'deleted it.' : 'could not delete it — the key may lack delete permission.')
        . ' Remember the bucket needs a CORS rule allowing PUT from this site.'];
}

/** The CORS rule the bucket needs, for the console to show (R2 / S3 JSON form). */
function mam_bucket_cors_rule(): string {
    $origin = rtrim((string) mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');
    return json_encode([[ 'AllowedOrigins' => [$origin], 'AllowedMethods' => ['PUT', 'GET', 'HEAD'],
        'AllowedHeaders' => ['content-type'], 'ExposeHeaders' => ['etag'], 'MaxAgeSeconds' => 3600 ]],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/* ─── Keys and types ─────────────────────────────────────────────────────── */

/** The media kind and extension a file is stored as, or null when this purpose does not take it. */
function mam_media_classify(string $purpose, string $name, string $type): ?array {
    $p = mam_media_purposes()[$purpose] ?? null;
    if (!$p) return null;
    $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    $type = strtolower(trim(explode(';', $type)[0]));
    foreach ($p['kinds'] as $kind) {
        $types = MAM_MEDIA_TYPES[$kind] ?? [];
        if ($ext !== '' && isset($types[$ext])) return ['kind' => $kind, 'ext' => $ext, 'type' => $types[$ext]];
    }
    foreach ($p['kinds'] as $kind) {
        $hit = array_search($type, MAM_MEDIA_TYPES[$kind] ?? [], true);
        if ($hit !== false) return ['kind' => $kind, 'ext' => (string) $hit, 'type' => MAM_MEDIA_TYPES[$kind][$hit]];
    }
    return null;
}

/** A new key: folder/purpose/YYYY/MM/<24 hex>.ext — chosen here, never by a caller. */
function mam_media_new_key(string $purpose, string $ext): string {
    return mam_bucket_cfg_live()['folder'] . '/' . $purpose . '/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
}

/** Is this exactly our key grammar? Never build a path or URL from a key that is not. */
function mam_media_key_ok(string $key): bool {
    return (bool) preg_match('#^[A-Za-z0-9_-]{1,40}/[a-z]{3,20}/[0-9]{4}/[0-9]{2}/[0-9a-f]{24}\.[a-z0-9]{2,8}$#', $key);
}

/** Where a key lives on this host when there is no bucket (outside the web root). */
function mam_media_local_path(string $key): ?string {
    if (!mam_media_key_ok($key)) return null;
    return MAM_STORAGE . '/uploads/media/' . $key;
}

/* ─── The lifecycle: sign → put → done → read ───────────────────────────── */

/**
 * Begin an upload. Returns ['id', 'upload' => ['url','method','headers'], 'max', 'provider'].
 * Throws InvalidArgumentException with a sentence for a file this purpose does not take.
 */
function mam_media_begin(int $ownerId, string $purpose, string $name, string $type, int $size, array $meta = []): array {
    $p = mam_media_purposes()[$purpose] ?? null;
    if (!$p) throw new InvalidArgumentException('That is not somewhere a file can be uploaded.');
    $c = mam_media_classify($purpose, $name, $type);
    if (!$c) throw new InvalidArgumentException('That kind of file cannot be uploaded here.');
    $bucket = mam_bucket_configured();
    $max = (int) ($p['max'][$c['kind']] ?? 0);
    if (!$bucket) $max = min($max, MAM_MEDIA_LOCAL_MAX);
    if ($size <= 0) throw new InvalidArgumentException('The file is empty.');
    if ($size > $max) throw new InvalidArgumentException('That file is larger than ' . (int) round($max / 1048576)
        . ' MB' . ($bucket ? '' : ' (the most this site can keep until a storage bucket is set)') . '.');
    $key = mam_media_new_key($purpose, $c['ext']);
    $id = (int) mam_db_insert('media_objects', [
        'object_key' => $key, 'owner_id' => $ownerId, 'purpose' => $purpose, 'kind' => $c['kind'],
        'content_type' => $c['type'], 'bytes' => $size, 'provider' => $bucket ? 'bucket' : 'local',
        'status' => 'pending', 'original_name' => mb_substr(basename($name), 0, 120),
        'meta' => $meta ? json_encode(array_slice($meta, 0, 12), JSON_UNESCAPED_SLASHES) : null,
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
    if ($bucket) {
        $cfg = mam_bucket_cfg_live();
        $url = mam_bucket_presign('PUT', mam_bucket_object_url($key), ['content-type' => $c['type'], 'content-length' => (string) $size],
            MAM_BUCKET_PUT_TTL, $cfg['access_key'], $cfg['secret_key'], $cfg['region'], time());
    } else {
        $url = '/api/media/put?id=' . $id . '&t=' . mam_media_put_token($id, $ownerId, time() + MAM_BUCKET_PUT_TTL);
    }
    return ['id' => $id, 'kind' => $c['kind'], 'provider' => $bucket ? 'bucket' : 'local', 'max' => $max,
            'upload' => ['url' => $url, 'method' => 'PUT', 'headers' => ['Content-Type' => $c['type']]]];
}

/** The signed token for a local PUT: id, owner and expiry, HMAC'd with the app key. */
function mam_media_put_token(int $id, int $ownerId, int $exp): string {
    return $exp . '.' . substr(hash_hmac('sha256', 'mput|' . $id . '|' . $ownerId . '|' . $exp, mam_app_key()), 0, 40);
}

function mam_media_put_token_ok(string $t, int $id, int $ownerId): bool {
    if (!preg_match('/^(\d{9,11})\.([0-9a-f]{40})$/', $t, $m)) return false;
    if ((int) $m[1] < time()) return false;
    return hash_equals(mam_media_put_token($id, $ownerId, (int) $m[1]), $t);
}

/**
 * Receive a local PUT body. Streams php://input to disk in 1 MB chunks and
 * refuses past the declared size, so a liar cannot fill the disk.
 */
function mam_media_local_receive(int $id, int $ownerId, $stream): void {
    $o = mam_db_row("SELECT * FROM media_objects WHERE id = ? AND owner_id = ? AND provider = 'local' AND status = 'pending'", [$id, $ownerId]);
    if (!$o) throw new RuntimeException('not_found');
    $path = mam_media_local_path((string) $o['object_key']);
    if ($path === null) throw new RuntimeException('not_found');
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
    $tmp = $path . '.part';
    $out = fopen($tmp, 'wb');
    if (!$out) throw new RuntimeException('store_failed');
    $limit = (int) $o['bytes']; $n = 0;
    while (!feof($stream)) {
        $chunk = fread($stream, 1048576);
        if ($chunk === false) break;
        $n += strlen($chunk);
        if ($n > $limit) { fclose($out); @unlink($tmp); throw new RuntimeException('too_large'); }
        fwrite($out, $chunk);
    }
    fclose($out);
    if ($n !== $limit) { @unlink($tmp); throw new RuntimeException('size_mismatch'); }
    rename($tmp, $path);
}

/**
 * The upload says it is done: check the stored size against what was signed
 * and only then mark it uploaded. A file that is not there, or not the size
 * we signed for, never becomes a submission.
 */
function mam_media_finish(int $id, int $ownerId, array $meta = []): array {
    $o = mam_db_row("SELECT * FROM media_objects WHERE id = ? AND owner_id = ?", [$id, $ownerId]);
    if (!$o) throw new RuntimeException('not_found');
    if ($o['status'] === 'uploaded') return $o;
    if ($o['status'] !== 'pending') throw new RuntimeException('not_found');
    if ($o['provider'] === 'bucket') {
        $h = mam_bucket_http('HEAD', (string) $o['object_key']);
        if ((int) $h['code'] !== 200) throw new RuntimeException('not_uploaded');
        if (isset($h['headers']['content-length']) && (int) $h['headers']['content-length'] !== (int) $o['bytes']) throw new RuntimeException('size_mismatch');
    } else {
        $path = mam_media_local_path((string) $o['object_key']);
        if ($path === null || !is_file($path)) throw new RuntimeException('not_uploaded');
        if ((int) filesize($path) !== (int) $o['bytes']) throw new RuntimeException('size_mismatch');
        if ($o['kind'] === 'image' && function_exists('getimagesize') && @getimagesize($path) === false) {
            @unlink($path); throw new RuntimeException('not_an_image');
        }
    }
    $m = json_decode((string) ($o['meta'] ?? ''), true) ?: [];
    foreach (['width', 'height', 'duration', 'from_bytes', 'compressed'] as $k) {
        if (isset($meta[$k]) && is_scalar($meta[$k])) $m[$k] = is_numeric($meta[$k]) ? $meta[$k] + 0 : (bool) $meta[$k];
    }
    mam_db_update('media_objects', ['status' => 'uploaded', 'uploaded_at' => gmdate('Y-m-d H:i:s'),
        'meta' => $m ? json_encode($m, JSON_UNESCAPED_SLASHES) : null], ['id' => $id]);
    return mam_db_row("SELECT * FROM media_objects WHERE id = ?", [$id]) ?: $o;
}

/** Attach an uploaded file to what it belongs to — this decides who may read it. */
function mam_media_attach(int $id, int $ownerId, string $scopeType, int $scopeId): void {
    if (!in_array($scopeType, ['space', 'student', 'material'], true)) throw new InvalidArgumentException('bad scope');
    $n = mam_db_update('media_objects', ['scope_type' => $scopeType, 'scope_id' => $scopeId],
        ['id' => $id, 'owner_id' => $ownerId, 'status' => 'uploaded']);
    if (!$n) throw new RuntimeException('file_not_ready');
}

/**
 * May this person read this file? The owner, an admin, and then whatever the
 * file is attached to decides: a class space's own rule for a space file, the
 * student's teachers and guardians for a student's file. An unattached file is
 * its owner's and an admin's only.
 */
function mam_media_can_read(array $obj, ?array $user): bool {
    if (!$user) return false;
    $uid = (int) $user['id'];
    if ((int) $obj['owner_id'] === $uid) return true;
    if (mam_user_has_role($user, 'admin')) return true;
    $scope = (string) ($obj['scope_type'] ?? ''); $sid = (int) ($obj['scope_id'] ?? 0);
    if ($scope === 'space' && function_exists('mam_space_post_can_view_media')) return mam_space_post_can_view_media((int) $obj['id'], $user);
    if ($scope === 'student') return mam_media_student_reader($sid, $user);
    if ($scope === 'material') return mam_user_has_role($user, 'instructor');
    return false;
}

/** The student themself, a teacher of theirs (either enrolment model), or a linked guardian. */
function mam_media_student_reader(int $studentId, array $user): bool {
    $uid = (int) $user['id'];
    if ($uid === $studentId) return true;
    if (mam_user_has_role($user, 'instructor')) {
        if (mam_db_row('SELECT 1 FROM enrollments e JOIN classes c ON c.id = e.class_id WHERE e.student_id = ? AND c.instructor_id = ?', [$studentId, $uid])) return true;
        if (mam_db_row('SELECT 1 FROM course_enrollments ce LEFT JOIN cohorts co ON co.id = ce.cohort_id
                         WHERE ce.student_id = ? AND (co.instructor_id = ? OR ce.instructor_id = ?)', [$studentId, $uid, $uid])) return true;
    }
    return function_exists('mam_parent_can_view') && mam_parent_can_view($uid, $studentId);
}

/** Where a reader is sent: a ten-minute presigned link (bucket), or null to stream from this host. */
function mam_media_read_url(array $obj, bool $download = false): ?string {
    if ($obj['provider'] !== 'bucket') return null;
    $c = mam_bucket_cfg_live();
    $q = ['response-content-type' => (string) $obj['content_type'],
          'response-cache-control' => 'private, max-age=' . MAM_BUCKET_GET_TTL];
    if ($download) $q['response-content-disposition'] = 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($obj['original_name'] ?: 'file')) . '"';
    return mam_bucket_presign('GET', mam_bucket_object_url((string) $obj['object_key']), [], MAM_BUCKET_GET_TTL,
        $c['access_key'], $c['secret_key'], $c['region'], time(), $q);
}

/**
 * Stream a local file, honouring a single Range. Safari will not play a
 * video from a server that ignores Range, and every phone seeks with it.
 */
function mam_media_stream_local(array $obj): void {
    $path = mam_media_local_path((string) $obj['object_key']);
    if ($path === null || !is_file($path)) { http_response_code(404); return; }
    $size = (int) filesize($path); $start = 0; $end = $size - 1;
    header_remove('Content-Type');
    header('Content-Type: ' . $obj['content_type']);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m)) {
        if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int) $m[2]); }
        else { $start = (int) $m[1]; if ($m[2] !== '') $end = min($end, (int) $m[2]); }
        if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); return; }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    $len = $end - $start + 1;
    header('Content-Length: ' . $len);
    $fh = fopen($path, 'rb'); fseek($fh, $start);
    while ($len > 0 && !feof($fh)) { $chunk = fread($fh, (int) min(1048576, $len)); echo $chunk; $len -= strlen((string) $chunk); }
    fclose($fh);
}

/** Delete a stored file. The row stays as a record that it existed; the bytes go. */
function mam_media_delete(int $id): bool {
    $o = mam_db_row("SELECT * FROM media_objects WHERE id = ?", [$id]);
    if (!$o || $o['status'] === 'deleted') return false;
    if ($o['provider'] === 'bucket') {
        $r = mam_bucket_http('DELETE', (string) $o['object_key']);
        if (!in_array((int) $r['code'], [200, 204, 404], true)) return false;
    } elseif (($p = mam_media_local_path((string) $o['object_key'])) !== null) {
        @unlink($p);
    }
    mam_db_update('media_objects', ['status' => 'deleted', 'deleted_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    return true;
}

/** The cron sweep: uploads begun and never finished, and their half-written bytes. */
function mam_media_sweep_pending(): int {
    $cut = gmdate('Y-m-d H:i:s', time() - MAM_MEDIA_PENDING_HOURS * 3600);
    $n = 0;
    foreach (mam_db_all("SELECT id FROM media_objects WHERE status = 'pending' AND created_at < ? LIMIT 200", [$cut]) as $r) {
        if (mam_media_delete((int) $r['id'])) $n++;
    }
    return $n;
}

/** What a page needs to show one file: the gated address, kind, size, and the meta the uploader recorded. */
function mam_media_public_view(?array $o): ?array {
    if (!$o || $o['status'] !== 'uploaded') return null;
    $m = json_decode((string) ($o['meta'] ?? ''), true) ?: [];
    return ['id' => (int) $o['id'], 'kind' => (string) $o['kind'], 'type' => (string) $o['content_type'],
            'bytes' => (int) $o['bytes'], 'url' => '/api/media/object?id=' . (int) $o['id'],
            'duration' => isset($m['duration']) ? (float) $m['duration'] : null,
            'width' => isset($m['width']) ? (int) $m['width'] : null, 'height' => isset($m['height']) ? (int) $m['height'] : null];
}
