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
