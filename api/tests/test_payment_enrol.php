<?php
/**
 * Auto-enrolment on payment settlement.
 *
 * Settling a tuition payment used to provision a portal account, add a course
 * enrolment, and stop. Three gaps, each of which produced a paid student nobody
 * was teaching:
 *
 *   • No programme was passed to mam_lms_add_course(), so the cohort resolved
 *     on (course, level, NULL) while the admin's own "Complete enrolment"
 *     screen resolves on (course, level, programme). The same applicant landed
 *     in a DIFFERENT class depending on which path settled them.
 *   • The instructor was written to the enrolment only, never to the cohort.
 *     mam_lms_instructor_cohorts() keys off either, so the roster showed up —
 *     but the class itself stayed teacher-less and every later student on it
 *     inherited nothing.
 *   • mam_lms_add_course() returns id === null on the waitlisted branch.
 *     `(int)$row['id']` read that as 0 and quietly did nothing further, so a
 *     paid applicant whose class was full got silence.
 *
 * Settlement now delegates to mam_enrol_complete() — the same function the
 * admin screen runs — so the two paths cannot drift apart again.
 *
 * The invariant that matters most is at the bottom: a payment must NEVER fail
 * because the placement could not be worked out.
 */
declare(strict_types=1);

t_group('payment → enrolment: the automation');

$reg = mam_automations_registry();
t_ok(isset($reg['payment_enrol']), 'payment_enrol is a registered rule');
t_ok($reg['payment_enrol']['live'] === true, '…and it is honoured at its trigger point');
t_ok($reg['payment_enrol']['default'] === true, '…and defaults on');
// Separate from payment_provision deliberately: one gives someone a login, the
// other puts them in a class with a teacher, and an academy may want the first
// without the second (mid-term, or where placement follows the Auditer).
t_ok(isset($reg['payment_provision']) && $reg['payment_provision'] !== $reg['payment_enrol'],
    'it is a separate flag from payment_provision, not a rename of it');

$pay = (string) file_get_contents(dirname(__DIR__) . '/lib/payments.php');
t_ok(strpos($pay, "mam_automation_on('payment_enrol')") !== false, 'settlement honours the flag');
t_ok(strpos($pay, 'mam_enrol_complete($appId, [], null)') !== false,
    'settlement delegates to the one enrolment implementation');
// null, never 0 — enrolment_events.actor_id is a users(id) FK under
// PRAGMA foreign_keys = ON, and 0 silently destroys the audit row.
t_ok(strpos($pay, 'null actor: a system action, never 0 (FK)') !== false,
    'the system actor is null, not 0');
t_ok(!preg_match('/mam_lms_add_course\(\s*\$studentId/', $pay),
    'the old partial chain (add_course + approve) is gone from settlement');

t_group('payment → enrolment: the whole chain runs');

/* A complete, unambiguous application: a real course, a real level, a pathway
 * that maps to a real programme. This is the 24-of-26 case. */
$sfx  = 'payenrol' . substr((string) microtime(true), -6);
$cid  = mam_db_insert('customers', [
    'first_name' => 'Ayo', 'last_name' => 'Bello', 'email' => "t_{$sfx}@x.dev", 'phone' => '+2348000000001',
]);
$appId = mam_db_insert('applications', [
    'customer_id' => $cid, 'reference' => strtoupper("T-$sfx"), 'status' => 'submitted',
    'discipline' => 'piano', 'level' => 'precert', 'pathway' => 'standard',
    'total_usd' => 100,
]);

// Give the course/level a remembered instructor so the chain has one to assign.
$instrId = mam_db_insert('users', [
    'email' => "t_{$sfx}_teacher@x.dev", 'password_hash' => 'x', 'role' => 'instructor',
    'name' => 'Test Teacher', 'status' => 'active',
]);
$pianoId   = (int) mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];
$precertId = (int) mam_db_row("SELECT id FROM levels WHERE code = 'precert'")['id'];
mam_lms_remember_instructor($instrId, $pianoId, $precertId);

mam_payment_settle_tuition($appId);

$stu = mam_db_row('SELECT id FROM users WHERE LOWER(email) = LOWER(?)', ["t_{$sfx}@x.dev"]);
t_ok($stu !== null, 'the portal account is provisioned');
$studentId = (int) ($stu['id'] ?? 0);

