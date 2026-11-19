<?php
/**
 * Member records (members.php, schema v94; HANDOFF §4w) — NGG's member rules,
 * ported: one status setter with a reason and a history, no locking yourself or
 * the academy out, staff not administered from a members list, and a member
 * number minted once, where an enrolment goes active.
 */
declare(strict_types=1);

$MB_mk = static function (string $email, string $role = 'student', array $extra = []): int {
    return mam_db_insert('users', $extra + ['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'role' => $role, 'password_hash' => 'x', 'status' => 'active']);
};
$MB_row = static fn(int $id) => mam_db_row('SELECT * FROM users WHERE id = ?', [$id]);

t_group('members — one status setter, with a reason and a history');

$MB_admin = $MB_mk('mbadmin@test.local', 'admin', ['admin_tier' => 'super']);
$MB_stu   = $MB_mk('mbstudent@test.local');
mam_db_insert('sessions', ['user_id' => $MB_stu, 'token_hash' => hash('sha256', 'mb-sess-1'), 'expires_at' => date('Y-m-d H:i:s', time() + 3600)]);

t_eq(mam_member_set_status($MB_stu, 'suspended', 'late', $MB_admin)['code'], 'reason_required', 'a suspension needs a reason a person can read later');
t_eq($MB_row($MB_stu)['status'], 'active', 'and without one nothing changes');
$MB_r = mam_member_set_status($MB_stu, 'suspended', 'Fees dispute escalated by the family', $MB_admin);
t_eq($MB_r['result'], 'applied', 'with a reason it is applied');
t_eq($MB_row($MB_stu)['status'], 'suspended', 'the account is suspended');
t_eq((int) mam_db_row('SELECT COUNT(*) n FROM sessions WHERE user_id = ?', [$MB_stu])['n'], 0, 'and their sessions end now, not at their next sign-in');
$MB_h = mam_member_status_history($MB_stu);
t_ok($MB_h && $MB_h[0]['from_status'] === 'active' && $MB_h[0]['to_status'] === 'suspended' && (int) $MB_h[0]['changed_by'] === $MB_admin
    && str_contains((string) $MB_h[0]['reason'], 'Fees dispute'), 'the history says from, to, who and why');
t_eq(mam_member_set_status($MB_stu, 'suspended', 'again and again', $MB_admin)['result'], 'unchanged', 'setting the status it already has is reported as unchanged');
t_eq(mam_member_set_status($MB_stu, 'active', '', $MB_admin)['result'], 'applied', 'reactivating needs no reason');
t_eq(count(mam_member_status_history($MB_stu)), 2, 'and is in the history too');
t_eq(mam_member_set_status($MB_stu, 'closed', 'erase them please', $MB_admin)['code'], 'bad_status', '"closed" is written only by erasure, never set by hand');

t_group('members — nobody locks themselves, or the academy, out');

t_eq(mam_member_set_status($MB_admin, 'suspended', 'Testing what happens here', $MB_admin)['code'], 'own_account', 'an operator cannot suspend their own account');
// Make $MB_admin the only active super admin for this group, and remember who to restore.
$MB_demoted = [];
foreach (mam_db_all("SELECT id, admin_tier FROM users WHERE status = 'active' AND id <> ?", [$MB_admin]) as $o) {
    if (mam_member_is_active_super(mam_db_row('SELECT * FROM users WHERE id = ?', [(int) $o['id']]))) {
        $MB_demoted[(int) $o['id']] = $o['admin_tier'];
        mam_db_update('users', ['admin_tier' => 'manager'], ['id' => (int) $o['id']]);
    }
}
$MB_mgr = $MB_mk('mbmanager@test.local', 'admin', ['admin_tier' => 'manager']);
t_eq(mam_member_set_status($MB_admin, 'suspended', 'Leaving the academy this week', $MB_mgr)['code'], 'last_super_admin',
    'the last active super administrator cannot be suspended — there would be nobody left who can undo it');
t_eq(mam_member_role_change_refusal($MB_admin, 'instructor', $MB_mgr), 'last_super_admin', 'nor demoted out of admin');
t_eq(mam_member_role_change_refusal($MB_admin, 'student', $MB_admin), 'own_account', 'and an operator cannot take admin away from themselves');
$MB_admin2 = $MB_mk('mbadmin2@test.local', 'admin', ['admin_tier' => 'super']);
t_eq(mam_member_set_status($MB_admin, 'suspended', 'Leaving the academy this week', $MB_admin2)['result'], 'applied', 'once there is another super administrator, it can be done');
mam_member_set_status($MB_admin, 'active', '', $MB_admin2);
foreach ($MB_demoted as $MB_id => $MB_tier) mam_db_update('users', ['admin_tier' => $MB_tier], ['id' => $MB_id]);

t_group('members — a members list does not administer staff');

$MB_inst = $MB_mk('mbinstructor@test.local', 'instructor');
t_eq(mam_member_set_status($MB_inst, 'suspended', 'Bulk suspension from a list', $MB_admin, ['members_only' => true])['code'], 'staff_account',
    'an instructor is refused from the members roster');
$MB_both = $MB_mk('mbboth@test.local');
mam_user_ensure_role($MB_both, 'instructor');
t_eq(mam_member_set_status($MB_both, 'suspended', 'Bulk suspension from a list', $MB_admin, ['members_only' => true])['code'], 'staff_account',
    'and so is a student who also teaches — staff by ANY role they hold');

t_group('members — staff notes on a person');

t_eq((function () use ($MB_stu, $MB_admin) { try { mam_member_note_add($MB_stu, ' ', $MB_admin); return 'none'; } catch (\Throwable $e) { return $e->getMessage(); } })(), 'note_required', 'an empty note is refused');
mam_member_note_add($MB_stu, 'Mother prefers WhatsApp for reminders.', $MB_admin);
mam_member_note_add($MB_stu, 'Sight-reading assessed at Grade 2.', $MB_admin);
$MB_n = mam_member_notes($MB_stu);
t_ok(count($MB_n) === 2 && str_contains($MB_n[0]['body'], 'Sight-reading') && $MB_n[0]['author_name'] === 'Mbadmin', 'notes are kept newest first, with who wrote them');

t_group('members — the member number is minted where an enrolment goes active');

$MB_new = $MB_mk('mbnew@test.local');
t_ok(empty($MB_row($MB_new)['enrollment_ref']), 'a new student has no number yet');
t_ok(mam_member_ensure_ref($MB_new) !== null && preg_match('#^(JE/)?MAM/\d{2}/\d{3,}$#', (string) $MB_row($MB_new)['enrollment_ref']) === 1, 'ensuring one mints MAM/YY/NNN');
$MB_ref = (string) $MB_row($MB_new)['enrollment_ref'];
t_eq(mam_member_ensure_ref($MB_new), $MB_ref, 'and asking again never reissues it — it is printed on a card');
t_eq(mam_member_ensure_ref($MB_inst), null, 'staff are not given a member number');
$MB_lms = (string) file_get_contents(dirname(__DIR__) . '/lib/lms.php');
t_ok(str_contains(substr($MB_lms, (int) strpos($MB_lms, 'function mam_lms_transition'), 4000), 'mam_member_ensure_ref('),
    'the enrolment transition mints it, so office, Summer and Auditer enrolments get one — only payment settlement did before');

t_group('members — every status change goes through the setter');

$MB_adm = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
t_ok(!preg_match("/mam_db_update\\('users', \\['status'/", $MB_adm), 'the admin handlers no longer write users.status directly');
t_ok(str_contains($MB_adm, "mam_member_set_status(\$uid, \$status"), 'user_status / user_delete call the one setter');
t_ok(str_contains($MB_adm, 'mam_member_role_change_refusal('), 'user_edit refuses a role change that would lock the academy out');
t_ok(str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/partials/admin-console/access.php'), "ac_action_btn('member_signout'"),
    'the console\'s Sign out ends THAT person\'s sessions — it posted session_revoke, which only ever touched the operator\'s own, and did nothing');

t_group('members roster — filters and the sort are allow-listed, totals are true');

$MB_q = 'mbroster';
$MB_ids = [];
for ($i = 1; $i <= 7; $i++) $MB_ids[] = $MB_mk("mbroster$i@test.local", 'student', ['name' => "Mbroster Pupil $i", 'phone' => $i <= 2 ? '08000000001' : null]);
mam_db_update('users', ['student_type' => 'scholarship'], ['id' => $MB_ids[0]]);
$MB_list = mam_console_students(['q' => $MB_q]);
t_eq((int) $MB_list['total'], 7, 'the total is the true count for the filter');
t_eq(mam_console_students(['q' => $MB_q, 'type' => 'scholarship'])['total'], 1, 'a fee-type facet narrows it');
t_eq(mam_console_students(['q' => $MB_q, 'missing' => 'number'])['total'], 7, '"no member number" finds the ones without');
$MB_bad = mam_console_students(['q' => $MB_q, 'sort' => 'name; DROP TABLE users', 'missing' => 'nonsense', 'stage' => 'gone']);
t_ok($MB_bad['sort'] === 'name' && (int) $MB_bad['total'] === 7, 'an unknown sort, facet or stage falls back to the default — a stale bookmark shows the roster, never an error or injected SQL');
t_ok(!array_key_exists('band', MAM_ROSTER_SORTS) && !preg_grep('/mastery|band/i', MAM_ROSTER_SORTS), 'and the band is not a sort key — sorting children by it would make the table a ranking');
t_eq(count(mam_console_students_ids(['q' => $MB_q])), 7, '"all N matching" ids come from the same filter, on the server');

t_group('members roster — a bulk run validates first, then reports every row');

$MB_run = static fn(string $a, array $ids, array $p = []) => (function () use ($a, $ids, $p, $MB_admin) {
    try { return mam_member_bulk_run($a, $ids, $p, $MB_admin); } catch (\Throwable $e) { return $e->getMessage(); }
})();
t_eq($MB_run('delete_everyone', $MB_ids), 'unknown_action', 'an action that is not declared is refused before anything is written');
t_eq($MB_run('suspend', $MB_ids, ['reason' => 'short']), 'reason_required', 'a bulk suspension needs its reason up front');
t_eq($MB_run('suspend', range(100000, 100000 + MAM_MEMBERS_BULK_MAX)), 'too_many', 'and a run above the cap is refused whole, naming the cap');
t_ok(mam_db_row("SELECT 1 FROM member_status_log WHERE user_id IN (" . implode(',', $MB_ids) . ")") === null, 'none of those refusals changed anybody');

$MB_mix = array_merge($MB_ids, [$MB_inst, 99999999]);
$MB_out = mam_member_bulk_run('suspend', $MB_mix, ['reason' => 'Term fees unpaid after the grace window'], $MB_admin);
t_eq([$MB_out['requested'], $MB_out['applied'], $MB_out['refused']], [9, 7, 2], 'nine asked for: seven suspended, the instructor and the missing id refused');
$MB_codes = array_column($MB_out['results'], 'code', 'id');
t_ok($MB_codes[$MB_inst] === 'staff_account' && $MB_codes[99999999] === 'not_found', 'each refusal carries its own reason');
t_eq(mam_db_row('SELECT status FROM users WHERE id = ?', [$MB_inst])['status'], 'active', 'the instructor was not touched');
$MB_again = mam_member_bulk_run('suspend', $MB_ids, ['reason' => 'Term fees unpaid after the grace window'], $MB_admin);
t_eq([$MB_again['applied'], $MB_again['unchanged']], [0, 7], 'running it again changes nobody, and says "already so" rather than "done"');
$MB_stored = mam_member_bulk_run_get((int) $MB_out['run_id']);
t_ok($MB_stored && (int) $MB_stored['refused'] === 2 && count($MB_stored['results']) === 9, 'the run is kept, with every row\'s verdict, for the screen it returns to');
t_eq(count(mam_member_status_history($MB_ids[3])), 1, 'and each member\'s own history has the change, with the reason');
mam_member_bulk_run('activate', $MB_ids, [], $MB_admin);

$MB_num = mam_member_bulk_run('number', [$MB_ids[0], $MB_ids[1]], [], $MB_admin);
t_eq($MB_num['applied'], 2, 'giving member numbers in bulk mints one each');
t_eq(mam_member_bulk_run('number', [$MB_ids[0]], [], $MB_admin)['unchanged'], 1, 'and never reissues');
t_eq(mam_member_bulk_run('scholarship', [$MB_ids[0], $MB_ids[2]], [], $MB_admin)['applied'], 1, 'a fee-type change counts only the one that changed');

t_group('members roster — duplicates are listed, never merged');

$MB_dup = mam_member_duplicates(100);
$MB_phone = array_values(array_filter($MB_dup, fn($g) => str_contains($g['why'], 'phone') && in_array($MB_ids[0], array_map('intval', array_column($g['members'], 'id')), true)));
t_ok(count($MB_phone) === 1 && count($MB_phone[0]['members']) === 2, 'two students on one phone are listed as possibly the same person — or siblings');
t_ok(!preg_match('/function mam_member_merge/', (string) file_get_contents(dirname(__DIR__) . '/lib/members.php')), 'and nothing merges them: a person decides from the records');

t_group('members roster — the screen and the handler');

$MB_part = (string) file_get_contents(dirname(__DIR__, 2) . '/partials/admin-console/students.php');
t_ok(str_contains($MB_part, "ac_form('member_bulk'") && str_contains($MB_part, 'name="all_matching"'), 'the roster has a bulk bar with an "all matching" button that needs no script');
t_ok(str_contains($MB_adm . (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php'), "mam_console_students_ids(\$filters"), 'and "all matching" is resolved from the filter on the server');
t_ok(str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php'), "'member_bulk' => 'users'"), 'bulk actions need the users capability');
// Render the real screen — a member panel open, a run on show — and walk the
// HTML: a <form> opened inside another is silently DROPPED by the parser and its
// button posts to the outer action. A source grep cannot see forms that helpers
// (ac_action_btn, ac_form) emit, so this reads what a browser would receive.
require_once dirname(__DIR__, 2) . '/partials/admin-console/_ui.php';
$GLOBALS['MAM_CONSOLE_CSRF'] = 'test-token';
$d = mam_console_students(['q' => $MB_q, 'id' => $MB_ids[3], 'run' => (int) $MB_out['run_id'], 'dupes' => 1]);
ob_start(); include dirname(__DIR__, 2) . '/partials/admin-console/students.php'; $MB_html = (string) ob_get_clean();
$MB_depth = 0; $MB_nested = 0;
preg_match_all('#<(/?)form\b#i', $MB_html, $MB_tags, PREG_SET_ORDER);
foreach ($MB_tags as $MB_t) { if ($MB_t[1] === '') { if ($MB_depth > 0) $MB_nested++; $MB_depth++; } else { $MB_depth--; } }
t_eq([$MB_nested, $MB_depth], [0, 0], 'the rendered roster opens no form inside another, and closes every form it opens');
t_ok(str_contains($MB_html, 'Term fees unpaid') && str_contains($MB_html, 'Status history'), 'the member panel shows the status history with its reason');
t_ok(str_contains($MB_html, 'That is a staff account'), 'and the last run names who was refused, in words');
t_ok(substr_count($MB_html, 'name="ids[]"') === 7, 'one tick box per member on the page');

t_group('onboarding — the checklist is read from the record, never ticked');

$OB_cust = mam_db_insert('customers', ['email' => 'obkid@test.local', 'first_name' => 'Ob', 'last_name' => 'Kid', 'phone' => '0801', 'dob' => date('Y-m-d', strtotime('-10 years'))]);
$OB_prov = mam_ensure_student_user($OB_cust);
$OB = (int) $OB_prov['user']['id'];
$OB_c = mam_onboarding_checklist($OB);
t_eq($OB_c['done'], 0, 'a brand-new ten-year-old has done nothing yet');
t_eq(array_column($OB_c['items'], 'key'), array_keys(MAM_ONBOARDING_ITEMS), 'and every item applies, guardian included');
t_ok(!in_array('fees', array_column($OB_c['items'], 'key'), true), 'money is not an onboarding item — nothing is withheld from a child over fees');
$OB_hint = array_values(array_filter($OB_c['items'], fn($i) => $i['key'] === 'account'))[0]['hint'];
t_eq($OB_hint, 'No invitation has been sent', 'the sign-in item says why it is not done');
mam_auth_send_invite($OB, 'student', 'portal_welcome');
mam_db_update('users', ['avatar_url' => 'https://example.org/p.jpg'], ['id' => $OB]);
mam_parent_link('obguardian@test.local', $OB);
mam_consent_set($OB, ['photo_consent' => 0, 'media_consent' => 0, 'data_consent' => 1]);
$OB_c = mam_onboarding_checklist($OB);
$OB_by = array_column($OB_c['items'], null, 'key');
t_ok($OB_by['photo']['done'] && $OB_by['guardian']['done'] && $OB_by['consent']['done'], 'a photo, a guardian and recorded consents tick themselves off');
t_ok($OB_by['consent']['done'], 'consents count as RECORDED even where the answer is no — the decision is the step, not a yes');
t_eq($OB_by['account']['hint'], 'Invited — has not set a password yet', 'an invitation that was not taken up is named as such');
$OB_adult = mam_db_insert('users', ['name' => 'Ob Adult', 'email' => 'obadult@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active',
    'customer_id' => mam_db_insert('customers', ['email' => 'obadult@test.local', 'first_name' => 'Ob', 'last_name' => 'Adult', 'dob' => date('Y-m-d', strtotime('-30 years'))])]);
t_ok(!in_array('guardian', array_column(mam_onboarding_checklist($OB_adult)['items'], 'key'), true), 'an adult has no guardian item');
$OB_noage = $MB_mk('obnoage@test.local');
t_eq(array_values(array_filter(mam_onboarding_checklist($OB_noage)['items'], fn($i) => $i['key'] === 'guardian'))[0]['hint'] ?? '',
    'Age unknown, so treated as a child', 'an unknown age counts as a child, as the safeguarding gate does');
t_ok(in_array($OB, array_map('intval', array_column(mam_onboarding_incomplete(500), 'id')), true), 'and the student is on the "still joining" list');

t_group('onboarding — a reminder tells the family only what they can do, and not twice in a row');

$OB_mail = static fn(string $ctx) => (int) mam_db_row('SELECT COUNT(*) n FROM mail_log WHERE context = ?', [$ctx])['n'];
$OB_before = $OB_mail('onboarding_reminder');
// The harness has no mail transport, so every send FAILS here — which is the
// case worth pinning first: a failure is reported as such and blocks nothing.
$OB_r = mam_onboarding_remind($OB, $MB_admin);
t_eq($OB_mail('onboarding_reminder') - $OB_before, 2, 'a student who has not signed in is reminded, and their guardian too — one each');
t_eq($OB_r['code'], 'not_sent', 'a send that failed is reported as not sent — not as "no address", and never as done');
t_eq(mam_onboarding_remind($OB, $MB_admin)['code'], 'not_sent', 'and a failed send does not start the quiet period, so it can be tried again');
mam_db()->exec("UPDATE mail_log SET status = 'sent' WHERE context = 'onboarding_reminder'");   // as if delivered
t_eq(mam_onboarding_remind($OB, $MB_admin)['code'], 'reminded_recently', 'once one was delivered, pressing it again the same day sends nothing');
mam_db_update('users', ['last_login' => date('Y-m-d H:i:s')], ['id' => $OB]);
$OB_quiet = $MB_mk('obquiet@test.local', 'student', ['last_login' => date('Y-m-d H:i:s'), 'avatar_url' => 'https://example.org/q.jpg']);
mam_consent_set($OB_quiet, ['data_consent' => 1]);
t_eq(mam_onboarding_remind($OB_quiet, $MB_admin)['code'], 'nothing_for_family',
    'a student missing only what the OFFICE owes (a number, a card, a class) is not asked to do anything');

t_group('onboarding — inviting in bulk');

$OB_inv = $MB_mk('obinvite@test.local');
$OB_out = mam_member_bulk_run('invite', [$OB_inv, $OB_quiet], [], $MB_admin);
// No mail transport in the harness: the link is minted and the email fails, and
// the verdict has to say so rather than "done".
t_eq(array_column($OB_out['results'], 'code', 'id'), [$OB_inv => 'not_sent', $OB_quiet => 'already_signed_in'],
    'somebody who never signed in is sent a new invitation (reported as NOT sent when the email fails); somebody who signs in is left alone');
t_ok(mam_db_row('SELECT 1 FROM password_resets WHERE user_id = ?', [$OB_inv]) !== null, 'the link itself is a real single-use token');

t_group('onboarding — every way an account is made sends a link, never a password');

$OB_src = static fn(string $rel) => (string) file_get_contents(dirname(__DIR__) . '/' . $rel);
t_ok(str_contains($OB_src('lib/enrol-complete.php'), "mam_auth_send_invite(\$studentId, 'student', 'portal_welcome')"),
    '"Complete enrolment" invites — its step said "sign-in details emailed" while nothing was sent');
t_ok(str_contains($OB_src('lib/auditer.php'), 'mam_auth_send_invite($studentId'), 'an Auditer placement that makes an account invites');
t_ok(str_contains($OB_src('routes/admin/parent-link.php'), "'guardian_invite'") && str_contains($MB_adm, "'guardian_invite'"),
    'linking a new guardian invites them — the account used to be made with a password nobody was given');
$OB_admin = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
t_ok(!str_contains($OB_admin, 'Temporary password:') && !str_contains($OB_admin, 'password_hash($pass'),
    'no admin path emails or sets a password an administrator chose');
t_ok(!str_contains($OB_src('routes/admin/reset-password.php'), 'password_hash(') && !str_contains($OB_src('routes/admin/create-user.php'), 'password_hash($password'),
    'nor do the JSON twins');
t_ok(!function_exists('mam_email_account_created'), 'and the template that carried a password in plain text is gone');
