<?php
/**
 * MAM Auditer placement validity — valid one year, once-until-expiration,
 * enrolled students exempt. Derived per customer from booking_fee_paid_at +
 * enrolments.
 */
declare(strict_types=1);
t_group('MAM Auditer validity (1yr · once · students exempt)');

// ── Never taken → can book ──
$cidNone = mam_db_insert('customers', ['email' => 't_aud_none@x.dev', 'first_name' => 'New', 'last_name' => 'Lead']);
mam_db_insert('applications', ['reference' => 'MAM-AUD-N', 'customer_id' => $cidNone, 'pathway' => 'standard', 'status' => 'submitted']);
$none = mam_auditer_status($cidNone);
t_eq($none['state'], 'none', 'no prior Auditer → state none');
t_ok($none['can_book'] === true, 'a customer who never sat it can book');
t_ok($none['valid'] === false, 'no placement on file yet');

// ── Done recently → valid, cannot re-book (once-until-expiration) ──
$cidValid = mam_db_insert('customers', ['email' => 't_aud_valid@x.dev', 'first_name' => 'Rec', 'last_name' => 'Ent']);
mam_db_insert('applications', ['reference' => 'MAM-AUD-V', 'customer_id' => $cidValid, 'pathway' => 'standard', 'status' => 'submitted', 'booking_fee_paid_at' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
$valid = mam_auditer_status($cidValid);
t_eq($valid['state'], 'valid', 'a recent Auditer is valid');
t_ok($valid['valid'] === true, 'placement in date');
t_ok($valid['can_book'] === false, 'cannot re-book a valid placement (once-until-expiration)');
t_ok($valid['expires_at'] !== null && strtotime($valid['expires_at']) > time(), 'expiry is in the future (~1 year out)');

// A SECOND application by the same customer is still gated by the valid placement.
mam_db_insert('applications', ['reference' => 'MAM-AUD-V2', 'customer_id' => $cidValid, 'pathway' => 'crash', 'status' => 'submitted']);
t_ok(mam_auditer_status($cidValid)['can_book'] === false, 'a new application does NOT reset the clock (validity is per customer)');

// ── Expired (> 1 year) → can book again ──
$cidExp = mam_db_insert('customers', ['email' => 't_aud_exp@x.dev', 'first_name' => 'Old', 'last_name' => 'Timer']);
mam_db_insert('applications', ['reference' => 'MAM-AUD-E', 'customer_id' => $cidExp, 'pathway' => 'standard', 'status' => 'submitted', 'booking_fee_paid_at' => date('Y-m-d H:i:s', time() - 400 * 86400)]);
$exp = mam_auditer_status($cidExp);
t_eq($exp['state'], 'expired', 'an Auditer older than a year is expired');
t_ok($exp['valid'] === false, 'expired placement is not valid');
t_ok($exp['can_book'] === true, 'an expired placement can be re-booked');

// ── Enrolled student → exempt (never expires, no re-book needed) ──
$cidStu = mam_db_insert('customers', ['email' => 't_aud_stu@x.dev', 'first_name' => 'Stew', 'last_name' => 'Dent']);
// Even with an OLD booking fee, an active enrolment keeps them exempt.
mam_db_insert('applications', ['reference' => 'MAM-AUD-S', 'customer_id' => $cidStu, 'pathway' => 'standard', 'status' => 'enrolled', 'booking_fee_paid_at' => date('Y-m-d H:i:s', time() - 800 * 86400)]);
$uid = mam_db_insert('users', ['email' => 't_aud_stu@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Stew Dent', 'status' => 'active']);
$courseId = (int)mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];
mam_db_insert('course_enrollments', ['student_id' => $uid, 'course_id' => $courseId, 'status' => 'active']);
$stu = mam_auditer_status($cidStu);
t_eq($stu['state'], 'student', 'an enrolled student is exempt');
t_ok($stu['is_student'] === true, 'student flag set');
t_ok($stu['valid'] === true, 'student placement never lapses (even with an 800-day-old fee)');
t_ok($stu['can_book'] === false, 'a student needs no reassessment');

// ── Guard + messages ──
t_eq(mam_auditer_status(0)['state'], 'none', 'no customer id → none (safe)');
t_ok(strpos(mam_auditer_message($valid), 'valid') !== false, 'valid message reads sensibly');
t_ok(strpos(mam_auditer_message($exp), 'expired') !== false, 'expired message reads sensibly');
t_ok(strpos(mam_auditer_message($stu), 'student') !== false, 'student message reads sensibly');
