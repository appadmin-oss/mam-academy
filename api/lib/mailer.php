<?php
/**
 * Minimal mailer: SMTP if MAIL_HOST is set, otherwise PHP mail().
 * Supports plain text or HTML.
 */
declare(strict_types=1);

function mam_send_mail(string $to, string $subject, string $body, bool $html = false, array $opts = []): bool {
    $result = mam_send_mail_verbose($to, $subject, $body, $html, $opts);
    return (bool)$result['ok'];
}

/**
 * Same as mam_send_mail() but returns a structured result:
 *   ['ok' => bool, 'transport' => 'smtp'|'mail()', 'error' => ?string, 'steps' => array]
 * Useful for diagnostics (CLI tester, /api/admin/mail-test).
 */
function mam_send_mail_verbose(string $to, string $subject, string $body, bool $html = false, array $opts = []): array {
    $from      = $opts['from']      ?? mam_env('MAIL_FROM_EMAIL', 'no-reply@mamcentre.org');
    $fromName  = $opts['from_name'] ?? mam_env('MAIL_FROM_NAME', 'MAM Academy');
    $replyTo   = $opts['reply_to']  ?? null;
    $host      = mam_env('MAIL_HOST', '');

    if ($host) {
        $transcript = [];
        $ok = mam_smtp_send($host, (int)mam_env('MAIL_PORT', '587'),
            mam_env('MAIL_USERNAME', ''), mam_env('MAIL_PASSWORD', ''),
            strtolower((string)mam_env('MAIL_ENCRYPTION', 'tls')),
            $from, $fromName, $replyTo, $to, $subject, $body, $html, $transcript);
        $failedStep = null;
        foreach ($transcript as $s) {
            if (!($s['ok'] ?? true)) { $failedStep = $s; break; }
        }
        return [
            'ok'        => $ok,
            'transport' => 'smtp',
            'host'      => $host,
            'error'     => $ok ? null : ($failedStep['step'] ?? 'smtp_failed') . ': ' . trim((string)($failedStep['reply'] ?? '')),
            'steps'     => $transcript,
        ];
    }

    // PHP mail() fallback (no SMTP configured). Many hosts disable this —
    // configure MAIL_HOST/MAIL_USERNAME/MAIL_PASSWORD in api/.env for reliable delivery.
    mam_log('warn', 'mail_not_configured', [
        'hint' => 'MAIL_HOST is empty in api/.env — falling back to PHP mail(), which is unreliable on most hosts. Run `php api/tools/mail-test.php you@example.com` after setting MAIL_HOST.',
        'to' => $to, 'subject' => $subject,
    ]);
    $headers = [];
    $headers[] = 'From: ' . mb_encode_mimeheader($fromName) . " <$from>";
    if ($replyTo) $headers[] = "Reply-To: $replyTo";
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = $html ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'X-Mailer: MAM-Academy';
    $params = (strpos($from, '@') !== false) ? ('-f' . $from) : '';
    $ok = @mail($to, $subject, $body, implode("\r\n", $headers), $params);
    if (!$ok) mam_log('warn', 'mail_failed_phpmail', ['to' => $to, 'subject' => $subject, 'hint' => 'Set MAIL_HOST + SMTP creds in api/.env; PHP mail() is unreliable/disabled on most hosts']);
    return [
        'ok'        => (bool)$ok,
        'transport' => 'mail()',
        'host'      => null,
        'error'     => $ok ? null : 'PHP mail() returned false (host likely has no MTA or sendmail is disabled)',
        'steps'     => [],
    ];
}

/**
 * Tiny SMTP client (no external deps). Supports STARTTLS and SSL.
 * Designed to handle the common shared-host case (Gmail SMTP relay,
 * SendGrid SMTP, Postmark, Mailgun, etc.).
 */
