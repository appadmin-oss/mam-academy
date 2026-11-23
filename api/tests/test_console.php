<?php
/**
 * The admin console's read model and its integration points.
 *
 * What is worth testing here is NOT that a provider returns rows — the dev data
 * decides that — but the four things that have gone wrong in this repo before
 * and would go wrong again silently:
 *
 *   1. A provider that names a column the schema does not have. The legacy admin
 *      shipped exactly that for months, because the exception was caught and
 *      logged and the table rendered as nothing (CLAUDE.md gotcha #9). Here the
 *      failure REGISTER makes it assertable: a provider that reads cleanly
 *      records no failure, so the test is "call every provider, assert the
 *      register is empty" — which is the strongest statement available without
 *      pinning row counts to fixture data.
 *   2. A status filter naming a value the CHECK constraint forbids. A count
 *      that can never be non-zero is a badge nobody ever sees.
 *   3. The rail and the router disagreeing about what a screen is.
 *   4. Every screen having a renderer, and every renderer being loadable.
 */
declare(strict_types=1);

t_group('console — the rail and the router agree');

$CN_screens = mam_console_screens();
t_eq(count($CN_screens), count(array_unique($CN_screens)), 'no screen key is registered twice');
t_ok(in_array('dashboard', $CN_screens, true), 'dashboard is a screen');

/* The IA is TWO levels: nine areas in the rail, that area's sections in a
   strip under the page title. The handoff names a flat 31-item rail as the
   superseded pass — "still too long to scan on every navigation" — so these
   assertions pin the shape, not just the contents. */
$CN_areas = mam_console_nav();
t_eq(count($CN_areas), 9, 'nine areas — the rail is re-scanned on every navigation, and nine is the stated ceiling');

$CN_flat = [];
foreach ($CN_areas as $a) foreach ($a['sections'] as $sec) $CN_flat[] = $sec;
t_eq(count($CN_flat), count($CN_screens), 'mam_console_screens() flattens every section, losing nothing');

