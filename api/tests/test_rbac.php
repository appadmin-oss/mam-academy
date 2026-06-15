<?php
/**
 * Admin access tiers (RBAC). mam_admin_tier + mam_admin_can capability matrix:
 * super → everything, manager → operational only, non-admin → nothing, and a
 * NULL tier on an admin defaults to super (backwards-compatible).
 */
declare(strict_types=1);
t_group('admin access tiers (RBAC)');

$superId   = mam_db_insert('users', ['email' => 't_rbac_super@x.dev',   'password_hash' => 'x', 'role' => 'admin',      'name' => 'Super',   'status' => 'active', 'admin_tier' => 'super']);
$mgrId     = mam_db_insert('users', ['email' => 't_rbac_mgr@x.dev',     'password_hash' => 'x', 'role' => 'admin',      'name' => 'Manager', 'status' => 'active', 'admin_tier' => 'manager']);
$nullId    = mam_db_insert('users', ['email' => 't_rbac_null@x.dev',    'password_hash' => 'x', 'role' => 'admin',      'name' => 'Legacy',  'status' => 'active']); // admin_tier NULL
$insId     = mam_db_insert('users', ['email' => 't_rbac_ins@x.dev',     'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Tutor',   'status' => 'active']);

$super = mam_db_row('SELECT * FROM users WHERE id = ?', [$superId]);
$mgr   = mam_db_row('SELECT * FROM users WHERE id = ?', [$mgrId]);
$null  = mam_db_row('SELECT * FROM users WHERE id = ?', [$nullId]);
$ins   = mam_db_row('SELECT * FROM users WHERE id = ?', [$insId]);

// ── Tier resolution ──
t_eq(mam_admin_tier($super), 'super', 'explicit super tier');
t_eq(mam_admin_tier($mgr), 'manager', 'explicit manager tier');
t_eq(mam_admin_tier($null), 'super', 'an admin with NULL tier defaults to super (no one loses access)');
t_eq(mam_admin_tier($ins), 'none', 'a non-admin has no admin tier');

// ── Capability matrix ──
foreach (['view', 'enrollments', 'admissions', 'messages', 'store_orders', 'users', 'settings', 'store_catalog', 'mail', 'migrate', 'rbac'] as $cap) {
    t_ok(mam_admin_can($super, $cap), "super can: $cap");
}
// manager: operational yes, sensitive no
t_ok(mam_admin_can($mgr, 'view'), 'manager can view');
t_ok(mam_admin_can($mgr, 'enrollments'), 'manager can work enrollments');
t_ok(mam_admin_can($mgr, 'admissions'), 'manager can work admissions');
t_ok(mam_admin_can($mgr, 'store_orders'), 'manager can fulfil store orders');
t_ok(!mam_admin_can($mgr, 'users'), 'manager CANNOT manage users');
t_ok(!mam_admin_can($mgr, 'settings'), 'manager CANNOT change settings/pricing');
t_ok(!mam_admin_can($mgr, 'store_catalog'), 'manager CANNOT edit the store catalogue');
t_ok(!mam_admin_can($mgr, 'mail'), 'manager CANNOT run mail tools');
t_ok(!mam_admin_can($mgr, 'migrate'), 'manager CANNOT migrate the DB');
t_ok(!mam_admin_can($mgr, 'rbac'), 'manager CANNOT assign admin tiers');
t_ok(!mam_admin_can($mgr, 'unknown_cap'), 'an unknown capability is super-only by default (fail-safe)');

// ── Non-admins hold no capability ──
t_ok(!mam_admin_can($ins, 'view') && !mam_admin_can($ins, 'users'), 'a non-admin holds no admin capability');

// No-lockout guarantee: a NULL tier on an admin is treated as super (proven by
// $null above), so backfilled / un-assigned legacy admins keep full access.
