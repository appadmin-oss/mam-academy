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

// ── Server-side drafts (A1: cross-device resume) ──
mam_app_draft_save('MAMK-draft-1', 'Drafter@x.dev', ['currentStep' => 2, 'firstName' => 'Bem']);
$d = mam_app_draft_load('MAMK-draft-1');
t_ok($d !== null && is_array($d['payload']), 'draft saves + loads with its payload');
t_eq($d['payload']['firstName'], 'Bem', 'draft payload round-trips');
t_eq($d['email'], 'drafter@x.dev', 'draft email is normalised to lowercase');
mam_app_draft_save('MAMK-draft-1', 'drafter@x.dev', ['currentStep' => 3, 'firstName' => 'Bem']);
t_eq(mam_app_draft_load('MAMK-draft-1')['payload']['currentStep'], 3, 're-saving the same key updates in place (upsert)');
t_eq((int)mam_db_row("SELECT COUNT(*) AS n FROM application_drafts WHERE draft_key='MAMK-draft-1'")['n'], 1, 'one row per draft key');

// magic-link token: verified load works, a forged token is rejected
$tok = mam_app_draft_token('MAMK-draft-1');
t_ok(mam_app_draft_load_verified('MAMK-draft-1', $tok) !== null, 'a valid token loads the draft');
t_ok(mam_app_draft_load_verified('MAMK-draft-1', 'deadbeef') === null, 'a forged token is rejected');
t_ok(mam_app_draft_load_verified('MAMK-nope', $tok) === null, 'a token for a different key is rejected');
t_ok(strpos(mam_app_draft_resume_url('MAMK-draft-1'), 't=' . $tok) !== false, 'resume URL carries the signed token');

// mark submitted
mam_app_draft_mark_submitted('MAMK-draft-1');
t_eq(mam_app_draft_load('MAMK-draft-1')['status'], 'submitted', 'a submitted draft is marked submitted');

// ── Abandoned-draft nudge sweep (L3 automation) ──
// A fresh draft (just saved) is NOT yet eligible (idle < 24h).
mam_app_draft_save('MAMK-fresh', 'fresh@x.dev', ['currentStep' => 1]);
t_eq(mam_app_draft_nudge_sweep(24, 168, 50), 0, 'a fresh draft is not nudged (idle < 24h)');
// An old draft (idle 2 days, within window) IS nudged exactly once.
mam_app_draft_save('MAMK-stale', 'stale@x.dev', ['currentStep' => 2]);
mam_db_update('application_drafts', ['updated_at' => date('Y-m-d H:i:s', time() - 48 * 3600)], ['draft_key' => 'MAMK-stale']);
t_eq(mam_app_draft_nudge_sweep(24, 168, 50), 1, 'a stale draft (idle 2 days) is nudged');
t_eq(mam_app_draft_load('MAMK-stale')['status'], 'nudged', 'the nudged draft is marked nudged');
t_eq(mam_app_draft_nudge_sweep(24, 168, 50), 0, 're-running the sweep does not nudge it again');
