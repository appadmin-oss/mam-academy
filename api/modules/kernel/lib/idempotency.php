<?php
/**
 * Shared idempotency layer.
 *
 * Any write endpoint can opt in by calling mam_idempotency_replay() at the
 * top of the handler and mam_idempotency_store() just before mam_ok(). This
 * guarantees that a client retrying on a network error receives the identical
 * JSON that the first request produced, without the operation running twice.
 *
 * Storage: `idempotency_cache` table (schema v51). Entries expire after 24 h
 * (a 410-Gone replay rather than a stored response, matching Stripe's contract).
 * The primary key is (scope, idem_key) so keys from /api/application never
 * collide with keys from /api/payment/initialize.
 *
 * Usage pattern (inside a route file):
 *
 *   $iKey = mam_idempotency_key();
 *   if ($iKey !== '') mam_idempotency_replay($iKey, 'my_scope');
 *   // ... do the work ...
 *   mam_idempotency_store($iKey, 'my_scope', $responseData);
 *   mam_ok($responseData);
 *
 * mam_idempotency_replay() exits (via mam_ok) if a matching stored response
 * exists and is fresh; it returns normally (continues execution) when the
 * key is empty or no prior response exists. mam_idempotency_store() is a
 * no-op when $key is empty.
 */
declare(strict_types=1);

const MAM_IDEM_TTL_SECONDS = 86400; // 24 h

/** Read the X-Idempotency-Key request header, trimmed + bounded. '' when absent. */
function mam_idempotency_key(): string {
    return substr(trim((string)($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '')), 0, 80);
}

/**
 * Look up a prior stored response for this (scope, key) pair.
 * If found and not expired:  calls mam_ok() with the stored payload and exits.
 * If found but expired:      removes the stale entry and returns.
 * If not found / key empty:  returns (caller proceeds normally).
 */
function mam_idempotency_replay(string $key, string $scope): void {
    if ($key === '') return;
    try {
        $row = mam_db_row(
            'SELECT response_json, created_at FROM idempotency_cache WHERE idem_key = ? AND scope = ?',
            [$key, $scope]
        );
        if (!$row) return;

        $age = time() - strtotime((string)$row['created_at']);
        if ($age > MAM_IDEM_TTL_SECONDS) {
            mam_db()->prepare('DELETE FROM idempotency_cache WHERE idem_key = ? AND scope = ?')->execute([$key, $scope]);
            return;
        }

        $data = json_decode((string)$row['response_json'], true);
        if (is_array($data)) {
            mam_ok(array_merge($data, ['idempotent' => true]));
        }
    } catch (\Throwable $e) {
        // Table may not yet exist on older installs — degrade gracefully.
        mam_log('warn', 'idempotency_replay_failed', ['err' => $e->getMessage(), 'scope' => $scope]);
    }
}

/**
 * Persist a response payload for this (scope, key) pair so future retries can
 * replay it. No-op when $key is empty or the table is unavailable.
 */
function mam_idempotency_store(string $key, string $scope, array $data): void {
    if ($key === '') return;
    try {
        mam_db_upsert(
            'idempotency_cache',
            ['idem_key' => $key, 'scope' => $scope, 'response_json' => json_encode($data), 'created_at' => date('Y-m-d H:i:s')],
            ['idem_key', 'scope'],
            ['response_json' => json_encode($data), 'created_at' => date('Y-m-d H:i:s')]
        );
    } catch (\Throwable $e) {
        mam_log('warn', 'idempotency_store_failed', ['err' => $e->getMessage(), 'scope' => $scope]);
    }
}

/**
 * Purge idempotency cache entries older than TTL. Call from the daily cron
 * (api/tools/run-jobs.php) to keep the table small.
 */
function mam_idempotency_purge(): int {
    try {
        $cutoff = date('Y-m-d H:i:s', time() - MAM_IDEM_TTL_SECONDS);
        $stmt = mam_db()->prepare('DELETE FROM idempotency_cache WHERE created_at < ?');
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    } catch (\Throwable $e) {
        mam_log('warn', 'idempotency_purge_failed', ['err' => $e->getMessage()]);
        return 0;
    }
}
