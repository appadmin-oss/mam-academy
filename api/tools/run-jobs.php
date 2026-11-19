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

echo "── MAM Academy job runner ────────────────────────────────\n";
$s = mam_run_jobs($limit);
printf("jobs       : ran %d · done %d · failed %d\n", $s['ran'], $s['done'], $s['failed']);

// Abandoned-draft nudges (A1 × L3): one "finish your application" email per
// eligible idle draft.
if (function_exists('mam_app_draft_nudge_sweep')) {
    printf("draft nudge: emailed %d\n", mam_app_draft_nudge_sweep());
}

// Session reminders (24h out) + KTAMS birthday notes — each gated by its
// automation flag (admin ?v=automations) and deduped through the events log.
if (function_exists('mam_automation_session_reminder_sweep')) {
    printf("reminders  : sessions reminded %d\n", mam_automation_session_reminder_sweep());
}
if (function_exists('mam_automation_ktams_birthday_sweep')) {
    printf("birthdays  : KTAMS notes sent %d\n", mam_automation_ktams_birthday_sweep());
}
if (function_exists('mam_automation_card_expiry_sweep')) {
    $ce = mam_automation_card_expiry_sweep();
    printf("id cards   : expiry warnings %d, lapsed cards retired %d
", $ce['warned'], $ce['retired']);
}
if (function_exists('mam_fee_dunning_sweep')) {
    printf("fees       : overdue reminders sent %d\n", mam_fee_dunning_sweep());
}
if (function_exists('mam_lesson_due_run')) {
    $ld = mam_lesson_due_run();
    printf("lessons    : students reminded %d, fines %d%s\n", $ld['reminded'], $ld['fined'], $ld['skipped'] ? ' (' . $ld['skipped'] . ')' : '');
}
if (function_exists('mam_automation_week_drip_sweep')) {
    printf("week drip  : modules released %d\n", mam_automation_week_drip_sweep());
}

// NDPA retention. Gated twice on purpose: the `data_retention` automation says
// whether to scan at all, and `dp_retention_mode` (shipped as `report`) says
// whether anything is allowed to act on the result. In `report` this line prints
// what WOULD go and touches nothing.
if (function_exists('mam_dp_retention_sweep') && (!function_exists('mam_automation_on') || mam_automation_on('data_retention'))) {
    $rt = mam_dp_retention_sweep();
    printf("retention  : mode %s, deleted %d, anonymised %d, flagged %d\n",
        $rt['mode'], $rt['deleted'], $rt['anonymised'], $rt['flagged']);

    /* The same schedule, applied to the application log FILES. They carry an
       IP and a user agent per line, so they are personal data on the same
       footing as the tables above — and until this ran, nothing ever removed
       them. Same gate, same automation flag, so an academy that has not opted
       into enforcement gets a count and no deletion. */
    if (function_exists('mam_dp_prune_app_logs')) {
        $lp = mam_dp_prune_app_logs();
        printf("log prune  : mode %s, kept after %s, overdue %d, deleted %d (%d KB)\n",
            $lp['mode'], $lp['cutoff'], $lp['overdue'], $lp['deleted'], (int) round($lp['bytes'] / 1024));
    }

    /* A snapshot is a full copy of every table AND every uploaded photograph,
       so these are the largest personal-data stores on the disk. Same gate. */
    if (function_exists('mam_backup_prune')) {
        $bp = mam_backup_prune();
        printf("snapshots  : mode %s, kept %d, overdue %d, deleted %d (%d MB)\n",
            $bp['mode'], $bp['kept'], $bp['overdue'], $bp['deleted'], (int) round($bp['bytes'] / 1048576));
    }
}

/* ── Is a backup still happening? ──────────────────────────────────────────
   This job does NOT take the backup — export.php is standalone on purpose and
   shared hosting often disables exec, so the cron runs it directly (see
   DEPLOYMENT.md). What this does is publish the key fingerprint the exporter
   stamps into each manifest, and say out loud when the newest snapshot has
   gone stale. A backup job that quietly stopped looks exactly like one that is
   working, right until somebody needs it. */
if (function_exists('mam_backup_write_fingerprint')) {
    mam_backup_write_fingerprint();
    $bs = mam_backup_status();
    printf("backup     : %s — %s\n", strtoupper($bs['state']), $bs['message']);
    if ($bs['state'] !== 'ok') {
        fwrite(STDERR, "WARNING backup " . $bs['state'] . ": " . $bs['message'] . "\n");
        if (function_exists('mam_log')) mam_log('warn', 'backup_' . $bs['state'], ['message' => $bs['message']]);
    }
}

// §28 academic emails — the four swept types (gated behind academic_emails_enabled).
// §11 — pull live Meet participation for online sessions that have finished.
// A no-op (0) until the Google service account is configured.
if (function_exists('mam_meeting_google_sweep')) {
    printf("meet sync  : sessions reconciled %d\n", mam_meeting_google_sweep());
}
// Online classes (live-classes.php): turn the calls that finished into attendance.
if (function_exists('mam_live_sweep')) {
    printf("live class : sessions reconciled %d\n", mam_live_sweep());
}
// Where each student stands this week (growth.php) — stored so a later correction shows in its own week.
if (function_exists('mam_growth_snapshot_all')) {
    printf("growth     : snapshots taken %d\n", mam_growth_snapshot_all());
}
// Uploads begun and never finished (media-store.php).
if (function_exists('mam_media_sweep_pending')) {
    printf("uploads    : unfinished removed %d\n", mam_media_sweep_pending());
}
if (function_exists('mam_acadmail_run_all_sweeps')) {
    $ae = mam_acadmail_run_all_sweeps();
    if (!empty($ae['enabled'])) {
        printf("acad email : pre-class %d · absence %d · prep %d · weekly %d\n", (int)$ae['preclass'], (int)$ae['absence'], (int)$ae['prep'], (int)$ae['weekly']);
    } else {
        printf("acad email : disabled (academic_emails_enabled=0)\n");
    }
}

// Warm the live FX cache here (the one place that's allowed to hit the network)
// so the public display endpoint and the charge path both read the SAME cached
// rate — the UI's "approx ₦…" can't disagree with what's charged.
if (function_exists('mam_usd_to_ngn_rate')) {
    mam_usd_to_ngn_rate(true);
    printf("fx warm    : usd→ngn %.2f\n", mam_usd_to_ngn_rate(false));
}

// Application invoices: auto-invoice applications whose fee is due, and expire
// those left unpaid past the window (the applicant must then re-apply).
if (function_exists('mam_invoices_process')) {
    $iv = mam_invoices_process();
    printf("invoices   : scanned %d · invoiced %d · expired %d\n", $iv['scanned'], $iv['invoiced'], $iv['expired']);
}

// Paid rescheduling (LMS 2.0 Part C): expire requests whose payment window has
// lapsed (no-op when the feature is disabled). Never touches paid requests.
if (function_exists('mam_reschedule_expire_due')) {
    printf("reschedule : expired unpaid requests %d\n", mam_reschedule_expire_due());
}

// Academic email engine (LMS 2.0 §28): pre-class reminders, preparation alerts,
// absence notices, weekly reports. No-op unless academic_emails_enabled=1.
if (function_exists('mam_acadmail_preclass_reminder_sweep')) {
    printf("acad mail  : pre-class %d · prep %d · absence %d · weekly %d\n",
        mam_acadmail_preclass_reminder_sweep(),
        mam_acadmail_prep_alert_sweep(),
        mam_acadmail_absence_sweep(),
        mam_acadmail_weekly_report_sweep());
}

// Generalised cron: also drain the failed-email outbox in the same pass.
if (function_exists('mam_mail_retry_failed')) {
    $m = mam_mail_retry_failed(50, 6, 0);
    printf("mail retry : tried %d · sent %d · still-failed %d\n", $m['tried'], $m['sent'], $m['failed']);
}
echo "Log file   : " . MAM_LOGS . '/app-' . date('Y-m-d') . ".log\n";
exit(0);
