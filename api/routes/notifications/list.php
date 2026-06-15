<?php
/**
 * GET /api/notifications?unread=1&limit=50
 * The signed-in user's notifications (newest first) + their unread count.
 * Owner-scoped — a user only ever sees their own.
 */
declare(strict_types=1);

$u = mam_require_user();
$uid = (int)$u['id'];
$unreadOnly = (string)($_GET['unread'] ?? '') === '1';
$limit = (int)($_GET['limit'] ?? 50);

mam_ok([
    'notifications' => mam_notifications($uid, $unreadOnly, $limit > 0 ? $limit : 50),
    'unread'        => mam_notifications_unread_count($uid),
]);
