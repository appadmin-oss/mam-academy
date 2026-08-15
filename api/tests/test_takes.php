<?php
/**
 * Recorded practice takes (Advanced Modernization §1.4).
 *
 * A take cannot ride the headshot path: what makes the image store safe is
 * that GD RE-ENCODES the bytes, so a renamed .php fails to decode and never
 * lands. There is no audio equivalent on shared hosting, so the safety comes
 * from container sniffing, a key grammar, storage outside the web root, and a
 * gated proxy. This file exercises those, because they are the whole defence.
 */
declare(strict_types=1);

t_group('takes — container sniffing is the gate');

$webm = "\x1A\x45\xDF\xA3" . str_repeat("\x00", 64);
$ogg  = 'OggS' . str_repeat("\x00", 64);
$wav  = 'RIFF' . "\x24\x00\x00\x00" . 'WAVE' . str_repeat("\x00", 64);
$m4a  = "\x00\x00\x00\x18" . 'ftyp' . 'M4A ' . str_repeat("\x00", 64);
$mp3  = 'ID3' . str_repeat("\x00", 64);
$mp3b = "\xFF\xFB" . str_repeat("\x00", 64);

t_eq(mam_take_sniff($webm)['ext'], 'webm', 'an EBML header is WebM');
t_eq(mam_take_sniff($ogg)['ext'],  'ogg',  'OggS is Ogg');
t_eq(mam_take_sniff($wav)['ext'],  'wav',  'RIFF + WAVE is a wav');
t_eq(mam_take_sniff($m4a)['ext'],  'm4a',  'ftyp at offset 4 is ISO-BMFF');
t_eq(mam_take_sniff($mp3)['ext'],  'mp3',  'an ID3 tag is an mp3');
t_eq(mam_take_sniff($mp3b)['ext'], 'mp3',  'a bare MPEG frame sync is an mp3');
t_eq(mam_take_sniff($webm)['mime'], 'audio/webm', 'the MIME comes from the sniff, never from the client');

// Everything that is not audio must be refused BEFORE anything is written.
// These are the payloads that matter: a script, a page, a binary, an image.
t_ok(mam_take_sniff("<?php system(\$_GET['c']); ?>" . str_repeat('x', 64)) === null, 'a PHP script is not audio');
t_ok(mam_take_sniff('<!doctype html><html><body>hi</body></html>') === null, 'an HTML page is not audio');
t_ok(mam_take_sniff("\x7FELF" . str_repeat("\x00", 64)) === null, 'an ELF binary is not audio');
t_ok(mam_take_sniff("\xFF\xD8\xFF\xE0" . str_repeat("\x00", 64)) === null, 'a JPEG is not audio');
t_ok(mam_take_sniff('#!/bin/sh' . str_repeat("\n", 64)) === null, 'a shell script is not audio');
t_ok(mam_take_sniff('') === null, 'empty is not audio');
t_ok(mam_take_sniff('short') === null, 'a too-short buffer is not audio, not a crash');
// RIFF alone must NOT pass — an AVI or a WebP is also RIFF, and accepting the
// magic without the WAVE form would widen the store to any RIFF container.
t_ok(mam_take_sniff('RIFF' . "\x24\x00\x00\x00" . 'AVI ' . str_repeat("\x00", 64)) === null, 'RIFF without WAVE is refused');

t_group('takes — the key grammar');

t_ok(mam_take_path('202608/' . str_repeat('a', 32) . '.webm') !== null, 'a well-formed key resolves');
// The path traversal cases. An unvalidated key is exactly how a media endpoint
// becomes an arbitrary-file reader.
t_ok(mam_take_path('../../../../etc/passwd') === null, 'traversal is refused');
t_ok(mam_take_path('202608/' . str_repeat('a', 32) . '.php') === null, 'a .php extension is not in the grammar');
t_ok(mam_take_path('202608/' . str_repeat('a', 32)) === null, 'a key with no extension is refused');
t_ok(mam_take_path('202608/' . str_repeat('Z', 32) . '.webm') === null, 'non-hex is refused');
t_ok(mam_take_path('20268/' . str_repeat('a', 32) . '.webm') === null, 'a short month prefix is refused');
t_ok(mam_take_path('202608/' . str_repeat('a', 31) . '.webm') === null, 'a short random half is refused');
t_ok(mam_take_path('') === null, 'an empty key is refused');
// The served MIME is derived from the KEY, so it cannot be influenced by an
// upload's declared type.
t_eq(mam_take_mime('202608/' . str_repeat('a', 32) . '.webm'), 'audio/webm', 'mime derives from the key extension');
t_eq(mam_take_mime('202608/' . str_repeat('a', 32) . '.m4a'), 'audio/mp4', 'm4a serves as audio/mp4');
t_ok(mam_take_mime('202608/' . str_repeat('a', 32) . '.exe') === null, 'an unknown extension has no mime');

