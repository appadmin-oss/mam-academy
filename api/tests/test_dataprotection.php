<?php
/**
 * The NDPA 2023 / GAID 2025 compliance layer (schema v84).
 *
 * The tests that matter most here are not the CRUD round-trips — they are the
 * three that check the layer against reality:
 *
 *  1. Every registry entry names a table and column that EXIST. A register or a
 *     retention schedule that points at storage nobody has is a policy document
 *     pretending to be a control, and it drifts silently the day a feature ships.
 *  2. `action = delete` appears only on LEAF tables. A cascade through an
 *     enrolment would destroy academic history in order to tidy up a note.
 *  3. Every clock is DERIVED and measured from the statutory trigger — 72 hours
 *     from AWARENESS, not from occurrence.
 */

/**
 * Local expect-a-throw helper. `assert.php` has none, and a globally-named one
 * added here would collide with any future shared helper.
 */
function dp_throws(callable $fn, string $needle, string $msg): void {
    $got = '';
    try { $fn(); } catch (\Throwable $e) { $got = $e->getMessage(); }
    t_ok($got !== '' && strpos($got, $needle) !== false, $msg . ' [threw ' . var_export($got, true) . ']');
}

t_group('data protection — the legal constants and their provenance');

t_eq(MAM_DP_DEADLINES['breach_ndpc_hours'], 72, 'NDPA s.40(2): the NDPC gets 72 hours from awareness');
t_eq(MAM_DP_DEADLINES['snag_ack_days'], 7, 'GAID SNAG: acknowledge within 7 days');
t_eq(MAM_DP_DEADLINES['snag_close_days'], 30, 'GAID SNAG: resolve within 30 days');

$dpSrc = file_get_contents(__DIR__ . '/../lib/dataprotection.php');
t_ok(strpos($dpSrc, 'not statutory') !== false,
    'the response deadline is labelled as the academy\'s own commitment, not the law\'s');
t_ok(strpos($dpSrc, 'Verified 2026-08-17') !== false,
    'the deadline block carries a verified-on date — these are the facts most likely to move');
t_ok(count(MAM_DP_BASES) === 6, 'the six NDPA s.25 lawful bases are enumerated [got ' . count(MAM_DP_BASES) . ', want 6]');

t_group('data protection — the register is derived from the code, not typed in');

$purposes = mam_dp_purposes();
t_ok(count($purposes) >= 8, 'the ROPA covers the system\'s processing [got ' . count($purposes) . ']');

// (1) Every purpose names a real basis, and real tables.
$badBasis = [];
$missingTables = [];
foreach ($purposes as $key => $p) {
    if (!isset(MAM_DP_BASES[$p['basis']])) $badBasis[] = $key;
    foreach ((array) $p['tables'] as $t) {
        if (mam_dp_val("SELECT COUNT(*) FROM {$t}") === null) $missingTables[] = $key . ':' . $t;
    }
}
t_eq(implode(',', $badBasis), '', 'every purpose cites one of the six lawful bases');
t_eq(implode(',', $missingTables), '',
    'every purpose names tables that EXIST — a register pointing at dropped storage has drifted');

// Every purpose points at a retention rule that exists.
$retention = mam_dp_retention();
$danglingRef = [];
foreach ($purposes as $key => $p) {
    if (!isset($retention[(string) $p['retention_ref']])) $danglingRef[] = $key;
}
t_eq(implode(',', $danglingRef), '', 'every purpose points at a retention rule that exists');

t_group('data protection — the retention schedule is checked against the live schema');

$badTable = $badCol = $noBasis = [];
foreach ($retention as $key => $r) {
    if (mam_dp_val("SELECT COUNT(*) FROM {$r['table']}") === null) { $badTable[] = $key; continue; }
    // A date column that does not exist raises, so a successful count proves it.
    if (mam_dp_val("SELECT COUNT(*) FROM {$r['table']} WHERE {$r['date']} IS NOT NULL") === null) $badCol[] = $key;
    if (trim((string) $r['basis']) === '') $noBasis[] = $key;
}
t_eq(implode(',', $badTable), '', 'every retention rule names a real table');
t_eq(implode(',', $badCol), '', 'every retention rule names a real date column');
t_eq(implode(',', $noBasis), '', 'every retention rule states the basis for the period it sets');

