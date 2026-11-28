<?php
/**
 * Health check — verifies API + DB connectivity + env config.
 */
declare(strict_types=1);

$dbOk = false; $dbErr = null;
try {
    mam_db()->query('SELECT 1');
    $dbOk = true;
} catch (Throwable $e) { $dbErr = $e->getMessage(); }

// The public payload is intentionally minimal — an uptime probe only. The
// config posture (which payment providers / SMTP / admin / CSRF-key are set)
// is reconnaissance for an attacker, so it is only revealed to an admin session
// or a caller holding DEBUG_TOKEN (same gate as /api/diag).
$authorized = false;
try {
    $u = function_exists('mam_current_user') ? mam_current_user() : null;
    if ($u && function_exists('mam_user_has_role') && mam_user_has_role($u, 'admin')) $authorized = true;
} catch (Throwable $e) {}
$debugToken = (string)mam_env('DEBUG_TOKEN', '');
if (!$authorized && $debugToken !== '' && hash_equals($debugToken, (string)($_GET['key'] ?? ''))) $authorized = true;

$ops = function_exists('mam_ops_health') ? mam_ops_health() : ['ok' => $dbOk, 'checks' => ['db' => $dbOk ? 'ok' : 'down'], 'jobs_last_run' => null];

$out = [
    'service'     => 'mam-academy-api',
    'api_version' => MAM_API_VERSION,
    'time'        => date('c'),
    'db'          => $dbOk ? 'ok' : 'down',
    // For an uptime monitor: `status` and the per-check STATES (no details).
    // The response is 503 whenever any check is not ok — the database, a job
    // runner that stopped or keeps failing, a stale or missing backup, or a
    // mail backlog — so "is MAM healthy" is one URL a monitor can watch.
    'status'      => $ops['ok'] ? 'ok' : 'degraded',
    'checks'      => $ops['checks'],
];

if ($authorized) {
    $out['schema'] = (int)MAM_SCHEMA_TARGET;
    $out['jobs_last_run'] = $ops['jobs_last_run'];
    $out['env']     = mam_env('APP_ENV', 'production');
    $out['db_error'] = $dbErr;
    $out['features'] = [
        'paystack'       => (bool)mam_env('PAYSTACK_SECRET_KEY'),
        'flutterwave'    => (bool)mam_env('FLW_SECRET_KEY'),
        'stripe'         => (bool)mam_env('STRIPE_SECRET_KEY'),
        'smtp'           => (bool)mam_env('MAIL_HOST'),
        'admin'          => (bool)(mam_env('ADMIN_USER') && mam_env('ADMIN_PASSWORD_HASH')),
        /* ⚠️ Not a length check. An UNPERSISTED key is a well-formed 64
           characters and changes every process, so `strlen() >= 32` reported
           healthy in precisely the state where every form on the site fails
           with csrf_invalid. `mam_app_key_persisted()` is the real question. */
        'csrf'           => strlen(mam_app_key()) >= 32 && mam_app_key_persisted(),
        'app_key_source' => strlen(trim((string)mam_env('APP_KEY', ''))) >= 32 ? 'env'
                            : (mam_app_key_persisted() ? 'auto' : 'EPHEMERAL — not persisted; set APP_KEY or make api/storage writable'),
    ];
}

mam_json(['ok' => (bool) $ops['ok']] + $out, $ops['ok'] ? 200 : 503);
