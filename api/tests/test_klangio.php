<?php
/**
 * Practice Studio · Transcribe (api/lib/klangio.php, api/routes/transcribe.php).
 * Klangio itself is never called here: what is pinned is that the feature is OFF
 * without a key, that provider statuses map to three phases, that the note CSV
 * parser survives the shapes it may meet, and that the route is gated.
 */
declare(strict_types=1);

t_group('klangio — off until configured, phases, note CSV');

t_ok(mam_klangio_configured() === (function_exists('curl_init') && trim((string) mam_env('KLANGIO_API_KEY', '')) !== ''),
    'configured only with a key and cURL');
foreach (['SUCCESS' => 'done', 'completed' => 'done', 'FAILED' => 'failed', 'cancelled' => 'failed', 'PENDING' => 'working', '' => 'working'] as $in => $want) {
    t_eq(mam_klangio_phase((string) $in), $want, "status \"$in\" reads as $want");
}
$KL_csv = "onset,offset,pitch\n0.0,0.5,60\n0.5,1.0,64\n1.0,1.5,G4\n1.5,2.0,999\n";
$KL_n = mam_klangio_parse_csv($KL_csv);
t_eq(array_column($KL_n, 'note'), ['C4', 'E4', 'G4'], 'MIDI numbers and note names both parse; an impossible pitch is dropped');
t_eq($KL_n[1]['d'], 0.5, 'a duration is derived from onset and offset');
t_eq(mam_klangio_parse_csv(''), [], 'an empty CSV gives no notes');
t_eq(count(mam_klangio_parse_csv("t;d;p\n" . str_repeat("0;1;60\n", 500), 50)), 50, 'semicolon CSVs parse, and the preview is capped');

$KL_idx = (string) file_get_contents(dirname(__DIR__) . '/index.php');
t_ok((bool) preg_match("#post\('/transcribe'.*mw_csrf\(\).*mw_user\(\).*mw_rate_limit#", $KL_idx),
    'submitting a clip needs CSRF, a signed-in user and a rate limit (it spends a paid quota)');
$KL_route = (string) file_get_contents(dirname(__DIR__) . '/routes/transcribe.php');
t_ok(str_contains($KL_route, "mam_fail('transcribe_unconfigured', 503"), 'the route refuses cleanly when no key is set');
t_ok(str_contains($KL_route, '8 * 1024 * 1024'), 'clips are capped at 8 MB');
