<?php
/**
 * Minimal mailer: SMTP if MAIL_HOST is set, otherwise PHP mail().
 * Supports plain text or HTML.
 */
declare(strict_types=1);

function mam_send_mail(string $to, string $subject, string $body, bool $html = false, array $opts = []): bool {
    $from      = $opts['from']      ?? mam_env('MAIL_FROM_EMAIL', 'no-reply@mamcentre.org');
    $fromName  = $opts['from_name'] ?? mam_env('MAIL_FROM_NAME', 'MAM Academy');
    $replyTo   = $opts['reply_to']  ?? null;
    $host      = mam_env('MAIL_HOST', '');

    if ($host) {
        return mam_smtp_send($host, (int)mam_env('MAIL_PORT', '587'),
            mam_env('MAIL_USERNAME', ''), mam_env('MAIL_PASSWORD', ''),
            strtolower((string)mam_env('MAIL_ENCRYPTION', 'tls')),
            $from, $fromName, $replyTo, $to, $subject, $body, $html);
    }

    // PHP mail() fallback
    $headers = [];
    $headers[] = 'From: ' . mb_encode_mimeheader($fromName) . " <$from>";
    if ($replyTo) $headers[] = "Reply-To: $replyTo";
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = $html ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'X-Mailer: MAM-Academy';
    $ok = @mail($to, $subject, $body, implode("\r\n", $headers));
    if (!$ok) mam_log('warn', 'mail_failed', ['to' => $to, 'subject' => $subject]);
    return (bool)$ok;
}

/**
 * Tiny SMTP client (no external deps). Supports STARTTLS and SSL.
 * Designed to handle the common shared-host case (Gmail SMTP relay,
 * SendGrid SMTP, Postmark, Mailgun, etc.).
 */
function mam_smtp_send(string $host, int $port, string $user, string $pass, string $encryption,
                       string $from, string $fromName, ?string $replyTo,
                       string $to, string $subject, string $body, bool $html): bool {
    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host;
    $errno = 0; $errstr = '';
    $sock = @stream_socket_client("$remote:$port", $errno, $errstr, 20);
    if (!$sock) { mam_log('error', 'smtp_connect_failed', ['err' => $errstr]); return false; }
    stream_set_timeout($sock, 30);

    $get = function () use ($sock) {
        $resp = '';
        while ($line = fgets($sock, 1024)) {
            $resp .= $line;
            if (preg_match('/^\d{3} /', $line)) break;
        }
        return $resp;
    };
    $send = function ($cmd) use ($sock, $get) {
        fwrite($sock, $cmd . "\r\n");
        return $get();
    };

    $banner = $get();
    if (substr($banner, 0, 3) !== '220') { fclose($sock); return false; }

    $ehlo = $send('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'mamcentre.org'));
    if (substr($ehlo, 0, 3) !== '250') { fclose($sock); return false; }

    if ($encryption === 'tls') {
        if (substr($send('STARTTLS'), 0, 3) !== '220') { fclose($sock); return false; }
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($sock); return false; }
        $send('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'mamcentre.org'));
    }

    if ($user !== '') {
        $send('AUTH LOGIN');
        $send(base64_encode($user));
        $resp = $send(base64_encode($pass));
        if (substr($resp, 0, 3) !== '235') { mam_log('error', 'smtp_auth_failed'); fclose($sock); return false; }
    }

    $send('MAIL FROM:<' . $from . '>');
    $send('RCPT TO:<' . $to . '>');
    $send('DATA');

    $head = [];
    $head[] = 'From: ' . mb_encode_mimeheader($fromName) . " <$from>";
    $head[] = "To: $to";
    if ($replyTo) $head[] = "Reply-To: $replyTo";
    $head[] = 'Subject: ' . mb_encode_mimeheader($subject);
    $head[] = 'Date: ' . date('r');
    $head[] = 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . ($_SERVER['SERVER_NAME'] ?? 'mam.local') . '>';
    $head[] = 'MIME-Version: 1.0';
    $head[] = $html ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8';
    $head[] = 'Content-Transfer-Encoding: 8bit';
    $head[] = 'X-Mailer: MAM-Academy';

    // Dot-stuff
    $bodyEsc = preg_replace('/^\./m', '..', $body);
    $payload = implode("\r\n", $head) . "\r\n\r\n" . $bodyEsc . "\r\n.";
    $resp = $send($payload);
    $send('QUIT');
    fclose($sock);
    return substr($resp, 0, 3) === '250';
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
