<?php
/**
 * Guardian↔staff threads, channel preferences, and make-up credits
 * (api/modules/comms/lib/guardian-comms.php, schema v88).
 *
 * Three refusals carry this file, and each is the kind that gets "simplified"
 * away by a later change that looks like a convenience:
 *   1. a student is never a participant in a guardian thread;
 *   2. no preference can mute a message about safeguarding or money;
 *   3. a teacher cancellation always credits and never costs an allowance slot.
 */
declare(strict_types=1);

t_group('comms — a student is never a participant in a guardian thread');

$GC_stu = mam_db_insert('users', ['name' => 'Comms Kid', 'email' => 'commskid@test.local',
    'role' => 'student', 'password_hash' => 'x', 'status' => 'active', 'phone' => '+2348000000001']);
$GC_ins = mam_db_insert('users', ['name' => 'Mr Adeyemi', 'email' => 'commsins@test.local',
    'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$GC_office = mam_db_insert('users', ['name' => 'Academy Office', 'email' => 'commsoffice@test.local',
    'role' => 'admin', 'password_hash' => 'x', 'status' => 'active']);
$GC_par = (int) mam_parent_link('commsguardian@test.local', $GC_stu)['parent_id'];
mam_db_update('users', ['phone' => '+2348000000002'], ['id' => $GC_par]);
$GC_par2 = (int) mam_parent_link('commsguardian2@test.local', $GC_stu)['parent_id'];

$GC_th = mam_thread_open($GC_stu, 'Saturday rhythm catch-up', 'academic', $GC_ins);
t_ok($GC_th > 0, 'an instructor opens a thread about a child');

t_throws(fn() => mam_thread_add_participant($GC_th, $GC_stu), 'student_not_a_participant',
    'the CHILD the thread is about cannot be added — no guardian scope reaches a young person\'s own messages');

