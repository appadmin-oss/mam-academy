<?php
/**
 * DB-backed rate limiter (SQLite or MySQL via mam_db_upsert).
 * mam_rate_limit('apply', mam_client_ip(), 10, 600) → throws 429 if
 * more than 10 requests in the past 600 seconds.
 */
declare(strict_types=1);

function mam_rate_limit(string $name, string $key, int $limit, int $windowSec): void {
    $bucket = $name . ':' . sha1($key);
    $now = time();
    $reset = $now + $windowSec;
    $db = mam_db();
    $db->beginTransaction();
    try {
        $row = mam_db_row('SELECT count, reset_at FROM rate_limits WHERE bucket = ?', [$bucket]);
        if (!$row || (int)$row['reset_at'] <= $now) {
            mam_db_upsert('rate_limits',
                ['bucket' => $bucket, 'count' => 1, 'reset_at' => $reset],
                ['bucket'],
                ['count' => 1, 'reset_at' => $reset]);
            $db->commit();
            return;
        }
        if ((int)$row['count'] >= $limit) {
            $db->commit();
            header('Retry-After: ' . max(1, (int)$row['reset_at'] - $now));
            mam_log('warn', 'rate_limited', ['bucket' => $name, 'key_hash' => sha1($key)]);
            mam_fail('rate_limited', 429, ['retry_after' => (int)$row['reset_at'] - $now]);
        }
        $db->prepare('UPDATE rate_limits SET count = count + 1 WHERE bucket = ?')->execute([$bucket]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        // Fail open: log but do not block legitimate users due to DB error
        mam_log('warn', 'rate_limit_error', ['err' => $e->getMessage()]);
    }
}
