<?php
/**
 * Calendar subscriptions — RFC 5545 feeds a family adds to Google, Apple or
 * Outlook once, after which every move and cancellation reaches their phone.
 *
 * Feeds: a student's classes, a guardian's (every linked child), an
 * instructor's teaching, a room's bookings, a cohort's sessions.
 *
 * Tokens are never stored. A feed row lives in `calendar_tokens` (v65) with
 * scope `feed:<kind>:<subject>`; the URL token is DERIVED —
 * HMAC(app key, row id + created_at + scope) — and only its SHA-256 is kept in
 * the `token` column. So the database alone yields no working URL, the console
 * can still show a subscriber their link without re-minting, revoking is
 * `active = 0`, and rotating is revoke + mint (a new row, a new URL). Feeds
 * lapse after MAM_CAL_FEED_TTL_DAYS and are re-issued on request.
 * (The older lms2.php feed stores its token in the clear; it is left alone and
 * nothing new uses it.)
 *
 * Times are the academy's wall clock: DTSTART;TZID=Africa/Lagos with a
 * VTIMEZONE (WAT, UTC+1, no daylight saving). UIDs are stable per session;
 * SEQUENCE counts the moves (session_history) plus one for a cancellation, and a
 * cancelled session stays in the feed as STATUS:CANCELLED so calendars remove it.
 */
declare(strict_types=1);

const MAM_CAL_FEED_KINDS = ['student', 'guardian', 'instructor', 'room', 'cohort'];
const MAM_CAL_FEED_TTL_DAYS = 365;

function mam_cal_feed_scope(string $kind, int $subject): string { return 'feed:' . $kind . ':' . $subject; }

/** The URL token for a feed row. Derived, never stored. */
function mam_cal_feed_token(array $row): string {
    return mam_b64url_encode(substr(hash_hmac('sha256', 'calfeed|' . (int) $row['id'] . '|' . (string) $row['created_at'] . '|' . (string) $row['scope'], mam_app_key(), true), 0, 24));
}

function mam_cal_feed_expired(array $row, ?int $now = null): bool {
    return strtotime((string) $row['created_at']) + MAM_CAL_FEED_TTL_DAYS * 86400 < ($now ?? time());
}

/** The live feed row for a subject, or null. */
function mam_cal_feed_row(string $kind, int $subject): ?array {
    $r = mam_db_row('SELECT * FROM calendar_tokens WHERE scope = ? AND active = 1 ORDER BY id DESC LIMIT 1', [mam_cal_feed_scope($kind, $subject)]);
    return ($r && !mam_cal_feed_expired($r)) ? $r : null;
}

/** Mint a feed, or return the live one. $ownerId: who it belongs to / who asked (an FK, so a real user). */
function mam_cal_feed_mint(string $kind, int $subject, int $ownerId): array {
    if (!in_array($kind, MAM_CAL_FEED_KINDS, true) || $subject <= 0 || $ownerId <= 0) throw new InvalidArgumentException('bad_feed');
    if ($r = mam_cal_feed_row($kind, $subject)) return $r + ['url_token' => mam_cal_feed_token($r)];
    $scope = mam_cal_feed_scope($kind, $subject);
    $id = mam_db_insert('calendar_tokens', ['user_id' => $ownerId, 'token' => 'pending:' . bin2hex(random_bytes(16)), 'scope' => $scope]);
    $row = mam_db_row('SELECT * FROM calendar_tokens WHERE id = ?', [$id]);
    $tok = mam_cal_feed_token($row);
    mam_db_update('calendar_tokens', ['token' => hash('sha256', $tok)], ['id' => $id]);
    mam_audit('cal_feed_mint', 'calendar_feed', $id, ['scope' => $scope, 'by' => $ownerId]);
    return mam_db_row('SELECT * FROM calendar_tokens WHERE id = ?', [$id]) + ['url_token' => $tok];
}

function mam_cal_feed_revoke(string $kind, int $subject, ?int $actorId = null): int {
    $n = mam_db_update('calendar_tokens', ['active' => 0], ['scope' => mam_cal_feed_scope($kind, $subject), 'active' => 1]);
    if ($n) mam_audit('cal_feed_revoke', 'calendar_feed', $subject, ['kind' => $kind, 'by' => $actorId]);
    return $n;
}

