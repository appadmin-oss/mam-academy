<?php
/** Auth & provisioning — admin create, multi-role checks, student auto-provision. */
declare(strict_types=1);
t_group('auth & provisioning');

// ── mam_create_admin: create, verify hash, idempotent update ──
$a = mam_create_admin('t_admin@x.dev', 'correct-horse-1', 'First Admin');
t_ok($a['created'] === true, 'admin account created');
$row = mam_db_row('SELECT * FROM users WHERE email = ?', ['t_admin@x.dev']);
t_eq($row['role'], 'admin', 'created with the admin role');
t_ok(password_verify('correct-horse-1', $row['password_hash']), 'password stored as a verifiable bcrypt hash');

$a2 = mam_create_admin('t_admin@x.dev', 'rotated-pass-22', 'Renamed Admin');
t_ok($a2['created'] === false && $a2['id'] === $a['id'], 're-creating the same email updates instead of duplicating');
$row = mam_db_row('SELECT * FROM users WHERE id = ?', [(int)$a['id']]);
t_ok(password_verify('rotated-pass-22', $row['password_hash']), 'idempotent create rotates the password');

// Rejections
$bad = false; try { mam_create_admin('not-an-email', 'longenough1'); } catch (InvalidArgumentException $e) { $bad = $e->getMessage() === 'invalid_email'; }
t_ok($bad, 'invalid email rejected');
$bad = false; try { mam_create_admin('t2@x.dev', 'short'); } catch (InvalidArgumentException $e) { $bad = $e->getMessage() === 'weak_password'; }
t_ok($bad, 'password under 8 chars rejected');

// ── Multi-role: a secondary admin via user_roles MUST count as admin ──
// (Regression: mam_require_admin used to check only the primary users.role.)
$iid = mam_db_insert('users', ['email' => 't_multi@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Multi Role', 'status' => 'active']);
mam_db_insert('user_roles', ['user_id' => $iid, 'role' => 'admin']);
$mu = mam_db_row('SELECT * FROM users WHERE id = ?', [$iid]);
t_ok(in_array('instructor', mam_user_roles($mu), true), 'primary role listed');
t_ok(in_array('admin', mam_user_roles($mu), true), 'secondary role from user_roles listed');
t_ok(mam_user_has_role($mu, 'admin'), 'user_roles admin passes the admin check (require_admin regression)');
t_ok(!mam_user_has_role($mu, 'student'), 'unrelated role still refused');

// ── Student auto-provision from a customer (tuition-paid path) ──
$cid = mam_db_insert('customers', ['email' => 't_cust@x.dev', 'first_name' => 'Ada', 'last_name' => 'Obi', 'phone' => '+2348000000']);
$p1 = mam_ensure_student_user((int)$cid);
t_ok(!empty($p1['password']) && strlen($p1['password']) === 10, 'first provision returns a 10-char temp password');
$su = mam_db_row('SELECT * FROM users WHERE email = ?', ['t_cust@x.dev']);
t_eq($su['role'], 'student', 'provisioned as a student');
t_eq((int)$su['must_change_password'], 1, 'temp password is flagged for forced change');
t_ok(password_verify($p1['password'], $su['password_hash']), 'temp password verifies against the stored hash');
$p2 = mam_ensure_student_user((int)$cid);
t_ok($p2['password'] === null, 're-provisioning is idempotent (no new password)');
t_eq((int)$p2['user']['id'], (int)$su['id'], 're-provision returns the same account');

// ── The application headshot becomes the portal avatar automatically ──
$cid2 = mam_db_insert('customers', ['email' => 't_shot@x.dev', 'first_name' => 'Bem', 'last_name' => 'T', 'phone' => '+2348111111']);
mam_db_insert('applications', ['reference' => 'MAM-SHOT-1', 'customer_id' => $cid2, 'pathway' => 'standard', 'headshot_url' => 'https://res.cloudinary.com/demo/image/upload/bem.jpg', 'status' => 'submitted']);
$ps = mam_ensure_student_user((int)$cid2);
t_eq($ps['user']['avatar_url'], 'https://res.cloudinary.com/demo/image/upload/bem.jpg', 'the application headshot becomes the student avatar at provisioning');

// Backfill: an existing avatar-less account gains the headshot on re-provision.
$cid3 = mam_db_insert('customers', ['email' => 't_backfill@x.dev', 'first_name' => 'Chidi', 'last_name' => 'K']);
mam_db_insert('users', ['email' => 't_backfill@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Chidi K', 'status' => 'active']);
mam_db_insert('applications', ['reference' => 'MAM-SHOT-2', 'customer_id' => $cid3, 'pathway' => 'crash', 'headshot_url' => 'https://res.cloudinary.com/demo/image/upload/chidi.jpg', 'status' => 'submitted']);
$pb = mam_ensure_student_user((int)$cid3);
t_eq($pb['user']['avatar_url'], 'https://res.cloudinary.com/demo/image/upload/chidi.jpg', 'an avatar-less account is backfilled with the headshot');

// No headshot → no avatar (initials fallback in the UI), never an error.
$cid4 = mam_db_insert('customers', ['email' => 't_noshot@x.dev', 'first_name' => 'Deb', 'last_name' => 'O']);
$pn = mam_ensure_student_user((int)$cid4);
t_ok(empty($pn['user']['avatar_url']), 'no headshot → no avatar set (portal falls back to initials)');

