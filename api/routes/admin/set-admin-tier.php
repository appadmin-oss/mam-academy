<?php
/**
 * POST /api/admin/set-admin-tier  {user_id, tier:'super'|'manager'}
 * Super-only (mw_admin_cap('rbac')). Sets an existing admin's access tier.
 * Granting/revoking the admin role itself is separate user management — this
 * only refines the tier of someone who already holds the admin role (which
 * avoids the NULL-tier-defaults-to-super footgun on revoke).
 */
declare(strict_types=1);

$me  = mam_current_user();
$in  = mam_json_input();
$uid = (int)($in['user_id'] ?? 0);
$tier = strtolower(trim((string)($in['tier'] ?? '')));
if ($uid <= 0) mam_fail('validation', 422, ['fields' => ['user_id']]);
if (!in_array($tier, ['super', 'manager'], true)) mam_fail('validation', 422, ['fields' => ['tier']]);

$target = mam_db_row('SELECT id, email, role FROM users WHERE id = ?', [$uid]);
if (!$target) mam_fail('user_not_found', 404);
if (!mam_user_has_role($target, 'admin')) {
    mam_fail('not_an_admin', 422, ['detail' => 'Give this user the admin role first, then set their tier.']);
}
// Lockout guard: a super can't demote themselves to manager.
if ($me && (int)$me['id'] === $uid && $tier === 'manager') mam_fail('cannot_demote_self', 409);

mam_db_update('users', ['admin_tier' => $tier], ['id' => $uid]);
mam_audit('set_admin_tier', 'user', $uid, ['tier' => $tier]);
mam_ok(['ok' => true, 'tier' => $tier]);
