<?php
/**
 * Data-subject rights (NDPA/GDPR) — per-person EXPORT + ERASURE. Export gathers
 * everything we hold (minus secrets); erasure anonymises identity + deletes
 * free-form PII while preserving financial + audit records, and is idempotent.
 */
declare(strict_types=1);
t_group('data rights (export + erasure)');

// ── Fixtures: a customer + a matching user, with data across the surfaces ──
$email = 'subject@x.dev';
$cid = mam_db_insert('customers', ['email' => $email, 'first_name' => 'Subject', 'last_name' => 'Person', 'phone' => '+234 800 000 0000', 'address' => '1 Test Rd', 'state' => 'Lagos', 'age_range' => '16-20']);
$aid = mam_db_insert('applications', ['reference' => 'MAM-DR-1', 'customer_id' => $cid, 'pathway' => 'standard', 'mode' => 'online', 'discipline' => 'piano', 'experience' => 'beginner', 'goals' => 'perform', 'status' => 'submitted', 'total_usd' => 5]);
mam_db_insert('payments', ['application_id' => $aid, 'reference' => 'PAY-DR-1', 'provider' => 'paystack', 'status' => 'succeeded', 'amount' => 5, 'currency' => 'USD']);
$uid = mam_db_insert('users', ['email' => $email, 'password_hash' => 'secret-hash', 'role' => 'student', 'name' => 'Subject Person', 'phone' => '+234 800 000 0000', 'status' => 'active']);
$cidp = (int)mam_db_row("SELECT id FROM courses WHERE code='piano'")['id'];
$eid = mam_db_insert('course_enrollments', ['student_id' => $uid, 'course_id' => $cidp, 'status' => 'active']);
$asg = mam_db_insert('assignments', ['student_id' => $uid, 'author_id' => $uid, 'title' => 'Scale', 'status' => 'open']);
mam_db_insert('submissions', ['assignment_id' => $asg, 'student_id' => $uid, 'artifact_kind' => 'link', 'note' => 'my private note', 'status' => 'submitted']);
mam_db_insert('practice_logs', ['student_id' => $uid, 'minutes' => 30, 'focus' => 'scales', 'notes' => 'felt good', 'logged_on' => date('Y-m-d')]);
mam_db_insert('messages', ['name' => 'Subject', 'email' => $email, 'topic' => 'q', 'message' => 'hello', 'status' => 'unread']);
mam_db_insert('newsletter', ['email' => $email, 'source' => 'footer', 'status' => 'subscribed']);
mam_notify($uid, 'test', 'A note', 'body');

// ── Export ──
t_ok(mam_datarights_export('nobody@x.dev') === null, 'export for an unknown email is null');
t_ok(mam_datarights_export('not-an-email') === null, 'export for an invalid email is null');
$b = mam_datarights_export('SUBJECT@X.DEV'); // case-insensitive
t_ok(is_array($b), 'export returns a bundle (case-insensitive match)');
t_eq($b['customer']['first_name'], 'Subject', 'bundle carries the customer record');
t_eq($b['user']['name'], 'Subject Person', 'bundle carries the user record');
t_ok(!array_key_exists('password_hash', $b['user']), 'the export NEVER includes the password hash (not the subject\'s data)');
t_eq(count($b['applications']), 1, 'bundle includes the application');
t_eq(count($b['payments']), 1, 'bundle includes the payment');
t_eq(count($b['enrolments']), 1, 'bundle includes the enrolment');
t_eq(count($b['submissions']), 1, 'bundle includes the submission');
t_eq(count($b['practice_logs']), 1, 'bundle includes practice logs');
t_eq(count($b['messages']), 1, 'bundle includes contact messages');
t_eq(count($b['newsletter']), 1, 'bundle includes the newsletter subscription');

// ── Erasure ──
$bad = mam_datarights_erase('not-an-email');
t_ok(empty($bad['ok']) && $bad['error'] === 'invalid_email', 'erase rejects an invalid email');
$nf = mam_datarights_erase('nobody@x.dev');
t_ok(empty($nf['ok']) && $nf['error'] === 'not_found', 'erase reports not_found for an unknown email');

$res = mam_datarights_erase($email, 1);
t_ok($res['ok'] === true, 'erase succeeds');
t_eq($res['customer'], 1, 'the customer was anonymised');
t_eq($res['user'], 1, 'the user was anonymised');
t_ok($res['messages'] >= 1, 'contact messages were deleted');

// Identity is gone; the rows still exist (referential integrity preserved).
$cust = mam_db_row('SELECT email, first_name, phone, address FROM customers WHERE id = ?', [$cid]);
t_ok(strpos((string)$cust['email'], '@deleted.invalid') !== false, 'customer email is tombstoned');
t_eq($cust['first_name'], 'Erased', 'customer first name anonymised');
t_ok($cust['phone'] === null && $cust['address'] === null, 'customer phone/address cleared');

$usr = mam_db_row('SELECT email, name, status, password_hash FROM users WHERE id = ?', [$uid]);
t_ok(strpos((string)$usr['email'], '@deleted.invalid') !== false, 'user email is tombstoned');
t_eq($usr['status'], 'suspended', 'user account suspended');
t_ok($usr['password_hash'] !== 'secret-hash', 'user password hash was scrambled (login disabled)');

// Free-form PII nulled; financial + structural rows preserved.
t_ok(mam_db_row('SELECT note FROM submissions WHERE student_id = ?', [$uid])['note'] === null, 'submission note nulled');
t_ok(mam_db_row('SELECT notes FROM practice_logs WHERE student_id = ?', [$uid])['notes'] === null, 'practice note nulled');
t_eq((int)mam_db_row('SELECT COUNT(*) AS n FROM messages WHERE LOWER(email) = ?', [$email])['n'], 0, 'contact messages gone');
t_eq((int)mam_db_row('SELECT COUNT(*) AS n FROM newsletter WHERE LOWER(email) = ?', [$email])['n'], 0, 'newsletter entry gone');
t_eq((int)mam_db_row('SELECT COUNT(*) AS n FROM payments WHERE reference = ?', ['PAY-DR-1'])['n'], 1, 'the payment record is preserved (lawful basis)');
t_eq((int)mam_db_row('SELECT COUNT(*) AS n FROM course_enrollments WHERE id = ?', [$eid])['n'], 1, 'the enrolment row is preserved (anonymised owner)');

// ── Idempotent ──
$again = mam_datarights_erase($email, 1);
// After erasure the email no longer matches (tombstoned), so a re-run is a clean no-op/not_found.
t_ok(empty($again['ok']) && $again['error'] === 'not_found', 're-erasing the same email is a safe no-op (already tombstoned)');
