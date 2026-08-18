<?php
/**
 * ETL — load a v1 export into a v2 database, then verify it.
 * ─────────────────────────────────────────────────────────────
 *   php api/tools/v2-load.php --export=DIR --db=FILE            # dry run
 *   php api/tools/v2-load.php --export=DIR --db=FILE --commit    # write it
 *   php api/tools/v2-load.php --export=DIR --db=FILE --verify-only
 *
 * DRY RUN IS THE DEFAULT. `--commit` has to be typed, because the direction
 * that changes a database is never the one you should get by forgetting a flag.
 *
 * `--db` is an SQLite path by default; `--dsn` takes a full PDO DSN for MySQL
 * (with `--user`/`--pass`). The schema is created from db/schema-v2-sqlite.sql
 * when the target is empty and `--create` is given — an existing database is
 * never rebuilt, because "the load recreated the schema" is how a second run
 * destroys the first.
 *
 * Re-running is SAFE and expected: every write is guarded by
 * legacy_reference_map, so a second run reports what already exists and writes
 * nothing twice. The first attempt at a real migration is never the last.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$ROOT = dirname(__DIR__);
require $ROOT . '/lib/v2/clarity.php';      // mam_v2_ulid()
require $ROOT . '/lib/v2/identity.php';
require $ROOT . '/lib/v2/transform.php';
require $ROOT . '/lib/v2/load.php';
require $ROOT . '/lib/v2/verify.php';

/** --key=value / --flag → an options array. */
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (!preg_match('/^--([a-z0-9\-]+)(?:=(.*))?$/i', $a, $m)) continue;
    $opt[$m[1]] = $m[2] ?? true;
}

$exportDir = (string) ($opt['export'] ?? '');
if ($exportDir === '' || !is_dir($exportDir)) {
    fwrite(STDERR, "Need --export=DIR (the directory of table JSON files from api/tools/export.php).\n");
    exit(2);
}

// The export holds real identity, payment and audit rows. It is read here and
// never copied anywhere the repository can see.
$source = [];
foreach (['customers', 'users', 'applications', 'payments'] as $t) {
    $f = rtrim($exportDir, '/') . '/' . $t . '.json';
    if (!is_file($f)) { fwrite(STDERR, "Missing $t.json in the export.\n"); exit(2); }
    $j = json_decode((string) file_get_contents($f), true);
    if (!is_array($j)) { fwrite(STDERR, "$t.json did not parse as JSON.\n"); exit(2); }
    $source[$t] = $j;
}

