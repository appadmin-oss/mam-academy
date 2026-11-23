<?php
/**
 * Scheduling from the console (scheduling.php), automatic meeting links
 * (meeting-links.php), calendar feeds (calendar-feeds.php), WhatsApp via Kapso
 * (kapso.php) and the slot finder (slot-finder.php). No test touches the
 * network: $GLOBALS['MAM_INTEGRATION_HTTP'] is a fake transport throughout.
 */
declare(strict_types=1);

$SC_tag = 'sc' . substr(md5((string) microtime(true)), 0, 8);
$SC_user = static function (string $who, string $role, ?string $phone = null) use ($SC_tag): int {
    $id = (int) mam_db_insert('users', ['email' => "$who.$SC_tag@example.test", 'name' => ucfirst($who) . ' ' . $SC_tag,
        'role' => $role, 'status' => 'active', 'password_hash' => mam_auth_unusable_password(), 'phone' => $phone]);
    mam_user_ensure_role($id, $role);
    return $id;
};
$SC_admin = $SC_user('admin', 'admin');
$SC_teach = $SC_user('teacher', 'instructor', '08031110000');
$SC_teach2 = $SC_user('teacher2', 'instructor');
$SC_kid = $SC_user('kid', 'student', '08035550000');
$SC_kid2 = $SC_user('sibling', 'student');
$SC_mum = $SC_user('mum', 'parent', '0803 222 3333');
mam_db_insert('parent_links', ['parent_id' => $SC_mum, 'student_id' => $SC_kid, 'relationship' => 'mother']);
mam_db_insert('parent_links', ['parent_id' => $SC_mum, 'student_id' => $SC_kid2, 'relationship' => 'mother']);

$SC_cid = (int) (mam_db_row('SELECT id FROM courses ORDER BY id LIMIT 1')['id'] ?? 0);
$SC_cohort = (int) mam_db_insert('cohorts', ['course_id' => $SC_cid, 'title' => 'Piano ' . $SC_tag, 'instructor_id' => $SC_teach, 'status' => 'active']);
$SC_other  = (int) mam_db_insert('cohorts', ['course_id' => $SC_cid, 'title' => 'Theory ' . $SC_tag, 'instructor_id' => $SC_teach2, 'status' => 'active']);
$SC_sibco  = (int) mam_db_insert('cohorts', ['course_id' => $SC_cid, 'title' => 'Drums ' . $SC_tag, 'instructor_id' => $SC_teach2, 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $SC_kid, 'course_id' => $SC_cid, 'cohort_id' => $SC_cohort, 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $SC_kid, 'course_id' => $SC_cid, 'cohort_id' => $SC_other, 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $SC_kid2, 'course_id' => $SC_cid, 'cohort_id' => $SC_sibco, 'status' => 'active']);
$SC_room = (int) mam_db_insert('rooms', ['name' => 'Room ' . $SC_tag, 'capacity' => 6, 'active' => 1]);

// A term in 2033 with a holiday on Tue 11 Jan, so the dates are nobody else's.
$SC_term = (int) mam_db_insert('academic_terms', ['year_label' => '2032/33', 'code' => 'T' . $SC_tag, 'term_no' => 2, 'label' => 'Test term ' . $SC_tag,
    'starts_on' => '2033-01-03', 'ends_on' => '2033-03-25', 'status' => 'planned']);
mam_db_insert('term_closures', ['term_id' => $SC_term, 'kind' => 'holiday', 'label' => 'Founders Day', 'from_on' => '2033-01-11', 'to_on' => '2033-01-11']);
mam_db_insert('term_closures', ['term_id' => $SC_term, 'kind' => 'exam', 'label' => 'Exam week', 'from_on' => '2033-01-18', 'to_on' => '2033-01-18']);

$GLOBALS['MAM_INTEGRATION_HTTP'] = static function () { return [0, '']; };  // nothing leaves the process
$SC_rule = ['days' => [2], 'time' => '16:00', 'duration' => 60, 'room_id' => $SC_room, 'mode' => 'onsite', 'from' => '2033-01-04', 'to' => '2033-01-25'];

t_group('scheduling — a weekly series skips closures, refuses clashes, never duplicates');

