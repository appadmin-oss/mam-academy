<?php
/**
 * Session time — the ONE way to read a class time or compare it with "now".
 *
 * THE RULE: `class_sessions.scheduled_at` (and the dates a class runs on) are
 * Africa/Lagos WALL-CLOCK time, exactly as the office typed them into the
 * timetable. Everything else in the database is UTC (bootstrap pins PHP to UTC;
 * db.php pins MySQL's session to UTC).
 *
 * Until 2026-11-28 the two were mixed: the timetable stored Lagos time and
 * check-in, clock-in and live classes read it as UTC, so every class was an
 * hour out — "starts in 79 minutes" for a class 20 minutes away, and check-in
 * refused. The calendar feeds and meeting links read it as Lagos. Measured
 * before choosing: storing UTC instead would have meant rewriting every place a
 * time is SHOWN (27 in the portals' JS alone, plus PHP pages, emails, feeds)
 * and shifting every live row; keeping Lagos wall-clock touches only the
 * comparisons and arithmetic, and no data. Nigeria keeps no daylight saving,
 * so the mapping is exact.
 *
 *   mam_session_ts($wall)      a stored session time → Unix timestamp (real instant)
 *   mam_session_wall($ts)      a real instant → the stored wall-clock form
 *   mam_session_now()          "now" in the stored form, to compare with scheduled_at in SQL
 *   mam_session_today()        today's date in Lagos ('Y-m-d')
 *   mam_session_clock($ts,$f)  format an instant for a reader in Lagos
 *
 * ⚠️ Never strtotime() a session time, and never compare scheduled_at with
 * date()/gmdate(): both treat Lagos wall-clock as UTC. test_session_time.php
 * fails on either.
 */
declare(strict_types=1);

const MAM_SESSION_TZ = 'Africa/Lagos';

function mam_session_tz(): DateTimeZone {
    static $tz = null;
    return $tz ??= new DateTimeZone(MAM_SESSION_TZ);
}

/** A stored session time (Lagos wall-clock, 'Y-m-d H:i[:s]' or with 'T') → Unix timestamp, or null. */
function mam_session_ts(?string $wall): ?int {
    $w = trim((string) $wall);
    if ($w === '') return null;
    try { return (new DateTimeImmutable(str_replace('T', ' ', $w), mam_session_tz()))->getTimestamp(); }
    catch (Throwable $e) { return null; }
}

/** A real instant → the stored wall-clock form ('Y-m-d H:i:s' in Lagos). */
function mam_session_wall(int $ts): string {
    return (new DateTimeImmutable('@' . $ts))->setTimezone(mam_session_tz())->format('Y-m-d H:i:s');
}

/** "Now" in the stored form — bind THIS, never date(), when comparing with scheduled_at. */
function mam_session_now(): string { return mam_session_wall(time()); }

/** Today's date in Lagos. A class on "today" is a Lagos day, not a UTC one. */
function mam_session_today(?int $ts = null): string {
    return (new DateTimeImmutable('@' . ($ts ?? time())))->setTimezone(mam_session_tz())->format('Y-m-d');
}

/** Format an instant for a reader in Lagos (e.g. 'g:i A'). Null (an unparseable time) → ''. */
function mam_session_clock(?int $ts, string $format = 'g:i A'): string {
    if ($ts === null) return '';
    return (new DateTimeImmutable('@' . $ts))->setTimezone(mam_session_tz())->format($format);
}

/** Format a STORED session time for a reader (Lagos), e.g. mam_session_fmt($s['scheduled_at'], 'D j M, H:i'). */
function mam_session_fmt(?string $wall, string $format = 'D j M, H:i'): string {
    return mam_session_clock(mam_session_ts($wall), $format);
}
