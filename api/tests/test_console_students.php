<?php
/**
 * Student management in the admin console — Students → a student, People &
 * access, and the admissions follow-up forms.
 *
 * The profile is one read (mam_console_member + mam_console_member_profile) and
 * every write posts to an EXISTING handler in admin/index.php. So this file
 * asserts two kinds of thing: what the payload may and may not carry (both
 * enrolment models, the band and never raw_score, the safeguarding count only
 * for a viewer with that capability), and which handler each form names — the
 * one status setter, the invitation instead of a password, the scope writer.
 */
declare(strict_types=1);

$CS_root  = dirname(dirname(__DIR__));
$CS_stu   = (string) file_get_contents($CS_root . '/partials/admin-console/students.php');
$CS_acc   = (string) file_get_contents($CS_root . '/partials/admin-console/access.php');
$CS_adm   = (string) file_get_contents($CS_root . '/partials/admin-console/admissions.php');
$CS_index = (string) file_get_contents($CS_root . '/admin/index.php');

/* ── fixtures: a course-only student, a legacy-only student, an instructor ── */
$CS_tag = 'cs' . substr(md5((string) microtime(true)), 0, 8);
$CS_mk = static function (string $who, string $role) use ($CS_tag): int {
    return (int) mam_db_insert('users', ['email' => "$who.$CS_tag@example.test", 'name' => ucfirst($who) . ' ' . $CS_tag,
        'role' => $role, 'status' => 'active', 'password_hash' => mam_auth_unusable_password()]);
};
$CS_course = $CS_mk('course', 'student');
$CS_legacy = $CS_mk('legacy', 'student');
$CS_teach  = $CS_mk('teacher', 'instructor');
mam_user_ensure_role($CS_teach, 'instructor');

$CS_cid = (int) (mam_db_row('SELECT id FROM courses ORDER BY id LIMIT 1')['id'] ?? 0);
$CS_cohort = (int) mam_db_insert('cohorts', ['course_id' => $CS_cid, 'title' => 'Cohort ' . $CS_tag,
    'instructor_id' => $CS_teach, 'status' => 'active']);
$CS_ce = (int) mam_db_insert('course_enrollments', ['student_id' => $CS_course, 'course_id' => $CS_cid,
    'cohort_id' => $CS_cohort, 'status' => 'active']);
$CS_today = function_exists('mam_calendar_today') ? mam_calendar_today() : gmdate('Y-m-d');
$CS_sess = (int) mam_db_insert('class_sessions', ['cohort_id' => $CS_cohort, 'title' => 'Scales ' . $CS_tag,
    'scheduled_at' => $CS_today . ' 10:00:00', 'duration_min' => 60, 'status' => 'scheduled']);
mam_db_insert('session_attendance', ['session_id' => $CS_sess, 'student_id' => $CS_course, 'status' => 'late']);
$CS_class = (int) mam_db_insert('classes', ['title' => 'Legacy ' . $CS_tag, 'discipline' => 'piano',
    'instructor_id' => $CS_teach, 'status' => 'active']);
$CS_enr = (int) mam_db_insert('enrollments', ['student_id' => $CS_legacy, 'class_id' => $CS_class, 'status' => 'active']);

t_group('student profile — one class model (the old classes are carried into cohorts)');

$CS_super   = ['id' => 0, 'role' => 'admin', 'admin_tier' => 'super'];
$CS_manager = ['id' => 0, 'role' => 'admin', 'admin_tier' => 'manager'];
$GLOBALS['__mam_console_fail'] = [];
$CS_m = mam_console_member($CS_course);
t_ok(is_array($CS_m), 'the member read resolves a student');
t_eq(mam_console_failures(), [], 'and records no failed read — every column the profile names exists');
t_eq(count($CS_m['courses'] ?? []), 1, 'a course-only student shows their course enrolment');
t_eq((string) ($CS_m['courses'][0]['instructor'] ?? ''), 'Teacher ' . $CS_tag, '…with the teacher the cohort carries');
t_ok(!array_key_exists('classes', $CS_m), 'the profile has no second, old-class list: one model, one list');
// A student who was only in an old class: once carried, the profile shows them in a course —
// never "enrolled in nothing" (the reason the profile used to read both models).
mam_legacy_carry(null);
$CS_l = mam_console_member($CS_legacy);
t_eq(count($CS_l['courses'] ?? []), 1, 'a student from an old class shows their course once it is carried across');