$SC_p = mam_sched_plan_series($SC_cohort, $SC_rule);
t_eq(count($SC_p['slots']), 4, 'four Tuesdays between 4 and 25 January');
t_eq($SC_p['count']['closed'], 1, 'the holiday is skipped…');
t_eq((string) $SC_p['slots'][1]['closure']['label'], 'Founders Day', '…and reported by name');
t_eq($SC_p['slots'][2]['status'], 'ok', 'an exam week does NOT take the date away');
$SC_r = mam_sched_create_series($SC_cohort, $SC_rule, $SC_admin);
t_eq(count($SC_r['created']), 3, 'three sessions written');
$SC_again = mam_sched_create_series($SC_cohort, $SC_rule, $SC_admin);
t_eq(count($SC_again['created']), 0, 're-running the same series creates nothing');
t_eq($SC_again['count']['exists'], 3, '…it reports the three as already there');
t_eq((int) mam_db_row("SELECT COUNT(*) n FROM class_sessions WHERE cohort_id = ?", [$SC_cohort])['n'], 3, 'and the table holds exactly three');

// The room is now taken on those Tuesdays: another class there clashes.
$SC_clashRule = ['days' => [2], 'time' => '16:30', 'duration' => 60, 'room_id' => $SC_room, 'mode' => 'onsite', 'from' => '2033-01-04', 'to' => '2033-01-25'];
$SC_x = mam_sched_create_series($SC_sibco, $SC_clashRule, $SC_admin);
t_ok($SC_x['refused'] && !$SC_x['created'], 'a clashing series is refused whole by default');
t_eq($SC_x['count']['clash'], 3, '…naming every clashing date');
t_eq((string) ($SC_x['slots'][0]['with'][0]['kind'] ?? ''), 'room', '…and saying it is the room');
$SC_xs = mam_sched_create_series($SC_sibco, array_merge($SC_clashRule, ['to' => '2033-02-01']), $SC_admin, 'skip');
t_eq(count($SC_xs['created']), 1, '"create the rest" writes only the clash-free date');

// A learner already in another class at that time.
$SC_t = mam_sched_add_session($SC_other, ['at' => '2033-01-04T16:15', 'duration' => 30, 'mode' => 'online'], $SC_admin);
t_ok($SC_t['id'] === null, 'a session that puts a learner in two classes at once is refused');
t_ok(in_array('learner', array_column($SC_t['clashes'], 'kind'), true), '…as a LEARNER clash');
$SC_f = mam_sched_add_session($SC_other, ['at' => '2033-01-04T16:15', 'duration' => 30, 'mode' => 'online'], $SC_admin, true);
t_ok($SC_f['id'] > 0, '"add it anyway" keeps both');

t_group('meeting links — JaaS by default, per session and per person, moved with the session, withdrawn on cancel');

// Nothing configured: the session still gets its class page, which says live classes are not set up.
$SC_on1 = mam_sched_add_session($SC_other, ['at' => '2033-02-01T09:00', 'duration' => 45, 'mode' => 'online'], $SC_admin)['id'];
$SC_l1 = mam_db_row('SELECT meeting_link, meeting_provider FROM class_sessions WHERE id = ?', [$SC_on1]);
t_ok(str_ends_with((string) $SC_l1['meeting_link'], '/portal/live.html?session=' . $SC_on1), 'an online session has its class page the moment it exists — nobody typed it');
t_eq((string) $SC_l1['meeting_provider'], 'jaas', '…stored as JaaS, so it works the moment keys are added');
$SC_threw = '';
try { mam_live_join($SC_on1, mam_db_row('SELECT * FROM users WHERE id = ?', [$SC_teach2]), strtotime('2033-02-01 09:00:00 UTC')); } catch (\Throwable $e) { $SC_threw = $e->getMessage(); }
t_eq($SC_threw, 'live_not_set_up', 'with no JaaS keys the class page refuses with a setup notice — never a public meet.jit.si room');

// JaaS configured with a real RSA key.
$SC_pk = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($SC_pk, $SC_pem);
mam_setting_set('jaas_app_id', 'vpaas-magic-cookie-test'); mam_setting_set('jaas_kid', 'vpaas-magic-cookie-test/abc'); mam_setting_set('jaas_private_key', $SC_pem);
t_eq(mam_meeting_provider_order(), ['jaas'], 'JaaS is first (here, only) once configured');
$SC_on2 = mam_sched_add_session($SC_other, ['at' => '2033-02-02T09:00', 'duration' => 45, 'mode' => 'hybrid'], $SC_admin)['id'];
$SC_l2 = mam_db_row('SELECT meeting_link FROM class_sessions WHERE id = ?', [$SC_on2]);
t_ok($SC_l1['meeting_link'] !== $SC_l2['meeting_link'], 'each session has its OWN link');
t_ok(mam_meeting_jitsi_room($SC_on1) !== mam_meeting_jitsi_room($SC_on2) && preg_match('/^MAM-\d+-[0-9a-f]{12}$/', mam_meeting_jitsi_room($SC_on1)) === 1,
    'the room is per session and HMAC-tagged, so it cannot be enumerated');
