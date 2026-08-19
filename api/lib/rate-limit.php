<?php
/**
 * DB-backed rate limiter (SQLite or MySQL via mam_db_upsert).
 * mam_rate_limit('apply', mam_client_ip(), 10, 600) → throws 429 if
 * more than 10 requests in the past 600 seconds.
 */
declare(strict_types=1);

function mam_rate_limit(string $name, string $key, int $limit, int $windowSec, bool $failClosed = false): void {
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
        // In production `mam_fail()` exits, so the 429 raised above never
        // reaches here. Under a harness that makes it throw it does, and the
        // fail-open branch below would swallow it — leaving the limiter's only
        // interesting behaviour untestable. A limiter nobody has seen block is
        // not evidence of anything, so the rejection is passed straight through.
        if (str_starts_with($e->getMessage(), 'mam_fail:')) throw $e;
        mam_log('warn', 'rate_limit_error', ['err' => $e->getMessage(), 'bucket' => $name]);
        // Sensitive buckets (login/reset) fail CLOSED — a DB error must not
        // silently disable brute-force protection. Others fail open so a
        // transient DB hiccup doesn't block legitimate public traffic.
        if ($failClosed) {
            header('Retry-After: 60');
            mam_fail('rate_limited', 429, ['retry_after' => 60]);
        }
    }
}

/**
 * ── Failure-counting limiters ────────────────────────────────────────────────
 *
 * `mam_rate_limit()` above counts REQUESTS, which is right for a public form:
 * the cost is the request itself. It is wrong for a sign-in, and the difference
 * is not academic.
 *
 * Counting requests on a login means a person who types their password
 * correctly eight times in ten minutes is locked out. That is not a hypothetical
 * — it is a family with two children signing in on two devices, a member of
 * staff whose session expired, anyone testing a change. Worse, the key is the
 * IP: on carrier-grade NAT, which is how most Nigerian mobile traffic reaches
 * the site, and on any school or office connection, those eight are shared by
 * everyone behind it. Eight SUCCESSFUL sign-ins locks out the building.
 *
 * Counting failures instead keeps the brute-force property exactly — a guesser
 * never succeeds, so their count only climbs — and costs a legitimate user
 * nothing. It is also why this pair does not simply CLEAR the bucket on success:
 * an attacker holding one valid credential could then reset the counter at will
 * between guesses at other accounts. A success must neither increment nor
 * forgive; only a failure counts.
 */

/** Over the limit already? Throws 429. Never increments. */
function mam_rate_limit_check(string $name, string $key, int $limit, bool $failClosed = false): void {
    $bucket = $name . ':' . sha1($key);
    $now = time();
    try {
        $row = mam_db_row('SELECT count, reset_at FROM rate_limits WHERE bucket = ?', [$bucket]);
        if (!$row || (int)$row['reset_at'] <= $now) return;      // window elapsed
        if ((int)$row['count'] < $limit) return;
        $retry = max(1, (int)$row['reset_at'] - $now);
        header('Retry-After: ' . $retry);
        mam_log('warn', 'rate_limited', ['bucket' => $name, 'key_hash' => sha1($key)]);
        mam_fail('rate_limited', 429, ['retry_after' => $retry]);
    } catch (Throwable $e) {
        // A 429 raised above travels as an exception too; it must not be
        // swallowed by the fail-open branch meant for database trouble.
        if (str_starts_with($e->getMessage(), 'mam_fail:')) throw $e;
        mam_log('warn', 'rate_limit_error', ['err' => $e->getMessage(), 'bucket' => $name]);
        if ($failClosed) {
            header('Retry-After: 60');
            mam_fail('rate_limited', 429, ['retry_after' => 60]);
        }
    }
}

/**
 * Record one failure. Starts the window on the first, extends nothing after —
 * the window runs from the first failure, so a slow guesser cannot hold a
 * bucket open indefinitely by pacing themselves.
 */
function mam_rate_limit_record(string $name, string $key, int $windowSec): void {
    $bucket = $name . ':' . sha1($key);
    $now = time();
    $db = mam_db();
    $db->beginTransaction();
    try {
        $row = mam_db_row('SELECT count, reset_at FROM rate_limits WHERE bucket = ?', [$bucket]);
        if (!$row || (int)$row['reset_at'] <= $now) {
            mam_db_upsert('rate_limits',
                ['bucket' => $bucket, 'count' => 1, 'reset_at' => $now + $windowSec],
                ['bucket'],
                ['count' => 1, 'reset_at' => $now + $windowSec]);
        } else {
            $db->prepare('UPDATE rate_limits SET count = count + 1 WHERE bucket = ?')->execute([$bucket]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        // Failing to RECORD a failure cannot be fail-closed — that would turn a
        // database hiccup into a lockout for someone who did nothing wrong. It
        // is logged, and the check side is what fails closed.
        mam_log('warn', 'rate_limit_error', ['err' => $e->getMessage(), 'bucket' => $name]);
    }
}
