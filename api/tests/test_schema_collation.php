<?php
/**
 * ONE collation for every MySQL table (schema v105).
 *
 * Half of db/schema-mysql.sql declared utf8mb4_unicode_ci and half declared
 * nothing, taking the server's default (utf8mb4_general_ci on MariaDB,
 * utf8mb4_0900_ai_ci on MySQL 8). A comparison of text columns across the two
 * halves is error 1267, which the console's reads catch and draw as an EMPTY
 * panel: the invoice's online payments — and the refund the office owes on
 * one — showed nothing on every MySQL install while SQLite stayed green.
 *
 * The static half runs on both engines, so a new table that forgets its
 * collation fails the ordinary build, not only the MariaDB job.
 */
declare(strict_types=1);

t_group('schema — every MySQL table names one collation');

$SC_sql = (string) file_get_contents(dirname(__DIR__, 2) . '/db/schema-mysql.sql');
preg_match_all('/^CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?\s*\(/m', $SC_sql, $SC_m, PREG_OFFSET_CAPTURE);
$SC_bad = [];
foreach ($SC_m[1] as $i => [$name, $off]) {
    $next = $SC_m[0][$i + 1][1] ?? strlen($SC_sql);
    // The statement's OWN ending, never the next table's: matching across a
    // statement boundary is how the first draft of the repair missed tables.
    if (!preg_match('/\n\)( ENGINE=[^\n]*)?;/', $SC_sql, $e, PREG_OFFSET_CAPTURE, $off) || $e[0][1] > $next) {
        $SC_bad[] = $name . ' (no ending found)';
        continue;
    }
    if (!str_contains($e[0][0], 'COLLATE=utf8mb4_unicode_ci')) $SC_bad[] = $name;
}
t_ok(count($SC_m[1]) > 100, 'the schema file was read (' . count($SC_m[1]) . ' tables)');
t_eq($SC_bad, [], 'every CREATE TABLE ends with COLLATE=utf8mb4_unicode_ci — a table that names none takes the server\'s default and cannot be compared with the rest');
t_ok(str_contains($SC_sql, 'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci') && str_contains($SC_sql, 'VALUES (105)'),
    'and the tables that already exist are converted by a repair, not only fresh ones');

if (mam_db_driver() === 'mysql') {
    $SC_coll = mam_db_all("SELECT table_collation c, COUNT(*) n FROM information_schema.tables
                            WHERE table_schema = DATABASE() GROUP BY table_collation");
    t_eq(array_column($SC_coll, 'c'), ['utf8mb4_unicode_ci'], 'the live database has one table collation');
    $SC_cols = mam_db_all("SELECT DISTINCT collation_name c FROM information_schema.columns
                            WHERE table_schema = DATABASE() AND collation_name IS NOT NULL
                              AND collation_name NOT IN ('utf8mb4_unicode_ci','utf8mb4_bin')");
    t_eq(array_column($SC_cols, 'c'), [], 'and no column outside it (binary columns are declared on purpose)');
}
