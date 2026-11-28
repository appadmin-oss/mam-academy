<?php
/**
 * §11 live Google Meet participation.
 *
 * The wire call needs a Workspace service account, which no test environment
 * has — so the logic is factored into pure functions and those are tested for
 * real: a JWT that is actually verified against its own public key, the
 * meeting-code extractor, and the activities parser against a payload shaped
 * like the Reports API's.
 */
declare(strict_types=1);

t_group('google meet — the signed assertion');

// A throwaway RSA key, generated here so the test never depends on a fixture.
$GM_res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($GM_res, $GM_priv);
$GM_pub = openssl_pkey_get_details($GM_res)['key'];
$GM_sa = [
    'client_email'   => 'reports@mam-academy.iam.gserviceaccount.com',
    'private_key'    => $GM_priv,
    'private_key_id' => 'kid-123',
    'token_uri'      => 'https://oauth2.googleapis.com/token',
];

$GM_now = 1786000000;
$GM_jwt = mam_google_jwt_assertion($GM_sa, MAM_GOOGLE_REPORTS_SCOPE, 'admin@mamcentre.org', $GM_now, 3600);
$GM_parts = explode('.', $GM_jwt);
t_eq(count($GM_parts), 3, 'the assertion is a three-part compact JWT');

$unb64 = function (string $s) { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); };
$GM_head = json_decode($unb64($GM_parts[0]), true);
$GM_body = json_decode($unb64($GM_parts[1]), true);

t_eq($GM_head['alg'], 'RS256', 'Google requires RS256');
t_eq($GM_head['kid'], 'kid-123', 'the key id is carried so Google can pick the right key');
t_eq($GM_body['iss'], 'reports@mam-academy.iam.gserviceaccount.com', 'the issuer is the service account');
// Without `sub` the token is the robot's own identity, which has no Reports
// access at all — this is the single most common domain-delegation mistake.
t_eq($GM_body['sub'], 'admin@mamcentre.org', 'the assertion impersonates the delegated admin');
t_eq($GM_body['scope'], MAM_GOOGLE_REPORTS_SCOPE, 'it requests the audit-readonly scope');
t_eq($GM_body['aud'], 'https://oauth2.googleapis.com/token', 'the audience is the token endpoint');
t_eq($GM_body['iat'], $GM_now, 'issued-at is the supplied clock');
t_eq($GM_body['exp'], $GM_now + 3600, 'and it expires an hour later');

// The signature must actually verify — a JWT that merely looks right is useless.
$GM_sig = $unb64($GM_parts[2]);
t_eq(openssl_verify($GM_parts[0] . '.' . $GM_parts[1], $GM_sig, $GM_pub, OPENSSL_ALGO_SHA256), 1,
     'the signature verifies against the service account public key');

// Tampering must break it, or the signature proves nothing.
$GM_bad = json_decode($unb64($GM_parts[1]), true);
$GM_bad['sub'] = 'attacker@example.com';
$GM_tampered = mam_b64url_encode((string) json_encode($GM_bad));
t_ok(openssl_verify($GM_parts[0] . '.' . $GM_tampered, $GM_sig, $GM_pub, OPENSSL_ALGO_SHA256) !== 1,
     'altering the impersonated subject invalidates the signature');

// TTL is clamped: Google rejects assertions valid for more than an hour.
$GM_long = json_decode($unb64(explode('.', mam_google_jwt_assertion($GM_sa, 's', 'a@b.c', $GM_now, 99999))[1]), true);
t_eq($GM_long['exp'] - $GM_long['iat'], 3600, 'an over-long TTL is clamped to Google’s maximum');

// An unusable key must throw, not silently produce an unsigned token.
$threw = false;
try { mam_google_jwt_assertion(['client_email' => 'a@b.c', 'private_key' => 'not a key'], 's', 'x@y.z'); }
catch (\RuntimeException $e) { $threw = ($e->getMessage() === 'bad_private_key'); }
t_ok($threw, 'a malformed private key throws rather than signing nothing');
$threw = false;
try { mam_google_jwt_assertion(['client_email' => 'a@b.c'], 's', 'x@y.z'); }
catch (\RuntimeException $e) { $threw = ($e->getMessage() === 'bad_service_account'); }
t_ok($threw, 'a service account with no key is refused');

