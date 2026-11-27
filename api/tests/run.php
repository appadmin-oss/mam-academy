<?php
/**
 * Tiny zero-dependency test runner for the MAM API.
 *
 * Boots the library layer against a throwaway SQLite DB (no Composer, no
 * PHPUnit — matches the project's buildless philosophy) and runs every
 * api/tests/test_*.php. Exits non-zero on any failure so CI fails the build.
 *
 *   php api/tests/run.php
 */
declare(strict_types=1);

// CLI only. Refuse to run over HTTP so `GET /api/tests/run.php` can't execute
// the suite (info disclosure / remote code execution of the harness) on any
// host that serves this directory despite the .htaccess deny.
if (PHP_SAPI !== 'cli') { http_response_code(403); header('Content-Type: text/plain'); echo "Forbidden: CLI only.\n"; exit(1); }

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$ROOT = dirname(__DIR__);                       // .../api
define('MAM_API_ROOT', $ROOT);
$tmp = sys_get_temp_dir() . '/mam-tests-' . getmypid() . '-' . bin2hex(random_bytes(3));
@mkdir($tmp, 0775, true);
@mkdir($tmp . '/logs', 0775, true);
define('MAM_STORAGE', $tmp);
define('MAM_LOGS', $tmp . '/logs');
register_shutdown_function(function () use ($tmp) { @array_map('unlink', glob("$tmp/logs/*") ?: []); @array_map('unlink', glob("$tmp/*") ?: []); @rmdir("$tmp/logs"); @rmdir($tmp); });

// ---- Settable env (tests can inject keys/secrets) --------------------------
$GLOBALS['__env'] = ['APP_KEY' => str_repeat('k', 48)]; // deterministic CSRF key
// The suite runs against SQLite by default. Setting DB_DSN in the PROCESS
// environment runs the whole of it against a real MySQL/MariaDB instead, which
// is the only way the engine differences show up — three schema defects were
// invisible on SQLite and cost 32 tables on a MariaDB host. CI stays on SQLite;
// this is the switch for running it against the production engine deliberately.
foreach (['DB_DSN', 'DB_USERNAME', 'DB_PASSWORD'] as $__k) {
    $__v = getenv($__k);
    if ($__v !== false && $__v !== '') $GLOBALS['__env'][$__k] = $__v;
}
function mam_env($key, $default = null) { return $GLOBALS['__env'][$key] ?? $default; }
function mam_test_env(array $kv) { foreach ($kv as $k => $v) $GLOBALS['__env'][$k] = $v; }

// ---- Stubs the libs expect from bootstrap (kept harmless for tests) --------
// mam_fail/mam_ok normally exit; here they THROW so an unexpected error path
// fails a test loudly instead of silently killing the runner.
function mam_fail($error, $status = 400, $extra = []) { throw new RuntimeException("mam_fail:$error:$status"); }
function mam_ok($data = [], $status = 200) { throw new RuntimeException('mam_ok:unexpected'); }
function mam_log(...$a) {}
function mam_audit(...$a) {}
function mam_client_ip() { return '127.0.0.1'; }
// Real impl (bootstrap.php) defers to after the HTTP response; in tests we
// no-op so mint-trigger helpers that queue an email (cards.php) are exercisable
// without attempting to send mail.
if (!function_exists('mam_after_response')) { function mam_after_response(callable $fn): void {} }

// ---- Library under test ----------------------------------------------------
// The one list (resolved from the module manifests by api/modules/libs.php),
// minus the two libraries stubbed above — their real versions write to disk
// and end the request.
foreach (array_diff_key(require $ROOT . '/modules/libs.php', ['log' => 1, 'response' => 1]) as $__lib => $__path) {
    require_once $__path;
}

require __DIR__ . '/assert.php';

echo "MAM API test suite (PHP " . PHP_VERSION . ")\n\n";
/*
 * Files that introspect the SQLite schema directly (`sqlite_master`, `PRAGMA
 * table_info`) are skipped when the suite is pointed at MySQL. They assert the
 * SHAPE of the shipped DDL, which is a different question from whether the
 * libraries behave the same on both engines — and that second question is the
 * one a MySQL run exists to answer.
 */
$__sqliteOnly = ['test_admin_screens.php', 'test_card_expiry.php', 'test_dataprotection.php',
                 'test_fees.php', 'test_guardian_comms.php', 'test_inline_edit.php',
                 'test_payment_enrol.php', 'test_schema_mysql.php', 'test_schema_v2.php',
                 'test_v2_clarity.php', 'test_v2_load.php', 'test_v2_plan.php',
                 'test_v2_readmodel.php', 'test_vetting.php'];
$__driver = 'sqlite';
try { $__driver = (string) mam_db()->getAttribute(PDO::ATTR_DRIVER_NAME); } catch (Throwable $e) {}
$__skipped = [];
foreach (glob(__DIR__ . '/test_*.php') as $f) {
    if ($__driver !== 'sqlite' && in_array(basename($f), $__sqliteOnly, true)) {
        $__skipped[] = basename($f);
        continue;
    }
    require $f;
}
if ($__skipped) {
    echo "\n(driver: $__driver — skipped " . count($__skipped) . " SQLite-schema file(s): "
       . implode(', ', $__skipped) . ")\n";
}
mam_test_summary();