/* ── The target ──────────────────────────────────────────────────────────── */
try {
    if (!empty($opt['dsn'])) {
        $db = new PDO((string) $opt['dsn'], (string) ($opt['user'] ?? ''), (string) ($opt['pass'] ?? ''));
    } else {
        $path = (string) ($opt['db'] ?? '');
        if ($path === '') { fwrite(STDERR, "Need --db=FILE or --dsn=...\n"); exit(2); }
        $fresh = !is_file($path) || filesize($path) === 0;
        $db = new PDO('sqlite:' . $path);
        $db->exec('PRAGMA foreign_keys = ON');
        if ($fresh && empty($opt['create'])) {
            fwrite(STDERR, "$path is empty. Pass --create to build the v2 schema into it.\n");
            exit(2);
        }
        if ($fresh) {
            $ddl = (string) file_get_contents($ROOT . '/../db/schema-v2-sqlite.sql');
            $db->exec($ddl);
            fwrite(STDOUT, "schema   : created from db/schema-v2-sqlite.sql\n");
        }
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not open the target: ' . $e->getMessage() . "\n");
    exit(1);
}

$commit = !empty($opt['commit']);

/* ── Load ────────────────────────────────────────────────────────────────── */
$report = ['rejected' => []];
if (empty($opt['verify-only'])) {
    $report = mam_v2_load($db, $source, ['commit' => $commit] + $opt);

    if (empty($report['ok'])) {
        fwrite(STDERR, "LOAD FAILED: " . (string) ($report['error'] ?? '?') . "\n");
        if (!empty($report['message']))     fwrite(STDERR, '  ' . $report['message'] . "\n");
        if (!empty($report['unaccounted'])) fwrite(STDERR, '  unaccounted rows: ' . implode(', ', $report['unaccounted']) . "\n");
        exit(1);
    }

    printf("mode     : %s\n", $commit ? 'COMMIT' : 'dry run (nothing written) — pass --commit to write');
    printf("identity : %d customers + %d users → %d people (%d merged, %d erased, %d without an address)\n",
        $report['identity']['customers'], $report['identity']['users'], $report['identity']['people'],
        $report['identity']['merged'], $report['identity']['erased'], $report['identity']['no_email']);
    if (!empty($report['name_conflicts'])) {
        printf("  ⚠ %d merged identit%s where the v1 rows DISAGREE about the person's name.\n",
            $report['name_conflicts'], $report['name_conflicts'] === 1 ? 'y' : 'ies');
        printf("    Review them in the admin's v2 Read Model before any cutover: a guardian's\n");
        printf("    address used to apply for a child looks like one person to an email-keyed rule.\n");
    }
    printf("people   : %d created, %d already present, %d credentials carried\n",
        $report['people']['users_created'], $report['people']['users_existing'], $report['people']['credentials_created']);
    printf("apps     : %d of %d loaded, %d already present, %d rejected by the transform\n",
        $report['applications']['created'], $report['transform']['applications']['in'],
        $report['applications']['existing'], count($report['rejected']['applications']));
    printf("payments : %d of %d loaded, %d already present, %d rejected, %d with no owner\n",
        $report['payments']['created'], $report['transform']['payments']['in'],
        $report['payments']['existing'], count($report['rejected']['payments']), $report['payments']['unowned']);
    // "this run" matters: on a re-run everything is already present, so these
    // are zero and that is not the same statement as "there is no money".
    printf("money    : %s %s loaded this run, %s of it succeeded\n", MAM_V2_BASE_CURRENCY,
        number_format($report['payments']['total_minor'] / 100, 2),
        number_format($report['payments']['succeeded_minor'] / 100, 2));

    foreach (['applications', 'payments'] as $k) {
        foreach ((array) $report['rejected'][$k] as $r) {
            printf("  ! rejected %s #%s: %s\n", $k, (string) ($r['id'] ?? '?'), (string) $r['why']);
        }
    }
}

/* ── Verify ──────────────────────────────────────────────────────────────── */
// Run even on a dry run, because then it checks the PREVIOUS load — which is
// the question somebody re-running this actually has. But a dry run against a
// target nothing has been loaded into has nothing to verify, and saying
// "VERIFICATION FAILED — do not cut over" there is alarming and wrong: the run
// did exactly what was asked.
$loadedAlready = (int) $db->query("SELECT COUNT(*) FROM tenants")->fetchColumn() > 0;
if (!$commit && !$loadedAlready) {
    printf("\nverify   : nothing has been loaded into this target yet, so there is nothing to verify.\n");
    printf("\nDry run complete. Nothing was written. Re-run with --commit to load.\n");
    exit(0);
}

$v = mam_v2_verify($db, $source, $report['rejected'] ?? [], $opt);
if (!empty($v['error'])) {
    printf("\nverify   : skipped — %s\n", (string) $v['error']);
    exit($commit ? 1 : 0);
}

printf("\nverify   : %d of %d checks passed\n", $v['summary']['passed'], $v['summary']['total']);
foreach ($v['checks'] as $c) {
    printf("  %s %-34s expected %-12s got %-12s %s\n",
        $c['ok'] ? '✓' : '✗', $c['check'],
        is_scalar($c['expected']) ? (string) $c['expected'] : json_encode($c['expected']),
        is_scalar($c['actual']) ? (string) $c['actual'] : json_encode($c['actual']),
        (string) $c['note']);
}
if (!$v['ok']) {
    fwrite(STDERR, "\nVERIFICATION FAILED — do not cut over.\n");
    exit(1);
}
printf("\n%s\n", $commit ? 'Loaded and verified.' : 'Dry run complete. Nothing was written.');