function mam_smtp_send(string $host, int $port, string $user, string $pass, string $encryption,
                       string $from, string $fromName, ?string $replyTo,
                       string $to, string $subject, string $body, bool $html,
                       array &$transcript = null): bool {
    if ($transcript === null) $transcript = [];
    // Most authenticated relays (Gmail, Office365, SES, …) reject a MAIL FROM
    // envelope that differs from the authenticated account. Use the auth user
    // as the envelope sender when it's an address; keep the friendly From header.
    $envelopeFrom = ($user !== '' && strpos($user, '@') !== false) ? $user : $from;

    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host;
    $errno = 0; $errstr = '';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $sock = @stream_socket_client("$remote:$port", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        mam_log('error', 'smtp_connect_failed', ['host' => $host, 'port' => $port, 'err' => $errstr]);
        $transcript[] = ['step' => 'connect', 'ok' => false, 'reply' => "$errno $errstr"];
        return false;
    }
    $transcript[] = ['step' => 'connect', 'ok' => true, 'reply' => "$remote:$port"];
    stream_set_timeout($sock, 30);

    $get = function () use ($sock) {
        $resp = '';
        while ($line = fgets($sock, 2048)) {
            $resp .= $line;
            if (preg_match('/^\d{3} /', $line)) break;
        }
        return $resp;
    };
    $send = function ($cmd) use ($sock, $get) { fwrite($sock, $cmd . "\r\n"); return $get(); };
    // Verify a response code; on mismatch log the exact server reply and bail.
    $expect = function ($resp, array $codes, string $step) use ($sock, &$transcript) {
        $reply = trim((string)$resp);
        $ok = in_array(substr((string)$resp, 0, 3), $codes, true);
        $transcript[] = ['step' => $step, 'ok' => $ok, 'reply' => $reply];
        if (!$ok) {
            mam_log('error', 'smtp_step_failed', ['step' => $step, 'reply' => $reply]);
            @fwrite($sock, "QUIT\r\n"); fclose($sock);
            return false;
        }
        return true;
    };

    if (!$expect($get(), ['220'], 'banner')) return false;

    $ehloName = $_SERVER['SERVER_NAME'] ?? 'mamcentre.org';
    if (!$expect($send('EHLO ' . $ehloName), ['250'], 'ehlo')) return false;

    if ($encryption === 'tls') {
        if (!$expect($send('STARTTLS'), ['220'], 'starttls')) return false;
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            mam_log('error', 'smtp_tls_failed');
            $transcript[] = ['step' => 'tls_handshake', 'ok' => false, 'reply' => 'stream_socket_enable_crypto returned false'];
            fclose($sock);
            return false;
        }
        $transcript[] = ['step' => 'tls_handshake', 'ok' => true, 'reply' => 'TLS negotiated'];
        if (!$expect($send('EHLO ' . $ehloName), ['250'], 'ehlo_tls')) return false;
    }

    if ($user !== '') {
        if (!$expect($send('AUTH LOGIN'), ['334'], 'auth_init')) return false;
        if (!$expect($send(base64_encode($user)), ['334'], 'auth_user')) return false;
        if (!$expect($send(base64_encode($pass)), ['235'], 'auth_pass')) return false;
    }

    if (!$expect($send('MAIL FROM:<' . $envelopeFrom . '>'), ['250'], 'mail_from')) return false;
    if (!$expect($send('RCPT TO:<' . $to . '>'), ['250', '251'], 'rcpt_to')) return false;
    if (!$expect($send('DATA'), ['354'], 'data')) return false;

    $head = [];
    $head[] = 'From: ' . mb_encode_mimeheader($fromName) . " <$from>";
    if (strcasecmp($envelopeFrom, $from) !== 0) $head[] = "Sender: <$envelopeFrom>";
    $head[] = "To: $to";
    if ($replyTo) $head[] = "Reply-To: $replyTo";
    $head[] = 'Subject: ' . mb_encode_mimeheader($subject);
    $head[] = 'Date: ' . date('r');
    $head[] = 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . substr(strrchr($envelopeFrom, '@') ?: '@mam.local', 1) . '>';
    $head[] = 'MIME-Version: 1.0';
    $head[] = $html ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8';
    $head[] = 'Content-Transfer-Encoding: 8bit';
    $head[] = 'X-Mailer: MAM-Academy';

    $bodyEsc = preg_replace('/^\./m', '..', $body);          // dot-stuffing
    $bodyEsc = preg_replace('/\r\n|\r|\n/', "\r\n", $bodyEsc); // normalise CRLF
    $payload = implode("\r\n", $head) . "\r\n\r\n" . $bodyEsc . "\r\n.";
    $ok = $expect($send($payload), ['250'], 'body');
    if ($ok) { @fwrite($sock, "QUIT\r\n"); fclose($sock); }
    return $ok;
}

