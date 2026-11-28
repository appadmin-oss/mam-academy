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
// No password is generated any more (HANDOFF §4w): three callers made one and
// gave it to nobody, one emailed it in plain text. The account is reached by an
// invitation the caller sends; `created` says one is owed.
t_ok($p1['password'] === null && $p1['created'] === true, 'first provision creates the account and returns NO password');
$su = mam_db_row('SELECT * FROM users WHERE email = ?', ['t_cust@x.dev']);
t_eq($su['role'], 'student', 'provisioned as a student');
t_eq((int)$su['must_change_password'], 0, 'nothing to force-change: the invitation sets the first password');
t_ok(str_starts_with((string)$su['password_hash'], '$2y$') && !password_verify('', $su['password_hash']), 'the stored hash is a real bcrypt of something nobody knows');
$p2 = mam_ensure_student_user((int)$cid);
t_ok($p2['password'] === null && $p2['created'] === false, 're-provisioning is idempotent and owes no invitation');
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

// ── The break-glass recovery identity ───────────────────────────────────────
// Admin routes are authorised by mw_admin_cap(), so their bodies do not
// re-check the role — that only added a mam_require_user() which 401s a
// Basic-Auth break-glass operator. Writes still need an actor to attribute the
// action to, so the break-glass resolves to a dedicated recovery users row
// rather than to nobody.
t_group('auth — the break-glass recovery identity');

// Default state: no session and no break-glass → no actor at all.
t_ok(mam_break_glass_active() === false, 'a request is not break-glass by default');
t_ok(mam_admin_actor() === null, 'and therefore has no admin actor');
$threw = null;
try { mam_admin_require_actor(); } catch (\RuntimeException $e) { $threw = $e->getMessage(); }
t_eq($threw, 'mam_fail:admin_session_required:403',
     'a write with no identity at all fails closed, rather than writing an unattributed row');

// Once the guard has recorded a break-glass authorisation, the actor resolves.
mam_break_glass_active(true);
$rec = mam_admin_actor();
t_ok(is_array($rec), 'a break-glass request DOES have an actor');
t_eq($rec['email'], MAM_RECOVERY_EMAIL, 'it is the dedicated recovery identity');
t_eq($rec['role'], 'admin', 'which holds the admin role');
t_ok((int) $rec['id'] > 0, 'and is a real users row, so it can satisfy a foreign key');
t_eq(mam_admin_require_actor()['id'], $rec['id'], 'so a write now resolves an actor instead of failing');

// The identity must NOT be a usable account. Every login path requires
// status='active'; this one is deliberately not.
t_eq($rec['status'], 'system', 'the recovery row is not an active account');
t_ok($rec['status'] !== 'active', 'so mam_login(), mam_session_user() and password reset all skip it');
t_ok(mam_db_row("SELECT id FROM users WHERE email = ? AND status = 'active'", [MAM_RECOVERY_EMAIL]) === null,
     'the exact query those three paths run returns nothing');
// Even if a future code path forgot the status gate, no known password works:
// the hash is of 32 random bytes that were never stored.
t_ok(!password_verify('', (string) $rec['password_hash']), 'an empty password does not verify');
t_ok(!password_verify('password', (string) $rec['password_hash']), 'nor does a guessable one');
t_ok(strlen((string) $rec['password_hash']) >= 55, 'the stored value is a real bcrypt hash, not a placeholder');

// Address is under RFC 2606 `.invalid`, which can never resolve, so nothing
// can accidentally deliver mail to the recovery identity.
t_ok(substr(MAM_RECOVERY_EMAIL, -8) === '.invalid', 'the address is permanently undeliverable');

// Get-or-create is idempotent — a second call must not make a second admin.
$again = mam_admin_recovery_user();
t_eq($again['id'], $rec['id'], 'resolving it twice returns the same row');
t_eq((int) (mam_db_row('SELECT COUNT(*) c FROM users WHERE email = ?', [MAM_RECOVERY_EMAIL])['c'] ?? 0), 1,
     'and there is exactly one recovery identity');

// A real session admin always outranks the recovery identity.
$sessionAdmin = mam_db_row('SELECT * FROM users WHERE email = ?', ['t_admin@x.dev']);
t_ok(mam_user_has_role($sessionAdmin, 'admin'), 'the seeded session admin holds the role');
t_ok((int) $sessionAdmin['id'] !== (int) $rec['id'], 'and is a different identity from the recovery row');

mam_break_glass_active(false); // leave the request state as we found it
t_ok(mam_admin_actor() === null, 'clearing the flag removes the actor again');

// Actor-taking callees still demand a real int — which is exactly why the
// recovery row has to exist rather than passing null.
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

t_group('account invitations — a link, never a password in an email');

// The academy's position: on enrolment a family receives a link to set their own
// password. A temporary password sits in an inbox for years, is usually reused,
// is readable by anyone holding the phone, cannot be revoked once sent — and for
// a children's academy that inbox is usually a shared parental one. It also
// teaches the exact habit phishing relies on.
t_ok(function_exists('mam_auth_invite') && function_exists('mam_auth_send_invite')
     && function_exists('mam_auth_unusable_password'), 'the invitation helpers exist');

$ivU = (int) mam_db_insert('users', ['email' => 'iv_' . bin2hex(random_bytes(4)) . '@x.dev',
    'name' => 'Invited Person', 'role' => 'student', 'status' => 'active',
    'password_hash' => mam_auth_unusable_password(), 'created_at' => date('Y-m-d H:i:s')]);
