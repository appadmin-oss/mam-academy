<?php
/**
 * /api/raw-debug.php?key=DEBUG_TOKEN
 *
 * Bypasses the router and bootstrap so a bootstrap-time failure can't
 * eat the error message. Probes each layer separately and reports back
 * as plain JSON.
 *
 * Hit this directly: https://academy.mamcentre.org/api/raw-debug.php?key=…
 *
 * The file must remain CALLABLE EVEN IF EVERYTHING ELSE IS BROKEN — so
 * it does NOT require bootstrap.php. Every dependency is loaded inside
 * its own try/catch and reported separately.
 *
 * SECURITY: the output discloses server internals (paths, env key names,
 * recent error-log lines), so it is gated by DEBUG_TOKEN from api/.env —
 * parsed here with a minimal reader precisely so the gate still works when
 * bootstrap is broken. No token configured ⇒ the endpoint refuses.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-MAM-Debug: raw');

/* ── Token gate (CLI is always allowed) ── */
if (PHP_SAPI !== 'cli') {
    $debugToken = '';
    $envPath = __DIR__ . '/.env';
    if (is_readable($envPath)) {
        foreach ((file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, 'DEBUG_TOKEN=') === 0) { $debugToken = trim(substr($line, 12), " \t\"'"); break; }
        }
    }
    $given = (string)($_GET['key'] ?? '');
    if ($debugToken === '' || $given === '' || !hash_equals($debugToken, $given)) {
        http_response_code(403);
        echo json_encode([
            'error' => 'forbidden',
            'hint'  => 'Set DEBUG_TOKEN in api/.env and call this endpoint with ?key=<token>. The token gate works even when bootstrap is broken.',
        ]);
        exit;
    }
}

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
