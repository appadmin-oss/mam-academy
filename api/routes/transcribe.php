<?php
/**
 * Practice Studio — Transcribe (Klangio proxy)
 *
 *   POST /api/transcribe        Body JSON: { audio_base64, mime?, model?, filename? }
 *                               → { job_id, status }   (submits the clip)
 *   GET  /api/transcribe?job=ID                → { status, done|failed, notes?, formats }
 *   GET  /api/transcribe?job=ID&download=midi  → streams the artifact (midi|musicxml|csv)
 *
 * Auth + CSRF (POST) + rate limiting are applied by the router. The audio is
 * uploaded as base64 JSON so it rides the existing CSRF/session pipeline; we
 * decode to a temp file and hand it to Klangio server-side. Every Klangio call
 * is wrapped so a provider hiccup becomes a clean JSON error, never a 500.
 */
declare(strict_types=1);

$u = mam_require_user();
// (Ported 2026-11 from the June session branch claude/upbeat-fermat-cW7ay.)

if (!mam_klangio_configured()) {
    mam_fail('transcribe_unconfigured', 503, ['message' => 'Transcription isn\'t enabled on this server yet.']);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

/* ─── Submit ─── */
if ($method === 'POST') {
    $in = mam_json_input();
    $b64 = (string)($in['audio_base64'] ?? '');
    // Strip a possible data: URL prefix (data:audio/webm;base64,XXXX).
    if (($comma = strpos($b64, ',')) !== false && stripos(substr($b64, 0, $comma), 'base64') !== false) {
        $b64 = substr($b64, $comma + 1);
    }
    $b64 = preg_replace('/\s+/', '', $b64);
    if ($b64 === '' || !preg_match('#^[A-Za-z0-9+/=]+$#', $b64)) mam_fail('no_audio', 400, ['message' => 'No audio received.']);

    $bytes = base64_decode($b64, true);
    if ($bytes === false || strlen($bytes) < 1024) mam_fail('bad_audio', 400, ['message' => 'The audio clip was empty or invalid.']);
    if (strlen($bytes) > 8 * 1024 * 1024) mam_fail('audio_too_large', 413, ['message' => 'Clip is too large (keep it under ~30 seconds / 8 MB).']);

    $mime = strtolower(trim((string)($in['mime'] ?? 'audio/webm')));
    if (!preg_match('#^audio/[a-z0-9.+-]+$#', $mime)) $mime = 'audio/webm';
    $model = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($in['model'] ?? '')));
    if ($model === '') $model = null;

    $ext = ['audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/flac' => 'flac'][$mime] ?? 'webm';
    $tmp = tempnam(sys_get_temp_dir(), 'mamklg_');
    if ($tmp === false || @file_put_contents($tmp, $bytes) === false) {
        if ($tmp) @unlink($tmp);
        mam_fail('temp_failed', 500, ['message' => 'Could not stage the audio for upload.']);
    }

    try {
        $res = mam_klangio_submit($tmp, 'recording.' . $ext, $mime, $model);
    } catch (Throwable $e) {
        @unlink($tmp);
        mam_log('error', 'klangio_submit_failed', ['err' => $e->getMessage(), 'user' => (int)$u['id']]);
        mam_fail('transcribe_failed', 502, ['message' => 'Could not reach the transcription service. Please try again.']);
    }
    @unlink($tmp);

    $jobId = (string)($res['job_id'] ?? $res['id'] ?? $res['jobId'] ?? $res['uuid'] ?? '');
    if ($jobId === '') {
        mam_log('error', 'klangio_no_job', ['res' => array_slice($res, 0, 5)]);
        mam_fail('transcribe_failed', 502, ['message' => 'The transcription service returned an unexpected response.']);
    }
    mam_log('info', 'klangio_submitted', ['job' => $jobId, 'user' => (int)$u['id'], 'model' => $model ?: 'default', 'bytes' => strlen($bytes)]);
    mam_ok(['job_id' => $jobId, 'status' => (string)($res['status'] ?? 'PENDING')]);
}

/* ─── Status / download (GET) ─── */
$job = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string)($_GET['job'] ?? ''));
if ($job === '') mam_fail('missing_job', 400);

$download = preg_replace('/[^a-z0-9]/', '', strtolower((string)($_GET['download'] ?? '')));
if ($download !== '') {
    $types = ['midi' => 'audio/midi', 'mid' => 'audio/midi', 'musicxml' => 'application/vnd.recordare.musicxml+xml', 'xml' => 'application/xml', 'csv' => 'text/csv', 'pdf' => 'application/pdf', 'gp5' => 'application/octet-stream'];
    if (!isset($types[$download])) mam_fail('bad_format', 400);
    try {
        $r = mam_klangio_result($job, $download);
    } catch (Throwable $e) {
        mam_fail('result_failed', 502, ['message' => 'Could not fetch the file.']);
    }
    if ($r['code'] >= 300 || $r['body'] === '') mam_fail('result_unavailable', 404, ['message' => 'That file isn\'t ready.']);

    // Stream the binary, bypassing the JSON envelope. Mark the response as sent
    // so bootstrap's shutdown net doesn't append a JSON body to the file.
    if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
    while (function_exists('ob_get_level') && ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) {
        header_remove('Content-Type');
        header('Content-Type: ' . $types[$download]);
        header('Content-Disposition: attachment; filename="mam-transcription.' . ($download === 'mid' ? 'mid' : $download) . '"');
        header('Content-Length: ' . strlen($r['body']));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo $r['body'];
    exit;
}

try {
    $job_data = mam_klangio_job($job);
} catch (Throwable $e) {
    mam_fail('status_failed', 502, ['message' => 'Could not check the transcription status.']);
}
$phase = mam_klangio_phase((string)($job_data['status'] ?? 'working'));
$out = ['job_id' => $job, 'status' => (string)($job_data['status'] ?? 'working'), 'phase' => $phase];

if ($phase === 'done') {
    $out['done'] = true;
    $out['formats'] = ['midi', 'musicxml', 'csv'];
    // Pull the CSV note events for an in-page preview (best-effort).
    try {
        $r = mam_klangio_result($job, 'csv');
        if ($r['code'] < 300 && $r['body'] !== '') $out['notes'] = mam_klangio_parse_csv($r['body']);
    } catch (Throwable $e) { /* preview is optional */ }
} elseif ($phase === 'failed') {
    $out['failed'] = true;
    $out['message'] = (string)($job_data['message'] ?? $job_data['error'] ?? 'Transcription failed. Try a cleaner, mono recording.');
}

mam_ok($out);