/** Rotate: the old URL stops working, a new one is issued. */
function mam_cal_feed_rotate(string $kind, int $subject, int $ownerId): array {
    mam_cal_feed_revoke($kind, $subject, $ownerId);
    return mam_cal_feed_mint($kind, $subject, $ownerId);
}

/** A URL token → its live feed row, or null. Hash lookup; inactive and lapsed are refused. */
function mam_cal_feed_resolve(string $token): ?array {
    $token = trim($token);
    if ($token === '' || strlen($token) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $token)) return null;
    $r = mam_db_row("SELECT * FROM calendar_tokens WHERE token = ? AND active = 1 AND scope LIKE 'feed:%'", [hash('sha256', $token)]);
    if (!$r || mam_cal_feed_expired($r) || !hash_equals(mam_cal_feed_token($r), $token)) return null;
    return $r;
}

/** The sessions a feed carries: 30 days back to a year ahead, cancellations included. */
function mam_cal_feed_sessions(string $kind, int $subject, ?string $from = null, ?string $to = null): array {
    $from ??= mam_session_wall(time() - 30 * 86400); $to ??= mam_session_wall(time() + 366 * 86400);
    $live = "('active','pending','completed')";
    switch ($kind) {
        case 'student':  $where = "cs.cohort_id IN (SELECT cohort_id FROM course_enrollments WHERE student_id = ? AND status IN $live)"; $args = [$subject]; break;
        case 'guardian': $where = "cs.cohort_id IN (SELECT ce.cohort_id FROM course_enrollments ce JOIN parent_links pl ON pl.student_id = ce.student_id WHERE pl.parent_id = ? AND ce.status IN $live)"; $args = [$subject]; break;
        case 'instructor': $where = '(co.instructor_id = ? OR cs.substitute_id = ?)'; $args = [$subject, $subject]; break;
        case 'room':     $where = 'cs.room_id = ?'; $args = [$subject]; break;
        case 'cohort':   $where = 'cs.cohort_id = ?'; $args = [$subject]; break;
        default: return [];
    }
    $sql = "SELECT cs.*, co.title AS cohort_title, r.name AS room_name,
                              (SELECT COUNT(*) FROM session_history h WHERE h.session_id = cs.id AND h.field_changed = 'scheduled_at') AS moves
                         FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id LEFT JOIN rooms r ON r.id = cs.room_id
                        WHERE $where AND cs.scheduled_at >= ? AND cs.scheduled_at < ? AND cs.status IN ('scheduled','held','cancelled')
                        ORDER BY cs.scheduled_at LIMIT 1500";
    $rows = mam_db_all($sql, array_merge($args, [$from, $to]));
    // Lazily give an older online session its link on first read (a few per request,
    // so a feed fetch never waits on dozens of provider calls).
    $fixed = 0;
    foreach ($rows as &$r) {
        if ($fixed < 10 && ($r['status'] ?? '') === 'scheduled' && in_array((string) ($r['mode'] ?? ''), ['online', 'hybrid'], true) && empty($r['meeting_link'])) {
            $x = mam_meeting_ensure((int) $r['id']); $fixed++;
            if ($x['link']) $r['meeting_link'] = $x['link'];
        }
    }
    unset($r);
    return $rows;
}

function mam_cal_feed_title(string $kind, int $subject): string {
    $name = match ($kind) {
        'student', 'guardian', 'instructor' => (string) (mam_db_row('SELECT name FROM users WHERE id = ?', [$subject])['name'] ?? ''),
        'room' => (string) (mam_db_row('SELECT name FROM rooms WHERE id = ?', [$subject])['name'] ?? ''),
        'cohort' => (string) (mam_db_row('SELECT title FROM cohorts WHERE id = ?', [$subject])['title'] ?? ''),
        default => '',
    };
    return 'MAM Academy' . ($name !== '' ? ' — ' . $name : '') . ($kind === 'guardian' ? ' (family)' : ($kind === 'instructor' ? ' (teaching)' : ''));
}

