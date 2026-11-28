<?php
/**
 * The admissions fixes (2026-11-28). An audit walked every intake path and
 * found families who were never told the bank account, siblings enrolled into
 * each other's accounts, decisions that told nobody, applications nobody could
 * see, accounts with no course, status writes that skipped the state machine,
 * 16-year-olds signing for themselves and instruments nobody could place.
 * One group per fix, in the order the work was done.
 */
declare(strict_types=1);

$AF_root = dirname(__DIR__, 2);
$AF_src  = static fn(string $rel) => (string) file_get_contents($AF_root . '/' . $rel);
$AF_tag  = substr(md5((string) microtime(true) . 'af'), 0, 6);
$AF_mail = static fn(string $ctx, string $to) => mam_db_row(
    'SELECT * FROM mail_log WHERE context = ? AND LOWER(to_email) = LOWER(?) ORDER BY id DESC LIMIT 1', [$ctx, $to]);
$AF_app = static function (array $cust, array $app) {
    $cid = (int) mam_db_insert('customers', $cust);
    $aid = (int) mam_db_insert('applications', $app + ['customer_id' => $cid, 'reference' => 'MAM-AF' . strtoupper(substr(md5(uniqid('', true)), 0, 8)),
        'mode' => 'online', 'status' => 'submitted', 'total_usd' => 5, 'created_at' => date('Y-m-d H:i:s')]);
    return [$cid, $aid];
};
$AF_status = static fn(int $id) => (string) mam_db_row('SELECT status FROM applications WHERE id = ?', [$id])['status'];
$AF_throws = static function (callable $fn): ?string { try { $fn(); } catch (Throwable $e) { return $e->getMessage(); } return null; };

/* ── 1 ─────────────────────────────────────────────────────────────────── */
t_group('admissions fix 1 — a bank-transfer family is told the account, and the done screen keeps it');

$AF_flow = $AF_src('assets/js/apply-flow.js');
t_ok(str_contains($AF_flow, "if (k !== 'done') s.payNote = '';"), 'go() keeps the payment note when it draws the done screen (it wiped the bank details)');
t_ok(str_contains($AF_flow, "href=\"/book?ref=' + encodeURIComponent(s.ref)"), '"Book my assessment" carries the reference /book needs');
t_ok(!str_contains($AF_flow, '<a href="/book" class="btn"'), 'and no bare /book link is left on the done screen');
t_ok(str_contains($AF_flow, "payMethod: f.pay === 'transfer' ? 'transfer' : 'card'"), 'the form tells the server how the applicant chose to pay');
$AF_route = $AF_src('api/routes/application.php');
t_ok(str_contains($AF_route, "=== 'transfer' ? mam_bank_instructions(\$reference . '-BK'"), 'a transfer applicant\'s confirmation is given the bank details, on the booking-fee reference');
$AF_html = mam_email_application_confirmation(['reference' => 'MAM-AFBANK', 'pathway' => 'standard', 'mode' => 'online', 'discipline' => 'piano', 'policy_mode' => null],
    ['first_name' => 'Ada'], ['label' => '$5'], 'INV-1', ['bank_instructions' => mam_bank_instructions('MAM-AFBANK-BK', '$5')]);
$AF_bank = mam_bank_details();
t_ok(str_contains($AF_html, 'You chose bank transfer') && str_contains($AF_html, htmlspecialchars((string) $AF_bank['account_number'], ENT_QUOTES)),
    'the confirmation prints the account number when the applicant chose transfer');
t_ok(str_contains($AF_html, 'MAM-AFBANK-BK'), 'and the reference to put on the transfer');
$AF_card = mam_email_application_confirmation(['reference' => 'MAM-AFCARD', 'pathway' => 'standard', 'mode' => 'online', 'discipline' => 'piano', 'policy_mode' => null],
    ['first_name' => 'Ada'], ['label' => '$5'], 'INV-2');
t_ok(!str_contains($AF_card, 'You chose bank transfer'), 'a card applicant\'s confirmation carries no bank block');
t_ok(str_contains($AF_src('api/routes/payment-init.php'), "'bank_transfer_instructions'"), 'switching to transfer after a card attempt emails the details');

/* ── 2 ─────────────────────────────────────────────────────────────────── */
t_group('admissions fix 2 — a sibling\'s summer application is held, not enrolled into the other child');

