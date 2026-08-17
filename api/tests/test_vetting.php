<?php
/**
 * Instructor vetting and the safeguarding gate (api/lib/vetting.php).
 *
 * This is the highest-consequence control in the system: the academy teaches
 * children one-to-one. Until now nothing recorded staff clearance and nothing
 * checked it, which is the gap Child Rights Act 2003 s.2(2) ("suitability of
 * their staff") and the Lagos State Safeguarding and Child Protection Policy
 * 2016 both speak to.
 *
 * The tests that matter most are the negative ones — what the gate REFUSES, and
 * what the schema refuses to store.
 */
declare(strict_types=1);

t_group('vetting — the required set is fixed, not configurable');

/* If an academy could shorten the required list, the gate would certify nothing,
   and shortening the list is the first thing anyone does under time pressure. */
foreach (['reference_1', 'reference_2', 'policy_commitment', 'affidavit_good',
          'identity', 'safeguarding_training', 'sex_offender_lagos'] as $need) {
    t_ok(in_array($need, MAM_VETTING_REQUIRED, true), "the Lagos safer-recruitment set includes $need");
}
t_eq(count(MAM_VETTING_REQUIRED), 7, 'seven required clearances');
foreach (MAM_VETTING_REQUIRED as $need) {
    t_ok(isset(MAM_VETTING_TYPES[$need]), "$need is a known clearance type");
}
// Two references, not one — the Lagos policy is explicit about the minimum.
t_ok(in_array('reference_1', MAM_VETTING_REQUIRED, true) && in_array('reference_2', MAM_VETTING_REQUIRED, true),
    'TWO written references are required, matching the Lagos mandate');

t_group('vetting — a clearance is recorded without its contents');

