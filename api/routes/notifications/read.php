<?php
/**
 * POST /api/notifications/read
 * Mark notifications read for the signed-in user. Body: {id:N} for one, or
 * {all:true} to clear the inbox. Returns the new unread count. Owner-scoped.
 */
declare(strict_types=1);

$u = mam_require_user();
$uid = (int)$u['id'];
$body = mam_json_input();

if (!empty($body['all'])) {
    $cleared = mam_notifications_mark_all_read($uid);
    mam_ok(['cleared' => $cleared, 'unread' => mam_notifications_unread_count($uid)]);
}

$id = (int)($body['id'] ?? 0);
if ($id <= 0) mam_fail('missing_id', 422);
$changed = mam_notification_mark_read($uid, $id);
mam_ok(['changed' => $changed, 'unread' => mam_notifications_unread_count($uid)]);
