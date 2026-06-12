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