$VT_admin = mam_db_insert('users', ['name' => 'VT Admin', 'email' => 'vt_admin@test.local', 'role' => 'admin', 'password_hash' => 'x', 'status' => 'active']);
$VT_ins   = mam_db_insert('users', ['name' => 'VT Ins', 'email' => 'vt_ins@test.local', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);

$vid = mam_vetting_record($VT_ins, 'identity', $VT_admin, '2026-01-10', null, 'NIMC', 'NIN-****1234', 'Staff file A/12');
t_ok($vid > 0, 'a clearance is recorded');
$cols = array_map(static fn($c) => $c['name'], mam_db_all('PRAGMA table_info(vetting_records)'));
/* The schema must have NO room for the content of a check. Under the NDPA this is
   sensitive personal data about the instructor, and a police certificate scan in
   a shared-hosting database is a liability rather than a control — a reference
   number and a filing location prove diligence just as well. */
foreach (['content', 'offences', 'offence_data', 'certificate', 'scan', 'document', 'notes', 'result'] as $forbidden) {
    t_ok(!in_array($forbidden, $cols, true), "vetting_records has no `$forbidden` column — check contents stay offline");
}
t_ok(in_array('evidence_held', $cols, true), 'it stores a POINTER to where the evidence is filed');
t_ok(in_array('reference_no', $cols, true), '…and the reference number');
// Validity is derived, never stored, so a clearance lapsing at midnight reads as
// expired on the next page load rather than the next cron run.
t_ok(!in_array('status', $cols, true), 'there is no stored status column — validity is derived from expires_on');

t_group('vetting — expiry is derived and dated correctly');

$rows = mam_vetting_for($VT_ins);
t_eq(count($rows), 1, 'the record is returned');
t_eq($rows[0]['state'], 'valid', 'an identity check with no expiry is valid indefinitely');
t_ok($rows[0]['expires_on'] === null, '…and carries no expiry date');

// A type with a default period gets one computed.
mam_vetting_record($VT_ins, 'safeguarding_training', $VT_admin, '2026-01-31');
$tr = array_values(array_filter(mam_vetting_for($VT_ins), fn($r) => $r['clearance_type'] === 'safeguarding_training'))[0];
t_ok($tr['expires_on'] !== null, 'a training record gets a default expiry');
// Month arithmetic must clamp: +24 months from the 31st must not skip a month.
t_eq(substr((string) $tr['expires_on'], 0, 7), '2028-01', '…24 months on, still in January (no month-skip from the 31st)');

// A lapsed clearance reads as expired.
mam_vetting_record($VT_ins, 'sex_offender_lagos', $VT_admin, '2020-01-01', '2021-01-01');
$so = array_values(array_filter(mam_vetting_for($VT_ins), fn($r) => $r['clearance_type'] === 'sex_offender_lagos'))[0];
t_eq($so['state'], 'expired', 'a clearance past its expiry reads as expired');
t_ok((int) $so['days_left'] < 0, '…with a negative days-left');

// An expiry before the issue date is a typo, and accepting it would make the
// instructor permanently un-clearable with nothing on screen to explain why.
$threw = false;
try { mam_vetting_record($VT_ins, 'trcn', $VT_admin, '2026-06-01', '2026-01-01'); }
catch (InvalidArgumentException $e) { $threw = ($e->getMessage() === 'expiry_before_issue'); }
t_ok($threw, 'an expiry before the issue date is refused as a data-entry slip');

t_group('vetting — re-running a check replaces, never accumulates');

$before = count(mam_vetting_for($VT_ins));
mam_vetting_record($VT_ins, 'identity', $VT_admin, '2026-08-01', null, 'NIMC', 'NIN-****9999', 'Staff file A/13');
t_eq(count(mam_vetting_for($VT_ins)), $before, 're-recording the same type does not add a row');
$idr = array_values(array_filter(mam_vetting_for($VT_ins), fn($r) => $r['clearance_type'] === 'identity'))[0];
t_eq((string) $idr['issued_on'], '2026-08-01', '…it replaces the dates');
t_eq((string) $idr['reference_no'], 'NIN-****9999', '…and the reference');

t_group('vetting — the gate refuses an uncleared instructor');

$gate = mam_vetting_may_teach_minors($VT_ins);
t_ok($gate['ok'] === false, 'an instructor missing most of the set is not cleared');
// Missing and expired need DIFFERENT actions (obtain one vs renew one), and an
// admin chasing the wrong one wastes days.
t_ok(in_array('sex_offender_lagos', $gate['expired'], true), 'a lapsed check is reported as expired');
t_ok(in_array('reference_1', $gate['missing'], true), '…and one never obtained as missing');
t_ok(!in_array('sex_offender_lagos', $gate['missing'], true), 'the two lists do not overlap');
t_ok(isset($gate['satisfied']['identity']), 'what IS satisfied is reported too');
t_ok(strpos(mam_vetting_explain($gate), 'Not cleared') === 0, 'the explanation leads with the verdict');

// Complete the set.
foreach (['reference_1', 'reference_2', 'policy_commitment', 'affidavit_good'] as $t) {
    mam_vetting_record($VT_ins, $t, $VT_admin, '2026-08-01');
}
mam_vetting_record($VT_ins, 'sex_offender_lagos', $VT_admin, date('Y-m-d'));
$gate2 = mam_vetting_may_teach_minors($VT_ins);
t_ok($gate2['ok'] === true, 'with every required clearance current, the instructor is cleared');
t_eq(count($gate2['missing']), 0, 'nothing missing');
t_eq(count($gate2['expired']), 0, 'nothing expired');

t_group('vetting — a jurisdiction that cannot issue a Lagos check is not stranded');

/* A UK-resident instructor cannot obtain a Lagos Sex Offenders Register search.
   An Enhanced DBS with the Children's Barred List is the stronger equivalent, so
   requiring the literal Lagos check would exclude them for a reason unrelated to
   suitability. */
$VT_uk = mam_db_insert('users', ['name' => 'VT UK', 'email' => 'vt_uk@test.local', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
foreach (['reference_1', 'reference_2', 'policy_commitment', 'affidavit_good', 'identity', 'safeguarding_training'] as $t) {
    mam_vetting_record($VT_uk, $t, $VT_admin, date('Y-m-d'));
}
t_ok(mam_vetting_may_teach_minors($VT_uk)['ok'] === false, 'without any criminal-record check they are not cleared');
mam_vetting_record($VT_uk, 'dbs_enhanced', $VT_admin, date('Y-m-d'), null, 'UK DBS', 'DBS-****4321');
$ukGate = mam_vetting_may_teach_minors($VT_uk);
t_ok($ukGate['ok'] === true, 'an Enhanced DBS satisfies the criminal-record limb');
t_eq($ukGate['satisfied']['sex_offender_lagos']['by'], 'dbs_enhanced', '…and the payload says which check met it');

t_group('vetting — the gate has three modes and ships in `warn`');

t_eq(mam_vetting_mode(), 'warn', 'the shipping default is `warn`, not `enforce`');
/* Deliberate: an install with no records yet would refuse every assignment on the
   next click in `enforce`, and the reliable outcome of safeguarding theatre is
   somebody switching the whole feature off to get through the day. `warn`
   records and surfaces; the admin loads what they already hold; then they enforce. */

$VT_stu = mam_db_insert('users', ['name' => 'VT Kid', 'email' => 'vt_kid@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
// DOB lives on `customers`; program_track on `applications`.
$VT_cust = mam_db_insert('customers', ['first_name' => 'VT', 'last_name' => 'Kid', 'email' => 'vt_kid@test.local', 'phone' => '+2348000000009', 'dob' => '2015-05-05']);
mam_db_insert('applications', ['customer_id' => $VT_cust, 'reference' => 'VT-KID-1', 'status' => 'enrolled',
    'discipline' => 'piano', 'pathway' => 'standard', 'program_track' => 'KTAMS', 'total_usd' => 0]);
t_ok(mam_vetting_student_is_minor($VT_stu) === true, 'a 2015-born student is recognised as a minor');

$VT_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
$VT_coh = mam_db_insert('cohorts', ['course_id' => $VT_course, 'title' => 'VT Cohort', 'status' => 'active', 'capacity' => 10]);
mam_db_insert('course_enrollments', ['student_id' => $VT_stu, 'course_id' => $VT_course, 'cohort_id' => $VT_coh, 'status' => 'active']);
t_ok(mam_vetting_cohort_has_minor($VT_coh) === true, 'the cohort is recognised as containing a minor');

$VT_bad = mam_db_insert('users', ['name' => 'VT Bad', 'email' => 'vt_bad@test.local', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);

// warn: proceeds, but reports.
mam_setting_set('safeguarding_gate_mode', 'warn');
$w = mam_vetting_check_assignment($VT_bad, $VT_coh);
t_ok($w['allow'] === true, 'in `warn` an uncleared assignment proceeds');
t_ok($w['reason'] !== '', '…but a reason is returned for the caller to surface');

// enforce: refuses.
mam_setting_set('safeguarding_gate_mode', 'enforce');
$e = mam_vetting_check_assignment($VT_bad, $VT_coh);
t_ok($e['allow'] === false, 'in `enforce` it is refused');
t_ok($e['gate']['blocking'] === true, '…and the gate says it is blocking');

// A cleared instructor passes in enforce.
$c = mam_vetting_check_assignment($VT_ins, $VT_coh);
t_ok($c['allow'] === true, 'a cleared instructor is allowed in `enforce`');

// off: the gate is inert, for a non-teaching install.
mam_setting_set('safeguarding_gate_mode', 'off');
t_ok(mam_vetting_check_assignment($VT_bad, $VT_coh)['allow'] === true, 'in `off` nothing is checked');

t_group('vetting — an adult-only cohort is not gated');

/* The gate exists to protect children. Applying it to an adult class would train
   admins to see it as noise, which is how a real control gets ignored. */
mam_setting_set('safeguarding_gate_mode', 'enforce');
mam_setting_set('safeguarding_unknown_age_is_minor', '0');
$VT_adultStu = mam_db_insert('users', ['name' => 'VT Adult', 'email' => 'vt_adult@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$VT_adultCust = mam_db_insert('customers', ['first_name' => 'VT', 'last_name' => 'Adult', 'email' => 'vt_adult@test.local', 'phone' => '+2348000000010', 'dob' => '1990-01-01']);
mam_db_insert('applications', ['customer_id' => $VT_adultCust, 'reference' => 'VT-AD-1', 'status' => 'enrolled',
    'discipline' => 'piano', 'pathway' => 'standard', 'total_usd' => 0]);
$VT_adultCoh = mam_db_insert('cohorts', ['course_id' => $VT_course, 'title' => 'VT Adults', 'status' => 'active', 'capacity' => 10]);
mam_db_insert('course_enrollments', ['student_id' => $VT_adultStu, 'course_id' => $VT_course, 'cohort_id' => $VT_adultCoh, 'status' => 'active']);
t_ok(mam_vetting_student_is_minor($VT_adultStu) === false, 'a 1990-born student is not a minor');
t_ok(mam_vetting_cohort_has_minor($VT_adultCoh) === false, 'an adult-only cohort contains no minor');
t_ok(mam_vetting_check_assignment($VT_bad, $VT_adultCoh)['allow'] === true,
    'an uncleared instructor may teach adults even in `enforce`');

t_group('vetting — unknown age errs toward protection');

/* An academy that teaches children and cannot tell whether a given student is one
   should err toward protection. Because the gate ships in `warn`, erring that way
   costs a visible notice rather than a blocked lesson. */
mam_setting_set('safeguarding_unknown_age_is_minor', '1');
$VT_unknownStu = mam_db_insert('users', ['name' => 'VT Unknown', 'email' => 'vt_unknown@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$VT_unkCoh = mam_db_insert('cohorts', ['course_id' => $VT_course, 'title' => 'VT Unknown', 'status' => 'active', 'capacity' => 10]);
mam_db_insert('course_enrollments', ['student_id' => $VT_unknownStu, 'course_id' => $VT_course, 'cohort_id' => $VT_unkCoh, 'status' => 'active']);
t_ok(mam_vetting_student_is_minor($VT_unknownStu) === null, 'a student with no DOB on file is age-unknown');
t_ok(mam_vetting_cohort_has_minor($VT_unkCoh) === true, '…and counts as a minor while the safe default is on');
mam_setting_set('safeguarding_unknown_age_is_minor', '0');
t_ok(mam_vetting_cohort_has_minor($VT_unkCoh) === false, '…and does not when an academy turns that off');
mam_setting_set('safeguarding_unknown_age_is_minor', '1');

t_group('vetting — the gate is wired into the real assignment path');

/* A gate nobody calls is documentation. mam_lms_approve() is the choke point for
   "who teaches this child", reached from the admin screen, the API routes AND
   payment settlement. */
mam_setting_set('safeguarding_gate_mode', 'enforce');
$VT_ce = mam_db_insert('course_enrollments', ['student_id' => $VT_stu, 'course_id' => $VT_course, 'cohort_id' => $VT_coh, 'status' => 'pending']);
$blocked = false;
try { mam_lms_approve($VT_ce, $VT_bad, $VT_admin); }
catch (RuntimeException $ex) { $blocked = strpos($ex->getMessage(), 'vetting_required') === 0; }
t_ok($blocked, 'approving an uncleared instructor onto a minor\'s enrolment throws in `enforce`');
$after = mam_db_row('SELECT instructor_id FROM course_enrollments WHERE id = ?', [$VT_ce]);
t_ok(empty($after['instructor_id']), '…and no instructor was written');

// The cleared one goes through.
mam_lms_approve($VT_ce, $VT_ins, $VT_admin);
t_eq((int) mam_db_row('SELECT instructor_id FROM course_enrollments WHERE id = ?', [$VT_ce])['instructor_id'], $VT_ins,
    'a cleared instructor is assigned normally');

mam_setting_set('safeguarding_gate_mode', 'warn');   // restore the default

t_group('vetting — the officer\'s worklist');

$att = mam_vetting_attention(500);
$badRow = array_values(array_filter($att, fn($a) => $a['instructor_id'] === $VT_bad));
t_eq(count($badRow), 1, 'an instructor with no records at all appears on the worklist');
t_eq($badRow[0]['severity'], 3, '…at the highest severity, because an empty file is the worst state');
t_ok(count(array_filter($att, fn($a) => $a['instructor_id'] === $VT_ins)) === 0,
    'a fully cleared instructor does not clutter the list');

$sum = mam_vetting_summary();
t_ok($sum['instructors'] >= 3, 'the summary counts active instructors');
t_ok($sum['needs_action'] >= 1, '…and how many need action');
t_eq($sum['mode'], 'warn', '…and reports the gate mode');
// The Lagos policy requires a NAMED officer. Unset is itself a finding, because
// "we have a policy" with nobody responsible is the commonest way it is missed.
t_ok(array_key_exists('dso', $sum), 'the summary reports the Designated Safeguarding Officer');
mam_setting_set('safeguarding_officer_user_id', (string) $VT_admin);
t_eq((int) (mam_vetting_dso()['id'] ?? 0), $VT_admin, 'a named officer resolves');
mam_setting_set('safeguarding_officer_user_id', '0');
t_ok(mam_vetting_dso() === null, 'and an unset officer reads as null rather than a phantom user');

t_group('vetting — the admin surface exists and is wired');

/* A vetting system an admin cannot operate is not shipped. These are static
   checks on the markup; the browser pass is what proved the write path. */
$vAdmin = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
t_ok(strpos($vAdmin, "'vetting' => ['Staff & Vetting', 'shield-halved']") !== false, 'there is a nav entry');
t_ok(strpos($vAdmin, "elseif (\$view === 'vetting')") !== false, '…and a view');
foreach (['vetting_record', 'vetting_delete', 'safeguarding_mode'] as $act) {
    t_ok(strpos($vAdmin, "\$action === '$act'") !== false, "the `$act` action is handled");
    t_ok((bool) preg_match("/'$act'\s*=> '(users|settings)'/", $vAdmin), "…behind a capability");
}
/* mam_admin_redirect() does not exist in this codebase — I invented it while
   writing this screen and every POST rendered a blank page until it was caught in
   a browser. The same class of bug as the `showFieldError()` ReferenceError
   CLAUDE.md records. Pin it: this file redirects with header('Location: …'). */
t_ok(strpos($vAdmin, 'mam_admin_redirect') === false,
    'the view uses this file\'s own header(Location) pattern, not an invented helper');
t_ok(strpos($vAdmin, "header('Location: ?v=vetting&i=' . \$iid . '&ok='") !== false,
    '…and redirects back to the instructor it just recorded against');

// The nav probe must not key off vetting_records: an EMPTY vetting table is
// exactly when this screen is needed, so probing it would fold the section away
// at the worst possible moment.
t_ok((bool) preg_match("/'vetting' => 'users',/", $vAdmin),
    'the nav probe keys off users, not vetting_records');

// The screen must warn, in the UI, against pasting check contents.
t_ok(strpos($vAdmin, 'Do not paste the contents of a check') !== false,
    'the form warns against storing check contents');
t_ok(strpos($vAdmin, 'No Designated Safeguarding Officer named') !== false,
    'an unnamed safeguarding officer is surfaced as a finding, not left blank');
// Removing a clearance can un-clear someone; it must say so before it happens.
t_ok((bool) preg_match('/vetting_delete.*?\n.*?/s', $vAdmin) && strpos($vAdmin, 'may stop being cleared to teach children') !== false,
    'removing a clearance confirms with its consequence');
