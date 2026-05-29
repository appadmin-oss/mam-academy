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

$features = [
    'paystack' => mam_env('PAYSTACK_SECRET_KEY') ? true : false,
    'flutterwave' => mam_env('FLW_SECRET_KEY') ? true : false,
    'stripe' => mam_env('STRIPE_SECRET_KEY') ? true : false,
    'smtp' => mam_env('MAIL_HOST') ? true : false,
    'admin' => (mam_env('ADMIN_USER') && mam_env('ADMIN_PASSWORD_HASH')) ? true : false,
    // CSRF is functional whenever a signing key resolves — either APP_KEY from
    // .env, or the persistent auto-generated fallback. app_key_source tells the
    // operator which one is live so an unset APP_KEY is still visible.
    'csrf' => strlen(mam_app_key()) >= 32,
    'app_key_source' => strlen(trim((string)mam_env('APP_KEY', ''))) >= 32 ? 'env' : 'auto',
];

mam_ok([
    'service' => 'mam-academy-api',
    'version' => '1.0.0',
    'time' => date('c'),
    'env' => mam_env('APP_ENV', 'production'),
    'db' => $dbOk ? 'ok' : 'down',
    // Don't leak the raw DB error (file path / DSN) to the public in prod.
    'db_error' => (mam_env('APP_ENV', 'production') === 'production') ? ($dbErr ? 'see logs' : null) : $dbErr,
    'features' => $features,
]);
