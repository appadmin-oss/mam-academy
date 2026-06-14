<?php
/**
 * POST /api/admin/mail-retry
 * Re-send failed outbox rows. Body: {"id": <int>} retries a single row;
 * {} (or no id) flushes the whole eligible backlog (under the attempt cap).
 * Returns the {tried,sent,failed,skipped} summary plus refreshed status counts.
 *
 * Admin-only + CSRF.
 */
declare(strict_types=1);

$in = mam_json_input();
$id = (int)($in['id'] ?? 0);

// Bounded batch: re-send eligible failures (admin action is interactive, so a
// generous-but-finite cap keeps the request snappy even with a big backlog).
$summary = mam_mail_retry_failed(50, 6, $id);

mam_audit('retry', 'mail', $id ?: null, $summary);

$counts = ['sent' => 0, 'failed' => 0, 'queued' => 0];
try {
    foreach (mam_db_all('SELECT status, COUNT(*) AS n FROM mail_log GROUP BY status') as $c) {
        $counts[(string)$c['status']] = (int)$c['n'];
    }
} catch (\Throwable $e) { /* table may not exist yet */ }

mam_ok(['retry' => $summary, 'counts' => $counts]);
