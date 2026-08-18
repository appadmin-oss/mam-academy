<?php
/**
 * ETL — emit (a plan → files somebody else can load).
 * ─────────────────────────────────────────────────────────────
 * `plan.php` decides what the v2 dataset is. This renders it, because the load
 * is not always ours to run: the schema may be applied by a DBA, by a hosting
 * panel's import tool, or by a pipeline that only speaks `.sql`. So the output
 * is a plain file with every foreign key already resolved.
 *
 * Two artefacts, deliberately:
 *   **SQL** — what gets loaded. Ordered so no statement references a row that
 *   does not exist yet, which means it needs no deferred constraints and no
 *   `FOREIGN_KEY_CHECKS = 0`. Turning constraint checking off during a load is
 *   how a migration ends up with orphans nobody notices until a JOIN goes
 *   missing; if the order is right it is never needed.
 *   **JSON** — what gets reviewed. One file per table, plus the coverage ledger
 *   and the review list, so a person can read the mapping without executing it.
 *
 * ── The dialect matters ──
 * MySQL treats `\` inside a string literal as an escape character; SQLite does
 * not. So the SAME bytes need different quoting for the two engines, and a
 * single "portable" quoter is quietly wrong on one of them: a Windows path or a
 * regex in a settings value would come out mangled, or worse, would terminate
 * the literal. There are two quoters, chosen by `--dialect`.
 *
 * Nothing here re-decides anything. If the emitter is making a choice about
 * content, it is in the wrong file.
 */
declare(strict_types=1);

/** Columns the emitter derives rather than copies, keyed by table. */
const MAM_V2_EMIT_DERIVED = ['audit_log' => ['ip_text' => 'ip']];

/** Values that are JSON columns and must be encoded, keyed by table. */
const MAM_V2_EMIT_JSON = [
    'applications'  => ['payload'],
    'payments'      => ['metadata'],
    'audit_log'     => ['before_state', 'after_state', 'metadata'],
    'tenants'       => ['branding', 'contact'],
    'centres'       => ['address'],
];

/**
 * A SQL string literal.
 *
 * MySQL: double the backslash as well as the quote, and use the hex form for
 * anything with a NUL — a NUL inside a quoted literal truncates the statement
 * in some clients rather than erroring, which is the worst possible outcome.
 * SQLite: doubling the quote is the whole of the escaping, and doubling a
 * backslash would insert a second one.
 */
function mam_v2_emit_quote(string $v, string $dialect): string
{
    if (str_contains($v, "\0")) {
        return $dialect === 'sqlite' ? "x'" . bin2hex($v) . "'" : "0x" . bin2hex($v);
    }
    if ($dialect === 'sqlite') return "'" . str_replace("'", "''", $v) . "'";
    return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $v) . "'";
}

/**
 * `192.0.2.1` → the packed 4 or 16 bytes v2's `VARBINARY(16)` holds, as a
 * dialect-appropriate blob literal. An unparseable address becomes NULL rather
 * than being stored as text: a column that sometimes holds packed bytes and
 * sometimes holds a string cannot be queried by either.
 */
function mam_v2_emit_ip(?string $text, string $dialect): ?string
{
    $t = trim((string) $text);
    if ($t === '') return null;
    $packed = @inet_pton($t);
    if ($packed === false) return null;
    return $dialect === 'sqlite' ? "x'" . bin2hex($packed) . "'" : "0x" . bin2hex($packed);
}