foreach ($CN_areas as $a) {
    t_ok(isset($a['key'], $a['label'], $a['icon']) && !empty($a['sections']),
        'area ' . (string) ($a['key'] ?? '?') . ' carries a key, a label, an icon and at least one section');
    // Decision 4: six is the widest strip. This test used to check EIGHT, so
    // Finance and Governance drifted to seven each with nothing failing.
    t_ok(count($a['sections']) <= 6,
        'area ' . (string) ($a['key'] ?? '?') . ' has at most six sections (' . count($a['sections']) . ')');
}
// Decision 5: a section's label is its page's title. Six screens were titled
// differently from the entry that opened them, so an admin clicked one name and
// landed on another. Read from each renderer's first ac_head().
foreach ($CN_flat as $it) {
    $src = (string) @file_get_contents(dirname(__DIR__, 2) . '/partials/admin-console/' . $it['key'] . '.php');
    if (!preg_match("/ac_head\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m)) continue;
    t_eq(stripslashes($m[1]), $it['label'], "the {$it['key']} screen is titled what its entry calls it");
}
foreach ($CN_flat as $it) {
    t_ok(isset($it['key'], $it['label']) && array_key_exists('cap', $it),
        'section ' . (string) ($it['key'] ?? '?') . ' carries a key, a label and a capability');
}

// Decision 1: Reports is its OWN area, never a Governance section. Governance
// is quarterly work and Reports is daily; mixing cadences buries the frequent
// thing, which is the whole reason it was pulled out.
$CN_areaKeys = array_column($CN_areas, 'key');
t_ok(in_array('reports', $CN_areaKeys, true), 'Reports is a top-level area');
$CN_gov = array_values(array_filter($CN_areas, fn($a) => $a['key'] === 'governance'))[0] ?? ['sections' => []];
t_ok(!in_array('reports', array_column($CN_gov['sections'], 'key'), true),
    'Reports is NOT a Governance section');

// Decision 3: the rail follows the AREA. A screen with no area orphans the
// rail — an admin on it cannot tell where they are.
foreach ($CN_screens as $CN_s) {
    t_ok(mam_console_area_of($CN_s) !== null, "screen '$CN_s' resolves to a rail area");
}

// Decision 2: a rail click lands on the first section the CALLER may see, so a
// manager without a capability is never sent to a screen that refuses them.
$CN_people = array_values(array_filter($CN_areas, fn($a) => $a['key'] === 'people'))[0];
t_eq(mam_console_area_home($CN_people, fn($c) => true), 'access', 'People opens on People & access');
// People now carries "My account", which needs no capability, so a manager
// without `users` still has a home there — their own account — rather than
// losing the area. The barred case is proven with a gate that admits nothing.
t_eq(mam_console_area_home($CN_people, fn($c) => $c !== 'users'), 'deductions',
    'a manager without `users` lands on the first People section they may open, not on a screen that refuses them');
t_eq(mam_console_area_home($CN_people, fn($c) => false), null,
    'an area whose every section is barred returns no home, so the rail omits it rather than offering a dead end');

// Dashboard and Reports are single destinations: a strip holding one tab that
// is the page you are on is furniture with no function.
foreach ($CN_areas as $a) {
    $flat = mam_console_area_is_flat($a);
    t_eq($flat, count($a['sections']) < 2, 'area ' . $a['key'] . ' reports its flatness honestly');
}

// A capability named here that mam_admin_can() does not understand would hide a
// screen from every manager, silently. Check each against the real registry.
$CN_caps = array_values(array_unique(array_filter(array_column($CN_flat, 'cap'))));
$CN_probe = ['id' => 0, 'role' => 'admin', 'admin_tier' => 'super'];
foreach ($CN_caps as $cap) {
    t_ok(mam_admin_can($CN_probe, $cap) === true,
        "capability '$cap' is one a super admin holds — a name the registry does not know would hide the screen from everybody");
}

t_group('console — every screen has a renderer, and it parses');

$CN_dir = dirname(__DIR__, 2) . '/partials/admin-console';
t_ok(is_file($CN_dir . '/_ui.php'), 'the shared render helpers exist');
foreach ($CN_screens as $screen) {
    $f = $CN_dir . '/' . $screen . '.php';
    t_ok(is_file($f), "screen '$screen' has a renderer");
    if (is_file($f)) {
        // php -l in-process: a parse error in a partial is a blank screen.
        $out = [];
        exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
        t_eq($code, 0, "screen '$screen' parses" . ($code === 0 ? '' : ': ' . implode(' ', $out)));
    }
}

t_group('console — no partial carries an inline style');

/* The stylesheet states this rule about itself, and a design system that breaks
   its own first rule is worse than one that never stated it. The single
   exception is the meter fill, which is a VALUE (a percentage from live data),
   not a design decision — so it is allowed only as a custom property. */
foreach (glob($CN_dir . '/*.php') as $f) {
    $src = (string) file_get_contents($f);
    preg_match_all('/style="([^"]*)"/', $src, $m);
    $bad = array_values(array_filter($m[1], fn($v) => strpos($v, '--ac-fill') === false));
    t_eq(count($bad), 0, basename($f) . ' carries no inline style'
        . ($bad ? ' (found: ' . implode(' | ', array_slice($bad, 0, 3)) . ')' : ''));
    // A duplicated class attribute silently drops the second one.
    t_eq(preg_match('/class="[^"]*"\s+class="/', $src), 0,
        basename($f) . ' never writes the class attribute twice on one element');
}

t_group('console — every provider reads without a schema error');

/* This is the load-bearing test. mam_console_rows()/_scalar() record a failed
   read rather than swallowing it, so a provider naming a column the schema does
   not have leaves a row in the register — and this asserts the register is
   empty after calling all of them. It catches the class of bug that made an
   admin table silently render as nothing. */
$GLOBALS['__mam_console_fail'] = [];
$CN_providers = [
    'account' => fn() => mam_console_account(),
    'badges' => fn() => mam_console_badges(),
    'term' => fn() => mam_console_term(),
    'health' => fn() => mam_console_health(),
    'dashboard' => fn() => mam_console_dashboard(),
    'admissions' => fn() => mam_console_admissions([]),
    'auditions' => fn() => mam_console_auditions(),
    'bursaries' => fn() => mam_console_bursaries(),
    'funnel' => fn() => mam_console_funnel(),
    'students' => fn() => mam_console_students([]),
    'cohorts' => fn() => mam_console_cohorts(),
    'roster' => fn() => mam_console_roster([]),
    'timetable' => fn() => mam_console_timetable([]),
    'curriculum' => fn() => mam_console_curriculum([]),
    'assessment' => fn() => mam_console_assessment(),
    'attendance' => fn() => mam_console_attendance([]),
    'guardians' => fn() => mam_console_guardians(),
    'calendar' => fn() => mam_console_calendar(),
    'events' => fn() => mam_console_events([]),
    'files' => fn() => mam_console_files(),
    'invoices' => fn() => mam_console_invoices(),
    'ledger' => fn() => mam_console_ledger(),
    'pricebooks' => fn() => mam_console_pricebooks(),
    'charges' => fn() => mam_console_charges(),
    'store' => fn() => mam_console_store(),
    'deductions' => fn() => mam_console_deductions(),
    'earnings' => fn() => mam_console_earnings(),
    'access' => fn() => mam_console_access(),
    'governance' => fn() => mam_console_governance(),
    'reports' => fn() => mam_console_reports(),
    'comms' => fn() => mam_console_comms(),
    'vetting' => fn() => mam_console_vetting(),
    'safeguarding' => fn() => mam_console_safeguarding(['concern' => 1]),
    'dataprotection' => fn() => mam_console_dataprotection(),
    'support' => fn() => mam_console_support(),
    'system' => fn() => mam_console_system(),
    'settings' => fn() => mam_console_settings(),
];
// Every screen must appear here, or a provider could rot unexercised.
foreach ($CN_screens as $screen) {
    t_ok(isset($CN_providers[$screen]), "provider for '$screen' is exercised by this test");
}
foreach ($CN_providers as $name => $fn) {
    $before = count(mam_console_failures());
    $threw = null;
    try { $r = $fn(); } catch (\Throwable $e) { $threw = get_class($e) . ': ' . $e->getMessage(); $r = null; }
    $new = array_slice(mam_console_failures(), $before);
    t_ok($threw === null, "mam_console_$name() does not throw" . ($threw ? " ($threw)" : ''));
    t_eq(count($new), 0, "mam_console_$name() reads cleanly"
        . ($new ? ' — ' . implode('; ', array_map(fn($f) => $f['where'] . ': ' . $f['error'], $new)) : ''));
    if ($threw === null) t_ok(is_array($r), "mam_console_$name() returns an array");
}

t_group('console — a status filter never names a value the schema forbids');

/* A filter on a value the CHECK constraint cannot hold is a count that is
   always zero: a badge nobody ever sees, and nothing fails. Derive the legal
   set from the live schema and check every literal the lib filters on. */
$CN_isSqlite = mam_db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
if ($CN_isSqlite) {
    $CN_vocab = function (string $table): array {
        $sql = (string) (mam_db_row("SELECT sql FROM sqlite_master WHERE name = ?", [$table])['sql'] ?? '');
        if (!preg_match('/status[^,]*?IN \(([^)]*)\)/', $sql, $m)) return [];
        preg_match_all("/'([^']+)'/", $m[1], $vals);
        return $vals[1];
    };
    $src = (string) file_get_contents(dirname(__DIR__) . '/lib/console.php');
    // Every "FROM <table> ... status IN ('a','b')" the lib writes, per table.
    foreach (['fee_invoices', 'bursary_applications', 'academic_terms', 'termly_reports'] as $table) {
        $legal = $CN_vocab($table);
        if (!$legal) continue;
        // Pull the literals out of any status filter mentioning this table.
        preg_match_all('/FROM\s+' . preg_quote($table, '/') . '\b(.{0,400}?)status\s+IN\s*\(([^)]*)\)/is', $src, $m, PREG_SET_ORDER);
        $seen = 0;
        foreach ($m as $hit) {
            preg_match_all("/'([^']+)'/", $hit[2], $vals);
            foreach ($vals[1] as $v) {
                $seen++;
                t_ok(in_array($v, $legal, true),
                    "console filters $table on '$v', which the schema admits"
                    . (in_array($v, $legal, true) ? '' : ' — legal values are ' . implode(', ', $legal)));
            }
        }
        t_ok(true, "checked $seen status literal(s) against $table's own CHECK");
    }
}

t_group('console — formatting helpers');

t_eq(mam_console_usd(31000), '$310.00', 'cents render as dollars, two places');
t_eq(mam_console_usd_short(1460000), '$14.6k', 'a KPI figure is compacted');
t_eq(mam_console_usd_short(660000000), '$6.6m', 'and so are millions');
t_eq(mam_console_usd_short(0), '$0', 'zero is zero, not $0.0k');
t_eq(mam_console_usd_short(99900), '$999', 'just under a thousand keeps its digits');
t_eq(mam_console_ngn(30000, 1580.0), '₦474,000', 'a frozen rate converts exactly');
t_eq(mam_console_ngn(30000, 0.0), '—', 'a rate of zero converts to nothing rather than to zero naira');
t_eq(mam_console_pct(3, 4), 75, 'a percentage rounds');
t_eq(mam_console_pct(1, 0), 0, 'a zero denominator is 0, not a division error');
t_eq(mam_console_initials('Ada Okonkwo'), 'AO', 'two initials from a name');
t_eq(mam_console_initials('Ada Nkechi Okonkwo'), 'AO', 'the middle name is skipped, not included');
t_eq(mam_console_initials('Prince'), 'P', 'one word gives one initial');
t_eq(mam_console_initials('  '), '—', 'no name gives a dash, never an empty circle');
t_eq(mam_console_age(null), '—', 'no timestamp is a dash');
t_eq(mam_console_date(null), '—', 'and so is no date');
t_eq(mam_console_date('not a date'), '—', 'an unparseable date is a dash, not 1 Jan 1970');

t_group('console — the status vocabulary is complete');

/* The design's rule: a status with no sentence cannot exist. So every status
   the admissions screen offers must carry all three fields. */
foreach (mam_console_status_vocabulary() as $v) {
    t_ok(($v['stored'] ?? '') !== '', 'a vocabulary row names its stored value');
    t_ok(($v['shown'] ?? '') !== '', "'{$v['stored']}' has a phrase a family reads");
    t_ok(strlen((string) ($v['next'] ?? '')) > 20, "'{$v['stored']}' says what happens next, in a sentence");
    t_ok(array_key_exists('action', $v), "'{$v['stored']}' says whether it needs an action");
}

/* Every stored value must be one the admissions state machine knows. The list
   once named design-only values (under_review, offered, rejected), so every
   decision saved from the console was silently dropped by the handler. */
$CN_states = array_keys(mam_admissions_transitions());
foreach (mam_console_status_vocabulary() as $v) {
    t_ok(in_array($v['stored'], $CN_states, true), "'{$v['stored']}' is a status the state machine knows");
}
foreach ($CN_states as $st) {
    t_ok(mam_console_status_label($st) !== '' && mam_console_status_label($st) !== $st, "'{$st}' has a phrase, not its stored value");
}
$CN_adm = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
t_ok(strpos($CN_adm, "(int)(\$_POST['app'] ?? 0)") !== false, 'app_status reads the `app` field the console decision card posts');

t_group('console — the return hook is bound to the real screen list');

/* admin/index.php rewrites a console POST's redirect at shutdown. The hook must
   validate the screen name against mam_console_screens() rather than merely
   sanitising it: "../evil?x=1" sanitised to "evilx" is safe but lands an admin
   on a screen that does not exist, and a name the console does not know means
   the POST was not from the console at all. */
$CN_admin = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
t_ok(strpos($CN_admin, 'function mam_admin_console_return') !== false,
    'admin/index.php defines the console return hook');
t_ok(strpos($CN_admin, "in_array(\$screen, mam_console_screens(), true)") !== false,
    'the hook validates against mam_console_screens(), not against a character class');
t_ok(strpos($CN_admin, "\$_POST['_console']") !== false,
    'the hook is armed from the POST body, before any handler runs');
// The helper must be ABOVE the POST dispatch, or it is declared only when the
// branch that contains it runs (CLAUDE.md gotcha #9 / #4).
$CN_fnAt = strpos($CN_admin, 'function mam_admin_console_return');
$CN_useAt = strpos($CN_admin, 'mam_admin_console_return((string)');
t_ok($CN_fnAt !== false && $CN_useAt !== false && $CN_fnAt < $CN_useAt,
    'the helper is defined above the dispatch that calls it — a top-level function inside an if block is declared only when that block runs');

t_group('console — the search endpoint is engine-portable');

$CN_search = (string) file_get_contents(dirname(__DIR__) . '/routes/admin/search.php');
$CN_searchSql = preg_replace('#/\*.*?\*/#s', '', $CN_search);
t_eq(preg_match('/\|\|/', $CN_searchSql), 0,
    'the palette search never uses `||` — it concatenates in SQLite and means OR in MySQL');
t_ok(strpos($CN_searchSql, "ESCAPE ") !== false,
    'LIKE patterns declare an escape character, so a query containing % or _ searches for those characters');
/* And the escape character is not a backslash. `ESCAPE '\'` is one backslash in
   the SQL text: SQLite accepts it, MariaDB reads it as escaping the closing
   quote and the statement becomes a syntax error. This shipped, passed every
   SQLite test, and 500'd on the first MySQL request. */
t_eq(preg_match("/ESCAPE\s+'\\\\+'/", $CN_searchSql), 0,
    "the LIKE escape character is not a backslash — `ESCAPE '\\' ` is a hard syntax error on MariaDB");

/* Exercise it against the live engine, both ways: a plain query and one made
   entirely of LIKE metacharacters. A statement that cannot even parse is the
   failure mode this is guarding. */
foreach (['oko', '100%', 'a_b', '!', '%_!'] as $CN_q) {
    $CN_like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $CN_q) . '%';
    $CN_ok = true;
    try {
        mam_db_all("SELECT id FROM users WHERE name LIKE ? ESCAPE '!' LIMIT 1", [$CN_like]);
    } catch (\Throwable $e) { $CN_ok = false; }
    t_ok($CN_ok, "a LIKE search for " . json_encode($CN_q) . " parses and runs on this engine");
}

t_group('break glass — no capability-gated admin route re-checks the session');

/* The security posture is explicit: a route behind mw_admin_cap() is already
 * authorised, so calling mam_require_user() inside its body adds nothing except
 * a 401 for the Basic-Auth break-glass operator — who is precisely the person
 * using it during a recovery. Three routes still did (`recitals` on the GET as
 * well as the POST, so nobody could even read the programme; `id-cards` on
 * revoke; `session-remind` entirely), and the sweep that found them was a real
 * HTTP request rather than a test. This asserts the class of bug, not the three
 * instances, so the next route to be written cannot reintroduce it.
 *
 * `mam_admin_require_actor()` is the sanctioned call: it returns the session
 * admin, or the recovery identity, so the ledger still names who acted. */
$BG_dir = dirname(__DIR__) . '/routes/admin';
$BG_index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
$BG_offenders = [];
foreach (glob($BG_dir . '/*.php') as $BG_f) {
    $BG_name = basename($BG_f);
    // Only routes registered behind a capability. inline-edit is registered
    // with mw_user() and resolves a per-FIELD capability against that user, so
    // a session is genuinely part of its contract.
    if (!preg_match("#admin/" . preg_quote($BG_name, '#') . "'[^;]*mw_admin_cap#", $BG_index)) continue;
    $BG_src = (string) file_get_contents($BG_f);
    // Strip comments: several of these files EXPLAIN the rule in prose that
    // names the very function they must not call.
    $BG_code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $BG_src);
    if (preg_match("/mam_require_user\s*\(|mam_require_role\s*\(\s*'admin'/", $BG_code)) {
        $BG_offenders[] = $BG_name;
    }
}
t_eq(count($BG_offenders), 0,
    'no capability-gated admin route re-checks the session in its body'
    . ($BG_offenders ? ' (found: ' . implode(', ', $BG_offenders) . ')' : ''));

