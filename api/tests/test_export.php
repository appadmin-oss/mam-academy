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
// helpers it defines by evaluating the function bodies we need. They are
// duplicated nowhere — this asserts against the real definitions.
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