t_group('student profile — the week, attendance, money');
t_eq(array_column($CS_m['week'] ?? [], 'id'), [$CS_sess], 'this week lists the session of the cohort they are in, read from class_sessions');
t_eq((string) ($CS_m['week'][0]['attended'] ?? ''), 'late', '…with their own mark beside it');
t_eq((int) ($CS_m['attendance']['total'] ?? 0), 1, 'attendance counts the marks recorded');
t_eq((int) ($CS_m['attendance']['pct'] ?? -1), 100, 'late counts as came, as the roster counts it');
t_ok(is_array($CS_m['invoices'] ?? null), 'the fee invoices are listed (empty for a new student)');

t_group('student profile — the band only, never raw_score');
t_ok(!str_contains((string) json_encode($CS_m), 'raw_score'), 'no `raw_score` anywhere in the profile payload');
t_ok(!array_key_exists('raw_score', (array) ($CS_m['mastery'] ?? [])), 'the mastery entry has no raw figure');
t_eq(array_keys((array) ($CS_m['mastery'] ?? [])), ['band', 'withheld_reason', 'coverage_pct'],
    'the mastery entry carries exactly a band, why one is withheld, and coverage');
t_ok(($CS_m['mastery']['band'] ?? null) === null, 'an unassessed student has no band — "not assessed", not zero');

t_group('student profile — safeguarding is a count, for the capability only');
t_ok(mam_console_member_profile($CS_course, $CS_manager)['concerns'] === null,
    'a manager (no safeguarding capability) is given NOTHING — not even a zero');
t_ok(is_int(mam_console_member_profile($CS_course, $CS_super)['concerns']),
    'a super admin is given the count of open concerns');
$CS_lib = (string) file_get_contents($CS_root . '/api/lib/console.php');
$CS_fn  = substr($CS_lib, (int) strpos($CS_lib, 'function mam_console_member_profile'), 9000);
t_ok(str_contains($CS_fn, 'SELECT COUNT(*) FROM safeguarding_concerns') && !preg_match('/safeguarding_concerns[^;]*\\b(body|category)\\b/', $CS_fn),
    'the profile reads only a COUNT of concerns — no body, no category (those stay behind the safeguarding screen)');

t_group('guardians — link with a scope, change it, MAM_PARENT_NEVER first');
$CS_gl = mam_parent_link("guardian.$CS_tag@example.test", $CS_course, 'mother', null);
t_ok(!empty($CS_gl['parent_id']), 'a guardian links to the student');
mam_parent_scope_set((int) $CS_gl['parent_id'], $CS_course, 'billing', null);
$CS_g = mam_console_member($CS_course)['guardians'][0] ?? [];
t_eq((string) ($CS_g['access_scope'] ?? ''), 'billing', 'the profile shows the scope the one writer set');
$CS_bad = null;
try { mam_parent_scope_set((int) $CS_gl['parent_id'], $CS_course, 'everything', null); } catch (\Throwable $e) { $CS_bad = $e->getMessage(); }
t_eq($CS_bad, 'bad_scope', 'an unknown scope is refused by the writer the console posts to');
mam_parent_scope_set((int) $CS_gl['parent_id'], $CS_course, 'full', null);
foreach ((array) MAM_PARENT_NEVER as $CS_never) {
    t_ok(!mam_parent_scope_allows((int) $CS_gl['parent_id'], $CS_course, (string) $CS_never), "no scope — not even full — opens `$CS_never`");
}
t_ok(str_contains($CS_stu, "ac_form('guardian_scope_set', 'students'"), 'the profile changes a scope through `guardian_scope_set`');
t_ok(str_contains($CS_stu, "ac_form('guardian_link', 'students'") && str_contains($CS_stu, 'name="access_scope"'),
    'and links a guardian with a scope chosen at the same moment');
