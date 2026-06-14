<?php
/**
 * MAM Academy — job runner (CLI / cron) · LMS L3 automation spine.
 * ─────────────────────────────────────────────────────────────
 * Drains the due `jobs` queue (reminders, auto-progression, digests,
 * notifications — as handlers are added) AND flushes the failed-email outbox,
 * so a single cron entry runs all background work:
 *
 *     php /path/to/site/api/tools/run-jobs.php  >> /var/log/mam-jobs.log 2>&1
 *   (schedule: every 5 minutes)
 *
 * Options:  --limit=N  max jobs this pass (default 100)
 * Exit: 0 ran, 1 invalid usage.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); echo "This tool is CLI-only.\n"; exit(1); }
require_once __DIR__ . '/../lib/bootstrap.php';

$limit = 100;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--limit=') === 0) $limit = max(1, (int)substr($a, 8));
}

echo "── MAM Academy job runner ────────────────────────────────\n";
$s = mam_run_jobs($limit);
printf("jobs       : ran %d · done %d · failed %d\n", $s['ran'], $s['done'], $s['failed']);

// Abandoned-draft nudges (A1 × L3): one "finish your application" email per
// eligible idle draft.
if (function_exists('mam_app_draft_nudge_sweep')) {
    printf("draft nudge: emailed %d\n", mam_app_draft_nudge_sweep());
}

// Generalised cron: also drain the failed-email outbox in the same pass.
if (function_exists('mam_mail_retry_failed')) {
    $m = mam_mail_retry_failed(50, 6, 0);
    printf("mail retry : tried %d · sent %d · still-failed %d\n", $m['tried'], $m['sent'], $m['failed']);
}
echo "Log file   : " . MAM_LOGS . '/app-' . date('Y-m-d') . ".log\n";
exit(0);