// (2) The invariant that protects academic history: only leaf tables are deleted.
$inbound = [];
try {
    $tables = mam_db_all("SELECT name FROM sqlite_master WHERE type = 'table'");
    foreach ($tables as $t) {
        foreach (mam_db_all("PRAGMA foreign_key_list(`" . $t['name'] . "`)") as $fk) {
            $inbound[(string) $fk['table']][] = (string) $t['name'];
        }
    }
} catch (\Throwable $e) { $inbound = []; }

$unsafeDeletes = [];
foreach ($retention as $key => $r) {
    if ($r['action'] !== 'delete') continue;
    if (!empty($inbound[(string) $r['table']])) {
        $unsafeDeletes[] = $key . ' (' . $r['table'] . ' ← ' . implode(',', array_unique($inbound[(string) $r['table']])) . ')';
    }
}
t_eq(implode('; ', $unsafeDeletes), '',
    'action=delete only on LEAF tables — a cascade must never erase academic history to tidy a note');

// The corollary: the records nobody should sweep are on `review`.
foreach (['academic_record', 'financial', 'vetting', 'audit'] as $k) {
    t_eq($retention[$k]['action'], 'review',
        "'$k' is a human decision, never a timed delete [" . $retention[$k]['action'] . ']');
}
$anonNoCols = [];
foreach ($retention as $key => $r) {
    if ($r['action'] === 'anonymise' && empty($r['pii'])) $anonNoCols[] = $key;
}
t_eq(implode(',', $anonNoCols), '', 'an anonymise rule names the columns it nulls, or it does nothing');

t_group('data protection — retention periods may be lengthened, never shortened');

$shipped = mam_dp_keep_days('enquiries');
t_eq($shipped, $retention['enquiries']['days'], 'the shipped period applies with no annotation');
$r = mam_dp_note_save('retention', 'enquiries', ['keep_days' => $shipped + 365, 'notes' => 'Accountant asked for an extra year.'], null);
t_eq($r['keep_days'], $shipped + 365, 'a longer period is accepted');
t_eq(mam_dp_keep_days('enquiries'), $shipped + 365, 'and takes effect');
$r2 = mam_dp_note_save('retention', 'enquiries', ['keep_days' => 1], null);
t_ok($r2['clamped'] === true, 'a SHORTER period is clamped, not applied');
t_eq(mam_dp_keep_days('enquiries'), $shipped, 'so the effective period falls back to the shipped one');
dp_throws(static fn() => mam_dp_note_save('retention', 'no_such_rule', ['keep_days' => 10]), 'unknown_ref',
    'annotating a rule that does not exist is refused');

t_group('data protection — the retention scan reads, and report mode never acts');

$scan = mam_dp_retention_scan();
t_eq(count($scan), count($retention), 'the scan covers every rule');
t_ok($scan['enquiries']['exists'] === true, 'the scan reports table existence');
t_ok(array_key_exists('overdue', $scan['enquiries']), 'and how many rows are past the period');

// Seed a message far older than its retention period and prove report mode
// leaves it exactly where it is.
$oldStamp = date('Y-m-d H:i:s', strtotime('-10 years'));
mam_db_insert('messages', [
    'name' => 'Retention Fixture', 'email' => 'retention-fixture@test.invalid',
    'topic' => 'general', 'message' => 'old enquiry', 'status' => 'closed', 'created_at' => $oldStamp,
]);
$before = (int) mam_dp_val("SELECT COUNT(*) FROM messages WHERE email = ?", ['retention-fixture@test.invalid']);
t_eq($before, 1, 'the fixture enquiry is in place');

mam_setting_set('dp_retention_mode', 'report');
t_eq(mam_dp_retention_mode(), 'report', 'the sweep ships in report mode');
$swept = mam_dp_retention_sweep();
t_ok($swept['applied'] === false, 'report mode does not apply anything');
t_ok($swept['flagged'] > 0, 'but it does count what is overdue [' . $swept['flagged'] . ']');
t_eq((int) mam_dp_val("SELECT COUNT(*) FROM messages WHERE email = ?", ['retention-fixture@test.invalid']), 1,
    'and the overdue row is still there');