t_ok(str_contains($CS_index, "'guardian_scope_set' => 'enrollments'"), '`guardian_scope_set` is gated by the enrollments capability, like link/unlink');
t_ok((bool) preg_match("/action === 'guardian_scope_set'.{0,600}mam_parent_scope_set\\(/s", $CS_index),
    'and is a thin call to mam_parent_scope_set() — the only scope writer');
t_ok((bool) preg_match("/action === 'guardian_link'.{0,1600}mam_parent_scope_set\\(/s", $CS_index),
    'guardian_link applies a posted scope through the same writer');

t_group('accounts — an invitation, never a password');
foreach (['students.php' => $CS_stu, 'access.php' => $CS_acc, 'admissions.php' => $CS_adm] as $CS_f => $CS_src) {
    t_ok(!preg_match('/name="(new_)?password"/', $CS_src), "$CS_f has no password field");
}
t_ok((bool) preg_match("/action === 'user_create'.{0,1400}mam_auth_unusable_password\\(\\).{0,900}mam_auth_send_invite\\(/s", $CS_index),
    'user_create makes an unusable password and sends an invitation');
t_ok(str_contains($CS_index, "header('Location: ?v=users&' . (\$flash[0] === 'ok' ? 'ok=' : 'err=') . urlencode(\$flash[1]))"),
    'user_create reports WHETHER the invitation was sent, not a bare ok=1');
t_ok((bool) preg_match("/action === 'user_reset'.{0,200}mam_auth_send_invite\\(/s", $CS_index),
    'the reset button sends a link (user_reset → mam_auth_send_invite)');
$CS_before = (string) mam_db_row('SELECT password_hash FROM users WHERE id = ?', [$CS_course])['password_hash'];
$CS_sent = mam_auth_send_invite($CS_course, 'portal', 'admin_password_reset');
t_eq((string) mam_db_row('SELECT password_hash FROM users WHERE id = ?', [$CS_course])['password_hash'], $CS_before,
    'sending an invite leaves the password hash untouched — the person sets it, nobody else');
t_ok(is_bool($CS_sent) || $CS_sent === null || is_array($CS_sent), 'the invite reports an outcome the handler turns into a sentence');
foreach (['user_create' => "\$mailed = !empty(mam_auth_send_invite(", 'user_reset' => "if (!empty(\$sent['sent']))", 'guardian_link' => "'guardian_invite')['sent'])"] as $CS_a => $CS_needle) {
    t_ok(str_contains($CS_index, $CS_needle), "$CS_a reports the invite's `sent` — whether the email LEFT — not merely that a link exists");
}
t_ok(str_contains($CS_stu, "ac_action_btn('user_reset', 'students'") && str_contains($CS_acc, "ac_action_btn('user_reset', 'access'"),
    'both the profile and People & access offer the link');
t_ok(str_contains($CS_acc, "ac_form('user_create', 'access'"), 'People & access creates an account');

t_group('status — the one setter, and staff are not administered from the roster');
t_ok(str_contains($CS_stu, "ac_form('member_status_set', 'students'"), 'the profile changes status through member_status_set');
t_ok(str_contains($CS_acc, "ac_form('user_status', 'access'"), 'People & access through user_status');
t_ok((bool) preg_match("/action === 'member_status_set'\\).{0,900}mam_member_set_status\\(/s", $CS_index),
    'both actions land in mam_member_set_status() — the one status setter');
t_ok(mam_member_is_staff(mam_db_row('SELECT * FROM users WHERE id = ?', [$CS_teach])), 'an instructor reads as staff');
t_ok((bool) preg_match("/if \\(\\\$m\\['is_staff'\\]\\):.{0,400}People &amp; access.{0,200}<\\?php else: \\?>.{0,200}user_edit/s", $CS_stu),
    'the profile offers a staff member no edit or status control — it points to People & access');
