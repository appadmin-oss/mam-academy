<?php
/**
 * ETL — verify a v2 database against the v1 export it came from. READ ONLY.
 * ─────────────────────────────────────────────────────────────
 *   php api/tools/v2-verify.php --export=DIR --db=FILE
 *   php api/tools/v2-verify.php --export=DIR --dsn='mysql:host=...;dbname=...' --user=U --pass=P
 *
 * The counterpart to `v2-transform.php`, for when the load was not ours to run.
 * Point it at the export and at the database somebody loaded, and it recomputes
 * the figures that must match — head counts, the money, whether erasure held —
 * from the SOURCE, by arithmetic that shares nothing with the transform.
 *
 * It opens the connection READ ONLY, and not as a nicety: a verifier that can
 * write is a verifier that can make itself pass.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$ROOT = dirname(__DIR__);
require $ROOT . '/lib/v2/identity.php';
require $ROOT . '/lib/v2/transform.php';
require $ROOT . '/lib/v2/plan.php';      // for mam_v2_apply_exclusions()
require $ROOT . '/lib/v2/verify.php';

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (!preg_match('/^--([a-z0-9\-]+)(?:=(.*))?$/i', $a, $m)) continue;
    $opt[$m[1]] = $m[2] ?? true;
}

$exportDir = rtrim((string) ($opt['export'] ?? ''), '/');
if ($exportDir === '' || !is_dir($exportDir)) {
    fwrite(STDERR, "Need --export=DIR (the export the database was built from).\n");
    exit(2);
}
$source = [];
foreach (glob($exportDir . '/*.json') ?: [] as $f) {
    $t = basename($f, '.json');
    if ($t === 'manifest' || str_starts_with($t, '_')) continue;
    $j = json_decode((string) file_get_contents($f), true);
    if (is_array($j)) $source[$t] = $j;
}
foreach (['customers', 'users', 'applications', 'payments'] as $need) {
    if (!isset($source[$need])) { fwrite(STDERR, "Missing $need.json in the export.\n"); exit(2); }
}

/* ── The same exclusions the transform applied ───────────────────────────────
 * A row the academy declared to be test data is not missing from the database —
 * it was never meant to be there. The verifier has to know that or it reports a
 * correct load as a shortfall, which is the fastest way to teach somebody to
 * ignore it.
 *
 * This is not the verifier borrowing the transform's arithmetic: the figures are
 * still recomputed independently. It is both of them reading the same declared
 * decision, which is the only way they can agree about what should be there.
 */
$exclFile = (string) ($opt['exclusions'] ?? ($ROOT . '/../db/v2-exclusions.json'));
$excluded = [];
if (empty($opt['no-exclusions']) && is_file($exclFile)) {
    $j = json_decode((string) file_get_contents($exclFile), true);
    if (is_array($j) && isset($j['exclusions'])) {
        $ex = mam_v2_apply_exclusions($source, (array) $j['exclusions']);
        if ($ex['missed']) {
            fwrite(STDERR, "Exclusions name rows this export does not have: "
                . implode(', ', $ex['missed']) . "\n");
            exit(2);
        }
        $source   = $ex['source'];
        $excluded = $ex['removed'];
    }
}

try {
    if (!empty($opt['dsn'])) {
        $db = new PDO((string) $opt['dsn'], (string) ($opt['user'] ?? ''), (string) ($opt['pass'] ?? ''));
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Read-only at the SESSION level, so a mistake in this file cannot write.
        $db->exec('SET SESSION TRANSACTION READ ONLY');
    } else {
        $path = (string) ($opt['db'] ?? '');
        if ($path === '' || !is_file($path)) { fwrite(STDERR, "Need --db=FILE or --dsn=...\n"); exit(2); }
        $db = new PDO('sqlite:file:' . $path . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA query_only = 1');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not open the database read-only: ' . $e->getMessage() . "\n");
    exit(1);
}

$v = mam_v2_verify($db, $source, [], $opt);
if (!empty($v['error'])) {
    fwrite(STDERR, "Cannot verify: " . (string) $v['error'] . "\n");
    exit(1);
}
printf("verify   : %d of %d checks passed\n", $v['summary']['passed'], $v['summary']['total']);
if ($excluded) {
    printf("excluded : %d v1 row(s) held out by decision (%s) and not expected in the database\n",
        count($excluded), basename($exclFile));
}
printf("\n");
foreach ($v['checks'] as $c) {
    printf("  %s %-38s expected %-12s got %-12s %s\n",
        $c['ok'] ? '✓' : '✗', $c['check'],
        is_scalar($c['expected']) ? (string) $c['expected'] : json_encode($c['expected']),
        is_scalar($c['actual']) ? (string) $c['actual'] : json_encode($c['actual']),
        (string) $c['note']);
}
if (!$v['ok']) { fwrite(STDERR, "\nVERIFICATION FAILED — do not cut over.\n"); exit(1); }
printf("\nThe database says the same thing as the snapshot.\n");
