<?php
/**
 * ETL — transform a v1 export into a loadable v2 dataset. WRITES NO DATABASE.
 * ─────────────────────────────────────────────────────────────
 *   php api/tools/v2-transform.php --export=DIR --out=DIR [--dialect=mysql|sqlite]
 *                                    [--batch=N]       # rows per INSERT, default 100
 *                                    [--with-schema]   # also write v2-full.sql
 *
 * The counterpart to `v2-load.php`, for when the load is not ours to run: apply
 * `db/schema-v2.sql` yourself, then apply the `v2-data.sql` this writes. Every
 * foreign key in that file is already resolved, so it loads in one pass with
 * constraint checking ON.
 *
 * It touches no database at all — not even to read one. That is the point: the
 * whole mapping can be produced, reviewed and diffed before anything is
 * applied, and running it twice over the same export gives byte-identical
 * output.
 *
 * Read `_coverage.json` and `_review.json` before loading. Coverage says where
 * every v1 table went, or why it went nowhere; review lists the decisions that
 * want a human's eye — merged identities, a cohort whose subject had to be
 * derived, a timetable that did not parse.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$ROOT = dirname(__DIR__);
require $ROOT . '/lib/v2/identity.php';
require $ROOT . '/lib/v2/transform.php';
require $ROOT . '/lib/v2/plan.php';
require $ROOT . '/lib/v2/emit.php';

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (!preg_match('/^--([a-z0-9\-]+)(?:=(.*))?$/i', $a, $m)) continue;
    $opt[$m[1]] = $m[2] ?? true;
}

$exportDir = rtrim((string) ($opt['export'] ?? ''), '/');
$outDir    = rtrim((string) ($opt['out'] ?? ''), '/');
if ($exportDir === '' || !is_dir($exportDir)) {
    fwrite(STDERR, "Need --export=DIR (the directory of table JSON files from api/tools/export.php).\n");
    exit(2);
}
if ($outDir === '') { fwrite(STDERR, "Need --out=DIR (where to write the dataset).\n"); exit(2); }
if (realpath($outDir) !== false && realpath($outDir) === realpath($exportDir)) {
    // Writing the output over the input would destroy the only copy of the
    // snapshot, and the snapshot is the one thing here that cannot be rebuilt.
    fwrite(STDERR, "--out must not be the export directory.\n");
    exit(2);
}

/* ── Read the whole export ───────────────────────────────────────────────────
 * Every table, not a chosen few: the coverage ledger's job is to account for
 * all of them, and it can only do that if they are all present. The export
 * holds real identity, payment and audit rows and is never copied anywhere the
 * repository can see.
 */
$source = [];
$skipped = [];
foreach (glob($exportDir . '/*.json') ?: [] as $f) {
    $t = basename($f, '.json');
    if ($t === 'manifest' || str_starts_with($t, '_')) { $skipped[] = $t; continue; }
    $j = json_decode((string) file_get_contents($f), true);
    if (!is_array($j)) { fwrite(STDERR, "$t.json did not parse as a JSON array.\n"); exit(2); }
    $source[$t] = $j;
}
if (!$source) { fwrite(STDERR, "No table JSON files in $exportDir.\n"); exit(2); }
foreach (['customers', 'users', 'applications', 'payments'] as $need) {
    if (!isset($source[$need])) { fwrite(STDERR, "Missing $need.json in the export.\n"); exit(2); }
}

/* ── Plan ────────────────────────────────────────────────────────────────── */
$plan = mam_v2_plan($source, $opt);
if (empty($plan['ok'])) {
    fwrite(STDERR, 'TRANSFORM FAILED: ' . (string) ($plan['error'] ?? '?') . "\n");
    if (!empty($plan['unaccounted'])) {
        fwrite(STDERR, "  v1 rows that landed in no person: " . implode(', ', $plan['unaccounted']) . "\n");
    }
    exit(1);
}

$dialect = (string) ($opt['dialect'] ?? 'mysql');
$stamp   = basename($exportDir);
// Batch size is exposed because it is the one thing a constrained host makes you
// change: a shared server's `max_allowed_packet` can be smaller than one
// hundred-row insert, and that failure arrives as a dropped connection rather
// than an error naming the cause. Smaller batches are slower and always work.
$batch   = max(1, (int) ($opt['batch'] ?? 100));
$sql     = mam_v2_emit_sql($plan, ['dialect' => $dialect, 'stamp' => $stamp, 'batch' => $batch]);
$manifest = mam_v2_emit_dir($plan, $outDir, ['dialect' => $dialect, 'stamp' => $stamp]);
$sqlPath = $outDir . '/v2-data.sql';
file_put_contents($sqlPath, $sql);
@chmod($sqlPath, 0600);

