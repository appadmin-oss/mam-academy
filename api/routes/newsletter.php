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
    mam_db()->prepare('INSERT INTO newsletter (email, source) VALUES (?,?)
        ON CONFLICT(email) DO UPDATE SET status = "subscribed", source = excluded.source')
        ->execute([$email, $source]);
} catch (Throwable $e) {
    mam_log('error', 'newsletter_failed', ['err' => $e->getMessage()]);
    mam_fail('save_failed', 500);
}

$notify = mam_env('NOTIFY_EMAIL');
if ($notify) mam_send($notify, 'New newsletter subscriber', "$email subscribed via $source", 'newsletter_notice', false);
mam_audit('subscribe', 'newsletter', null, ['email' => $email, 'source' => $source]);
mam_ok(['subscribed' => true, 'email' => $email]);