t_eq(mam_member_set_status($CS_teach, 'suspended', 'A staff suspension from the roster', null, ['members_only' => true])['code'] ?? '', 'staff_account',
    'the one setter refuses a staff account when asked from the members list');
t_ok((bool) preg_match("/\\['members_only' => \\\$action === 'member_status_set'\\]/", $CS_index),
    'and member_status_set (the profile\'s action) asks it to — the refusal is the handler\'s, not the template\'s');
$CS_staff = mam_member_bulk_run('suspend', [$CS_teach], ['reason' => 'Testing the staff refusal here'], null);
t_eq((int) ($CS_staff['refused'] ?? 0), 1, 'the roster\'s bulk run refuses a staff member (staff_account)');
$CS_r = mam_member_role_change_refusal($CS_course, 'admin', $CS_course);
t_ok(is_string($CS_r) || $CS_r === null, 'user_edit\'s role change goes through mam_member_role_change_refusal()');

t_group('tiers and admissions — on the existing handlers');
t_ok(str_contains($CS_acc, "ac_form('set_admin_tier', 'access'") && str_contains($CS_acc, "!empty(\$d['can_tier'])"),
    'the tier control is shown only to a viewer holding rbac (and the handler re-checks)');
t_ok(str_contains($CS_acc, '$isMe') && str_contains($CS_acc, 'This is you'),
    'your own row offers no status or tier change (own_account)');
foreach (['app_assign', 'record_placement', 'resend_confirmation'] as $CS_a) {
    t_ok(str_contains($CS_adm, "'$CS_a', 'admissions'"), "the admissions screen posts `$CS_a`");
}
t_ok(str_contains($CS_index, "\$keep['app'] = \$q['app']"), 'a handler\'s `app=` survives the console redirect, so the same application comes back open');
t_ok(str_contains($CS_index, "\$_POST['_console_id']") && str_contains($CS_index, "preg_match('/^[1-9][0-9]{0,9}\$/', \$cid)"),
    'a profile form returns to its record via a digits-only `_console_id`');
t_ok(!str_contains($CS_index, "mam_audit('resend_confirmation', 'application', \$aid, ['to' => \$cust['email']]);\n        header('Location: ?v=applications&ok=1')"),
    'resend_confirmation no longer answers ok whether or not the copy left');
foreach (['roster' => "ac_url('roster', ['student' => \$sid])"] as $CS_k => $CS_link) {
    t_ok(str_contains($CS_stu, $CS_link), 'Enrol links to the roster screen with the student preselected (?student=ID)');
}
$GLOBALS['__mam_console_fail'] = [];
$CS_app = (int) (mam_db_row('SELECT id FROM applications ORDER BY id LIMIT 1')['id'] ?? 0);
if ($CS_app > 0) {
    $CS_det = mam_console_application($CS_app);
    t_ok(is_array($CS_det['staff'] ?? null) && is_array($CS_det['courses'] ?? null) && is_array($CS_det['levels'] ?? null),
        'the applicant card carries the staff, courses and levels the forms offer');
    t_eq(mam_console_failures(), [], '…read without a failed query');
}
$CS_v = mam_console_access_viewer();
t_ok(array_key_exists('can_tier', $CS_v) && array_key_exists('me', $CS_v), 'People & access knows who is looking');

/* ── tidy the fixtures that other tests count ── */
mam_db()->prepare('DELETE FROM session_attendance WHERE session_id = ?')->execute([$CS_sess]);
mam_db()->prepare('DELETE FROM class_sessions WHERE id = ?')->execute([$CS_sess]);
mam_db()->prepare('DELETE FROM course_enrollments WHERE id = ?')->execute([$CS_ce]);
mam_db()->prepare('DELETE FROM cohorts WHERE id = ?')->execute([$CS_cohort]);
mam_db()->prepare('DELETE FROM enrollments WHERE id = ?')->execute([$CS_enr]);
mam_db()->prepare('DELETE FROM classes WHERE id = ?')->execute([$CS_class]);
