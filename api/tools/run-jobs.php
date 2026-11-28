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

// Single-runner lock: a slow pass (50 SMTP sends + transient retries) can
// overrun the 5-minute cron, and two overlapping passes would both read
// "not yet reminded" and double-send invoice emails. Hold an exclusive lock
// for the lifetime of the process; bail out cleanly if another pass holds it.
$mamJobsLock = @fopen(MAM_STORAGE . '/.run-jobs.lock', 'c');
if ($mamJobsLock === false || !@flock($mamJobsLock, LOCK_EX | LOCK_NB)) {
    echo "Another job run is in progress — skipping this pass.\n";
    exit(0);
}

$limit = 100;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--limit=') === 0) $limit = max(1, (int)substr($a, 8));
}

/* ── One step at a time, each on its own ─────────────────────────────────────
   Every sweep runs inside $step(). A sweep that throws is logged as an error
   and counted; the NEXT one still runs. This used to be one straight line, so a
   single failing sweep exited the process and silently skipped everything after
   it — FX warming, invoices, the mail retry. The outcome of the whole pass is
   written as a heartbeat (mam_jobs_heartbeat_write) that /api/health reads: a
   cron that stopped, or a pass that keeps failing, is visible from outside. */
$mamJobsStarted = microtime(true);
$mamJobsFailed  = [];
$step = static function (string $name, callable $fn) use (&$mamJobsFailed): void {
    try {
        $fn();
    } catch (Throwable $e) {
        $mamJobsFailed[] = $name;
        fwrite(STDERR, "ERROR $name: " . $e->getMessage() . "\n");
        if (function_exists('mam_log')) mam_log('error', 'job_step_failed', ['step' => $name, 'error' => $e->getMessage()]);
    }
};

echo "── MAM Academy job runner ────────────────────────────────\n";
$step('jobs', function () use ($limit) {
    $s = mam_run_jobs($limit);
    printf("jobs       : ran %d · done %d · failed %d\n", $s['ran'], $s['done'], $s['failed']);
});

// Abandoned-draft nudges (A1 × L3): one "finish your application" email per eligible idle draft.
$step('draft_nudge', function () {
    if (function_exists('mam_app_draft_nudge_sweep')) printf("draft nudge: emailed %d\n", mam_app_draft_nudge_sweep());
});

// Session reminders (24h out) + KTAMS birthday notes — each gated by its
// automation flag and deduped through the events log.
$step('session_reminders', function () {
    if (function_exists('mam_automation_session_reminder_sweep')) printf("reminders  : sessions reminded %d\n", mam_automation_session_reminder_sweep());
});
$step('birthdays', function () {
    if (function_exists('mam_automation_ktams_birthday_sweep')) printf("birthdays  : KTAMS notes sent %d\n", mam_automation_ktams_birthday_sweep());
});
// Who teaches a class lives in cohort_staff (teaching.php). Heals any cohort whose
// instructor_id was written around mam_cohort_set_lead(); idempotent.
$step('cohort_staff_backfill', function () {
    if (!function_exists('mam_cohort_staff_backfill')) return;
    printf("teaching   : staff rows healed %d\n", mam_cohort_staff_backfill());
});
$step('card_expiry', function () {
    if (!function_exists('mam_automation_card_expiry_sweep')) return;
    $ce = mam_automation_card_expiry_sweep();
    printf("id cards   : expiry warnings %d, lapsed cards retired %d\n", $ce['warned'], $ce['retired']);
});
// Every actively enrolled student holds a card (cards.php): catches up anyone
// enrolled while the issue email was broken, or by a path that did not mint.
$step('card_backfill', function () {
    if (function_exists('mam_card_backfill_sweep')) printf("id cards   : issued to enrolled students without one %d\n", mam_card_backfill_sweep());
});
$step('paystack_reconcile', function () {
    if (!function_exists('mam_paystack_reconcile_sweep')) return;
    // Card payments a webhook never reached (payment-guards.php, guard 7).
    $pr = mam_paystack_reconcile_sweep();
    printf("payments   : pending card payments checked %d, settled %d, abandoned %d%s\n",
        $pr['checked'], $pr['settled'], $pr['abandoned'], $pr['skipped'] ? ' (' . $pr['skipped'] . ')' : ($pr['errors'] ? ', errors ' . $pr['errors'] : ''));
});
$step('fee_dunning', function () {
    if (function_exists('mam_fee_dunning_sweep')) printf("fees       : overdue reminders sent %d\n", mam_fee_dunning_sweep());
});
$step('lesson_deadlines', function () {
    if (!function_exists('mam_lesson_due_run')) return;
    $ld = mam_lesson_due_run();
    printf("lessons    : students reminded %d, fines %d%s\n", $ld['reminded'], $ld['fined'], $ld['skipped'] ? ' (' . $ld['skipped'] . ')' : '');
});
$step('week_drip', function () {
    if (function_exists('mam_automation_week_drip_sweep')) printf("week drip  : modules released %d\n", mam_automation_week_drip_sweep());
});

