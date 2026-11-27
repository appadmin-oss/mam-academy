<?php
/**
 * Google Drive file gateway via a Google **Apps Script web app** (portal brief
 * #5) — NO OAuth / Drive API credentials required.
 *
 * The site never talks to Google directly. Instead an admin deploys a small
 * Apps Script web app (see docs/drive-apps-script.md) that runs as the account
 * owning a Drive folder; it exposes a single `doPost(e)` endpoint. This gateway
 * signs each request with a shared secret (HMAC over a timestamp + the body) so
 * the web app can reject forged or replayed calls, then:
 *   - `upload` → the script writes the bytes to the folder, sets link-sharing,
 *     and returns the file id + view/download URLs (so the file is FETCHABLE).
 *   - `fetch`  → the script returns the file's bytes (base64) so the site can
 *     stream a private file back through an access-gated proxy.
 *
 * Off by default (like the AI gateway): until `drive_webapp_url` +
 * `drive_shared_secret` are set, `mam_drive_enabled()` is false and nothing is
 * uploaded. A `drive_transport = mock` setting drives an in-process round-trip
 * store for dev + tests, so the upload→fetch flow is exercised without network.
 *
 * Security: the outbound target is the ADMIN-CONFIGURED web-app URL only (never
 * user-supplied), required https; requests are HMAC-signed + timestamped;
 * uploads are mime-allowlisted, size-capped, and filename-sanitised.
 */
declare(strict_types=1);

function mam_drive_webapp_url(): string { return trim((string)mam_setting('drive_webapp_url', (string)mam_env('DRIVE_WEBAPP_URL', ''))); }
function mam_drive_secret(): string { return (string)mam_setting('drive_shared_secret', (string)mam_env('DRIVE_SHARED_SECRET', '')); }
function mam_drive_folder(): string { return trim((string)mam_setting('drive_folder_id', (string)mam_env('DRIVE_FOLDER_ID', ''))); }
/** 'live' (default) hits the web app; 'mock' uses the in-process store. */
function mam_drive_mode(): string { $m = strtolower(trim((string)mam_setting('drive_transport', (string)mam_env('DRIVE_TRANSPORT', 'live')))); return $m === 'mock' ? 'mock' : 'live'; }

/** Configured (mock is always "enabled" for dev/tests). */
function mam_drive_enabled(): bool {
    if (mam_drive_mode() === 'mock') return true;
    return mam_drive_webapp_url() !== '' && mam_drive_secret() !== '';
}

/** Human-readable readiness for the admin diagnostics screen. */
function mam_drive_readiness(): array {
    if (mam_drive_mode() === 'mock') return ['ready' => true, 'issues' => [], 'mode' => 'mock'];
    $issues = [];
    if (mam_drive_webapp_url() === '') $issues[] = 'drive_webapp_url is not set — deploy the Apps Script web app (docs/drive-apps-script.md) and paste its /exec URL.';
    elseif (!preg_match('#^https://#i', mam_drive_webapp_url())) $issues[] = 'drive_webapp_url must be https.';
    if (mam_drive_secret() === '') $issues[] = 'drive_shared_secret is not set — use the same value in the Apps Script.';
    return ['ready' => empty($issues), 'issues' => $issues, 'mode' => 'live'];
}

function mam_drive_max_bytes(): int { return max(1, (int)mam_setting_num('drive_max_mb', 256)) * 1024 * 1024; }

/** Uploadable mime types: media + common documents. */
function mam_drive_mime_ok(string $mime): bool {
    $mime = strtolower(trim($mime));
    if ($mime === '') return false;
    // SVG (and XML that renders as SVG) is an active document — it can carry
    // <script> and would run on our origin when the proxy serves it inline.
    // Exclude it from the media prefixes below; raster/video/audio are inert.
    if (in_array($mime, ['image/svg+xml', 'image/svg'], true)) return false;
    foreach (['video/', 'audio/', 'image/'] as $p) if (strpos($mime, $p) === 0) return true;
    return in_array($mime, [
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
    ], true);
}