// And the helper they should use resolves for a break-glass caller.
t_ok(function_exists('mam_admin_require_actor'), 'mam_admin_require_actor() exists');
t_ok(function_exists('mam_admin_actor'), 'mam_admin_actor() exists');

/* ═══════════════════════════════════════════════════════════════════════════
 * Performance marking — the audio-only control and what it refuses.
 * ═══════════════════════════════════════════════════════════════════════════ */
t_group('marking — technical criteria are heard, not watched');

// The control, stated once: video is a property of the CRITERION.
t_ok(!mam_marking_video_allowed('pitch'), 'pitch is marked by ear');
t_ok(!mam_marking_video_allowed('time'),  'time is marked by ear');
t_ok(!mam_marking_video_allowed('tone'),  'tone is marked by ear');
t_ok(mam_marking_video_allowed('shape'),  'shape needs sight — it is the one that does');

// An unknown criterion withholds the video. Showing it for a technical mark is
// the failure this exists to prevent; withholding it needlessly costs nothing.
t_ok(!mam_marking_video_allowed('something nobody registered'), 'an unknown criterion fails SAFE, to audio');

// Rubric criteria are authored by instructors, so a LABEL is what arrives.
t_eq(mam_marking_criterion('Pitch & intonation')['key'], 'pitch', 'a real rubric label resolves');
t_eq(mam_marking_criterion('Rhythmic accuracy')['key'], 'time', 'rhythm is a timing criterion');
t_eq(mam_marking_criterion('Tone and shape')['key'], 'tone',
    'a label naming BOTH resolves technical — the order is load-bearing, as in mastery.php');
