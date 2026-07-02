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

$out = [
    'service' => 'mam-academy-api',
    'time'    => date('c'),
    'db'      => $dbOk ? 'ok' : 'down',
];

if ($authorized) {
    $out['version'] = '1.0.0';
    $out['env']     = mam_env('APP_ENV', 'production');
    $out['db_error'] = $dbErr;
    $out['features'] = [
        'paystack'       => (bool)mam_env('PAYSTACK_SECRET_KEY'),
        'flutterwave'    => (bool)mam_env('FLW_SECRET_KEY'),
        'stripe'         => (bool)mam_env('STRIPE_SECRET_KEY'),
        'smtp'           => (bool)mam_env('MAIL_HOST'),
        'admin'          => (bool)(mam_env('ADMIN_USER') && mam_env('ADMIN_PASSWORD_HASH')),
        'csrf'           => strlen(mam_app_key()) >= 32,
        'app_key_source' => strlen(trim((string)mam_env('APP_KEY', ''))) >= 32 ? 'env' : 'auto',
    ];
}

mam_ok($out);