$AF_fam = "fam.$AF_tag@test.local";
// The first child already has an account on the parent's address.
mam_db_insert('customers', ['email' => $AF_fam, 'first_name' => 'Tobi', 'last_name' => 'Ade', 'dob' => '2014-02-02']);
$AF_first = (int) mam_db_insert('users', ['email' => $AF_fam, 'name' => 'Tobi Ade', 'role' => 'student', 'status' => 'active', 'password_hash' => 'x']);
$AF_sum = (int) mam_db_insert('summer_applications', ['reference' => 'SUM-AF' . strtoupper($AF_tag), 'first_name' => 'Kemi', 'last_name' => 'Ade',
    'email' => $AF_fam, 'dob' => '2016-05-05', 'age_at_submission' => 10, 'guardian_name' => 'Bola Ade', 'guardian_email' => "bola.$AF_tag@test.local",
    'discipline' => 'piano', 'session_year' => (int) date('Y'), 'status' => 'confirmed', 'policy_agreed' => 1]);
$AF_before = (int) mam_db_row('SELECT COUNT(*) n FROM course_enrollments WHERE student_id = ?', [$AF_first])['n'];
$AF_enr = mam_summer_enrol($AF_sum);
t_eq($AF_enr['reason'], 'identity_held', 'the second child is held: the address belongs to the first');
t_eq((int) mam_db_row('SELECT COUNT(*) n FROM course_enrollments WHERE student_id = ?', [$AF_first])['n'], $AF_before, 'nothing is enrolled into the first child\'s account');
t_ok((string) mam_db_row('SELECT identity_hold FROM summer_applications WHERE id = ?', [$AF_sum])['identity_hold'] !== '', 'the hold is stored on the application for the admin');
$AF_set = mam_summer_set_status($AF_sum, 'enrolled');
t_ok($AF_set['held'] && !$AF_set['ok'] && str_contains($AF_set['message'], 'Kemi'), 'marking it enrolled is refused with a sentence naming who applied');
t_eq((string) mam_db_row('SELECT status FROM summer_applications WHERE id = ?', [$AF_sum])['status'], 'confirmed', 'held = not enrolled: the status does not move');
$AF_ok = mam_summer_set_status($AF_sum, 'enrolled', null, null, ['identity_confirmed' => true]);
t_ok($AF_ok['ok'] && $AF_ok['changed'], 'once the office confirms it is the same person, it enrols');
t_eq((string) mam_db_row('SELECT identity_hold FROM summer_applications WHERE id = ?', [$AF_sum])['identity_hold'], '', 'and the hold is cleared');
t_ok(str_contains($AF_src('api/modules/admissions/lib/admissions.php'), 'mam_identity_conflict($who, $submitted)'), 'the summer path uses the same identity rule as the year-round path');

/* ── 3 ─────────────────────────────────────────────────────────────────── */
t_group('admissions fix 3 — a decision emails the family, and a refusal must say why');

[, $AF_offer] = $AF_app(['email' => "offer.$AF_tag@test.local", 'first_name' => 'Ife', 'last_name' => 'Offer', 'dob' => '1995-01-01'],
    ['pathway' => 'standard', 'discipline' => 'piano', 'level' => 'cert']);
$AF_r = mam_admissions_decide($AF_offer, 'accepted', null, null);
t_ok($AF_r['changed'] && $AF_status($AF_offer) === 'accepted', 'an offer moves the application through the state machine');
t_ok($AF_mail('application_decision_accepted', "offer.$AF_tag@test.local") !== null, 'and the applicant is emailed (mail_log)');
t_ok(mam_db_row("SELECT 1 FROM application_events WHERE application_id = ? AND to_status = 'accepted'", [$AF_offer]) !== null, 'the move is in application_events');
[, $AF_wait] = $AF_app(['email' => "wait.$AF_tag@test.local", 'first_name' => 'Wale', 'last_name' => 'Wait', 'dob' => '1990-01-01'], ['pathway' => 'crash', 'discipline' => 'piano']);
mam_admissions_decide($AF_wait, 'waitlisted');
t_ok($AF_mail('application_decision_waitlisted', "wait.$AF_tag@test.local") !== null, 'a waiting-list place is emailed');
[, $AF_no] = $AF_app(['email' => "no.$AF_tag@test.local", 'first_name' => 'Nora', 'last_name' => 'No', 'dob' => '1990-01-01'], ['pathway' => 'crash', 'discipline' => 'piano']);
t_eq($AF_throws(fn() => mam_admissions_decide($AF_no, 'cancelled', null, '  ')), 'reason_required', 'a refusal with no reason is refused');
t_eq($AF_status($AF_no), 'submitted', 'and nothing moved');
mam_admissions_decide($AF_no, 'cancelled', null, 'The Crash Programme is full for this intake.');
t_ok($AF_mail('application_decision_cancelled', "no.$AF_tag@test.local") !== null, 'a refusal is emailed');
$AF_msg = mam_admissions_decision_message(mam_db_row('SELECT * FROM applications WHERE id = ?', [$AF_no]), 'cancelled',
    'The Crash Programme is full <b>for</b> this intake.', mam_application_contact(mam_db_row('SELECT * FROM applications WHERE id = ?', [$AF_no])));