t_eq(mam_marking_criterion('Stagecraft')['key'], 'shape', 'presentation words reach shape');
t_eq(mam_marking_criterion(''), null, 'an empty label resolves to nothing rather than guessing');

// The band table reproduces the design's own figures at max = 30.
$MK_b = mam_marking_bands(30);
t_eq($MK_b[0]['low'] . '-' . $MK_b[0]['high'], '27-30', 'Distinction 27–30');
t_eq($MK_b[1]['low'] . '-' . $MK_b[1]['high'], '24-26', 'Merit 24–26');
t_eq($MK_b[2]['low'] . '-' . $MK_b[2]['high'], '20-23', 'Pass 20–23');
t_eq(mam_marking_band_of(25, 30)['label'], 'Merit', '25 of 30 is a Merit');
t_eq(mam_marking_band_of(19, 30)['label'], 'Below pass', 'below the pass floor is named, not blank');

// "Not assessed" is not zero — the invariant the whole repo holds.
t_eq(mam_marking_band_of(null, 30)['band'], 'not_assessed', 'an unmarked criterion is "not assessed"');
t_ok(mam_marking_band_of(null, 30)['assessed'] === false, '…and reports itself as unassessed');
t_ok(mam_marking_band_of(0, 30)['assessed'] === true, 'a real zero IS an assessment, and differs from not marking');

