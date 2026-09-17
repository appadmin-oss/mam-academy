<?php
/** Faculty invites — approval-gated, single-use, 7-day expiry — + form validation. */
declare(strict_types=1);
t_group('faculty invites & applications');

// ── Request: queued, nothing invited yet ──
$rid = mam_faculty_request('Prof.Keys@Example.com', 'Prof Keys', 'contact');
t_ok($rid > 0, 'teaching request queued');
$row = mam_db_row('SELECT * FROM faculty_invites WHERE id = ?', [$rid]);
t_eq($row['status'], 'requested', 'request awaits approval');
t_ok(empty($row['nonce']), 'no link exists before approval');
t_eq(mam_faculty_request('prof.keys@example.com'), $rid, 'repeat interest reuses the open request');
t_ok(count(mam_faculty_pending_requests()) >= 1, 'request appears in the admin queue');

// ── Approve: single-use nonce + 7-day expiry ──
$inv = mam_faculty_approve($rid, null);
t_eq($inv['status'], 'invited', 'approval marks invited');
t_ok(!empty($inv['nonce']), 'approval mints the nonce');
$expDays = (strtotime((string)$inv['expires_at']) - time()) / 86400;
t_ok($expDays > 6.9 && $expDays <= 7.05, 'invite expires in 7 days');

// ── Token: authentic + single-use ──
$url = mam_faculty_apply_url($inv);
parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
$tok = (string)$q['token'];
$live = mam_faculty_verify_token($tok);
t_ok(is_array($live) && $live['email'] === 'prof.keys@example.com', 'token resolves the invite');
t_eq(mam_faculty_verify_token('garbage'), 'invalid', 'garbage token invalid');
t_eq(mam_faculty_verify_token(substr($tok, 0, -4) . 'AAAA'), 'invalid', 'tampered token invalid');

// Re-approving re-issues: the OLD link stops working.
$inv2 = mam_faculty_approve($rid, null);
t_eq(mam_faculty_verify_token($tok), 'invalid', 're-approval revokes the previous link');
$url2 = mam_faculty_apply_url($inv2);
parse_str((string)parse_url($url2, PHP_URL_QUERY), $q2);
$tok2 = (string)$q2['token'];
t_ok(is_array(mam_faculty_verify_token($tok2)), 'fresh link works');

// Expiry: force the clock past it.
mam_db_update('faculty_invites', ['expires_at' => date('Y-m-d H:i:s', time() - 60)], ['id' => $rid]);
t_eq(mam_faculty_verify_token($tok2), 'expired', 'expired invite rejected');
mam_db_update('faculty_invites', ['expires_at' => date('Y-m-d H:i:s', time() + 3600)], ['id' => $rid]);

// ── Submit consumes: the link is never reusable ──
$good = mam_faculty_validate([
    'full_name' => 'Prof Keys', 'phone' => '+2348000000', 'disciplines' => 'Piano, Theory',
    'experience_years' => 12, 'bio' => str_repeat('Conservatory-trained pianist and educator. ', 3),
    'links' => 'https://x.com/profkeys', 'cv_url' => 'https://example.com/cv.pdf',
]);
t_eq($good['errors'], [], 'complete application validates');
$appId = mam_faculty_application_create('prof.keys@example.com', $good['data'], '10.0.0.1');
t_ok($appId > 0, 'application stored');
mam_faculty_consume($rid);
t_eq(mam_faculty_verify_token($tok2), 'used', 'consumed invite reports used — single-use enforced');
t_ok(mam_faculty_approve($rid, null) === null, 'a used invite cannot be re-approved');

// ── Dismiss path ──
$rid2 = mam_faculty_request('other@example.com', 'Other', 'contact');
mam_faculty_dismiss($rid2, null);
t_eq(mam_db_row('SELECT status FROM faculty_invites WHERE id = ?', [$rid2])['status'], 'dismissed', 'dismissed request closed without email');

// ── Validation edges ──
$bad = mam_faculty_validate(['full_name' => '', 'disciplines' => '', 'bio' => 'too short']);
t_ok(in_array('full_name', $bad['errors'], true) && in_array('bio', $bad['errors'], true), 'empty/short fields rejected');
$badUrl = mam_faculty_validate(['full_name' => 'A B', 'disciplines' => 'Piano', 'bio' => str_repeat('Teaching experience across levels. ', 3), 'headshot_url' => 'javascript:alert(1)']);
t_ok(in_array('headshot_url', $badUrl['errors'], true), 'non-https headshot URL rejected');

