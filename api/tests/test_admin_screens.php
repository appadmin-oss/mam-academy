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
t_ok(count($asDefLine) >= 5, 'the file defines several admin helpers [' . count($asDefLine) . ']');

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
t_ok(($asDefLine['mam_admin_instr_metric'] ?? PHP_INT_MAX) < $asFirstBranch,
    'mam_admin_instr_metric specifically is top-level, so ?v=upper and ?v=academic both work');

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
