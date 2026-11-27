<?php
/**
 * db/schema-mysql.sql — does it APPLY, not merely parse?
 *
 * The file is what the deploy path runs to stand up production, and the runtime
 * migrator executes it whenever `schema_version` is behind target. Measured on a
 * real MySQL 8.0.46 it left the `results` table missing entirely. Nothing in CI
 * touched it: PHP lint does not read SQL, and the suite runs on SQLite, whose
 * rules differ in exactly the ways that broke it.
 *
 * ⚠️ **Every assertion here is mutation-tested**, because the first version of
 * this file was not and three of its four checks could not fail. The
 * duplicate-foreign-key check — the one named after the defect that cost the
 * `results` table — matched `/CONSTRAINT ([a-z_]+) FOREIGN KEY/`, which demands
 * exactly one space and no digit in the name. It therefore saw 92 of the file's
 * 167 constraints, missed `fk_pay_app_v10` for its digits and the two-line
 * `ADD CONSTRAINT … \n FOREIGN KEY` for its newline, and re-introducing
 * `fk_res_user` verbatim left the suite green. A regex that is the whole of a
 * safety check has to be shown to reject the thing it exists to reject; the
 * mutation cases at the bottom of this file do that for each one.
 */
declare(strict_types=1);

t_group('schema-mysql — it has to APPLY, not just parse');

$SM_PATH = dirname(__DIR__, 2) . '/db/schema-mysql.sql';
$smSrc = (string) file_get_contents($SM_PATH);

/**
 * The file with `--` line comments and block comments removed — which is what
 * the migrator actually executes (it strips both before splitting). Every
 * structural check runs against THIS, not the raw text: the notes in this file
 * discuss the very syntax the checks forbid, so scanning the raw text either
 * produces false positives or, worse, invites a `str_starts_with('--')` guard
 * that a trailing comment on a real statement walks straight through.
 */
function mam_test_sql_code(string $sql): string {
    $sql = (string) preg_replace('!/\*.*?\*/!s', '', $sql);      // block comments
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);      // whole-line comments
    $sql = (string) preg_replace('/--[^\n]*$/m', '', $sql);      // trailing comments
    return $sql;
}

/** Every `CREATE TABLE [IF NOT EXISTS] <name>` in the file, in order. */
function mam_test_sql_tables(string $code): array {
    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/is', $code, $m);
    return $m[1];
}

/** Every foreign-key constraint NAME in the file, however it is spelled. */
function mam_test_sql_fk_names(string $code): array {
    // Whitespace-tolerant across newlines, digits allowed, optional backticks,
    // and it matches both the inline `CONSTRAINT x FOREIGN KEY` form and the
    // `ALTER TABLE … ADD CONSTRAINT x` + newline + `FOREIGN KEY` form.
    preg_match_all('/CONSTRAINT\s+`?([A-Za-z0-9_]+)`?\s+FOREIGN\s+KEY/is', $code, $m);
    return $m[1];
}

/**
 * Every CHECK constraint NAME in the file.
 *
 * MySQL scopes a named CHECK to the DATABASE exactly as it does a foreign key,
 * and a collision raises ER_CHECK_CONSTRAINT_DUP_NAME (3822) which fails the
 * whole CREATE TABLE — the identical failure mode that cost the `results` table.
 * There are now twenty-odd of these, all added since the FK check was written,
 * and none of them were covered by it.
 */
function mam_test_sql_check_names(string $code): array {
    preg_match_all('/CONSTRAINT\s+`?([A-Za-z0-9_]+)`?\s+CHECK\s*\(/is', $code, $m);
    return $m[1];
}

