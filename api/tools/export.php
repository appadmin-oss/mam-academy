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
 * ── Read-only, and STANDALONE ──
 * Nothing here writes to the source database, and — importantly — this file
 * does NOT load the application bootstrap. `mam_db()` runs the migrator on
 * every connection, including MySQL, so requiring bootstrap.php would migrate
 * the database as a side effect of trying to snapshot it: the one thing a
 * pre-migration safety net must never do. It therefore parses api/.env and
 * opens its own PDO instead.
 *
 * The practical consequence is that this file is self-contained. It can be
 * copied to a server running much older application code and will still run,
 * because it depends on nothing in the repo but PHP and PDO. It reads whatever
 * tables exist at whatever schema version, making no assumptions about either.
 *
 * Idempotent: two runs over the same data produce the same checksums (only the
 * timestamped directory name differs).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

/* ── standalone configuration (no bootstrap: see the header note) ───────── */

const MAM_EXPORT_API_ROOT = __DIR__ . '/..';

/** Minimal .env reader — same KEY=VALUE grammar the application uses. */
function mam_export_env(string $key, string $default = ''): string {
    static $env = null;
    if ($env === null) {
        $env  = [];
        $path = MAM_EXPORT_API_ROOT . '/.env';
        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $t = trim($line);
                if ($t === '' || $t[0] === '#' || strpos($t, '=') === false) continue;
                [$k, $v] = explode('=', $t, 2);
                $env[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
            }
        }
    }
    if (isset($env[$key]) && $env[$key] !== '') return $env[$key];
    $v = getenv($key);
    if ($v !== false && $v !== '') return (string) $v;
    return $default;
}

/**
 * Open the source database directly. Deliberately NOT mam_db(): that runs the
 * migrator, which on a server behind on deploys would silently upgrade the
 * schema during what is supposed to be a read-only snapshot.
 */
function mam_export_connect(): PDO {
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];
    $dsn = mam_export_env('DB_DSN');
    if ($dsn !== '') {
        $pdo = new PDO($dsn, mam_export_env('DB_USERNAME') ?: null, mam_export_env('DB_PASSWORD') ?: null, $opts);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $pdo->exec('SET NAMES utf8mb4');
        return $pdo;
    }
    $path = MAM_EXPORT_API_ROOT . '/storage/mam.db';
    if (!is_file($path)) {
        fwrite(STDERR, "No DB_DSN in api/.env and no SQLite database at $path\n");
        exit(1);
    }
    // Opened without PRAGMA writes; a snapshot must not even change journal mode.
    return new PDO('sqlite:' . $path, null, null, $opts);
}

/* ── arguments ─────────────────────────────────────────────────────────── */

