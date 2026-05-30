<?php
/**
 * MAM Academy — Application abandonment recovery (CLI cron)
 * ─────────────────────────────────────────────────────────────
 * Finds applications that were submitted but never paid, and — once
 * they're at least RECOVERY_MIN_AGE old (default 24h) but no older
 * than RECOVERY_MAX_AGE (default 7 days) — sends a single "complete
 * your enrolment" email, then stamps recovery_sent_at so we never
 * email the same application twice.
 *
 * Run from cron, e.g. hourly:
 *   0 * * * * /usr/bin/php /path/to/api/tools/recovery-email.php >> /path/to/api/logs/recovery.log 2>&1
 *
 * Flags:
 *   --dry-run   list who would be emailed without sending
 *
 * Exit codes: 0 ok, 1 invalid usage/CLI-only.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This tool is CLI-only.\n";
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';

$dryRun  = in_array('--dry-run', array_slice($argv, 1), true);
$minHours = (int)mam_env('RECOVERY_MIN_AGE_HOURS', '24');
$maxDays  = (int)mam_env('RECOVERY_MAX_AGE_DAYS', '7');

$now      = new DateTimeImmutable('now');
$notAfter = $now->modify("-{$minHours} hours")->format('Y-m-d H:i:s'); // older than this
$notBefore = $now->modify("-{$maxDays} days")->format('Y-m-d H:i:s');  // newer than this

// Submitted-but-unpaid, in the recovery window, not yet nudged.
$rows = mam_db_all(
    "SELECT a.id, a.reference, a.total_usd, a.created_at,
            c.email, c.first_name, c.last_name
       FROM applications a
       LEFT JOIN customers c ON c.id = a.customer_id
      WHERE a.status = 'submitted'
        AND a.recovery_sent_at IS NULL
        AND a.created_at <= ?
        AND a.created_at >= ?
        AND c.email IS NOT NULL
      ORDER BY a.created_at ASC
      LIMIT 200",
    [$notAfter, $notBefore]
);

echo "── MAM recovery sweep " . $now->format('c') . " ──\n";
echo "Window: older than {$minHours}h, newer than {$maxDays}d · candidates: " . count($rows) . ($dryRun ? " · DRY RUN\n" : "\n");

$sent = 0; $failed = 0;
foreach ($rows as $r) {
    $email = (string)$r['email'];
    $label = $r['reference'] . ' <' . $email . '>';
    if ($dryRun) { echo "  would email: $label\n"; continue; }

    $ok = mam_send(
        $email,
        'Complete your MAM Academy enrolment — ' . $r['reference'],
        mam_email_application_recovery($r, $r),
        'application_recovery',
        true
    );
    if ($ok) {
        mam_db_update('applications', ['recovery_sent_at' => $now->format('Y-m-d H:i:s')], ['id' => (int)$r['id']]);
        mam_audit('recovery_email', 'application', (int)$r['id'], ['reference' => $r['reference']]);
        $sent++;
        echo "  sent: $label\n";
    } else {
        // Leave recovery_sent_at NULL so the next run retries this one.
        $failed++;
        echo "  FAILED: $label\n";
    }
}

echo "Done · sent: $sent · failed: $failed\n";
exit(0);