/* ── RFC 5545 ───────────────────────────────────────────────────────────── */

function mam_cal_escape(string $s): string {
    return str_replace(["\\", "\r\n", "\n", ",", ";"], ["\\\\", "\\n", "\\n", "\\,", "\\;"], $s);
}

/** Fold a content line at 75 octets without splitting a UTF-8 character (§3.1). */
function mam_cal_fold(string $line): string {
    if (strlen($line) <= 75) return $line;
    $out = []; $cur = ''; $limit = 75;
    foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if (strlen($cur) + strlen($ch) > $limit) { $out[] = $cur; $cur = ' '; $limit = 75; }
        $cur .= $ch;
    }
    $out[] = $cur;
    return implode("\r\n", $out);
}

function mam_cal_local(string $at): string { return mam_session_fmt($at, 'Ymd\THis'); } // Lagos wall-clock, emitted with TZID

/** One VEVENT's lines for a session row (from mam_cal_feed_sessions or similar). */
function mam_cal_vevent(array $s, ?string $stamp = null): array {
    $start = (string) $s['scheduled_at'];
    $mins = (int) ($s['duration_min'] ?: 60);
    $cancelled = ($s['status'] ?? '') === 'cancelled';
    $title = (string) (($s['title'] ?? '') !== '' ? $s['title'] : ($s['cohort_title'] ?? 'Class'));
    $online = in_array((string) ($s['mode'] ?? ''), ['online', 'hybrid'], true);
    $room = trim((string) (($s['room_name'] ?? '') ?: ($s['room'] ?? '')));
    $link = (!$cancelled && $online && !empty($s['meeting_link'])) ? (string) $s['meeting_link'] : '';
    $loc = ($s['mode'] ?? '') === 'online' ? 'Online' : ($room !== '' ? $room . ', MAM Academy' : 'MAM Academy');
    $desc = mam_cal_desc($s, $link, $cancelled);
    $lines = ['BEGIN:VEVENT',
        'UID:session-' . (int) $s['id'] . '@academy.mamcentre.org',
        'DTSTAMP:' . ($stamp ?? gmdate('Ymd\THis\Z')),
        'DTSTART;TZID=' . MAM_SCHED_TZ . ':' . mam_cal_local($start),
        'DTEND;TZID=' . MAM_SCHED_TZ . ':' . mam_session_clock((mam_session_ts($start) ?? 0) + $mins * 60, 'Ymd\THis'),
        'SEQUENCE:' . ((int) ($s['moves'] ?? 0) + ($cancelled ? 1 : 0)),
        'STATUS:' . ($cancelled ? 'CANCELLED' : 'CONFIRMED'),
        'SUMMARY:' . mam_cal_escape(($cancelled ? 'Cancelled: ' : '') . $title),
        'LOCATION:' . mam_cal_escape($loc)];
    if ($link !== '') $lines[] = 'URL:' . $link;
    if ($desc !== '') $lines[] = 'DESCRIPTION:' . mam_cal_escape($desc);
    $lines[] = 'TRANSP:' . ($cancelled ? 'TRANSPARENT' : 'OPAQUE');
    $lines[] = 'END:VEVENT';
    return $lines;
}

function mam_cal_desc(array $s, string $link, bool $cancelled): string {
    $p = [];
    if (!empty($s['cohort_title'])) $p[] = 'Class: ' . $s['cohort_title'];
    if ($cancelled && !empty($s['cancellation_reason'])) $p[] = 'Cancelled — ' . $s['cancellation_reason'];
    if ($link !== '') $p[] = 'Join online: ' . $link;
    return implode("\n", $p);
}

/** A whole calendar. $events are session rows. */
function mam_cal_ics(array $events, string $name, ?string $stamp = null): string {
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//MAM Academy//Timetable//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'X-WR-CALNAME:' . mam_cal_escape($name), 'X-WR-TIMEZONE:' . MAM_SCHED_TZ,
        'REFRESH-INTERVAL;VALUE=DURATION:PT6H', 'X-PUBLISHED-TTL:PT6H',
        // West Africa Time: UTC+1 all year, no daylight saving.
        'BEGIN:VTIMEZONE', 'TZID:' . MAM_SCHED_TZ, 'BEGIN:STANDARD', 'DTSTART:19700101T000000',
        'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0100', 'TZNAME:WAT', 'END:STANDARD', 'END:VTIMEZONE'];
    foreach ($events as $s) foreach (mam_cal_vevent($s, $stamp) as $l) $lines[] = $l;
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", array_map('mam_cal_fold', $lines)) . "\r\n";
}