/* ═══════════════════════════════════════════════════════════════════════════
 * v90 — the application as an assessment: the teaching matrix, referees, and
 * the refusals that make it safe to collect any of it.
 * ═══════════════════════════════════════════════════════════════════════════ */
t_group('faculty application — teaching matrix & safer recruitment');

// ── The catalogue is the LIVE one, never a hardcoded list ──
$cat = mam_faculty_catalogue();
t_ok(count($cat['courses']) > 0, 'catalogue offers the academy\'s real courses');
t_ok(count($cat['levels']) > 0, 'catalogue offers the real levels');
$codes = array_column($cat['courses'], 'code');
t_ok(in_array('piano', $codes, true), 'piano is offerable');
t_eq($cat['modes'], ['onsite', 'online', 'hybrid'], 'modes match class_sessions.mode');

// ── The matrix resolves against the catalogue, and REFUSES to guess ──
$subs = mam_faculty_normalise_subjects([
    ['course' => 'piano',   'level' => 'diploma', 'years' => 9],
    ['course' => 'theory',  'level' => 'cert'],
    ['course' => 'violin',  'level' => 'cert'],          // not a course here — dropped
    ['course' => 'piano',   'level' => 'diploma'],       // duplicate (course, level)
    ['course' => 'voice',   'level' => 'nonsense'],      // unknown level → level-less, kept
]);
t_eq(count($subs), 3, 'unknown course dropped, duplicate collapsed');
$byCourse = []; foreach ($subs as $s) $byCourse[$s['course_code']] = $s;
t_ok(!isset($byCourse['violin']), 'an instrument that is not a course is never invented');
t_eq($byCourse['piano']['years'], 9, 'years carried');
t_ok($byCourse['piano']['level_id'] > 0, 'level resolved to an id the assignment table can use');
t_eq($byCourse['voice']['level_id'], null, 'unknown level degrades to level-less, not to a guess');

// ── Referees are CONTACTS ONLY (gotcha #10) ──
$refs = mam_faculty_normalise_referees([
    ['name' => 'Dr Ada Bell', 'relationship' => 'Head of Music', 'organisation' => 'Lagos Conservatory', 'email' => 'Ada@Example.com', 'phone' => '+2348000001'],
    ['name' => 'Mr Tayo Ade', 'relationship' => 'Former principal', 'email' => 'not-an-email'],
    ['name' => ''],                                       // nameless → dropped
]);
t_eq(count($refs), 2, 'nameless referee dropped');
t_eq($refs[0]['email'], 'ada@example.com', 'referee email normalised');
t_eq($refs[1]['email'], null, 'an invalid referee email is stored as nothing, never as text');

// The schema must have nowhere to put check CONTENT. A certificate number or an
// offence in this table is the liability vetting.php exists to avoid.
$refCols = array_map(fn($c) => strtolower((string)($c['name'] ?? $c['Field'] ?? '')),
    mam_db_all(mam_db_driver() === 'mysql' ? 'SHOW COLUMNS FROM instructor_application_referees'
                                           : "PRAGMA table_info('instructor_application_referees')"));
foreach (['certificate', 'certificate_no', 'offence', 'conviction', 'dbs_number', 'outcome', 'verdict'] as $forbidden) {
    t_ok(!in_array($forbidden, $refCols, true), "referees table has no '$forbidden' column");
}

// ── Validation: the matrix wins, and both commitments are required ──
$full = [
    'full_name' => 'Ada Keys', 'email' => 'ada.keys@example.com',
    'disciplines' => 'ignored when a matrix is present',
    'bio' => str_repeat('Twelve years teaching piano and theory. ', 3),
    'subjects' => [['course' => 'piano', 'level' => 'diploma', 'years' => 12], ['course' => 'theory', 'level' => 'cert']],
    'referees' => [['name' => 'Dr Ada Bell', 'email' => 'bell@example.com'], ['name' => 'Mr Tayo Ade', 'email' => 'tayo@example.com']],
    'experience_years' => 12, 'qualifications' => 'BMus, Trinity ATCL',
    'teaching_modes' => ['online', 'onsite', 'onsite', 'teleport'],
    'programmes' => ['standard', 'certified', 'not-a-programme'],
    'availability' => ['mon:morning', 'mon:morning', 'sat:evening', 'funday:midnight'],
    'weekly_hours' => 14, 'earliest_start' => '2026-10-01', 'location' => 'Lagos',
    'safeguarding_ack' => 1, 'policy_ack' => 1,
];
$v = mam_faculty_validate_full($full);
t_eq($v['errors'], [], 'a complete application validates');
t_ok(str_contains($v['data']['disciplines'], 'Piano'), 'disciplines DERIVED from the matrix');
t_ok(str_contains($v['data']['disciplines'], 'Diploma'), 'derived summary names the level');
t_eq(json_decode((string)$v['data']['teaching_modes'], true), ['online', 'onsite'], 'modes deduped, unknown dropped');
t_eq(json_decode((string)$v['data']['programmes'], true), ['standard', 'certified'], 'unknown programme dropped');
t_eq(json_decode((string)$v['data']['availability'], true), ['mon:morning', 'sat:evening'], 'availability deduped + vetted');

