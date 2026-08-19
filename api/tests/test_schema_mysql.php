<?php
/**
 * db/schema-mysql.sql — does it APPLY, not merely parse?
 *
 * The file is the documented way to stand up production ("apply
 * db/schema-mysql.sql once"). Measured on a real MySQL 8.0.46 it left 112 of 113
 * tables and silently skipped five columns. Nothing in CI touched it: PHP lint
 * does not read SQL, and the test suite runs on SQLite, whose rules differ in
 * exactly the three ways that broke it.
 */
declare(strict_types=1);

t_group('schema-mysql — it has to APPLY, not just parse');

// `db/schema-mysql.sql` is the documented way to stand up production
// ("apply db/schema-mysql.sql once"). Measured on a real MySQL 8.0.46 it left
// 112 of 113 tables and silently skipped five columns. Three distinct causes,
// each checked here because none of them is visible by reading the file.

$smSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/db/schema-mysql.sql');

// 1 · A table defined twice can drift, and the second definition never runs
//     (both are CREATE TABLE IF NOT EXISTS), so a divergence would be invisible.
preg_match_all('/CREATE TABLE IF NOT EXISTS ([a-z_]+)/', $smSrc, $smT);
$smDupTables = array_keys(array_filter(array_count_values($smT[1]), static fn(int $n): bool => $n > 1));
t_eq(count($smDupTables), 0, 'no table is defined twice' . ($smDupTables ? ' — ' . implode(', ', $smDupTables) : ''));

// 2 · MySQL scopes foreign-key constraint names to the DATABASE, not the table.
//     A collision makes the whole CREATE TABLE fail — `fk_res_user` was on both
//     class_resources and results, so a fresh MySQL install had no `results`
//     table at all while SQLite (per-table scope) was perfectly happy.
preg_match_all('/CONSTRAINT ([a-z_]+) FOREIGN KEY/', $smSrc, $smC);
$smDupFk = array_keys(array_filter(array_count_values($smC[1]), static fn(int $n): bool => $n > 1));
t_eq(count($smDupFk), 0, 'every foreign-key constraint name is unique across the file'
    . ($smDupFk ? ' — ' . implode(', ', $smDupFk) : ''));

// 3 · `ADD COLUMN IF NOT EXISTS` is MariaDB-only. MySQL 8 raises 1064, which the
//     runner does not tolerate, so the column never lands while schema_version
//     advances anyway. The file's own v46 note says this; five statements added
//     afterwards used it regardless.
$smBad = [];
foreach (explode("\n", $smSrc) as $i => $line) {
    if (str_starts_with(ltrim($line), '--')) continue;          // the notes may name it
    if (stripos($line, 'ADD COLUMN IF NOT EXISTS') !== false) $smBad[] = $i + 1;
}
t_eq(count($smBad), 0, 'no statement uses the MariaDB-only ADD COLUMN IF NOT EXISTS'
    . ($smBad ? ' — line(s) ' . implode(', ', $smBad) : ''));

// 4 · The columns those five statements were supposed to create, checked against
//     the live runtime rather than the file, so a future rewrite cannot lose them.
foreach ([['class_sessions', 'meeting_link'], ['promo_banners', 'public'],
          ['promo_banners', 'discount_percent'], ['promo_banners', 'applies_to'],
          ['id_cards', 'expires_at']] as [$tbl, $col]) {
    $cols = [];
    foreach (mam_db_all('PRAGMA table_info(' . $tbl . ')') as $c) $cols[strtolower($c['name'])] = true;
    t_ok(isset($cols[$col]), "$tbl.$col exists in the runtime schema");
    // And the canonical file must be able to create it on MySQL too.
    t_ok(preg_match('/ADD COLUMN ' . preg_quote($col, '/') . '\b/', $smSrc) === 1
         || preg_match('/^\s+`?' . preg_quote($col, '/') . '`?\s+[A-Z]/m', $smSrc) === 1,
        "and db/schema-mysql.sql creates $tbl.$col portably");
}