$opts = [
    'out'        => null,
    'roundtrip'  => false,
    'pretty'     => false,
    'tables'     => [],
    'quiet'      => false,
    'verifyOnly' => null,
    'uploads'    => false,
    'keyFp'      => null,
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--verify-roundtrip') { $opts['roundtrip'] = true; continue; }
    if ($arg === '--pretty')           { $opts['pretty']    = true; continue; }
    if ($arg === '--quiet')            { $opts['quiet']     = true; continue; }
    if ($arg === '--with-uploads')     { $opts['uploads']   = true; continue; }
    /* SUPPLIED, never derived here. Resolving the signing key would mean
       reproducing mam_app_key()'s fallback chain in a file that deliberately
       loads no application code — and a second copy of that resolution is
       exactly the drift that had ID cards signed with a constant. The caller
       that already has the real resolver passes the fingerprint in; a run
       without it records "not supplied" rather than a guess. */
    if (str_starts_with($arg, '--key-fingerprint=')) { $opts['keyFp'] = substr($arg, 18); continue; }
    if (str_starts_with($arg, '--out='))    { $opts['out'] = substr($arg, 6); continue; }
    if (str_starts_with($arg, '--verify-only=')) { $opts['verifyOnly'] = rtrim(substr($arg, 14), '/'); continue; }
    if (str_starts_with($arg, '--tables=')) {
        $opts['tables'] = array_values(array_filter(array_map('trim', explode(',', substr($arg, 9)))));
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php api/tools/export.php [--out=DIR] [--tables=a,b] [--with-uploads]\n"
            . "       [--key-fingerprint=HEX] [--verify-roundtrip] [--verify-only=DIR] [--pretty] [--quiet]\n");
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
    // A snapshot taken from MySQL can only have its FILE fidelity checked here
    // (see mam_export_roundtrip) — its DDL cannot be replayed into the SQLite
    // scratch database. Honour what the snapshot says about itself rather than
    // assuming the local driver.
    $snapDriver = (string) ($manifest['driver'] ?? 'sqlite');
    $restore    = array_key_exists('restore_verified', $manifest)
        ? (bool) $manifest['restore_verified']
        : ($snapDriver === 'sqlite');

    // The DDL travels WITH the snapshot precisely so this mode needs nothing
    // else. An older SQLite snapshot without it cannot be replayed, and says so
    // rather than silently verifying a subset.
    if ($restore && empty($manifest['ddl'])) {
        fwrite(STDERR, "This snapshot predates embedded DDL and cannot be verified standalone.\n");
        fwrite(STDERR, "Re-export it from the source database to get a verifiable artifact.\n");
        exit(1);
    }
    $say('MAM Academy — verifying an existing snapshot');
    $say('  directory      : ' . $dir);
    $say('  exported at    : ' . (string) ($manifest['exported_at'] ?? '?'));
    $say('  schema version : ' . (string) ($manifest['schema_version'] ?? '?'));
    $say('  tables         : ' . count($manifest['tables']));
    $say('  source driver  : ' . $snapDriver);
    if (!$restore) {
        $say('  restore stage  : skipped (non-SQLite source) — file fidelity only');
    }
    $say('');

    $mismatches = mam_export_roundtrip($dir, $manifest, $manifest['ddl'] ?? [], $restore);
    if ($mismatches) {
        fwrite(STDERR, "  ✗ VERIFICATION FAILED — this snapshot does not match its manifest:\n");
        foreach ($mismatches as $m) fwrite(STDERR, "      $m\n");
        fwrite(STDERR, "  Do NOT restore from it.\n");
        exit(2);
    }
    $say(sprintf('  ✓ all %d tables match their manifest checksums (%d rows)%s',
        count($manifest['tables']), (int) ($manifest['total_rows'] ?? 0),
        $restore ? '' : ' — file fidelity only'));
    /* State the files explicitly, both ways. "Intact" over a line that never
       mentions the uploads reads the same whether they were verified, absent,
       or never captured — and the difference is whether a restore returns the
       children's photographs. */
    $vUp = $manifest['uploads'] ?? null;
    if (is_array($vUp) && !empty($vUp['sha256'])) {
        $say(sprintf('  ✓ all %d uploaded file(s) match their sha256', count($vUp['sha256'])));
    } elseif (is_array($vUp) && ($vUp['captured'] ?? true) !== false) {
        // Taken WITH --with-uploads on an install that had none yet.
        $say('  ✓ uploads were captured; the install had no uploaded files at the time');
    } else {
        $say('  ⚠ this archive holds NO uploaded files — headshots and recordings are not in it'
            . ' (it was taken without --with-uploads)');
    }
    $say('  This snapshot is intact.');
    exit(0);
}

/**
 * The signing-key fingerprint, from the flag or from the file the application
 * publishes for exactly this purpose (`storage/.app_key.fingerprint`, written
 * by `mam_backup_write_fingerprint()` on the daily job).
 *
 * ⚠️ It is READ, never derived. Deriving it would mean a second copy of
 * mam_app_key()'s fallback chain inside a file that deliberately loads no
 * application code — and a second copy of that exact resolution is what had
 * ID cards signed with a constant printed in the repository. Reading a value
 * the authoritative resolver wrote cannot drift from it; recomputing it can.
 * A missing file is reported honestly rather than guessed at.
 */
function mam_export_key_fingerprint(?string $flag): string {
    if ($flag !== null && $flag !== '') return $flag;
    $path = MAM_EXPORT_API_ROOT . '/storage/.app_key.fingerprint';
    if (is_readable($path)) {
        $fp = trim((string) @file_get_contents($path));
        if ($fp !== '') return $fp;
    }
    return 'not supplied';
}

/* ── the uploaded FILES ─────────────────────────────────────────────────
 * A snapshot of the database alone is not a backup of this academy. The
 * headshots and the recorded takes under api/storage/uploads are children's
 * photographs and recordings of children playing: the rows that point at them
 * can be restored from the tables, and the files themselves cannot be
 * re-derived from anything. They were in no snapshot at all.
 *
 * Copied with a per-file sha256 for the same reason every table carries a
 * checksum — a directory of photographs nobody can verify is a directory of
 * photographs nobody should trust. --verify-only checks them too.
 */