// NDPA retention. Gated twice on purpose: the `data_retention` automation says
// whether to scan at all, and `dp_retention_mode` (shipped as `report`) says
// whether anything is allowed to act on the result. In `report` this line prints
// what WOULD go and touches nothing.
if (function_exists('mam_dp_retention_sweep') && (!function_exists('mam_automation_on') || mam_automation_on('data_retention'))) {
    $step('retention', function () {
        $rt = mam_dp_retention_sweep();
        printf("retention  : mode %s, deleted %d, anonymised %d, flagged %d\n",
            $rt['mode'], $rt['deleted'], $rt['anonymised'], $rt['flagged']);
    });
    /* The same schedule, applied to the application log FILES. They carry an
       IP and a user agent per line, so they are personal data on the same
       footing as the tables above. Same gate, same automation flag. */
    $step('log_prune', function () {
        if (!function_exists('mam_dp_prune_app_logs')) return;
        $lp = mam_dp_prune_app_logs();
        printf("log prune  : mode %s, kept after %s, overdue %d, deleted %d (%d KB)\n",
            $lp['mode'], $lp['cutoff'], $lp['overdue'], $lp['deleted'], (int) round($lp['bytes'] / 1024));
    });
    /* A snapshot is a full copy of every table AND every uploaded photograph,
       so these are the largest personal-data stores on the disk. Same gate. */
    $step('snapshot_prune', function () {
        if (!function_exists('mam_backup_prune')) return;
        $bp = mam_backup_prune();
        printf("snapshots  : mode %s, kept %d, overdue %d, deleted %d (%d MB)\n",
            $bp['mode'], $bp['kept'], $bp['overdue'], $bp['deleted'], (int) round($bp['bytes'] / 1048576));
    });
}

/* The old class model is retired (legacy-carry.php). Carrying is idempotent, so
   running it nightly costs nothing once done. It used to sit INSIDE the
   retention gate above, so switching retention off also stopped it. */
$step('legacy_carry', function () {
    if (!function_exists('mam_legacy_carry')) return;
    $lc = mam_legacy_carry(null);
    printf("old classes: %d enrolments, %d marks carried; %d waiting for a course\n",
        $lc['enrolments'], $lc['marks'], count($lc['waiting']));
});

/* ── Is a backup still happening? ──────────────────────────────────────────
   This job does NOT take the backup — export.php is standalone on purpose and
   shared hosting often disables exec, so the cron runs it directly (see
   DEPLOYMENT.md). What this does is publish the key fingerprint the exporter
   stamps into each manifest, and say out loud when the newest snapshot has
   gone stale. A backup job that quietly stopped looks exactly like one that is
   working, right until somebody needs it. */
