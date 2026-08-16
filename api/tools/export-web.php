<?php
/**
 * MAM Academy — ETL Layer 1: browser-triggered export (no SSH required).
 * ─────────────────────────────────────────────────────────────
 * Produces exactly the same artifact as `php api/tools/export.php` — the same
 * per-table JSON files and the same checksummed manifest — but for hosts where
 * there is no shell. You upload this one file, hit it once in a browser, get a
 * .zip, and DELETE IT.
 *
 * ── How to use ──
 *  1. Set MAM_EXPORT_TOKEN below to a long random string (30+ chars). It ships
 *     empty and the script refuses to run until you change it.
 *  2. Upload to api/tools/export-web.php (File Manager, FTP, whatever you have).
 *  3. Visit  https://your-site/api/tools/export-web.php?token=THE_TOKEN
 *  4. Save the .zip it downloads.
 *  5. DELETE THIS FILE FROM THE SERVER. It dumps the entire database; leaving
 *     it in place leaves a copy of every user, payment and audit row one URL
 *     away from anyone who learns the token.
 *
 * ── Safety properties, and why each one is here ──
 *  • Read-only. It opens its own PDO and never loads the application, so the
 *    migrator cannot run. `mam_db()` migrates on every connection — including
 *    MySQL — so a tool that loaded the app would upgrade the schema as a side
 *    effect of snapshotting it, which is the exact accident a pre-migration
 *    snapshot exists to prevent.
 *  • Nothing is written to the web root, or to disk at all beyond one temp file
 *    that is unlinked before the response ends. The snapshot never sits in a
 *    directory somebody could fetch.
 *  • HTTPS is required. This response is a full copy of the database; sending
 *    it over plain HTTP would hand it to anything on the path.
 *  • The token is compared with hash_equals, and a missing/short token is
 *    refused rather than warned about.
 *
 * The artifact is identical to the CLI tool's, so it verifies the same way:
 *     unzip the download, then
 *     php api/tools/export.php --verify-only=/path/to/unzipped
 */
declare(strict_types=1);

/* ── 1. SET THIS ───────────────────────────────────────────────────────────
   A long random string. On a Mac/Linux box: openssl rand -hex 24
   Leave it empty and this script will refuse to do anything.            */
const MAM_EXPORT_TOKEN = '';

/* ──────────────────────────────────────────────────────────────────────── */

const MAM_EXPORT_API_ROOT = __DIR__ . '/..';

/** Fail closed, in plain text, without leaking anything about the install. */
function mam_web_deny(string $why, int $code = 403): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "$why\n";
    exit;
}

$token = (string) MAM_EXPORT_TOKEN;
if (strlen($token) < 20) {
    mam_web_deny(
        "This export tool is not configured.\n\n"
        . "Open api/tools/export-web.php and set MAM_EXPORT_TOKEN to a long random\n"
        . "string (30+ characters), then reload this page.",
        503
    );
}

// Behind Cloudflare the origin often speaks plain HTTP, so trust the forwarded
// protocol as well as the direct one — but require that ONE of them says TLS.
$https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
      || (($_SERVER['SERVER_PORT'] ?? '') === '443')
      || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
      || (strtolower((string) ($_SERVER['HTTP_CF_VISITOR'] ?? '')) !== '' && str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https'));
if (!$https) {
    mam_web_deny("Refusing to send a full database export over plain HTTP. Use https://", 400);
}

$given = (string) ($_GET['token'] ?? '');
if ($given === '' || !hash_equals($token, $given)) {
    // Deliberately identical to a "not found" so the file's presence is not
    // confirmed to someone guessing.
    mam_web_deny("Not found", 404);
}

/* ── configuration + connection (standalone: never loads the app) ───────── */

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
    if (!is_file($path)) mam_web_deny("No DB_DSN configured and no SQLite database found.", 500);
    return new PDO('sqlite:' . $path, null, null, $opts);
}

/* ── checksum helpers ───────────────────────────────────────────────────────
   These MUST stay byte-identical to the definitions in export.php, or a
   snapshot taken here would not verify there. test_export.php asserts that
   mechanically rather than trusting anyone to remember.                    */

