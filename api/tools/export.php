<?php
/**
 * MAM Academy — ETL Layer 1: export.php
 * ─────────────────────────────────────────────────────────────
 * A lossless, typed, checksummed snapshot of the CURRENT database.
 *
 * This is the first thing built and the first thing run, because it is the
 * rollback artifact and the audit evidence for everything that follows. It
 * makes ZERO assumptions about schema-v2: it captures what is there, exactly as
 * it is, so the snapshot stays valid however the transform later changes.
 *
 *   php api/tools/export.php                      # snapshot to export/<ts>/
 *   php api/tools/export.php --verify-roundtrip   # snapshot, then prove it
 *   php api/tools/export.php --out=/path/to/dir   # explicit destination
 *   php api/tools/export.php --tables=users,payments
 *   php api/tools/export.php --verify-only=DIR    # check an EXISTING snapshot
 *
 * `--verify-only` exists because the snapshot is a rollback artifact: before
 * trusting one to restore from — possibly months later, on a different machine,
 * with no live database to re-export from — you must be able to verify the
 * files you were handed. It re-imports them against their own manifest and
 * touches no database but a scratch one.
 *
 * Exit codes: 0 success · 1 export failed · 2 round-trip mismatch (the
 * interesting one — it means the snapshot is NOT lossless and nothing
 * downstream may proceed).
 *
 * ── Why the checksum is order-independent ──
 * A snapshot is lossless if it holds the same SET of rows, not the same
 * sequence. Row order can legitimately differ between a source table and a
 * re-imported copy (no ORDER BY guarantee without a key, and rowids can shift),
 * so hashing the concatenated dump would produce false failures. Instead each
 * row is canonically encoded and hashed, the row-hashes are sorted, and the
 * digest is taken over that. Identical content therefore always yields an
 * identical checksum, and any changed/missing/extra row changes it.
 *
 * ── Read-only ──
 * Nothing here writes to the source database. Safe to run against production at
 * any time, and idempotent: two runs of the same data produce the same
 * checksums (only the timestamped directory name differs).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../lib/bootstrap.php';

/* ── arguments ─────────────────────────────────────────────────────────── */

$opts = [
    'out'        => null,
    'roundtrip'  => false,
    'pretty'     => false,
    'tables'     => [],
    'quiet'      => false,
    'verifyOnly' => null,
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--verify-roundtrip') { $opts['roundtrip'] = true; continue; }
    if ($arg === '--pretty')           { $opts['pretty']    = true; continue; }
    if ($arg === '--quiet')            { $opts['quiet']     = true; continue; }
    if (str_starts_with($arg, '--out='))    { $opts['out'] = substr($arg, 6); continue; }
    if (str_starts_with($arg, '--verify-only=')) { $opts['verifyOnly'] = rtrim(substr($arg, 14), '/'); continue; }
    if (str_starts_with($arg, '--tables=')) {
        $opts['tables'] = array_values(array_filter(array_map('trim', explode(',', substr($arg, 9)))));
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php api/tools/export.php [--out=DIR] [--tables=a,b] [--verify-roundtrip] [--verify-only=DIR] [--pretty] [--quiet]\n");
        exit(0);
    }
    fwrite(STDERR, "Unknown argument: $arg\n");
    exit(1);
}

$say = function (string $line) use ($opts) { if (!$opts['quiet']) fwrite(STDOUT, $line . "\n"); };

/* ── helpers ───────────────────────────────────────────────────────────── */