$GC_otherKid = mam_db_insert('users', ['name' => 'Another Kid', 'email' => 'commskid2@test.local',
    'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
t_throws(fn() => mam_thread_add_participant($GC_th, $GC_otherKid), 'student_not_a_participant',
    'and neither can any other student — the guard is on the ACCOUNT, not on a role label a caller passes');

// The participant role is DERIVED, so there is no signature through which
// "add this student as an office contact" can be expressed.
$GC_roles = array_column(mam_thread_get($GC_th, $GC_ins)['participants'], 'role');
t_ok(!in_array('student', $GC_roles, true), 'no participant carries a student role');
t_ok(in_array('instructor', $GC_roles, true), 'the instructor who opened it is a participant');
t_ok(in_array('guardian', $GC_roles, true), 'and so are the guardians');

// BOTH guardians of a household are added — the commonest failure of a school
// messaging system is that the reply went to the parent who was not doing the run.
$GC_parts = mam_db_all('SELECT user_id FROM guardian_thread_participants WHERE thread_id = ?', [$GC_th]);
$GC_ids = array_map(fn($r) => (int) $r['user_id'], $GC_parts);
t_ok(in_array($GC_par, $GC_ids, true) && in_array($GC_par2, $GC_ids, true),
    'every guardian whose scope reaches staff messages is on the thread, not just one');

t_group('comms — posting, reading, and who may do either');

$GC_stranger = mam_db_insert('users', ['name' => 'Comms Stranger', 'email' => 'commsstranger@test.local',
    'role' => 'parent', 'password_hash' => 'x', 'status' => 'active']);
t_throws(fn() => mam_thread_get($GC_th, $GC_stranger), 'forbidden', 'a non-participant cannot read a thread');
t_throws(fn() => mam_thread_post($GC_th, $GC_stranger, 'hello'), 'forbidden', 'nor post to one');
t_ok(!mam_thread_can_read($GC_th, $GC_stranger), 'and the predicate says so directly');

$GC_m1 = mam_thread_post($GC_th, $GC_ins, "Ada's report is with you now. I've booked her a twenty-minute rhythm catch-up on Saturday.");
t_ok($GC_m1 > 0, 'the instructor posts');
$GC_m2 = mam_thread_post($GC_th, $GC_par, 'Thank you — Saturday works.');
t_ok($GC_m2 > 0, 'and the guardian replies');
try { mam_thread_post($GC_th, $GC_par, '   '); t_ok(false, 'an empty message is refused'); }
catch (\InvalidArgumentException $e) { t_ok(true, 'an empty message is refused'); }

$GC_full = mam_thread_get($GC_th, $GC_par);
t_eq(count($GC_full['messages']), 2, 'the thread holds both messages, in order');
t_eq($GC_full['messages'][0]['author_role'], 'instructor', 'the first is the instructor\'s');
t_ok($GC_full['messages'][0]['author_name'] !== '', 'and carries a NAME — author_id goes NULL when a staff account is deleted');

// The inbox, and the unread flag.
$GC_box = mam_thread_inbox($GC_par);
t_ok(count($GC_box) >= 1, 'the guardian has an inbox');
t_ok($GC_box[0]['unread'] === true, 'with the thread unread, because they have not marked it read');
mam_thread_mark_read($GC_th, $GC_par);
t_ok(mam_thread_inbox($GC_par)[0]['unread'] === false, 'and read once they have');
t_ok($GC_box[0]['student_name'] !== '', 'each thread names the child it is about — a multi-child household is the default case');

mam_thread_close($GC_th, $GC_office);
t_throws(fn() => mam_thread_post($GC_th, $GC_par, 'one more thing'), 'thread_closed',
    'a closed thread takes no more messages');
t_eq(count(mam_thread_get($GC_th, $GC_par)['messages']), 2, 'but keeps the ones it had');

t_group('comms — no preference can mute safeguarding or money');

// Everything off.
mam_guardian_prefs_set($GC_par, ['whatsapp' => false, 'email' => false, 'sms' => false]);
$GC_p = mam_guardian_prefs($GC_par);
t_ok($GC_p['whatsapp'] === false && $GC_p['email'] === false && $GC_p['sms'] === false, 'a guardian can mute every channel');

$GC_gen = mam_guardian_channels($GC_par, 'general');
t_eq(count($GC_gen['channels']), 0, 'and a general notice then reaches them on none');
t_ok($GC_gen['override'] === false, 'with no override claimed');

foreach (MAM_THREAD_ALWAYS_REACH as $topic) {
    $GC_must = mam_guardian_channels($GC_par, $topic);
    t_ok(count($GC_must['channels']) > 0, "a '$topic' notice reaches them ANYWAY — the toggles do not apply to it");
    t_ok($GC_must['override'] === true, "and the override is declared rather than silent ($topic)");
    t_ok(strpos((string) $GC_must['reason'], 'safeguarding or money') !== false,
        "with the reason in words the screen can print ($topic)");
}

// There is nowhere in the schema to record "mute safeguarding".
$GC_prefCols = [];
foreach (mam_db_all('PRAGMA table_info(guardian_channel_prefs)') as $c) $GC_prefCols[strtolower($c['name'])] = true;
t_eq(count(array_intersect(array_keys($GC_prefCols), ['whatsapp', 'email', 'sms'])), 3, 'the three channels are there');
foreach (['safeguarding', 'money', 'topics', 'muted_topics'] as $forbidden) {
    t_ok(!isset($GC_prefCols[$forbidden]), "and there is no '$forbidden' column — that is not a preference the academy offers");
}

// A channel with no address is never returned, whatever the topic. Queueing an
// SMS to a guardian with no phone and reporting it sent is worse than not trying.
$GC_noPhone = mam_db_insert('users', ['name' => 'No Phone', 'email' => 'commsnophone@test.local',
    'role' => 'parent', 'password_hash' => 'x', 'status' => 'active']);
$GC_np = mam_guardian_channels($GC_noPhone, 'safeguarding');
t_ok(!in_array('sms', $GC_np['channels'], true), 'a guardian with no phone gets no SMS, even on a safeguarding notice');
t_ok(in_array('email', $GC_np['channels'], true), 'but does get the email they do have');

// SMS is opt-IN by default: it costs per message and reaches the same phone.
$GC_fresh = mam_db_insert('users', ['name' => 'Fresh Guardian', 'email' => 'commsfresh@test.local',
    'role' => 'parent', 'password_hash' => 'x', 'status' => 'active', 'phone' => '+2348000000003']);
$GC_fp = mam_guardian_prefs($GC_fresh);
t_ok($GC_fp['whatsapp'] === true && $GC_fp['email'] === true, 'WhatsApp and email are on by default');
t_ok($GC_fp['sms'] === false, 'SMS is off by default — it is opt-in, not opt-out');
t_ok($GC_fp['set'] === false, 'and the defaults are reported as defaults, not as a saved choice');

t_group('comms — make-up credits: five a year, and the academy pays for its own cancellations');

$GC_pol = mam_makeup_policy();
t_eq($GC_pol['notice_hours'], 24, "24 hours' notice earns a credit");
t_eq($GC_pol['allowance_year'], 5, 'five a year');
t_ok(count($GC_pol['rules']) >= 5, 'and the rules are DATA, so the screen states them where a credit is spent');
$GC_rulesText = implode(' ', $GC_pol['rules']);
t_ok(stripos($GC_rulesText, 'cannot be transferred') !== false, 'non-transferable is stated');
t_ok(stripos($GC_rulesText, 'no cash refund') !== false, 'no cash in lieu is stated');
t_ok(stripos($GC_rulesText, 'never counts against') !== false, 'and so is the academy-cancellation rule');

// There is no money column anywhere on a credit: a credit buys a lesson or it
// lapses, and making it refundable turns every cancellation into a negotiation.
$GC_mcCols = [];
foreach (mam_db_all('PRAGMA table_info(makeup_credits)') as $c) $GC_mcCols[strtolower($c['name'])] = true;
foreach (['amount', 'amount_minor', 'currency', 'value', 'refund_minor', 'payment_id', 'transferred_to'] as $forbidden) {
    t_ok(!isset($GC_mcCols[$forbidden]), "makeup_credits has no '$forbidden' column — no cash in lieu, and not transferable");
}
t_ok(isset($GC_mcCols['expires_on']), 'and it DOES have an expiry — a credit with none is a liability the academy cannot close a year on');

$GC_mkStu = mam_db_insert('users', ['name' => 'Makeup Kid', 'email' => 'makeupkid@test.local',
    'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$GC_mkPar = (int) mam_parent_link('makeupguardian@test.local', $GC_mkStu)['parent_id'];

$GC_b0 = mam_makeup_balance($GC_mkStu);
t_eq($GC_b0['available'], 0, 'a new student has no credits');
t_eq($GC_b0['allowance_left'], 5, 'and the full allowance');

// 24 hours' notice is required for a guardian cancellation.
t_throws(fn() => mam_makeup_grant($GC_mkStu, 'guardian_notice', ['notice_hours' => 3]), 'insufficient_notice',
    'three hours\' notice earns no credit');
t_throws(fn() => mam_makeup_grant($GC_mkStu, 'guardian_notice', []), 'insufficient_notice',
    'and neither does no stated notice at all');
$GC_c1 = mam_makeup_grant($GC_mkStu, 'guardian_notice', ['notice_hours' => 48], $GC_office);
t_ok($GC_c1 > 0, "but 48 hours' notice does");
t_eq(mam_makeup_balance($GC_mkStu)['allowance_left'], 4, 'and it costs an allowance slot');

/* ── The rule the design states twice ─────────────────────────────────────── */
$GC_c2 = mam_makeup_grant($GC_mkStu, 'teacher_cancellation', ['note' => 'Instructor unwell'], $GC_office);
$GC_b1 = mam_makeup_balance($GC_mkStu);
t_eq($GC_b1['available'], 2, 'a teacher cancellation grants a credit');
t_eq($GC_b1['allowance_left'], 4,
    'and does NOT cost an allowance slot — charging a family because the academy cancelled is the most resented thing a make-up policy can do');
t_eq($GC_b1['academy_credits'], 1,
    'the academy\'s own cancellations are disclosed separately, so a family can see they were not charged for them');
t_eq((int) mam_db_row('SELECT counts_against_allowance FROM makeup_credits WHERE id = ?', [$GC_c2])['counts_against_allowance'], 0,
    'the column says so, and the v88 CHECK forces it whatever a caller passes');

// A teacher cancellation needs no notice at all — the family gave none.
$GC_c3 = mam_makeup_grant($GC_mkStu, 'academy_closure', [], $GC_office);
t_ok($GC_c3 > 0, 'an academy closure credits with no notice requirement');
t_eq(mam_makeup_balance($GC_mkStu)['allowance_left'], 4, 'and costs nothing from the allowance either');

// The allowance is a real limit, on the rows that count.
for ($i = 0; $i < 4; $i++) mam_makeup_grant($GC_mkStu, 'guardian_notice', ['notice_hours' => 30], $GC_office);
t_eq(mam_makeup_balance($GC_mkStu)['allowance_left'], 0, 'four more guardian cancellations exhaust the five');
t_throws(fn() => mam_makeup_grant($GC_mkStu, 'guardian_notice', ['notice_hours' => 30]), 'allowance_exhausted',
    'and the sixth is refused');
t_ok(mam_makeup_grant($GC_mkStu, 'teacher_cancellation', [], $GC_office) > 0,
    'but a teacher cancellation is STILL granted with the allowance exhausted — it never came out of it');

// Spending takes the credit nearest its expiry first.
$GC_avail = mam_db_all("SELECT id, expires_on FROM makeup_credits WHERE student_id = ? AND status = 'available' ORDER BY expires_on ASC, id ASC", [$GC_mkStu]);
$GC_spent = mam_makeup_spend($GC_mkStu, 0, $GC_office);
t_eq($GC_spent, (int) $GC_avail[0]['id'],
    'spending takes the credit nearest its expiry — a LIFO queue silently lapses the one with the deadline');
t_eq(mam_db_row('SELECT status FROM makeup_credits WHERE id = ?', [$GC_spent])['status'], 'spent', 'and marks it spent');

// A spent credit still counts against the allowance for the year: the family used it.
t_eq(mam_makeup_balance($GC_mkStu)['allowance_left'], 0, 'a spent credit does not return an allowance slot');

// Expiry is derived from the date and swept on READ, so a credit lapsing at
// midnight reads as expired on the next page load, not the next cron run.
// `goodwill` is the academy's gift and costs the family nothing, which is why
// this still grants with the allowance exhausted.
$GC_old = mam_makeup_grant($GC_mkStu, 'goodwill', ['note' => 'Backdated'], $GC_office);
t_eq((int) mam_db_row('SELECT counts_against_allowance FROM makeup_credits WHERE id = ?', [$GC_old])['counts_against_allowance'], 0,
    'a goodwill credit is the academy\'s gift and never costs the family an allowance slot');
mam_db_update('makeup_credits', ['expires_on' => date('Y-m-d', strtotime('-1 day'))], ['id' => $GC_old]);
mam_makeup_balance($GC_mkStu);
t_eq(mam_db_row('SELECT status FROM makeup_credits WHERE id = ?', [$GC_old])['status'], 'expired',
    'a lapsed credit reads as expired on the next read, not the next sweep');

// Nothing left to spend once everything has lapsed or been used.
mam_db()->prepare("UPDATE makeup_credits SET status = 'expired' WHERE student_id = ? AND status = 'available'")->execute([$GC_mkStu]);
t_throws(fn() => mam_makeup_spend($GC_mkStu, 0), 'no_credit', 'and spending with nothing available is refused');

t_group('comms — the guardian view of credits is scope-gated');

$GC_mkAdmin = mam_db_insert('users', ['name' => 'MK Admin', 'email' => 'mkadmin@test.local',
    'role' => 'admin', 'password_hash' => 'x', 'status' => 'active']);
$GC_v = mam_makeup_for_guardian($GC_mkPar, $GC_mkStu);
t_ok($GC_v['scope_denied'] === false, 'a standard guardian sees the credits');
t_ok(isset($GC_v['allowance_left']), 'with the allowance position');

mam_parent_scope_set($GC_mkPar, $GC_mkStu, 'billing', $GC_mkAdmin);
$GC_vd = mam_makeup_for_guardian($GC_mkPar, $GC_mkStu);
t_ok($GC_vd['scope_denied'] === true, 'a billing-only guardian does not — credits are a timetable matter');
t_ok(isset($GC_vd['policy']),
    'but the POLICY is still returned, so the screen can explain the boundary rather than showing an empty panel');
mam_parent_scope_set($GC_mkPar, $GC_mkStu, 'standard', $GC_mkAdmin);

$GC_mkStranger = mam_db_insert('users', ['name' => 'MK Stranger', 'email' => 'mkstranger@test.local',
    'role' => 'parent', 'password_hash' => 'x', 'status' => 'active']);
t_throws(fn() => mam_makeup_for_guardian($GC_mkStranger, $GC_mkStu), 'forbidden',
    'and an unlinked adult sees nothing at all');
