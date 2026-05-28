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

$id = mam_db_insert('messages', [
    'name' => $name, 'email' => $email, 'phone' => $phone,
    'topic' => $topic, 'message' => $message, 'ip' => mam_client_ip(),
]);

$notify = mam_env('NOTIFY_EMAIL', 'connect@mamcentre.org');
$body = "<h3>New contact message</h3>"
      . "<p><strong>From:</strong> " . htmlspecialchars($name) . " &lt;" . htmlspecialchars($email) . "&gt;</p>"
      . "<p><strong>Phone:</strong> " . htmlspecialchars($phone ?: '—') . "</p>"
      . "<p><strong>Topic:</strong> " . htmlspecialchars($topic) . "</p>"
      . "<p>" . nl2br(htmlspecialchars($message)) . "</p>";
mam_send($notify, '[MAM Contact] ' . $topic, $body, 'contact_message', true, ['reply_to' => $email]);

// Auto-responder to the sender so they're never left wondering whether
// their message got through. Surfaces apply/phone as faster routes.
mam_send($email, 'Got your message — MAM Academy', mam_email_contact_autoresponse($name, $topic), 'contact_autoresponse', true);

mam_audit('create', 'message', $id, ['email' => $email, 'topic' => $topic]);
mam_ok(['received' => true, 'id' => $id]);