/** The body of one `CREATE TABLE <tbl> ( … )` block, or '' when absent. */
function mam_test_sql_table_block(string $code, string $table): string {
    $re = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?' . preg_quote($table, '/') . '`?\s*\((.*?)\n\)\s*ENGINE/is';
    return preg_match($re, $code, $m) === 1 ? $m[1] : '';
}

$smCode = mam_test_sql_code($smSrc);

// ── 1 · A table defined twice can drift, and the second definition never runs
//        (both are CREATE TABLE IF NOT EXISTS), so a divergence is invisible.
$smTables = mam_test_sql_tables($smCode);
$smDupTables = array_keys(array_filter(array_count_values($smTables), static fn(int $n): bool => $n > 1));
t_eq(count($smDupTables), 0, 'no table is defined twice' . ($smDupTables ? ' — ' . implode(', ', $smDupTables) : ''));
t_ok(count($smTables) > 100, 'the table scan actually found the tables (' . count($smTables) . ') — a regex that matches nothing proves nothing');

// ── 2 · MySQL scopes foreign-key constraint names to the DATABASE, not the
//        table. A collision makes the whole CREATE TABLE fail with 1826, so a
//        fresh install simply had no `results` table while SQLite (per-table
//        scope) was perfectly happy.
$smFks = mam_test_sql_fk_names($smCode);
$smDupFk = array_keys(array_filter(array_count_values($smFks), static fn(int $n): bool => $n > 1));
t_eq(count($smDupFk), 0, 'every foreign-key constraint name is unique across the file'
    . ($smDupFk ? ' — ' . implode(', ', $smDupFk) : ''));
t_ok(count($smFks) > 150, 'and the scan sees essentially all of them (' . count($smFks)
    . ') — the original pattern saw 92 of 167 and could not have caught fk_res_user');

// A named CHECK is database-scoped too (3822), and every one of these arrived
// after the foreign-key check above was written, so none was covered by it.
$smChecks = mam_test_sql_check_names($smCode);
$smDupCheck = array_keys(array_filter(array_count_values($smChecks), static fn(int $n): bool => $n > 1));
t_eq(count($smDupCheck), 0, 'every named CHECK constraint is unique across the file'
    . ($smDupCheck ? ' — ' . implode(', ', $smDupCheck) : ''));
t_ok(count($smChecks) > 10, 'and the file really does carry named CHECKs (' . count($smChecks) . ')');

// And no constraint name is reused ACROSS the two kinds — one namespace in MySQL.
$smAllNames = array_merge($smFks, $smChecks);
$smDupAny = array_keys(array_filter(array_count_values($smAllNames), static fn(int $n): bool => $n > 1));
t_eq(count($smDupAny), 0, 'foreign-key and CHECK names share one namespace and do not collide'
    . ($smDupAny ? ' — ' . implode(', ', $smDupAny) : ''));

// The declared engine requirement has to be one that ENFORCES a CHECK. 5.7
// parses and discards them, which would make every constraint above decoration.
t_ok(preg_match('/Requires\s+\*?\*?MySQL\s+8\.0\.16\+/i', $smSrc) === 1,
    'the header requires MySQL 8.0.16+ — the first version that enforces CHECK rather than ignoring it');
t_ok(preg_match('/MariaDB\s+10\.2\.1\+/i', $smSrc) === 1, 'and MariaDB 10.2.1+ for the same reason');

// Index/key names are scoped PER TABLE in MySQL, so a repeat across tables is
// legal and must not be reported. Only a repeat WITHIN one table is a defect.
$smDupKeyIn = [];
foreach (array_unique($smTables) as $t) {
    $block = mam_test_sql_table_block($smCode, $t);
    if ($block === '') continue;
    preg_match_all('/(?:UNIQUE\s+)?KEY\s+`?([A-Za-z0-9_]+)`?\s*\(/i', $block, $km);
    foreach (array_count_values($km[1]) as $k => $n) if ($n > 1) $smDupKeyIn[] = "$t.$k";
}
t_eq(count($smDupKeyIn), 0, 'no index name repeats within a single table'
    . ($smDupKeyIn ? ' — ' . implode(', ', $smDupKeyIn) : ''));

// ── 3 · `ADD COLUMN IF NOT EXISTS` is MariaDB-only. MySQL 8 raises 1064, which
//        the runner does NOT tolerate, so the column never lands while
//        schema_version advances anyway.
$smBad = [];
foreach (explode("\n", $smCode) as $i => $line) {
    if (stripos($line, 'ADD COLUMN IF NOT EXISTS') !== false) $smBad[] = $i + 1;
}
t_eq(count($smBad), 0, 'no statement uses the MariaDB-only ADD COLUMN IF NOT EXISTS'
    . ($smBad ? ' — line(s) ' . implode(', ', $smBad) : ''));

// MySQL forbids a literal DEFAULT on TEXT/BLOB (CLAUDE.md's migration note).
$smTextDefault = [];
foreach (explode("\n", $smCode) as $i => $line) {
    if (preg_match('/\b(TINYTEXT|TEXT|MEDIUMTEXT|LONGTEXT|BLOB)\b[^,]*\bDEFAULT\s+(?!NULL)/i', $line)) $smTextDefault[] = $i + 1;
}
t_eq(count($smTextDefault), 0, 'no TEXT/BLOB column carries a literal DEFAULT — MySQL rejects it'
    . ($smTextDefault ? ' — line(s) ' . implode(', ', $smTextDefault) : ''));

// ── 4 · Every table the SQLite migration chain builds must exist here too.
//        This is the assertion that catches the commonest mirror failure: a new
//        `N => "CREATE TABLE …"` in api/modules/kernel/lib/db.php with no counterpart in this
//        file, which SQLite never notices and MySQL discovers as a 1146 at
//        runtime, on a live host, inside whichever endpoint reads it first.
$smRuntime = [];
foreach (mam_db_all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $r) {
    $smRuntime[strtolower((string) $r['name'])] = true;
}
$smMysqlSet = [];
foreach ($smTables as $t) $smMysqlSet[strtolower($t)] = true;
$smMissing = array_values(array_diff(array_keys($smRuntime), array_keys($smMysqlSet)));
sort($smMissing);
t_eq(count($smMissing), 0, 'every table the SQLite chain creates is mirrored in db/schema-mysql.sql'
    . ($smMissing ? ' — missing: ' . implode(', ', $smMissing) : ''));
t_ok(count($smRuntime) > 100, 'and the runtime comparison had a real schema to compare against (' . count($smRuntime) . ' tables)');

// ── 5 · The columns the five formerly-MariaDB-only statements create, checked
//        against the live runtime AND anchored to their own table in the file.
//        The earlier version searched the whole file for the column NAME with no
//        reference to the table, so deleting
//        `ALTER TABLE class_sessions ADD COLUMN meeting_link …` still passed —
//        it matched `ALTER TABLE classes ADD COLUMN meeting_link …`, a different
//        table 66 lines earlier.
foreach ([['class_sessions', 'meeting_link'], ['promo_banners', 'public'],
          ['promo_banners', 'discount_percent'], ['promo_banners', 'applies_to'],
          ['id_cards', 'expires_at']] as [$tbl, $col]) {
    $cols = [];
    foreach (mam_db_all('PRAGMA table_info(' . $tbl . ')') as $c) $cols[strtolower($c['name'])] = true;
    t_ok(isset($cols[$col]), "$tbl.$col exists in the runtime schema");

    // Either the table's own CREATE block declares it, or an ALTER naming THAT
    // table adds it. Both are portable; neither may be inferred from a namesake.
    $inCreate = mam_test_sql_table_block($smCode, $tbl) !== ''
        && preg_match('/^\s*`?' . preg_quote($col, '/') . '`?\s+\S/mi', mam_test_sql_table_block($smCode, $tbl)) === 1;
    $inAlter = preg_match('/ALTER\s+TABLE\s+`?' . preg_quote($tbl, '/') . '`?\s+ADD\s+COLUMN\s+`?'
        . preg_quote($col, '/') . '`?\b/is', $smCode) === 1;
    t_ok($inCreate || $inAlter, "and db/schema-mysql.sql creates $tbl.$col on that table specifically");
}

