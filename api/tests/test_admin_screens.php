<?php
/**
 * admin/index.php — two silent failure modes, both found by reading the app's own
 * log after walking every view rather than by looking at the code.
 *
 * Neither showed up in a browser: one rendered a card as nothing at all, and the
 * other only fataled on a view that happened not to be open at the time.
 */
declare(strict_types=1);

t_group('admin — a helper used across view branches must be defined at top level');

// PHP declares a top-level function at COMPILE time, but a function inside an
// `if` block is only declared when that block RUNS. So a helper defined in one
// `$view === …` branch and called from another is a hard fatal on the second
// view — and it survives review because the two screens are never open at once.
// ?v=upper threw "Call to undefined function mam_admin_instr_metric()" while
// ?v=academic worked fine. Same family as the card.php/join.php trap (CLAUDE.md #4).
$asSrc   = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
$asLines = explode("\n", $asSrc);

// Where does the view dispatch begin? Everything after it is branch-local.
$asFirstBranch = null;
foreach ($asLines as $i => $l) {
    if (preg_match('/^\s*(?:<\?php\s*)?(?:else)?if \(\$view === /', $l)) { $asFirstBranch = $i; break; }
}
t_ok($asFirstBranch !== null, 'the view dispatch is found [line ' . (($asFirstBranch ?? -1) + 1) . ']');

// Every mam_admin_* helper the file CALLS, and where it is DEFINED.
preg_match_all('/function (mam_admin_[a-z_]+)\s*\(/', $asSrc, $defs, PREG_OFFSET_CAPTURE);
$asDefLine = [];
foreach ($defs[1] as $d) {
    $asDefLine[$d[0]] = substr_count(substr($asSrc, 0, $d[1]), "\n");
}
t_ok(isset($asDefLine['mam_admin_console_return']), 'the sweep finds the helpers this file defines [' . count($asDefLine) . ']');

$asLate = [];
foreach ($asDefLine as $fn => $line) {
    // A definition after the dispatch begins is only reachable from its own
    // branch. If any OTHER branch calls it, that view is a fatal.
    if ($line <= $asFirstBranch) continue;
    $calls = [];
    foreach ($asLines as $i => $l) {
        if ($i !== $line && strpos($l, $fn . '(') !== false && strpos($l, 'function ' . $fn) === false) $calls[] = $i;
    }
    if ($calls) $asLate[$fn] = ['defined' => $line + 1, 'called' => array_map(fn($n) => $n + 1, $calls)];
}
t_eq(count($asLate), 0, 'no helper is defined inside a view branch and called from elsewhere'
    . ($asLate ? ' — ' . json_encode($asLate) : ''));

t_group('admin — its queries name columns that exist');

// The other silent failure this sweep found: `ORDER BY e.created_at` on
// `enrollments`, a table that has never had that column. The exception is caught
// and logged by the page handler, so the card rendered as nothing at all rather
// than as an error. Every table referenced with an alias in an ORDER BY is worth
// checking, because that is the clause a missing column hides in.
$asTables = [];
foreach (mam_db_all("SELECT name FROM sqlite_master WHERE type = 'table'") as $t) {
    $cols = [];
    foreach (mam_db_all('PRAGMA table_info(' . $t['name'] . ')') as $c) $cols[strtolower($c['name'])] = true;
    $asTables[$t['name']] = $cols;
}
// FROM/JOIN <table> <alias>  →  the columns that alias may carry.
preg_match_all('/\b(?:FROM|JOIN)\s+([a-z_]+)\s+([a-z]{1,3})\b/i', $asSrc, $aliases, PREG_SET_ORDER);
$asAlias = [];
foreach ($aliases as $a) {
    $tbl = strtolower($a[1]);
    if (isset($asTables[$tbl])) $asAlias[strtolower($a[2])][$tbl] = true;
}
$asBadCols = [];
preg_match_all('/ORDER BY\s+([a-z]{1,3})\.([a-z_]+)/i', $asSrc, $orders, PREG_SET_ORDER);
foreach ($orders as $o) {
    $al = strtolower($o[1]); $col = strtolower($o[2]);
    if (!isset($asAlias[$al])) continue;                 // alias we could not resolve
    // Sound only if SOME table that alias binds to has the column.
    $ok = false;
    foreach (array_keys($asAlias[$al]) as $tbl) if (isset($asTables[$tbl][$col])) { $ok = true; break; }
    if (!$ok) $asBadCols[] = $al . '.' . $col . ' (alias binds to ' . implode('/', array_keys($asAlias[$al])) . ')';
}
t_eq(count($asBadCols), 0, 'every aliased ORDER BY column exists on the table it sorts'
    . ($asBadCols ? ' — ' . implode('; ', array_unique($asBadCols)) : ''));

t_group('admin guards actually stop a request (the inert mam_fail defect)');

/* ⚠️ THE DEFECT THIS PINS, because it was invisible and total.
   `mam_json()` opens with `if (defined('MAM_RESPONSE_SENT')) return;` — meaning
   "bytes already went out, do not write a second body". A server-rendered page
   defined that same constant UP FRONT for an unrelated reason: to stop the API
   shutdown net appending a JSON envelope to its HTML (gotcha #5). The two
   meanings collided, so on those pages `mam_fail()` RETURNED instead of
   exiting and execution carried straight on past the guard.

   MEASURED on admin/index.php with a probe: a POST carrying an expired CSRF
   token logged "CSRF FAILED -> mam_fail" and then "CSRF PASSED" on the very
   next line, ran the handler, and answered `302 Location: …?v=vetting` — byte
   for byte the response a successful save gives. The CSRF and same-origin
   gates on every admin write handler were inert, and an admin whose token had
   aged out saw a page reload and no message, which is what "the forms are not
   submitting" looks like from a desk.

   `MAM_HTML_RESPONSE` now carries the "I render my own HTML" meaning, leaving
   `MAM_RESPONSE_SENT` to mean only what mam_json() reads it as. */