$SC_claims = static function (string $jwt): array { return json_decode((string) mam_b64url_decode(explode('.', $jwt)[1]), true) ?: []; };
$SC_startTs = strtotime('2033-02-02 09:00:00 UTC');
$SC_tj = mam_live_join($SC_on2, mam_db_row('SELECT * FROM users WHERE id = ?', [$SC_teach2]), $SC_startTs - 600);
$SC_sj = mam_live_join($SC_on2, mam_db_row('SELECT * FROM users WHERE id = ?', [$SC_kid]), $SC_startTs);
$SC_tc = $SC_claims($SC_tj['jwt']); $SC_scl = $SC_claims($SC_sj['jwt']);
t_eq([$SC_tc['context']['user']['moderator'] ?? '', $SC_scl['context']['user']['moderator'] ?? ''], ['true', 'false'], 'the teacher\'s token moderates, the student\'s does not');
t_ok($SC_tj['jwt'] !== $SC_sj['jwt'] && ($SC_scl['context']['user']['name'] ?? '') === 'Kid ' . $SC_tag, 'each person gets their own named token');
t_ok(($SC_scl['nbf'] ?? 0) <= $SC_startTs - 15 * 60 && ($SC_scl['nbf'] ?? 0) >= $SC_startTs - 120 * 60, 'the token is not valid before the check-in window opens');
t_eq((int) ($SC_scl['exp'] ?? 0), $SC_startTs + (45 + MAM_LIVE_CLOSE_AFTER_MIN) * 60, '…and expires at the end of the class plus grace');
t_ok(str_starts_with((string) $SC_tj['room'], 'vpaas-magic-cookie-test/MAM-'), 'the room lives under the academy\'s JaaS app');
t_ok(!str_contains(json_encode([$SC_l1, $SC_l2, $SC_tj, $SC_sj]), 'meet.jit.si'), 'no generated link or join payload points at public meet.jit.si');
t_eq(mam_db_row('SELECT meeting_link FROM class_sessions WHERE id = ?', [$SC_r['created'][0]])['meeting_link'] ?? null, null, 'an in-person session gets no link');

// Zoom preferred and configured, but its create fails → falls back to JaaS, logged; the session is still made.
mam_setting_set('meeting_link_provider', 'zoom');
mam_setting_set('zoom_account_id', 'acc'); mam_setting_set('zoom_client_id', 'cid'); mam_setting_set('zoom_client_secret', 'sec'); mam_setting_set('zoom_token_cache', '');
$SC_calls = [];
$GLOBALS['MAM_INTEGRATION_HTTP'] = static function ($m, $u, $h, $b) use (&$SC_calls) {
    $SC_calls[] = [$m, $u, $b];
    if (str_contains($u, 'zoom.us/oauth/token')) return [200, '{"access_token":"tok","expires_in":3600}'];
    return [500, '{}'];
};
t_eq(mam_meeting_provider_order(), ['zoom', 'jaas'], 'the chosen provider first, then the rest that are configured');
$SC_fb = mam_sched_add_session($SC_other, ['at' => '2033-02-03T09:00', 'duration' => 45, 'mode' => 'online'], $SC_admin)['id'];
t_ok($SC_fb > 0 && (string) mam_db_row('SELECT meeting_provider FROM class_sessions WHERE id = ?', [$SC_fb])['meeting_provider'] === 'jaas',
    'a failing provider falls through to the next — the session is created with a working link');

