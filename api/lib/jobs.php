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

        // Auto-progression on pass: promote a finalized, passed enrolment to the
        // same course at the next level. Idempotent (promote won't duplicate), so
        // safe to retry. Enqueued by mam_lms_finalize when lms_auto_promote is on.
        'lms_auto_progress' => function (array $p): bool {
            if (!function_exists('mam_lms_promote_enrollment')) return false;
            $eid = (int)($p['enrollment_id'] ?? 0);
            if ($eid > 0) mam_lms_promote_enrollment($eid, null);
            return true;
        },

        // Abandoned-draft nudge sweep: one gentle "finish your application" email
        // per eligible server draft (idle 24h–7d, has an email, not yet nudged).
        'app_draft_nudge' => function (array $p): bool {
            if (!function_exists('mam_app_draft_nudge_sweep')) return false;
            mam_app_draft_nudge_sweep();
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
