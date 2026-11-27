<?php
/**
 * Notification centre (LMS L3) — a per-user in-app inbox over the automation
 * spine. mam_notify() is the single write path; it is best-effort and
 * never-throws so emitting a notification can never break the action that
 * triggered it (feedback, finalize, assignment, …). Reads are owner-scoped:
 * a user can only ever see or mark their own notifications.
 */
declare(strict_types=1);

/** Create an in-app notification for a user. Best-effort; returns the id, or 0. */
function mam_notify(int $userId, string $type, string $title, ?string $body = null, ?string $url = null): int {
    if ($userId <= 0 || !function_exists('mam_db')) return 0;
    $title = trim($title);
    if ($title === '') return 0;
    try {
        return mam_db_insert('notifications', [
            'user_id' => $userId,
            'type'    => mb_substr($type, 0, 64),
            'title'   => mb_substr($title, 0, 200),
            'body'    => $body !== null ? mb_substr($body, 0, 2000) : null,
            'url'     => $url !== null ? mb_substr($url, 0, 500) : null,
        ]);
    } catch (\Throwable $e) { return 0; }
}

/** A user's notifications, newest first; optionally unread only. */
function mam_notifications(int $userId, bool $unreadOnly = false, int $limit = 50): array {
    if ($userId <= 0) return [];
    $sql = "SELECT id, type, title, body, url, read_at, created_at FROM notifications WHERE user_id = ?";
    if ($unreadOnly) $sql .= " AND read_at IS NULL";
    $sql .= " ORDER BY id DESC LIMIT " . max(1, min(200, $limit));
    try { return mam_db_all($sql, [$userId]); } catch (\Throwable $e) { return []; }
}

/** Count of a user's unread notifications. */
function mam_notifications_unread_count(int $userId): int {
    if ($userId <= 0) return 0;
    try { return (int)(mam_db_row("SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND read_at IS NULL", [$userId])['n'] ?? 0); }
    catch (\Throwable $e) { return 0; }
}

/** Mark one notification read (owner-scoped). Returns true if it changed. */
function mam_notification_mark_read(int $userId, int $id): bool {
    if ($userId <= 0 || $id <= 0) return false;
    try {
        $row = mam_db_row("SELECT id, read_at FROM notifications WHERE id = ? AND user_id = ?", [$id, $userId]);
        if (!$row || $row['read_at'] !== null) return false;
        mam_db_update('notifications', ['read_at' => date('Y-m-d H:i:s')], ['id' => $id]);
        return true;
    } catch (\Throwable $e) { return false; }
}

/** Mark all of a user's unread notifications read. Returns the number affected. */
function mam_notifications_mark_all_read(int $userId): int {
    if ($userId <= 0) return 0;
    try {
        $n = mam_notifications_unread_count($userId);
        mam_db()->prepare("UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL")
                ->execute([date('Y-m-d H:i:s'), $userId]);
        return $n;
    } catch (\Throwable $e) { return 0; }
}