t_ok(str_contains($AF_msg['html'], 'The Crash Programme is full &lt;b&gt;for&lt;/b&gt; this intake.'), 'with the reason in the family\'s words, escaped');
t_ok(str_contains(mam_application_status(mam_db_row('SELECT reference FROM applications WHERE id = ?', [$AF_no])['reference'], "no.$AF_tag@test.local")['next_step'], 'Crash Programme is full'),
    '/status shows the reason, as the status vocabulary promised');
t_eq($AF_throws(fn() => mam_admissions_decide($AF_wait, 'paid')), 'not_a_decision', '"paid" is not a decision');
$AF_idx = $AF_src('admin/index.php');
$AF_as = substr($AF_idx, (int) strpos($AF_idx, "if (\$action === 'app_status')"));
$AF_as = substr($AF_as, 0, (int) strpos($AF_as, "if (\$action === 'summer_set_status')"));
t_ok(str_contains($AF_as, 'mam_admissions_decide(') && str_contains($AF_as, 'mam_admissions_decide_refusal('), 'the console decision handler goes through mam_admissions_decide and says why a refusal failed');
// Summer decisions too.
$AF_s2 = (int) mam_db_insert('summer_applications', ['reference' => 'SUM-AFR' . strtoupper($AF_tag), 'first_name' => 'Rex', 'last_name' => 'Ray',
    'email' => "rex.$AF_tag@test.local", 'dob' => '2000-01-01', 'age_at_submission' => 26, 'discipline' => 'piano', 'session_year' => (int) date('Y'), 'status' => 'submitted', 'policy_agreed' => 1]);
t_eq($AF_throws(fn() => mam_summer_set_status($AF_s2, 'rejected', null, '')), 'reason_required', 'a summer refusal needs a reason too');
mam_summer_set_status($AF_s2, 'confirmed');
t_ok($AF_mail('summer_decision_confirmed', "rex.$AF_tag@test.local") !== null, 'a confirmed summer place is emailed');

/* ── 4 ─────────────────────────────────────────────────────────────────── */
t_group('admissions fix 4 — the Scholars Initiative and faculty applications are visible and answered');

mam_test_env(['NOTIFY_EMAIL' => 'office.af@test.local']);
$AF_sch = mam_scholarship_create(['full_name' => 'Sade Scholar', 'email' => "sade.$AF_tag@test.local", 'age_band' => '16-20', 'experience' => 'some',
    'financial_need' => 'Need help please', 'musical_journey' => 'Church choir ten years', 'values_alignment' => 'Service and craft',
    'community_impact' => 'Teach the children choir', 'commitment_ack' => 1, 'application_reference' => null, 'readiness_signal' => 'ready']);
