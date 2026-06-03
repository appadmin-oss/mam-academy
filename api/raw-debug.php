<?php
/**
 * /api/raw-debug.php
 *
 * Bypasses the router and bootstrap so a bootstrap-time failure can't
 * eat the error message. Probes each layer separately and reports back
 * as plain JSON.
 *
 * Hit this directly: https://academy.mamcentre.org/api/raw-debug.php
 *
 * The file must remain CALLABLE EVEN IF EVERYTHING ELSE IS BROKEN — so
 * it does NOT require bootstrap.php. Every dependency is loaded inside
 * its own try/catch and reported separately.
 */
declare(strict_types=1);

// ---------- Access gate ----------
// This endpoint deliberately bypasses bootstrap so it stays callable when the
// app is broken — which also means it can't rely on the session/Basic-Auth
// guards. Left open it leaks .env key names, the DB version, internal paths and
// the tail of php-error.log to anyone. So it is gated by a secret: the request
// must carry ?token=<SETUP_TOKEN>, matched in constant time. With no SETUP_TOKEN
// configured it returns 404 (no oracle that the file even exists). Read the
// token straight from the process env or api/.env, since bootstrap isn't loaded.
(function () {
    $token = getenv('SETUP_TOKEN');
    if ($token === false || $token === '') $token = (string)($_ENV['SETUP_TOKEN'] ?? $_SERVER['SETUP_TOKEN'] ?? '');
    if ($token === '') {
        $envPath = __DIR__ . '/.env';
        if (is_readable($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strncmp($line, 'SETUP_TOKEN', 11) !== 0) continue;
                $eq = strpos($line, '=');
                if ($eq !== false) { $token = trim(substr($line, $eq + 1), " \t\"'"); break; }
            }
        }
    }
    $provided = (string)($_GET['token'] ?? '');
    if ($token === '' || !hash_equals($token, $provided)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found";
        exit;
    }
})();

header('Content-Type: application/json; charset=utf-8');
header('X-MAM-Debug: raw');

$out = [
    'php'  => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'time' => date('c'),
    'cwd'  => __DIR__,
    'steps' => [],
];

function step(string $name, callable $fn) {
    global $out;
    $start = microtime(true);
    try {
        $result = $fn();
        $out['steps'][] = ['name' => $name, 'ok' => true, 'ms' => round((microtime(true) - $start) * 1000, 1), 'result' => $result];
    } catch (Throwable $e) {
        $out['steps'][] = [
            'name'    => $name,
            'ok'      => false,
            'ms'      => round((microtime(true) - $start) * 1000, 1),
            'error'   => $e->getMessage(),
            'file'    => $e->getFile() . ':' . $e->getLine(),
            'class'   => get_class($e),
            'trace'   => array_slice(array_map(function ($f) { return ($f['file'] ?? '') . ':' . ($f['line'] ?? '') . ' ' . ($f['function'] ?? ''); }, $e->getTrace()), 0, 5),
        ];
    }
}

// 1. Can we even write to api/storage and api/logs?
step('dirs', function () {
    $logs = __DIR__ . '/logs';
    $store = __DIR__ . '/storage';
    if (!is_dir($logs))  @mkdir($logs,  0775, true);
    if (!is_dir($store)) @mkdir($store, 0775, true);
    return [
        'logs_exists'      => is_dir($logs),
        'logs_writable'    => is_writable($logs),
        'storage_exists'   => is_dir($store),
        'storage_writable' => is_writable($store),
        'logs_path'        => $logs,
        'storage_path'     => $store,
    ];
});

// 2. Read api/.env without going through mam_load_env.
step('env', function () {
    $path = __DIR__ . '/.env';
    if (!is_readable($path)) return ['readable' => false, 'path' => $path];
    $bytes = filesize($path);
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $keys = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $keys[] = substr($line, 0, $eq);
    }
    return ['readable' => true, 'bytes' => $bytes, 'keys' => $keys];
});

// 3. Composer autoload (PHPMailer) — does it explode?
step('composer_autoload', function () {
    $path = __DIR__ . '/vendor/autoload.php';
    if (!is_file($path)) return ['present' => false];
    require_once $path;
    return ['present' => true, 'phpmailer' => class_exists('PHPMailer\\PHPMailer\\PHPMailer')];
});

// 4. Bootstrap — the big one.
step('bootstrap', function () {
    require_once __DIR__ . '/lib/bootstrap.php';
    return ['loaded' => true];
});

// 5. CSRF token — the failing endpoint's core call.
step('csrf_token', function () {
    if (!function_exists('mam_csrf_token')) return ['function_missing' => true];
    $t = mam_csrf_token();
    return ['len' => strlen((string)$t), 'preview' => substr((string)$t, 0, 24)];
});

// 6. Booking tier — second failing endpoint.
step('booking_tier', function () {
    if (!function_exists('mam_booking_tier')) return ['function_missing' => true];
    $t = mam_booking_tier();
    return ['country' => $t['country'] ?? null, 'label' => $t['label'] ?? null];
});

// 7. DB connection (without forcing migration).
step('db_connect', function () {
    if (!function_exists('mam_db')) return ['function_missing' => true];
    $db = mam_db();
    return [
        'driver'  => $db->getAttribute(PDO::ATTR_DRIVER_NAME),
        'version' => $db->getAttribute(PDO::ATTR_SERVER_VERSION),
    ];
});

// 8. Last 30 lines of php-error.log if it exists.
$errLog = __DIR__ . '/logs/php-error.log';
if (is_readable($errLog)) {
    $lines = @file($errLog, FILE_IGNORE_NEW_LINES);
    if ($lines) $out['recent_php_errors'] = array_slice($lines, -30);
} else {
    $out['recent_php_errors'] = 'php-error.log not readable at ' . $errLog;
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
