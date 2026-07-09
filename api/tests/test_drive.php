<?php
/**
 * Google Drive gateway (#5) — Apps Script web-app upload/fetch, no OAuth.
 * Off by default; a `drive_transport=mock` in-process store exercises the full
 * upload→store→fetch round-trip and the request signing without network.
 */
declare(strict_types=1);
t_group('drive gateway (#5)');

// ── Off by default ──
mam_setting_set('drive_transport', '');
mam_setting_set('drive_webapp_url', '');
mam_setting_set('drive_shared_secret', '');
t_ok(!mam_drive_enabled(), 'the gateway is disabled until configured');
t_eq(mam_drive_upload('x.mp4', 'video/mp4', 'data')['error'], 'disabled', 'upload is inert while disabled');
t_ok(!mam_drive_readiness()['ready'], 'readiness reports not-ready while unconfigured');

// ── Turn on the mock transport (dev/tests) ──
mam_setting_set('drive_shared_secret', 'test-secret-123');
mam_setting_set('drive_transport', 'mock');
t_ok(mam_drive_enabled(), 'mock transport counts as enabled');
t_ok(mam_drive_readiness()['ready'] && mam_drive_readiness()['mode'] === 'mock', 'mock readiness is ready');

// ── Request signing: deterministic, ts-bound, secret-bound ──
$s1 = mam_drive_sign(1000, 'upload', 'BODY');
t_eq($s1, mam_drive_sign(1000, 'upload', 'BODY'), 'signing is deterministic for the same inputs');
t_ok($s1 !== mam_drive_sign(1001, 'upload', 'BODY'), 'a different timestamp changes the signature');
t_ok($s1 !== mam_drive_sign(1000, 'fetch', 'BODY'), 'a different action changes the signature');

// ── Validation guards ──
t_eq(mam_drive_upload('x.mp4', 'video/mp4', '')['error'], 'empty_file', 'an empty file is rejected');
t_eq(mam_drive_upload('x.exe', 'application/x-msdownload', 'MZ...')['error'], 'mime_not_allowed', 'a disallowed mime is rejected');
t_ok(mam_drive_mime_ok('video/mp4') && mam_drive_mime_ok('application/pdf') && mam_drive_mime_ok('image/png'), 'media + pdf mimes are allowed');
t_ok(!mam_drive_mime_ok('application/x-sh') && !mam_drive_mime_ok(''), 'scripts and empty mimes are not allowed');
mam_setting_set('drive_max_mb', 1);
t_eq(mam_drive_upload('big.mp4', 'video/mp4', str_repeat('x', 1024 * 1024 + 10))['error'], 'too_large', 'a file over the cap is rejected');
mam_setting_set('drive_max_mb', 256);

// ── Filename sanitisation ──
t_eq(mam_drive_safe_name('../../etc/passwd'), '....etcpasswd', 'path separators are stripped from the name');
t_eq(mam_drive_safe_name('  '), 'upload', 'a blank name falls back to a default');

// ── Round-trip: upload → fetchable URL → fetch the exact bytes back ──
$bytes = "MP4\x00\x01binary\xffcontent";
$up = mam_drive_upload('lesson week 1.mp4', 'video/mp4', $bytes);
t_ok($up['ok'] === true, 'upload succeeds under the mock transport');
t_ok(!empty($up['file_id']), 'upload returns a file id');
t_ok(strpos((string)$up['url'], $up['file_id']) !== false, 'the view URL carries the file id (fetchable link)');
t_eq($up['size'], strlen($bytes), 'the reported size matches the bytes');

$idFromUrl = mam_drive_id_from_url((string)$up['url']);
t_eq($idFromUrl, $up['file_id'], 'the file id is recoverable from the stored view URL');

$got = mam_drive_fetch((string)$up['file_id']);
t_ok($got['ok'] === true, 'fetch retrieves the file');
t_ok($got['data'] === $bytes, 'the fetched bytes are byte-for-byte the uploaded content');
t_eq($got['mime'], 'video/mp4', 'the fetched mime is preserved');

// ── Fetch guards ──
t_eq(mam_drive_fetch('has spaces!')['error'], 'bad_file_id', 'a malformed file id is rejected');
t_eq(mam_drive_fetch('mock_doesnotexist000000')['error'], 'not_found', 'an unknown file id is not found');

// ── Server-side signature verification (mock mirrors the web app) ──
$ts = 1234567890; $data = base64_encode('abc');
$good = ['action' => 'upload', 'ts' => $ts, 'name' => 'a.mp4', 'mime' => 'video/mp4', 'data' => $data, 'sig' => mam_drive_sign($ts, 'upload', $data)];
t_ok(mam_drive_mock_dispatch('upload', $good, 'abc')['ok'] === true, 'a correctly-signed request is accepted');
$bad = $good; $bad['sig'] = 'deadbeef';
t_eq(mam_drive_mock_dispatch('upload', $bad, 'abc')['error'], 'bad_signature', 'a forged signature is rejected');

// Restore defaults so later tests see a pristine (disabled) gateway.
mam_setting_set('drive_transport', '');
mam_setting_set('drive_shared_secret', '');