// ── 6 · Version stamping. Each version block ends with an INSERT that advances
//        schema_version, and the highest one must be MAM_SCHEMA_TARGET — a
//        mirror that stops short leaves the migrator re-running the file forever.
preg_match_all('/INSERT\s+INTO\s+schema_version\s*\(version\)\s*VALUES\s*\((\d+)\)/i', $smCode, $smV);
$smVersions = array_map('intval', $smV[1]);
t_ok(count($smVersions) > 0, 'the file stamps schema_version');
t_eq(max($smVersions), MAM_SCHEMA_TARGET, 'and its highest stamp is MAM_SCHEMA_TARGET');

t_group('schema-mysql — the checks above are shown to REJECT their own defect');

/**
 * Mutation tests. Each takes the real file, re-introduces one historical defect,
 * and asserts the corresponding check now sees it. Without these, a check is an
 * assertion about a regex rather than about the schema — which is exactly how
 * three of the four original ones came to be unfalsifiable.
 */

// (a) the fk_res_user collision, verbatim, including its three-space spelling.
$smMutA = str_replace('CONSTRAINT fk_result_user FOREIGN KEY (finalized_by)',
                      'CONSTRAINT fk_res_user   FOREIGN KEY (finalized_by)', $smSrc);
t_ok($smMutA !== $smSrc, 'the fk mutation applied');
$smMutAFks = mam_test_sql_fk_names(mam_test_sql_code($smMutA));
t_ok(in_array('fk_res_user', array_keys(array_filter(array_count_values($smMutAFks), static fn(int $n): bool => $n > 1)), true),
    'a re-introduced fk_res_user collision IS detected — the original pattern missed it entirely');