/** Recursively list files under $root, as paths RELATIVE to it. */
function mam_export_upload_files(string $root): array {
    if (!is_dir($root)) return [];
    $out = [];
    $it  = new RecursiveIteratorIterator(
        // SKIP_DOTS, and never follow a symlink: a link pointing out of the
        // uploads tree would copy arbitrary files from the server into an
        // archive somebody later hands to a third party.
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if ($f->isLink() || !$f->isFile()) continue;
        $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
        if ($rel !== '') $out[] = $rel;
    }
    sort($out);          // stable order, so two runs produce one manifest
    return $out;
}

/**
 * Copy the uploads tree into the snapshot and return the manifest entry.
 * Returns ['files'=>N,'bytes'=>N,'sha256'=>[rel=>hash],'skipped'=>[...]].
 */
function mam_export_copy_uploads(string $srcRoot, string $destRoot): array {
    $res = ['files' => 0, 'bytes' => 0, 'sha256' => [], 'skipped' => []];
    $rels = mam_export_upload_files($srcRoot);
    if (!$rels) return $res;

    foreach ($rels as $rel) {
        $src = $srcRoot . '/' . $rel;
        $dst = $destRoot . '/' . $rel;
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $res['skipped'][] = "$rel (cannot create directory)";
            continue;
        }
        if (!@copy($src, $dst)) { $res['skipped'][] = "$rel (copy failed)"; continue; }
        // Hash the COPY, not the source: that proves what landed in the
        // archive, which is the thing a restore will read.
        $hash = @hash_file('sha256', $dst);
        if ($hash === false) { $res['skipped'][] = "$rel (unreadable after copy)"; continue; }
        $res['sha256'][$rel] = $hash;
        $res['bytes'] += (int) @filesize($dst);
        $res['files']++;
    }
    return $res;
}

/** Verify an archive's copied uploads against the manifest that shipped with it. */
function mam_export_verify_uploads(string $dir, array $manifest): array {
    $bad = [];
    $up  = $manifest['uploads'] ?? null;
    if (!is_array($up)) return $bad;
    /* A file that could not be copied is a file a restore will not bring back.
       It used to be written to `skipped`, warned about on stderr, and then read
       by nothing: the export exited 0, the status page said ok and this check
       said "intact". */
    foreach ((array) ($up['skipped'] ?? []) as $sk) $bad[] = 'uploads: NOT captured — ' . $sk;
    if (empty($up['sha256'])) return $bad;   // none captured (or none existed)
    foreach ((array) $up['sha256'] as $rel => $want) {
        $path = $dir . '/uploads/' . $rel;
        if (!is_file($path)) { $bad[] = "uploads/$rel: missing from the archive"; continue; }
        $have = @hash_file('sha256', $path);
        if ($have !== $want) {
            $bad[] = sprintf('uploads/%s: sha256 %s != %s', $rel, substr((string) $want, 0, 12), substr((string) $have, 0, 12));
        }
    }
    return $bad;
}

/* ── export ────────────────────────────────────────────────────────────── */

$db     = mam_export_connect();
$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
// UTC, like every other timestamp here: backup.php reads this name as UTC, and
// this script loads no bootstrap that would pin the zone for it.
$stamp  = gmdate('Ymd-His');
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
    'app_version'    => null,   // deliberately not loaded: see the header note
    /* The signing key is NOT in this archive and must not be: it would turn a
       data backup into a credential store. But a restore depends on it —
       mam_card_sign() derives an ID card's HMAC from it, so a restore with the
       wrong key leaves every printed card failing at a door with nothing to
       say why. The fingerprint answers "is the key I have the one this data
       was signed with" without carrying the key itself. */
    'signing_key_fingerprint' => mam_export_key_fingerprint($opts['keyFp']),
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
} else {
    // MySQL DDL is captured as evidence and for the transform to read, even
    // though it cannot be replayed into the SQLite scratch database.
    foreach (array_keys($manifest['tables']) as $t) {
        try {
            $r = $db->query('SHOW CREATE TABLE ' . mam_export_quote_ident($db, $t))->fetch(PDO::FETCH_ASSOC);
            if ($r) $ddlMap[$t] = (string) (($r['Create Table'] ?? $r['Create View'] ?? ''));
        } catch (Throwable $e) { /* evidence only — never fail an export over it */ }
    }
}
if ($opts['uploads']) {
    $upSrc = MAM_EXPORT_API_ROOT . '/storage/uploads';
    $up    = mam_export_copy_uploads($upSrc, $outDir . '/uploads');
    $manifest['uploads'] = $up;
    $say(sprintf('  %-28s %6d files %s', 'uploads/', $up['files'],
        $up['bytes'] > 0 ? round($up['bytes'] / 1024) . ' KB' : ''));
    foreach ($up['skipped'] as $sk) fwrite(STDERR, "  WARNING uploads: $sk\n");
    $uploadsIncomplete = count($up['skipped']);
} else {
    /* Stated, not omitted. An archive that is silent about the files is
       indistinguishable from one taken when there were none — and the
       difference is whether a restore comes back with the children's
       photographs or without them. */
    $manifest['uploads'] = ['captured' => false,
        'note' => 'Run with --with-uploads to include api/storage/uploads in the archive.'];
}

