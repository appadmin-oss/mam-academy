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
$notify = mam_env('NOTIFY_EMAIL', 'academy@mamcentre.org');
mam_after_response(function () use ($notify, $name, $email, $phone, $topic, $message) {
    $adminUrl = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/admin/?v=messages';
    $body = mam_email_admin_notice(
        'New contact message',
        [
            'From'  => $name,
            'Email' => $email,
            'Phone' => $phone ?: '—',
            'Topic' => $topic,
        ],
        [
            'lede' => 'A new message arrived through the website contact form. Reply directly to this email to respond.',
            'note' => $message,
            'cta'  => ['url' => $adminUrl, 'label' => 'Open in admin'],
        ]
    );
    mam_send($notify, '[MAM Contact] ' . $topic, $body, 'contact_message', true, ['reply_to' => $email]);
    mam_send($email, 'Got your message — MAM Academy', mam_email_contact_autoresponse($name, $topic), 'contact_autoresponse', true);

    // Teaching interest → queue a faculty-invite REQUEST for admin approval.
    // The application form is only emailed once an admin approves it, so every
    // candidate is deliberately let in — never auto-invited.
    if (preg_match('/faculty|teach/i', $topic)) {
        try { mam_faculty_request($email, $name, 'contact'); } catch (\Throwable $e) { /* message itself is already saved */ }
        $first = htmlspecialchars(trim(explode(' ', trim($name))[0] ?: 'there'));
        mam_send($email, 'Your teaching interest — MAM Academy',
            mam_email_layout(['header' => 'MAM ACADEMY', 'preheader' => 'We received your interest in teaching at MAM.',
                'reason' => 'You received this because you expressed interest in teaching at MAM Academy.',
                'body' => "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px'>Thanks for your interest in teaching, $first</h2>"
                    . "<p style='margin:0'>Our faculty team reviews every teaching request personally. Once approved, we'll email you a personal link to the full instructor application (details + headshot). It usually takes a day or two.</p>"]),
            'faculty_interest_ack', true);
    }
});

mam_audit('create', 'message', $id, ['email' => $email, 'topic' => $topic]);
mam_ok(['received' => true, 'id' => $id]);