// Second marking is deterministic: a marker must not be able to re-roll it.
$MK_first = mam_marking_needs_second_marker(41);
t_eq(mam_marking_needs_second_marker(41), $MK_first, 'the same submission always gives the same answer');
$MK_hits = 0;
for ($i = 1; $i <= 800; $i++) if (mam_marking_needs_second_marker($i)) $MK_hits++;
t_ok($MK_hits > 60 && $MK_hits < 140, "roughly one in eight is second-marked (got $MK_hits of 800)");
t_ok(mam_marking_needs_second_marker(7, 1), 'an every-of-1 double-marks everything rather than dividing by zero');

// No automatic scoring of a child's playing, anywhere in this file.
$MK_src = file_get_contents(dirname(__DIR__) . '/lib/marking.php');
foreach (['detectPitch', 'autoscore', 'auto_score', 'analyse_audio'] as $MK_no) {
    t_ok(!str_contains($MK_src, $MK_no), "marking.php contains no '$MK_no' — it helps a human mark, it never marks");
}

t_group('console — a table row becomes a labelled card on a phone');

/* MEASURED before this existed, at 390px: 23 of the 31 screens had a table
   scrolling sideways, and the vetting matrix hid 918px — more of the row off
   the screen than on it, with nothing to say so. `admin/index.php` already
   stacked its tables, so the console was the weaker surface on the thing a
   registrar reaches for away from a desk.

   The label is derived from the column's own header rather than hand-written
   per cell, which is what these assertions are really protecting: a written
   label can drift from its header, and cannot follow a column that does not
   exist until runtime. */