$AF_n = mam_scholarship_notify($AF_sch['reference']);
t_ok($AF_mail('scholarship_confirmation', "sade.$AF_tag@test.local") !== null, 'the applicant gets a confirmation (mail_log)');
t_ok($AF_mail('scholarship_admin_notice', 'office.af@test.local') !== null, 'and the office gets a notice');
mam_test_env(['NOTIFY_EMAIL' => null]);
t_ok(str_contains($AF_src('api/routes/scholarship-apply.php'), 'mam_scholarship_notify('), 'the public form sends both');
$AF_st = mam_scholarship_status_view(strtolower($AF_sch['reference']), "sade.$AF_tag@test.local");
t_ok($AF_st !== null && $AF_st['stage'] === 'received' && $AF_st['programme'] === 'MAM Scholars Initiative', '/status answers an SCH- reference');
t_ok(str_contains($AF_src('api/routes/application/status.php'), "stripos(trim(\$ref), 'SCH-') === 0"), 'the status route routes SCH- to the scholarship table');
$AF_board = mam_console_admissions(['type' => 'scholarship']);
t_ok(in_array($AF_sch['reference'], array_column($AF_board['scholar_rows'], 'reference'), true), 'the console lists it, from scholarship_applications');
t_ok(!str_contains($AF_src('api/modules/admin/lib/console.php'), "pathway = 'scholarship'"), 'the dead pathway=\'scholarship\' filter is gone');
foreach (['assets/js/config.js', 'api/modules/integrations/lib/mailer.php', 'index.php', 'about.php', 'pathways.php', 'programmes.php'] as $AF_f) {
    t_ok(!str_contains($AF_src($AF_f), 'NGV Scholarship'), "$AF_f says MAM Scholars Initiative, not NGV Scholarship");
}
// Faculty.
$AF_inv = mam_faculty_request("teach.$AF_tag@test.local", 'Tayo Teach', 'contact');
$AF_fac = mam_console_faculty([]);
t_ok(in_array($AF_inv, array_map('intval', array_column($AF_fac['requests'], 'id')), true), 'a teaching request is listed on the Faculty applications screen');
t_ok(in_array('faculty', mam_console_screens(), true) && mam_console_area_of('faculty')['key'] === 'admissions', 'which is a console screen in Admissions');
$AF_fpart = $AF_src('partials/admin-console/faculty.php');
foreach (['faculty_request_review', 'faculty_invite', 'faculty_app_status', 'faculty_assign_subjects'] as $AF_a) {
    t_ok(str_contains($AF_fpart, "'$AF_a', 'faculty'"), "the screen posts to the existing `$AF_a` handler");
}
t_ok(mam_faculty_app_can_transition('submitted', 'reviewing') && mam_faculty_app_can_transition('reviewing', 'approved'), 'an application moves forward');
t_ok(!mam_faculty_app_can_transition('declined', 'approved') && !mam_faculty_app_can_transition('approved', 'declined'), 'a declined one cannot be approved without reopening, an approved one is not silently declined');
$AF_fs = substr($AF_idx, (int) strpos($AF_idx, "if (\$action === 'faculty_app_status')"), 2500);
t_ok(str_contains($AF_fs, 'mam_faculty_app_can_transition('), 'the handler honours the transition set');
t_ok(str_contains($AF_src('api/routes/faculty-apply.php'), "/admin/console.php?v=faculty"), 'the office email links the Faculty screen, not ?v=users#faculty');
t_ok(!str_contains($AF_src('api/routes/faculty-apply.php'), '/book') && !str_contains($AF_src('instructor-apply.php'), 'href="/book"'),
    'no instructor is sent to /book (the applicant fee page); they are told the panel emails a time');

/* ── 5 ─────────────────────────────────────────────────────────────────── */
t_group('admissions fix 5 — no course-less accounts; the Auditer placement uses the one enrolment path; a waiver is recorded');

$AF_part = $AF_src('partials/admin-console/admissions.php');
t_ok(str_contains($AF_part, 'array_intersect(mam_admissions_transitions()[$cur] ?? [], MAM_ADMISSIONS_DECISIONS)'), 'the Decision card offers decisions only');
t_ok(!in_array('paid', MAM_ADMISSIONS_DECISIONS, true) && !in_array('enrolled', MAM_ADMISSIONS_DECISIONS, true), 'Paid and Enrolled are not on it');
t_ok(!str_contains($AF_as, 'mam_ensure_student_user') && !str_contains($AF_as, 'mam_card_issue_for_user'), 'and a status change makes no account and no ID card');
mam_automation_set('auditer_placement', true);
[$AF_pc, $AF_pa] = $AF_app(['email' => "place.$AF_tag@test.local", 'first_name' => 'Pele', 'last_name' => 'Place', 'dob' => '1992-03-03'],
    ['pathway' => 'standard', 'discipline' => 'violin']);
$AF_rec = mam_auditer_record($AF_pc, 'standard', ['application_id' => $AF_pa, 'course_code' => 'piano', 'level_code' => 'cert']);
t_ok($AF_rec['enrolled'] && $AF_rec['programme'] === 'standard', 'the placement enrols through mam_enrol_complete, with the programme (not NULL)');
$AF_ce = mam_db_row('SELECT ce.status, co.program_id FROM course_enrollments ce JOIN cohorts co ON co.id = ce.cohort_id WHERE ce.id = ?', [(int) $AF_rec['enrollment_id']]);
t_eq((int) ($AF_ce['program_id'] ?? 0), (int) mam_db_row("SELECT id FROM programs WHERE code = 'standard'")['id'], 'the class it lands in carries the programme');
t_eq((string) ($AF_ce['status'] ?? ''), 'active', 'and the enrolment is active, not left pending');
t_eq($AF_status($AF_pa), 'enrolled', 'and the application is marked enrolled (it stayed "accepted")');
[, $AF_wa] = $AF_app(['email' => "waive.$AF_tag@test.local", 'first_name' => 'Wura', 'last_name' => 'Waive', 'dob' => '1992-03-03'], ['pathway' => 'standard', 'discipline' => 'piano']);
t_ok(!mam_enrol_fee_settled(mam_db_row('SELECT * FROM applications WHERE id = ?', [$AF_wa])), 'an application with no payment has not settled its registration fee');
t_eq($AF_throws(fn() => mam_enrol_record_waiver($AF_wa, null, '')), 'reason_required', 'a waiver needs a reason');
mam_enrol_record_waiver($AF_wa, null, 'MAM Scholar — fee covered');
$AF_w = json_decode((string) mam_db_row('SELECT fee_waiver FROM applications WHERE id = ?', [$AF_wa])['fee_waiver'], true);
t_eq($AF_w['reason'] ?? null, 'MAM Scholar — fee covered', 'the waiver is recorded on the application');
t_ok(mam_enrol_fee_settled(mam_db_row('SELECT * FROM applications WHERE id = ?', [$AF_wa])), 'and counts as settled from then on');
$AF_ec = substr($AF_idx, (int) strpos($AF_idx, "if (\$action === 'enrol_complete')"), 4000);
t_ok(strpos($AF_ec, "empty(\$_POST['waive_fee'])") !== false && strpos($AF_ec, "empty(\$_POST['waive_fee'])") < strpos($AF_ec, 'mam_enrol_complete('),
    'Complete enrolment refuses an unpaid fee without the waiver, before enrolling');