// Zoom working: create, then a move PATCHes the same meeting, a cancel DELETEs it.
$SC_calls = [];
$GLOBALS['MAM_INTEGRATION_HTTP'] = static function ($m, $u, $h, $b) use (&$SC_calls) {
    $SC_calls[] = [$m, $u, $b];
    if (str_contains($u, 'oauth/token')) return [200, '{"access_token":"tok","expires_in":3600}'];
    if ($m === 'POST' && str_contains($u, '/users/me/meetings')) return [201, '{"id":987654,"join_url":"https://zoom.us/j/987654"}'];
    return [204, ''];
};
$SC_z = mam_sched_add_session($SC_other, ['at' => '2033-02-04T09:00', 'duration' => 45, 'mode' => 'online'], $SC_admin)['id'];
$SC_zr = mam_db_row('SELECT meeting_link, meeting_provider, meeting_external_id FROM class_sessions WHERE id = ?', [$SC_z]);
t_eq([$SC_zr['meeting_provider'], $SC_zr['meeting_external_id']], ['zoom', 'zoom:987654'], 'a Zoom meeting is created and its id kept on the session');
$SC_body = json_decode((string) end($SC_calls)[2], true);
t_ok(($SC_body['settings']['waiting_room'] ?? false) === true && ($SC_body['settings']['join_before_host'] ?? true) === false, '…with a waiting room and no join-before-host');
$SC_calls = [];
t_ok(mam_sched_reschedule($SC_z, '2033-02-04T11:00', 'Teacher at a concert', $SC_admin)['moved'], 'the session moves');
$SC_patch = array_values(array_filter($SC_calls, fn($c) => $c[0] === 'PATCH' && str_contains($c[1], '/meetings/987654')));
t_ok((bool) $SC_patch, '…and the SAME Zoom meeting is moved with it');
t_eq((string) (json_decode((string) ($SC_patch[0][2] ?? '{}'), true)['start_time'] ?? ''), '2033-02-04T11:00:00', '…to the new time');
$GLOBALS['MAM_INTEGRATION_HTTP'] = static function ($m, $u) {
    if (str_contains($u, 'oauth/token')) return [200, '{"access_token":"tok","expires_in":3600}'];
    if ($m === 'PATCH') return [404, ''];
    if ($m === 'POST') return [201, '{"id":111,"join_url":"https://zoom.us/j/111"}'];
    return [204, ''];
};
mam_sched_reschedule($SC_z, '2033-02-04T12:00', 'Again', $SC_admin);
t_eq((string) mam_db_row('SELECT meeting_external_id FROM class_sessions WHERE id = ?', [$SC_z])['meeting_external_id'], 'zoom:111', 'a meeting the provider could not move is regenerated, never left at the old time');
$SC_calls = [];
$GLOBALS['MAM_INTEGRATION_HTTP'] = static function ($m, $u) use (&$SC_calls) { $SC_calls[] = [$m, $u, null]; return str_contains($u, 'oauth') ? [200, '{"access_token":"tok","expires_in":3600}'] : [204, '']; };
mam_sched_cancel($SC_z, 'teacher', 'Teacher unwell', $SC_admin);
t_ok((bool) array_filter($SC_calls, fn($c) => $c[0] === 'DELETE' && str_contains($c[1], '/meetings/111')), 'cancelling deletes the provider\'s meeting');
t_eq(mam_db_row('SELECT meeting_link FROM class_sessions WHERE id = ?', [$SC_z])['meeting_link'] ?? null, null, '…and the session loses its link');
foreach (['zoom_account_id', 'zoom_client_id', 'zoom_client_secret', 'zoom_token_cache', 'meeting_link_provider', 'jaas_app_id', 'jaas_kid', 'jaas_private_key'] as $k) mam_setting_set($k, '');
$GLOBALS['MAM_INTEGRATION_HTTP'] = static function () { return [0, '']; };

$SC_old = (int) mam_db_insert('class_sessions', ['cohort_id' => $SC_other, 'scheduled_at' => date('Y-m-d H:i:s', time() + 86400 * 400), 'duration_min' => 60, 'status' => 'scheduled', 'mode' => 'online']);
$SC_bf = mam_meeting_backfill();
t_ok($SC_bf['done'] >= 1 && (string) mam_db_row('SELECT meeting_link FROM class_sessions WHERE id = ?', [$SC_old])['meeting_link'] !== '', 'one click gives every older online session its link');

t_group('reschedule and cancel — tell everyone, credit free make-ups, never touch money');

