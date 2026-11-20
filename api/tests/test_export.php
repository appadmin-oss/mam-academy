<?php
/**
 * ETL Layer 1 — the export snapshot's fidelity guarantees.
 *
 * The snapshot is the rollback artifact for the whole rebuild, so the property
 * that matters is not "the exporter runs" but "the verifier FAILS when the
 * snapshot is wrong". These tests tamper with a snapshot nine ways and assert
 * each one is caught — a verifier that has never failed proves nothing.
 *
 * The checksum helpers are pure, so they are exercised directly rather than by
 * shelling out to the CLI (which the harness cannot do portably).
 */
declare(strict_types=1);

// The tool is a CLI script that would execute on include; pull in just the pure
// helpers it defines by evaluating the function bodies we need. They ARE
// duplicated, once and on purpose: export-web.php must be uploadable as a single
// file to a host with no shell, so it cannot include them. The group below
// requires the two copies to be identical, because a snapshot taken by one and
// verified by the other must agree byte for byte.
if (!function_exists('mam_export_row_hash')) {
    $src = file_get_contents(dirname(__DIR__) . '/tools/export.php');
    // Extract the two pure functions, so requiring the CLI's side effects is
    // unnecessary. If either is renamed or removed this throws and the test
    // fails loudly rather than silently testing a stale copy.
    foreach (['mam_export_row_hash', 'mam_export_checksum'] as $fn) {
        if (!preg_match('/\nfunction ' . preg_quote($fn, '/') . '\(.*?\n\}/s', $src, $m)) {
            throw new RuntimeException("export.php no longer defines $fn — update test_export.php");
        }
        eval($m[0]);
    }
}

t_group('ETL export — checksum properties');

$rows = [
    ['id' => 1, 'name' => 'Ada',  'score' => 88,   'note' => null],
    ['id' => 2, 'name' => 'Femi', 'score' => 52,   'note' => 'retake'],
    ['id' => 3, 'name' => 'Chi',  'score' => 100,  'note' => null],
];
$base = mam_export_checksum($rows);
t_ok(strlen($base) === 64, 'the checksum is a sha256 digest');
t_eq(mam_export_checksum($rows), $base, 'it is deterministic across calls');

// A snapshot is lossless if it holds the same SET of rows. Row order can
// legitimately differ between a source table and a re-imported copy, so order
// must NOT change the digest, or every verification would fail spuriously.
$shuffled = [$rows[2], $rows[0], $rows[1]];
t_eq(mam_export_checksum($shuffled), $base, 'row ORDER does not change the checksum');

// Column order must not either — that would make the digest about DDL, not data.
$reordered = array_map(fn($r) => ['note' => $r['note'], 'score' => $r['score'], 'name' => $r['name'], 'id' => $r['id']], $rows);
t_eq(mam_export_checksum($reordered), $base, 'column ORDER does not change the checksum');

t_group('ETL export — every corruption is detected');

$mut = function (callable $fn) use ($rows) { $c = $rows; $fn($c); return mam_export_checksum($c); };

t_ok($mut(function (&$r) { array_pop($r); }) !== $base,          'a deleted row changes the checksum');
t_ok($mut(function (&$r) { $r[] = ['id' => 4, 'name' => 'New', 'score' => 1, 'note' => null]; }) !== $base,
     'an added row changes the checksum');
t_ok($mut(function (&$r) { $r[0]['name'] = 'TAMPERED'; }) !== $base, 'an edited field changes the checksum');

// The one that a re-import alone CANNOT catch: SQLite column affinity coerces
// the string "88" back to the integer 88 on insert, so a restore-only check
// reports a false pass. transform.php consumes the JSON directly, where a
// stringified integer compares differently — so the file itself is checksummed.
t_ok($mut(function (&$r) { $r[0]['score'] = '88'; }) !== $base,
     'an integer stringified to "88" changes the checksum (affinity would hide this on re-import)');
t_ok($mut(function (&$r) { $r[0]['note'] = ''; }) !== $base,
     'a NULL flattened to an empty string changes the checksum');
t_ok($mut(function (&$r) { $r[0]['id'] = false; }) !== $base,
     'an integer coerced to a boolean changes the checksum');
t_ok($mut(function (&$r) { $r[0]['score'] = 88.0; }) !== $base,
     'an integer widened to a float changes the checksum');

