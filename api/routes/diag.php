<?php
/**
 * GET /api/diag                  — restricted summary (safe to ping)
 * GET /api/diag?key=DEBUG_TOKEN  — full diagnostics (operator)
 *
 * Diagnostic endpoint. The FULL view returns the state of every moving part
 * (env-key presence, dir writability, schema introspection, recent error-log
 * lines) and is gated: a logged-in admin session OR ?key= matching
 * DEBUG_TOKEN in api/.env. Unauthenticated callers get only a minimal,
 * secret-free health summary — error logs can contain stack traces and
 * paths, so they are never served without the gate.
 */
declare(strict_types=1);

$diagAuthorized = false;
try {
    $u = function_exists('mam_current_user') ? mam_current_user() : null;
    if ($u && function_exists('mam_user_has_role') && mam_user_has_role($u, 'admin')) $diagAuthorized = true;
} catch (Throwable $e) { /* session layer down — token can still authorize */ }
$debugToken = (string)mam_env('DEBUG_TOKEN', '');
$givenKey   = (string)($_GET['key'] ?? '');
if (!$diagAuthorized && $debugToken !== '' && $givenKey !== '' && hash_equals($debugToken, $givenKey)) $diagAuthorized = true;

if (!$diagAuthorized) {
    // Minimal, secret-free summary — enough for an uptime probe. Deliberately
    // omits PHP version, DB driver and schema_version: those are fingerprinting
    // aids and are only served behind the gate below.
    $min = ['ok' => true, 'restricted' => true, 'time' => date('c')];
    try { mam_db()->query('SELECT 1'); $min['db'] = 'ok'; }
    catch (Throwable $e) { $min['db'] = 'down'; }
    $min['hint'] = 'Full diagnostics need an admin session or ?key=DEBUG_TOKEN (api/.env).';
    mam_ok($min);
}

$out = [
    'php'    => PHP_VERSION,
    'sapi'   => PHP_SAPI,
    'time'   => date('c'),
    'ob'     => ['level' => ob_get_level()],
    'dirs'   => [
        'logs_writable'    => is_writable(MAM_LOGS),
        'storage_writable' => is_writable(MAM_STORAGE),
    ],
    'env_present' => [
        'APP_KEY'             => strlen((string)mam_env('APP_KEY')) >= 32,
        'DB_DSN'              => !!mam_env('DB_DSN'),
        'PAYSTACK_SECRET_KEY' => !!mam_env('PAYSTACK_SECRET_KEY'),
        'FLW_SECRET_KEY'      => !!mam_env('FLW_SECRET_KEY'),
        'MAIL_HOST'           => !!mam_env('MAIL_HOST'),
    ],
    // Email-delivery readiness (no secrets) — the usual reason mail isn't
    // delivered. `ready:false` + `issues` tells the operator exactly what to set.
    'mail' => mam_mail_readiness(),
];

try {
    $db = mam_db();
    $out['db'] = [
        'driver'           => $db->getAttribute(PDO::ATTR_DRIVER_NAME),
        'schema_version'   => (int)$db->query('SELECT IFNULL(MAX(version),0) FROM schema_version')->fetchColumn(),
        'target'           => defined('MAM_SCHEMA_TARGET') ? MAM_SCHEMA_TARGET : 16,
    ];
    // Probe a few key tables so we know what's actually present.
    foreach (['customers', 'applications', 'payments', 'store_items', 'store_orders'] as $t) {
        try { $db->query("SELECT 1 FROM $t LIMIT 1"); $out['db']['tables'][$t] = true; }
        catch (Throwable $e) { $out['db']['tables'][$t] = false; }
    }
    // applications must have these columns for the apply form to insert.
    $sql = $out['db']['driver'] === 'mysql'
        ? "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'applications'"
        : "SELECT name AS COLUMN_NAME FROM pragma_table_info('applications')";
    try {
        $cols = $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        $need = ['idempotency_key', 'booking_fee_paid_at', 'program', 'headshot_url', 'session'];
        $missing = array_values(array_diff($need, $cols));
        $out['db']['applications_missing_columns'] = $missing;
    } catch (Throwable $e) { $out['db']['applications_missing_columns'] = 'introspection_failed: ' . $e->getMessage(); }
} catch (Throwable $e) {
    $out['db'] = ['error' => $e->getMessage()];
}

// Recent buffer leaks — surface the smoking gun without needing FTP.
$leakPath = MAM_LOGS . '/buffer-leak.log';
if (is_readable($leakPath)) {
    $lines = @file($leakPath, FILE_IGNORE_NEW_LINES);
    if ($lines) $out['recent_buffer_leaks'] = array_slice($lines, -20);
}
$errPath = MAM_LOGS . '/php-error.log';
if (is_readable($errPath)) {
    $lines = @file($errPath, FILE_IGNORE_NEW_LINES);
    if ($lines) $out['recent_php_errors'] = array_slice($lines, -10);
}

mam_ok($out);