require_once dirname(dirname(__DIR__)) . '/partials/admin-console/_ui.php';

$CT_in = '<div class="ac-tablewrap"><table class="ac-table">'
    . '<thead><tr><th>Instructor</th><th>Identity</th><th colspan="2">Clearance</th></tr></thead>'
    . '<tbody>'
    . '<tr><td>Ada &amp; Co</td><td><span class="ac-pill">Missing</span></td><td>No</td><td>—</td></tr>'
    . '<tr><td colspan="4"><div class="ac-empty">Nothing yet.</div></td></tr>'
    . '</tbody></table></div>';
$CT_out = ac_label_table_cells($CT_in);

t_ok(str_contains($CT_out, 'data-label="Instructor"'), 'a body cell takes its label from its own column header');
t_ok(str_contains($CT_out, 'data-label="Identity"'), '…including a cell whose value is an element rather than text');
t_ok(str_contains($CT_out, 'data-label="Clearance"'),
    'a header spanning two columns labels the FIRST of them');
t_ok(substr_count($CT_out, 'data-label=""') === 1,
    '…and the second carries an EMPTY label, not the next header along — the map advances by colspan, never by position');

/* The empty state is a message, not a value. Labelling it with the first
   column's name would read as a row whose Instructor is "Nothing yet." */
