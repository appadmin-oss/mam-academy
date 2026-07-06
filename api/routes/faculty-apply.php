<?php
/**
 * Instructor application form endpoint.
 *   GET  /api/faculty-apply?token=…   → { email }  (validates the invite link)
 *   POST /api/faculty-apply           → { ok, reference }  body: { token, full_name,
 *        phone?, disciplines, experience_years?, bio, links?, cv_url?, headshot_url? }
 *
 * The token is the HMAC invite minted when someone asked to teach (contact
 * form) or when an admin sent an invite — see api/lib/faculty.php.
 */
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $email = mam_faculty_verify_token((string)($_GET['token'] ?? ''));
    if (!$email) mam_fail('invite_invalid', 404);
    mam_ok(['email' => $email]);
}

$in = mam_json_input();
$email = mam_faculty_verify_token((string)($in['token'] ?? ''));
if (!$email) mam_fail('invite_invalid', 404);

// Honeypot: bots fill every field; humans never see this one.
if (trim((string)($in['website'] ?? '')) !== '') mam_ok(['ok' => true]);

$v = mam_faculty_validate($in);
if ($v['errors']) mam_fail('validation', 422, ['fields' => $v['errors']]);

$id = mam_faculty_application_create($email, $v['data'], mam_client_ip());

mam_after_response(function () use ($email, $v, $id) {
    $adminUrl = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/admin/?v=users#faculty';
    $notify = (string)mam_env('MAIL_NOTIFY', mam_env('MAIL_FROM', ''));
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
    // Confirmation to the applicant.
    mam_send($email, 'We received your instructor application — MAM Academy',
        mam_email_layout(['header' => 'MAM ACADEMY', 'preheader' => 'Your instructor application is in review.',
            'reason' => 'You received this because you submitted an instructor application to MAM Academy.',
            'body' => "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:20px'>Thank you, " . htmlspecialchars($name) . "</h2>"
                . "<p style='margin:0'>Your instructor application is with our faculty team. We review every application personally and will reach out about next steps — usually within a few days.</p>"]),
        'faculty_application_ack', true);
});

mam_ok(['ok' => true, 'reference' => 'FAC-' . $id]);
