<?php
/**
 * POST /api/admin/reset-password {user_id}
 *
 * Emails the person a link to choose a new password and ends their sessions.
 * It used to take a password an administrator chose and email it in plain text
 * (HANDOFF §4w); a `password` field in the body is now ignored.
 */
declare(strict_types=1);

$in = mam_json_input();
$userId = (int)($in['user_id'] ?? 0);
if (!$userId) mam_fail('validation', 422, ['fields' => ['user_id']]);
if (!mam_db_row('SELECT id FROM users WHERE id = ?', [$userId])) mam_fail('user_not_found', 404);

$sent = mam_auth_send_invite($userId, 'portal', 'admin_password_reset');
if (!$sent) mam_fail('not_sent', 409, ['message' => 'The account must be active and have an email address.']);
mam_member_revoke_sessions($userId);
mam_audit('admin_reset_password', 'user', $userId, ['link' => true]);
mam_ok(['reset' => true, 'link_sent' => true]);