// A dry run must not act even with the mode set to enforce — the preview button
// cannot be made destructive by a setting.
mam_setting_set('dp_retention_mode', 'enforce');
$dry = mam_dp_retention_sweep(true);
t_ok($dry['applied'] === false, 'an explicit dry run overrides enforce mode');
t_eq((int) mam_dp_val("SELECT COUNT(*) FROM messages WHERE email = ?", ['retention-fixture@test.invalid']), 1,
    'so the row survives a dry run in enforce mode');

// Now enforce for real.
$live = mam_dp_retention_sweep();
t_ok($live['applied'] === true, 'enforce mode applies');
t_eq((int) mam_dp_val("SELECT COUNT(*) FROM messages WHERE email = ?", ['retention-fixture@test.invalid']), 0,
    'the overdue enquiry is deleted');
// The records on `review` are untouched however overdue they are.
t_ok($live['per_entry']['academic_record']['affected'] === 0,
    'a `review` rule is never applied by the sweep, whatever the mode');
mam_setting_set('dp_retention_mode', 'off');
$off = mam_dp_retention_sweep();
t_ok(!empty($off['skipped']), 'off means the sweep does not run at all');
mam_setting_set('dp_retention_mode', 'report');

t_group('data protection — the request log and its clock');

$reqId = mam_dp_request_open([
    'kind' => 'access', 'subject_email' => 'Subject.One@Test.invalid',
    'received_at' => date('Y-m-d H:i:s', strtotime('-2 days')), 'detail' => 'Asked for a copy by email.',
], null);
t_ok($reqId > 0, 'a request is logged');
$row = mam_db_row("SELECT * FROM dp_requests WHERE id = ?", [$reqId]);
t_eq($row['subject_email'], 'subject.one@test.invalid', 'the subject email is normalised to lower case');

$clock = mam_dp_request_clock($row);
t_eq($clock['state'], 'open', 'two days into a thirty-day commitment reads as open');
t_eq($clock['days_left'], 28, 'with the days remaining derived [' . $clock['days_left'] . ']');
t_ok($clock['ack_due_at'] === null, 'a non-SNAG request carries no separate acknowledgement deadline');

// A request received long ago is overdue on read — derived, not swept.
$oldReq = mam_dp_request_open([
    'kind' => 'erasure', 'subject_email' => 'subject.two@test.invalid',
    'received_at' => date('Y-m-d H:i:s', strtotime('-60 days')),
]);
$oldClock = mam_dp_request_clock(mam_db_row("SELECT * FROM dp_requests WHERE id = ?", [$oldReq]));
t_eq($oldClock['state'], 'overdue', 'a 60-day-old open request reads overdue with no cron run');
t_ok($oldClock['close_late'] === true, 'and is flagged late');

// Closing it late records that it WAS late — but it must not keep drifting.
// Closed 40 days after receipt — past the 30-day commitment, so it is late.
mam_dp_request_close($oldReq, 'fulfilled', ['closed_at' => date('Y-m-d H:i:s', strtotime('-20 days'))]);
$closed = mam_db_row("SELECT * FROM dp_requests WHERE id = ?", [$oldReq]);
$closedClock = mam_dp_request_clock($closed);
t_eq($closedClock['state'], 'closed_late', 'a request closed after the deadline records as closed_late');
t_ok($closedClock['days_left'] === null, 'a closed request has no countdown');
t_ok(!empty($closed['acknowledged_at']), 'answering a request acknowledges it');

// The historic-drift trap: an on-time closure must stay on time forever.
$onTime = mam_dp_request_open([
    'kind' => 'access', 'subject_email' => 'subject.three@test.invalid',
    'received_at' => date('Y-m-d H:i:s', strtotime('-400 days')),
]);
mam_dp_request_close($onTime, 'fulfilled', ['closed_at' => date('Y-m-d H:i:s', strtotime('-395 days'))]);
$otClock = mam_dp_request_clock(mam_db_row("SELECT * FROM dp_requests WHERE id = ?", [$onTime]));
t_eq($otClock['state'], 'closed', 'a request answered in five days is still on time a year later');

