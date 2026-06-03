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

// ---------- Access gate ----------
// This surfaces the tail of php-error.log + buffer-leak.log, env-presence flags
// and DB internals — useful to the operator, but reconnaissance for anyone else.
// Allow a logged-in admin, OR a request carrying ?token=<SETUP_TOKEN> (the same
// break-glass secret the installer uses) so it still works on a fresh box with
// no admin yet. The session lookup is wrapped so a DB outage — the very thing
// you'd reach for diag to diagnose — can't 500 the token path. Otherwise 404.
$diagAdmin = false;
try { $u = mam_current_user(); $diagAdmin = $u && ($u['role'] ?? '') === 'admin'; } catch (Throwable $e) {}
$diagTok = (string)mam_env('SETUP_TOKEN', '');
$diagTokenOk = $diagTok !== '' && hash_equals($diagTok, (string)($_GET['token'] ?? ''));
if (!$diagAdmin && !$diagTokenOk) mam_fail('not_found', 404);

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
];

try {
    $db = mam_db();
    $out['db'] = [
        'driver'           => $db->getAttribute(PDO::ATTR_DRIVER_NAME),
        'schema_version'   => (int)$db->query('SELECT IFNULL(MAX(version),0) FROM schema_version')->fetchColumn(),
        'target'           => 15,
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
