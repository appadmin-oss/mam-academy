<?php
/**
 * Instructor application endpoint.
 *
 *   GET  /api/faculty-apply[?token=…]
 *        → { open, catalogue:{courses,levels,programmes,modes}, email? }
 *        With a token: also the invited address, or
 *        410 {error:'invite_used'} / 410 {error:'invite_expired'} / 404 {error:'invite_invalid'}
 *        Without one: 403 {error:'invite_required'} when open applications are off.
 *
 *   POST /api/faculty-apply → { ok, reference, book_url }
 *        body: { token?, email?, full_name, phone?, subjects[], referees[],
 *                bio, qualifications?, teaching_philosophy?, programmes[]?,
 *                teaching_modes[]?, availability[]?, weekly_hours?,
 *                earliest_start?, location?, links?, cv_url?, headshot_url?,
 *                safeguarding_ack, policy_ack }
 *
 * TWO WAYS IN, ONE WAY THROUGH. An invite proves the address up front (HMAC
 * over invite id + nonce, with the DB row the source of truth for
 * used/expired) and is consumed on submit, so a link never works twice. An
 * OPEN application carries no such proof, so the address is whatever the
 * candidate typed — it is rate-limited, honeypotted and CSRF-gated, and the
 * panel verifies the person at interview either way.
 *
 * ⚠️ On the invite path the email comes from the INVITE, never from the body.
 * Letting a request override it would turn a single-use link for one address
 * into an application for any address.
 */
declare(strict_types=1);

$gate = function ($v): array {
    if (is_array($v)) return $v;
    if ($v === 'used')    mam_fail('invite_used', 410);
    if ($v === 'expired') mam_fail('invite_expired', 410);
    mam_fail('invite_invalid', 404);
};

$open = mam_faculty_open_applications();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim((string)($_GET['token'] ?? ''));
    $out = ['open' => $open, 'catalogue' => mam_faculty_catalogue()];
    if ($token !== '') {
        $inv = $gate(mam_faculty_verify_token($token));
        $out['email'] = $inv['email'];
    } elseif (!$open) {
        mam_fail('invite_required', 403);
    }
    mam_ok($out);
}

$in = mam_json_input();
$token = trim((string)($in['token'] ?? ''));

$inv = $token !== '' ? $gate(mam_faculty_verify_token($token)) : null;

// One rule, stated once, in a place with tests on it: on the invite path the
// address is the INVITE's and the body is ignored. See the function's note for
// why honouring a body field here would be a real hole.
$email = mam_faculty_applicant_email($inv, $in);

// Honeypot: bots fill every field; humans never see this one. Answer as though
// it worked — telling a bot it was caught only tells it what to change.
if (trim((string)($in['website'] ?? '')) !== '') mam_ok(['ok' => true, 'book_url' => mam_faculty_book_url()]);

$v = mam_faculty_validate_full($in);
if ($v['errors']) mam_fail('validation', 422, ['fields' => $v['errors']]);

$id = mam_faculty_application_save($email, $v, mam_client_ip());
if ($inv) mam_faculty_consume((int)$inv['id']);     // single-use: this link is now dead

$bookUrl = mam_faculty_book_url();

mam_after_response(function () use ($email, $v, $id, $bookUrl) {
    $adminUrl = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/admin/?v=users#faculty';
    $notify = (string)mam_env('NOTIFY_EMAIL', mam_env('MAIL_FROM', ''));
    $name = (string)$v['data']['full_name'];

    if ($notify) {
        $rows = '';
        foreach (['full_name' => 'Name', 'disciplines' => 'Teaches', 'experience_years' => 'Years',
                  'qualifications' => 'Qualifications', 'location' => 'Location', 'phone' => 'Phone',
                  'weekly_hours' => 'Hours/week', 'earliest_start' => 'Can start', 'links' => 'Links',
                  'cv_url' => 'CV', 'headshot_url' => 'Headshot'] as $k => $label) {
            $val = (string)($v['data'][$k] ?? '');
            if ($val !== '') $rows .= "<tr><td style='padding:4px 10px 4px 0;color:#A89682'>$label</td><td style='padding:4px 0;color:#1A120B'>" . htmlspecialchars($val) . "</td></tr>";
        }
        // Referees are listed so the panel can take them up — the OUTCOME is
        // recorded in vetting_records by whoever does, never from this email.
        $refs = '';
        foreach ($v['referees'] as $r) {
            $refs .= '<li>' . htmlspecialchars($r['name'])
                . ($r['relationship'] ? ' — ' . htmlspecialchars($r['relationship']) : '')
                . ($r['organisation'] ? ', ' . htmlspecialchars($r['organisation']) : '')
                . ($r['email'] ? ' &middot; ' . htmlspecialchars($r['email']) : '')
                . ($r['phone'] ? ' &middot; ' . htmlspecialchars($r['phone']) : '')
                . '</li>';
        }
        mam_send($notify, '[MAM Faculty] Application — ' . $name,
            mam_email_layout(['header' => 'MAM ACADEMY', 'preheader' => 'New instructor application received.',
                'reason' => 'You received this because a prospective instructor completed the faculty application form.',
                'body' => "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:20px'>Instructor application #{$id}</h2>"
                    . "<p style='margin:0 0 10px'>From <strong>" . htmlspecialchars($email) . "</strong></p>"
                    . "<table style='font-size:14px;border-collapse:collapse'>$rows</table>"
                    . ($refs ? "<p style='margin:14px 0 4px;font-weight:700'>Referees to take up</p><ul style='margin:0;padding-left:18px;font-size:14px'>$refs</ul>" : '')
                    . "<p style='margin:12px 0 0;white-space:pre-wrap'>" . nl2br(htmlspecialchars(mb_substr((string)$v['data']['bio'], 0, 1200))) . "</p>"
                    . mam_email_button($adminUrl, 'Review in admin')]),
            'faculty_application', true, ['reply_to' => $email]);
    }

    mam_send($email, 'Next step: book your MAM Academy interview',
        mam_email_layout(['header' => 'MAM ACADEMY', 'preheader' => 'Your application is in — book your interview appointment.',
            'reason' => 'You received this because you submitted an instructor application to MAM Academy.',
            'body' => "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:20px'>Thank you, " . htmlspecialchars($name) . "</h2>"
                . "<p style='margin:0 0 10px'>Your instructor application is with our faculty panel. The next step is yours: <strong>book your interview appointment</strong> so we can meet you.</p>"
                . "<p style='margin:0 0 10px;font-size:14px;color:#6B5B49'>Because you would be teaching children, an offer follows safer-recruitment checks: we take up both references and confirm your clearances before any teaching begins.</p>"
                . mam_email_button($bookUrl, 'Book your appointment')]),
        'faculty_application_ack', true);
});

mam_ok(['ok' => true, 'reference' => 'FAC-' . $id, 'book_url' => $bookUrl]);