// SNAG carries GAID's two deadlines.
$snag = mam_dp_request_open([
    'kind' => 'snag', 'subject_email' => 'subject.four@test.invalid',
    'received_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
]);
$snagClock = mam_dp_request_clock(mam_db_row("SELECT * FROM dp_requests WHERE id = ?", [$snag]));
t_ok($snagClock['ack_due_at'] !== null, 'a SNAG has its own acknowledgement deadline');
t_ok($snagClock['ack_late'] === true, 'unacknowledged after 10 days, the 7-day limit has passed');
t_eq($snagClock['state'], 'open', 'while the 30-day resolution clock still has time');

dp_throws(static fn() => mam_dp_request_open(['kind' => 'nonsense', 'subject_email' => 'a@b.invalid']), 'unknown_kind',
    'an unknown request kind is refused');
dp_throws(static fn() => mam_dp_request_open(['kind' => 'access', 'subject_email' => 'not-an-email']), 'invalid_email',
    'a malformed subject email is refused');
dp_throws(static fn() => mam_dp_request_close($reqId, 'refused', []), 'reason_required',
    'a refusal must say why');

// Acknowledgement is idempotent — the first one stands.
mam_dp_request_acknowledge($reqId);
$ack1 = mam_db_row("SELECT acknowledged_at FROM dp_requests WHERE id = ?", [$reqId])['acknowledged_at'];
mam_dp_request_acknowledge($reqId, null, date('Y-m-d H:i:s', strtotime('+1 day')));
$ack2 = mam_db_row("SELECT acknowledged_at FROM dp_requests WHERE id = ?", [$reqId])['acknowledged_at'];
t_eq($ack2, $ack1, 'a second acknowledgement does not move the first');

t_group('data protection — an access request is fulfilled from the real export');

// The subject must exist for an export to be possible; reuse the suite's admin.
$anyUser = mam_db_row("SELECT email FROM users WHERE email IS NOT NULL AND email <> '' ORDER BY id LIMIT 1");
$exportReq = mam_dp_request_open(['kind' => 'access', 'subject_email' => (string) $anyUser['email']]);
$ful = mam_dp_request_fulfil_access($exportReq);
t_ok(!empty($ful['ok']), 'fulfilling an access request produces the export');
t_ok(is_array($ful['sections']), 'and records WHICH sections it contained');
$after = mam_db_row("SELECT outcome, detail FROM dp_requests WHERE id = ?", [$exportReq]);
t_eq($after['outcome'], 'fulfilled', 'the request closes as fulfilled');
t_ok(strpos((string) $after['detail'], 'Export produced') !== false, 'the log says an export was produced');
t_ok(strpos((string) $after['detail'], '@') === false,
    'and stores COUNTS, not a second copy of the subject\'s data');

$missing = mam_dp_request_open(['kind' => 'access', 'subject_email' => 'nobody-at-all@test.invalid']);
$noData = mam_dp_request_fulfil_access($missing);
t_eq($noData['error'] ?? '', 'no_data', 'an access request for an unknown subject reports no data rather than closing empty');

t_group('data protection — the breach register runs its clock from AWARENESS');

$bid = mam_dp_breach_record([
    'occurred_at'     => date('Y-m-d H:i:s', strtotime('-10 days')),
    'discovered_at'   => date('Y-m-d H:i:s', strtotime('-5 days')),
    'nature'          => 'A resource link was shared with the wrong cohort.',
    'data_categories' => 'Student names, submission titles',
    'risk_level'      => 'medium',
    'subjects_affected' => 4,
]);
t_ok($bid > 0, 'a breach is recorded');
$b = mam_db_row("SELECT * FROM dp_breaches WHERE id = ?", [$bid]);
t_ok(preg_match('/^BR-\d{4}-\d{3}$/', (string) $b['reference']) === 1,
    'it gets a sequential case reference [' . $b['reference'] . ']');