t_group('google meet — configuration gating');

mam_setting_set('google_meet_sa_json', '');
mam_setting_set('google_workspace_admin', '');
t_ok(mam_meeting_google_configured() === false, 'unconfigured by default');
t_ok(mam_meeting_google_service_account() === null, 'no service account when unset');

// A truncated paste must be caught at configuration time, not in the cron.
mam_setting_set('google_meet_sa_json', '{"client_email":"a@b.c"}');
mam_setting_set('google_workspace_admin', 'admin@mamcentre.org');
t_ok(mam_meeting_google_service_account() === null, 'a service account missing its private key is rejected');
t_ok(mam_meeting_google_configured() === false, 'and the integration stays off');

mam_setting_set('google_meet_sa_json', 'not json at all');
t_ok(mam_meeting_google_service_account() === null, 'unparseable JSON is rejected');

mam_setting_set('google_meet_sa_json', (string) json_encode($GM_sa));
t_ok(mam_meeting_google_configured() === true, 'a complete service account + delegated admin enables it');
mam_setting_set('google_meet_sa_json', '');
mam_setting_set('google_workspace_admin', '');

t_group('google meet — the meeting code');

t_eq(mam_meeting_meet_code('https://meet.google.com/abc-defg-hij'), 'abc-defg-hij', 'a standard Meet code is extracted');
t_eq(mam_meeting_meet_code('https://meet.google.com/ABC-DEFG-HIJ'), 'abc-defg-hij', 'and normalised to lower case');
t_eq(mam_meeting_meet_code('https://meet.google.com/lookup/abcdefghij'), 'abcdefghij', 'a lookup link works too');
t_ok(mam_meeting_meet_code('https://meet.jit.si/MAM-1-abc') === null, 'a Jitsi link yields no Meet code');
t_ok(mam_meeting_meet_code('') === null, 'an empty link yields none');
t_ok(mam_meeting_meet_code(null) === null, 'and neither does a null one');

t_group('google meet — parsing the Reports payload');

// Shaped like the Admin SDK Reports API: one call_ended event per participant,
// parameters as a typed name/value list, the event time being when they LEFT.
$GM_payload = [
    'items' => [
        [
            'id' => ['time' => '2026-09-10T17:02:00.000Z'],
            'events' => [[
                'name' => 'call_ended',
                'parameters' => [
                    ['name' => 'identifier', 'value' => 'Student@MAMCentre.org'],
                    ['name' => 'display_name', 'value' => 'David Adeyemi'],
                    ['name' => 'duration_seconds', 'intValue' => 3720],
                    ['name' => 'meeting_code', 'value' => 'abc-defg-hij'],
                ],
            ]],
        ],
        [
            'id' => ['time' => '2026-09-10T17:00:30.000Z'],
            'events' => [[
                'name' => 'call_ended',
                'parameters' => [
                    ['name' => 'identifier', 'value' => 'ola@mamcentre.org'],
                    ['name' => 'display_name', 'value' => 'Ola Instructor'],
                    ['name' => 'duration_seconds', 'intValue' => 3900],
                    ['name' => 'meeting_code', 'value' => 'abc-defg-hij'],
                ],
            ]],
        ],
        [   // a different class running at the same time
            'id' => ['time' => '2026-09-10T17:05:00.000Z'],
            'events' => [[
                'name' => 'call_ended',
                'parameters' => [
                    ['name' => 'identifier', 'value' => 'someone@mamcentre.org'],
                    ['name' => 'display_name', 'value' => 'Someone Else'],
                    ['name' => 'duration_seconds', 'intValue' => 600],
                    ['name' => 'meeting_code', 'value' => 'zzz-zzzz-zzz'],
                ],
            ]],
        ],
        [   // an unrelated event type in the same feed
            'id' => ['time' => '2026-09-10T16:30:00.000Z'],
            'events' => [['name' => 'call_started', 'parameters' => [['name' => 'identifier', 'value' => 'x@y.z']]]],
        ],
    ],
];