$SC_s1 = $SC_r['created'][0];
$SC_cnt = static fn(int $u) => (int) mam_db_row('SELECT COUNT(*) n FROM notifications WHERE user_id = ?', [$u])['n'];
[$SC_m0, $SC_t0] = [$SC_cnt($SC_mum), $SC_cnt($SC_teach)];
$SC_bad = '';
try { mam_sched_reschedule($SC_s1, '2033-01-04T18:00', '  ', $SC_admin); } catch (InvalidArgumentException $e) { $SC_bad = $e->getMessage(); }
t_eq($SC_bad, 'reason_required', 'a move needs a reason — it is what families are told');
$SC_mv = mam_sched_reschedule($SC_s1, '2033-01-04T18:00', 'Recital rehearsal', $SC_admin);
t_ok($SC_mv['moved'] && $SC_cnt($SC_mum) === $SC_m0 + 1 && $SC_cnt($SC_teach) === $SC_t0 + 1, 'the guardian and the teacher are each told once');
t_eq((string) mam_db_row("SELECT reason FROM session_history WHERE session_id = ? ORDER BY id DESC LIMIT 1", [$SC_s1])['reason'], 'Recital rehearsal', 'the move and its reason are on the record');
$SC_pay0 = (int) mam_db_row('SELECT COUNT(*) n FROM payments')['n'];
$SC_c = mam_sched_cancel($SC_s1, 'teacher', 'Teacher unwell', $SC_admin);
$SC_cr = mam_db_row('SELECT reason, counts_against_allowance FROM makeup_credits WHERE student_id = ? AND source_session_id = ?', [$SC_kid, $SC_s1]);
t_eq([$SC_c['credited'], $SC_cr['reason'] ?? null, (int) ($SC_cr['counts_against_allowance'] ?? 9)], [1, 'teacher_cancellation', 0], 'a teacher cancellation credits a FREE make-up');
t_eq((int) mam_db_row('SELECT COUNT(*) n FROM payments')['n'], $SC_pay0, 'and no money moves');
$SC_s2 = $SC_r['created'][1];
mam_sched_cancel($SC_s2, 'closure', 'Flooding', $SC_admin);
t_eq((string) mam_db_row('SELECT reason FROM makeup_credits WHERE source_session_id = ?', [$SC_s2])['reason'], 'academy_closure', 'a closure credits academy_closure');
$SC_twice = '';
try { mam_sched_cancel($SC_s2, 'academy', 'Again', $SC_admin); } catch (InvalidArgumentException $e) { $SC_twice = $e->getMessage(); }
t_eq([$SC_twice, (int) mam_db_row('SELECT COUNT(*) n FROM makeup_credits WHERE source_session_id = ?', [$SC_s2])['n']], ['not_scheduled', 1], 'a session cannot be cancelled twice, so nobody is credited twice');

t_group('calendar feeds — hashed, revocable tokens; valid RFC 5545');