$bc = mam_dp_breach_clock($b);
t_eq($bc['state'], 'overdue', 'five days after DISCOVERY the 72 hours have gone');
t_eq($bc['detection_lag_h'], 120, 'and the five days between occurrence and discovery are surfaced [' . $bc['detection_lag_h'] . 'h]');

// The trap this guards: measuring from occurrence rather than awareness would
// make an instantly-reported breach look late.
$fresh = mam_dp_breach_record([
    'occurred_at'     => date('Y-m-d H:i:s', strtotime('-30 days')),
    'discovered_at'   => date('Y-m-d H:i:s', strtotime('-1 hour')),
    'nature'          => 'Old export found on a shared drive.',
    'data_categories' => 'Names, emails',
    'risk_level'      => 'low',
]);
$fc = mam_dp_breach_clock(mam_db_row("SELECT * FROM dp_breaches WHERE id = ?", [$fresh]));
t_eq($fc['state'], 'open', 'a month-old breach discovered an hour ago is INSIDE its 72 hours');
t_ok($fc['hours_left'] >= 70, 'with nearly the full window left [' . $fc['hours_left'] . 'h]');

mam_dp_breach_notify_ndpc($fresh, null, 'NDPC/2026/TEST');
$fn = mam_db_row("SELECT * FROM dp_breaches WHERE id = ?", [$fresh]);
$fnc = mam_dp_breach_clock($fn);
t_eq($fnc['state'], 'notified', 'notifying inside the window reads as notified, not late');
t_ok($fnc['hours_to_notify'] <= 2, 'and records how long it actually took [' . $fnc['hours_to_notify'] . 'h]');
t_eq($fn['ndpc_reference'], 'NDPC/2026/TEST', 'the Commission\'s reference is kept');

$nc = mam_dp_breach_clock(mam_db_row("SELECT * FROM dp_breaches WHERE id = ?", [$bid]));
mam_dp_breach_notify_ndpc($bid);
$lateC = mam_dp_breach_clock(mam_db_row("SELECT * FROM dp_breaches WHERE id = ?", [$bid]));
t_eq($lateC['state'], 'notified_late', 'notifying after the window is recorded as late, not silently forgiven');

// A high-risk breach cannot be closed while the subjects' position is undecided.
$high = mam_dp_breach_record([
    'discovered_at'   => date('Y-m-d H:i:s', strtotime('-2 hours')),
    'nature'          => 'Guardian contact list exposed.',
    'data_categories' => 'Names, emails, phone numbers',
    'risk_level'      => 'high',
]);
dp_throws(static fn() => mam_dp_breach_close($high), 'subjects_undecided',
    'a high-risk breach cannot be closed until the subjects have been told, or the decision not to recorded');
mam_dp_breach_notify_subjects($high, null, 'Contained within 20 minutes; the list was never opened by the recipient.');
t_ok(mam_dp_breach_close($high, ['lessons' => 'Recipient allow-list on bulk exports.']),
    'recording WHY the subjects were not told lets the breach close');

dp_throws(static fn() => mam_dp_breach_record([
    'occurred_at' => date('Y-m-d H:i:s'), 'discovered_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
    'nature' => 'x', 'data_categories' => 'y',
]), 'occurred_after_discovered', 'a breach cannot be discovered before it happened');
dp_throws(static fn() => mam_dp_breach_record(['nature' => '', 'data_categories' => '']), 'nature_required',
    'a breach record must state what happened and what data was involved');

t_group('data protection — the registration tier is measured, not diarised');

$tier = mam_dp_tier();
t_ok(isset($tier['count'], $tier['tier'], $tier['headroom']), 'the tier is computed from live data');
t_eq($tier['measured']['window_months'], 6, 'over the six-month window the definition uses');

