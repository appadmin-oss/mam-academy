<?php
/**
 * Session time (2026-11-28). class_sessions.scheduled_at is Africa/Lagos
 * wall-clock; every other timestamp is UTC. Reading the former as UTC put every
 * class an hour out: "starts in 79 minutes" for a class 20 minutes away, and
 * check-in refused. api/modules/academics/lib/session-time.php is the one way in.
 */
declare(strict_types=1);

t_group('session time — the helpers');

$ST_ts = mam_session_ts('2026-12-01 10:00:00');
t_eq($ST_ts, gmmktime(9, 0, 0, 12, 1, 2026), '10:00 in Lagos is 09:00 UTC (Lagos is UTC+1, no daylight saving)');
t_eq(mam_session_wall((int) $ST_ts), '2026-12-01 10:00:00', 'and converts back to the same wall-clock form');
t_eq(mam_session_ts('2026-12-01T10:00'), $ST_ts, "the browser's datetime-local form reads the same");
t_eq(mam_session_ts(''), null, 'blank is null, not the epoch');
t_eq(mam_session_ts('not a time'), null, 'unparseable is null');
t_eq(mam_session_clock(gmmktime(15, 30, 0, 12, 1, 2026), 'H:i'), '16:30', 'an instant is shown in Lagos time');
t_eq(mam_session_fmt('2026-12-01 10:00:00', 'H:i'), '10:00', 'a stored time is shown as typed');
t_eq(mam_session_today(gmmktime(23, 30, 0, 12, 1, 2026)), '2026-12-02', "at 23:30 UTC it is already tomorrow in Lagos — 'today' is a Lagos day");

t_group('session time — the bug it fixes: a class 20 minutes away');

$ST_at = mam_session_wall(time() + 20 * 60);
$ST_w  = mam_checkin_window($ST_at, 60);
t_eq($ST_w['state'], 'open', 'a class starting in 20 minutes has its check-in open (30 minutes before)');
t_ok(abs($ST_w['seconds_to_start'] - 1200) <= 5, 'and starts in ~1200 seconds, not ~4800 [' . $ST_w['seconds_to_start'] . ']');
t_eq(mam_sportal_starts_in($ST_at), 20, "the student portal's countdown says 20 minutes");

t_group('session time — no reader goes back to treating it as UTC');

$ST_root = dirname(MAM_API_ROOT);
$ST_files = [];
foreach (['api/modules', 'api/routes', 'admin', 'partials'] as $ST_d) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ST_root . '/' . $ST_d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if (substr((string) $f, -4) === '.php') $ST_files[] = (string) $f;
}
$ST_files[] = $ST_root . '/join.php';
$ST_files[] = $ST_root . '/card.php';
$ST_direct = []; $ST_binds = [];
foreach ($ST_files as $f) {
    if (str_contains($f, '/modules/v2/') || str_ends_with($f, 'session-time.php') || str_ends_with($f, '/kernel/lib/db.php')) continue;
    $lines = file($f) ?: [];
    foreach ($lines as $i => $line) {
        // strtotime() straight on the field reads Lagos wall-clock as UTC.
        if (preg_match('/strtotime\(\s*\(?\s*(?:\(string\)\s*)?\$\w+\[[\'"]scheduled_at[\'"]\]/', $line)) $ST_direct[] = basename($f) . ':' . ($i + 1);
        // A comparison with scheduled_at must not bind a UTC clock.
        if (preg_match('/scheduled_at\s*(>=|<=|>|<|BETWEEN)\s*(\?|CURRENT_TIMESTAMP|NOW\(\)|datetime\(\'now\')/i', $line)) {
            if (preg_match('/scheduled_at\s*(>=|<=|>|<)\s*(CURRENT_TIMESTAMP|NOW\(\)|datetime\(\'now\')/i', $line)) $ST_binds[] = basename($f) . ':' . ($i + 1);
            $window = implode('', array_slice($lines, $i, 6));
            if (preg_match('/\[[^\]]*\bg?m?date\(\'Y-m-d H:i:s\'/', $window)) $ST_binds[] = basename($f) . ':' . ($i + 1);
        }
    }
}
t_eq($ST_direct, [], 'nothing strtotime()s a scheduled_at — use mam_session_ts()');
t_eq($ST_binds, [], 'no comparison with scheduled_at binds date()/gmdate()/CURRENT_TIMESTAMP — bind mam_session_now() / mam_session_wall()');
t_ok(str_contains((string) file_get_contents($ST_root . '/join.php'), 'modules/academics/lib/session-time.php'),
    'join.php (which loads its own libraries) loads the session-time helpers');
