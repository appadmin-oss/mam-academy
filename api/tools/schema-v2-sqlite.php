<?php
/**
 * Generate db/schema-v2-sqlite.sql from db/schema-v2.sql.
 * ─────────────────────────────────────────────────────────────
 * The MySQL file is CANONICAL. The SQLite mirror is generated, never
 * hand-edited, because the v1 schema drifted exactly that way: someone added a
 * migration to `api/lib/db.php` and forgot `db/schema-mysql.sql`, and the two
 * engines then disagreed about what the schema was.
 *
 *   php api/tools/schema-v2-sqlite.php            # write the mirror
 *   php api/tools/schema-v2-sqlite.php --check    # exit 1 if it is stale
 *
 * `--check` is what CI and test_schema_v2.php run: a mirror that is out of date
 * is a failure, not a warning.
 *
 * What the translation does, and why each rule is here:
 *  • Inline `KEY name (cols)` becomes a real `CREATE INDEX` statement rather
 *    than being dropped. An index is a performance contract; a mirror that
 *    quietly has none would let a query that is fine on MySQL be a table scan
 *    in dev, which is the wrong way round for catching problems.
 *  • `UNIQUE KEY name (cols)` becomes a table-level `UNIQUE (cols)`, keeping the
 *    constraint (SQLite has no named table constraints of that form).
 *  • `AUTO_INCREMENT PRIMARY KEY` becomes `INTEGER PRIMARY KEY AUTOINCREMENT` —
 *    the only spelling SQLite treats as a rowid alias with a monotonic counter,
 *    which matters because v2 public ids are ULIDs and internal ids must never
 *    be reused after a delete.
 *  • `ON UPDATE CURRENT_TIMESTAMP(3)` is dropped: SQLite has no such clause, so
 *    `updated_at` is the application's job there. Stated here rather than
 *    discovered later by a stale timestamp.
 *  • CHECK constraints, foreign keys and defaults pass through unchanged. They
 *    are the part of the schema that enforces correctness, so they must be
 *    identical on both engines or dev stops being a rehearsal for production.
 */
declare(strict_types=1);

const MAM_V2_MYSQL  = __DIR__ . '/../../db/schema-v2.sql';
const MAM_V2_SQLITE = __DIR__ . '/../../db/schema-v2-sqlite.sql';

/**
 * Translate the canonical MySQL DDL into the SQLite mirror.
 * Pure: takes the source text, returns the mirror text. Testable without IO.
 */