function mam_export_row_hash(array $row): string {
    ksort($row);
    return hash('sha256', (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
}

function mam_export_checksum(array $rows): string {
    $hashes = array_map('mam_export_row_hash', $rows);
    sort($hashes, SORT_STRING);
    return hash('sha256', implode("\n", $hashes));
}

function mam_export_quote_ident(PDO $db, string $name): string {
    return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? '`' . str_replace('`', '``', $name) . '`'
        : '"' . str_replace('"', '""', $name) . '"';
}

/* ── build the snapshot in memory, stream it as a zip ───────────────────── */

@set_time_limit(0);
@ini_set('memory_limit', '512M');

try {
    $db     = mam_export_connect();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    $sql = $driver === 'sqlite'
        ? "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        : "SELECT table_name AS name FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name";
    $tables = array_map(fn($r) => (string) $r['name'], $db->query($sql)->fetchAll(PDO::FETCH_ASSOC));

    $schemaVersion = 0;
    try {
        $r = $db->query('SELECT MAX(version) AS v FROM schema_version')->fetch(PDO::FETCH_ASSOC);
        $schemaVersion = (int) ($r['v'] ?? 0);
    } catch (Throwable $e) { /* exportable regardless */ }

    if (!class_exists('ZipArchive')) {
        mam_web_deny("ZipArchive is not available on this host — tell the developer, a JSON fallback is needed.", 500);
    }

    $tmp = tempnam(sys_get_temp_dir(), 'mamexp');
    if ($tmp === false) mam_web_deny("Could not create a temporary file.", 500);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) mam_web_deny("Could not create the archive.", 500);

    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
    $manifest  = [
        'format_version' => 1,
        'exported_at'    => date('c'),
        'driver'         => $driver,
        'schema_version' => $schemaVersion,
        'app_version'    => null,
        'source'         => 'web',
        'tables'         => [],
    ];
    $totalRows = 0;

    foreach ($tables as $table) {
        $rows = $db->query('SELECT * FROM ' . mam_export_quote_ident($db, $table))->fetchAll(PDO::FETCH_ASSOC);
        $zip->addFromString($table . '.json', (string) json_encode($rows, $jsonFlags));
        $manifest['tables'][$table] = [
            'rows'     => count($rows),
            'checksum' => mam_export_checksum($rows),
            'columns'  => $rows ? array_keys($rows[0]) : [],
            'file'     => $table . '.json',
        ];
        $totalRows += count($rows);
    }

    // DDL travels with the snapshot so it can be verified standalone later.
    $ddl = [];
    if ($driver === 'sqlite') {
        foreach ($db->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL")
                    ->fetchAll(PDO::FETCH_ASSOC) as $t) {
            if (isset($manifest['tables'][$t['name']])) $ddl[(string) $t['name']] = (string) $t['sql'];
        }
    } else {
        foreach (array_keys($manifest['tables']) as $t) {
            try {
                $r = $db->query('SHOW CREATE TABLE ' . mam_export_quote_ident($db, $t))->fetch(PDO::FETCH_ASSOC);
                if ($r) $ddl[$t] = (string) ($r['Create Table'] ?? '');
            } catch (Throwable $e) { /* evidence only */ }
        }
    }
    $manifest['ddl']              = $ddl;
    $manifest['restore_verified'] = ($driver === 'sqlite');
    $manifest['total_rows']       = $totalRows;
    $zip->addFromString('manifest.json', (string) json_encode($manifest, $jsonFlags | JSON_PRETTY_PRINT));
    $zip->close();

    $name = 'mam-export-' . date('Ymd-His') . '-v' . $schemaVersion . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . (string) filesize($tmp));
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow');
    readfile($tmp);
    @unlink($tmp);   // the snapshot must not outlive the response
    exit;
} catch (Throwable $e) {
    if (!empty($tmp) && is_string($tmp)) @unlink($tmp);
    // The message can name a table or column; that is fine for an operator
    // holding the token, and it is what makes a failure diagnosable at all.
    mam_web_deny("Export failed: " . $e->getMessage(), 500);
}