/** One value → its SQL literal. */
function mam_v2_emit_value(mixed $v, string $dialect, bool $isJson = false): string
{
    if ($v === null) return 'NULL';
    if ($isJson) {
        if ($v === [] || $v === '') return 'NULL';
        $s = is_string($v) ? $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return mam_v2_emit_quote($s, $dialect);
    }
    if (is_bool($v)) return $v ? '1' : '0';
    if (is_int($v)) return (string) $v;
    if (is_float($v)) return rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
    if (is_array($v)) return mam_v2_emit_quote((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $dialect);
    return mam_v2_emit_quote((string) $v, $dialect);
}

/**
 * A table's rows → INSERT statements.
 *
 * Multi-row inserts, batched, because 232 single-statement inserts is slow on a
 * shared host and one statement of 232 rows is a single round trip. Batched
 * rather than unbounded so a value nobody expected cannot produce a statement
 * larger than `max_allowed_packet`, which fails as a connection reset and reads
 * like a network problem.
 */
function mam_v2_emit_table(string $table, array $rows, string $dialect, int $batch = 100): string
{
    if (!$rows) return '';
    $derived = MAM_V2_EMIT_DERIVED[$table] ?? [];
    $jsonCols = MAM_V2_EMIT_JSON[$table] ?? [];

    // The column list comes from the FIRST row and every row is emitted against
    // it, so a row with a different shape is a hard error rather than a silently
    // shifted set of values.
    $cols = [];
    foreach (array_keys($rows[0]) as $c) $cols[] = $derived[$c] ?? $c;

    $out = '';
    foreach (array_chunk($rows, $batch) as $chunk) {
        $values = [];
        foreach ($chunk as $r) {
            $lit = [];
            foreach (array_keys($rows[0]) as $c) {
                if (!array_key_exists($c, $r)) {
                    throw new RuntimeException("emit: $table row is missing column '$c'");
                }
                $lit[] = isset($derived[$c]) && $derived[$c] === 'ip'
                    ? (mam_v2_emit_ip(is_string($r[$c]) ? $r[$c] : null, $dialect) ?? 'NULL')
                    : mam_v2_emit_value($r[$c], $dialect, in_array($derived[$c] ?? $c, $jsonCols, true));
            }
            $values[] = '  (' . implode(', ', $lit) . ')';
        }
        $out .= 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ") VALUES\n"
              . implode(",\n", $values) . ";\n";
    }
    return $out;
}

/**
 * The whole plan → one SQL script.
 *
 * Wrapped in a transaction so a failure leaves nothing behind: a half-loaded
 * database looks populated and reconciles against nothing, which is worse than
 * an empty one. (MySQL's DDL is not transactional, which is exactly why the
 * schema is applied separately from the data — this file only inserts.)
 */
function mam_v2_emit_sql(array $plan, array $opts = []): string
{
    $dialect = (string) ($opts['dialect'] ?? 'mysql');
    if (!in_array($dialect, ['mysql', 'sqlite'], true)) {
        throw new InvalidArgumentException("emit: unknown dialect '$dialect'");
    }
    $stamp = (string) ($opts['stamp'] ?? '');
    $h = [];
    $h[] = '-- MAM Academy — v1 → v2 data load.';
    $h[] = '-- Generated by api/tools/v2-transform.php. Dialect: ' . $dialect . '.';
    if ($stamp !== '') $h[] = '-- Source export: ' . $stamp;
    $h[] = '--';
    $h[] = '-- ============================================================';
    $h[] = '--  APPLY db/schema-v2' . ($dialect === 'sqlite' ? '-sqlite' : '') . '.sql INTO THIS DATABASE FIRST,';
    $h[] = '--  THEN THIS FILE. It inserts rows; it creates no tables.';
    $h[] = '--';
    $h[] = "--  If you see:  ERROR 1146 - Table '...tenants' doesn't exist";
    $h[] = '--  that means exactly one thing: the schema step was skipped, or';
    $h[] = '--  it ran against a different database. Nothing is wrong with this';
    $h[] = '--  file and nothing was written. Apply the schema, then run this.';
    $h[] = '-- ============================================================';
    $h[] = '--';
    $h[] = '-- The schema seeds `currencies` and `schema_version` itself, so this file does not.';
    $h[] = '-- Statements are ordered so every foreign key already exists: do NOT disable';
    $h[] = '-- constraint checking to load it. If it needs that, something is wrong.';
    $h[] = '--';
    foreach ($plan['counts'] as $t => $n) if ($n > 0) $h[] = sprintf('--   %-22s %6d rows', $t, $n);
    $h[] = '';

    $body = '';
    if ($dialect === 'mysql') {
        $body .= "SET NAMES utf8mb4;\nSET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';\n"
               . "START TRANSACTION;\n\n";
    } else {
        $body .= "PRAGMA foreign_keys = ON;\nBEGIN;\n\n";
    }
    foreach ($plan['order'] as $table) {
        $rows = (array) ($plan['tables'][$table] ?? []);
        if (!$rows) continue;
        $body .= '-- ' . $table . ' (' . count($rows) . ")\n";
        $body .= mam_v2_emit_table($table, $rows, $dialect, (int) ($opts['batch'] ?? 100));
        $body .= "\n";
    }
    $body .= "COMMIT;\n";
    return implode("\n", $h) . $body;
}

/**
 * The plan → a directory of files.
 *
 * Returns the manifest it wrote. The JSON is the reviewable half and is written
 * per table so a diff between two transforms is readable; `_coverage.json` and
 * `_review.json` are what a person should actually read first.
 */
function mam_v2_emit_dir(array $plan, string $dir, array $opts = []): array
{
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("emit: cannot create $dir");
    }
    $written = [];
    $put = function (string $name, string $body) use ($dir, &$written): void {
        $path = rtrim($dir, '/') . '/' . $name;
        if (file_put_contents($path, $body) === false) throw new RuntimeException("emit: cannot write $path");
        // The output is derived from a production export: identity, payment and
        // audit rows. Owner-only, in the same spirit as the export itself.
        @chmod($path, 0600);
        $written[$name] = strlen($body);
    };
    $json = static fn(mixed $v): string => (string) json_encode($v,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    foreach ($plan['order'] as $table) {
        $rows = (array) ($plan['tables'][$table] ?? []);
        if (!$rows) continue;
        $put($table . '.json', $json($rows));
    }
    $put('_coverage.json', $json($plan['coverage']));
    $put('_review.json',   $json($plan['review']));
    $put('_rejected.json', $json($plan['rejected']));

    $manifest = [
        'generated_by'  => 'api/tools/v2-transform.php',
        'dialect'       => (string) ($opts['dialect'] ?? 'mysql'),
        'source_export' => (string) ($opts['stamp'] ?? ''),
        'counts'        => $plan['counts'],
        'money'         => $plan['money'],
        'identity'      => array_diff_key($plan['identity'], ['review' => 1, 'conflicts' => 1]),
        'review_items'  => count($plan['review']),
        'rejected_rows' => count($plan['rejected']),
        'tables_out'    => count(array_filter($plan['counts'])),
        'files'         => $written,
    ];
    $put('_manifest.json', $json($manifest));
    return $manifest;
}