// The commitments are not optional — an academy teaching children one-to-one
// cannot take an application from somebody unwilling to state them.
$noAck = mam_faculty_validate_full(['safeguarding_ack' => 0, 'policy_ack' => 0] + $full);
t_ok(in_array('safeguarding_ack', $noAck['errors'], true), 'safeguarding commitment required');
t_ok(in_array('policy_ack', $noAck['errors'], true), 'policy commitment required');

$thin = mam_faculty_validate_full(['subjects' => [], 'referees' => []] + $full);
t_ok(in_array('subjects', $thin['errors'], true), 'an application with nothing to teach is refused');
t_ok(in_array('referees', $thin['errors'], true), 'two referees are required');

$badDate = mam_faculty_validate_full(['earliest_start' => '01/10/2026'] + $full);
t_ok(in_array('earliest_start', $badDate['errors'], true), 'a non-ISO start date is refused, not reinterpreted');

// ── Persistence: matrix + referees stored, and REPLACED on resubmit ──
$aid = mam_faculty_application_save('ada.keys@example.com', $v, '10.0.0.9');
t_ok($aid > 0, 'application stored');
$det = mam_faculty_application_detail($aid);
t_eq(count($det['subjects']), 2, 'matrix persisted');
t_eq(count($det['referees']), 2, 'referees persisted');
// Ordered by courses.sort_order (core before elective), not by submission order.
$detNames = array_column($det['subjects'], 'course_name');
sort($detNames);
t_eq($detNames, ['Piano', 'Theory of Music'], 'matrix joins back to the real courses');

$v2 = mam_faculty_validate_full(['subjects' => [['course' => 'guitar', 'level' => 'cert']]] + $full);
$aid2 = mam_faculty_application_save('ada.keys@example.com', $v2, '10.0.0.9');
t_eq($aid2, $aid, 'one live application per email — resubmit updates');
$det2 = mam_faculty_application_detail($aid);
t_eq(count($det2['subjects']), 1, 'resubmit REPLACES the matrix, never appends');
t_eq($det2['subjects'][0]['course_code'], 'guitar', 'the corrected matrix is what survives');
t_eq(count($det2['referees']), 2, 'referees replaced, not duplicated');

// ── Readiness is advisory triage, never a gate ──
t_eq(mam_faculty_readiness(['experience_years' => 12, 'qualifications' => 'BMus'], $v['subjects'], $v['referees']),
    'ready', 'experienced, qualified, two referees → ready');
t_eq(mam_faculty_readiness(['experience_years' => 0], $v['subjects'], $v['referees']),
    'developing', 'a newer teacher is "developing", never rejected');
t_eq(mam_faculty_readiness(['experience_years' => 12], $v['subjects'], []),
    'review', 'no referees → a human looks sooner');
t_ok(in_array($det2['readiness_signal'], MAM_FACULTY_READINESS, true), 'stored signal is one of the bands');

// ── The open/invite switch ──
mam_setting_set('faculty_open_applications', '0');
t_ok(!mam_faculty_open_applications(), 'invite-only restores the v36 flow');
mam_setting_set('faculty_open_applications', '1');
t_ok(mam_faculty_open_applications(), 'open applications can be published');

/* ═══════════════════════════════════════════════════════════════════════════
 * Which address an application is filed under, and accepting a matrix.
 * ═══════════════════════════════════════════════════════════════════════════ */
t_group('faculty — the applicant address is the INVITE\'s, never the body\'s');

// This is the most security-relevant line in the flow. An invite is a
// single-use credential issued TO one address; if the body could override it,
// the holder of one link could file as somebody else — and because
// application_create() keys on the email, overwrite a real candidate's live
// application.
$FA_inv = ['id' => 999, 'email' => 'invited@example.com'];
t_eq(mam_faculty_applicant_email($FA_inv, ['email' => 'attacker@evil.test']), 'invited@example.com',
    'a body email CANNOT override the invited address');