function mam_v2_to_sqlite(string $mysql): string
{
    $src = $mysql;

    // Session pragmas are MySQL-only and mean nothing here.
    $src = preg_replace('/^SET .*?;\s*$/m', '', $src) ?? $src;

    // Collect the secondary indexes before they are stripped, so they can be
    // re-emitted as real CREATE INDEX statements at the end.
    $indexes = [];
    $src = preg_replace_callback(
        '/CREATE TABLE\s+(\w+)\s*\((.*?)\n\)\s*ENGINE=[^;]*;/s',
        function (array $m) use (&$indexes): string {
            $table = $m[1];
            $body  = $m[2];

            $body = preg_replace_callback(
                '/^\s*KEY\s+(\w+)\s*\(([^)]*)\),?\s*$/m',
                function (array $k) use (&$indexes, $table): string {
                    $indexes[] = 'CREATE INDEX ' . $k[1] . ' ON ' . $table . ' (' . trim($k[2]) . ');';
                    return '';                       // removed from the table body
                },
                $body
            ) ?? $body;

            return "CREATE TABLE $table ($body\n);";
        },
        $src
    ) ?? $src;

    $rules = [
        // Keys / auto-increment.
        '/BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY/' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        '/^\s*UNIQUE KEY\s+\w+\s*\(/m'                 => '  UNIQUE (',

        // Types. Order matters: the widest spellings first.
        '/BIGINT UNSIGNED/'    => 'INTEGER',
        '/INT UNSIGNED/'       => 'INTEGER',
        '/SMALLINT UNSIGNED/'  => 'INTEGER',
        '/TINYINT UNSIGNED/'   => 'INTEGER',
        '/\bBIGINT\b/'         => 'INTEGER',
        '/\bSMALLINT\b/'       => 'INTEGER',
        '/TINYINT\(1\)/'       => 'INTEGER',
        '/\bTINYINT\b/'        => 'INTEGER',
        '/\bINT\b/'            => 'INTEGER',
        '/DATETIME\(3\)/'      => 'TEXT',
        '/\bDATETIME\b/'       => 'TEXT',
        '/\bDATE\b/'           => 'TEXT',
        '/VARCHAR\(\d+\)/'     => 'TEXT',
        '/CHAR\(\d+\)/'        => 'TEXT',
        '/VARBINARY\(\d+\)/'   => 'BLOB',
        '/DECIMAL\(\d+,\s*\d+\)/' => 'REAL',
        '/\bJSON\b/'           => 'TEXT',
        '/\bLONGTEXT\b/'       => 'TEXT',

        // Timestamps. Millisecond precision is kept so the two engines sort
        // identically on created_at.
        '/\s*ON UPDATE CURRENT_TIMESTAMP\(3\)/' => '',
        '/CURRENT_TIMESTAMP\(3\)/'              => "(strftime('%Y-%m-%d %H:%M:%f','now'))",
    ];
    foreach ($rules as $re => $to) $src = preg_replace($re, $to, $src) ?? $src;

    // Removing an inline KEY can leave a dangling comma before the closing
    // paren, which is a syntax error rather than a cosmetic problem.
    $src = preg_replace('/,(\s*)\n\s*\);/', "$1\n);", $src) ?? $src;
    $src = preg_replace('/\n{3,}/', "\n\n", $src) ?? $src;

    $header = "-- =============================================================================\n"
        . "-- MAM Platform — schema-v2, SQLite mirror\n"
        . "-- =============================================================================\n"
        . "-- GENERATED FILE — do not edit.\n"
        . "--   source:     db/schema-v2.sql   (canonical, MySQL 8)\n"
        . "--   regenerate: php api/tools/schema-v2-sqlite.php\n"
        . "--   verify:     php api/tools/schema-v2-sqlite.php --check\n"
        . "--\n"
        . "-- Differences from the canonical file are mechanical only: MySQL types map\n"
        . "-- to SQLite affinities, inline KEYs become CREATE INDEX statements, and\n"
        . "-- ON UPDATE CURRENT_TIMESTAMP is dropped (SQLite has no equivalent, so\n"
        . "-- updated_at is maintained by the application on this engine).\n"
        . "--\n"
        . "-- Constraints — CHECKs, foreign keys, uniqueness — are IDENTICAL by design.\n"
        . "-- They are what makes dev a rehearsal for production rather than a\n"
        . "-- looser environment that lets bad rows through.\n"
        . "-- =============================================================================\n\n"
        . "PRAGMA foreign_keys = ON;\n";

    $out = $header . rtrim($src) . "\n";
    if ($indexes) $out .= "\n-- Secondary indexes (inline KEY clauses in the canonical file).\n"
        . implode("\n", $indexes) . "\n";

    return $out;
}

/* ── CLI ──────────────────────────────────────────────────────────────────── */

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $mysql = file_get_contents(MAM_V2_MYSQL);
    if ($mysql === false) { fwrite(STDERR, "cannot read " . MAM_V2_MYSQL . "\n"); exit(1); }
    $want = mam_v2_to_sqlite($mysql);

    if (in_array('--check', $argv, true)) {
        $have = is_file(MAM_V2_SQLITE) ? (string) file_get_contents(MAM_V2_SQLITE) : '';
        if ($have === $want) { echo "db/schema-v2-sqlite.sql is up to date\n"; exit(0); }
        fwrite(STDERR, "db/schema-v2-sqlite.sql is STALE — run: php api/tools/schema-v2-sqlite.php\n");
        exit(1);
    }

    file_put_contents(MAM_V2_SQLITE, $want);
    echo "wrote db/schema-v2-sqlite.sql (" . number_format(strlen($want)) . " bytes)\n";
}
