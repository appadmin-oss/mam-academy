<?php
/**
 * The guardian's email on the year-round application (schema v96).
 *
 * The form said the guardian "gets their own portal account once you are
 * enrolled" and collected only a name and a phone, so no account could be made.
 * These pin the field (required for an under-16, refused when malformed,
 * optional for an adult) and the promise (linked and invited wherever the
 * student's account is made; never onto the student's own address).
 */
declare(strict_types=1);

$GE_base = [
    'firstName' => 'Tunde', 'lastName' => 'Ade', 'email' => 'tunde.apply@example.com',
    'phone' => '+2348012345600', 'pathway' => 'standard', 'mode' => 'online',
    'level' => 'cert', 'expression' => 'classical', 'program' => 'classical',
    'discipline' => 'piano', 'sex' => 'male', 'address' => '3 Close',
    'lga' => 'Ikeja', 'state' => 'Lagos', 'dob' => date('Y-m-d', strtotime('-11 years')),
    'parentName' => 'Bisi Ade', 'parentPhone' => '+2348011111100',
    'policyAgreed' => true, 'policySignedName' => 'Bisi Ade', 'includeRegistration' => true,
];
$GE_err = static function (array $in): array {
    try { mam_validate_application($in); return []; }
    catch (\Throwable $e) { return str_contains($e->getMessage(), 'validation') ? ['validation'] : [$e->getMessage()]; }
};

t_group('guardian email — on the application');

t_eq($GE_err($GE_base), ['validation'], 'an under-16 application with no guardian email is refused — the account the form promises cannot be made without it');
t_eq($GE_err($GE_base + ['parentEmail' => 'not an address']), ['validation'], 'a malformed address is refused, not stored and mailed into the void');
$GE_ok = mam_validate_application($GE_base + ['parentEmail' => ' Bisi.Ade@Example.com ']);
t_eq($GE_ok['parentEmail'], 'bisi.ade@example.com', 'a good one is kept, trimmed and lower-cased');
$GE_adult = mam_validate_application(array_merge($GE_base, ['dob' => date('Y-m-d', strtotime('-25 years')), 'parentName' => '', 'policySignedName' => 'Tunde Ade']));
t_ok($GE_adult['parentEmail'] === null, 'an adult needs none');
t_ok(str_contains((string) file_get_contents(dirname(__DIR__) . '/routes/application.php'), "'parent_email'           => \$v['parentEmail']"), 'and it is stored on the application');
// The live form (/apply, assets/js/apply-flow.js) asks for it; test_site_redesign.php
// fails if the flow stops sending any field mam_validate_application() requires.
t_ok(str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/apply-flow.js'), 'parentEmail'),
    'the application form asks for the guardian\'s own email');

t_group('guardian email — the guardian is linked and invited when the student\'s account is made');

$GE_cust = mam_db_insert('customers', ['email' => 'geKid@test.local', 'first_name' => 'Ge', 'last_name' => 'Kid']);
$GE_app = ['parent_email' => 'ge.parent@test.local', 'parent_name' => 'Funke Kid', 'parent_phone' => '+2348000000777'];
$GE_stu = (int) mam_ensure_student_user($GE_cust)['user']['id'];
$GE_r = mam_application_link_guardian($GE_app, $GE_stu);
t_ok($GE_r['linked'] && $GE_r['parent_id'] > 0, 'the guardian named on the application is linked to the student');
$GE_par = mam_db_row('SELECT * FROM users WHERE id = ?', [$GE_r['parent_id']]);
t_eq([$GE_par['name'], $GE_par['phone']], ['Funke Kid', '+2348000000777'], 'with the name and phone the family gave, not "Parent/Guardian"');
t_ok(mam_db_row('SELECT 1 FROM password_resets WHERE user_id = ?', [$GE_r['parent_id']]) !== null, 'and is sent their own invitation to set a password');
$GE_again = mam_application_link_guardian($GE_app, $GE_stu);
t_ok($GE_again['linked'] && (int) mam_db_row('SELECT COUNT(*) n FROM parent_links WHERE student_id = ?', [$GE_stu])['n'] === 1, 'running it again changes nothing — still one link');
t_eq((int) mam_db_row('SELECT COUNT(*) n FROM password_resets WHERE user_id = ?', [$GE_r['parent_id']])['n'], 1, 'and sends no second invitation to an account already made');

$GE_same = mam_application_link_guardian(['parent_email' => 'gekid@test.local'], $GE_stu);
t_ok(!$GE_same['linked'] && $GE_same['reason'] === 'same_as_student', 'a family applying from ONE inbox is not linked — the child\'s login would gain the guardian role');
t_ok(!mam_user_has_role(mam_db_row('SELECT * FROM users WHERE id = ?', [$GE_stu]), 'parent'), 'and the student\'s account stays a student\'s');
t_eq(mam_application_link_guardian(['parent_email' => ''], $GE_stu)['reason'], 'no_guardian_email', 'no address: nothing to do, nothing refused');

t_group('guardian email — every path that makes the student\'s account links the guardian');

$GE_src = static fn(string $rel) => (string) file_get_contents(dirname(__DIR__) . '/' . $rel);
t_ok(str_contains($GE_src('lib/payments.php'), 'mam_application_link_guardian($app, $studentId)'), 'payment settlement');
t_ok(str_contains($GE_src('lib/enrol-complete.php'), 'mam_application_link_guardian($app, (int) $studentId, $actorId)'), '"Complete enrolment", which reports it as a step');
t_ok(str_contains($GE_src('lib/auditer.php'), 'mam_application_link_guardian($gApp'), 'an Auditer placement');
t_ok(str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php'), 'mam_application_link_guardian($app,'), 'and an admin marking the application paid');