$SC_feed = mam_cal_feed_mint('guardian', $SC_mum, $SC_mum);
$SC_tok = (string) $SC_feed['url_token'];
t_ok(strlen($SC_tok) >= 30 && mam_db_row('SELECT id FROM calendar_tokens WHERE token = ?', [$SC_tok]) === null, 'the URL token is never stored');
t_eq((string) $SC_feed['token'], hash('sha256', $SC_tok), '…only its SHA-256');
t_eq((int) (mam_cal_feed_resolve($SC_tok)['id'] ?? 0), (int) $SC_feed['id'], 'the token resolves to its feed');
t_eq(mam_cal_feed_mint('guardian', $SC_mum, $SC_mum)['url_token'], $SC_tok, 'asking again returns the same live link');
t_eq(mam_cal_feed_resolve($SC_tok . 'x'), null, 'a tampered token resolves to nothing');
$SC_ics = mam_cal_ics(mam_cal_feed_sessions('guardian', $SC_mum, '2033-01-01 00:00:00', '2033-04-01 00:00:00'), mam_cal_feed_title('guardian', $SC_mum));
t_ok(str_starts_with($SC_ics, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n") && str_ends_with($SC_ics, "END:VCALENDAR\r\n"), 'a calendar, CRLF line endings');
t_ok(str_contains($SC_ics, "BEGIN:VTIMEZONE\r\nTZID:Africa/Lagos") && str_contains($SC_ics, 'TZOFFSETTO:+0100'), 'with a VTIMEZONE for Africa/Lagos');
t_ok(str_contains($SC_ics, 'UID:session-' . $SC_s1 . '@academy.mamcentre.org'), 'a stable UID per session');
t_ok(str_contains($SC_ics, "DTSTART;TZID=Africa/Lagos:20330104T180000"), 'the moved time, on the academy\'s clock');
$SC_ev = substr($SC_ics, strpos($SC_ics, 'UID:session-' . $SC_s1 . '@'));
$SC_ev = substr($SC_ev, 0, strpos($SC_ev, 'END:VEVENT'));
t_ok(str_contains($SC_ev, 'SEQUENCE:2') && str_contains($SC_ev, 'STATUS:CANCELLED'), 'moved once and cancelled: SEQUENCE 2, STATUS:CANCELLED');
foreach (explode("\r\n", $SC_ics) as $SC_line) if (strlen($SC_line) > 75) { t_ok(false, 'every line is folded at 75 octets: ' . substr($SC_line, 0, 40)); break; }
t_ok(str_contains($SC_ics, 'Theory ' . $SC_tag) || str_contains($SC_ics, 'Piano ' . $SC_tag), 'a guardian\'s feed carries every linked child\'s classes');
$SC_new = mam_cal_feed_rotate('guardian', $SC_mum, $SC_mum);
t_ok(mam_cal_feed_resolve($SC_tok) === null && mam_cal_feed_resolve((string) $SC_new['url_token']) !== null, 'rotating kills the old link and issues a new one');
mam_cal_feed_revoke('guardian', $SC_mum, $SC_admin);
t_eq(mam_cal_feed_resolve((string) $SC_new['url_token']), null, 'revoking kills it');
$SC_l = mam_cal_subscribe_links('https://academy.mamcentre.org/api/calendar/feed?t=abc');
t_ok(str_starts_with($SC_l['apple'], 'webcal://') && str_contains($SC_l['google'], 'cid=webcal') && str_contains($SC_l['outlook'], 'addfromweb?url=https'), 'one-click links for Apple, Google and Outlook — no API key');
mam_db_update('calendar_tokens', ['created_at' => date('Y-m-d H:i:s', time() - (MAM_CAL_FEED_TTL_DAYS + 1) * 86400)], ['id' => (int) $SC_new['id']]);
mam_db_update('calendar_tokens', ['active' => 1], ['id' => (int) $SC_new['id']]);
t_eq(mam_cal_feed_resolve((string) mam_cal_feed_token(mam_db_row('SELECT * FROM calendar_tokens WHERE id = ?', [(int) $SC_new['id']]))), null, 'a lapsed feed stops working');

t_group('WhatsApp via Kapso — templates, consent, guardians only, idempotent (fake transport)');

t_eq([mam_kapso_e164('08031234567'), mam_kapso_e164('+234 803 123 4567'), mam_kapso_e164('8031234567'), mam_kapso_e164('234-803-123-4567'), mam_kapso_e164('+44 7700 900123'), mam_kapso_e164('12345')],
    ['2348031234567', '2348031234567', '2348031234567', '2348031234567', '447700900123', null], 'phone numbers normalise to E.164 digits (Nigerian forms first)');
$SC_pl = mam_kapso_template_payload('2348031234567', 'mam_session_cancelled', 'en', ['Ada', 'Piano', 'Tue 4 Jan', 'Teacher unwell']);
t_eq([$SC_pl['messaging_product'], $SC_pl['type'], $SC_pl['template']['name'], $SC_pl['template']['language']['code']], ['whatsapp', 'template', 'mam_session_cancelled', 'en'], 'the payload is Meta\'s template shape');
t_eq(array_column($SC_pl['template']['components'][0]['parameters'], 'text'), ['Ada', 'Piano', 'Tue 4 Jan', 'Teacher unwell'], '…with the parameters in order');
t_eq(mam_kapso_template_name('session_reminder'), 'mam_session_reminder', 'templates have working default names');
t_eq(mam_kapso_send_template($SC_mum, 'session_cancelled', ['a'], 'x:' . $SC_tag), 'skipped:not_configured', 'nothing is sent until Kapso is configured');
mam_setting_set('kapso_api_key', 'k-test'); mam_setting_set('kapso_phone_number_id', '1234567890');
$SC_wa = [];
$GLOBALS['MAM_INTEGRATION_HTTP'] = static function ($m, $u, $h, $b) use (&$SC_wa) { $SC_wa[] = [$u, $h, json_decode((string) $b, true)]; return [200, '{"messaging_product":"whatsapp","messages":[{"id":"wamid.' . count($SC_wa) . '"}]}']; };
t_eq(mam_kapso_send_template($SC_mum, 'session_cancelled', ['a'], 'y:' . $SC_tag), 'skipped:not_opted_in', 'a guardian who never turned WhatsApp on is not messaged — a default is not an opt-in');
mam_guardian_prefs_set($SC_mum, ['whatsapp' => 1, 'email' => 1]);
mam_db_update('users', ['phone' => '08035550000'], ['id' => $SC_kid]);
t_eq(mam_kapso_send_template($SC_kid, 'session_cancelled', ['a'], 'k:' . $SC_tag), 'skipped:student_number', 'a student\'s own number is never messaged by default');
t_eq(mam_kapso_send_template($SC_mum, 'session_cancelled', ['Ada', 'Piano', 'Tue', 'x'], 'z:' . $SC_tag), 'sent', 'an opted-in guardian is');
t_eq($SC_wa[0][0], 'https://api.kapso.ai/meta/whatsapp/v24.0/1234567890/messages', 'to Kapso\'s documented endpoint');
t_ok(in_array('X-API-Key: k-test', $SC_wa[0][1], true) && $SC_wa[0][2]['to'] === '2348032223333', '…with the key header and the normalised number');
t_eq(mam_kapso_send_template($SC_mum, 'session_cancelled', ['Ada'], 'z:' . $SC_tag), 'duplicate', 'the same event to the same person is never sent twice');
t_eq(count($SC_wa), 1, '…and made no second call');
mam_guardian_prefs_set($SC_mum, ['whatsapp' => 0, 'email' => 1]);
t_eq(mam_kapso_recipient($SC_mum, 'safeguarding')['ok'], true, 'safeguarding reaches every usable channel whatever the toggle says');
$GLOBALS['MAM_INTEGRATION_HTTP'] = static fn() => [500, '{"error":{"message":"boom"}}'];
mam_guardian_prefs_set($SC_mum, ['whatsapp' => 1, 'email' => 1]);
t_eq(mam_kapso_send_template($SC_mum, 'session_reminder', ['a'], 'f:' . $SC_tag), 'failed', 'a provider failure is a logged row, never an exception');
t_eq((string) mam_db_row('SELECT status FROM whatsapp_outbound WHERE idem_key = ?', ['f:' . $SC_tag])['status'], 'failed', '…recorded as failed');
// The webhook: signature, out-of-order statuses, the 24-hour window.
mam_setting_set('kapso_webhook_secret', 'whsec');
$SC_raw = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.1', 'status' => 'read'], ['id' => 'wamid.1', 'status' => 'delivered']],
    'messages' => [['from' => '2348032223333', 'timestamp' => (string) time()]]]]]]]]);
