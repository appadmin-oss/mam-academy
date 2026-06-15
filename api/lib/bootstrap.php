<?php
/**
 * MAM Academy — API Bootstrap
 *
 * Loaded by every endpoint. Responsible for:
 *  - error handling (no PHP errors leak to client in production)
 *  - .env loading
 *  - CORS
 *  - JSON content type
 *  - autoload of library files
 *  - DB connection (lazy)
 *  - request logging
 */
declare(strict_types=1);

// ---------- Output buffer ----------
// Open a buffer IMMEDIATELY. The shutdown handler below converts any
// uncaught fatal error, exception, or premature exit into a JSON 500 so
// the client NEVER sees a non-JSON body. Status 200 + non-JSON body has
// caused real production outages here — this is the structural fix.
if (!ob_get_level()) ob_start();

// ---------- Constants ----------
define('MAM_API_ROOT', __DIR__ . '/..');
define('MAM_STORAGE',  MAM_API_ROOT . '/storage');
define('MAM_LOGS',     MAM_API_ROOT . '/logs');

// Make absolutely sure the logs + storage dirs exist. If they don't, PHP
// error_log() silently fails and "no log being written" reports become
// undiagnosable. Created with 0775 so the shared-host user can read them
// via FTP/SFTP without an SSH chmod dance.
foreach ([MAM_LOGS, MAM_STORAGE] as $d) {
    if (!is_dir($d)) @mkdir($d, 0775, true);
}

// ---------- Error handling ----------
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', MAM_LOGS . '/php-error.log');
// Mask deprecations (PHP 8.x noise) but keep warnings + errors.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// ---------- Last-resort JSON guarantee ----------
// Three layers — exception, fatal, shutdown. ANY of them can fire; each
// drains every buffer level and writes a clean JSON envelope. The client
// is guaranteed to receive parseable JSON or nothing.
function mam_emit_failure_json(int $status, string $error, string $message): void {
    // Short-circuit if a response was already written. Without this, an
    // exception handler that called us and the shutdown safety-net that
    // ALSO calls us both write JSON envelopes — the client receives the
    // concatenation, JSON.parse fails, and the user sees "couldn't reach
    // our server" with a status:200 + non-JSON body (sometimes 500).
    if (defined('MAM_RESPONSE_SENT')) return;
    define('MAM_RESPONSE_SENT', true);
    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) {
            $leaked = ob_get_clean();
            if (is_string($leaked) && $leaked !== '') {
                @file_put_contents(MAM_LOGS . '/buffer-leak.log',
                    '[' . date('c') . '] FATAL ' . ($_SERVER['REQUEST_URI'] ?? '?') . ' ' . strlen($leaked) . "b: " . mb_substr($leaked, 0, 400) . "\n",
                    FILE_APPEND);
            }
        }
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-MAM-API-Response: ' . $status);
    }
    echo json_encode(['ok' => false, 'error' => $error, 'message' => $message]);
}

set_exception_handler(function (Throwable $e) {
    $msg = $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
    @error_log('[mam-api unhandled] ' . $msg);
    if (function_exists('mam_log')) {
        try { mam_log('error', 'unhandled', ['message' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine(), 'trace' => substr($e->getTraceAsString(), 0, 1500)]); } catch (Throwable $x) {}
    }
    $env = function_exists('mam_env') ? mam_env('APP_ENV', 'production') : 'production';
    mam_emit_failure_json(500, 'server_error', $env === 'production' ? 'Server error' : $msg);
    exit;
});

// Fatal errors (parse errors, OOM, max execution time) bypass the
// exception handler. register_shutdown_function + error_get_last is the
// only way to convert them into a clean JSON response.
register_shutdown_function(function () {
    if (PHP_SAPI === 'cli') return; // CLI scripts (bin/*) manage their own output
    $err = error_get_last();
    $fatal = $err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
    if ($fatal) {
        @error_log('[mam-api fatal] ' . $err['message'] . ' at ' . $err['file'] . ':' . $err['line']);
        $env = function_exists('mam_env') ? mam_env('APP_ENV', 'production') : 'production';
        mam_emit_failure_json(500, 'fatal_error', $env === 'production' ? 'Server crashed handling this request' : ($err['message'] . ' at ' . $err['file'] . ':' . $err['line']));
        return;
    }
    // Defensive: if a route handler returned WITHOUT calling mam_ok/mam_fail,
    // we'd otherwise send a 200 OK with the buffer contents (garbage). Force
    // a JSON envelope so the client always sees valid JSON.
    if (!defined('MAM_RESPONSE_SENT')) {
        mam_emit_failure_json(500, 'no_response', 'Route handler returned without writing a response');
    }
});

