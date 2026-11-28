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

// ── Abandoned-draft nudge sweep — REMOVED 2026-11-28 (admissions fix 9) ──
// Nothing writes application_drafts since the /apply rework, so the sweep could
// never send anything; it, its job and its cron step are gone. (These three
// assertions used to exercise it; replaced deliberately.)
t_ok(!function_exists('mam_app_draft_nudge_sweep'), 'the dead draft-nudge sweep is gone');
t_ok(!str_contains((string) file_get_contents(MAM_API_ROOT . '/tools/run-jobs.php'), 'draft_nudge'), 'and the cron no longer runs it');

// ── Admissions pipeline (A2) ──
t_eq(mam_application_stage('in_review', '')['stage'], 'reviewing', 'in_review → reviewing stage');
t_eq(mam_application_stage('waitlisted', '')['stage'], 'waitlisted', 'waitlisted stage maps through');
t_eq(mam_application_stage('cancelled', '')['stage'], 'closed', 'cancelled → closed');
t_eq(mam_application_stage('paid', '')['stage'], 'confirmed', 'paid status → confirmed even without a payment row');
t_ok(mam_admissions_can_transition('submitted', 'in_review') && mam_admissions_can_transition('accepted', 'enrolled'), 'core forward transitions are legal');
t_ok(!mam_admissions_can_transition('enrolled', 'in_review') && !mam_admissions_can_transition('cancelled', 'accepted'), 'enrolled and cancelled are terminal');

$staff = mam_db_insert('users', ['email' => 't_adm_staff@x.dev', 'password_hash' => 'x', 'role' => 'admin', 'name' => 'Admissions A', 'status' => 'active', 'admin_tier' => 'manager']);
$cid2 = mam_db_insert('customers', ['email' => 'pipe@x.dev', 'first_name' => 'Pipe', 'last_name' => 'Line']);
$aid2 = mam_db_insert('applications', ['reference' => 'MAM-PIPE-1', 'customer_id' => $cid2, 'pathway' => 'standard', 'status' => 'submitted']);

$t = mam_admissions_transition($aid2, 'in_review', $staff, 'screening');
t_eq($t['to'], 'in_review', 'transition moves the application');
t_eq(mam_db_row('SELECT status FROM applications WHERE id=?', [$aid2])['status'], 'in_review', 'status persisted');
$t2 = mam_admissions_transition($aid2, 'accepted', $staff, 'strong sample');
t_eq($t2['from'], 'in_review', 'transition records the prior status');
$ev = mam_admissions_events($aid2);
t_eq(count($ev), 2, 'each transition appends an audit event');
t_eq($ev[1]['to_status'], 'accepted', 'audit captures the to-status');
t_eq($ev[1]['reason'], 'strong sample', 'audit captures the reason');
$threw = false; try { mam_admissions_transition($aid2, 'submitted', $staff); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'illegal_transition'; }
t_ok($threw, 'an illegal transition (accepted → submitted) throws');
$threw = false; try { mam_admissions_transition(99999, 'in_review'); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'application_not_found'; }
t_ok($threw, 'a transition on a missing application throws');

// the pipeline reflects the live status, and the applicant status portal agrees
t_eq(mam_application_status('MAM-PIPE-1', 'pipe@x.dev')['stage'], 'accepted', 'the applicant status portal reflects the pipeline decision');

// notes + assignee
$nid = mam_admissions_note($aid2, $staff, 'Called the parent; keen to start.');
t_ok($nid > 0, 'a note is added');
t_eq(mam_admissions_notes($aid2)[0]['note'], 'Called the parent; keen to start.', 'notes list newest-first with content');
$threw = false; try { mam_admissions_note($aid2, $staff, '   '); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'empty_note'; }
t_ok($threw, 'an empty note is rejected');
mam_admissions_assign($aid2, $staff, $staff);
t_eq((int)mam_db_row('SELECT assignee_id FROM applications WHERE id=?', [$aid2])['assignee_id'], $staff, 'assignee recorded');
$pipe = array_filter(mam_admissions_pipeline(), function ($r) use ($aid2) { return (int)$r['id'] === $aid2; });
$pipeRow = array_values($pipe)[0];
t_eq($pipeRow['stage'], 'accepted', 'pipeline carries the friendly stage');
t_eq($pipeRow['assignee_name'], 'Admissions A', 'pipeline resolves the assignee name');