/** Strip path/control chars from a client filename; keep a safe, bounded name. */
function mam_drive_safe_name(string $name): string {
    $name = str_replace(["\0", "/", "\\"], '', $name);
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
    $name = trim($name);
    if ($name === '') $name = 'upload';
    return mb_substr($name, 0, 180);
}

/** HMAC-SHA256(ts . "\n" . action . "\n" . sha256(body)) — bound to the secret. */
function mam_drive_sign(int $ts, string $action, string $body): string {
    return hash_hmac('sha256', $ts . "\n" . $action . "\n" . hash('sha256', $body), mam_drive_secret());
}

/** POST a signed JSON envelope to the configured web app. @return array{ok,status,body} */
function mam_drive_http_post(array $envelope, int $timeout = 120): array {
    $url = mam_drive_webapp_url();
    if ($url === '' || !preg_match('#^https://#i', $url)) return ['ok' => false, 'status' => 'not_configured', 'body' => ''];
    $payload = json_encode($envelope);
    if ($payload === false) return ['ok' => false, 'status' => 'encode_failed', 'body' => ''];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true, // Apps Script /exec 302-redirects to googleusercontent
            CURLOPT_MAXREDIRS => 3,
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return ['ok' => false, 'status' => $err ?: 'curl_failed', 'body' => ''];
        return ['ok' => $status >= 200 && $status < 300, 'status' => (string)$status, 'body' => (string)$resp];
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json", 'content' => $payload, 'timeout' => $timeout, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return ['ok' => false, 'status' => 'stream_failed', 'body' => ''];
    return ['ok' => true, 'status' => '200', 'body' => (string)$resp];
}

/**
 * Upload bytes to Drive. Returns a normalized result:
 *   ['ok'=>bool,'file_id'=>?string,'url'=>?string,'download_url'=>?string,
 *    'name'=>string,'mime'=>string,'size'=>int,'error'=>?string]
 * Never throws — a transport/validation failure returns ok=false + a tag.
 */
function mam_drive_upload(string $name, string $mime, string $bytes, array $opts = []): array {
    $out = ['ok' => false, 'file_id' => null, 'url' => null, 'download_url' => null, 'name' => $name, 'mime' => $mime, 'size' => strlen($bytes), 'error' => null];
    if (!mam_drive_enabled()) { $out['error'] = 'disabled'; return $out; }
    if ($bytes === '') { $out['error'] = 'empty_file'; return $out; }
    if (strlen($bytes) > mam_drive_max_bytes()) { $out['error'] = 'too_large'; return $out; }
    if (!mam_drive_mime_ok($mime)) { $out['error'] = 'mime_not_allowed'; return $out; }

    $name = mam_drive_safe_name($name);
    $out['name'] = $name;
    $ts = time();
    $data = base64_encode($bytes);
    $env = [
        'action' => 'upload', 'ts' => $ts, 'name' => $name, 'mime' => $mime,
        'folder' => mam_drive_folder(), 'data' => $data,
        'sig' => mam_drive_sign($ts, 'upload', $data),
    ];

    if (mam_drive_mode() === 'mock') { $r = mam_drive_mock_dispatch('upload', $env, $bytes); }
    else {
        $post = mam_drive_http_post($env);
        if (!$post['ok']) { $out['error'] = 'transport:' . $post['status']; return $out; }
        $r = json_decode($post['body'], true);
        if (!is_array($r)) { $out['error'] = 'bad_response'; return $out; }
    }
    if (empty($r['ok']) || empty($r['file_id'])) { $out['error'] = (string)($r['error'] ?? 'upload_failed'); return $out; }

    $out['ok'] = true;
    $out['file_id'] = (string)$r['file_id'];
    $out['url'] = (string)($r['view_url'] ?? $r['url'] ?? ('https://drive.google.com/file/d/' . $r['file_id'] . '/view'));
    $out['download_url'] = (string)($r['download_url'] ?? ('https://drive.google.com/uc?export=download&id=' . $r['file_id']));
    if (function_exists('mam_audit')) mam_audit('drive_upload', 'drive_file', 0, ['file_id' => $out['file_id'], 'mime' => $mime, 'size' => $out['size']]);
    return $out;
}