/* ── 6 ─────────────────────────────────────────────────────────────────── */
t_group('admissions fix 6 — tuition, refund and delete go through the state machine');

[$AF_ec1, $AF_en] = $AF_app(['email' => "enr.$AF_tag@test.local", 'first_name' => 'Eno', 'last_name' => 'Enr', 'dob' => '1992-03-03'], ['pathway' => 'standard', 'discipline' => 'piano', 'status' => 'enrolled']);
mam_payment_settle_tuition($AF_en);
t_eq($AF_status($AF_en), 'enrolled', 'settling tuition never moves an enrolled application back to paid');
[, $AF_cx] = $AF_app(['email' => "cx.$AF_tag@test.local", 'first_name' => 'Cee', 'last_name' => 'Cx', 'dob' => '1992-03-03'], ['pathway' => 'standard', 'discipline' => 'piano', 'status' => 'cancelled']);
t_eq(mam_payment_settle_tuition($AF_cx), 'application_closed', 'a cancelled application is not reopened by a payment');
t_eq($AF_status($AF_cx), 'cancelled', 'it stays cancelled');
[, $AF_pd] = $AF_app(['email' => "pd.$AF_tag@test.local", 'first_name' => 'Pat', 'last_name' => 'Pd', 'dob' => '1992-03-03'], ['pathway' => 'standard', 'discipline' => 'violin']);
mam_automation_set('payment_provision', false);
mam_payment_settle_tuition($AF_pd);
mam_automation_set('payment_provision', true);
t_eq($AF_status($AF_pd), 'paid', 'a submitted application becomes paid');
t_ok(mam_db_row("SELECT 1 FROM application_events WHERE application_id = ? AND to_status = 'paid'", [$AF_pd]) !== null, 'through application_events');
$AF_pay = (int) mam_db_insert('payments', ['application_id' => $AF_pd, 'reference' => 'AF-REF-' . $AF_tag, 'provider' => 'bank', 'purpose' => 'tuition', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'NGN']);
mam_payment_refund_apply(mam_db_row('SELECT * FROM payments WHERE id = ?', [$AF_pay]), 'family withdrew');
t_eq($AF_status($AF_pd), 'in_review', 'a tuition refund returns a PAID application to review');
t_ok(mam_db_row("SELECT 1 FROM application_events WHERE application_id = ? AND to_status = 'in_review' AND reason LIKE 'tuition refunded%'", [$AF_pd]) !== null, 'recorded with the reason');
$AF_pay2 = (int) mam_db_insert('payments', ['application_id' => $AF_en, 'reference' => 'AF-REF2-' . $AF_tag, 'provider' => 'bank', 'purpose' => 'tuition', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'NGN']);
mam_payment_refund_apply(mam_db_row('SELECT * FROM payments WHERE id = ?', [$AF_pay2]), 'x');
t_eq($AF_status($AF_en), 'enrolled', 'a refund never re-opens an enrolled application (it used to set in_review from any status)');
$AF_del = substr($AF_idx, (int) strpos($AF_idx, "if (\$action === 'app_delete')"), 2000);
t_ok(str_contains($AF_del, "=== 'enrolled'") && str_contains($AF_del, 'mam_admissions_transition($aid, \'cancelled\''), 'app_delete refuses an enrolled application and otherwise uses the state machine');
t_ok(!str_contains($AF_del, "mam_db_update('applications', ['status' => 'cancelled'"), 'with no raw status write');
t_ok(!str_contains($AF_src('api/modules/finance/lib/payments.php'), "mam_db_update('applications', ['status' =>"), 'payments.php writes no application status raw');

/* ── 7 ─────────────────────────────────────────────────────────────────── */
t_group('admissions fix 7 — summer is decided in the console, through one implementation, on the right capability');