/** Every user table in the current database, alphabetically. */
function mam_export_tables(PDO $db): array {
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $sql = "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
    } else {
        $sql = "SELECT table_name AS name FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name";
    }
    return array_map(fn($r) => (string) $r['name'], $db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Canonical encoding of one row: keys sorted, JSON with no float munging.
 * Sorting the keys means a column-order change in the source cannot alter the
 * checksum, which keeps the digest about DATA rather than about DDL.
 */
function mam_export_row_hash(array $row): string {
    ksort($row);
    return hash('sha256', (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
}

/** Order-independent digest over a set of rows (see the header note). */
function mam_export_checksum(array $rows): string {
    $hashes = array_map('mam_export_row_hash', $rows);
    sort($hashes, SORT_STRING);
    return hash('sha256', implode("\n", $hashes));
}

/** Identifier quoting that is correct for the live driver. */
function mam_export_quote_ident(PDO $db, string $name): string {
    // Table names come from the catalogue, never from user input, but quote
    // anyway so a reserved word or an unusual name cannot break the statement.
    return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? '`' . str_replace('`', '``', $name) . '`'
        : '"' . str_replace('"', '""', $name) . '"';
}

/* ── verify an existing snapshot (no export, no live DB needed) ────────── */

if ($opts['verifyOnly'] !== null) {
    $dir = $opts['verifyOnly'];
    $mf  = $dir . '/manifest.json';
    if (!is_file($mf)) { fwrite(STDERR, "No manifest.json in $dir\n"); exit(1); }
    $manifest = json_decode((string) file_get_contents($mf), true);
    if (!is_array($manifest) || empty($manifest['tables'])) {
        fwrite(STDERR, "manifest.json is unreadable or empty\n"); exit(1);
    }
    // The DDL travels WITH the snapshot precisely so this mode needs nothing
    // else. An older snapshot without it cannot be verified standalone, and
    // says so rather than silently verifying a subset.
    if (empty($manifest['ddl'])) {
        fwrite(STDERR, "This snapshot predates embedded DDL and cannot be verified standalone.\n");
        fwrite(STDERR, "Re-export it from the source database to get a verifiable artifact.\n");
        exit(1);
    }
    $say('MAM Academy — verifying an existing snapshot');
    $say('  directory      : ' . $dir);
    $say('  exported at    : ' . (string) ($manifest['exported_at'] ?? '?'));
    $say('  schema version : ' . (string) ($manifest['schema_version'] ?? '?'));
    $say('  tables         : ' . count($manifest['tables']));
    $say('');

    $mismatches = mam_export_roundtrip($dir, $manifest, $manifest['ddl']);
    if ($mismatches) {
        fwrite(STDERR, "  ✗ VERIFICATION FAILED — this snapshot does not match its manifest:\n");
        foreach ($mismatches as $m) fwrite(STDERR, "      $m\n");
        fwrite(STDERR, "  Do NOT restore from it.\n");
        exit(2);
    }
    $say(sprintf('  ✓ all %d tables match their manifest checksums (%d rows)',
        count($manifest['tables']), (int) ($manifest['total_rows'] ?? 0)));
    $say('  This snapshot is intact and safe to restore from.');
    exit(0);
}

/* ── export ────────────────────────────────────────────────────────────── */

$db     = mam_db();
$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
$stamp  = date('Ymd-His');
$outDir = rtrim($opts['out'] ?? (dirname(__DIR__) . '/storage/export/' . $stamp), '/');

if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create output directory: $outDir\n");
    exit(1);
}

$tables = mam_export_tables($db);
if ($opts['tables']) {
    $unknown = array_diff($opts['tables'], $tables);
    if ($unknown) { fwrite(STDERR, 'Unknown table(s): ' . implode(', ', $unknown) . "\n"); exit(1); }
    $tables = $opts['tables'];
}

$schemaVersion = 0;
try {
    $r = $db->query('SELECT MAX(version) AS v FROM schema_version')->fetch(PDO::FETCH_ASSOC);
    $schemaVersion = (int) ($r['v'] ?? 0);
} catch (Throwable $e) { /* a database without the table is still exportable */ }

$say("MAM Academy — ETL export");
$say("  driver         : $driver");
$say("  schema version : $schemaVersion");
$say("  tables         : " . count($tables));
$say("  destination    : $outDir");
$say('');

$manifest = [
    'format_version' => 1,
    'exported_at'    => date('c'),
    'driver'         => $driver,
    'schema_version' => $schemaVersion,
    'app_version'    => defined('MAM_API_VERSION') ? MAM_API_VERSION : null,
    'tables'         => [],
];
$totalRows = 0;
$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
           | ($opts['pretty'] ? JSON_PRETTY_PRINT : 0);

foreach ($tables as $table) {
    $q = mam_export_quote_ident($db, $table);
    try {
        $rows = $db->query("SELECT * FROM $q")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        fwrite(STDERR, "FAILED reading $table: " . $e->getMessage() . "\n");
        exit(1);
    }

    $file = $outDir . '/' . $table . '.json';
    // JSON_PRESERVE_ZERO_FRACTION keeps 1.0 from collapsing to 1, so a DECIMAL
    // column round-trips as the same value rather than changing type.
    if (@file_put_contents($file, (string) json_encode($rows, $jsonFlags)) === false) {
        fwrite(STDERR, "FAILED writing $file\n");
        exit(1);
    }

    $checksum = mam_export_checksum($rows);
    $manifest['tables'][$table] = [
        'rows'     => count($rows),
        'checksum' => $checksum,
        'columns'  => $rows ? array_keys($rows[0]) : [],
        'file'     => basename($file),
    ];
    $totalRows += count($rows);
    if (count($rows) > 0) $say(sprintf('  %-28s %6d rows  %s', $table, count($rows), substr($checksum, 0, 12)));
}

// Embed the table DDL so the snapshot is self-verifying later, with no live
// database to consult. This is what makes --verify-only possible.
$ddlMap = [];
if ($driver === 'sqlite') {
    foreach ($db->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL")
                ->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if (isset($manifest['tables'][$t['name']])) $ddlMap[(string) $t['name']] = (string) $t['sql'];
    }
}
$manifest['ddl']        = $ddlMap;
$manifest['total_rows'] = $totalRows;
file_put_contents($outDir . '/manifest.json', (string) json_encode($manifest, $jsonFlags | JSON_PRETTY_PRINT));

$say('');
$say(sprintf('  %d tables, %d rows → %s', count($tables), $totalRows, $outDir));


/* ── round-trip proof ──────────────────────────────────────────────────── */

/**
 * Re-import a snapshot directory into a scratch SQLite database and compare
 * every table's checksum against the manifest that shipped with it.
 *
 * The scratch schema is rebuilt from DDL captured in the manifest (or from the
 * live catalogue when exporting), NOT from the app's migrations. The question
 * is "did the snapshot keep every value?", not "do the migrations reproduce the
 * schema" — mixing the two would let a migration bug masquerade as an export
 * bug, or hide one.
 *
 * @return string[] human-readable mismatches; empty means lossless.
 */
function mam_export_roundtrip(string $dir, array $manifest, array $ddl): array {
    $scratch = $dir . '/.roundtrip.sqlite';
    @unlink($scratch);
    $rt = new PDO('sqlite:' . $scratch, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    $rt->exec('PRAGMA foreign_keys = OFF');   // insert order is irrelevant to fidelity

    $mismatches = [];
    foreach ($ddl as $name => $sql) {
        if (!isset($manifest['tables'][$name])) continue;
        try { $rt->exec((string) $sql); }
        catch (Throwable $e) { $mismatches[] = "$name: could not create scratch table — " . $e->getMessage(); }
    }

    foreach ($manifest['tables'] as $table => $meta) {
        $path = $dir . '/' . ($meta['file'] ?? ($table . '.json'));
        if (!is_file($path)) { $mismatches[] = "$table: snapshot file missing ($path)"; continue; }
        $rows = json_decode((string) file_get_contents($path), true);
        if (!is_array($rows)) { $mismatches[] = "$table: snapshot file is not readable JSON"; continue; }

        // Stage 1 — FILE fidelity. Checksum the rows exactly as they decode
        // from JSON, before any database touches them. This is the stage that
        // catches a type change: SQLite's column affinity silently coerces the
        // string "60" back to the integer 60 on insert, so a re-import alone
        // would report a false pass. It matters because transform.php consumes
        // this JSON directly, not a restored database — a stringified integer
        // would flow into the transform and compare differently there.
        $fileSum = mam_export_checksum($rows);
        if ($fileSum !== (string) ($meta['checksum'] ?? '')) {
            $mismatches[] = sprintf('%s: file checksum %s != %s (manifest says %d rows, file holds %d)',
                $table, substr((string) ($meta['checksum'] ?? ''), 0, 12), substr($fileSum, 0, 12),
                (int) ($meta['rows'] ?? 0), count($rows));
            continue;   // no point re-importing a file we already know is wrong
        }

        // Stage 2 — RESTORE fidelity. Prove the rows survive a real insert and
        // read back identically, so the snapshot is not merely well-formed but
        // actually restorable.
        if ($rows) {
            $cols = array_keys($rows[0]);
            $q    = '"' . str_replace('"', '""', $table) . '"';
            try {
                $ins = $rt->prepare(
                    'INSERT INTO ' . $q . ' (' . implode(',', array_map(fn($c) => '"' . $c . '"', $cols)) . ')'
                    . ' VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')'
                );
                $rt->beginTransaction();
                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($cols as $c) {
                        $v = $row[$c] ?? null;
                        // Bind by PHP type so an int stays INTEGER and a null
                        // stays NULL rather than becoming '' or the text 'NULL'.
                        $vals[] = is_bool($v) ? ($v ? 1 : 0) : $v;
                    }
                    $ins->execute($vals);
                }
                $rt->commit();
            } catch (Throwable $e) {
                if ($rt->inTransaction()) $rt->rollBack();
                $mismatches[] = "$table: re-import failed — " . $e->getMessage();
                continue;
            }
        }

        try {
            $back = $rt->query('SELECT * FROM "' . str_replace('"', '""', $table) . '"')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $mismatches[] = "$table: could not read back — " . $e->getMessage();
            continue;
        }
        $have = mam_export_checksum($back);
        $want = (string) ($meta['checksum'] ?? '');
        if ($have !== $want) {
            $mismatches[] = sprintf('%s: checksum %s != %s (manifest says %d rows, snapshot holds %d)',
                $table, substr($want, 0, 12), substr($have, 0, 12), (int) ($meta['rows'] ?? 0), count($back));
        }
    }

    @unlink($scratch);
    return $mismatches;
}

if (!$opts['roundtrip']) {
    $say('');
    $say('Snapshot written. Run again with --verify-roundtrip to PROVE it is lossless.');
    exit(0);
}

$say('');
$say('Round-trip verification');

if ($driver !== 'sqlite') {
    // The scratch database is SQLite; re-importing a MySQL snapshot into it
    // would compare across engines and produce type-coercion noise that says
    // nothing about the snapshot's own fidelity. Refuse rather than report a
    // meaningless pass.
    fwrite(STDERR, "  Round-trip currently supports SQLite sources only (source is $driver).\n");
    exit(1);
}

$mismatches = mam_export_roundtrip($outDir, $manifest, $ddlMap);

if ($mismatches) {
    $say('');
    fwrite(STDERR, "  ✗ ROUND-TRIP FAILED — the snapshot is not lossless:\n");
    foreach ($mismatches as $m) fwrite(STDERR, "      $m\n");
    fwrite(STDERR, "  Nothing downstream (transform/load/cutover) may proceed.\n");
    exit(2);
}

$say(sprintf('  ✓ all %d tables re-imported with identical checksums (%d rows)', count($tables), $totalRows));
$say('  The snapshot is lossless. Safety net established.');
exit(0);