$ivRow = mam_db_row('SELECT email, password_hash FROM users WHERE id = ?', [$ivU]);

// An unusable password is a REAL bcrypt hash of bytes nobody has, not a sentinel
// string: password_verify() against a non-hash returns false today, but a column
// that sometimes holds a hash and sometimes holds 'roster:...' invites a caller
// to start pattern-matching on it.
t_ok(strpos((string) $ivRow['password_hash'], '$2y$') === 0 && strlen((string) $ivRow['password_hash']) >= 55,
    'an unusable password is real bcrypt, so nothing downstream has to special-case it');
t_eq(mam_login((string) $ivRow['email'], ''), null, 'and nothing signs in with an empty password');

$iv = mam_auth_invite($ivU);
t_ok($iv !== null, 'an invitation is minted');
t_ok(preg_match('/^[a-f0-9]{40}$/', (string) $iv['token']) === 1,
    'the token is 40 hex chars — the shape portal/reset.html validates before showing the form');
t_ok(mam_db_row('SELECT 1 FROM password_resets WHERE token_hash = ?', [hash('sha256', (string) $iv['token'])]) !== null,
    'stored as a SHA-256 hash, so a database read does not hand out working links');
t_ok(mam_db_row('SELECT 1 FROM password_resets WHERE token_hash = ?', [(string) $iv['token']]) === null,
    'and never in the clear');
t_ok(strtotime((string) $iv['expires_at']) - time() > 6 * 86400,
    'valid for days: a reset is answered in minutes, an invitation when a parent next looks at their phone');
t_eq(MAM_INVITE_TTL_SEC, 604800, 'seven days, named as a constant rather than typed at each call site');

// Only an active account. Inviting a suspended one would hand out a working
// credential for an account somebody deliberately shut.
foreach (['suspended', 'closed', 'roster'] as $st) {
    mam_db_update('users', ['status' => $st], ['id' => $ivU]);
    t_eq(mam_auth_invite($ivU), null, "a $st account cannot be invited");
}
mam_db_update('users', ['status' => 'active'], ['id' => $ivU]);
t_ok(mam_auth_invite($ivU) !== null, 'and can again once active');

// The mail itself must not lie about why it arrived.
$ivMail = mam_email_account_invite('Ada', 'https://example.test/portal/reset?token=x&welcome=1', 'student', 7);
t_ok(strpos($ivMail, 'Create your password') !== false, 'the email offers to create a password');
t_ok(stripos($ivMail, 'temporary password') === false, 'and contains no temporary password');
t_ok(strpos($ivMail, 'we never send passwords by email') !== false, 'saying so explicitly, which is how the habit changes');
// Two things the reset mail says that are wrong here: they did not request it,
// and an unexpected enrolment email is something to query, not to ignore.
t_ok(stripos($ivMail, "didn't request this") === false, 'it does not claim they asked for it');
t_ok(strpos($ivMail, 'MAM Academy enrolled you') !== false, 'it says the academy enrolled them');
t_ok(strpos($ivMail, '7 days') !== false, 'and states the real window');
$ivParent = mam_email_account_invite('G', 'https://example.test/x', 'parent', 7);
t_ok(strpos($ivParent, "your child's lessons") !== false, 'a guardian is told what THEY will see');

// The page has to greet an invitation differently from a reset — "set a NEW
// password" to somebody who never had one is confusing, and the 60-minute line
// on the failure state is simply wrong for an invite.
$ivPage = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/reset.html');
t_ok(strpos($ivPage, "get('welcome') === '1'") !== false, 'reset.html recognises a welcome link');
t_ok(strpos($ivPage, 'Create your password') !== false, 'and changes its heading');
t_ok(strpos($ivPage, 'valid for 7 days') !== false, 'and its expiry sentence');

// Settlement must send the link, not a password. The old body printed one in a
// dashed box and told the family to change it on first sign-in.
$ivPay = (string) file_get_contents(dirname(__DIR__) . '/modules/finance/lib/payments.php');
t_ok(strpos($ivPay, 'mam_auth_send_invite') !== false, 'payment settlement sends an invitation');
t_ok(strpos($ivPay, 'Temporary password') === false, 'and no longer emails a temporary password');
$ivAuth = (string) file_get_contents(dirname(__DIR__) . '/modules/identity/lib/auth.php');
$ivEns  = substr($ivAuth, (int) strpos($ivAuth, 'function mam_ensure_student_user'), 3000);
t_ok(strpos($ivEns, 'mam_auth_unusable_password()') !== false && strpos($ivEns, 'random_bytes(5)') === false,
    'the provisioner itself never makes a usable password, so there is no plaintext for any caller to lose or send');
t_ok(strpos($ivPay, "empty(\$provision['created'])") !== false, 'and settlement invites exactly when an account was created');
// The summer status change is ONE implementation now (decisions.php,
// mam_summer_set_status), called by both the route and the console form.
$ivSummer = (string) file_get_contents(dirname(__DIR__) . '/modules/admissions/lib/decisions.php');
t_ok(strpos((string) file_get_contents(dirname(__DIR__) . '/routes/admin/summer.php'), 'mam_summer_set_status(') !== false,
    'the summer route goes through the one status implementation');
t_ok(strpos($ivSummer, "mam_auth_send_invite((int) \$e['user_id'], 'student'") !== false,
    'a summer enrolment invites the child');
t_ok(strpos($ivSummer, "'parent', 'summer_guardian_invite'") !== false,
    'and the guardian separately — the same link would set the CHILD\'s password');
