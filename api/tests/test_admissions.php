<?php
/**
 * Admissions — applicant status lookup (Application A1). Privacy-safe
 * (reference + matching email) and maps raw application/payment state to a
 * friendly stage + next step.
 */
declare(strict_types=1);
t_group('admissions: applicant status');

// ── Pure stage mapping ──
t_eq(mam_application_stage('submitted', '')['stage'], 'received', 'unpaid submitted → received');
t_eq(mam_application_stage('submitted', 'succeeded')['stage'], 'confirmed', 'paid booking fee → confirmed');
t_eq(mam_application_stage('enrolled', '')['stage'], 'enrolled', 'enrolled status → enrolled');
t_eq(mam_application_stage('declined', '')['stage'], 'closed', 'declined → closed');
t_ok(mam_application_stage('submitted', '')['next'] !== '', 'every stage carries a next step');

// ── Lookup setup ──
$cid = mam_db_insert('customers', ['email' => 'applicant@x.dev', 'first_name' => 'Ada', 'last_name' => 'Obi']);
$aid = mam_db_insert('applications', ['reference' => 'MAM-TEST-001', 'customer_id' => $cid, 'pathway' => 'standard', 'mode' => 'online', 'status' => 'submitted', 'total_usd' => 5]);

// ── Received (no payment) ──
$st = mam_application_status('MAM-TEST-001', 'applicant@x.dev');
t_ok($st !== null, 'status found with matching reference + email');
t_eq($st['stage'], 'received', 'unpaid application → received stage');
t_eq($st['first_name'], 'Ada', 'status carries the applicant first name');
t_eq($st['pathway'], 'standard', 'status carries the pathway');

// ── Email match is case-insensitive but required ──
t_ok(mam_application_status('MAM-TEST-001', 'APPLICANT@X.DEV') !== null, 'email match is case-insensitive');
t_ok(mam_application_status('MAM-TEST-001', 'wrong@x.dev') === null, 'wrong email → not found (not enumerable by reference alone)');
t_ok(mam_application_status('MAM-NOPE-999', 'applicant@x.dev') === null, 'unknown reference → not found');
t_ok(mam_application_status('', 'applicant@x.dev') === null, 'empty reference → null');
t_ok(mam_application_status('MAM-TEST-001', 'not-an-email') === null, 'invalid email → null');

// ── Paid booking fee → confirmed ──
mam_db_insert('payments', ['application_id' => $aid, 'reference' => 'PAY-TEST-001', 'provider' => 'paystack', 'status' => 'succeeded', 'amount' => 5, 'currency' => 'USD']);
t_eq(mam_application_status('MAM-TEST-001', 'applicant@x.dev')['stage'], 'confirmed', 'a succeeded payment moves the stage to confirmed');

// ── Admin-set status flows through ──
mam_db_update('applications', ['status' => 'enrolled'], ['id' => $aid]);
t_eq(mam_application_status('MAM-TEST-001', 'applicant@x.dev')['stage'], 'enrolled', 'an enrolled application reports enrolled');