$manifest['restore_verified'] = ($driver === 'sqlite');
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
function mam_export_roundtrip(string $dir, array $manifest, array $ddl, bool $restore = true): array {
    $mismatches = [];
    $rt = null; $scratch = null;

    // Stage 2 replays the snapshot into a scratch SQLite database. That is only
    // meaningful when the source WAS SQLite — replaying a MySQL snapshot into
    // SQLite compares across engines and produces type-coercion noise that says
    // nothing about the snapshot's own fidelity. Stage 1 below is engine-
    // agnostic and runs either way, so a MySQL snapshot is still verified, just
    // not proven restorable by this tool.
    if ($restore) {
        $scratch = $dir . '/.roundtrip.sqlite';
        @unlink($scratch);
        $rt = new PDO('sqlite:' . $scratch, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        $rt->exec('PRAGMA foreign_keys = OFF');   // insert order is irrelevant to fidelity
        foreach ($ddl as $name => $sql) {
            if (!isset($manifest['tables'][$name])) continue;
            try { $rt->exec((string) $sql); }
            catch (Throwable $e) { $mismatches[] = "$name: could not create scratch table — " . $e->getMessage(); }
        }
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
        if ($restore && $rows) {
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

        if (!$restore) continue;   // stage 1 already passed; nothing more to check
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

    foreach (mam_export_verify_uploads($dir, $manifest) as $m) $mismatches[] = $m;

    if ($scratch !== null) @unlink($scratch);
    return $mismatches;
}

/* Exit non-zero when an upload could not be copied: the cron that runs this
   must see a failure, not a success with a warning on stderr nobody reads. */
$uploadsIncomplete = $uploadsIncomplete ?? 0;
if ($uploadsIncomplete > 0) {
    fwrite(STDERR, "  ✗ $uploadsIncomplete uploaded file(s) could not be copied — this snapshot would NOT restore them.\n");
}
if (!$opts['roundtrip']) {
    $say('');
    $say('Snapshot written. Run again with --verify-roundtrip to PROVE it is lossless.');
    exit($uploadsIncomplete > 0 ? 3 : 0);
}

$say('');
$say('Round-trip verification');

// On MySQL only stage 1 (file fidelity) is meaningful — see the note in
// mam_export_roundtrip(). Say so explicitly rather than reporting a pass that
// covers less than the reader assumes.
$restore = ($driver === 'sqlite');
if (!$restore) {
    $say("  source is $driver: verifying FILE fidelity (checksums vs manifest).");
    $say('  the restore stage is skipped — replaying a MySQL snapshot into SQLite');
    $say('  would compare across engines and prove nothing about this snapshot.');
}

$mismatches = mam_export_roundtrip($outDir, $manifest, $ddlMap, $restore);

if ($mismatches) {
    $say('');
    fwrite(STDERR, "  ✗ ROUND-TRIP FAILED — the snapshot is not lossless:\n");
    foreach ($mismatches as $m) fwrite(STDERR, "      $m\n");
    fwrite(STDERR, "  Nothing downstream (transform/load/cutover) may proceed.\n");
    exit(2);
}

$say($restore
    ? sprintf('  ✓ all %d tables re-imported with identical checksums (%d rows)', count($tables), $totalRows)
    : sprintf('  ✓ all %d tables match their checksums (%d rows) — file fidelity only', count($tables), $totalRows));
$say('  The snapshot is lossless. Safety net established.');
exit(0);