t_eq(mam_faculty_applicant_email(['id' => 1, 'email' => 'MiXeD@Example.COM'], []), 'mixed@example.com',
    'the invited address is normalised');

mam_setting_set('faculty_open_applications', '1');
t_eq(mam_faculty_applicant_email(null, ['email' => ' Open.Applicant@Example.com ']), 'open.applicant@example.com',
    'an open application uses its own normalised address');
t_throws(fn() => mam_faculty_applicant_email(null, ['email' => 'not-an-email']), 'mam_fail:validation:422',
    'an unparseable address is refused rather than stored');

mam_setting_set('faculty_open_applications', '0');
t_throws(fn() => mam_faculty_applicant_email(null, ['email' => 'someone@example.com']), 'mam_fail:invite_required:403',
    'with open applications off, no invite means no application');
t_eq(mam_faculty_applicant_email($FA_inv, []), 'invited@example.com',
    '…but an invite still works when applications are closed');
mam_setting_set('faculty_open_applications', '1');

t_group('faculty — accepting a matrix into instructor_assignments');

$FA_v = mam_faculty_validate_full([
    'full_name' => 'Assign Me', 'bio' => str_repeat('Teaching piano and guitar for years. ', 3),
    'subjects' => [['course' => 'piano', 'level' => 'diploma'], ['course' => 'guitar', 'level' => null]],
    'referees' => [['name' => 'Ref One'], ['name' => 'Ref Two']],
    'safeguarding_ack' => 1, 'policy_ack' => 1,
]);
t_eq($FA_v['errors'], [], 'the fixture application is valid');
$FA_id = mam_faculty_application_save('assign.me@example.com', $FA_v, null);

// Refusals first: none of these may quietly half-succeed.
t_throws(fn() => mam_faculty_assign_subjects($FA_id), 'not_approved',
    'an application that is not approved cannot be assigned');
mam_db_update('instructor_applications', ['status' => 'approved'], ['id' => $FA_id]);
t_throws(fn() => mam_faculty_assign_subjects($FA_id), 'no_account',
    'without an account there is nobody to assign to');

$FA_uid = mam_db_insert('users', ['name' => 'Assign Me', 'email' => 'assign.me@example.com',
    'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
t_throws(fn() => mam_faculty_assign_subjects($FA_id), 'not_an_instructor',
    'a non-instructor account is refused — assignment is not a way to grant a role');
mam_db_update('users', ['role' => 'instructor'], ['id' => $FA_uid]);

$FA_r1 = mam_faculty_assign_subjects($FA_id, null);
t_eq($FA_r1['added'], 2, 'both claimed subjects assigned');
t_eq($FA_r1['already'], 0, 'nothing was already there');
t_eq((int) mam_db_row('SELECT COUNT(*) c FROM instructor_assignments WHERE instructor_id = ?', [$FA_uid])['c'], 2,
    'the assignment rows exist');

// Idempotent, INCLUDING the level-less row: SQLite treats NULLs as distinct so
// the UNIQUE index cannot carry that case — it is checked in PHP.
$FA_r2 = mam_faculty_assign_subjects($FA_id, null);
t_eq($FA_r2['added'], 0, 'a second run adds nothing');
t_eq($FA_r2['already'], 2, '…and reports both as already present');
t_eq((int) mam_db_row('SELECT COUNT(*) c FROM instructor_assignments WHERE instructor_id = ? AND level_id IS NULL', [$FA_uid])['c'], 1,
    'the level-less assignment was NOT duplicated');

// The harness stubs mam_audit() to a no-op (run.php), so the row cannot be
// observed here — assert the CALL instead, which is the thing that would be
// lost in a refactor.
t_ok(str_contains(file_get_contents(dirname(__DIR__) . '/lib/faculty.php'), "mam_audit('faculty_assign_subjects'"),
    'the acceptance is audited');

// mam_fail() is mam_json() — it EMITS AND EXITS. admin/index.php is an HTML
// page, so a mam_fail() in this path would print JSON into the admin screen
// and the caller's try/catch would never run. The harness stubs mam_fail() to
// throw, which is exactly why that bug passed its tests once already.
$FA_src = file_get_contents(dirname(__DIR__) . '/lib/faculty.php');
$FA_fn = substr($FA_src, strpos($FA_src, 'function mam_faculty_assign_subjects'));
$FA_fn = substr($FA_fn, 0, strpos($FA_fn, "\nfunction ") ?: strlen($FA_fn));
t_ok(!str_contains($FA_fn, 'mam_fail('),
    'mam_faculty_assign_subjects() never calls mam_fail() — its caller is an HTML page');
