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
printf("verify   : %d of %d checks passed\n\n", $v['summary']['passed'], $v['summary']['total']);
foreach ($v['checks'] as $c) {
    printf("  %s %-38s expected %-12s got %-12s %s\n",
        $c['ok'] ? '✓' : '✗', $c['check'],
        is_scalar($c['expected']) ? (string) $c['expected'] : json_encode($c['expected']),
        is_scalar($c['actual']) ? (string) $c['actual'] : json_encode($c['actual']),
        (string) $c['note']);
}
if (!$v['ok']) { fwrite(STDERR, "\nVERIFICATION FAILED — do not cut over.\n"); exit(1); }
printf("\nThe database says the same thing as the snapshot.\n");