t_ok(mam_kapso_webhook_ok($SC_raw, hash_hmac('sha256', $SC_raw, 'whsec')) && !mam_kapso_webhook_ok($SC_raw, hash_hmac('sha256', $SC_raw . ' ', 'whsec')), 'the webhook signature is verified over the raw body');
mam_kapso_webhook_apply(json_decode($SC_raw, true));
t_eq((string) mam_db_row('SELECT status FROM whatsapp_outbound WHERE idem_key = ?', ['z:' . $SC_tag])['status'], 'read', 'statuses never move backwards (read stays read)');
t_ok(mam_kapso_window_open('2348032223333'), 'an inbound message opens the 24-hour window');
foreach (['kapso_api_key', 'kapso_phone_number_id', 'kapso_webhook_secret'] as $k) mam_setting_set($k, '');
$GLOBALS['MAM_INTEGRATION_HTTP'] = static function () { return [0, '']; };

t_group('slot finder — pure, deterministic scoring with a reason');

$SC_d = ['2033-03-01', '2033-03-08'];
$SC_ts = static fn(string $d, string $t, int $m = 60) => [strtotime("$d $t:00"), strtotime("$d $t:00") + $m * 60];
$SC_ctx = ['size' => 5, 'mode' => 'onsite', 'buffer' => 15, 'closed' => [],
    'teacher_busy' => [$SC_ts('2033-03-01', '10:00')], 'teacher_windows' => null,
    'teacher_avail' => ['tue:afternoon' => true],
    'rooms' => [['id' => 1, 'name' => 'Big', 'capacity' => 20, 'busy' => []], ['id' => 2, 'name' => 'Small', 'capacity' => 6, 'busy' => [$SC_ts('2033-03-08', '16:00')]]],
    'students' => [7 => [$SC_ts('2033-03-01', '14:00')]], 'siblings' => [$SC_ts('2033-03-08', '17:30')]];