/**
 * Pre-rendered HTML templates for transactional emails.
 */
function mam_email_application_confirmation(array $app, array $customer): string {
    $tot = number_format((float)$app['total_usd'], 2);
    $ref = htmlspecialchars($app['reference'], ENT_QUOTES);
    $name = htmlspecialchars(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')), ENT_QUOTES);
    return "<!doctype html><html><body style='background:#120D08;color:#fff;font-family:Arial,sans-serif;padding:24px'>
    <table cellpadding='0' cellspacing='0' style='max-width:560px;margin:0 auto;background:#1A120B;border:1px solid #251A10;border-radius:18px;overflow:hidden'>
    <tr><td style='background:linear-gradient(135deg,#E08A1A,#FBBF24);padding:24px;text-align:center'>
      <div style='font-family:\"Bebas Neue\",Impact,sans-serif;font-size:26px;letter-spacing:.08em;color:#1A120B;font-weight:900'>MAM ACADEMY</div>
    </td></tr>
    <tr><td style='padding:32px 28px;color:#FBF7F3;line-height:1.7'>
      <h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>You're in, $name!</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px'>Thank you for applying to MAM Academy. Your application reference is:</p>
      <div style='background:#251A10;border:1px dashed #FBBF24;border-radius:10px;padding:14px 18px;font-family:monospace;color:#FBBF24;font-size:16px;text-align:center;margin:16px 0'>$ref</div>
      <p style='color:rgba(255,255,255,0.86);font-size:15px'>Programme: <strong style='color:#fff'>" . htmlspecialchars($app['pathway']) . "</strong> · Mode: <strong style='color:#fff'>" . htmlspecialchars($app['mode']) . "</strong></p>
      <p style='color:rgba(255,255,255,0.86);font-size:15px'>Total: <strong style='color:#FBBF24'>USD \${$tot}</strong></p>
      <p style='color:rgba(255,255,255,0.86);font-size:15px;margin-top:20px'>Our team will be in touch within <strong>24 hours</strong> to confirm your schedule and book your MAM Auditer entry assessment.</p>
      <p style='margin-top:28px;color:rgba(255,255,255,0.6);font-size:13px'>— MAM Centre, Lagos · <a href='mailto:connect@mamcentre.org' style='color:#FBBF24'>connect@mamcentre.org</a></p>
    </td></tr></table>
    </body></html>";
}

/**
 * Full class-policy email (online/home vs onsite), stamped with the
 * applicant's typed signature and the date they agreed.
 */
function mam_email_policy(string $mode, string $signedName, string $reference): string {
    $isOnsite = ($mode === 'onsite');
    $title = $isOnsite ? 'MAM Academy Policy — Onsite Classes' : 'MAM Academy Policy — Online / Home Classes';

    $online = [
        ['Conducive Learning Environment', 'Your space must be conducive for teaching and learning — minimal noise and distractions during class.'],
        ['No Picking Calls', 'Phones must be on silent; calls are not to be answered during class.'],
        ['Class Readiness', 'Be ready at least 15 minutes early. Lateness incurs a ₦500 / $0.50 fine; notify the instructor 2 hours ahead if unavoidable. Max two late arrivals.'],
        ['Assignment Compliance', 'All assignments must be completed on time. Late submission incurs a ₦1,000 / $1 fine.'],
        ['No Absenteeism', 'Notify the instructor at least 24 hours ahead. Max two absences; absence without notice or beyond two incurs a ₦1,000 / $1 fine.'],
        ['Class Rescheduling', 'Allowed only with 24-hour notice plus a ₦2,000 / $2 inconvenience fee.'],
        ['Certification', 'A certificate is issued after revision and a completed examination.'],
        ['Non-Refundable Payments', 'All payments are final and strictly non-refundable.'],
        ['Daily Practice Assessment', 'Free minutes are allocated daily to review your practice online at the agreed time.'],
        ['Internet-Connected Devices', 'Two devices requested: one to your left facing the instrument, one facing you for communication.'],
    ];
    $onsite = [
        ['Punctuality and Notification', 'Arrive at least 30 minutes before class. Lateness without notice incurs a ₦1,000 fine; notify at least 2 hours ahead if unavoidable.'],
        ['Class Attendance', 'Notify the Academy at least 24 hours in advance of any absence. Failure to do so incurs a ₦2,000 fine.'],
        ['Assignment Compliance', 'Submit assignments on time (late: ₦1,000) and a 1-minute practice video daily.'],
        ['No Use of Phones in Class', 'Phones must be switched off or on silent during class.'],
        ['Conducive Learning Environment', 'Maintain decorum and participate actively in all learning activities.'],
        ['Certification', 'A certificate is issued after a thorough revision and a successful examination.'],
        ['Non-Refundable Payments', 'All payments are strictly non-refundable.'],
        ['Daily Practice Assessments', 'Allocate free time daily to review concepts taught; consistent practice is essential.'],
    ];
    $points = $isOnsite ? $onsite : $online;

    $rows = '';
    $i = 1;
    foreach ($points as $p) {
        $rows .= "<tr><td style='padding:10px 0;border-bottom:1px solid #251A10;vertical-align:top'>"
            . "<strong style='color:#FBBF24'>{$i}. " . htmlspecialchars($p[0]) . "</strong><br>"
            . "<span style='color:rgba(255,255,255,0.82);font-size:14px'>" . htmlspecialchars($p[1]) . "</span></td></tr>";
        $i++;
    }
    $sig = htmlspecialchars($signedName, ENT_QUOTES);
    $ref = htmlspecialchars($reference, ENT_QUOTES);
    $date = date('F j, Y');

    return "<!doctype html><html><body style='background:#120D08;color:#fff;font-family:Arial,sans-serif;padding:24px'>
    <table cellpadding='0' cellspacing='0' style='max-width:600px;margin:0 auto;background:#1A120B;border:1px solid #251A10;border-radius:18px;overflow:hidden'>
    <tr><td style='background:linear-gradient(135deg,#E08A1A,#FBBF24);padding:22px;text-align:center'>
      <div style='font-family:\"Bebas Neue\",Impact,sans-serif;font-size:24px;letter-spacing:.08em;color:#1A120B;font-weight:900'>MAM ACADEMY</div>
    </td></tr>
    <tr><td style='padding:30px 28px;color:#FBF7F3;line-height:1.6'>
      <h2 style='font-family:Georgia,serif;margin:0 0 6px;font-size:20px;color:#fff'>" . htmlspecialchars($title) . "</h2>
      <p style='color:rgba(255,255,255,0.7);font-size:13px;margin:0 0 18px'>Welcome to MAM Academy — \"Crafting the Timeless in Time.\" Please keep this policy for your records.</p>
      <table cellpadding='0' cellspacing='0' style='width:100%'>{$rows}</table>
      <div style='margin-top:22px;padding:16px 18px;background:#251A10;border:1px dashed #FBBF24;border-radius:10px'>
        <div style='color:rgba(255,255,255,0.7);font-size:12px;text-transform:uppercase;letter-spacing:.08em'>Agreed &amp; electronically signed</div>
        <div style='color:#fff;font-size:16px;margin-top:4px'><strong>{$sig}</strong></div>
        <div style='color:rgba(255,255,255,0.7);font-size:13px;margin-top:2px'>{$date} · Application {$ref}</div>
      </div>
      <p style='margin-top:24px;color:rgba(255,255,255,0.6);font-size:13px'>ACADEMIC DIRECTOR · MAM Centre, 2 Oremeji Street, Egbeda, Lagos · <a href='mailto:connect@mamcentre.org' style='color:#FBBF24'>connect@mamcentre.org</a> · +234-704-444-6517</p>
    </td></tr></table>
    </body></html>";
}
