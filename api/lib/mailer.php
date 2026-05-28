<?php
/**
 * Minimal mailer: SMTP if MAIL_HOST is set, otherwise PHP mail().
 * Supports plain text or HTML.
 */
declare(strict_types=1);

/**
 * Convert an HTML email body to a serviceable plain-text alternative.
 * No DOM parsing — fast, dependency-free, "good enough" for transactional
 * mail. Preserves <a href> URLs inline (strip_tags would drop them
 * silently), keeps line breaks at block boundaries, decodes entities.
 */
function mam_html_to_text(string $html): string {
    // Preserve link URLs BEFORE strip_tags wipes them out. If the link text
    // is the URL itself (or empty), just keep the URL once; otherwise emit
    // "text (https://…)".
    $h = preg_replace_callback('#<a\s+[^>]*href=(["\'])([^"\']+)\1[^>]*>(.*?)</a>#is', function ($m) {
        $url  = trim($m[2]);
        $text = trim(strip_tags($m[3]));
        if ($text === '' || strcasecmp($text, $url) === 0) return $url;
        return $text . ' (' . $url . ')';
    }, $html);
    // Inject newlines at block boundaries before strip_tags so paragraphs survive
    $h = preg_replace('#<br\s*/?>#i', "\n", (string)$h);
    $h = preg_replace('#</(p|div|tr|li|h[1-6])>#i', "\n\n", (string)$h);
    $h = preg_replace('#<li[^>]*>#i', '• ', (string)$h);
    $text = strip_tags((string)$h);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Collapse runs of whitespace; preserve double newlines as paragraph breaks.
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

/**
 * fwrite can return fewer bytes than requested on slow or congested
 * sockets; without a retry loop the SMTP DATA payload silently truncates
 * and the server then sees a malformed message. Always use this.
 */
function mam_socket_write_all($sock, string $data): bool {
    $len = strlen($data);
    $off = 0;
    while ($off < $len) {
        $n = @fwrite($sock, substr($data, $off));
        if ($n === false || $n === 0) return false;
        $off += $n;
    }
    return true;
}

/**
 * Resolve the EHLO hostname the server should see. Prefers the explicit
 * MAIL_EHLO_HOST env (rare but useful when running behind NAT), falls
 * back to the request's SERVER_NAME, then APP_URL's host, then PHP's
 * gethostname() — never a hardcoded marketing domain.
 */
function mam_resolve_ehlo_host(): string {
    $explicit = (string)mam_env('MAIL_EHLO_HOST', '');
    if ($explicit !== '') return $explicit;
    if (!empty($_SERVER['SERVER_NAME']) && filter_var('http://' . $_SERVER['SERVER_NAME'], FILTER_VALIDATE_URL)) {
        return $_SERVER['SERVER_NAME'];
    }
    $appHost = parse_url((string)mam_env('APP_URL', ''), PHP_URL_HOST);
    if ($appHost) return $appHost;
    $hn = gethostname();
    return $hn !== false && $hn !== '' ? $hn : 'localhost';
}

/** True when PHPMailer is autoloadable (composer install or bundled vendor). */
function mam_phpmailer_available(): bool {
    return class_exists('PHPMailer\\PHPMailer\\PHPMailer');
}

/**
 * Send via PHPMailer when it's installed. Honours the same MAIL_* env as the
 * built-in client and returns the same {ok,transport,host,error,steps} shape
 * so callers (mam_send, the CLI tester, the admin endpoint) don't care which
 * transport ran.
 */
function mam_send_via_phpmailer(string $to, string $subject, string $body, bool $html, string $from, string $fromName, ?string $replyTo): array {
    $host = (string)mam_env('MAIL_HOST', '');
    $user = (string)mam_env('MAIL_USERNAME', '');
    $pass = (string)mam_env('MAIL_PASSWORD', '');
    $enc  = strtolower((string)mam_env('MAIL_ENCRYPTION', 'tls'));
    $mailClass = 'PHPMailer\\PHPMailer\\PHPMailer';
    $mail = new $mailClass(true); // exceptions on
    try {
        $mail->isSMTP();
        $mail->Host        = $host;
        $mail->Port        = (int)mam_env('MAIL_PORT', '587');
        $mail->Timeout     = 20;
        $mail->CharSet     = 'UTF-8';
        $mail->Hostname    = mam_resolve_ehlo_host();
        if ($user !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = $pass;
        }
        if ($enc === 'ssl')      $mail->SMTPSecure = 'ssl';
        elseif ($enc === 'tls')  $mail->SMTPSecure = 'tls';
        else                     $mail->SMTPSecure = '';
        $mail->setFrom($from, $fromName);
        // Authenticated relays usually require the envelope sender to match the
        // login — mirror the built-in client's behaviour.
        if ($user !== '' && strpos($user, '@') !== false) $mail->Sender = $user;
        $mail->addAddress($to);
        if ($replyTo) $mail->addReplyTo($replyTo);
        $mail->Subject = $subject;
        if ($html) {
            $mail->isHTML(true);
            $mail->Body    = $body;
            $mail->AltBody = mam_html_to_text($body);
        } else {
            $mail->isHTML(false);
            $mail->Body = $body;
        }
        $mail->send();
        return ['ok' => true, 'transport' => 'phpmailer', 'host' => $host, 'attempts' => 1, 'error' => null, 'steps' => []];
    } catch (Throwable $e) {
        $info = isset($mail->ErrorInfo) ? (string)$mail->ErrorInfo : $e->getMessage();
        mam_log('error', 'phpmailer_send_failed', ['err' => $e->getMessage(), 'info' => $info, 'to' => $to]);
        return ['ok' => false, 'transport' => 'phpmailer', 'host' => $host, 'attempts' => 1, 'error' => $info, 'steps' => []];
    }
}

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
    $forceSmtp = in_array(strtolower((string)mam_env('MAIL_FORCE_SMTP', '0')), ['1','true','yes','on'], true);

    // Operators can pin the mailer to SMTP only by setting MAIL_FORCE_SMTP=1
    // in api/.env. Useful in production where the host's mail() function
    // delivers to a black hole — we'd rather hard-fail (and log) than
    // silently pretend an email was sent.
    if ($forceSmtp && !$host) {
        mam_log('error', 'mail_force_smtp_no_host', [
            'hint' => 'MAIL_FORCE_SMTP=1 in api/.env but MAIL_HOST is empty. Set MAIL_HOST or unset MAIL_FORCE_SMTP.',
            'to' => $to, 'subject' => $subject,
        ]);
        return [
            'ok' => false, 'transport' => null, 'host' => null,
            'error' => 'MAIL_FORCE_SMTP is set but MAIL_HOST is empty', 'steps' => [],
        ];
    }

    if ($host) {
        // Transport preference: PHPMailer when it's installed (composer
        // install / bundled vendor), otherwise the built-in SMTP client.
        // Both honour the same MAIL_* env and produce the same result shape.
        if (mam_phpmailer_available()) {
            return mam_send_via_phpmailer($to, $subject, $body, $html, $from, $fromName, $replyTo);
        }
        // Retry-once-on-transient: 4xx replies are usually rate-limit or
        // greylisting and clear on a second attempt. 5xx is permanent —
        // don't waste time. The retry uses a fresh connection.
        $attempts = 0; $maxAttempts = 2; $transcript = []; $ok = false; $failedStep = null;
        while ($attempts < $maxAttempts) {
            $attempts++;
            $transcript = [];
            $ok = mam_smtp_send($host, (int)mam_env('MAIL_PORT', '587'),
                mam_env('MAIL_USERNAME', ''), mam_env('MAIL_PASSWORD', ''),
                strtolower((string)mam_env('MAIL_ENCRYPTION', 'tls')),
                $from, $fromName, $replyTo, $to, $subject, $body, $html, $transcript);
            $failedStep = null;
            foreach ($transcript as $s) {
                if (!($s['ok'] ?? true)) { $failedStep = $s; break; }
            }
            if ($ok) break;
            $code = $failedStep ? substr(trim((string)($failedStep['reply'] ?? '')), 0, 1) : '';
            // 4xx = transient → retry once with a short pause. Anything else (5xx,
            // connect failure) is treated as permanent.
            if ($code !== '4' || $attempts >= $maxAttempts) break;
            usleep(500000); // 0.5 s pause before retry
            mam_log('info', 'smtp_retry', ['step' => $failedStep['step'] ?? '', 'reply' => $failedStep['reply'] ?? '', 'attempt' => $attempts]);
        }
        return [
            'ok'        => $ok,
            'transport' => 'smtp',
            'host'      => $host,
            'attempts'  => $attempts,
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
    // Use the byte-safe writer so a partial fwrite() can't truncate a
    // 4 KB EHLO payload (or the multi-KB DATA payload further down).
    $send = function ($cmd) use ($sock, $get) { mam_socket_write_all($sock, $cmd . "\r\n"); return $get(); };
    // Verify a response code; on mismatch log the exact server reply and bail.
    $expect = function ($resp, array $codes, string $step) use ($sock, &$transcript) {
        $reply = trim((string)$resp);
        $ok = in_array(substr((string)$resp, 0, 3), $codes, true);
        $transcript[] = ['step' => $step, 'ok' => $ok, 'reply' => $reply];
        if (!$ok) {
            mam_log('error', 'smtp_step_failed', ['step' => $step, 'reply' => $reply]);
            mam_socket_write_all($sock, "QUIT\r\n"); fclose($sock);
            return false;
        }
        return true;
    };

    if (!$expect($get(), ['220'], 'banner')) return false;

    $ehloName = mam_resolve_ehlo_host();
    // Capture the FULL EHLO reply (multi-line, 250-EXT) so we can detect
    // server-advertised extensions like 8BITMIME and PIPELINING.
    $ehloResp = $send('EHLO ' . $ehloName);
    if (!$expect($ehloResp, ['250'], 'ehlo')) return false;
    $has8bitmime = stripos($ehloResp, '8BITMIME') !== false;

    if ($encryption === 'tls') {
        if (!$expect($send('STARTTLS'), ['220'], 'starttls')) return false;
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            mam_log('error', 'smtp_tls_failed');
            $transcript[] = ['step' => 'tls_handshake', 'ok' => false, 'reply' => 'stream_socket_enable_crypto returned false'];
            fclose($sock);
            return false;
        }
        $transcript[] = ['step' => 'tls_handshake', 'ok' => true, 'reply' => 'TLS negotiated'];
        // Extensions must be re-advertised after STARTTLS — re-detect 8BITMIME.
        $ehloResp = $send('EHLO ' . $ehloName);
        if (!$expect($ehloResp, ['250'], 'ehlo_tls')) return false;
        $has8bitmime = stripos($ehloResp, '8BITMIME') !== false;
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
    $head[] = 'X-Mailer: MAM-Academy';

    // Pick a Content-Transfer-Encoding that the server can actually accept.
    // If the relay advertises 8BITMIME we can send raw UTF-8 as 8bit;
    // otherwise fall back to base64 so multi-byte glyphs (₦, €, em-dashes
    // and any emoji in the templates) survive 7-bit-only relays intact.
    // quoted-printable would also work but base64 is bulletproof and the
    // size hit is irrelevant at email scale.
    $encode = function (string $s) use ($has8bitmime): array {
        if ($has8bitmime) return ['8bit', $s];
        return ['base64', chunk_split(base64_encode($s), 76, "\r\n")];
    };

    // Build a multipart/alternative body when we have HTML: include a
    // plain-text fallback. Most spam filters score multipart messages
    // higher than HTML-only, and screen readers fall back gracefully.
    if ($html) {
        $boundary = 'mam_' . bin2hex(random_bytes(8));
        $head[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        [$cteText, $partText] = $encode(mam_html_to_text($body));
        [$cteHtml, $partHtml] = $encode($body);
        $bodyOut = "--$boundary\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: $cteText\r\n\r\n"
                 . $partText . "\r\n"
                 . "--$boundary\r\n"
                 . "Content-Type: text/html; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: $cteHtml\r\n\r\n"
                 . $partHtml . "\r\n"
                 . "--$boundary--\r\n";
    } else {
        [$cte, $partBody] = $encode($body);
        $head[] = 'Content-Type: text/plain; charset=UTF-8';
        $head[] = "Content-Transfer-Encoding: $cte";
        $bodyOut = $partBody;
    }

    // Order matters: NORMALISE line endings to CRLF first, THEN dot-stuff.
    // If you dot-stuff before normalising, "\n.foo" never matches the
    // ^\. regex (because ^ only sees the start of the buffer) and the
    // server treats the bare-dot line as end-of-DATA. Subtle data loss.
    $bodyEsc = preg_replace('/\r\n|\r|\n/', "\r\n", $bodyOut);
    $bodyEsc = preg_replace('/^\./m', '..', $bodyEsc);
    $payload = implode("\r\n", $head) . "\r\n\r\n" . $bodyEsc . "\r\n.\r\n";
    // Use the byte-safe writer for the multi-KB payload so a partial
    // fwrite never silently truncates the message.
    if (!mam_socket_write_all($sock, $payload)) {
        mam_log('error', 'smtp_write_failed', ['bytes' => strlen($payload)]);
        $transcript[] = ['step' => 'body', 'ok' => false, 'reply' => 'partial socket write'];
        fclose($sock);
        return false;
    }
    $ok = $expect($get(), ['250'], 'body');
    if ($ok) { mam_socket_write_all($sock, "QUIT\r\n"); fclose($sock); }
    return $ok;
}

/**
 * Compute the next "first Wednesday of the month" occurrence for Power Play,
 * mirroring the client-side logic in power-play.html. Returns an array with
 * a DateTimeImmutable plus pre-formatted display strings. Reads schedule
 * knobs from env so it stays in lockstep with admin configuration:
 *   POWERPLAY_TIME      (24h "HH:MM", default 19:00)
 *   POWERPLAY_TIMEZONE  (IANA tz,   default Africa/Lagos)
 *   POWERPLAY_MEET_URL  (Google Meet room link, optional)
 */
function mam_powerplay_next(): array {
    $tz   = new DateTimeZone((string)mam_env('POWERPLAY_TIMEZONE', 'Africa/Lagos'));
    $time = (string)mam_env('POWERPLAY_TIME', '19:00');
    [$hh, $mm] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

    $now = new DateTimeImmutable('now', $tz);
    $firstWed = function (int $year, int $month) use ($tz, $hh, $mm): DateTimeImmutable {
        $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz);
        // 3 = Wednesday (ISO-8601: Mon=1 … Sun=7)
        $offset = (3 - (int)$d->format('N') + 7) % 7;
        return $d->modify("+{$offset} days")->setTime($hh, $mm);
    };
    $candidate = $firstWed((int)$now->format('Y'), (int)$now->format('n'));
    if ($candidate < $now) {
        $next = $now->modify('first day of next month');
        $candidate = $firstWed((int)$next->format('Y'), (int)$next->format('n'));
    }
    return [
        'dt'      => $candidate,
        'date'    => $candidate->format('l, F j, Y'),
        'time'    => $candidate->format('g:i A T'),
        'meetUrl' => (string)mam_env('POWERPLAY_MEET_URL', ''),
    ];
}

/**
 * Pre-rendered HTML templates for transactional emails.
 */
function mam_email_powerplay_confirmation(): string {
    $pp   = mam_powerplay_next();
    $date = htmlspecialchars($pp['date'], ENT_QUOTES);
    $time = htmlspecialchars($pp['time'], ENT_QUOTES);
    $meet = $pp['meetUrl'];
    $cta  = $meet
        ? "<p style='text-align:center;margin:26px 0'><a href='" . htmlspecialchars($meet, ENT_QUOTES) . "' style='display:inline-block;padding:14px 28px;background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#1A120B;border-radius:8px;font-weight:800;text-decoration:none;letter-spacing:0.04em;text-transform:uppercase;font-size:14px'>Join the Google Meet</a></p>"
        : "<p style='color:rgba(255,255,255,0.7);font-size:14px;text-align:center;margin:24px 0'>We'll email you the Google Meet link 24 hours before the session.</p>";
    return "<!doctype html><html><body style='background:#120D08;color:#fff;font-family:Arial,sans-serif;padding:24px'>
    <table cellpadding='0' cellspacing='0' style='max-width:560px;margin:0 auto;background:#1A120B;border:1px solid #251A10;border-radius:18px;overflow:hidden'>
    <tr><td style='background:linear-gradient(135deg,#6366F1,#A5B4FC);padding:22px;text-align:center'>
      <div style='font-family:\"Bebas Neue\",Impact,sans-serif;font-size:24px;letter-spacing:.08em;color:#120D08;font-weight:900'>⚡ POWER PLAY</div>
    </td></tr>
    <tr><td style='padding:32px 28px;color:#FBF7F3;line-height:1.7'>
      <h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>You're registered for Power Play.</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px'>A free, raw, 90-minute session with <strong>Van. BOJA</strong> for musicians, artists and culture-shapers. Here's the next one:</p>
      <div style='background:#251A10;border:1px dashed #A5B4FC;border-radius:10px;padding:18px;text-align:center;margin:18px 0'>
        <div style='font-size:13px;color:#A5B4FC;text-transform:uppercase;letter-spacing:0.1em;font-weight:800'>Next Session</div>
        <div style='font-size:18px;color:#fff;font-weight:700;margin-top:6px'>$date</div>
        <div style='font-size:15px;color:#FBBF24;margin-top:2px'>$time</div>
      </div>
      $cta
      <p style='color:rgba(255,255,255,0.7);font-size:14px'>Power Play runs the <strong>first Wednesday of every month</strong> — add it to your calendar so you never miss it.</p>
      <p style='font-size:13px;color:rgba(255,255,255,0.55);margin-top:24px'>Want the full structured path — certification, instructors, ensemble &amp; stage time? <a href='https://academy.mamcentre.org/apply' style='color:#FBBF24'>Apply to MAM Academy</a>.</p>
      <p style='margin-top:20px;color:rgba(255,255,255,0.6);font-size:13px'>— MAM Centre, Lagos · <a href='mailto:connect@mamcentre.org' style='color:#FBBF24'>connect@mamcentre.org</a></p>
    </td></tr></table>
    </body></html>";
}

/** Shared shell so every transactional email keeps one consistent look. */
function mam_email_shell(string $headerLabel, string $innerHtml, string $accent = '#E08A1A,#FBBF24'): string {
    return "<!doctype html><html><body style='background:#120D08;color:#fff;font-family:Arial,sans-serif;padding:24px'>
    <table cellpadding='0' cellspacing='0' style='max-width:560px;margin:0 auto;background:#1A120B;border:1px solid #251A10;border-radius:18px;overflow:hidden'>
    <tr><td style='background:linear-gradient(135deg,$accent);padding:22px;text-align:center'>
      <div style='font-family:\"Bebas Neue\",Impact,sans-serif;font-size:24px;letter-spacing:.08em;color:#1A120B;font-weight:900'>$headerLabel</div>
    </td></tr>
    <tr><td style='padding:32px 28px;color:#FBF7F3;line-height:1.7'>$innerHtml
      <p style='margin-top:24px;color:rgba(255,255,255,0.6);font-size:13px'>— MAM Centre, Lagos · <a href='mailto:connect@mamcentre.org' style='color:#FBBF24'>connect@mamcentre.org</a></p>
    </td></tr></table></body></html>";
}

/** New portal account created by an admin — sends the temporary credentials. */
function mam_email_account_created(string $name, string $email, string $tempPassword, string $role, string $loginUrl): string {
    $n = htmlspecialchars($name ?: 'there', ENT_QUOTES);
    $e = htmlspecialchars($email, ENT_QUOTES);
    $p = htmlspecialchars($tempPassword, ENT_QUOTES);
    $r = htmlspecialchars(ucfirst($role), ENT_QUOTES);
    $u = htmlspecialchars($loginUrl, ENT_QUOTES);
    $inner = "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>Your MAM Academy $r account is ready, $n</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px'>An administrator created a portal account for you. Sign in with these temporary credentials and you'll be asked to set your own password right away.</p>
      <div style='background:#251A10;border:1px dashed #FBBF24;border-radius:10px;padding:14px 18px;font-family:monospace;font-size:14px;color:#FBBF24;margin:16px 0'>
        <div>Email: <strong>$e</strong></div><div>Temporary password: <strong>$p</strong></div></div>
      <p style='text-align:center;margin:24px 0'><a href='$u' style='display:inline-block;padding:14px 28px;background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#1A120B;border-radius:8px;font-weight:800;text-decoration:none;letter-spacing:0.04em;text-transform:uppercase;font-size:14px'>Sign in to your portal</a></p>
      <p style='font-size:13px;color:rgba(255,255,255,0.55)'>If you weren't expecting this, please contact us — don't share these credentials.</p>";
    return mam_email_shell('MAM ACADEMY', $inner);
}

/** Security notice: password was changed (admin reset, self-service, or token reset). */
function mam_email_password_changed(string $name, string $context = 'changed'): string {
    $n = htmlspecialchars($name ?: 'there', ENT_QUOTES);
    $how = $context === 'admin' ? 'reset by an administrator' : 'changed';
    $extra = $context === 'admin'
        ? "You'll be asked to set your own password the next time you sign in."
        : "If this was you, no action is needed.";
    $inner = "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>Your password was $how</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px'>Hi $n — this is a security notice that the password on your MAM Academy account was just $how. $extra</p>
      <p style='font-size:13px;color:rgba(255,255,255,0.7);margin-top:14px'><strong>Didn't do this?</strong> Contact us immediately at <a href='mailto:connect@mamcentre.org' style='color:#FBBF24'>connect@mamcentre.org</a> so we can secure your account.</p>";
    return mam_email_shell('MAM ACADEMY', $inner);
}

/** Enrolment confirmation when an admin places a student into a class. */
function mam_email_enrolled(string $name, string $className, string $schedule, string $loginUrl): string {
    $n = htmlspecialchars($name ?: 'there', ENT_QUOTES);
    $c = htmlspecialchars($className ?: 'your class', ENT_QUOTES);
    $s = htmlspecialchars($schedule ?: 'To be announced', ENT_QUOTES);
    $u = htmlspecialchars($loginUrl, ENT_QUOTES);
    $inner = "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>You're enrolled, $n!</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px'>You've been placed into <strong style='color:#FBBF24'>$c</strong>. Here's what we have on the schedule:</p>
      <div style='background:#251A10;border:1px dashed #FBBF24;border-radius:10px;padding:14px 18px;color:#FBBF24;margin:16px 0'>$s</div>
      <p style='text-align:center;margin:24px 0'><a href='$u' style='display:inline-block;padding:14px 28px;background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#1A120B;border-radius:8px;font-weight:800;text-decoration:none;letter-spacing:0.04em;text-transform:uppercase;font-size:14px'>View my classes</a></p>";
    return mam_email_shell('MAM ACADEMY', $inner);
}

/**
 * Recovery email for an application that was submitted but never paid.
 * Sent by the api/tools/recovery-email.php cron a day after abandonment.
 */
function mam_email_application_recovery(array $app, array $customer): string {
    $name = htmlspecialchars(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')) ?: 'there', ENT_QUOTES);
    $ref  = htmlspecialchars((string)($app['reference'] ?? ''), ENT_QUOTES);
    $tot  = number_format((float)($app['total_usd'] ?? 0), 2);
    $resumeUrl = 'https://academy.mamcentre.org/apply?ref=' . rawurlencode((string)($app['reference'] ?? ''));
    return "<!doctype html><html><body style='background:#120D08;color:#fff;font-family:Arial,sans-serif;padding:24px'>
    <table cellpadding='0' cellspacing='0' style='max-width:560px;margin:0 auto;background:#1A120B;border:1px solid #251A10;border-radius:18px;overflow:hidden'>
    <tr><td style='background:linear-gradient(135deg,#E08A1A,#FBBF24);padding:22px;text-align:center'>
      <div style='font-family:\"Bebas Neue\",Impact,sans-serif;font-size:24px;letter-spacing:.08em;color:#1A120B;font-weight:900'>MAM ACADEMY</div>
    </td></tr>
    <tr><td style='padding:32px 28px;color:#FBF7F3;line-height:1.7'>
      <h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>You're one step away, $name.</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px'>We saved your MAM Academy application (<strong style='color:#FBBF24'>$ref</strong>) but it looks like checkout didn't finish. Your seat for the next intake isn't confirmed until payment is complete.</p>
      <div style='background:#251A10;border:1px dashed #FBBF24;border-radius:10px;padding:14px 18px;text-align:center;margin:18px 0'>
        <span style='color:rgba(255,255,255,0.7);font-size:13px'>Balance to confirm your seat</span><br>
        <strong style='color:#FBBF24;font-size:20px'>USD \${$tot}</strong>
      </div>
      <p style='text-align:center;margin:26px 0'><a href='" . htmlspecialchars($resumeUrl, ENT_QUOTES) . "' style='display:inline-block;padding:14px 28px;background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#1A120B;border-radius:8px;font-weight:800;text-decoration:none;letter-spacing:0.04em;text-transform:uppercase;font-size:14px'>Complete my enrolment</a></p>
      <p style='color:rgba(255,255,255,0.7);font-size:14px'>Intakes fill on a first-paid basis. If you have a question or want to pay by bank transfer, just reply to this email or call <strong>+234 704 444 6517</strong> — a real person will help.</p>
      <p style='font-size:13px;color:rgba(255,255,255,0.5);margin-top:24px'>If you've changed your mind, no worries — you can ignore this and we won't email you about it again.</p>
    </td></tr></table>
    </body></html>";
}

function mam_email_password_reset(string $name, string $resetUrl): string {
    $n = htmlspecialchars($name ?: 'there', ENT_QUOTES);
    $u = htmlspecialchars($resetUrl, ENT_QUOTES);
    return "<!doctype html><html><body style='background:#120D08;color:#fff;font-family:Arial,sans-serif;padding:24px'>
    <table cellpadding='0' cellspacing='0' style='max-width:560px;margin:0 auto;background:#1A120B;border:1px solid #251A10;border-radius:18px;overflow:hidden'>
    <tr><td style='background:linear-gradient(135deg,#E08A1A,#FBBF24);padding:22px;text-align:center'>
      <div style='font-family:\"Bebas Neue\",Impact,sans-serif;font-size:24px;letter-spacing:.08em;color:#1A120B;font-weight:900'>MAM ACADEMY</div>
    </td></tr>
    <tr><td style='padding:32px 28px;color:#FBF7F3;line-height:1.7'>
      <h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>Reset your password, $n</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px'>Click the button below to set a new password. The link works once and expires in <strong>60 minutes</strong>.</p>
      <p style='text-align:center;margin:28px 0'><a href='$u' style='display:inline-block;padding:14px 28px;background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#1A120B;border-radius:8px;font-weight:800;text-decoration:none;letter-spacing:0.04em;text-transform:uppercase;font-size:14px'>Set new password</a></p>
      <p style='font-size:13px;color:rgba(255,255,255,0.6);line-height:1.65'>If the button doesn't work, copy and paste this link into your browser:<br><span style='word-break:break-all;color:#FBBF24'>$u</span></p>
      <p style='font-size:13px;color:rgba(255,255,255,0.5);margin-top:24px'>Didn't request this? You can ignore this email — your password stays the same. The link expires automatically.</p>
      <p style='margin-top:28px;color:rgba(255,255,255,0.6);font-size:13px'>— MAM Centre, Lagos · <a href='mailto:connect@mamcentre.org' style='color:#FBBF24'>connect@mamcentre.org</a></p>
    </td></tr></table>
    </body></html>";
}

/**
 * Centralised, logged wrapper around mam_send_mail. Every caller passes
 * a short `context` string so failures surface in the logs with enough
 * information to debug — no more silent @ suppression.
 */
function mam_send(string $to, string $subject, string $body, string $context, bool $html = true, array $opts = []): bool {
    $result = mam_send_mail_verbose($to, $subject, $body, $html, $opts);
    if (!$result['ok']) {
        mam_log('warn', 'mail_delivery_failed', [
            'context'   => $context,
            'to'        => $to,
            'subject'   => $subject,
            'transport' => $result['transport'] ?? null,
            'error'     => $result['error']     ?? null,
        ]);
    } else {
        mam_log('info', 'mail_delivered', [
            'context' => $context, 'to' => $to,
            'transport' => $result['transport'] ?? null,
            'attempts'  => $result['attempts']  ?? 1,
        ]);
    }
    return (bool)$result['ok'];
}

/**
 * Welcome email sent to every new newsletter subscriber.
 */
function mam_email_newsletter_welcome(string $source = 'site'): string {
    $site = htmlspecialchars($source, ENT_QUOTES);
    return "<!doctype html><html><body style='background:#120D08;color:#fff;font-family:Arial,sans-serif;padding:24px'>
    <table cellpadding='0' cellspacing='0' style='max-width:560px;margin:0 auto;background:#1A120B;border:1px solid #251A10;border-radius:18px;overflow:hidden'>
    <tr><td style='background:linear-gradient(135deg,#E08A1A,#FBBF24);padding:22px;text-align:center'>
      <div style='font-family:\"Bebas Neue\",Impact,sans-serif;font-size:24px;letter-spacing:.08em;color:#1A120B;font-weight:900'>MAM ACADEMY</div>
    </td></tr>
    <tr><td style='padding:32px 28px;color:#FBF7F3;line-height:1.7'>
      <h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>You're on the inside.</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px'>Thanks for joining the MAM Academy circle. Once a month you'll get:</p>
      <ul style='color:rgba(255,255,255,0.86);font-size:15px;line-height:1.85;padding-left:18px;margin:14px 0'>
        <li>The date &amp; theme of the next free <strong style='color:#FBBF24'>Power Play with Van. BOJA</strong></li>
        <li>Open intake dates &amp; new programme launches</li>
        <li>Student showcases, faculty essays &amp; long-form notes on the MAM Method</li>
      </ul>
      <p style='color:rgba(255,255,255,0.86);font-size:15px'>If you're ready to enrol, you can start your application in five guided steps:</p>
      <p style='text-align:center;margin:24px 0'><a href='https://academy.mamcentre.org/apply' style='display:inline-block;padding:14px 28px;background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#1A120B;border-radius:8px;font-weight:800;text-decoration:none;letter-spacing:0.04em;text-transform:uppercase;font-size:14px'>Begin your application</a></p>
      <p style='font-size:13px;color:rgba(255,255,255,0.5);margin-top:24px'>You'll only ever get these monthly notes — no daily noise. Reply with \"unsubscribe\" any time and we'll take you off the list immediately.</p>
      <p style='margin-top:24px;color:rgba(255,255,255,0.6);font-size:13px'>— MAM Centre, Lagos · subscribed via <code style='color:#FBBF24'>$site</code></p>
    </td></tr></table>
    </body></html>";
}

/**
 * Auto-responder for the public contact form. Confirms receipt to the
 * sender and surfaces faster routes (apply, FAQ, phone) so they aren't
 * stuck waiting if they had a simple question.
 */
function mam_email_contact_autoresponse(string $name, string $topic): string {
    $n = htmlspecialchars($name ?: 'there', ENT_QUOTES);
    $t = htmlspecialchars($topic ?: 'your message', ENT_QUOTES);
    return "<!doctype html><html><body style='background:#120D08;color:#fff;font-family:Arial,sans-serif;padding:24px'>
    <table cellpadding='0' cellspacing='0' style='max-width:560px;margin:0 auto;background:#1A120B;border:1px solid #251A10;border-radius:18px;overflow:hidden'>
    <tr><td style='background:linear-gradient(135deg,#E08A1A,#FBBF24);padding:22px;text-align:center'>
      <div style='font-family:\"Bebas Neue\",Impact,sans-serif;font-size:24px;letter-spacing:.08em;color:#1A120B;font-weight:900'>MAM ACADEMY</div>
    </td></tr>
    <tr><td style='padding:32px 28px;color:#FBF7F3;line-height:1.7'>
      <h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>Got it, $n.</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px'>Your message about <strong>$t</strong> is with our team. We reply to every contact-form message within <strong>24 hours</strong> (often much sooner during Lagos working hours).</p>
      <p style='color:rgba(255,255,255,0.86);font-size:15px;margin-top:18px'>If your question is time-sensitive, these are the fastest routes:</p>
      <div style='margin:16px 0;padding:16px 18px;background:#251A10;border:1px solid #3A2A1A;border-radius:10px'>
        <p style='margin:0 0 8px;font-size:14px'>📞 <strong style='color:#FBBF24'>+234 704 444 6517</strong> · Mon–Fri 9 am – 6 pm WAT</p>
        <p style='margin:0 0 8px;font-size:14px'>✉️ <a href='mailto:connect@mamcentre.org' style='color:#FBBF24'>connect@mamcentre.org</a> · WhatsApp on the same number</p>
        <p style='margin:0;font-size:14px'>🎓 Ready to apply? <a href='https://academy.mamcentre.org/apply' style='color:#FBBF24'>apply</a> · 5 steps, auto-saved on this device</p>
      </div>
      <p style='font-size:13px;color:rgba(255,255,255,0.55);margin-top:24px'>You're getting this once — it's our acknowledgement that we received your note. A real human will reply from this same address shortly.</p>
    </td></tr></table>
    </body></html>";
}

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
