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
function mam_send_via_phpmailer(string $to, string $subject, string $body, bool $html, string $from, string $fromName, ?string $replyTo, array $extraHeaders = [], array $attachments = []): array {
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
        foreach ($extraHeaders as $hk => $hv) { $mail->addCustomHeader($hk, $hv); }
        foreach ($attachments as $a) {
            if (!empty($a['content']) && !empty($a['name'])) {
                $mail->addStringAttachment($a['content'], $a['name'], 'base64', $a['mime'] ?? 'application/octet-stream');
            }
        }
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
    $extraHeaders = is_array($opts['headers'] ?? null) ? $opts['headers'] : [];
    $attachments  = is_array($opts['attachments'] ?? null) ? $opts['attachments'] : [];

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
            return mam_send_via_phpmailer($to, $subject, $body, $html, $from, $fromName, $replyTo, $extraHeaders, $attachments);
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
                $from, $fromName, $replyTo, $to, $subject, $body, $html, $extraHeaders, $attachments, $transcript);
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
    foreach ($extraHeaders as $hk => $hv) { $headers[] = $hk . ': ' . $hv; }
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
                       array $extraHeaders = [], array $attachments = [], array &$transcript = null): bool {
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
    foreach ($extraHeaders as $hk => $hv) { $head[] = $hk . ': ' . $hv; }

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

    // Build the message "content" as a self-contained MIME part (its own
    // headers + body) so it can stand alone OR be nested inside
    // multipart/mixed when attachments are present. HTML gets a
    // multipart/alternative (plain + html) for deliverability + a11y.
    if ($html) {
        $altB = 'alt_' . bin2hex(random_bytes(8));
        [$cteText, $partText] = $encode(mam_html_to_text($body));
        [$cteHtml, $partHtml] = $encode($body);
        $contentHeaders = ['Content-Type: multipart/alternative; boundary="' . $altB . '"'];
        $contentBody = "--$altB\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: $cteText\r\n\r\n$partText\r\n"
                     . "--$altB\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: $cteHtml\r\n\r\n$partHtml\r\n"
                     . "--$altB--\r\n";
    } else {
        [$cte, $partBody] = $encode($body);
        $contentHeaders = ['Content-Type: text/plain; charset=UTF-8', "Content-Transfer-Encoding: $cte"];
        $contentBody = $partBody . "\r\n";
    }

    // Keep only well-formed attachments.
    $atts = [];
    foreach ($attachments as $a) { if (!empty($a['content']) && !empty($a['name'])) $atts[] = $a; }

    if ($atts) {
        // Wrap content + attachments in multipart/mixed.
        $mixB = 'mix_' . bin2hex(random_bytes(8));
        $head[] = 'Content-Type: multipart/mixed; boundary="' . $mixB . '"';
        $bodyOut = "--$mixB\r\n" . implode("\r\n", $contentHeaders) . "\r\n\r\n" . $contentBody;
        foreach ($atts as $a) {
            $name = preg_replace('/[\r\n"\\\\]/', '', (string)$a['name']);
            $mime = preg_replace('/[\r\n]/', '', (string)($a['mime'] ?? 'application/octet-stream'));
            $bodyOut .= "--$mixB\r\n"
                      . "Content-Type: $mime; name=\"$name\"\r\n"
                      . "Content-Transfer-Encoding: base64\r\n"
                      . "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n"
                      . chunk_split(base64_encode((string)$a['content']), 76, "\r\n");
        }
        $bodyOut .= "--$mixB--\r\n";
    } else {
        // No attachments — the content part's headers become the message headers.
        foreach ($contentHeaders as $h) $head[] = $h;
        $bodyOut = $contentBody;
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
 * Cross-promotion block appended to transactional emails so every message
 * tastefully advertises the rest of the catalogue. Prices come from the
 * single server-side source (mam_prices) so they never drift. Pass keys to
 * $exclude to drop programmes the recipient is already engaged with
 * (e.g. 'powerplay' in the Power Play confirmation). Email-client-safe:
 * tables + inline styles only.
 */
function mam_email_promo_block(array $exclude = []): string {
    $p = mam_prices();
    $base = 'https://academy.mamcentre.org/';
    $items = [
        'powerplay' => ['⚡ Power Play with Van. BOJA', 'Free monthly online session for musicians & culture-shapers.', 'Free', 'power-play'],
        'crash'     => ['Crash Programme', 'A focused 6-week intensive — quick, real wins.', '$' . $p['crash'], 'crash'],
        'pathways'  => ['Certification Pathways', 'Trinity-equivalent grades, online or onsite.', 'from $' . $p['pre-cert'], 'pathways'],
        'metamusic' => ['The MetaMusic Order', '12-week post-graduate cultural-architecture track.', '$' . $p['meta-order'], 'metamusic-order'],
    ];
    $rows = '';
    foreach ($items as $key => $it) {
        if (in_array($key, $exclude, true)) continue;
        [$name, $desc, $price, $slug] = $it;
        $rows .= "<tr>
          <td style='padding:12px 0;border-bottom:1px solid #251A10;vertical-align:top'>
            <a href='" . $base . $slug . "' style='color:#FBBF24;font-weight:700;font-size:15px;text-decoration:none'>" . htmlspecialchars($name, ENT_QUOTES) . " &rarr;</a>
            <div style='color:rgba(255,255,255,0.7);font-size:13px;margin-top:3px;line-height:1.5'>" . htmlspecialchars($desc, ENT_QUOTES) . "</div>
          </td>
          <td style='padding:12px 0 12px 14px;border-bottom:1px solid #251A10;text-align:right;vertical-align:top;white-space:nowrap'>
            <span style='color:#fff;font-size:14px;font-weight:700'>" . htmlspecialchars($price, ENT_QUOTES) . "</span>
          </td></tr>";
    }
    if ($rows === '') return '';
    return "<div style='margin-top:28px;padding:18px 18px 6px;background:#160F09;border:1px solid #251A10;border-radius:12px'>
      <div style='font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:#A89682;font-weight:800;margin-bottom:2px'>More ways to grow with MAM</div>
      <table cellpadding='0' cellspacing='0' style='width:100%'>" . $rows . "</table>
    </div>";
}

/**
 * Bulletproof CTA button. Renders VML for Outlook (which ignores padding and
 * border-radius on <a>) and a normal padded anchor everywhere else, so the
 * button looks right across Gmail, Apple Mail, Outlook desktop and mobile.
 */
function mam_email_button(string $url, string $label, string $accent = '#E08A1A,#FBBF24'): string {
    $u = htmlspecialchars($url, ENT_QUOTES);
    $l = htmlspecialchars($label, ENT_QUOTES);
    $solid = trim(explode(',', $accent)[0]);
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:26px auto"><tr>'
      . '<td align="center" bgcolor="' . $solid . '" style="border-radius:8px">'
      . '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $u . '" style="height:46px;v-text-anchor:middle;width:300px;" arcsize="18%" strokecolor="' . $solid . '" fillcolor="' . $solid . '"><w:anchorlock/><center style="color:#1A120B;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;letter-spacing:0.04em">' . $l . '</center></v:roundrect><![endif]-->'
      . '<!--[if !mso]><!--><a href="' . $u . '" style="display:inline-block;padding:14px 30px;background:linear-gradient(135deg,' . $accent . ');background-color:' . $solid . ';color:#1A120B;border-radius:8px;font-family:Arial,Helvetica,sans-serif;font-weight:bold;text-decoration:none;letter-spacing:0.04em;text-transform:uppercase;font-size:14px">' . $l . '</a><!--<![endif]-->'
      . '</td></tr></table>';
}

/**
 * Responsive, Outlook-compatible, legally-compliant email layout used by every
 * template. Provides: viewport + dark/light color-scheme meta, a fluid
 * max-width:600px container, a hidden preheader, MSO ghost-table wrapping, and
 * a footer carrying the sender's legal name + physical postal address, contact
 * details and the "why you're receiving this" line (CAN-SPAM, GDPR, NDPR/NDPA).
 * Marketing emails (unsubscribe=true) additionally emit a visible unsubscribe
 * link via the {{UNSUB_URL}} placeholder, which mam_send fills in and pairs
 * with RFC 8058 one-click List-Unsubscribe headers.
 *
 *   header      short banner label                (default 'MAM ACADEMY')
 *   accent      'c1,c2' gradient (solid = c1)      (default gold)
 *   preheader   inbox preview text
 *   body        inner HTML (content only)
 *   reason      one-line "why you got this"
 *   unsubscribe bool — render unsubscribe + one-click headers
 */
function mam_email_layout(array $o): string {
    $accent    = $o['accent'] ?? '#E08A1A,#FBBF24';
    $label     = htmlspecialchars($o['header'] ?? 'MAM ACADEMY', ENT_QUOTES);
    $inner     = $o['body'] ?? '';
    $preheader = htmlspecialchars($o['preheader'] ?? '', ENT_QUOTES);
    $reason    = htmlspecialchars($o['reason'] ?? 'You received this email from MAM Academy.', ENT_QUOTES);
    $marketing = !empty($o['unsubscribe']);
    $solid     = trim(explode(',', $accent)[0]);
    $year      = date('Y');

    $address = 'MAM Centre · 2 Oremeji Street, Egbeda, Lagos, Nigeria';
    $privacy = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/privacy';
    $contact = "<a href='mailto:connect@mamcentre.org' style='color:#FBBF24;text-decoration:none'>connect@mamcentre.org</a> &middot; +234 704 444 6517 &middot; <a href='" . htmlspecialchars($privacy, ENT_QUOTES) . "' style='color:#FBBF24;text-decoration:none'>Privacy</a>";

    $unsubLine = $marketing
        ? "<p style='margin:12px 0 0;font-size:12px;color:rgba(255,255,255,0.55);line-height:1.7'>" . $reason
          . " <a href='{{UNSUB_URL}}' style='color:#FBBF24'>Unsubscribe</a> from marketing emails at any time — we honour every request promptly. Transactional messages (security, applications) are unaffected.</p>"
        : "<p style='margin:12px 0 0;font-size:12px;color:rgba(255,255,255,0.5);line-height:1.7'>" . $reason . "</p>";

    return '<!doctype html><html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">'
      . '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
      . '<meta http-equiv="X-UA-Compatible" content="IE=edge"><meta name="x-apple-disable-message-reformatting">'
      . '<meta name="color-scheme" content="dark light"><meta name="supported-color-schemes" content="dark light">'
      . '<title>' . $label . '</title>'
      . '<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->'
      . '<style>'
      . 'body{margin:0;padding:0;width:100%!important;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}'
      . 'table{border-collapse:collapse}td{mso-line-height-rule:exactly}'
      . 'img{border:0;line-height:100%;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic}'
      . 'a{color:#FBBF24}'
      . '@media only screen and (max-width:600px){.email-container{width:100%!important;border-radius:0!important}.px{padding-left:20px!important;padding-right:20px!important}.h1{font-size:20px!important}}'
      . '</style></head>'
      . '<body style="margin:0;padding:0;background:#120D08">'
      . '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#120D08">' . $preheader . '&#8203;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;</div>'
      . '<center style="width:100%;background:#120D08">'
      . '<!--[if mso]><table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" width="600"><tr><td><![endif]-->'
      . '<table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" width="100%" class="email-container" style="max-width:600px;margin:0 auto;background:#1A120B;border-radius:16px;overflow:hidden">'
      . '<tr><td class="px" align="center" bgcolor="' . $solid . '" style="background:linear-gradient(135deg,' . $accent . ');background-color:' . $solid . ';padding:24px;text-align:center">'
      .   '<div style="font-family:Georgia,\'Times New Roman\',serif;font-size:23px;letter-spacing:0.08em;color:#1A120B;font-weight:bold">' . $label . '</div>'
      . '</td></tr>'
      . '<tr><td class="px" style="background:#1A120B;padding:32px 28px;color:#FBF7F3;font-family:Arial,Helvetica,sans-serif;line-height:1.7;font-size:15px">'
      .   $inner
      . '</td></tr>'
      . '<tr><td class="px" style="background:#160F09;padding:22px 28px;border-top:1px solid #251A10;font-family:Arial,Helvetica,sans-serif">'
      .   "<p style='margin:0;font-size:12px;color:rgba(255,255,255,0.65);line-height:1.7'><strong style='color:rgba(255,255,255,0.85)'>MAM Academy</strong> — a programme of MAM Centre.<br>" . $address . "<br>" . $contact . "</p>"
      .   $unsubLine
      .   "<p style='margin:12px 0 0;font-size:11px;color:rgba(255,255,255,0.4)'>&copy; " . $year . " MAM Centre &middot; &ldquo;Crafting the Timeless in Time.&rdquo;</p>"
      . '</td></tr>'
      . '</table>'
      . '<!--[if mso]></td></tr></table><![endif]-->'
      . '</center></body></html>';
}

/**
 * Pre-rendered HTML templates for transactional emails. Each builds its inner
 * content and routes through mam_email_layout for one compliant, responsive,
 * cross-client shell.
 */
function mam_email_powerplay_confirmation(): string {
    $pp   = mam_powerplay_next();
    $date = htmlspecialchars($pp['date'], ENT_QUOTES);
    $time = htmlspecialchars($pp['time'], ENT_QUOTES);
    $meet = $pp['meetUrl'];
    $cta  = $meet
        ? mam_email_button($meet, 'Join the Google Meet', '#6366F1,#A5B4FC')
        : "<p style='color:rgba(255,255,255,0.7);font-size:14px;text-align:center;margin:24px 0'>We'll email you the Google Meet link 24 hours before the session.</p>";
    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>You're registered for Power Play.</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px;margin:0'>A free, raw, 90-minute session with <strong>Van. BOJA</strong> for musicians, artists and culture-shapers. Here's the next one:</p>
      <div style='background:#251A10;border:1px dashed #A5B4FC;border-radius:10px;padding:18px;text-align:center;margin:18px 0'>
        <div style='font-size:13px;color:#A5B4FC;text-transform:uppercase;letter-spacing:0.1em;font-weight:800'>Next Session</div>
        <div style='font-size:18px;color:#fff;font-weight:700;margin-top:6px'>$date</div>
        <div style='font-size:15px;color:#FBBF24;margin-top:2px'>$time</div>
      </div>
      $cta
      <p style='color:rgba(255,255,255,0.7);font-size:14px;margin:0'>Power Play runs the <strong>first Wednesday of every month</strong> — add it to your calendar so you never miss it.</p>
      <p style='font-size:13px;color:rgba(255,255,255,0.55);margin:18px 0 0'>Want the full structured path — certification, instructors, ensemble &amp; stage time? <a href='https://academy.mamcentre.org/apply' style='color:#FBBF24'>Apply to MAM Academy</a>.</p>
      " . mam_email_promo_block(['powerplay']);
    return mam_email_layout([
        'header' => '⚡ POWER PLAY', 'accent' => '#6366F1,#A5B4FC',
        'preheader' => 'You are registered — here is the date for the next free Power Play with Van. BOJA.',
        'reason' => 'You received this because you registered for Power Play on academy.mamcentre.org.',
        'unsubscribe' => true, 'body' => $inner,
    ]);
}

/** Backward-compatible thin wrapper over mam_email_layout (transactional). */
function mam_email_shell(string $headerLabel, string $innerHtml, string $accent = '#E08A1A,#FBBF24'): string {
    return mam_email_layout(['header' => $headerLabel, 'accent' => $accent, 'body' => $innerHtml]);
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
      " . mam_email_button($loginUrl, 'Sign in to your portal') . "
      <p style='font-size:13px;color:rgba(255,255,255,0.55);margin:0'>If you weren't expecting this, please contact us — don't share these credentials.</p>";
    return mam_email_layout([
        'header' => 'MAM ACADEMY',
        'preheader' => 'Your MAM Academy portal account and temporary password.',
        'reason' => 'You received this because an administrator created a MAM Academy portal account for you.',
        'body' => $inner,
    ]);
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
      <p style='font-size:13px;color:rgba(255,255,255,0.7);margin:14px 0 0'><strong>Didn't do this?</strong> Contact us immediately at <a href='mailto:connect@mamcentre.org' style='color:#FBBF24'>connect@mamcentre.org</a> so we can secure your account.</p>";
    return mam_email_layout([
        'header' => 'MAM ACADEMY',
        'preheader' => 'Security notice: your MAM Academy password was changed.',
        'reason' => 'You received this security notice because the password on your MAM Academy account was changed.',
        'body' => $inner,
    ]);
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
      " . mam_email_button($loginUrl, 'View my classes') . "";
    $inner .= mam_email_promo_block(['pathways']);
    return mam_email_layout([
        'header' => 'MAM ACADEMY',
        'preheader' => 'You have been enrolled in a MAM Academy class.',
        'reason' => 'You received this because you were enrolled in a class at MAM Academy.',
        'unsubscribe' => true,
        'body' => $inner,
    ]);
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
    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>You're one step away, $name.</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px;margin:0'>We saved your MAM Academy application (<strong style='color:#FBBF24'>$ref</strong>) but it looks like checkout didn't finish. Your seat for the next intake isn't confirmed until payment is complete.</p>
      <div style='background:#251A10;border:1px dashed #FBBF24;border-radius:10px;padding:14px 18px;text-align:center;margin:18px 0'>
        <span style='color:rgba(255,255,255,0.7);font-size:13px'>Balance to confirm your seat</span><br>
        <strong style='color:#FBBF24;font-size:20px'>USD \${$tot}</strong>
      </div>
      " . mam_email_button($resumeUrl, 'Complete my enrolment') . "
      <p style='color:rgba(255,255,255,0.7);font-size:14px;margin:0'>Intakes fill on a first-paid basis. If you have a question or want to pay by bank transfer, just reply to this email or call <strong>+234 704 444 6517</strong> — a real person will help.</p>
      " . mam_email_promo_block(['pathways']);
    return mam_email_layout([
        'header' => 'MAM ACADEMY',
        'preheader' => 'Your application is saved — complete checkout to confirm your seat.',
        'reason' => 'You received this because you started an application with MAM Academy.',
        'unsubscribe' => true,
        'body' => $inner,
    ]);
}

function mam_email_password_reset(string $name, string $resetUrl): string {
    $n = htmlspecialchars($name ?: 'there', ENT_QUOTES);
    $u = htmlspecialchars($resetUrl, ENT_QUOTES);
    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>Reset your password, $n</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px;margin:0'>Click the button below to set a new password. The link works once and expires in <strong>60 minutes</strong>.</p>
      " . mam_email_button($resetUrl, 'Set new password') . "
      <p style='font-size:13px;color:rgba(255,255,255,0.6);line-height:1.65;margin:0'>If the button doesn't work, copy and paste this link into your browser:<br><span style='word-break:break-all;color:#FBBF24'>$u</span></p>
      <p style='font-size:13px;color:rgba(255,255,255,0.5);margin:24px 0 0'>Didn't request this? You can ignore this email — your password stays the same. The link expires automatically.</p>";
    return mam_email_layout([
        'header' => 'MAM ACADEMY',
        'preheader' => 'Reset your MAM Academy password — link valid for 60 minutes.',
        'reason' => 'You received this because a password reset was requested for your MAM Academy account.',
        'body' => $inner,
    ]);
}

/**
 * Centralised, logged wrapper around mam_send_mail. Every caller passes
 * a short `context` string so failures surface in the logs with enough
 * information to debug — no more silent @ suppression.
 */
/** Stable, signed unsubscribe token for an email (HMAC over the app key). */
function mam_unsubscribe_token(string $email): string {
    return substr(hash_hmac('sha256', strtolower(trim($email)), mam_app_key()), 0, 32);
}
/** One-click unsubscribe URL (handled by routes/newsletter-unsubscribe.php). */
function mam_unsubscribe_url(string $email): string {
    $base = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');
    return $base . '/api/newsletter/unsubscribe?e=' . rawurlencode(strtolower(trim($email))) . '&t=' . mam_unsubscribe_token($email);
}

function mam_send(string $to, string $subject, string $body, string $context, bool $html = true, array $opts = []): bool {
    // Marketing emails embed {{UNSUB_URL}} via the layout. When present, fill
    // the visible link AND attach RFC 8058 one-click List-Unsubscribe headers,
    // keyed to the recipient — so every promotional email is CAN-SPAM / GDPR /
    // NDPA compliant automatically, with no work for the calling route.
    if (strpos($body, '{{UNSUB_URL}}') !== false) {
        $url = mam_unsubscribe_url($to);
        $body = str_replace('{{UNSUB_URL}}', htmlspecialchars($url, ENT_QUOTES), $body);
        $mailto = 'mailto:' . mam_env('NOTIFY_EMAIL', 'connect@mamcentre.org') . '?subject=unsubscribe';
        $opts['headers'] = array_merge($opts['headers'] ?? [], [
            'List-Unsubscribe'      => '<' . $url . '>, <' . $mailto . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
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
    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>You're on the inside.</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px;margin:0'>Thanks for joining the MAM Academy circle. Once a month you'll get:</p>
      <ul style='color:rgba(255,255,255,0.86);font-size:15px;line-height:1.85;padding-left:18px;margin:14px 0'>
        <li>The date &amp; theme of the next free <strong style='color:#FBBF24'>Power Play with Van. BOJA</strong></li>
        <li>Open intake dates &amp; new programme launches</li>
        <li>Student showcases, faculty essays &amp; long-form notes on the MAM Method</li>
      </ul>
      <p style='color:rgba(255,255,255,0.86);font-size:15px;margin:0'>If you're ready to enrol, you can start your application in five guided steps:</p>
      " . mam_email_button('https://academy.mamcentre.org/apply', 'Begin your application') . "
      " . mam_email_promo_block(['powerplay']);
    return mam_email_layout([
        'header' => 'MAM ACADEMY',
        'preheader' => 'Welcome to the MAM Academy circle — here is what to expect.',
        'reason' => "You received this because you subscribed to MAM Academy updates (via $site).",
        'unsubscribe' => true,
        'body' => $inner,
    ]);
}

/**
 * Auto-responder for the public contact form. Confirms receipt to the
 * sender and surfaces faster routes (apply, FAQ, phone) so they aren't
 * stuck waiting if they had a simple question.
 */
function mam_email_contact_autoresponse(string $name, string $topic): string {
    $n = htmlspecialchars($name ?: 'there', ENT_QUOTES);
    $t = htmlspecialchars($topic ?: 'your message', ENT_QUOTES);
    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>Got it, $n.</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px;margin:0'>Your message about <strong>$t</strong> is with our team. We reply to every contact-form message within <strong>24 hours</strong> (often much sooner during Lagos working hours).</p>
      <p style='color:rgba(255,255,255,0.86);font-size:15px;margin:18px 0 0'>If your question is time-sensitive, these are the fastest routes:</p>
      <div style='margin:16px 0;padding:16px 18px;background:#251A10;border:1px solid #3A2A1A;border-radius:10px'>
        <p style='margin:0 0 8px;font-size:14px'>📞 <strong style='color:#FBBF24'>+234 704 444 6517</strong> · Mon–Fri 9 am – 6 pm WAT</p>
        <p style='margin:0 0 8px;font-size:14px'>✉️ <a href='mailto:connect@mamcentre.org' style='color:#FBBF24'>connect@mamcentre.org</a> · WhatsApp on the same number</p>
        <p style='margin:0;font-size:14px'>🎓 Ready to apply? <a href='https://academy.mamcentre.org/apply' style='color:#FBBF24'>apply</a> · 5 steps, auto-saved on this device</p>
      </div>
      " . mam_email_promo_block() . "
      <p style='font-size:13px;color:rgba(255,255,255,0.55);margin:24px 0 0'>You're getting this once — it's our acknowledgement that we received your note. A real human will reply from this same address shortly.</p>";
    return mam_email_layout([
        'header' => 'MAM ACADEMY',
        'preheader' => 'We received your message — here is how to reach us faster.',
        'reason' => 'You received this because you contacted MAM Academy through our website.',
        'unsubscribe' => true,
        'body' => $inner,
    ]);
}

function mam_email_application_confirmation(array $app, array $customer, ?array $tier = null, string $invoiceNumber = ''): string {
    $ref  = htmlspecialchars($app['reference'], ENT_QUOTES);
    $name = htmlspecialchars(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')), ENT_QUOTES);
    $bookUrl = htmlspecialchars(rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/book?ref=' . rawurlencode($app['reference']), ENT_QUOTES);
    $label   = htmlspecialchars($tier['label'] ?? '$5', ENT_QUOTES);
    $invLine = $invoiceNumber ? "<p style='color:rgba(255,255,255,0.6);font-size:12px;margin:6px 0 0'>Invoice <strong style='color:#FBBF24'>" . htmlspecialchars($invoiceNumber) . "</strong> is attached to this email — open it in any browser, then print or save as PDF for your records.</p>" : '';
    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>Application received, $name.</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px;margin:0'>Thank you for applying to MAM Academy. Your application reference is:</p>
      <div style='background:#251A10;border:1px dashed #FBBF24;border-radius:10px;padding:14px 18px;font-family:monospace;color:#FBBF24;font-size:16px;text-align:center;margin:16px 0'>$ref</div>
      <p style='color:rgba(255,255,255,0.86);font-size:15px;margin:0'>Programme: <strong style='color:#fff'>" . htmlspecialchars($app['pathway']) . "</strong> &middot; Mode: <strong style='color:#fff'>" . htmlspecialchars($app['mode']) . "</strong></p>
      <p style='color:rgba(255,255,255,0.86);font-size:15px;margin:20px 0 0'><strong style='color:#fff'>Next step &mdash; book your session.</strong> A one-time <strong style='color:#FBBF24'>$label confirmation</strong> secures your slot with our Admin Officer and the MAM Auditer Judges. It covers your <strong>registration</strong>, <strong>student ID card</strong>, <strong>student file</strong> and a <strong>30-minute MAM Auditer assessment</strong>. No tuition is charged yet &mdash; we only ask for tuition after we have placed you correctly.</p>
      " . mam_email_button($bookUrl, 'Book my session &mdash; ' . $label) . "
      <p style='color:rgba(255,255,255,0.7);font-size:13px;margin:0'>Slots are limited each week. Bookings without payment will be held for 48 hours, then released.</p>"
      . $invLine
      . mam_email_promo_block(['pathways']);
    return mam_email_layout([
        'header' => 'MAM ACADEMY',
        'preheader' => 'Your application is in. Invoice attached — reserve your seat with our Admin Officer + MAM Auditer Judges.',
        'reason' => 'You received this because you submitted an application to MAM Academy.',
        'unsubscribe' => true,
        'body' => $inner,
    ]);
}

/**
 * MAM Academy invoice (HTML, self-contained, print-friendly).
 *
 * Mirrors the studio's printed invoice layout: black header with address +
 * logo, a giant orange "INVOICE" word, customer block, item table with orange
 * header, T&C + totals row, GTCO bank tile + Finance Manager signature line,
 * black footer. Self-contained inline CSS so it renders identically when
 * attached to email, opened in a browser, or printed to PDF.
 *
 * Generated from the applicant's selections so the invoice always summarises
 * exactly what they chose. The TOTAL is what they owe NOW (the regional
 * booking fee). Tuition is listed as an estimate due after Auditer placement
 * — transparent, no surprises.
 *
 * $app + $customer come from the DB; $tier is the result of mam_booking_tier().
 */
function mam_invoice_print_html(array $app, array $customer, array $tier, string $invoiceNumber): string {
    $name  = htmlspecialchars(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')), ENT_QUOTES);
    $phone = htmlspecialchars((string)($customer['phone'] ?? ''), ENT_QUOTES);
    $email = htmlspecialchars((string)($customer['email'] ?? ''), ENT_QUOTES);
    $loc   = htmlspecialchars(trim(implode(', ', array_filter([$customer['lga'] ?? '', $customer['state'] ?? '', $customer['country'] ?? '']))) ?: 'Nigeria', ENT_QUOTES);
    $ref   = htmlspecialchars((string)$app['reference'], ENT_QUOTES);
    $dateLong = date('l, jS \\of F, Y');

    $pathwayMap = [
        'pre-cert'   => ['Pre-Certification (Foundation)',    'Online · 12 weeks'],
        'cert'       => ['Certification (KTAMS / MAM)',       'Online · 12 weeks'],
        'pre-dip'    => ['Pre-Diploma',                       'Online · 12 weeks'],
        'diploma'    => ['Diploma',                           'Online · 12 weeks'],
        'crash'      => ['Crash Programme',                   'Intensive · short-form'],
        'meta-order' => ['The MetaMusic Order',               'Premium track'],
    ];
    $pathwayKey = (string)($app['pathway'] ?? '');
    $pathwayName = $pathwayMap[$pathwayKey][0] ?? ucfirst($pathwayKey);
    $pathwaySub  = $pathwayMap[$pathwayKey][1] ?? '';
    $mode = htmlspecialchars((string)($app['mode'] ?? '—'), ENT_QUOTES);
    $disc = htmlspecialchars((string)($app['discipline'] ?? '—'), ENT_QUOTES);
    $track = htmlspecialchars((string)($app['program_track'] ?? 'MAM'), ENT_QUOTES);

    $prices = mam_prices();
    $tuitionUsd = (float)($prices[$pathwayKey] ?? 0);

    $cur = (string)$tier['currency'];
    $label = (string)$tier['label'];
    // Per-line allocation of the regional booking fee. Splits the total into
    // four equal items for clean display; rounded so the sum still equals.
    $total = (float)$tier['amount'];
    $each  = $cur === 'NGN' ? floor($total / 4) : round($total / 4, 2);
    $remainder = $total - ($each * 3); // last line absorbs rounding
    $fmt = function($n) use ($cur) {
        $sym = ['NGN'=>'₦','USD'=>'$','GBP'=>'£','EUR'=>'€','CAD'=>'CA$','AUD'=>'AU$','GHS'=>'GH₵','KES'=>'KSh ','ZAR'=>'R'][$cur] ?? ($cur.' ');
        return $sym . number_format($n, $cur === 'NGN' ? 0 : 2);
    };

    $logoUrl = htmlspecialchars(rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/mam-academy-logo.svg', ENT_QUOTES);
    $invNo   = htmlspecialchars($invoiceNumber, ENT_QUOTES);

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
      . '<title>Invoice ' . $invNo . ' — MAM Academy</title>'
      . '<meta name="viewport" content="width=device-width,initial-scale=1">'
      . '<style>'
      . '@page{size:A4;margin:0}'
      . 'body{margin:0;font-family:Inter,Arial,Helvetica,sans-serif;color:#1A120B;background:#F4F4F4;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
      . '.sheet{max-width:820px;margin:24px auto;background:#fff;box-shadow:0 6px 28px rgba(0,0,0,0.12)}'
      . '.bar-top{background:#0E0A06;color:#fff;padding:30px 44px;display:flex;justify-content:space-between;align-items:center;gap:22px}'
      . '.bar-top .addr{font-size:12.5px;line-height:1.6;color:#fff;border-bottom:2px solid #E08A1A;padding-bottom:8px;display:inline-block;max-width:320px}'
      . '.bar-top .tax{font-size:11.5px;color:#cdcdcd;margin-top:8px;display:block;letter-spacing:0.04em}'
      . '.bar-top .logo{width:96px;height:96px;border:1.5px solid #fff;display:flex;align-items:center;justify-content:center;background:#0E0A06}'
      . '.bar-top .logo img{width:78%;height:auto;display:block}'
      . '.body{padding:32px 44px 24px;background:#fff}'
      . '.titlerow{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;padding-bottom:18px;border-bottom:1px solid #e0e0e0;margin-bottom:24px}'
      . '.title{font-family:"Bebas Neue",Impact,sans-serif;font-size:88px;line-height:0.85;color:#E04A1A;letter-spacing:0.02em;margin:0;text-transform:uppercase}'
      . '.meta{font-size:13px;color:#3a2a1a;line-height:1.7;padding-left:18px;border-left:2px solid #E04A1A;min-width:230px}'
      . '.meta b{color:#1A120B}'
      . '.who{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:26px}'
      . '.who .col{padding-left:16px;border-left:2px solid #E04A1A}'
      . '.who .col p{margin:6px 0;font-size:13.5px;color:#1A120B;line-height:1.55}'
      . '.who .col p.label{font-size:11.5px;color:#7a7a7a;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:2px}'
      . '.summary{padding:14px 16px;background:#FFF6EF;border:1px solid #FBE3CC;border-radius:8px;margin-bottom:22px;font-size:12.5px;color:#3a2a1a;line-height:1.65}'
      . '.summary b{color:#1A120B}'
      . 'table.items{width:100%;border-collapse:collapse;margin-bottom:14px;font-size:13px}'
      . 'table.items thead th{background:#F2F2F2;color:#E04A1A;text-align:left;font-weight:800;font-size:13px;padding:14px 16px;border-bottom:2px solid #E04A1A}'
      . 'table.items thead th.r{text-align:right}'
      . 'table.items tbody td{padding:14px 16px;border-bottom:1px solid #ECECEC;vertical-align:top;color:#1A120B}'
      . 'table.items tbody td.r{text-align:right;font-variant-numeric:tabular-nums}'
      . 'table.items tbody td .desc{display:block;color:#7a7a7a;font-size:12px;font-style:italic;margin-top:3px}'
      . '.bottom{display:grid;grid-template-columns:1.2fr 1fr;gap:32px;margin-top:18px;margin-bottom:24px}'
      . '.tcs h4{color:#E04A1A;font-size:13.5px;margin:0 0 8px;letter-spacing:0.02em}'
      . '.tcs p{margin:0;font-size:12px;color:#3a2a1a;line-height:1.6}'
      . '.totals{font-size:13.5px}'
      . '.totals .row{display:flex;justify-content:space-between;align-items:center;padding:8px 4px;border-bottom:1px dotted #cdcdcd}'
      . '.totals .row b{font-variant-numeric:tabular-nums}'
      . '.totals .grand{margin-top:14px;background:#E04A1A;color:#fff;padding:18px 22px;border-radius:100px;display:flex;justify-content:space-between;align-items:center}'
      . '.totals .grand span{font-family:"Bebas Neue",Impact,sans-serif;letter-spacing:0.06em;font-size:22px}'
      . '.totals .grand b{font-family:"Bebas Neue",Impact,sans-serif;font-size:24px;letter-spacing:0.04em}'
      . '.tuition-note{margin:6px 4px 0;font-size:11.5px;color:#7a7a7a;text-align:right}'
      . '.sign{display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:flex-end;padding-top:16px;border-top:1px solid #e0e0e0;margin-bottom:24px}'
      . '.bank{display:flex;align-items:center;gap:12px}'
      . '.bank .gtco{background:#E04A1A;color:#fff;font-weight:800;font-size:11px;letter-spacing:0.05em;padding:14px 14px;border-radius:4px;text-align:center;line-height:1}'
      . '.bank .gtco div{font-size:18px;margin-top:4px}'
      . '.bank .bk{font-family:"Bebas Neue",Impact,sans-serif;font-size:24px;color:#1A120B;letter-spacing:0.02em;line-height:1}'
      . '.bank .bk b{display:block;font-size:18px;color:#E04A1A;margin-top:4px;font-family:Inter,Arial,sans-serif;font-weight:800;letter-spacing:0}'
      . '.sig{text-align:right;border-bottom:1px solid #1A120B;padding-bottom:6px}'
      . '.sig .nm{font-size:13.5px;color:#1A120B;font-weight:600}'
      . '.sig .role{font-size:12px;color:#E04A1A;font-weight:800;margin-top:3px}'
      . '.bar-bottom{background:#0E0A06;color:#cdcdcd;text-align:center;padding:14px 22px;font-size:12px;letter-spacing:0.02em}'
      . '@media print{body{background:#fff}.sheet{box-shadow:none;margin:0;max-width:none}}'
      . '</style></head><body>'
      . '<div class="sheet">'

      // Black top bar
      . '<div class="bar-top">'
      .   '<div>'
      .     '<span class="addr">2, Abolude / Oremeji Street,<br>Bakery B/S, Egbeda, Lagos</span>'
      .     '<span class="tax">TAX ID: 119 491 230001</span>'
      .   '</div>'
      .   '<div class="logo"><img src="' . $logoUrl . '" alt="MAM Academy"></div>'
      . '</div>'

      // Title row
      . '<div class="body">'
      .   '<div class="titlerow">'
      .     '<h1 class="title">Invoice</h1>'
      .     '<div class="meta">'
      .       'Invoice: <b>' . $invNo . '</b><br>'
      .       'Date: <b>' . $dateLong . '</b>'
      .     '</div>'
      .   '</div>'

      // Customer block
      .   '<div class="who">'
      .     '<div class="col">'
      .       '<p class="label">Billed to</p>'
      .       '<p style="font-size:15px;font-weight:700;margin:2px 0">' . $name . '</p>'
      .       '<p>' . $loc . '</p>'
      .       '<p style="font-size:11.5px;color:#7a7a7a">Application reference: <b style="color:#E04A1A">' . $ref . '</b></p>'
      .     '</div>'
      .     '<div class="col">'
      .       '<p class="label">Contact</p>'
      .       '<p>' . ($phone ?: '—') . '</p>'
      .       '<p>' . ($email ?: '—') . '</p>'
      .     '</div>'
      .   '</div>'

      // Programme summary (their choices)
      .   '<div class="summary">'
      .     '<b>Programme summary:</b> ' . htmlspecialchars($pathwayName) . ($pathwaySub ? ' &middot; <span style="color:#7a7a7a">' . htmlspecialchars($pathwaySub) . '</span>' : '')
      .     '<br><b>Track:</b> ' . $track . ' &middot; <b>Mode:</b> ' . $mode . ' &middot; <b>Discipline:</b> ' . $disc
      .   '</div>'

      // Items table — booking-fee components
      .   '<table class="items" cellspacing="0" cellpadding="0">'
      .     '<thead><tr>'
      .       '<th>Item / Description</th>'
      .       '<th class="r" style="width:60px">QTY</th>'
      .       '<th class="r" style="width:110px">Unit Price</th>'
      .       '<th class="r" style="width:110px">Total</th>'
      .     '</tr></thead>'
      .     '<tbody>'
      .       '<tr><td><b>REGISTRATION &amp; ADMISSION</b><span class="desc">Application processing &amp; admission</span></td>'
      .         '<td class="r">1</td><td class="r">' . $fmt($each) . '</td><td class="r">' . $fmt($each) . '</td></tr>'
      .       '<tr><td><b>MAM ACADEMY STUDENT ID CARD</b><span class="desc">Physical ID issued at the Centre</span></td>'
      .         '<td class="r">1</td><td class="r">' . $fmt($each) . '</td><td class="r">' . $fmt($each) . '</td></tr>'
      .       '<tr><td><b>STUDENT FILE</b><span class="desc">Onboarding documentation kept on record</span></td>'
      .         '<td class="r">1</td><td class="r">' . $fmt($each) . '</td><td class="r">' . $fmt($each) . '</td></tr>'
      .       '<tr><td><b>MAM AUDITER ASSESSMENT</b><span class="desc">30-minute placement session with our judges</span></td>'
      .         '<td class="r">1</td><td class="r">' . $fmt($remainder) . '</td><td class="r">' . $fmt($remainder) . '</td></tr>'
      .     '</tbody>'
      .   '</table>'

      // T&C + Totals
      .   '<div class="bottom">'
      .     '<div class="tcs">'
      .       '<h4>Term and Conditions</h4>'
      .       '<p>Our booking-confirmation fee is due before the scheduled session and is final once paid. Tuition is a separate charge, billed only after the MAM Auditer Judges have placed you accurately. Payment terms otherwise per the academy class policy.</p>'
      .     '</div>'
      .     '<div class="totals">'
      .       '<div class="row"><span>Subtotal</span><b>' . $label . '</b></div>'
      .       '<div class="row"><span>Discount (NIL)</span><b>' . $label . '</b></div>'
      .       '<div class="grand"><span>TOTAL</span><b>' . $label . '</b></div>'
      .       ($tuitionUsd > 0 ? '<div class="tuition-note">Estimated tuition (due after placement): <b>$' . number_format($tuitionUsd, 2) . '</b></div>' : '')
      .     '</div>'
      .   '</div>'

      // Signature row
      .   '<div class="sign">'
      .     '<div class="bank">'
      .       '<div class="gtco">GTCO<div>&nbsp;</div></div>'
      .       '<div class="bk">The Just Folks Limited<b>0117579643</b></div>'
      .     '</div>'
      .     '<div class="sig">'
      .       '<div class="nm">Anuoluwapo O. Ogunbanjo</div>'
      .       '<div class="role">Finance Manager</div>'
      .     '</div>'
      .   '</div>'
      . '</div>' // .body

      // Black bottom bar
      . '<div class="bar-bottom">academy@mamcentre.org &nbsp;|&nbsp; academy.mamcentre.org &nbsp;|&nbsp; +234 704 444 6517</div>'
      . '</div>' // .sheet
      . '</body></html>';
}

/**
 * Booking-fee paid confirmation — sent once the $5 clears, with the direct
 * link to the Google appointment embed and a friendly reminder of what the
 * fee covers (and what it deliberately does not).
 */
function mam_email_booking_confirmed(array $app, array $customer): string {
    $name = htmlspecialchars(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')) ?: 'there', ENT_QUOTES);
    $ref  = htmlspecialchars($app['reference'], ENT_QUOTES);
    $bookUrl = htmlspecialchars(rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/book?ref=' . rawurlencode($app['reference']), ENT_QUOTES);
    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#fff'>Your seat is reserved, $name.</h2>
      <p style='color:rgba(255,255,255,0.86);font-size:15px;margin:0'>We received your \$5 confirmation for application <strong style='color:#FBBF24'>$ref</strong>. Pick a time on the booking page to lock in your meeting with our Admin Officer and the MAM Auditer Judges.</p>
      " . mam_email_button($bookUrl, 'Pick my time') . "
      <p style='color:rgba(255,255,255,0.78);font-size:14px;margin:0'>Your \$5 covers:</p>
      <ul style='color:rgba(255,255,255,0.78);font-size:14px;line-height:1.85;padding-left:18px;margin:8px 0 0'>
        <li>Registration &amp; admission processing</li>
        <li>Your MAM Academy student ID card</li>
        <li>Your student file (kept on record at the Centre)</li>
        <li>A 30-minute MAM Auditer assessment with our judges</li>
      </ul>
      <p style='color:rgba(255,255,255,0.6);font-size:13px;margin:16px 0 0'>Note: tuition is separate and only charged after we have placed you. The \$5 is final &mdash; payment terms apply per our published policy.</p>
      " . mam_email_promo_block(['pathways']);
    return mam_email_layout([
        'header' => 'MAM ACADEMY',
        'preheader' => 'Your $5 cleared — pick a time with our Admin Officer & MAM Auditer Judges.',
        'reason' => 'You received this because you confirmed your MAM Academy application with the $5 booking fee.',
        'unsubscribe' => true,
        'body' => $inner,
    ]);
}

/**
 * Single source of truth for the class-policy clauses (mirrors the signed
 * PDFs). Shared by the policy email and the printable attachment so they
 * can never diverge.
 */
function mam_policy_points(bool $isOnsite): array {
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
    return $isOnsite ? $onsite : $online;
}

/**
 * Standalone, print-optimised HTML of the policy — attached to the policy
 * email so the applicant keeps a clean, signed, printable copy. Light
 * (ink-friendly) theme, self-contained, opens in any browser then
 * Print / "Save as PDF". No server-side PDF dependency required.
 */
function mam_policy_print_html(string $mode, string $signedName, string $reference): string {
    $isOnsite = ($mode === 'onsite');
    $title  = $isOnsite ? 'MAM Academy — Policy for Onsite Classes' : 'MAM Academy — Policy for Online / Home Classes';
    $points = mam_policy_points($isOnsite);
    $sig  = htmlspecialchars($signedName ?: '—', ENT_QUOTES);
    $ref  = htmlspecialchars($reference, ENT_QUOTES);
    $date = date('F j, Y');
    $rows = '';
    foreach ($points as $p) {
        $rows .= '<li><strong>' . htmlspecialchars($p[0]) . '</strong><span>' . htmlspecialchars($p[1]) . '</span></li>';
    }
    return "<!doctype html><html lang='en'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>
<title>" . htmlspecialchars($title) . "</title>
<style>
  @page{margin:18mm}
  *{box-sizing:border-box}
  body{font-family:Georgia,'Times New Roman',serif;color:#1a1a1a;background:#fff;margin:0 auto;padding:32px;line-height:1.6;max-width:780px}
  .head{display:flex;align-items:flex-start;justify-content:space-between;border-bottom:2px solid #C8901A;padding-bottom:14px;flex-wrap:wrap;gap:10px}
  .brand{font-family:Arial,Helvetica,sans-serif;font-weight:800;letter-spacing:.12em;color:#9a6a12;font-size:18px}
  .meta{font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#666;text-align:right;line-height:1.5}
  h1{font-size:20px;margin:18px 0 2px}
  .slogan{font-style:italic;color:#9a6a12;margin:0 0 16px}
  p.intro{font-size:14px;color:#333}
  ol{padding:0;list-style:none;counter-reset:c}
  li{counter-increment:c;position:relative;padding:10px 0 10px 36px;border-bottom:1px solid #eee;font-family:Arial,Helvetica,sans-serif;font-size:13px}
  li::before{content:counter(c);position:absolute;left:0;top:10px;width:25px;height:25px;border-radius:6px;background:#F6E5C0;color:#9a6a12;font-weight:800;display:flex;align-items:center;justify-content:center;font-size:12px}
  li strong{display:block;color:#111;font-size:13.5px;margin-bottom:1px}
  li span{color:#444}
  .sign{margin-top:26px;padding:16px 18px;border:1px dashed #C8901A;border-radius:8px;font-family:Arial,Helvetica,sans-serif}
  .sign .lbl{font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888}
  .sign .name{font-size:18px;font-family:Georgia,serif;margin-top:3px;color:#111}
  .foot{margin-top:22px;font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#666;border-top:1px solid #eee;padding-top:12px}
  .print-btn{margin:16px 0;font-family:Arial,Helvetica,sans-serif}
  .print-btn button{padding:10px 18px;background:#C8901A;color:#1a120b;border:0;border-radius:6px;font-weight:700;cursor:pointer}
  @media print{.print-btn{display:none}body{padding:0}}
</style></head>
<body>
  <div class='head'><div class='brand'>MAM ACADEMY</div>
    <div class='meta'>+234&nbsp;704&nbsp;444&nbsp;6517<br>2 Oremeji Street, Egbeda, Lagos<br>connect@mamcentre.org</div></div>
  <h1>" . htmlspecialchars($title) . "</h1>
  <p class='slogan'>&ldquo;Crafting the Timeless in Time.&rdquo;</p>
  <p class='intro'>Welcome to MAM Academy. The following policies ensure a seamless and rewarding learning experience. Please keep this signed copy for your records.</p>
  <div class='print-btn'><button onclick='window.print()'>Print / Save as PDF</button></div>
  <ol>" . $rows . "</ol>
  <p style='font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#333'>By enrolling you join us in crafting a timeless legacy of growth, excellence and achievement.</p>
  <div class='sign'><div class='lbl'>Agreed &amp; electronically signed</div>
    <div class='name'>" . $sig . "</div>
    <div style='font-size:12px;color:#666;margin-top:2px'>" . $date . " &middot; Application " . $ref . "</div></div>
  <div class='foot'>ACADEMIC DIRECTOR &middot; MAM Centre, 2 Oremeji Street, Egbeda, Lagos &middot; connect@mamcentre.org &middot; +234 704 444 6517</div>
</body></html>";
}

/**
 * Full class-policy email (online/home vs onsite), stamped with the
 * applicant's typed signature and the date they agreed.
 */
function mam_email_policy(string $mode, string $signedName, string $reference): string {
    $isOnsite = ($mode === 'onsite');
    $title = $isOnsite ? 'MAM Academy Policy — Onsite Classes' : 'MAM Academy Policy — Online / Home Classes';

    $points = mam_policy_points($isOnsite);

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

    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 6px;font-size:20px;color:#fff'>" . htmlspecialchars($title) . "</h2>
      <p style='color:rgba(255,255,255,0.7);font-size:13px;margin:0 0 18px'>Welcome to MAM Academy — \"Crafting the Timeless in Time.\" Please keep this policy for your records.</p>
      <table role='presentation' cellpadding='0' cellspacing='0' style='width:100%'>{$rows}</table>
      <div style='margin-top:22px;padding:16px 18px;background:#251A10;border:1px dashed #FBBF24;border-radius:10px'>
        <div style='color:rgba(255,255,255,0.7);font-size:12px;text-transform:uppercase;letter-spacing:.08em'>Agreed &amp; electronically signed</div>
        <div style='color:#fff;font-size:16px;margin-top:4px'><strong>{$sig}</strong></div>
        <div style='color:rgba(255,255,255,0.7);font-size:13px;margin-top:2px'>{$date} · Application {$ref}</div>
      </div>
      <p style='margin:24px 0 0;color:rgba(255,255,255,0.6);font-size:13px'>ACADEMIC DIRECTOR · MAM Centre</p>";
    return mam_email_layout([
        'header' => 'MAM ACADEMY',
        'preheader' => 'Your MAM Academy class policy agreement — please keep for your records.',
        'reason' => 'You received this because you agreed to the class policy when applying to MAM Academy.',
        'body' => $inner,
    ]);
}
