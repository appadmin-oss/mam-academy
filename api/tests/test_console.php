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

$CN_groups = mam_console_nav();
$CN_flat = [];
foreach ($CN_groups as $g) foreach ($g['items'] as $it) $CN_flat[] = $it;
t_eq(count($CN_flat), count($CN_screens), 'mam_console_screens() flattens the whole rail, losing nothing');
foreach ($CN_flat as $it) {
    t_ok(isset($it['key'], $it['label'], $it['icon']) && array_key_exists('cap', $it),
        'rail entry ' . (string) ($it['key'] ?? '?') . ' carries a key, a label, an icon and a capability');
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
    'access' => fn() => mam_console_access(),
    'governance' => fn() => mam_console_governance(),
    'reports' => fn() => mam_console_reports(),
    'comms' => fn() => mam_console_comms(),
    'vetting' => fn() => mam_console_vetting(),
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
t_eq(preg_match('/\|\|/', preg_replace('#/\*.*?\*/#s', '', $CN_search)), 0,
    'the palette search never uses `||` — it concatenates in SQLite and means OR in MySQL');
t_ok(strpos($CN_search, "ESCAPE '\\\\'") !== false,
    'LIKE patterns declare an escape character, so a query containing % or _ searches for those characters');
