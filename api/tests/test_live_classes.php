<?php
/**
 * Live online classes (api/lib/live-classes.php, v101): who may join and when,
 * the signed token, the webhook, and turning presence into attendance.
 */
declare(strict_types=1);

$LV_now = gmdate('YmdHis') . random_int(100, 999);
$LV_u = static fn(string $t, string $r) => (int) mam_db_insert('users', ['email' => "lv.$t.$LV_now@test.local", 'name' => ucfirst($t) . ' Live', 'role' => $r, 'password_hash' => 'x', 'status' => 'active']);
$LV_kid1 = $LV_u('kid', 'student'); $LV_kid2 = $LV_u('kidtwo', 'student'); $LV_kid3 = $LV_u('kidthree', 'student');
$LV_out = $LV_u('outsider', 'student'); $LV_t = $LV_u('teacher', 'instructor');
$LV_user = static fn(int $id) => mam_db_row('SELECT * FROM users WHERE id = ?', [$id]);
// An existing course: a test that adds one changes the catalogue count other suites pin.
$LV_course = (int) mam_db_row('SELECT id FROM courses ORDER BY id LIMIT 1')['id'];
$LV_co = (int) mam_db_insert('cohorts', ['course_id' => $LV_course, 'instructor_id' => $LV_t, 'title' => 'Online violin', 'status' => 'active']);
foreach ([$LV_kid1, $LV_kid2, $LV_kid3] as $k) mam_db_insert('course_enrollments', ['student_id' => $k, 'course_id' => $LV_course, 'cohort_id' => $LV_co, 'status' => 'active']);
$LV_start = time() + 600;
$LV_sid = (int) mam_db_insert('class_sessions', ['cohort_id' => $LV_co, 'title' => 'Bowing', 'scheduled_at' => gmdate('Y-m-d H:i:s', $LV_start),
    'duration_min' => 60, 'status' => 'scheduled', 'mode' => 'online']);
$LV_code = static function (callable $fn): string { try { $fn(); return 'no error'; } catch (Throwable $e) { return $e->getMessage(); } };

t_group('live classes — who may join, and when');

t_eq(mam_live_role(mam_live_session($LV_sid), $LV_user($LV_kid1)), 'student', 'an enrolled student is in the class');
t_eq(mam_live_role(mam_live_session($LV_sid), $LV_user($LV_t)), 'teacher', 'the cohort\'s teacher leads it');
t_eq($LV_code(fn() => mam_live_join($LV_sid, $LV_user($LV_out))), 'not_yours', 'somebody from another class is refused');
t_eq($LV_code(fn() => mam_live_join($LV_sid, $LV_user($LV_kid1), $LV_start - 3600)), 'not_open_yet', 'a student cannot get in an hour early');
t_eq(mam_live_join($LV_sid, $LV_user($LV_t), $LV_start - 3600)['role'], 'teacher', 'a teacher can open the room early to set up');
t_eq($LV_code(fn() => mam_live_join($LV_sid, $LV_user($LV_kid1), $LV_start + 3 * 3600)), 'over', 'nobody joins a class that has finished');

t_group('live classes — the JaaS token');

$LV_key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($LV_key, $LV_pem);
$LV_pub = openssl_pkey_get_details($LV_key)['key'];
mam_setting_set('jaas_app_id', 'vpaas-magic-cookie-test');
mam_setting_set('jaas_kid', 'vpaas-magic-cookie-test/abc123');
mam_setting_set('jaas_private_key', $LV_pem);
mam_setting_set('meeting_default_provider', 'jaas');
$LV_j = mam_live_join($LV_sid, $LV_user($LV_kid1), $LV_start);
t_eq($LV_j['provider'], 'jaas', 'with JaaS set up, the class is embedded');
[$LV_h, $LV_b, $LV_s] = explode('.', $LV_j['jwt']);
$LV_dec = static fn(string $x) => base64_decode(strtr($x, '-_', '+/') . str_repeat('=', (4 - strlen($x) % 4) % 4));
t_eq(openssl_verify($LV_h . '.' . $LV_b, $LV_dec($LV_s), $LV_pub, OPENSSL_ALGO_SHA256), 1, 'the token is a valid RS256 signature with the academy\'s key');
$LV_claims = json_decode($LV_dec($LV_b), true);
t_eq($LV_claims['context']['user']['moderator'] ?? null, 'false', 'a student is never a moderator');
t_eq($LV_claims['context']['user']['id'] ?? null, 'u' . $LV_kid1, 'the token carries the academy\'s own id for the person');
t_eq($LV_claims['context']['features']['recording'] ?? null, 'false', 'nobody records unless the academy turns it on');
t_ok(str_ends_with($LV_j['room'], mam_meeting_jitsi_room($LV_sid)) && $LV_j['config']['disablePrivateChat'] === true, 'one room per class, private chat off');
t_ok($LV_j['lobby'] === true, 'a class with (or possibly with) children uses the lobby');
$LV_tj = json_decode($LV_dec(explode('.', mam_live_join($LV_sid, $LV_user($LV_t), $LV_start)['jwt'])[1]), true);
t_eq($LV_tj['context']['user']['moderator'] ?? null, 'true', 'the teacher is the moderator');

t_group('live classes — the webhook');

$LV_body = json_encode(['eventType' => 'PARTICIPANT_JOINED', 'fqn' => 'vpaas-magic-cookie-test/' . mam_meeting_jitsi_room($LV_sid),
    'timestamp' => ($LV_start + 120) * 1000, 'idempotencyKey' => 'k1-' . $LV_now, 'data' => ['id' => 'u' . $LV_kid2, 'name' => 'Kidtwo Live']]);
