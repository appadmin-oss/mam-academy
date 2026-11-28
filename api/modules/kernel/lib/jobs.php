<?php
/**
 * Automation spine (LMS L3) — append-only domain events + a due-able job queue.
 *
 * mam_event() records that something happened; mam_job_enqueue() schedules work;
 * mam_run_jobs() (driven by api/tools/run-jobs.php on cron) drains due jobs,
 * dispatching each to a handler by kind. Everything is best-effort / never-throw
 * so recording an event or enqueuing a job can't break a request. This is the
 * foundation for reminders, auto-progression, digests, and the notification
 * centre — handlers are added incrementally to mam_job_handlers().
 */
declare(strict_types=1);

/** Record an append-only domain event. Never throws. */
function mam_event(string $type, ?string $entity = null, ?int $entityId = null, array $payload = []): void {
    if (!function_exists('mam_db')) return;
    try {
        mam_db_insert('events', [
            'type'      => mb_substr($type, 0, 64),
            'entity'    => $entity !== null ? mb_substr($entity, 0, 64) : null,
            'entity_id' => $entityId,
            'payload'   => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    } catch (\Throwable $e) { /* events are best-effort */ }
}

/** Enqueue a job. run_after defaults to now (immediately due). Returns the id (0 on failure). */
function mam_job_enqueue(string $kind, array $payload = [], ?string $runAfter = null): int {
    if (!function_exists('mam_db')) return 0;
    try {
        return mam_db_insert('jobs', [
            'kind'      => mb_substr($kind, 0, 64),
            'payload'   => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'run_after' => $runAfter ?? date('Y-m-d H:i:s'),
            'status'    => 'pending',
        ]);
    } catch (\Throwable $e) { return 0; }
}

/** Pending jobs that are due (run_after <= now) and under the attempt cap. */
function mam_jobs_due(int $limit = 50, int $maxAttempts = 6, ?string $now = null): array {
    $now = $now ?? date('Y-m-d H:i:s');
    try {
        return mam_db_all(
            "SELECT * FROM jobs WHERE status = 'pending' AND attempts < ? AND (run_after IS NULL OR run_after <= ?)
              ORDER BY id ASC LIMIT " . max(1, $limit),
            [$maxAttempts, $now]
        );
    } catch (\Throwable $e) { return []; }
}

/**
 * Handler registry: kind => callable(array $payload): bool (true = done).
 * Handlers are pure of transport concerns and must be idempotent (a job can be
 * retried). New automations register here.
 */
function mam_job_handlers(): array {
    return [
        // A safe, always-available no-op (used by tests + as a heartbeat).
        'noop' => function (array $p): bool { return true; },
        // A settled payment's effects (finance/payments.php), queued with the claim.
        'settle_effects' => function (array $p): bool {
            if (!function_exists('mam_payment_settle_effects_job')) return false;
            return mam_payment_settle_effects_job($p);
        },

        // Auto-progression on pass: promote a finalized, passed enrolment to the
        // same course at the next level. Idempotent (promote won't duplicate), so
        // safe to retry. Enqueued by mam_lms_finalize when lms_auto_promote is on.
        'lms_auto_progress' => function (array $p): bool {
            if (!function_exists('mam_lms_promote_enrollment')) return false;
            $eid = (int)($p['enrollment_id'] ?? 0);
            if ($eid > 0) mam_lms_promote_enrollment($eid, null);
            return true;
        },

        // Post-session progress report deadline check (v46).
        // Runs 2 hrs after a session is marked 'held'. For every active student
        // in the cohort without a submitted report, records an instructor fine.
        'progress_report_deadline' => function (array $p): bool {
            $sessionId = (int)($p['session_id'] ?? 0);
            if ($sessionId < 1) return true; // nothing to do
            if (!function_exists('mam_progress_report_deadline_check')) return false;
            mam_progress_report_deadline_check($sessionId);
            return true;
        },
    ];
}

/**
 * Drain due jobs, dispatching each to its handler. A handler returning true (or
 * an unknown kind treated as a soft failure) updates the row; failures bump
 * attempts and retry until the cap, then mark 'failed'. Never throws. Returns a
 * {ran,done,failed} summary. $now lets tests drive a faked clock.
 */
function mam_run_jobs(int $limit = 50, ?string $now = null, int $maxAttempts = 6): array {
    $sum = ['ran' => 0, 'done' => 0, 'failed' => 0];
    if (!function_exists('mam_db')) return $sum;
    $handlers = mam_job_handlers();
    foreach (mam_jobs_due($limit, $maxAttempts, $now) as $job) {
        $sum['ran']++;
        $payload = !empty($job['payload']) ? json_decode((string)$job['payload'], true) : [];
        if (!is_array($payload)) $payload = [];
        $ok = false; $err = null;
        $h = $handlers[$job['kind']] ?? null;
        if ($h === null) { $err = 'no_handler:' . $job['kind']; }
        else { try { $ok = (bool)$h($payload); } catch (\Throwable $e) { $err = $e->getMessage(); } }

        $nowTs = date('Y-m-d H:i:s');
        try {
            if ($ok) {
                mam_db_update('jobs', ['status' => 'done', 'updated_at' => $nowTs], ['id' => (int)$job['id']]);
                $sum['done']++;
            } else {
                $att = (int)$job['attempts'] + 1;
                mam_db_update('jobs', [
                    'status'     => $att >= $maxAttempts ? 'failed' : 'pending',
                    'attempts'   => $att,
                    'last_error' => $err !== null ? mb_substr($err, 0, 255) : null,
                    'updated_at' => $nowTs,
                ], ['id' => (int)$job['id']]);
                $sum['failed']++;
            }
        } catch (\Throwable $e) { /* a bad row must not abort the batch */ }
    }
    return $sum;
}

/* ── Operational health: is the system doing its background work? ────────────
   Nothing used to say when the cron stopped. A missing job runner silently
   stops payment reconciliation, reminders, ID cards, fee reminders, FX warming
   and the mail retry — and the site still looks fine. run-jobs.php writes a
   heartbeat at the end of every pass; /api/health reads it and answers 503 when
   something is wrong, so a free uptime monitor can raise the alarm. */

/** Hours after which a missing job-runner pass is an incident (it runs every 5 minutes). */
const MAM_JOBS_STALE_HOURS = 2;

function mam_jobs_heartbeat_path(): string { return MAM_STORAGE . '/.jobs-heartbeat.json'; }

/** Record the outcome of one job-runner pass. Never throws. */
function mam_jobs_heartbeat_write(array $summary): void {
    $data = ['at' => gmdate('c'), 'seconds' => (float) ($summary['seconds'] ?? 0),
             'failed' => array_values(array_map('strval', (array) ($summary['failed'] ?? [])))];
    @file_put_contents(mam_jobs_heartbeat_path(), json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** The last pass, or null when the runner has never completed one here. */
function mam_jobs_heartbeat_read(): ?array {
    $raw = @file_get_contents(mam_jobs_heartbeat_path());
    if ($raw === false || $raw === '') return null;
    $d = json_decode($raw, true);
    return is_array($d) && isset($d['at']) ? $d : null;
}

/**
 * The operational checks, each with a state a monitor can act on:
 *   db      ok | down
 *   jobs    ok | never | stale | failing   (the last pass, and how long ago)
 *   backup  ok | none | stale | partial    (mam_backup_status, when present)
 *   mail    ok | backlog                   (failed sends in the last 24 hours)
 * `ok` is true only when every check is ok. States only — no secrets, no counts
 * an attacker could use — so the public health endpoint can carry them.
 */
function mam_ops_health(?int $now = null): array {
    $now = $now ?? time();
    $checks = [];
    try { mam_db()->query('SELECT 1'); $checks['db'] = 'ok'; } catch (Throwable $e) { $checks['db'] = 'down'; }

    $hb = mam_jobs_heartbeat_read();
    if ($hb === null) $checks['jobs'] = 'never';
    elseif ($now - (int) strtotime((string) $hb['at']) > MAM_JOBS_STALE_HOURS * 3600) $checks['jobs'] = 'stale';
    elseif (!empty($hb['failed'])) $checks['jobs'] = 'failing';
    else $checks['jobs'] = 'ok';

    if (function_exists('mam_backup_status')) {
        try { $bs = mam_backup_status(); $checks['backup'] = (string) ($bs['state'] ?? 'none'); }
        catch (Throwable $e) { $checks['backup'] = 'none'; }
    }

    if ($checks['db'] === 'ok') {
        try {
            $n = (int) (mam_db_row("SELECT COUNT(*) AS n FROM mail_log WHERE status = 'failed' AND created_at >= ?",
                [gmdate('Y-m-d H:i:s', $now - 86400)])['n'] ?? 0);
            $checks['mail'] = $n >= 10 ? 'backlog' : 'ok';
        } catch (Throwable $e) { /* no mail_log on this install yet */ }
    }

    $ok = true;
    foreach ($checks as $state) if ($state !== 'ok') { $ok = false; break; }
    return ['ok' => $ok, 'checks' => $checks, 'jobs_last_run' => $hb['at'] ?? null];
}