/* ── Optionally, the schema and the data as ONE file ──────────────────────────
 * Two files loaded in the right order is a step somebody has to get right, and
 * getting it wrong produces `ERROR 1146 … tenants doesn't exist` — which reads
 * like a broken export rather than a skipped step. One file cannot be applied
 * out of order.
 *
 * The trade is stated rather than hidden: DDL is not transactional in MySQL, so
 * the data half is still all-or-nothing but the tables it creates are not. A
 * failed combined run means DROP DATABASE and start again, which is what you
 * would do anyway with a database that has no data in it.
 */
$fullPath = null;
if (!empty($opt['with-schema'])) {
    $schemaFile = $ROOT . '/../db/schema-v2' . ($dialect === 'sqlite' ? '-sqlite' : '') . '.sql';
    if (!is_file($schemaFile)) {
        fwrite(STDERR, "Cannot find $schemaFile for --with-schema.\n");
        exit(2);
    }
    $fullPath = $outDir . '/v2-full.sql';
    $banner = "-- MAM Academy — v2 schema AND data, in one file.\n"
        . "-- Apply this to an EMPTY database. It creates the tables and then fills them,\n"
        . "-- so there is no ordering to get wrong.\n"
        . "--\n"
        . "-- The table creation is NOT transactional (no engine makes it so). If this\n"
        . "-- fails partway, DROP the database and run it again rather than patching\n"
        . "-- around it — a half-built schema reconciles against nothing.\n"
        . "--\n"
        . "-- Generated by api/tools/v2-transform.php from " . $schemaFile . " + the export.\n\n";
    file_put_contents($fullPath, $banner . (string) file_get_contents($schemaFile) . "\n\n" . $sql);
    @chmod($fullPath, 0600);
}

/* ── Report ──────────────────────────────────────────────────────────────── */
printf("export   : %s (%d tables read, %d with rows)\n", $exportDir, count($source),
    count(array_filter($source, static fn($r) => count((array) $r) > 0)));
printf("out      : %s\n", $outDir);
printf("dialect  : %s → %s (%s, %d row(s) per insert)\n", $dialect, basename($sqlPath),
    number_format(strlen($sql) / 1024, 1) . ' KiB', $batch);
if ($fullPath !== null) {
    printf("one-file : %s (%s) — schema + data, apply to an EMPTY database\n", basename($fullPath),
        number_format((int) filesize($fullPath) / 1024, 1) . ' KiB');
}
printf("identity : %d customers + %d users → %d people (%d merged: %d explicit link, %d address+name; "
     . "%d kept apart on a shared address; %d erased)\n",
    $plan['identity']['customers'], $plan['identity']['users'], $plan['identity']['people'],
    $plan['identity']['merged'], $plan['identity']['merged_explicit'], $plan['identity']['merged_email'],
    $plan['identity']['split_shared_email'], $plan['identity']['erased']);
printf("money    : %s %s planned, %s of it succeeded, %d payment(s) with no owner\n",
    $plan['money']['currency'], number_format($plan['money']['total_minor'] / 100, 2),
    number_format($plan['money']['succeeded_minor'] / 100, 2), $plan['money']['unowned']);

printf("\nrows out :\n");
foreach ($plan['counts'] as $t => $n) if ($n > 0) printf("  %-22s %6d\n", $t, $n);

// Not carried, and why. This is the half of a migration report people skip and
// then argue about six months later.
$none = array_values(array_filter($plan['coverage'], static fn($c) => $c['destination'] === null && $c['rows_in'] > 0));
if ($none) {
    printf("\nnot carried (%d table%s with rows):\n", count($none), count($none) === 1 ? '' : 's');
    foreach ($none as $c) printf("  %-22s %6d  %s\n", $c['table'], $c['rows_in'], $c['note']);
}

if ($plan['rejected']) {
    printf("\nrejected (%d row%s — nothing was guessed in their place):\n",
        count($plan['rejected']), count($plan['rejected']) === 1 ? '' : 's');
    foreach ($plan['rejected'] as $r) {
        printf("  ! %s #%s: %s\n", (string) ($r['table'] ?? '?'), (string) ($r['id'] ?? '?'), (string) $r['why']);
    }
}

if ($plan['review']) {
    printf("\nreview before loading (%d):\n", count($plan['review']));
    foreach ($plan['review'] as $r) {
        printf("  ? %s\n", (string) $r['kind']);
        foreach ($r as $k => $v) {
            if ($k === 'kind' || !is_scalar($v)) continue;
            printf("      %-14s %s\n", $k, (string) $v);
        }
    }
}

printf("\nNothing was written to any database. Load with:\n");
if ($fullPath !== null) {
    printf("  %s\n", $dialect === 'sqlite' ? 'sqlite3 v2.db < ' . $fullPath : 'mysql DB < ' . $fullPath);
} else {
    printf("  %s\n", $dialect === 'sqlite'
        ? 'sqlite3 v2.db < db/schema-v2-sqlite.sql && sqlite3 v2.db < ' . $sqlPath
        : 'mysql DB < db/schema-v2.sql && mysql DB < ' . $sqlPath);
}
printf("Read %s/_coverage.json and %s/_review.json first.\n", $outDir, $outDir);