t_group('takes — store round trip');

$stored = mam_take_store($webm);
t_ok($stored['ok'] === true, 'a WebM take stores');
t_eq($stored['mime'], 'audio/webm', 'and reports its sniffed mime');
t_eq($stored['bytes'], strlen($webm), 'and its byte count');
t_ok(preg_match('#^\d{6}/[0-9a-f]{32}\.webm$#', (string) $stored['key']) === 1, 'the key follows the grammar');
$path = mam_take_path($stored['key']);
t_ok(is_file($path), 'the file is on disk');
t_eq(file_get_contents($path), $webm, 'the bytes round-trip unchanged (no re-encode, so none may be lost)');
// Outside the web root is the whole point — nothing there is reachable by URL.
t_ok(strpos($path, MAM_STORAGE) === 0, 'takes live under MAM_STORAGE, outside the web root');
t_ok(!is_file($path . '.part'), 'no partial file is left behind');

t_eq(mam_take_url($stored['key']), '/api/media/take?k=' . rawurlencode($stored['key']), 'the URL is same-origin');
t_ok(mam_take_is_own_url(mam_take_url($stored['key'])), 'our own URL is recognised');
t_eq(mam_take_key_from_url(mam_take_url($stored['key'])), $stored['key'], 'the key round-trips through the URL');
t_ok(!mam_take_is_own_url('https://evil.example/take.webm'), 'a foreign URL is not ours');
t_ok(!mam_take_is_own_url('/api/media/headshot?k=202608/' . str_repeat('a', 32)), 'the headshot proxy is not the take proxy');
t_ok(mam_take_key_from_url('/api/media/take?k=../../etc/passwd') === null, 'a traversal key in a URL does not resolve');

$two = mam_take_store($webm);
t_ok($two['key'] !== $stored['key'], 'two identical takes get distinct, unguessable keys');

t_ok(mam_take_store('')['error'] === 'empty', 'an empty body is refused');
t_ok(mam_take_store('<?php echo 1;')['error'] === 'not_audio', 'a script body is refused');
t_ok(mam_take_store(str_repeat('a', 40))['error'] === 'not_audio', 'unrecognised bytes are refused');

t_group('takes — submission accepts a first-party URL');

// The bug this pins: mam_lms_submit demanded an absolute http(s) URL, which
// refuses OUR OWN stored take. The headshot store shipped with exactly this
// defect (CLAUDE.md gotcha #5) — the upload succeeded and was then dropped on
// the way into the database.
$TK_stu = mam_db_insert('users', ['name' => 'TK Student', 'email' => 'tk_stu@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$TK_other = mam_db_insert('users', ['name' => 'TK Other', 'email' => 'tk_other@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$TK_asg = mam_db_insert('assignments', ['student_id' => $TK_stu, 'title' => 'Scales take', 'status' => 'open']);

$subId = mam_lms_submit($TK_asg, $TK_stu, $stored['url'], ['artifact_kind' => 'audio', 'note' => 'first go']);
t_ok($subId > 0, 'a take submits against the student\'s own assignment');
$row = mam_db_row('SELECT artifact_url, artifact_kind FROM submissions WHERE id = ?', [$subId]);
t_eq($row['artifact_url'], $stored['url'], 'the first-party URL is stored verbatim, not dropped');
t_eq($row['artifact_kind'], 'audio', 'and is recorded as audio');

// The guard still does its job for everything else.
$err = function (callable $fn) { try { $fn(); return null; } catch (\Throwable $e) { return $e->getMessage(); } };
t_eq($err(fn() => mam_lms_submit($TK_asg, $TK_stu, 'javascript:alert(1)')), 'invalid_url', 'javascript: is still refused');
t_eq($err(fn() => mam_lms_submit($TK_asg, $TK_stu, 'data:text/html,<script>')), 'invalid_url', 'data: is still refused');
t_eq($err(fn() => mam_lms_submit($TK_asg, $TK_stu, '/api/media/take?k=../../etc/passwd')), 'invalid_url', 'a fake first-party URL with a bad key is refused');
t_eq($err(fn() => mam_lms_submit($TK_asg, $TK_stu, '/etc/passwd')), 'invalid_url', 'an arbitrary path is refused');
t_ok(mam_lms_submit($TK_asg, $TK_stu, 'https://drive.example/file') > 0, 'an absolute https URL still works');

// A student may not attach a take to someone else's assignment: a recording of
// a minor must never land in a stranger's review queue.
t_eq($err(fn() => mam_lms_submit($TK_asg, $TK_other, $stored['url'])), 'not_your_assignment', 'a take cannot be attached to another student\'s assignment');

// Housekeeping: remove the stored fixtures so the test storage dir stays clean.
foreach ([$stored['key'], $two['key']] as $k) { $p = mam_take_path($k); if ($p && is_file($p)) @unlink($p); }