// ── Dual-role portal chooser: a user who is both student + instructor gets a
// choice; single-role users go straight to their portal. ──
t_eq(mam_post_login_redirect(['student']), '/portal/student/', 'lone student → student portal');
t_eq(mam_post_login_redirect(['instructor']), '/portal/instructor/', 'lone instructor → instructor portal');
t_eq(mam_post_login_redirect(['admin']), '/admin/', 'lone admin → admin dashboard');
t_eq(mam_post_login_redirect(['student', 'instructor']), '/portal/choose', 'student + instructor → chooser');
t_eq(count(mam_portal_targets(['student', 'instructor'])), 2, 'dual-role user has two portal targets');
t_eq(mam_post_login_redirect([]), '/portal/student/', 'no roles → safe student default');

// Secondary-role grant/revoke round-trips through user_roles.
$dr = mam_db_insert('users', ['email' => 't_dual@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Dual Role', 'status' => 'active']);
mam_user_ensure_role($dr, 'instructor');
$duser = mam_db_row('SELECT * FROM users WHERE id = ?', [$dr]);
$roles = mam_user_roles($duser);
t_ok(in_array('student', $roles, true) && in_array('instructor', $roles, true), 'granted user holds both student + instructor');
t_eq(mam_post_login_redirect($roles), '/portal/choose', 'the dual-role user is routed to the chooser');
mam_user_remove_role($dr, 'instructor');
t_ok(!in_array('instructor', mam_user_roles(mam_db_row('SELECT * FROM users WHERE id = ?', [$dr])), true), 'revoking the secondary role removes it');

// ── Admin access decision table (mam_admin_access_outcome) ───────────────────
// Regression: a signed-in NON-admin used to receive 503 'admin_not_configured'
// whenever the Basic-Auth break-glass was unset (the normal deployment). That
// told a logged-in student the server was broken, and told an API client to
// retry later on what is a permanent refusal.
t_group('auth — admin access decision');

t_eq(mam_admin_access_outcome(true,  true,  false), 'allow',
     'a signed-in admin is allowed');
t_eq(mam_admin_access_outcome(false, true,  false), 'forbidden',
     'a signed-in NON-admin is forbidden, not asked to authenticate');
t_eq(mam_admin_access_outcome(false, false, false), 'authenticate',
     'an anonymous caller is asked to authenticate');
// The break-glass must survive a stale non-admin session, so valid Basic-Auth
// credentials are honoured before the signed-in check.
t_eq(mam_admin_access_outcome(false, true,  true),  'allow',
     'valid break-glass credentials win over a non-admin session');
t_eq(mam_admin_access_outcome(false, false, true),  'allow',
     'valid break-glass credentials work with no session at all');
t_eq(mam_admin_access_outcome(true,  false, false), 'allow',
     'an admin identity is enough on its own');

// The outcome never depends on whether the break-glass is *configured* —
// only on whether valid credentials were supplied. Configuration state is an
// operator concern and must not be disclosed to an anonymous caller.
foreach ([[false, false], [false, true], [true, false], [true, true]] as [$isAdmin, $signedIn]) {
    $out = mam_admin_access_outcome($isAdmin, $signedIn, false);
    t_ok(in_array($out, ['allow', 'forbidden', 'authenticate'], true),
         'outcome is one of the three states for isAdmin=' . var_export($isAdmin, true) . ' signedIn=' . var_export($signedIn, true));
    t_ok($out !== 'unavailable', 'no outcome reports the server as misconfigured');
}

// ── The break-glass actor split ─────────────────────────────────────────────
// Admin routes are authorised by mw_admin_cap(), so their bodies do not
// re-check the role — that only added a mam_require_user() which 401s a
// Basic-Auth break-glass operator. Reads are now reachable that way; writes
// still need a named actor, because each records who performed it.
t_group('auth — break-glass reads, session-only writes');

// mam_admin_actor() is null with no session, which is exactly the break-glass
// case. mam_session_user() memoises per process, so this is the reachable
// assertion; the decision it feeds is covered by the table above.
t_ok(mam_admin_actor() === null, 'no session → no named actor (the break-glass case)');

$threw = null;
try { mam_admin_require_actor(); } catch (\RuntimeException $e) { $threw = $e->getMessage(); }
t_eq($threw, 'mam_fail:admin_session_required:403',
     'a write without a named actor is refused 403, not 401');
// 401 would invite the operator to retry credentials that already worked, and
// 503 would claim the server was broken. Neither is true.
t_ok(strpos((string) $threw, ':401') === false, 'and specifically not 401');
t_ok(strpos((string) $threw, ':503') === false, 'and specifically not 503');

// Every actor-taking callee still demands a real int, which is why writes
// cannot simply pass null: these columns are attribution, and one of them is
// shown to the student.
foreach ([
    ['mam_accountability_resolve', 1],
    ['mam_fine_resolve',           1],
    ['mam_progress_token_create',  1],
    ['mam_survey_create',          0],
    ['mam_ticket_reply',           1],
] as [$fn, $argIndex]) {
    $p = (new ReflectionFunction($fn))->getParameters()[$argIndex];
    t_ok(!$p->allowsNull(), "$fn() still requires a non-null actor id");
}
