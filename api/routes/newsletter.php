<?php
declare(strict_types=1);

$in = mam_json_input();
if (!$in) {
    parse_str(file_get_contents('php://input') ?: '', $form);
    $in = $form;
}
mam_honeypot($in);
$email = mam_email((string)($in['email'] ?? ''));
if (!$email) mam_fail('invalid_email', 422);
$source = mam_str($in, 'source', 64) ?: 'site';

try {
    mam_db_upsert('newsletter',
        ['email' => $email, 'source' => $source],
        ['email'],
        ['status' => 'subscribed', 'source' => $source]);
} catch (Throwable $e) {
    // Surface the actual error in dev — production stays opaque, but the
    // detail goes to api/logs/php-error.log either way so support has it.
    @error_log('[mam-api newsletter] ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    if (function_exists('mam_log')) mam_log('error', 'newsletter_failed', ['err' => $e->getMessage()]);
    $envName = strtolower((string)mam_env('APP_ENV', 'production'));
    mam_fail('save_failed', 500, [
        'message' => $envName === 'production' ? 'Could not save the subscription' : ($e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()),
    ]);
}

// Defer email sends until after the response — keeps the PowerPlay /
// newsletter form snappy. The DB upsert above is what actually records
// the subscription; the emails are confirmations, fine to flush async.
$notify = mam_env('NOTIFY_EMAIL');
mam_after_response(function () use ($email, $source, $notify) {
    if (strpos($source, 'power-play') === 0) {
        mam_send($email, "⚡ You're in — next Power Play details", mam_email_powerplay_confirmation(), 'powerplay_confirmation', true);
    } else {
        mam_send($email, 'Welcome to the MAM Academy circle', mam_email_newsletter_welcome($source), 'newsletter_welcome', true);
    }
    if ($notify) mam_send($notify, 'New newsletter subscriber', "$email subscribed via $source", 'newsletter_notice', false);
});
mam_audit('subscribe', 'newsletter', null, ['email' => $email, 'source' => $source]);
mam_ok(['subscribed' => true, 'email' => $email]);