// Whitespace and case are content, not formatting.
t_ok($mut(function (&$r) { $r[0]['name'] = 'Ada '; }) !== $base, 'a trailing space changes the checksum');
t_ok($mut(function (&$r) { $r[0]['name'] = 'ada'; })  !== $base, 'a case change changes the checksum');

t_group('ETL export — edge cases');

t_eq(mam_export_checksum([]), mam_export_checksum([]), 'an empty table has a stable checksum');
t_ok(mam_export_checksum([]) !== $base, 'and it differs from a populated one');

// Unicode and slashes must survive verbatim — a name is identity data.
$uni = [['id' => 1, 'name' => "Chidinma Ezé", 'url' => 'https://a/b']];
t_eq(mam_export_checksum($uni), mam_export_checksum($uni), 'unicode + slashes hash stably');
t_ok(mam_export_checksum($uni) !== mam_export_checksum([['id' => 1, 'name' => 'Chidinma Eze', 'url' => 'https://a/b']]),
     'an accent stripped from a name is detected');

// Two different tables with identical content SHOULD share a checksum — the
// digest describes rows, and the manifest keys it by table name.
t_eq(mam_export_checksum($rows), mam_export_checksum($shuffled), 'identical content hashes identically');

t_group('ETL export — the CLI and web tools cannot drift apart');

// A snapshot taken through the browser must verify with the CLI tool. That only
// holds if both files hash rows identically, so compare the function bodies
// mechanically rather than trusting anyone to keep them in step by hand.
$cli = file_get_contents(dirname(__DIR__) . '/tools/export.php');
$web = file_get_contents(dirname(__DIR__) . '/tools/export-web.php');
t_ok($web !== false && $web !== '', 'the web export tool exists');

// Compare BEHAVIOUR, not prose: comments may legitimately differ between the
// two files, but a single changed operator would break cross-verification.
$normalise = function (string $body): string {
    $b = preg_replace('~/\*.*?\*/~s', '', $body);   // block comments
    $b = preg_replace('~//.*$~m', '', $b);            // line comments
    return trim(preg_replace('/\s+/', ' ', $b));
};
foreach (['mam_export_row_hash', 'mam_export_checksum', 'mam_export_quote_ident'] as $fn) {
    $re = '/\nfunction ' . preg_quote($fn, '/') . '\(.*?\n\}/s';
    preg_match($re, $cli, $a);
    preg_match($re, $web, $b);
    t_ok(!empty($a[0]) && !empty($b[0]), "$fn is defined in both tools");
    t_eq($normalise((string) ($b[0] ?? 'B')), $normalise((string) ($a[0] ?? 'A')),
         "$fn behaves identically in both tools (a snapshot taken in the browser must verify on the CLI)");
}

// The web tool must ship locked: an unset token means anyone who finds the URL
// gets the database.
t_ok((bool) preg_match("/const MAM_EXPORT_TOKEN = '';/", $web),
     'the web tool ships with an EMPTY token so it refuses until configured');
t_ok(strpos($web, 'hash_equals') !== false, 'and compares the token with hash_equals');
t_ok(strpos($web, 'Refusing to send a full database export over plain HTTP') !== false,
     'and refuses to transmit the database over plain HTTP');

// Neither tool may reach the application, whose mam_db() runs the migrator.
foreach (['export.php' => $cli, 'export-web.php' => $web] as $name => $src) {
    $code = preg_replace('~/\*.*?\*/~s', '', $src);
    $code = preg_replace('~^\s*//.*$~m', '', $code);
    t_ok(strpos($code, 'bootstrap.php') === false, "$name never loads bootstrap.php");
    t_ok(strpos($code, 'mam_db(') === false, "$name never calls mam_db() (which would migrate)");
}

t_group('ETL export — the web tool can actually be reached');

// The bug this catches shipped: the tool's own instructions told the operator
// to upload it to api/tools/, which carries `Require all denied`. Apache
// answered 403 before PHP ran, so a correct script was unreachable and the
// failure looked like a permissions problem with the host.
$repo   = dirname(__DIR__, 2);
$denied = [];
foreach (['api/tools', 'api/lib', 'api/routes', 'api/tests', 'api/bin', 'api/storage', 'api/logs', 'docs'] as $dir) {
    $ht = $repo . '/' . $dir . '/.htaccess';
    if (is_file($ht) && strpos((string) file_get_contents($ht), 'Require all denied') !== false) $denied[] = $dir;
}
t_ok(in_array('api/tools', $denied, true),
     'api/tools/ is web-denied (the cron runner lives there — this must stay true)');