/* ── Links ──────────────────────────────────────────────────────────────── */

function mam_cal_feed_url(array $row): string {
    return mam_sched_app_url() . '/api/calendar/feed?t=' . rawurlencode($row['url_token'] ?? mam_cal_feed_token($row));
}

/** The one-click subscribe links. None needs an API key. */
function mam_cal_subscribe_links(string $httpsUrl): array {
    $webcal = preg_replace('~^https?://~', 'webcal://', $httpsUrl);
    return [
        'google'  => 'https://calendar.google.com/calendar/r?cid=' . rawurlencode($webcal),
        'apple'   => $webcal,
        'outlook' => 'https://outlook.live.com/calendar/0/addfromweb?url=' . rawurlencode($httpsUrl) . '&name=' . rawurlencode('MAM Academy'),
        'office'  => 'https://outlook.office.com/calendar/0/addfromweb?url=' . rawurlencode($httpsUrl) . '&name=' . rawurlencode('MAM Academy'),
        'ics'     => $httpsUrl,
    ];
}

/** "Add this one session" links: Google, Outlook, and the .ics download route. */
function mam_cal_event_links(array $s): array {
    $start = (mam_session_ts((string) $s['scheduled_at']) ?? false);
    $end = $start + (int) ($s['duration_min'] ?: 60) * 60;
    $title = (string) (($s['title'] ?? '') !== '' ? $s['title'] : ($s['cohort_title'] ?? 'MAM class'));
    $loc = ($s['mode'] ?? '') === 'online' ? 'Online' : trim((string) (($s['room_name'] ?? '') ?: ($s['room'] ?? 'MAM Academy')));
    $details = !empty($s['meeting_link']) ? 'Join online: ' . $s['meeting_link'] : '';
    $iso = fn(int $t) => mam_session_clock($t, 'Y-m-d\TH:i:sP'); // Lagos local with its offset
    return [
        'google' => 'https://calendar.google.com/calendar/render?' . http_build_query(['action' => 'TEMPLATE', 'text' => $title,
            'dates' => mam_session_clock($start, 'Ymd\THis') . '/' . mam_session_clock($end, 'Ymd\THis'), 'ctz' => MAM_SCHED_TZ, 'location' => $loc, 'details' => $details]),
        'outlook' => 'https://outlook.live.com/calendar/0/deeplink/compose?' . http_build_query(['path' => '/calendar/action/compose', 'rru' => 'addevent',
            'subject' => $title, 'startdt' => $iso($start), 'enddt' => $iso($end), 'location' => $loc, 'body' => $details]),
        'ics' => '/api/calendar/session?id=' . (int) $s['id'],
    ];
}

/** The feeds a signed-in person can subscribe to, by role. Reads only. */
function mam_cal_my_feed_kinds(array $user): array {
    $roles = array_column(mam_db_all('SELECT role FROM user_roles WHERE user_id = ?', [(int) $user['id']]), 'role');
    $roles[] = (string) ($user['role'] ?? '');
    $out = [];
    if (in_array('student', $roles, true)) $out[] = 'student';
    if (in_array('parent', $roles, true)) $out[] = 'guardian';
    if (in_array('instructor', $roles, true)) $out[] = 'instructor';
    return array_values(array_unique($out));
}

/** May this user read this session (for the single .ics)? Audience, staff of it, or an admin. */
function mam_cal_user_may_see(array $user, int $sessionId): bool {
    if (function_exists('mam_user_has_role') && mam_user_has_role($user, 'admin')) return true;
    $a = mam_sched_audience($sessionId);
    return in_array((int) $user['id'], array_merge($a['students'], $a['guardians'], $a['staff']), true);
}
