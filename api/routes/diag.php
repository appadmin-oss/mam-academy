<?php
/**
 * GET /api/diag
 *
 * Diagnostic endpoint. Returns the state of the moving parts so you can
 * verify the pipeline end-to-end from a browser without guessing:
 *
 *   - PHP version + sapi
 *   - DB driver + schema_version + whether store_items exists
 *   - Logs + storage dirs writable
 *   - Output-buffering state at entry
 *   - Provider keys present (boolean only — never the value)
 *   - Last 20 lines of buffer-leak.log if any
 *
 * No PII. Safe to ping without auth from the operator.
 */
declare(strict_types=1);

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
        'target'           => 16,
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