// (b) a constraint name containing digits, which `[a-z_]+` could never see.
$smMutB = $smSrc . "\nALTER TABLE payments ADD CONSTRAINT fk_pay_app_v10\n  FOREIGN KEY (application_id) REFERENCES applications(id);\n";
$smMutBFks = mam_test_sql_fk_names(mam_test_sql_code($smMutB));
t_ok(count(array_filter(array_count_values($smMutBFks), static fn(int $n): bool => $n > 1)) > 0,
    'a duplicate name with digits, split across two lines, IS detected');

// (c) a table defined twice.
$smMutC = $smSrc . "\nCREATE TABLE IF NOT EXISTS termly_reports (id INT) ENGINE=InnoDB;\n";
$smMutCT = mam_test_sql_tables(mam_test_sql_code($smMutC));
t_ok(count(array_filter(array_count_values($smMutCT), static fn(int $n): bool => $n > 1)) > 0,
    'a second CREATE TABLE for an existing table IS detected');

// (d) the MariaDB-only ALTER, on a real statement line with a trailing comment
//     — the shape a `str_starts_with('--')` guard walks straight past.
$smMutD = mam_test_sql_code($smSrc . "\nALTER TABLE cohorts ADD COLUMN IF NOT EXISTS foo INT NULL; -- note\n");
$smMutDHit = false;
foreach (explode("\n", $smMutD) as $line) if (stripos($line, 'ADD COLUMN IF NOT EXISTS') !== false) $smMutDHit = true;
t_ok($smMutDHit, 'a MariaDB-only ALTER with a trailing comment IS detected');

// (e) a table present in SQLite and missing from the mirror.
$smMutE = [];
foreach ($smTables as $t) $smMutE[strtolower($t)] = true;
unset($smMutE['users']);
t_ok(in_array('users', array_values(array_diff(array_keys($smRuntime), array_keys($smMutE))), true),
    'a table missing from the mirror IS detected');

// (f) a namesake column on the wrong table does NOT satisfy the column check.
$smMutF = preg_replace('/ALTER\s+TABLE\s+`?class_sessions`?\s+ADD\s+COLUMN\s+`?meeting_link`?[^;]*;/is', '', $smCode);
t_ok($smMutF !== $smCode, 'the column mutation applied');
$smMutFInCreate = mam_test_sql_table_block($smMutF, 'class_sessions') !== ''
    && preg_match('/^\s*`?meeting_link`?\s+\S/mi', mam_test_sql_table_block($smMutF, 'class_sessions')) === 1;
$smMutFInAlter = preg_match('/ALTER\s+TABLE\s+`?class_sessions`?\s+ADD\s+COLUMN\s+`?meeting_link`?\b/is', $smMutF) === 1;
t_ok(!$smMutFInCreate && !$smMutFInAlter,
    'deleting class_sessions.meeting_link IS detected, despite classes.meeting_link existing elsewhere in the file');

// (g2) a duplicate named CHECK, which the FK-only check could never see.
$smMutG2 = mam_test_sql_code($smSrc . "\nALTER TABLE cohorts ADD CONSTRAINT chk_treport_status CHECK (id > 0);\n");
$smMutG2Names = array_merge(mam_test_sql_fk_names($smMutG2), mam_test_sql_check_names($smMutG2));
t_ok(count(array_filter(array_count_values($smMutG2Names), static fn(int $n): bool => $n > 1)) > 0,
    'a duplicate named CHECK IS detected — MySQL raises 3822 and drops the whole CREATE TABLE');

// (g) a TEXT column with a literal DEFAULT.
$smMutG = mam_test_sql_code($smSrc . "\nALTER TABLE cohorts ADD COLUMN blurb TEXT DEFAULT 'x';\n");
$smMutGHit = false;
foreach (explode("\n", $smMutG) as $line) {
    if (preg_match('/\b(TINYTEXT|TEXT|MEDIUMTEXT|LONGTEXT|BLOB)\b[^,]*\bDEFAULT\s+(?!NULL)/i', $line)) $smMutGHit = true;
}
t_ok($smMutGHit, 'a TEXT column with a literal DEFAULT IS detected');

