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

// ---------- Library autoload (manual; no Composer needed) ----------
$mam_libs = ['log', 'response', 'validation', 'db', 'session', 'auth', 'mailer', 'csrf', 'rate-limit', 'paystack', 'flutterwave'];
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
function mam_cidr_match(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) return $ip === $cidr;
    [$subnet, $bits] = explode('/', $cidr, 2); $bits = (int)$bits;
    $ipB = @inet_pton($ip); $snB = @inet_pton($subnet);
    if ($ipB === false || $snB === false || strlen($ipB) !== strlen($snB)) return false;
    $bytes = intdiv($bits, 8); $rem = $bits % 8;
    if ($bytes > 0 && strncmp($ipB, $snB, $bytes) !== 0) return false;
    if ($rem === 0) return true;
    $mask = chr(0xFF << (8 - $rem) & 0xFF);
    return (($ipB[$bytes] ^ $snB[$bytes]) & $mask) === "\0";
}

/**
 * Real client IP, proxy-aware. CF-Connecting-IP / X-Forwarded-For are honoured
 * ONLY when the direct peer is a Cloudflare edge (or an operator-listed proxy
 * via TRUSTED_PROXIES). Otherwise those headers are attacker-controlled and are
 * ignored — without this, the per-IP rate limiter is reset just by sending a
 * fresh X-Forwarded-For value. Refresh the CF list from cloudflare.com/ips.
 */
function mam_client_ip(): string {
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote === '') return '0.0.0.0';
    static $cf = [
        '173.245.48.0/20','103.21.244.0/22','103.22.200.0/22','103.31.4.0/22',
        '141.101.64.0/18','108.162.192.0/18','190.93.240.0/20','188.114.96.0/20',
        '197.234.240.0/22','198.41.128.0/17','162.158.0.0/15','104.16.0.0/13',
        '104.24.0.0/14','172.64.0.0/13','131.0.72.0/22',
        '2400:cb00::/32','2606:4700::/32','2803:f800::/32','2405:b500::/32',
        '2405:8100::/32','2a06:98c0::/29','2c0f:f248::/32',
    ];
    $trusted = $cf;
    foreach (explode(',', (string)mam_env('TRUSTED_PROXIES', '')) as $c) {
        $c = trim($c); if ($c !== '') $trusted[] = $c;
    }
    $peerTrusted = false;
    foreach ($trusted as $cidr) { if (mam_cidr_match($remote, $cidr)) { $peerTrusted = true; break; } }
    if ($peerTrusted) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', (string)$_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
    }
    return $remote;
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
