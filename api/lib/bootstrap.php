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

// ---------- Constants ----------
define('MAM_API_ROOT', __DIR__ . '/..');
define('MAM_STORAGE',  MAM_API_ROOT . '/storage');
define('MAM_LOGS',     MAM_API_ROOT . '/logs');

// ---------- Error handling ----------
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', MAM_LOGS . '/php-error.log');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) {
    // Defensive: mam_log/mam_env may not be loaded if the failure was in
    // the autoload step itself — fall back to error_log + plain output.
    if (function_exists('mam_log')) {
        mam_log('error', 'unhandled', [
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => substr($e->getTraceAsString(), 0, 1500),
        ]);
    } else {
        error_log('[mam] unhandled: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    $env = function_exists('mam_env') ? mam_env('APP_ENV', 'production') : 'production';
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => $env === 'production' ? 'Server error' : $e->getMessage(),
    ]);
    exit;
});

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
    return $env[$key] ?? $default;
}

// ---------- Composer autoload (OPTIONAL) ----------
// If `composer install` has been run (vendor/ exists), load it so optional
// packages — chiefly PHPMailer — become available. The app runs fine
// without it: the mailer falls back to its built-in SMTP client.
$mam_vendor = MAM_API_ROOT . '/vendor/autoload.php';
if (is_file($mam_vendor)) require_once $mam_vendor;

// ---------- Library autoload (manual; no Composer needed) ----------
$mam_libs = ['log', 'response', 'validation', 'db', 'session', 'auth', 'mailer', 'csrf', 'rate-limit', 'paystack', 'flutterwave', 'booking-pricing', 'store'];
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