$GM_all = mam_meeting_google_parse_activities($GM_payload);
t_eq(count($GM_all), 3, 'every call_ended participant is parsed');
t_ok(!in_array('call_started', array_column($GM_all, 'name'), true), 'other event types are ignored');

$GM_one = mam_meeting_google_parse_activities($GM_payload, 'abc-defg-hij');
t_eq(count($GM_one), 2, 'filtering by meeting code excludes a concurrent class');
t_eq($GM_one[0]['email'], 'student@mamcentre.org', 'the identifier is lower-cased so it matches a stored user');
t_eq($GM_one[0]['name'], 'David Adeyemi', 'the display name is kept');
// Reports gives the LEAVE time and a duration; the join time has to be derived.
t_eq($GM_one[0]['leave_at'], date('Y-m-d H:i:s', strtotime('2026-09-10T17:02:00.000Z')), 'the event time is the leave time');
t_eq($GM_one[0]['join_at'], date('Y-m-d H:i:s', strtotime('2026-09-10T17:02:00.000Z') - 3720), 'the join time is derived by subtracting the duration');

// A participant with no identifier (dial-in / anonymous) is still recorded, so
// the operator can see somebody was present, but it will not map to a user.
$GM_anon = mam_meeting_google_parse_activities(['items' => [[
    'id' => ['time' => '2026-09-10T17:00:00.000Z'],
    'events' => [['name' => 'call_ended', 'parameters' => [
        ['name' => 'display_name', 'value' => 'Phone caller'],
        ['name' => 'duration_seconds', 'intValue' => 60],
    ]]],
]]]);
t_eq(count($GM_anon), 1, 'an anonymous participant is still recorded');
t_eq($GM_anon[0]['email'], '', 'with no email to map on');
t_eq($GM_anon[0]['name'], 'Phone caller', 'but a usable display name');

// A participant with neither identifier nor name carries no information.
t_eq(count(mam_meeting_google_parse_activities(['items' => [[
    'id' => ['time' => '2026-09-10T17:00:00.000Z'],
    'events' => [['name' => 'call_ended', 'parameters' => [['name' => 'duration_seconds', 'intValue' => 60]]]],
]]])), 0, 'a participant with neither name nor identifier is dropped');

t_eq(count(mam_meeting_google_parse_activities([])), 0, 'an empty payload parses to nothing');
t_eq(count(mam_meeting_google_parse_activities(['items' => []])), 0, 'so does an empty item list');

t_group('google meet — fetch degrades rather than throwing (§11)');

// §11: where Meet data cannot be retrieved, the MAM clock-in stands. An
// unconfigured integration must return null quietly, never raise.
mam_setting_set('google_meet_sa_json', '');
mam_setting_set('google_workspace_admin', '');
t_ok(mam_meeting_google_fetch(999999) === null, 'an unconfigured fetch returns null');
t_ok(mam_meeting_google_sync(999999) === null, 'and so does the sync');
t_ok(mam_meeting_google_token() === null, 'no token without credentials');

// The API rows share the CSV shape, so they store through the same importer.
$GM_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($GM_course === 0) $GM_course = mam_db_insert('courses', ['code' => 'gm_c', 'name' => 'GM', 'kind' => 'core']);
$GM_coh  = mam_db_insert('cohorts', ['course_id' => $GM_course, 'title' => 'GM Cohort', 'status' => 'active', 'capacity' => 10]);
$GM_sess = mam_db_insert('class_sessions', ['cohort_id' => $GM_coh, 'title' => 'GM Session',
    'scheduled_at' => '2026-09-10 16:00:00', 'status' => 'held', 'duration_min' => 60, 'kind' => 'class',
    'meeting_link' => 'https://meet.google.com/abc-defg-hij']);