$SC_c = static fn(string $t) => ['dow' => 2, 'time' => $t, 'dur' => 60, 'dates' => $SC_d];
$SC_a = mam_slot_score($SC_c('10:00'), $SC_ctx);
t_ok(!$SC_a['ok'] && str_contains($SC_a['sentence'], 'teacher busy'), 'a teacher clash rules a slot out, and says so');
t_ok(!mam_slot_score($SC_c('10:30'), $SC_ctx)['ok'], 'the turnaround buffer counts: 10:30 after a 10–11 class is out too');
$SC_b = mam_slot_score($SC_c('16:00'), $SC_ctx);
t_ok($SC_b['ok'] && $SC_b['room']['name'] === 'Big', 'a room busy on one date is passed over for one free on all');
$SC_e = mam_slot_score($SC_c('15:00'), $SC_ctx);
t_eq($SC_e['room']['name'], 'Big', '…the smallest room that seats the class is chosen (Small is booked at 16:00 on the 8th with buffer)');
$SC_f = mam_slot_score($SC_c('14:00'), $SC_ctx);
t_ok($SC_f['ok'] && $SC_f['score'] < $SC_e['score'] && str_contains($SC_f['sentence'], 'learner'), 'a learner clash costs and is named');
$SC_g = mam_slot_score($SC_c('19:00'), $SC_ctx);
t_ok(str_contains($SC_g['sentence'], 'did not list tue:evening'), 'outside the teacher\'s stated availability is a penalty with a reason');
$SC_h = mam_slot_score(['dow' => 2, 'time' => '16:00', 'dur' => 60, 'dates' => ['2033-03-08']], $SC_ctx);
t_ok(str_contains($SC_h['sentence'], 'sibling'), 'next to a sibling\'s class is preferred');
t_ok(str_starts_with($SC_b['sentence'], 'Tue 4:00pm: teacher free, Big fits 20'), 'the sentence reads like the office would say it');
$SC_closed = mam_slot_score($SC_c('16:00'), ['closed' => array_fill_keys($SC_d, 'Holiday')] + $SC_ctx);
t_ok(!$SC_closed['ok'], 'a slot with every date closed is out');
$SC_r1 = mam_slot_rank([mam_slot_score($SC_c('15:00'), $SC_ctx), $SC_a, mam_slot_score($SC_c('16:00'), $SC_ctx), $SC_f]);
$SC_r2 = mam_slot_rank([$SC_f, mam_slot_score($SC_c('16:00'), $SC_ctx), $SC_a, mam_slot_score($SC_c('15:00'), $SC_ctx)]);
t_eq(array_column($SC_r1, 'time'), array_column($SC_r2, 'time'), 'ranking is deterministic whatever the input order');
t_eq(end($SC_r1)['time'], '10:00', 'an unusable slot ranks last');
$SC_sug = mam_slot_suggest_series($SC_cohort, ['from' => '2033-02-07', 'to' => '2033-02-27', 'limit' => 3, 'days' => [2, 3]]);
t_ok(count($SC_sug) === 3 && $SC_sug[0]['ok'] && $SC_sug[0]['sentence'] !== '', 'the series finder returns explained suggestions from the live schedule');
$SC_mk = mam_slot_suggest_makeup($SC_s2, 3);
t_ok($SC_mk && count(array_unique(array_map(fn($x) => $x['dates'][0], $SC_mk))) === count($SC_mk), 'make-up suggestions after a cancellation, one per day');

t_group('scheduling — console wiring');

$SC_idx = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
foreach (['sched_series', 'sched_session_add', 'sched_reschedule', 'sched_cancel', 'sched_meeting_backfill', 'sched_feed', 'sched_integrations_save', 'sched_kapso_test'] as $SC_a2) {
    t_ok(preg_match("/'" . $SC_a2 . "' => '(curriculum|settings)'/", $SC_idx) === 1 && str_contains($SC_idx, "\$action === '" . $SC_a2 . "'"), "$SC_a2 has a capability and a handler");
}
$GLOBALS['__mam_console_fail'] = [];
$SC_tt = mam_console_timetable(['w' => 0, 'by' => 'room', 's' => $SC_s1, 'plan' => 1, 'pc' => $SC_cohort, 'days' => [2], 'time' => '16:00', 'from' => '2033-01-04', 'to' => '2033-01-25', 'suggest' => 1, 'room' => $SC_room]);
t_eq(mam_console_failures(), [], 'the timetable provider reads cleanly with every option on');
t_ok(is_array($SC_tt['plan']) && is_array($SC_tt['suggest']) && is_array($SC_tt['session']) && isset($SC_tt['feeds']['room']), '…plan, suggestions, the session and the room feed');
t_ok(!str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/partials/admin-console/timetable.php'), 'style='), 'the timetable carries no style= attribute');