$AF_ss = substr($AF_idx, (int) strpos($AF_idx, "if (\$action === 'summer_set_status')"), 2500);
t_ok(str_contains($AF_ss, 'mam_summer_set_status(') && !str_contains($AF_ss, "mam_db_update('summer_applications'"), 'the form twin calls the one implementation, never a raw write');
t_ok(str_contains($AF_src('api/routes/admin/summer.php'), 'mam_summer_set_status('), 'so does the JSON route');
t_ok(str_contains($AF_src('api/index.php'), "\$r->post('/summer/status',  \$f('admin/summer.php'), [mw_csrf(), mw_admin_cap('admissions')]);"), 'the route needs `admissions`, not `view`');
t_eq($AF_throws(fn() => mam_summer_set_status($AF_s2, 'submitted')), 'illegal_transition', 'the state machine is enforced (confirmed cannot go back to submitted)');
$AF_sb = mam_console_admissions(['type' => 'summer']);
t_ok(in_array('SUM-AFR' . strtoupper($AF_tag), array_column($AF_sb['summer_rows'], 'reference'), true), 'the console lists summer applications (it only counted them)');

/* ── 8 ─────────────────────────────────────────────────────────────────── */
t_group('admissions fix 8 — under 18 is a child: the guardian signs, the child has their own record');

$AF_base = ['firstName' => 'Lara', 'lastName' => 'Teen', 'phone' => '+2348000000001', 'pathway' => 'standard', 'mode' => 'online', 'level' => 'cert',
    'expression' => 'classical', 'program' => 'classical', 'discipline' => 'piano', 'sex' => 'female', 'address' => '1 Road', 'lga' => 'Ikeja', 'state' => 'Lagos',
    'policyAgreed' => true, 'dob' => date('Y-m-d', strtotime('-17 years -2 days'))];
t_ok(is_string($AF_throws(fn() => mam_validate_application($AF_base + ['email' => 'lara@test.local', 'policySignedName' => 'Lara Teen']))),
    'a 17-year-old cannot apply without a parent or guardian (it was under 16)');
$AF_v = mam_validate_application($AF_base + ['email' => '', 'parentName' => 'Gbenga Teen', 'parentEmail' => 'gbenga@test.local', 'policySignedName' => 'Gbenga Teen']);
t_ok($AF_v['isMinor'] && mam_email_is_placeholder($AF_v['email']), 'a child with no email gets a record of their own, under a placeholder');
t_ok($AF_v['email'] !== 'gbenga@test.local', 'never the guardian\'s address');
$AF_v2 = mam_validate_application($AF_base + ['email' => 'Gbenga@test.local', 'parentName' => 'Gbenga Teen', 'parentEmail' => 'gbenga@test.local', 'policySignedName' => 'Gbenga Teen']);
t_eq($AF_v2['email'], $AF_v['email'], 'the guardian\'s address typed as the child\'s is treated as "none", and the same child maps to the same record');
$AF_v3 = mam_validate_application($AF_base + ['email' => 'lara.own@test.local', 'parentName' => 'Gbenga Teen', 'parentEmail' => 'gbenga@test.local', 'policySignedName' => 'Gbenga Teen']);
t_eq($AF_v3['email'], 'lara.own@test.local', 'a child may have their own email');
t_ok(is_string($AF_throws(fn() => mam_validate_application($AF_base + ['email' => '', 'parentName' => 'Gbenga Teen', 'parentEmail' => 'gbenga@test.local', 'policySignedName' => 'Lara Teen']))),
    'the guardian signs for a 17-year-old');