$ce = mam_db_row(
    'SELECT ce.*, co.instructor_id AS cohort_instructor, co.program_id
       FROM course_enrollments ce
       LEFT JOIN cohorts co ON co.id = ce.cohort_id
      WHERE ce.student_id = ?', [$studentId]);
t_ok($ce !== null, 'the student is enrolled on a course');
t_eq((int) ($ce['course_id'] ?? 0), $pianoId, '…the course from the application');
t_ok((int) ($ce['cohort_id'] ?? 0) > 0, '…and allocated to a class');

// The three gaps, each asserted directly.
$stdId = (int) (mam_db_row("SELECT id FROM programs WHERE code = 'standard'")['id'] ?? 0);
t_eq((int) ($ce['program_id'] ?? 0), $stdId,
    'the cohort carries the PROGRAMME — settlement and the admin screen now resolve the same class');
t_eq((int) ($ce['instructor_id'] ?? 0), $instrId, 'the enrolment carries the instructor');
t_eq((int) ($ce['cohort_instructor'] ?? 0), $instrId,
    'and so does the CLASS — an enrolment-only assignment leaves the class teacher-less for everyone after');

$after = mam_db_row('SELECT status FROM applications WHERE id = ?', [$appId]);
t_eq((string) ($after['status'] ?? ''), 'enrolled',
    'the application ends up enrolled, not merely paid');

t_group('payment → enrolment: settling twice changes nothing');

// Webhooks retry. A second settlement must not create a second enrolment.
mam_payment_settle_tuition($appId);
$n = (int) mam_db_row('SELECT COUNT(*) AS n FROM course_enrollments WHERE student_id = ?', [$studentId])['n'];
t_eq($n, 1, 'a retried settlement leaves exactly one enrolment');

t_group('payment → enrolment: a payment never fails over placement');

/* `discipline` holds instruments that are not courses — "violin" and
 * "clarinet" are both in the live data. The placement cannot be resolved, and
 * that must NOT cost the applicant their account or their paid status: it is
 * exactly what mam_enrol_incomplete() lists for the admin to finish. */
$sfx2 = 'payenrolx' . substr((string) microtime(true), -6);
$cid2 = mam_db_insert('customers', [
    'first_name' => 'Ndidi', 'last_name' => 'Eze', 'email' => "t_{$sfx2}@x.dev", 'phone' => '+2348000000002',
]);
$app2 = mam_db_insert('applications', [
    'customer_id' => $cid2, 'reference' => strtoupper("T-$sfx2"), 'status' => 'submitted',
    'discipline' => 'violin', 'pathway' => 'standard', 'total_usd' => 100,
]);

$threw = false;
try { mam_payment_settle_tuition($app2); } catch (Throwable $e) { $threw = true; }
t_ok(!$threw, 'settling a payment we cannot place does not throw');

$stu2 = mam_db_row('SELECT id FROM users WHERE LOWER(email) = LOWER(?)', ["t_{$sfx2}@x.dev"]);
t_ok($stu2 !== null, '…the account is still provisioned');
$paid2 = mam_db_row('SELECT status FROM applications WHERE id = ?', [$app2]);
t_eq((string) ($paid2['status'] ?? ''), 'paid', '…and the application is still marked paid');
$n2 = (int) mam_db_row('SELECT COUNT(*) AS n FROM course_enrollments WHERE student_id = ?',
    [(int) $stu2['id']])['n'];
t_eq($n2, 0, '…with no enrolment invented from a course that does not exist');
// And the admin can find it: this is the queue the whole enrol-complete file exists for.
$backlog = array_column(mam_enrol_incomplete(200), 'id');
t_ok(in_array($app2, $backlog, true), '…and it appears in the admin backlog to be finished by hand');

t_group('payment → enrolment: an erased applicant is never rebuilt');

/* The erasure guard lives in mam_enrol_resolve() and is checked FIRST. It has
 * to hold on the automatic path too — a settled payment must not resurrect
 * somebody the academy was asked to forget, and email them a password. */
$sfx3 = 'payenrole' . substr((string) microtime(true), -6);
$cid3 = mam_db_insert('customers', [
    'first_name' => 'Erased', 'last_name' => 'Person', 'email' => "t_{$sfx3}@deleted.invalid", 'phone' => '',
]);
$app3 = mam_db_insert('applications', [
    'customer_id' => $cid3, 'reference' => strtoupper("T-$sfx3"), 'status' => 'submitted',
    'discipline' => 'piano', 'level' => 'precert', 'pathway' => 'standard',
    'total_usd' => 100,
]);
$plan3 = mam_enrol_resolve($app3);
t_ok(!$plan3['ready'], 'an erased applicant is not enrollable');
t_ok(in_array('erased', array_column($plan3['blockers'], 'field'), true),
    '…and the reason given is the erasure, not a missing course');
$done3 = mam_enrol_complete($app3);
t_ok($done3['ok'] === false, 'completing it is refused outright');
$n3 = (int) mam_db_row('SELECT COUNT(*) AS n FROM course_enrollments ce
                          JOIN users u ON u.id = ce.student_id
                         WHERE LOWER(u.email) = LOWER(?)', ["t_{$sfx3}@deleted.invalid"])['n'];
t_eq($n3, 0, '…and no enrolment exists for them');