$GM_stu = mam_db_insert('users', ['name' => 'GM Stu', 'email' => 'student@mamcentre.org', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);

$n = mam_meeting_reconcile_import($GM_sess, $GM_one, 'meet', 'api');
t_eq($n, 2, 'parsed API rows store through the same importer as a CSV');
$stored = mam_db_all("SELECT * FROM meeting_reconciliation WHERE session_id = ? AND source = 'api' ORDER BY id", [$GM_sess]);
t_eq(count($stored), 2, 'both rows landed');
t_eq((int) $stored[0]['subject_id'], $GM_stu, 'the identifier resolved to the real user');
t_eq((int) $stored[0]['minutes'], 62, 'the stored duration matches join→leave');

// `source` keeps an operator's CSV and the API apart, so a sync never wipes an
// import (and re-syncing replaces only its own rows).
mam_meeting_reconcile_import($GM_sess, [['name' => 'Manual Row', 'email' => '', 'join_at' => '', 'leave_at' => '']], 'meet', 'import');
mam_meeting_reconcile_import($GM_sess, $GM_one, 'meet', 'api');
t_eq((int) (mam_db_row("SELECT COUNT(*) c FROM meeting_reconciliation WHERE session_id = ? AND source = 'import'", [$GM_sess])['c'] ?? 0), 1,
     're-syncing the API does not clear an operator’s CSV import');
t_eq((int) (mam_db_row("SELECT COUNT(*) c FROM meeting_reconciliation WHERE session_id = ? AND source = 'api'", [$GM_sess])['c'] ?? 0), 2,
     'and the API rows are replaced, not duplicated');

t_group('google meet — the cron sweep');

// Unconfigured, the sweep is a no-op rather than an error the cron trips on.
t_eq(mam_meeting_google_sweep(), 0, 'the sweep does nothing while the integration is off');

// Its selection rules are worth pinning even without credentials: only online
// or hybrid sessions, only ones that have finished settling, only once.
$GM_online = mam_db_insert('class_sessions', ['cohort_id' => $GM_coh, 'title' => 'Finished online',
    'scheduled_at' => mam_session_wall(time() - 3 * 3600), 'status' => 'held', 'duration_min' => 60, 'kind' => 'class', 'mode' => 'online']);
$GM_onsite = mam_db_insert('class_sessions', ['cohort_id' => $GM_coh, 'title' => 'Finished onsite',
    'scheduled_at' => mam_session_wall(time() - 3 * 3600), 'status' => 'held', 'duration_min' => 60, 'kind' => 'class', 'mode' => 'onsite']);
$GM_running = mam_db_insert('class_sessions', ['cohort_id' => $GM_coh, 'title' => 'Still running',
    'scheduled_at' => mam_session_wall(time() - 10 * 60), 'status' => 'scheduled', 'duration_min' => 60, 'kind' => 'class', 'mode' => 'online']);

$GM_candidates = mam_db_all(
    "SELECT id, scheduled_at, duration_min FROM class_sessions
      WHERE scheduled_at >= ? AND scheduled_at <= ? AND mode IN ('online','hybrid')",
    [mam_session_wall(time() - 24 * 3600), mam_session_now()]
);
$GM_ids = array_map(fn($r) => (int) $r['id'], $GM_candidates);
t_ok(in_array($GM_online, $GM_ids, true), 'a finished online session is a sweep candidate');
t_ok(!in_array($GM_onsite, $GM_ids, true), 'an onsite session is not — there is no Meet to reconcile');

// The settle window: a class that ended two minutes ago is skipped, because
// Reports lags the call and would return an incomplete participant list.
$stillSettling = 0;
foreach ($GM_candidates as $c) {
    $end = mam_session_ts((string) $c['scheduled_at']) + (int) $c['duration_min'] * 60;
    if (time() < $end + 10 * 60 && (int) $c['id'] === $GM_running) $stillSettling++;
}
t_eq($stillSettling, 1, 'a session that has not finished settling is held back');

// The dedupe marker is what stops a transient Google outage being retried forever.
mam_db_insert('events', ['type' => 'meet.synced', 'entity' => 'class_session', 'entity_id' => $GM_online, 'payload' => '{"rows":2}']);
t_ok(mam_db_row("SELECT id FROM events WHERE type = 'meet.synced' AND entity = 'class_session' AND entity_id = ?", [$GM_online]) !== null,
     'a synced session is marked so the sweep does not repeat it');