$LV_ts = (string) time();
$LV_sig = base64_encode(hash_hmac('sha256', $LV_ts . '.' . $LV_body, 'whsec', true));
t_ok(mam_live_webhook_ok("t=$LV_ts,v1=$LV_sig", $LV_body, 'whsec'), 'a correctly signed webhook is accepted');
t_ok(!mam_live_webhook_ok("t=$LV_ts,v1=$LV_sig", $LV_body . ' ', 'whsec'), 'a changed body is refused');
t_ok(!mam_live_webhook_ok('t=' . (time() - 3600) . ",v1=$LV_sig", $LV_body, 'whsec'), 'an old one is refused (no replays)');
t_eq(mam_live_webhook(json_decode($LV_body, true)), 'recorded', 'a join is recorded against the person in the token');
t_eq(mam_live_webhook(json_decode($LV_body, true)), 'duplicate', 'the same event twice is recorded once');
t_eq(mam_live_webhook(['eventType' => 'PARTICIPANT_JOINED', 'fqn' => 'x/MAM-' . $LV_sid . '-000000000000', 'timestamp' => time() * 1000, 'data' => []]),
    'unknown_room', 'a room name whose tag is not ours is ignored');
mam_live_webhook(['eventType' => 'PARTICIPANT_LEFT', 'fqn' => 'a/' . mam_meeting_jitsi_room($LV_sid), 'timestamp' => ($LV_start + 1200) * 1000,
    'idempotencyKey' => 'k2-' . $LV_now, 'data' => ['id' => 'u' . $LV_kid2]]);

t_group('live classes — presence becomes attendance');

// kid1: on time, the whole class, by heartbeat, with a 4-minute drop in the middle.
for ($m = 0; $m <= 58; $m++) { if ($m >= 20 && $m < 24) continue; mam_live_beat($LV_sid, $LV_user($LV_kid1), $LV_start + 60 + $m * 60); }
// kid2: joined 2 min in (webhook), left at 20 min.
// kid3: marked present by the teacher by hand, then appears in the call 15 minutes late.
mam_checkin_set_status($LV_sid, $LV_kid3, 'present', 'online', $LV_t);
for ($m = 15; $m <= 59; $m++) mam_live_beat($LV_sid, $LV_user($LV_kid3), $LV_start + $m * 60);
// the teacher, the whole time.
for ($m = 0; $m <= 60; $m++) mam_live_beat($LV_sid, $LV_user($LV_t), $LV_start + $m * 60);

$LV_iv = mam_live_intervals($LV_sid);
t_eq(count($LV_iv[$LV_kid1]), 2, 'a dropped connection and rejoin is two stays (a four-minute gap is not bridged)');
$LV_r = mam_live_reconcile($LV_sid);
$LV_by = array_column($LV_r['rows'], null, 'user_id');
$LV_att = static fn(int $k) => mam_db_row('SELECT * FROM session_attendance WHERE session_id = ? AND student_id = ?', [$LV_sid, $LV_k = $k]);
t_eq($LV_att($LV_kid1)['status'], 'present', 'on time and there throughout: present');
t_ok($LV_by[$LV_kid1]['minutes'] >= 54 && $LV_by[$LV_kid1]['minutes'] <= 57, 'minutes present leave out the gap (' . $LV_by[$LV_kid1]['minutes'] . ')');
t_eq($LV_att($LV_kid1)['mode'], 'online', 'recorded as online');
t_eq($LV_att($LV_kid2)['status'], 'left_early', 'joined then left after 18 minutes: left early');
t_eq((int) $LV_att($LV_kid2)['duration_min'], 18, '…with the minutes measured from the webhook');
t_eq($LV_att($LV_kid3)['status'], 'present', 'a mark the teacher made by hand is kept');
t_eq((int) $LV_att($LV_kid3)['minutes_late'], 14, '…but the measured lateness is filled in beside it');
t_eq($LV_r['adults'], 1, 'one adult was present');
t_ok($LV_r['single_adult'] && str_contains((string) $LV_r['note'], 'two adults'), 'and the register says so, for a class with children');
t_eq(count(mam_db_all("SELECT 1 FROM meeting_reconciliation WHERE session_id = ? AND provider = 'jaas'", [$LV_sid])), 4, 'the record keeps one line per person');
mam_live_reconcile($LV_sid);
t_eq(count(mam_db_all("SELECT 1 FROM meeting_reconciliation WHERE session_id = ? AND provider = 'jaas'", [$LV_sid])), 4, 'taking the register twice does not duplicate it');

$LV_ht = (string) file_get_contents(__DIR__ . '/../../.htaccess');
preg_match('/^\s*Header set Permissions-Policy "(.*)"\s*$/m', $LV_ht, $LV_pp);
t_ok(str_contains($LV_pp[1] ?? '', 'camera=(self') && str_contains($LV_pp[1] ?? '', '8x8.vc') && !str_contains($LV_pp[1] ?? '', 'camera=()'),
    'the permissions header lets the class call use the camera (a bare camera=() would deny it silently)');
t_ok((bool) preg_match('#frame-src[^;]*https://8x8\.vc#', $LV_ht) && (bool) preg_match('#script-src[^;]*https://8x8\.vc#', $LV_ht), 'and the CSP lets it load');
foreach (['jaas_app_id', 'jaas_kid', 'jaas_private_key', 'meeting_default_provider'] as $k) mam_setting_set($k, '');
