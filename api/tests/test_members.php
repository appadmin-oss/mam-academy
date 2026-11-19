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