$AG_pages = ['admin/index.php', 'admin/console.php', 'admin/phone.php',
             'card-studio.php', 'recital.php', 'status.php', 'summer.php'];
foreach ($AG_pages as $AG_p) {
    $AG_src = (string) @file_get_contents(dirname(MAM_API_ROOT) . '/' . $AG_p);
    if ($AG_src === '') continue;
    t_ok(!str_contains($AG_src, "define('MAM_RESPONSE_SENT'"),
        "$AG_p does not pre-define MAM_RESPONSE_SENT — that is what made mam_fail() a no-op");
    t_ok(str_contains($AG_src, 'MAM_HTML_RESPONSE'),
        "$AG_p suppresses the JSON envelope with MAM_HTML_RESPONSE instead");
}

/* The shutdown net must honour BOTH, or every HTML page grows a trailing
   {"error":"no_response"} — the symptom gotcha #5 exists for. */
$AG_boot = (string) @file_get_contents(MAM_API_ROOT . '/lib/bootstrap.php');
t_ok((bool) preg_match("/!defined\('MAM_RESPONSE_SENT'\)\s*&&\s*!defined\('MAM_HTML_RESPONSE'\)/", $AG_boot),
    'the no_response net accepts either constant');
$AG_resp = (string) @file_get_contents(MAM_API_ROOT . '/lib/response.php');
t_ok(!str_contains($AG_resp, 'MAM_HTML_RESPONSE'),
    'mam_json() still keys ONLY on MAM_RESPONSE_SENT — an HTML page must never make it return early');

/* The two guards themselves. They answer a BROWSER, so they redirect with a
   message rather than emitting JSON nobody can act on — and the redirect is
   the thing that proves execution stopped. */
$AG_admin = (string) @file_get_contents(dirname(MAM_API_ROOT) . '/admin/index.php');
t_ok(!preg_match("/mam_fail\('csrf_invalid'/", $AG_admin),
    'the CSRF guard no longer relies on mam_fail(), which cannot stop this page');
t_ok(!preg_match("/mam_fail\('cross_origin_blocked'/", $AG_admin),
    '…nor does the same-origin guard');
t_ok(str_contains($AG_admin, 'admin_csrf_rejected'),
    'a rejected token is logged, so a real attempt is visible rather than merely refused');
t_ok(str_contains($AG_admin, 'Your session expired before that was saved'),
    'the admin is told their change was NOT saved, in words');

/* ⚠️ HTTP_HOST carries the port; parse_url()'s host never does. A bare
   comparison refuses every admin form on any install not served from 80/443 —
   which read as correct only for as long as the guard never fired. */
t_ok(str_contains($AG_admin, "strrpos(\$host, ':')") || str_contains($AG_admin, 'strrpos($host'),
    'the same-origin check strips the port before comparing host to host');

$AG_hostOf = static function (string $h): string {
    if ($h !== '' && $h[0] === '[') { $e = strpos($h, ']'); return $e !== false ? substr($h, 0, $e + 1) : $h; }
    $p = strrpos($h, ':');
    return $p !== false ? substr($h, 0, $p) : $h;
};
foreach ([
    ['https://academy.mamcentre.org/admin/', 'academy.mamcentre.org',     true,  'the live host'],
    ['http://127.0.0.1:8221/admin/console.php', '127.0.0.1:8221',         true,  'a dev port'],
    ['http://[::1]:8221/admin/', '[::1]:8221',                            true,  'an IPv6 literal with a port'],
    ['http://[::1]/admin/', '[::1]',                                      true,  'an IPv6 literal without one'],
    ['https://academy.mamcentre.org/x', 'academy.mamcentre.org:443',      true,  'an explicit :443'],
    ['https://evil.example/x', 'academy.mamcentre.org',                   false, 'a genuine cross-site POST'],
] as [$AG_o, $AG_h, $AG_same, $AG_why]) {
    t_eq(((string) parse_url($AG_o, PHP_URL_HOST) === $AG_hostOf($AG_h)), $AG_same,
        'same-origin: ' . $AG_why);
}

/* ⚠️ /card-studio was the page where this became an AUTH BYPASS, not merely a
   silent guard. It defines the envelope-suppressing constant BEFORE calling
   mam_require_admin(), so with the old spelling that call returned instead of
   exiting and the page rendered on. MEASURED unauthenticated against the old
   constant: HTTP 200 and 14,506 bytes of the admin ID Card Studio. With
   MAM_HTML_RESPONSE it answers 401 authentication_required.

   The general rule the whole group enforces — no page pre-defines
   MAM_RESPONSE_SENT — is what keeps this closed. This assertion names the page
   that proved it, because ORDER is what made it exploitable there and a future
   page that authenticates after suppressing the envelope would be exposed the
   same way. */
$AG_cs = (string) @file_get_contents(dirname(MAM_API_ROOT) . '/card-studio.php');
t_ok(str_contains($AG_cs, 'mam_require_admin'), 'card-studio is admin-gated');
$AG_defAt  = strpos($AG_cs, 'MAM_HTML_RESPONSE');
$AG_authAt = strpos($AG_cs, 'mam_require_admin');
t_ok($AG_defAt !== false && $AG_authAt !== false && $AG_authAt > $AG_defAt,
    '…and still authenticates AFTER suppressing the envelope — which is only safe because the constant no longer disarms mam_fail()');