// (Old exception handler replaced by the three-layer safety net above.)

/**
 * Run $fn AFTER the client response has been fully delivered. Anything
 * the route handler doesn't strictly need to block on — chiefly SMTP
 * email sends, which can take 1-8 s each — should be wrapped in this.
 *
 * Under PHP-FPM (`fastcgi_finish_request` available) the client
 * connection is closed before $fn runs, so the user perceives the
 * response as instant regardless of how long the deferred work takes.
 *
 * Under Apache mod_php the connection stays open until PHP shuts down,
 * but the response body is already written via mam_json()'s `exit`, so
 * the browser has already rendered success — only the underlying TCP
 * connection lingers, which is invisible to the user.
 */
function mam_after_response(callable $fn): void {
    register_shutdown_function(function () use ($fn) {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            // Best-effort flush for mod_php — close any remaining buffer
            // and force the response out so the browser can render.
            while (ob_get_level() > 0) @ob_end_flush();
            @flush();
        }
        try { $fn(); } catch (Throwable $e) {
            @error_log('[mam after_response] ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        }
    });
}

// ---------- Tiny .env loader ----------
function mam_load_env(string $path): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $env = [];
    if (is_readable($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === '#' || strpos($t, '=') === false) continue;
            $parts = explode('=', $t, 2);
            $env[trim($parts[0])] = trim($parts[1], " \t\n\r\0\x0B\"'");
        }
    }
    return $cache = $env;
}
function mam_env(string $key, $default = null) {
    $env = mam_load_env(MAM_API_ROOT . '/.env');
    // A value in api/.env wins (explicit per-install config). Otherwise fall
    // back to a real process environment variable so deployments that inject
    // config as env vars (PaaS, Docker, cPanel SetEnv, CI) work without a
    // .env file — covers DB_DSN, ADMIN_*, SETUP_TOKEN, mail/payment keys, etc.
    if (array_key_exists($key, $env) && $env[$key] !== '') return $env[$key];
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
    if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    return $default;
}

// ---------- Composer autoload (OPTIONAL) ----------
// If `composer install` has been run (vendor/ exists), load it so optional
// packages — chiefly PHPMailer — become available. The app runs fine
// without it: the mailer falls back to its built-in SMTP client.
$mam_vendor = MAM_API_ROOT . '/vendor/autoload.php';
if (is_file($mam_vendor)) require_once $mam_vendor;

// ---------- Library autoload (manual; no Composer needed) ----------
$mam_libs = ['log', 'response', 'validation', 'db', 'settings', 'fx', 'session', 'academic-session', 'auth', 'mailer', 'csrf', 'rate-limit', 'paystack', 'flutterwave', 'booking-pricing', 'store', 'practice', 'lms', 'ai', 'admissions', 'jobs', 'funnel', 'notifications'];
foreach ($mam_libs as $lib) {
    require_once __DIR__ . '/' . $lib . '.php';
}

// ---------- Response defaults ----------
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

// ---------- CORS ----------
$allowed = array_filter(array_map('trim', explode(',', (string)mam_env('ALLOWED_ORIGINS', ''))));
$reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($reqOrigin && in_array($reqOrigin, $allowed, true)) {
    header("Access-Control-Allow-Origin: $reqOrigin");
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Idempotency-Key, Authorization');
    header('Access-Control-Max-Age: 86400');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

// ---------- Request log ----------
mam_log('info', 'request', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'route' => $_GET['route'] ?? ($_SERVER['REQUEST_URI'] ?? ''),
    'ip' => mam_client_ip(),
]);

// Best-effort session GC
if (function_exists('mam_session_gc')) mam_session_gc();

// ---------- Helpers ----------
function mam_client_ip(): string {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = explode(',', $_SERVER[$h])[0];
            return trim($ip);
        }
    }
    return '0.0.0.0';
}

function mam_json_input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function mam_random_id(string $prefix = 'MAM'): string {
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
}