t_ok(!mam_email_is_erased($AF_v['email']) && mam_email_is_erased('erased-c1@deleted.invalid'), 'a placeholder is not an erasure tombstone');
// The account is made with the placeholder, and the guardian link works.
$AF_kc = (int) mam_db_insert('customers', ['email' => $AF_v['email'], 'first_name' => 'Lara', 'last_name' => 'Teen', 'dob' => $AF_v['dob']]);
$AF_kid = (int) mam_ensure_student_user($AF_kc)['user']['id'];
$AF_ka = ['parent_email' => 'gbenga@test.local', 'parent_name' => 'Gbenga Teen', 'photo_consent' => 1, 'media_consent' => null];
$AF_link = mam_application_link_guardian($AF_ka, $AF_kid);
t_ok($AF_link['linked'], 'the guardian is linked and invited (it was refused as same_as_student)');
t_eq(mam_mail_route_placeholder($AF_v['email']), 'gbenga@test.local', 'mail for the child goes to the guardian');
t_eq(mam_mail_route_placeholder('someone@test.local'), 'someone@test.local', 'a real address is untouched');
t_eq(mam_auth_send_invite($AF_kid, 'student', 'portal_welcome'), null, 'no sign-in link is minted for a child with no email');
t_ok(mam_application_record_consent($AF_ka, $AF_kid), 'the photo consent given at intake is recorded on the account');
$AF_cs = mam_consent_get($AF_kid);
t_ok($AF_cs['photo'] === true && $AF_cs['media'] === null, 'yes stays yes, and an unanswered question stays unanswered (not a no)');
$AF_vc = mam_validate_application($AF_base + ['email' => '', 'parentName' => 'Gbenga Teen', 'parentEmail' => 'gbenga@test.local', 'policySignedName' => 'Gbenga Teen', 'photoConsent' => 'no', 'mediaConsent' => 'later']);
t_ok($AF_vc['photoConsent'] === 0 && $AF_vc['mediaConsent'] === null, 'intake consent is three-state');
foreach (['mam_payment_settle_tuition' => 'api/modules/finance/lib/payments.php', 'mam_enrol_complete' => 'api/modules/admissions/lib/enrol-complete.php', 'mam_auditer_record' => 'api/modules/admissions/lib/auditer.php'] as $AF_fn => $AF_file) {
    t_ok(str_contains($AF_src($AF_file), 'mam_application_record_consent('), "$AF_fn records the intake consent where it makes the account");
}
[, $AF_ga] = $AF_app(['email' => $AF_v['email'] . 'x', 'first_name' => 'Lara', 'last_name' => 'Teen', 'dob' => $AF_v['dob']], ['pathway' => 'standard', 'discipline' => 'piano', 'parent_email' => 'gbenga@test.local', 'parent_name' => 'Gbenga Teen']);
$AF_gc = mam_application_contact(mam_db_row('SELECT * FROM applications WHERE id = ?', [$AF_ga]));
t_ok($AF_gc['guardian'] && $AF_gc['email'] === 'gbenga@test.local' && $AF_gc['greet'] === 'Gbenga', 'a child\'s correspondence goes to, and greets, the guardian');
$AF_gh = mam_email_application_confirmation(['reference' => 'MAM-AFG', 'pathway' => 'standard', 'mode' => 'online', 'discipline' => 'piano', 'policy_mode' => null],
    ['first_name' => 'Lara'], ['label' => '$5'], '', ['greet' => 'Gbenga', 'learner' => 'Lara']);
t_ok(str_contains($AF_gh, 'Thank you, Gbenga.') && str_contains($AF_gh, 'Lara&rsquo;s application is in') && !str_contains($AF_gh, 'Welcome aboard'), 'the confirmation greets the guardian, not the child');
t_ok(str_contains($AF_flow, 'var ADULT = 18;') && str_contains($AF_flow, 'a != null && a < ADULT') && !str_contains($AF_flow, 'a < 16'), 'the form asks for a guardian under 18');
t_ok(str_contains($AF_flow, 'var email = g ? f.learnerEmail.trim() : f.email.trim();'), 'and no longer files the child under the guardian\'s address');
t_ok(str_contains($AF_flow, "case 'consent':") && str_contains($AF_flow, "photoConsent: tri(f.photo), mediaConsent: tri(f.media)"), 'and asks for photo and recording consent');
t_ok(str_contains(mam_application_status(mam_db_row('SELECT reference FROM applications WHERE id = ?', [$AF_ga])['reference'], 'gbenga@test.local')['reference'] ?? '', 'MAM-'),
    '/status finds a child\'s application by the guardian\'s email');

/* ── 9 ─────────────────────────────────────────────────────────────────── */
t_group('admissions fix 9 — an offer is not a payment; the wording matches the policy; the funnel is honest');

t_ok(!mam_invoice_paid(['id' => 0, 'status' => 'accepted']), 'an accepted application has not paid its processing fee');
t_ok(mam_invoice_paid(['id' => 0, 'status' => 'enrolled']), 'an enrolled one has');
t_ok(!str_contains($AF_card, '48 hours') && str_contains($AF_card, 'lapses'), 'the confirmation states the real reminder/expiry policy, not "48 hours"');
$AF_pol = mam_invoice_policy();
t_ok(str_contains($AF_card, (int) $AF_pol['expiry_days'] . ' days'), 'with the expiry days the sweep actually uses');
t_ok(!function_exists('mam_app_draft_nudge_sweep'), 'the dead draft_nudge job is removed');
t_ok(!str_contains($AF_src('partials/admin-console/funnel.php'), 'Half-finished'), 'and the "half-finished" figure that read a table nothing writes');
mam_funnel_record(['event' => 'tool_open', 'sid' => 'af-studio-' . $AF_tag, 'pathway' => 'tuner']);
$AF_fun0 = mam_console_funnel()['starts'];
mam_funnel_record(['event' => 'apply_start', 'sid' => 'af-apply-' . $AF_tag, 'step' => 1]);
t_eq(mam_console_funnel()['starts'], $AF_fun0 + 1, 'the funnel counts the application form, not Studio visitors');
t_ok(str_contains($AF_flow, "track('apply_step_view', k)") && str_contains($AF_flow, "track('apply_submit')"), 'and the form reports its screens again');
t_ok(str_contains($AF_src('api/routes/payment-init.php'), "&& (in_array((string)(\$app['status'] ?? ''), ['paid', 'enrolled'], true)"), 'payment-init no longer refuses tuition from an accepted applicant as "already paid"');