// So the instructions must not name any denied directory as the upload target.
$doc = (string) file_get_contents(dirname(__DIR__) . '/tools/export-web.php');
$head = substr($doc, 0, (int) strpos($doc, 'declare(strict_types=1)'));
// A denied directory may be MENTIONED freely — the header explains why
// api/tools/ cannot serve the tool, and names `php api/tools/export.php` as a
// CLI command, which is fine. What must never appear is an instruction to
// FETCH a URL under one, or to UPLOAD the tool into one. That is the exact
// sentence that shipped and produced a 403.
foreach ($denied as $dir) {
    $bad = [];
    foreach (explode("\n", $head) as $line) {
        if (strpos($line, $dir) === false) continue;
        $isUrl    = (bool) preg_match('~https?://[^\s]*' . preg_quote($dir, '~') . '~', $line);
        $isUpload = (bool) preg_match('/\b(Upload|upload)\b/', $line)
                    && !preg_match('/\b(NOT|not|never)\b/', $line);
        if ($isUrl || $isUpload) $bad[] = trim($line, " *\t");
    }
    t_eq(count($bad), 0, "the instructions never point a BROWSER at $dir/"
        . ($bad ? ': ' . implode(' | ', $bad) : ''));
}
t_ok(strpos($head, 'dbexport') !== false, 'they name a web-servable folder instead');
t_ok(strpos($head, 'Require all denied') !== false,
     'and explain WHY api/tools/ cannot work, so nobody re-files it there');

// The companion .htaccess must exist and must undo the site-wide Cache-Control.
// mod_headers runs after PHP, so without it the parent rule overwrites the
// script's `no-store` and invites a proxy to keep the database for 5 minutes.
$comp = $repo . '/api/tools/export-web.htaccess';
t_ok(is_file($comp), 'the companion .htaccess ships beside the tool');
$c = is_file($comp) ? (string) file_get_contents($comp) : '';
t_ok(strpos($c, 'Header unset Cache-Control') !== false, 'it clears the inherited Cache-Control');
t_ok((bool) preg_match('/Header always set Cache-Control "no-store/', $c), '…and replaces it with no-store');
t_ok(strpos($c, 'Options -Indexes') !== false, 'and the drop folder cannot be browsed');

// The site-wide rule it is defending against must still be the reason it exists
// — if that rule is ever removed, this comment should stop claiming it.
$root = (string) file_get_contents($repo . '/.htaccess');
$cachePos = strpos($root, 'Header set Cache-Control "public, max-age=300');
t_ok($cachePos !== false && strpos(substr($root, max(0, $cachePos - 120), 120), '(html|php)') !== false,
     'the site-wide Cache-Control on *.php is still there (the reason the companion is needed)');

// Path resolution must not assume the file sits in api/tools/, since that is
// the one place it can never be served from.
$code = preg_replace('~/\*.*?\*/~s', '', $doc);
t_ok(strpos($code, "__DIR__ . '/..'") === false || strpos($code, 'mam_export_api_root') !== false,
     'the api/ directory is discovered, not assumed from a fixed depth');
t_ok(strpos($code, 'mam_export_api_root') !== false, 'a probe function does the discovery');

t_group('ETL export — the browser export computes exactly what the CLI computes');

$EX_body = static function (string $src, string $fn): ?string {
    if (!preg_match('/\nfunction ' . preg_quote($fn, '/') . '\(.*?\n\}/s', $src, $m)) return null;
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $m[0]);
    return preg_replace('/\s+/', '', (string) $code);
};
$EX_cli = (string) file_get_contents(dirname(__DIR__) . '/tools/export.php');
$EX_web = (string) file_get_contents(dirname(__DIR__) . '/tools/export-web.php');
foreach (['mam_export_row_hash', 'mam_export_checksum', 'mam_export_quote_ident', 'mam_export_env'] as $EX_fn) {
    $a = $EX_body($EX_cli, $EX_fn); $b = $EX_body($EX_web, $EX_fn);
    t_ok($a !== null && $b !== null, "both exporters define $EX_fn");
    t_eq($b, $a, "$EX_fn is the same code in export-web.php as in export.php — a snapshot from one must verify with the other");
}
