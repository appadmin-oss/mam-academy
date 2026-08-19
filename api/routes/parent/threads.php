<?php
/**
 * GET /api/parent/threads       — the guardian's inbox across all their children.
 * GET /api/parent/threads?id=N  — one thread with its messages.
 */
declare(strict_types=1);
$u  = mam_require_role('parent');
$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    try {
        $t = mam_thread_get($id, (int) $u['id']);
        if (!$t) mam_fail('thread_not_found', 404);
        mam_thread_mark_read($id, (int) $u['id']);
        mam_ok(['thread' => $t]);
    } catch (\RuntimeException $e) { mam_fail($e->getMessage(), 403); }
}
mam_ok(['threads' => mam_thread_inbox((int) $u['id']), 'channels' => mam_guardian_prefs((int) $u['id'])]);
