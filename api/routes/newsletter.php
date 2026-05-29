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
    mam_log('error', 'newsletter_failed', ['err' => $e->getMessage()]);
    mam_fail('save_failed', 500);
}

// Confirm to the subscriber. Power Play registrations get a session-
// specific confirmation (next first-Wednesday date + Meet link); every
// other source gets the general welcome. Both double as a delivery test.
if (strpos($source, 'power-play') === 0) {
    mam_send($email, '⚡ You\'re in — next Power Play details', mam_email_powerplay_confirmation(), 'powerplay_confirmation', true);
} else {
    mam_send($email, 'Welcome to the MAM Academy circle', mam_email_newsletter_welcome($source), 'newsletter_welcome', true);
}

$notify = mam_env('NOTIFY_EMAIL');
if ($notify) mam_send($notify, 'New newsletter subscriber', "$email subscribed via $source", 'newsletter_notice', false);
mam_audit('subscribe', 'newsletter', null, ['email' => $email, 'source' => $source]);
mam_ok(['subscribed' => true, 'email' => $email]);