$step('backup_check', function () {
    if (!function_exists('mam_backup_write_fingerprint')) return;
    mam_backup_write_fingerprint();
    $bs = mam_backup_status();
    printf("backup     : %s — %s\n", strtoupper($bs['state']), $bs['message']);
    if ($bs['state'] !== 'ok') {
        fwrite(STDERR, "WARNING backup " . $bs['state'] . ": " . $bs['message'] . "\n");
        if (function_exists('mam_log')) mam_log('warn', 'backup_' . $bs['state'], ['message' => $bs['message']]);
    }
});

// §11 — pull live Meet participation for online sessions that have finished.
$step('meet_sync', function () {
    if (function_exists('mam_meeting_google_sweep')) printf("meet sync  : sessions reconciled %d\n", mam_meeting_google_sweep());
});
// Safeguarding concerns nobody has opened are sent again. Deliberately not an automation toggle.
$step('safeguarding_escalate', function () {
    if (function_exists('mam_sg_escalate_sweep')) printf("safeguard  : concerns escalated %d\n", mam_sg_escalate_sweep());
});
// Online classes (live-classes.php): turn the calls that finished into attendance.
$step('live_classes', function () {
    if (function_exists('mam_live_sweep')) printf("live class : sessions reconciled %d\n", mam_live_sweep());
});
// Where each student stands this week (growth.php).
$step('growth', function () {
    if (function_exists('mam_growth_snapshot_all')) printf("growth     : snapshots taken %d\n", mam_growth_snapshot_all());
});
// Uploads begun and never finished (media-store.php).
$step('media_pending', function () {
    if (function_exists('mam_media_sweep_pending')) printf("uploads    : unfinished removed %d\n", mam_media_sweep_pending());
});
// §28 academic emails — all four sweeps, ONCE. (They used to run here and then
// again individually further down; dedup stopped double mail, not double work.)
$step('academic_emails', function () {
    if (!function_exists('mam_acadmail_run_all_sweeps')) return;
    $ae = mam_acadmail_run_all_sweeps();
    if (!empty($ae['enabled'])) {
        printf("acad email : pre-class %d · absence %d · prep %d · weekly %d\n", (int)$ae['preclass'], (int)$ae['absence'], (int)$ae['prep'], (int)$ae['weekly']);
    } else {
        printf("acad email : disabled (academic_emails_enabled=0)\n");
    }
});

// Warm the live FX cache here — the ONE place allowed to hit the network — so
// the display and the charge both read the same cached rate.
$step('fx_warm', function () {
    if (!function_exists('mam_usd_to_ngn_rate')) return;
    mam_usd_to_ngn_rate(true);
    printf("fx warm    : usd→ngn %.2f\n", mam_usd_to_ngn_rate(false));
});

// Application invoices: auto-invoice when due, expire when left unpaid.
$step('application_invoices', function () {
    if (!function_exists('mam_invoices_process')) return;
    $iv = mam_invoices_process();
    printf("invoices   : scanned %d · invoiced %d · expired %d\n", $iv['scanned'], $iv['invoiced'], $iv['expired']);
});

// Paid rescheduling: expire requests whose payment window lapsed. Never touches paid ones.
$step('reschedule_expire', function () {
    if (function_exists('mam_reschedule_expire_due')) printf("reschedule : expired unpaid requests %d\n", mam_reschedule_expire_due());
});

// Drain the failed-email outbox in the same pass (under this runner's lock).
$step('mail_retry', function () {
    if (!function_exists('mam_mail_retry_failed')) return;
    $m = mam_mail_retry_failed(50, 6, 0);
    printf("mail retry : tried %d · sent %d · still-failed %d\n", $m['tried'], $m['sent'], $m['failed']);
});

if (function_exists('mam_jobs_heartbeat_write')) {
    mam_jobs_heartbeat_write(['seconds' => round(microtime(true) - $mamJobsStarted, 2), 'failed' => $mamJobsFailed]);
}
echo "Log file   : " . MAM_LOGS . '/app-' . date('Y-m-d') . ".log\n";
if ($mamJobsFailed) echo "FAILED     : " . implode(', ', $mamJobsFailed) . "\n";
exit(0);
