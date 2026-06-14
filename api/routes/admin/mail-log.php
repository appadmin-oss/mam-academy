<?php
/**
 * GET /api/admin/mail-log
 * Recent outbound-email activity from the mail_log outbox, so an operator can
 * see what was sent / failed (and the exact SMTP error) without SSH or log
 * access. Never returns the rendered body (PII + size) — re-sending is done via
 * the "Retry failed" action, which already holds the stored body.
 *
 * Query: ?status=failed|sent|queued (optional), ?limit (default 100, max 500).
 * Admin-only.
 */
declare(strict_types=1);

$status = strtolower(trim((string)($_GET['status'] ?? '')));
$limit  = max(1, min(500, (int)($_GET['limit'] ?? 100)));

$entries   = [];
$counts    = ['sent' => 0, 'failed' => 0, 'queued' => 0];
$retryable = 0;

// Soft: an install that hasn't migrated to v17 yet simply has no outbox table —
// return empty structures rather than 500-ing the dashboard.
try {
    $where = '';
    $params = [];
    if (in_array($status, ['sent', 'failed', 'queued'], true)) {
        $where = 'WHERE status = ?';
        $params[] = $status;
    }
    $entries = mam_db_all(
        "SELECT id, to_email, subject, context, is_html, status, transport, error, attempts, created_at, updated_at, sent_at
           FROM mail_log $where
          ORDER BY id DESC
          LIMIT " . $limit,
        $params
    );
    foreach (mam_db_all('SELECT status, COUNT(*) AS n FROM mail_log GROUP BY status') as $c) {
        $counts[(string)$c['status']] = (int)$c['n'];
    }
    $retryable = (int)((mam_db_row("SELECT COUNT(*) AS n FROM mail_log WHERE status='failed' AND attempts < 6 AND body IS NOT NULL") ?? ['n' => 0])['n']);
} catch (\Throwable $e) {
    // mail_log not present yet — leave the empty defaults.
}

mam_ok([
    'entries'   => $entries,
    'counts'    => $counts,
    'retryable' => $retryable,
    'readiness' => mam_mail_readiness(),
]);
