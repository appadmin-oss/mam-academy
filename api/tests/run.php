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

// ---- Library under test ----------------------------------------------------
require $ROOT . '/lib/db.php';
require $ROOT . '/lib/settings.php';
require $ROOT . '/lib/fx.php';
require $ROOT . '/lib/validation.php';      // pricing engine lives here
require $ROOT . '/lib/booking-pricing.php';
require $ROOT . '/lib/csrf.php';
require $ROOT . '/lib/paystack.php';
require $ROOT . '/lib/flutterwave.php';
require $ROOT . '/lib/lms.php';
require $ROOT . '/lib/auth.php';            // account provisioning + role checks
require $ROOT . '/lib/store.php';           // store totals (server-authoritative)
require $ROOT . '/lib/mailer.php';          // mail helpers + outbox (record/retry)

require __DIR__ . '/assert.php';

echo "MAM API test suite (PHP " . PHP_VERSION . ")\n\n";
foreach (glob(__DIR__ . '/test_*.php') as $f) { require $f; }
mam_test_summary();
