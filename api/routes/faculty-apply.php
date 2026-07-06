<?php
/**
 * Instructor application form endpoint (approval-gated, single-use invites).
 *   GET  /api/faculty-apply?token=…  → { email }
 *        or 410 {error:'invite_used'} / 410 {error:'invite_expired'} / 404 {error:'invite_invalid'}
 *   POST /api/faculty-apply          → { ok, reference, book_url }
 *        body: { token, full_name, phone?, disciplines, experience_years?, bio,
 *                links?, cv_url?, headshot_url? }
 *
 * The token proves authenticity (HMAC over invite id + nonce); the
 * faculty_invites row is the source of truth for used/expired — submitting
 * consumes the invite, so a link can never be used twice.
 */
declare(strict_types=1);

$gate = function ($v): array {
    if (is_array($v)) return $v;
    if ($v === 'used')    mam_fail('invite_used', 410);
    if ($v === 'expired') mam_fail('invite_expired', 410);
    mam_fail('invite_invalid', 404);
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $inv = $gate(mam_faculty_verify_token((string)($_GET['token'] ?? '')));
    mam_ok(['email' => $inv['email']]);
}

$in  = mam_json_input();
$inv = $gate(mam_faculty_verify_token((string)($in['token'] ?? '')));

// Honeypot: bots fill every field; humans never see this one.
if (trim((string)($in['website'] ?? '')) !== '') mam_ok(['ok' => true, 'book_url' => mam_faculty_book_url()]);

$v = mam_faculty_validate($in);
if ($v['errors']) mam_fail('validation', 422, ['fields' => $v['errors']]);

$email = (string)$inv['email'];
$id = mam_faculty_application_create($email, $v['data'], mam_client_ip());
mam_faculty_consume((int)$inv['id']);   // single-use: this link is now dead

$bookUrl = mam_faculty_book_url();

mam_after_response(function () use ($email, $v, $id, $bookUrl) {
    $adminUrl = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/admin/?v=users#faculty';
    $notify = (string)mam_env('NOTIFY_EMAIL', mam_env('MAIL_FROM', ''));
    $name = (string)$v['data']['full_name'];
    if ($notify) {
        $rows = '';
        foreach (['full_name' => 'Name', 'disciplines' => 'Disciplines', 'experience_years' => 'Years', 'phone' => 'Phone', 'links' => 'Links', 'cv_url' => 'CV', 'headshot_url' => 'Headshot'] as $k => $label) {
            $val = (string)($v['data'][$k] ?? '');
            if ($val !== '') $rows .= "<tr><td style='padding:4px 10px 4px 0;color:#A89682'>$label</td><td style='padding:4px 0;color:#1A120B'>" . htmlspecialchars($val) . "</td></tr>";
        }
        mam_send($notify, '[MAM Faculty] Application — ' . $name,
            mam_email_layout(['header' => 'MAM ACADEMY', 'preheader' => 'New instructor application received.',
                'reason' => 'You received this because a prospective instructor completed the faculty application form.',
                'body' => "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:20px'>Instructor application #{$id}</h2>"
                    . "<p style='margin:0 0 10px'>From <strong>" . htmlspecialchars($email) . "</strong></p>"
                    . "<table style='font-size:14px;border-collapse:collapse'>$rows</table>"
                    . "<p style='margin:12px 0 0;white-space:pre-wrap'>" . nl2br(htmlspecialchars(mb_substr((string)$v['data']['bio'], 0, 1200))) . "</p>"
                    . mam_email_button($adminUrl, 'Review in admin')]),
            'faculty_application', true, ['reply_to' => $email]);
    }
    // Confirmation + next step: book the interview appointment.
    mam_send($email, 'Next step: book your MAM Academy interview',
        mam_email_layout(['header' => 'MAM ACADEMY', 'preheader' => 'Your application is in — book your interview appointment.',
            'reason' => 'You received this because you submitted an instructor application to MAM Academy.',
            'body' => "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:20px'>Thank you, " . htmlspecialchars($name) . "</h2>"
                . "<p style='margin:0 0 10px'>Your instructor application is with our faculty panel. The next step is yours: <strong>book your interview appointment</strong> so we can meet you.</p>"
                . mam_email_button($bookUrl, 'Book your appointment')]),
        'faculty_application_ack', true);
});

mam_ok(['ok' => true, 'reference' => 'FAC-' . $id, 'book_url' => $bookUrl]);