t_ok(!preg_match('/colspan="4"[^>]*data-label/', $CT_out),
    'a cell spanning the whole table is left unlabelled');

/* ⚠️ Setting display:block on a table element DROPS its implicit ARIA role,
   so the phone layout would hand a screen reader an unstructured run of text
   at exactly the width where the visual structure has gone too. This is what
   makes the stacking rule safe to apply. */
t_ok(str_contains($CT_out, '<table class="ac-table" role="table"') || str_contains($CT_out, 'role="table"'),
    'the table states its role explicitly, because the phone layout changes its display');
foreach (['rowgroup', 'row', 'columnheader', 'cell'] as $CT_role) {
    t_ok(str_contains($CT_out, 'role="' . $CT_role . '"'), "…and so does every $CT_role");
}

/* LOSSLESS. The helper runs over every console screen on every page load, so
   the one thing it must never do is quietly rewrite the markup around the
   attribute it adds. Compared against a bare DOM round-trip so that
   normalisation is not mistaken for damage. */
$CT_roundtrip = static function (string $html): string {
    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $ok = $doc->loadHTML('<?xml encoding="UTF-8" ?><div data-ac-root="1">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    if (!$ok) return $html;
    $root = $doc->getElementsByTagName('div')->item(0); $o = '';
    foreach ($root->childNodes as $c) $o .= $doc->saveHTML($c);
    return $o;
};
$CT_stripped = preg_replace('/ (?:data-label|role)="[^"]*"/', '', $CT_out);
t_eq($CT_stripped, $CT_roundtrip($CT_in),
    'the helper adds attributes and changes NOTHING else');

/* ⚠️ That assertion compares against a DOM ROUND TRIP, not against the input,
   and the distinction matters: the round trip is not byte-identical. MEASURED
   over the 31 console screens, three differ, in two benign ways — a boolean
   attribute loses its empty value (`selected=""` becomes `selected`) and an
   attribute carrying double quotes is re-delimited with single ones
   (`onsubmit="…&quot;x&quot;"` becomes `onsubmit='…"x"'`). Both parse
   identically, which is why the helper is safe to run on every page load, but
   "lossless" overstates it and the difference should not be discovered again
   from scratch.

   The one case worth pinning is the re-delimiting, because it is only safe
   while the serializer escapes a value that contains BOTH quote characters —
   an apostrophe in a confirm message is very much this codebase's voice. */
$CT_apos = ac_label_table_cells(
    '<table class="ac-table"><thead><tr><th>A</th></tr></thead><tbody><tr><td>'
    . '<form onsubmit="return confirm(&quot;Revoke Ada&#39;s sessions?&quot;)"><button>Go</button></form>'
    . '</td></tr></tbody></table>');
t_ok(str_contains($CT_apos, 'Ada&#39;s') || str_contains($CT_apos, 'onsubmit="'),
    'an inline handler containing an apostrophe keeps double-quote delimiters — re-delimiting it would break the JS');
t_ok(!preg_match("~onsubmit='[^']*'[^']*'~", $CT_apos),
    '…and never produces a handler terminated early by its own apostrophe');

// A screen with no table must not pay for a DOM parse, and must come back identical.
$CT_plain = '<div class="ac-card"><p>No table here. Naira: ₦1,500 — em dash kept.</p></div>';
t_eq(ac_label_table_cells($CT_plain), $CT_plain, 'a screen with no table is returned untouched, byte for byte');

// Non-ASCII must survive: DOMDocument reads a fragment as Latin-1 unless told.
t_ok(str_contains(ac_label_table_cells(
        '<table class="ac-table"><thead><tr><th>Owed</th></tr></thead><tbody><tr><td>₦1,500 · naïve</td></tr></tbody></table>'),
     '₦1,500 · naïve'),
    'UTF-8 survives the round trip — a naira sign must not become mojibake');

/* The shell has to actually call it, or every assertion above is about a
   function nothing runs. */
$CT_shell = (string) file_get_contents(dirname(dirname(__DIR__)) . '/admin/console.php');
t_ok(str_contains($CT_shell, 'ac_label_table_cells('),
    'the console shell runs the labeller over the rendered screen');

// And the stylesheet has to have the rule the labels exist for.
$CT_css = (string) file_get_contents(dirname(dirname(__DIR__)) . '/assets/css/admin-v2.css');
t_ok(str_contains($CT_css, 'content: attr(data-label)'),
    'the phone rule prints the label it is given');
t_ok(preg_match('/@media \(max-width: 720px\)/', $CT_css) === 1,
    'the stacking is confined to a phone — the desktop table is unchanged');