/* ── 10 ────────────────────────────────────────────────────────────────── */
t_group('admissions fix 10 — instruments that are not courses are flagged for the assessment');

t_eq(mam_enrol_course_code('rhythm'), 'rhythm_pitch', '"rhythm" is the Rhythm & Pitch course');
t_eq(mam_enrol_course_code('metamusic'), 'meta_music', '"metamusic" is the Meta Music course');
t_eq(mam_enrol_course_code('piano'), 'piano', 'a course is itself');
foreach (['violin', 'trumpet', 'flute', 'clarinet', 'performance', 'undecided', 'bass', 'multiple'] as $AF_i) {
    t_eq(mam_enrol_course_code($AF_i), null, "\"$AF_i\" is not a course of its own");
}
[, $AF_vi] = $AF_app(['email' => "vio.$AF_tag@test.local", 'first_name' => 'Vi', 'last_name' => 'Olin', 'dob' => '1990-01-01'], ['pathway' => 'standard', 'discipline' => 'violin', 'level' => null]);
$AF_va = mam_db_row('SELECT * FROM applications WHERE id = ?', [$AF_vi]);
$AF_need = (string) mam_enrol_needs_assessment($AF_va);
t_ok(str_contains($AF_need, 'violin') && str_contains($AF_need, 'level'), 'the application is flagged for the assessment, naming the instrument and the level');
$AF_rz = mam_enrol_resolve($AF_vi);
t_ok(!empty($AF_rz['assessment']) && str_contains((string) $AF_rz['blockers'][0]['why'], 'Flagged for the assessment'), 'Complete enrolment says so, and asks for the course the assessment chose');
$AF_board2 = mam_console_admissions(['type' => 'assess']);
t_ok(in_array($AF_vi, array_map('intval', array_column($AF_board2['rows'], 'id')), true), 'the console lists it under "Needs assessment"');
[, $AF_rh] = $AF_app(['email' => "rh.$AF_tag@test.local", 'first_name' => 'Rhy', 'last_name' => 'Thm', 'dob' => '1990-01-01'], ['pathway' => 'crash', 'discipline' => 'rhythm']);
t_eq(mam_enrol_resolve($AF_rh)['course']['code'] ?? null, 'rhythm_pitch', 'an older alias resolves to its course in Complete enrolment');
$AF_lv = mam_validate_application(array_merge($AF_base, ['level' => '']) + ['email' => '', 'parentName' => 'Gbenga Teen', 'parentEmail' => 'gbenga@test.local', 'policySignedName' => 'Gbenga Teen']);
t_eq($AF_lv['level'], '', '"Not sure" stores no level (it was Pre-Certificate)');
t_ok(str_contains($AF_route, "'level'               => \$v['level'] !== '' ? \$v['level'] : 'precert',"), 'and the price is estimated at the starting level, so it is never $0');
t_ok(mam_compute_application_price(['programme' => 'standard', 'level' => 'precert', 'mode' => 'onsite'])['total'] > 0, 'which prices');
t_ok(str_contains($AF_flow, "level: p.levelled && f.level !== 'unsure' ? f.level : ''"), 'the form sends no level for "Not sure"');
t_ok(str_contains($AF_flow, "case 'more':") && str_contains($AF_flow, "coursesLabel: p.k === 'grand'"), 'Grand Musician captures its three disciplines (in courses_label)');

/* ── medium ────────────────────────────────────────────────────────────── */
t_group('admissions — words, not codes; no filter every row matches');

t_eq(mam_console_payment_status_label('pending_bank_transfer', 'booking_fee'), 'Registration fee awaiting the bank transfer', 'the Money row never shows "pending_bank_transfer"');
t_ok(!str_contains($AF_part, "'audition' =>") && !str_contains($AF_src('api/modules/admin/lib/console.php'), 'auditer_optin = 1'),
    'the Audition filter (every application matched it) is gone, and the Auditions screen\'s "booked" list reads a paid booking fee instead');