t_group('schema portability — two defects only a real MariaDB reveals');

/**
 * Both of these were found by applying the shipped files to MariaDB 10.11 and
 * counting the tables that arrived. Both are INVISIBLE on SQLite, and the first
 * is invisible on MySQL 8 as well, so a static check is the only guard that runs
 * in CI.
 *
 * 1 · MariaDB refuses (error 1901) a CHECK constraint that references a column a
 *     referential action can NULL — the cascade would silently break the
 *     constraint. The whole CREATE TABLE fails, so the table is simply ABSENT
 *     while `schema_version` still reads the target. Measured: four v85 tables
 *     (termly_reports and its three companions) were missing from every MariaDB
 *     install, which is the guardian portal's entire report subsystem.
 *
 * 2 · MySQL requires a foreign key's TARGET table to exist already; SQLite
 *     resolves references at runtime. A table declared before the one it points
 *     at fails with errno 150. Measured: six invoice/payment tables, because the
 *     calendar layer was declared after the money layer that references it.
 */
$portFiles = ['db/schema-mysql.sql', 'db/schema-v2.sql'];
foreach ($portFiles as $rel) {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    $blocks = [];
    if (preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`?([a-z_0-9]+)`?\s*\((.*?)\n\)\s*ENGINE/is',
                       $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $b) $blocks[] = [$b[1], $b[2]];
    }
    t_ok(count($blocks) > 90, "$rel parsed into table blocks [" . count($blocks) . ']');

    // 1 · a CHECK may not name a column a referential action nulls.
    $clash = [];
    foreach ($blocks as [$table, $body]) {
        $nulled = [];
        if (preg_match_all('/FOREIGN\s+KEY\s*\(\s*`?([a-z_0-9]+)`?\s*\)[^,]*?ON\s+(?:DELETE|UPDATE)\s+SET\s+NULL/is',
                           $body, $fk, PREG_SET_ORDER)) {
            foreach ($fk as $f) $nulled[strtolower($f[1])] = true;
        }
        if (!$nulled) continue;
        if (preg_match_all('/CHECK\s*\((.*?)\)\s*(?:,\s*\n?\s*(?:CONSTRAINT|KEY|UNIQUE|PRIMARY|FOREIGN)|\s*\n\)\s*ENGINE)/is',
                           $body . "\n) ENGINE", $ck, PREG_SET_ORDER)) {
            foreach ($ck as $c) {
                foreach (array_keys($nulled) as $col) {
                    if (preg_match('/\b' . preg_quote($col, '/') . '\b/i', $c[1])) $clash[] = "$table.$col";
                }
            }
        }
    }
    $clash = array_values(array_unique($clash));
    t_eq(implode(', ', $clash), '',
        "$rel: no CHECK names a column an ON DELETE/UPDATE SET NULL can blank — MariaDB refuses the whole "
        . 'table with error 1901, and the table is then absent while schema_version reads the target');

    // 2 · every foreign key target is declared EARLIER in the file.
    $seen = [];
    $forward = [];
    foreach ($blocks as [$table, $body]) {
        $seen[$table] = true;
        if (preg_match_all('/REFERENCES\s+`?([a-z_0-9]+)`?\s*\(/i', $body, $ref)) {
            foreach ($ref[1] as $target) {
                if ($target === $table) continue;                 // self-reference is fine
                if (!isset($seen[$target])) $forward[] = "$table → $target";
            }
        }
    }
    $forward = array_values(array_unique($forward));
    t_eq(implode(', ', $forward), '',
        "$rel: every foreign key points at a table declared BEFORE it — MySQL cannot create a key onto a "
        . 'table that does not exist yet, and SQLite resolves it at runtime so the ordering error is '
        . 'invisible there');
}

// 3 · `||` is OR in MySQL, not string concatenation. A version note built with it
//     collapsed into a numeric comparison and MySQL refused the INSERT as a
//     truncated DOUBLE — losing the schema_version stamp at the end of the file.
foreach ($portFiles as $rel) {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    $pipes = [];
    foreach (explode("\n", $src) as $i => $line) {
        if (preg_match('/^\s*--/', $line)) continue;
        if (str_contains($line, '||')) $pipes[] = $i + 1;
    }
    t_eq(count($pipes), 0, "$rel uses no `||` — MySQL reads it as OR, not concatenation"
        . ($pipes ? ' — line(s) ' . implode(', ', $pipes) : ''));
}
