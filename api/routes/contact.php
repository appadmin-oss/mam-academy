<?php
declare(strict_types=1);

$in = mam_json_input();
mam_honeypot($in);
$name    = mam_str($in, 'name', 120);
$email   = mam_email(mam_str($in, 'email', 120));
$phone   = mam_str($in, 'phone', 24);
$topic   = mam_str($in, 'topic', 80);
$message = mam_str($in, 'message', 2000);

$errors = [];
if (!$name)    $errors[] = 'name';
if (!$email)   $errors[] = 'email';
if (!$topic)   $errors[] = 'topic';
if (!$message) $errors[] = 'message';
if ($errors) mam_fail('validation', 422, ['fields' => $errors]);

try {
    $id = mam_db_insert('messages', [
        'name' => $name, 'email' => $email, 'phone' => $phone,
        'topic' => $topic, 'message' => $message, 'ip' => mam_client_ip(),
    ]);
} catch (Throwable $e) {
    @error_log('[mam-api contact] ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    if (function_exists('mam_log')) mam_log('error', 'contact_failed', ['err' => $e->getMessage()]);
    $envName = strtolower((string)mam_env('APP_ENV', 'production'));
    mam_fail('save_failed', 500, [
        'message' => $envName === 'production' ? 'Could not save the message' : ($e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()),
    ]);
}

// Defer both emails (admin notice + auto-responder) so the contact form
// returns instantly. The DB insert above is what actually records the
// message; the emails are courtesy notifications, safe to flush async.
$notify = mam_env('NOTIFY_EMAIL', 'connect@mamcentre.org');
mam_after_response(function () use ($notify, $name, $email, $phone, $topic, $message) {
    $body = "<h3>New contact message</h3>"
          . "<p><strong>From:</strong> " . htmlspecialchars($name) . " &lt;" . htmlspecialchars($email) . "&gt;</p>"
          . "<p><strong>Phone:</strong> " . htmlspecialchars($phone ?: '—') . "</p>"
          . "<p><strong>Topic:</strong> " . htmlspecialchars($topic) . "</p>"
          . "<p>" . nl2br(htmlspecialchars($message)) . "</p>";
    mam_send($notify, '[MAM Contact] ' . $topic, $body, 'contact_message', true, ['reply_to' => $email]);
    mam_send($email, 'Got your message — MAM Academy', mam_email_contact_autoresponse($name, $topic), 'contact_autoresponse', true);
});

mam_audit('create', 'message', $id, ['email' => $email, 'topic' => $topic]);
mam_ok(['received' => true, 'id' => $id]);