/**
 * Fetch a file's bytes back from Drive (for an access-gated proxy stream).
 *   ['ok'=>bool,'name'=>?string,'mime'=>?string,'data'=>?string(raw bytes),'size'=>int,'error'=>?string]
 */
function mam_drive_fetch(string $fileId): array {
    $out = ['ok' => false, 'name' => null, 'mime' => null, 'data' => null, 'size' => 0, 'error' => null];
    $fileId = trim($fileId);
    if ($fileId === '' || !preg_match('/^[A-Za-z0-9_-]{6,128}$/', $fileId)) { $out['error'] = 'bad_file_id'; return $out; }
    if (!mam_drive_enabled()) { $out['error'] = 'disabled'; return $out; }
    $ts = time();
    $env = ['action' => 'fetch', 'ts' => $ts, 'file_id' => $fileId, 'sig' => mam_drive_sign($ts, 'fetch', $fileId)];

    if (mam_drive_mode() === 'mock') { $r = mam_drive_mock_dispatch('fetch', $env, ''); }
    else {
        $post = mam_drive_http_post($env, 90);
        if (!$post['ok']) { $out['error'] = 'transport:' . $post['status']; return $out; }
        $r = json_decode($post['body'], true);
        if (!is_array($r)) { $out['error'] = 'bad_response'; return $out; }
    }
    if (empty($r['ok']) || !isset($r['data'])) { $out['error'] = (string)($r['error'] ?? 'fetch_failed'); return $out; }
    $bytes = base64_decode((string)$r['data'], true);
    if ($bytes === false) { $out['error'] = 'decode_failed'; return $out; }
    $out['ok'] = true; $out['data'] = $bytes; $out['size'] = strlen($bytes);
    $out['name'] = isset($r['name']) ? (string)$r['name'] : null;
    $out['mime'] = isset($r['mime']) ? (string)$r['mime'] : 'application/octet-stream';
    return $out;
}

/** Extract a Drive file id from a stored view/download URL (or a bare id). */
function mam_drive_id_from_url(string $url): ?string {
    $url = trim($url);
    if (preg_match('#/file/d/([A-Za-z0-9_-]{6,128})#', $url, $m)) return $m[1];
    if (preg_match('#[?&]id=([A-Za-z0-9_-]{6,128})#', $url, $m)) return $m[1];
    if (preg_match('/^[A-Za-z0-9_-]{6,128}$/', $url)) return $url;
    return null;
}

/**
 * In-process mock transport — a deterministic Drive stand-in for dev + tests.
 * `upload` stores the bytes under a content-derived id; `fetch` returns them,
 * so the full round-trip (upload → store URL → fetch bytes) is exercised with
 * no network. Verifies the signature exactly as the real web app would.
 */
function mam_drive_mock_dispatch(string $action, array $env, string $rawBytes): array {
    // Signature check mirrors the server so tests catch signing regressions.
    $body = $action === 'upload' ? (string)$env['data'] : (string)$env['file_id'];
    if (!hash_equals(mam_drive_sign((int)$env['ts'], $action, $body), (string)($env['sig'] ?? ''))) {
        return ['ok' => false, 'error' => 'bad_signature'];
    }
    if (!isset($GLOBALS['__mam_drive_mock']) || !is_array($GLOBALS['__mam_drive_mock'])) $GLOBALS['__mam_drive_mock'] = [];
    if ($action === 'upload') {
        $id = 'mock_' . substr(hash('sha256', $rawBytes . '|' . $env['name']), 0, 24);
        $GLOBALS['__mam_drive_mock'][$id] = ['name' => (string)$env['name'], 'mime' => (string)$env['mime'], 'data' => base64_encode($rawBytes)];
        return ['ok' => true, 'file_id' => $id, 'view_url' => 'https://drive.google.com/file/d/' . $id . '/view', 'download_url' => 'https://drive.google.com/uc?export=download&id=' . $id];
    }
    if ($action === 'fetch') {
        $id = (string)$env['file_id'];
        if (!isset($GLOBALS['__mam_drive_mock'][$id])) return ['ok' => false, 'error' => 'not_found'];
        return ['ok' => true] + $GLOBALS['__mam_drive_mock'][$id];
    }
    return ['ok' => false, 'error' => 'unknown_action'];
}
