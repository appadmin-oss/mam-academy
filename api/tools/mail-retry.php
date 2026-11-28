<?php
/**
 * MAM Academy — flush the failed-email outbox (CLI / cron)
 * ─────────────────────────────────────────────────────────────
 * Re-sends transactional emails that previously failed (e.g. while SMTP was
 * misconfigured, or during a transient relay outage). Once api/.env is fixed,
 * this drains the backlog — application confirmations, password resets, contact
 * auto-responses, etc. — that would otherwise have been lost.
 *
 * Safe to run on a schedule (it only touches rows under the retry cap). Add a
 * cron entry that runs it every 15 minutes (minute field "0,15,30,45"):
 *
 *     php /path/to/site/api/tools/mail-retry.php >> /var/log/mam-mail.log 2>&1
 *
 * Options:
 *   --limit=N   max rows to process this pass   (default 50)
 *   --max=N     give up on a row after N total failures (default 6)
 *
 * Exit codes: 0 ran, 1 invalid usage.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This tool is CLI-only.\n";
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';

// Share the job runner's lock: both retry the same failed rows, and with no
// claim on a row, two at once could send the same email twice.
$mamJobsLock = @fopen(MAM_STORAGE . '/.run-jobs.lock', 'c');
if ($mamJobsLock === false || !@flock($mamJobsLock, LOCK_EX | LOCK_NB)) {
    echo "The job runner is busy (it retries mail itself) — skipping this pass.\n";
    exit(0);
}

$limit = 50;
$max   = 6;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--limit=') === 0)    $limit = max(1, (int)substr($a, 8));
    elseif (strpos($a, '--max=') === 0)  $max   = max(1, (int)substr($a, 6));
}

echo "── MAM Academy mail-outbox retry ─────────────────────────\n";

$rdy = mam_mail_readiness();
echo "Transport   : " . $rdy['transport'] . ($rdy['host'] ? ' via ' . $rdy['host'] : '') . "\n";
if (!$rdy['ready']) {
    echo "⚠  Mail is NOT configured — retries will keep failing until api/.env is fixed:\n";
    foreach ($rdy['issues'] as $i) echo "   - $i\n";
}

$s = mam_mail_retry_failed($limit, $max, 0);
printf("Result      : tried %d · sent %d · still-failed %d · skipped %d\n",
    $s['tried'], $s['sent'], $s['failed'], $s['skipped']);
echo "Log file    : " . MAM_LOGS . '/app-' . date('Y-m-d') . ".log\n";

exit(0);