// The tier boundaries themselves.
t_eq(mam_dp_tier_for(0)['key'], 'below', 'nobody = below the DCPMI threshold');
t_eq(mam_dp_tier_for(200)['key'], 'below', '200 subjects is still below (the test is MORE than 200)');
t_eq(mam_dp_tier_for(201)['key'], 'ohl', '201 crosses into Ordinary-High Level');
t_eq(mam_dp_tier_for(999)['key'], 'ohl', 'and stays there below 1,000');
t_eq(mam_dp_tier_for(1001)['key'], 'uhl', 'past 1,000 is Ultra-High Level');
t_eq(mam_dp_tier_for(50000)['key'], 'ehl', 'and the top tier has no ceiling');

t_ok(mam_dp_tier_for(201)['register'] === true, 'a DCPMI must register');
t_ok(mam_dp_tier_for(201)['car'] === false,
    'but Ordinary-High Level does NOT file Compliance Audit Returns — the obligation the research overstated');
t_ok(mam_dp_tier_for(1001)['car'] === true, 'filing starts at Ultra-High Level');
t_ok(mam_dp_tier_for(0)['register'] === false, 'below the threshold there is nothing to register');

// Deduplication across sources is what keeps the count honest.
$dupEmail = 'dedupe-' . substr(md5((string) mt_rand()), 0, 8) . '@test.invalid';
$c0 = mam_dp_subject_count(6)['distinct'];
mam_db_insert('newsletter', ['email' => $dupEmail, 'source' => 'test', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
mam_db_insert('messages', ['name' => 'Dupe', 'email' => $dupEmail, 'topic' => 'general', 'message' => 'hi', 'status' => 'new', 'created_at' => date('Y-m-d H:i:s')]);
$c1 = mam_dp_subject_count(6)['distinct'];
t_eq($c1, $c0 + 1, 'one person appearing in two tables counts ONCE, not twice');

t_group('data protection — the processor register knows what is actually switched on');

$procs = mam_dp_processors();
t_ok(count($procs) >= 7, 'every integration the code can call is in the register [' . count($procs) . ']');
$byKey = [];
foreach ($procs as $p) $byKey[$p['key']] = $p;
foreach (['paystack', 'flutterwave', 'smtp', 'cloudinary', 'google_drive', 'google_meet', 'cloudflare'] as $k) {
    t_ok(isset($byKey[$k]), "the register lists '$k'");
}
t_ok(is_bool($byKey['paystack']['configured']),
    'each entry reports whether it is configured RIGHT NOW — an aspirational register is the commonest kind');
t_ok($byKey['cloudflare']['configured'] === true, 'the CDN that sees every request IP is not omitted');

// A gap is a LIVE processor with no agreement recorded.
t_ok($byKey['cloudflare']['gap'] === true, 'a configured processor with no DPA on file is flagged as a gap');
mam_dp_processor_save('cloudflare', ['dpa_signed_on' => '2026-01-15', 'dpa_reference' => 'CF-DPA-2026', 'country' => 'United States']);
$procs2 = [];
foreach (mam_dp_processors() as $p) $procs2[$p['key']] = $p;
t_ok($procs2['cloudflare']['gap'] === false, 'recording the agreement closes the gap');
t_eq($procs2['cloudflare']['dpa_reference'], 'CF-DPA-2026', 'and the reference is kept');
t_ok($procs2['cloudflare']['recorded'] === true, 'the entry now has a stored row');

// A processor engaged outside the code is still registrable, and shows as unknown.
mam_dp_processor_save('local_print_shop', ['name' => 'Ikeja print shop', 'purpose' => 'Printing ID cards', 'country' => 'Nigeria']);
$procs3 = [];
foreach (mam_dp_processors() as $p) $procs3[$p['key']] = $p;
t_ok(isset($procs3['local_print_shop']), 'a processor the code knows nothing about can still be registered');
t_ok($procs3['local_print_shop']['configured'] === null,
    'and is shown as unknown rather than guessed at');
dp_throws(static fn() => mam_dp_processor_save('bad key!', ['name' => 'x']), 'bad_key', 'a malformed key is refused');

t_group('data protection — impact assessments and their gaps');

$gapsBefore = mam_dp_assessment_gaps();
t_ok(count($gapsBefore) > 0, 'sensitive purposes with no assessment are listed [' . count($gapsBefore) . ']');
t_ok(isset($gapsBefore['safeguarding']) || isset($gapsBefore['identity_cards']),
    'including the ones handling a child\'s data');

$did = mam_dp_assessment_save([
    'title' => 'Identity cards and door scanning',
    'scope' => 'Covers the identity_cards purpose: photographs of children, serials and scan events.',
    'trigger_reason' => 'Processing a child\'s photograph at scale.',
    'residual_risk' => 'low', 'decision' => 'proceed_with_controls',
    'assessed_on' => date('Y-m-d'), 'review_due_on' => date('Y-m-d', strtotime('+1 year')),
]);
t_ok($did > 0, 'a DPIA is recorded');
$gapsAfter = mam_dp_assessment_gaps();
t_ok(!isset($gapsAfter['identity_cards']), 'and the purpose it names stops reading as a gap');
t_ok(count($gapsAfter) < count($gapsBefore), 'so the gap list shrinks');
dp_throws(static fn() => mam_dp_assessment_save(['title' => '', 'scope' => '']), 'title_and_scope_required',
    'a DPIA must at least say what it assessed');

t_group('data protection — the summary and the attention list');

$sum = mam_dp_summary();
foreach (['tier', 'requests_open', 'requests_overdue', 'breaches_open', 'processor_gaps',
          'retention_mode', 'purposes', 'attention'] as $k) {
    t_ok(array_key_exists($k, $sum), "the summary carries '$k'");
}
t_ok(is_array($sum['attention']), 'the attention list is a list');
$levels = array_column($sum['attention'], 'level');
foreach ($levels as $lv) t_ok(in_array($lv, ['critical', 'warn', 'info'], true), "attention level '$lv' is known");

// Ordered by consequence: a breach clock must never sort below an unreviewed
// register entry, whatever order the checks happen to run in.
$rank = ['critical' => 0, 'warn' => 1, 'info' => 2];
$seq = array_map(static fn(array $a): int => $rank[$a['level']], $sum['attention']);
$sorted = $seq; sort($sorted);
t_eq(implode(',', $seq), implode(',', $sorted),
    'the attention list is ordered critical → warn → info');

// And prove it with a breach that IS inside its window: it must lead the list.
mam_dp_breach_record([
    'discovered_at'   => date('Y-m-d H:i:s', strtotime('-1 hour')),
    'nature'          => 'Ordering fixture — a live, unnotified breach.',
    'data_categories' => 'Names',
    'risk_level'      => 'medium',
]);
$sum2 = mam_dp_summary();
t_ok(!empty($sum2['attention']) && $sum2['attention'][0]['level'] === 'critical',
    'a breach still inside its 72 hours leads the list, above every older item');
t_ok(strpos((string) $sum2['attention'][0]['text'], 'Breach ') === 0,
    'and the leading item names the breach [' . ($sum2['attention'][0]['text'] ?? '') . ']');

t_group('data protection — the admin surface and the wiring');

$adminSrc = file_get_contents(__DIR__ . '/../../admin/index.php');
t_ok(strpos($adminSrc, "v === 'dataprotection'") !== false || strpos($adminSrc, "'dataprotection'") !== false,
    'the admin has a data-protection view');
foreach (['dp_request_open', 'dp_request_close', 'dp_breach_record', 'dp_processor_save', 'dp_note_save'] as $act) {
    t_ok(strpos($adminSrc, "'$act'") !== false, "the admin wires the '$act' action");
}
t_ok(strpos($adminSrc, 'mam_admin_redirect') === false,
    'no invented redirect helper — this file has none (gotcha #9)');

t_ok(in_array('dataprotection', require __DIR__ . '/../lib/libs.php', true), 'the lib is in the one library list (gotcha #1)');
$jobsSrc = file_get_contents(__DIR__ . '/../tools/run-jobs.php');
t_ok(strpos($jobsSrc, 'mam_dp_retention_sweep') !== false, 'the retention sweep is wired into the cron');
t_ok(strpos($jobsSrc, "mam_automation_on('data_retention')") !== false,
    'and is gated by its automation as well as by the mode');
t_ok(isset(mam_automations_registry()['data_retention']), 'the automation is registered');
