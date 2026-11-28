<?php
/**
 * Identity hold (schema v109). Found by driving /apply in a browser
 * (2026-11-28): a parent applying for a child with their OWN email rewrote the
 * parent's customer record into the child's, and enrolment then put the
 * child's course into the parent's account. Until the identity module gives
 * each person their own record, such an application is held for the office.
 */
declare(strict_types=1);

t_group('identity hold — telling a different person from the same one');

t_eq(mam_identity_conflict(['first_name' => 'Ada', 'last_name' => 'Obi', 'dob' => '1990-02-01'],
                           ['first_name' => 'Dayo', 'last_name' => 'Obi', 'dob' => '2015-06-06']), 'name_differs',
    'a different first name on the same address is a different person');
t_eq(mam_identity_conflict(['first_name' => 'Ada', 'last_name' => 'Obi', 'dob' => '1990-02-01'],
                           ['first_name' => ' ada ', 'last_name' => 'OBI', 'dob' => '2015-06-06']), 'dob_differs',
    'the same name with a different date of birth is still flagged');
t_eq(mam_identity_conflict(['first_name' => 'Ada', 'last_name' => 'Obi', 'dob' => null],
                           ['first_name' => 'Ada', 'last_name' => 'Obi', 'dob' => '1990-02-01']), null,
    'a returning applicant (same name, a date filled in for the first time) is not held');
t_eq(mam_identity_conflict(['first_name' => '', 'last_name' => '', 'dob' => null],
                           ['first_name' => 'Ada', 'last_name' => 'Obi']), null,
    'a missing value never counts as a difference');

t_group('identity hold — nothing is made from a held application');

$IH_tag  = substr(md5((string) microtime(true)), 0, 6);
$IH_mail = "holder.$IH_tag@test.local";
$IH_cust = (int) mam_db_insert('customers', ['email' => $IH_mail, 'first_name' => 'Ada', 'last_name' => 'Holder', 'dob' => '1988-01-01']);
$IH_hold = json_encode(['reason' => 'name_differs', 'existing_customer_id' => $IH_cust,
    'submitted' => ['first_name' => 'Dayo', 'last_name' => 'Holder', 'dob' => '2015-06-06']]);
$IH_app = (int) mam_db_insert('applications', ['reference' => 'MAM-IH' . strtoupper($IH_tag), 'customer_id' => $IH_cust,
    'pathway' => 'standard', 'mode' => 'online', 'discipline' => 'piano', 'status' => 'submitted',
    'identity_hold' => $IH_hold, 'created_at' => date('Y-m-d H:i:s')]);

$IH_plan = mam_enrol_resolve($IH_app);
$IH_fields = array_column($IH_plan['blockers'], 'field');
t_ok(in_array('identity', $IH_fields, true), 'a held application cannot be enrolled without a decision');
t_ok(!$IH_plan['ready'], 'and is not ready');
$IH_why = (string) ($IH_plan['blockers'][array_search('identity', $IH_fields, true)]['why'] ?? '');
t_ok(str_contains($IH_why, 'Dayo') && str_contains($IH_why, 'Ada'), 'the office is told who applied and who holds the address');

$IH_res = mam_enrol_complete($IH_app);
t_ok(!$IH_res['ok'], 'Complete enrolment refuses while the hold stands');

$IH_before = (int) mam_db_row('SELECT COUNT(*) AS n FROM users WHERE LOWER(email) = LOWER(?)', [$IH_mail])['n'];
$IH_tag2 = mam_payment_settle_tuition($IH_app);
t_eq($IH_tag2, 'identity_review', 'settlement records the money and reports the hold');
t_eq(mam_db_row('SELECT status FROM applications WHERE id = ?', [$IH_app])['status'], 'paid', 'the application is still marked paid — a payment never fails over this');
t_eq((int) mam_db_row('SELECT COUNT(*) AS n FROM users WHERE LOWER(email) = LOWER(?)', [$IH_mail])['n'], $IH_before,
    'no account is made in the name of the person who holds the address');
$IH_c = mam_db_row('SELECT first_name, dob FROM customers WHERE id = ?', [$IH_cust]);
t_eq([$IH_c['first_name'], substr((string) $IH_c['dob'], 0, 10)], ['Ada', '1988-01-01'], "the existing person's record is untouched");

$IH_ok = mam_enrol_resolve($IH_app, ['identity_confirmed' => true]);
t_ok(!in_array('identity', array_column($IH_ok['blockers'], 'field'), true), 'once the office confirms it is the same person, the hold no longer blocks');

t_group('identity hold — the application route uses it');

$IH_route = (string) file_get_contents(MAM_API_ROOT . '/routes/application.php');
t_ok(str_contains($IH_route, 'mam_identity_conflict(') && str_contains($IH_route, "'identity_hold'          => \$identityHold"),
    '/api/application holds a conflicting application instead of rewriting the customer');
t_ok((bool) preg_match('/mam_identity_conflict\(.*\)\) !== null\) \{.*?\} elseif \(\$cust\) \{/s', $IH_route),
    'the customer UPDATE runs only when there is no conflict');

t_group('guardian links — a student is never their own guardian');

$IH_stu = (int) mam_db_insert('users', ['name' => 'Self Link', 'email' => "selflink.$IH_tag@test.local", 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$IH_err = null;
try { mam_parent_link("selflink.$IH_tag@test.local", $IH_stu); } catch (Throwable $e) { $IH_err = $e->getMessage(); }
t_eq($IH_err, 'same_as_student', "linking the student's own email as their guardian is refused");
t_eq((int) mam_db_row('SELECT COUNT(*) AS n FROM parent_links WHERE student_id = ?', [$IH_stu])['n'], 0, 'and no link row is written');
t_eq((int) mam_db_row("SELECT COUNT(*) AS n FROM user_roles WHERE user_id = ? AND role = 'parent'", [$IH_stu])['n'], 0, "and the student's login is not given the parent role");

t_group('ID card email — the layout is called the way it is defined');

$IH_cards = (string) file_get_contents(MAM_API_ROOT . '/modules/identity/lib/cards.php');
t_ok(!preg_match('/mam_email_layout\(\s*"/', $IH_cards), 'cards.php passes mam_email_layout() an options array, not a string (it was a TypeError on every send)');
