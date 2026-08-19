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
 * Email-delivery readiness — a single, secret-free source of truth for the
 * diag endpoint and the admin warning banner. The #1 reason mail "isn't
 * delivered" is that MAIL_* is unset, so the app silently falls back to PHP
 * mail() (a black hole on most hosts). This makes that visible and actionable.
 */
function mam_mail_readiness(): array {
    $host  = trim((string)mam_env('MAIL_HOST', ''));
    $user  = trim((string)mam_env('MAIL_USERNAME', ''));
    $pass  = (string)mam_env('MAIL_PASSWORD', '');
    $force = in_array(strtolower((string)mam_env('MAIL_FORCE_SMTP', '0')), ['1', 'true', 'yes', 'on'], true);

    $issues = [];
    if ($host === '') {
        $issues[] = 'MAIL_HOST is empty in api/.env — the app is falling back to PHP mail(), which most hosts silently drop. Set MAIL_HOST (e.g. mail.mamcentre.org).';
    }
    if ($host !== '' && $user !== '' && $pass === '') {
        $issues[] = 'MAIL_USERNAME is set but MAIL_PASSWORD is empty — SMTP authentication will fail. Set MAIL_PASSWORD.';
    }
    $ready = $host !== '' && !($user !== '' && $pass === '');

    return [
        'ready'        => $ready,
        'transport'    => $host !== '' ? (mam_phpmailer_available() ? 'phpmailer' : 'smtp') : 'mail()',
        'host'         => $host !== '' ? $host : null,
        'port'         => (int)mam_env('MAIL_PORT', '587'),
        'encryption'   => strtolower((string)mam_env('MAIL_ENCRYPTION', 'tls')),
        'username_set' => $user !== '',
        'password_set' => $pass !== '',
        'from'         => (string)mam_env('MAIL_FROM_EMAIL', 'no-reply@mamcentre.org'),
        'notify'       => (string)mam_env('NOTIFY_EMAIL', ''),
        'force_smtp'   => $force,
        'phpmailer'    => mam_phpmailer_available(),
        'issues'       => $issues,
    ];
}

/**
 * Persist one outbound-email attempt to the mail_log outbox. This is the piece
 * that turns "the email silently vanished" into "an operator can see it failed,
 * why, and re-send it." Never throws — mail bookkeeping must never break a
 * request — and disables itself for the rest of the request if the table is
 * absent (pre-migration) so it can't spam the same error on every send.
 *
 * On SUCCESS the body + opts are dropped (the message already went out; there
 * is nothing to retry and no reason to hoard rendered PII). On FAILURE they are
 * retained verbatim so mam_mail_retry_failed() can re-send the exact message.
 * Returns the row id (0 on any no-op/failure).
 */
function mam_mail_log_record(string $to, string $subject, string $context, string $body, bool $html, array $opts, array $result): int {
    static $disabled = false;
    if ($disabled || !function_exists('mam_db')) return 0;
    try {
        $ok = !empty($result['ok']);
        return mam_db_insert('mail_log', [
            'to_email'  => mb_substr($to, 0, 320),
            'subject'   => mb_substr($subject, 0, 500),
            'context'   => mb_substr($context, 0, 120),
            'is_html'   => $html ? 1 : 0,
            'status'    => $ok ? 'sent' : 'failed',
            'transport' => $result['transport'] ?? null,
            'error'     => $ok ? null : mb_substr((string)($result['error'] ?? ''), 0, 1000),
            'attempts'  => (int)($result['attempts'] ?? 1),
            'body'      => $ok ? null : $body,
            'opts'      => $ok ? null : json_encode($opts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'sent_at'   => $ok ? date('c') : null,
        ]);
    } catch (\Throwable $e) {
        // Table missing or DB unavailable — stop trying for this request.
        $disabled = true;
        return 0;
    }
}

/**
 * Re-send failed outbox rows. Drives the admin "Retry failed" button and the
 * api/tools/mail-retry.php cron, so once an operator fixes the SMTP config the
 * backlog of failed transactional mail (application confirmations, password
 * resets, …) actually goes out instead of being lost. Each attempt updates the
 * row in place. Never throws; returns a {tried,sent,failed,skipped} summary.
 *
 *   $limit       max rows processed in one pass (cron-friendly batching)
 *   $maxAttempts give up on a row once it has failed this many times (poison-
 *                message guard — a permanently-bad address won't retry forever)
 *   $onlyId      retry a single row (admin "retry this one"); 0 = all eligible
 */
function mam_mail_retry_failed(int $limit = 25, int $maxAttempts = 6, int $onlyId = 0): array {
    $summary = ['tried' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    if (!function_exists('mam_db')) return $summary;
    try {
        if ($onlyId > 0) {
            $rows = mam_db_all('SELECT * FROM mail_log WHERE id = ? AND status = ?', [$onlyId, 'failed']);
        } else {
            $rows = mam_db_all(
                'SELECT * FROM mail_log WHERE status = ? AND attempts < ? AND body IS NOT NULL ORDER BY id ASC LIMIT ' . max(1, $limit),
                ['failed', $maxAttempts]
            );
        }
    } catch (\Throwable $e) { return $summary; }

    foreach ($rows as $r) {
        $summary['tried']++;
        $body = (string)($r['body'] ?? '');
        if ($body === '') { $summary['skipped']++; continue; }
        $opts = [];
        if (!empty($r['opts'])) { $d = json_decode((string)$r['opts'], true); if (is_array($d)) $opts = $d; }
        $res = mam_send_mail_verbose((string)$r['to_email'], (string)$r['subject'], $body, (int)($r['is_html'] ?? 1) === 1, $opts);
        $ok  = !empty($res['ok']);
        try {
            mam_db_update('mail_log', [
                'status'     => $ok ? 'sent' : 'failed',
                'transport'  => $res['transport'] ?? null,
                'error'      => $ok ? null : mb_substr((string)($res['error'] ?? ''), 0, 1000),
                'attempts'   => (int)($r['attempts'] ?? 0) + (int)($res['attempts'] ?? 1),
                'body'       => $ok ? null : $body,
                'opts'       => $ok ? null : ($r['opts'] ?? null),
                'sent_at'    => $ok ? date('c') : null,
                'updated_at' => date('c'),
            ], ['id' => (int)$r['id']]);
        } catch (\Throwable $e) { /* a single bad row must not abort the batch */ }
        if ($ok) $summary['sent']++; else $summary['failed']++;
    }
    return $summary;
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
    // SMTP encryption auto-correct. Port 465 = implicit SSL (SMTPS),
    // port 587 = STARTTLS. Misconfiguring this (e.g. port 465 + 'tls')
    // makes PHPMailer hang 20s waiting for STARTTLS that never comes —
    // a single application submit fires 3 emails so a misconfigured
    // host can take 60s per request. Auto-correct based on port so a
    // mismatched .env still works.
    $port = (int)mam_env('MAIL_PORT', '587');
    if ($port === 465) $enc = 'ssl';
    elseif ($port === 587 && $enc === 'ssl') $enc = 'tls';

    $mailClass = 'PHPMailer\\PHPMailer\\PHPMailer';
    $mail = new $mailClass(true);
    try {
        $mail->isSMTP();
        $mail->Host        = $host;
        $mail->Port        = $port;
        $mail->Timeout     = 8;
        $mail->CharSet     = 'UTF-8';
        $mail->Hostname    = mam_resolve_ehlo_host();
        if ($user !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = $pass;
        }
        if ($enc === 'ssl')      $mail->SMTPSecure = 'ssl';
        elseif ($enc === 'tls')  $mail->SMTPSecure = 'tls';
        else {
            // Operator explicitly chose no TLS — also disable PHPMailer's
            // opportunistic STARTTLS upgrade, which otherwise breaks plain
            // port-25/2525 relays whose certs would fail verification.
            $mail->SMTPSecure  = '';
            $mail->SMTPAutoTLS = false;
        }
        // Shared-host reality: the relay can't complete a *verified* TLS
        // handshake (cert is for the server hostname and/or signed by a CA not
        // in PHP's bundle), so verified TLS fails and NOTHING sends. STARTTLS
        // still encrypts; we just can't verify the peer. Default to
        // encrypted-but-unverified (what actually delivers on cPanel/Exim);
        // MAIL_STRICT_TLS=1 opts a capable host back into full verification.
        $mail->SMTPOptions = ['ssl' =>
            in_array(strtolower((string)mam_env('MAIL_STRICT_TLS', '0')), ['1', 'true', 'yes', 'on'], true)
                ? ['verify_peer' => true,  'verify_peer_name' => true]
                : ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        $mail->setFrom($from, $fromName);
        // Authenticated relays usually require the envelope sender to match the
        // login — mirror the built-in client's behaviour. But ONLY when the
        // username is a strictly valid address: many shared-host SMTP logins
        // (user@host, cPanel system users) fail PHPMailer's validator, and an
        // invalid Sender makes preSend() THROW and the whole email silently
        // dies. Skipping it just lets the envelope fall back to From.
        if ($user !== '' && strpos($user, '@') !== false && $mailClass::validateAddress($user)) {
            $mail->Sender = $user;
        }
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
  try {
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
            $r = mam_send_via_phpmailer($to, $subject, $body, $html, $from, $fromName, $replyTo, $extraHeaders, $attachments);
            if ($r['ok']) return $r;
            // PHPMailer failed (strict validation, TLS negotiation quirk, …).
            // The built-in client is more forgiving of shared-host setups, so
            // fall through and retry with it — a failed library must never be
            // the reason an email dies when a working transport exists.
            mam_log('warn', 'phpmailer_falling_back_to_builtin', ['error' => $r['error'] ?? null, 'to' => $to]);
        }
        // Retry-once-on-transient: 4xx replies are usually rate-limit or
        // greylisting and clear on a second attempt. 5xx is permanent —
        // don't waste time. The retry uses a fresh connection.
        // Transport strategies, tried in order: the configured one first, then
        // the ALTERNATE TLS transport — because a host that can't complete a
        // STARTTLS handshake on 587 will often accept implicit SSL on 465 (and
        // vice versa). This auto-recovers the most common shared-host mail
        // failure ("tls_handshake: enable_crypto returned false") with no config
        // change. Each strategy still gets the 4xx transient retry.
        $cfgPort = (int)mam_env('MAIL_PORT', '587');
        $cfgEnc  = strtolower((string)mam_env('MAIL_ENCRYPTION', 'tls'));
        if ($cfgPort === 465) $cfgEnc = 'ssl';
        elseif ($cfgPort === 587 && $cfgEnc === 'ssl') $cfgEnc = 'tls';
        $strategies = [[$cfgPort, $cfgEnc]];
        $alt = ($cfgEnc === 'ssl') ? [587, 'tls'] : [465, 'ssl'];
        if ($alt !== [$cfgPort, $cfgEnc]) $strategies[] = $alt;

        $transcript = []; $ok = false; $failedStep = null; $usedPort = $cfgPort; $usedEnc = $cfgEnc; $attempts = 0;
        foreach ($strategies as $si => $strat) {
            [$sPort, $sEnc] = $strat;
            $attempts = 0; $maxAttempts = 2;
            while ($attempts < $maxAttempts) {
                $attempts++;
                $transcript = [];
                $ok = mam_smtp_send($host, $sPort,
                    mam_env('MAIL_USERNAME', ''), mam_env('MAIL_PASSWORD', ''), $sEnc,
                    $from, $fromName, $replyTo, $to, $subject, $body, $html, $extraHeaders, $attachments, $transcript);
                $failedStep = null;
                foreach ($transcript as $s) { if (!($s['ok'] ?? true)) { $failedStep = $s; break; } }
                if ($ok) { $usedPort = $sPort; $usedEnc = $sEnc; break; }
                // 4xx = transient (greylist/rate-limit) → retry once. Else stop this strategy.
                $code = $failedStep ? substr(trim((string)($failedStep['reply'] ?? '')), 0, 1) : '';
                if ($code !== '4' || $attempts >= $maxAttempts) break;
                usleep(500000);
                mam_log('info', 'smtp_retry', ['step' => $failedStep['step'] ?? '', 'attempt' => $attempts]);
            }
            if ($ok) break;
            // Only fall back to the alternate transport for connect/TLS-level
            // failures — switching STARTTLS↔SSL can't fix auth/recipient/5xx errors.
            $fstep = $failedStep['step'] ?? '';
            if (!in_array($fstep, ['connect', 'banner', 'starttls', 'tls_handshake'], true)) break;
            if ($si + 1 < count($strategies)) {
                mam_log('warn', 'smtp_transport_fallback', ['tried' => "$sPort/$sEnc", 'reason' => $fstep, 'next' => $strategies[$si + 1][0] . '/' . $strategies[$si + 1][1]]);
            }
        }
        return [
            'ok'         => $ok,
            'transport'  => 'smtp',
            'host'       => $host,
            'port'       => $usedPort,
            'encryption' => $usedEnc,
            'attempts'   => $attempts,
            'error'      => $ok ? null : ($failedStep['step'] ?? 'smtp_failed') . ': ' . trim((string)($failedStep['reply'] ?? '')),
            'steps'      => $transcript,
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
  } catch (\Throwable $e) {
    // Mail must NEVER break a request. Any unexpected error (transport bug,
    // bad attachment, malformed header, library quirk) is swallowed + logged;
    // the caller just sees a failed delivery, never an exception.
    @error_log('[mam mail] exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (function_exists('mam_log')) mam_log('error', 'mail_exception', ['err' => $e->getMessage(), 'to' => $to, 'subject' => $subject]);
    return ['ok' => false, 'transport' => 'exception', 'host' => null, 'error' => 'exception: ' . $e->getMessage(), 'steps' => []];
  }
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
    // TLS verification posture. Shared mail hosts (cPanel/Exim, e.g. qservers)
    // routinely CANNOT complete a *verified* STARTTLS handshake: the relay cert
    // is issued for the server hostname (27.qservers.net) and/or signed by a CA
    // that isn't in PHP's bundle — so stream_socket_enable_crypto() returns
    // false even with name-checking off. STARTTLS still ENCRYPTS the channel;
    // we just can't verify the peer. So we DEFAULT to encrypted-but-unverified
    // (the universal shared-host setting that actually delivers mail), and let
    // a capable host opt into full verification with MAIL_STRICT_TLS=1.
    $strictTls = in_array(strtolower((string)mam_env('MAIL_STRICT_TLS', '0')), ['1', 'true', 'yes', 'on'], true);
    $ctx = stream_context_create(['ssl' => $strictTls
        ? ['verify_peer' => true,  'verify_peer_name' => true,  'SNI_enabled' => true, 'peer_name' => $host]
        : ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
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
    // Prices are whole-dollar (no decimals); "from" prices reflect the
    // level-based model (lowest level), the crash flat fee, and the intensive.
    $fp = mam_programme_from_prices();
    $base = 'https://academy.mamcentre.org/';
    $items = [
        'powerplay' => ['⚡ Power Play with Van. BOJA', 'Free monthly online session for musicians & culture-shapers.', 'Free', 'power-play'],
        'crash'     => ['Crash Programme', 'A focused 6-week accelerated intensive — quick, real wins.', '$' . (int)round($fp['crash']), 'crash'],
        'pathways'  => ['Certified &amp; Grand Musician', 'Train across all four levels, online or onsite.', 'from $' . (int)round($fp['certified-musician']), 'pathways'],
        'metamusic' => ['The MetaMusic Order', '12-week post-graduate cultural-architecture track.', '$' . (int)round($fp['meta-order']), 'metamusic-order'],
    ];
    $rows = '';
    foreach ($items as $key => $it) {
        if (in_array($key, $exclude, true)) continue;
        [$name, $desc, $price, $slug] = $it;
        $rows .= "<tr>
          <td style='padding:12px 0;border-bottom:1px solid #ECDFC4;vertical-align:top'>
            <a href='" . $base . $slug . "' style='color:#FBBF24;font-weight:700;font-size:15px;text-decoration:none'>" . htmlspecialchars($name, ENT_QUOTES) . " &rarr;</a>
            <div style='color:#7A5C3D;font-size:13px;margin-top:3px;line-height:1.5'>" . htmlspecialchars($desc, ENT_QUOTES) . "</div>
          </td>
          <td style='padding:12px 0 12px 14px;border-bottom:1px solid #ECDFC4;text-align:right;vertical-align:top;white-space:nowrap'>
            <span style='color:#fff;font-size:14px;font-weight:700'>" . htmlspecialchars($price, ENT_QUOTES) . "</span>
          </td></tr>";
    }
    if ($rows === '') return '';
    return "<div style='margin-top:28px;padding:18px 18px 6px;background:#160F09;border:1px solid #ECDFC4;border-radius:12px'>
      <div style='font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:#A89682;font-weight:800;margin-bottom:2px'>More ways to grow with MAM</div>
      <table cellpadding='0' cellspacing='0' style='width:100%'>" . $rows . "</table>
    </div>";
}

/**
 * Scholarship details — single source for the email side (mirrors the frontend
 * MAM_CONFIG.scholarship). Admin-overridable via the scholarship_enabled /
 * scholarship_url settings (or the SCHOLARSHIP_URL env); copy lives here so a
 * change is one edit. Returns enabled flag + url + copy.
 */
function mam_scholarship(): array {
    return [
        'enabled' => (int)mam_setting_num('scholarship_enabled', 1) === 1,
        'url'     => (string)mam_setting('scholarship_url', mam_env('SCHOLARSHIP_URL', 'https://mamcentre.org/scholarships/ngv')),
        'name'    => 'NGV Scholarship',
        'cta'     => 'Apply for a Scholarship',
        'blurb'   => 'Can’t afford the fees? The NGV Scholarship helps talented students who genuinely cannot afford tuition study at MAM Academy. If money is the only thing standing in your way, apply — we want to hear from you.',
    ];
}

/**
 * Reusable scholarship promo block for emails. Empty string when disabled, so
 * callers can concatenate it unconditionally.
 */
function mam_email_scholarship_block(): string {
    $s = mam_scholarship();
    if (empty($s['enabled']) || $s['url'] === '') return '';
    $url = htmlspecialchars($s['url'], ENT_QUOTES);
    return "<div style='margin-top:24px;padding:20px;background:#FBF6EA;border:1px solid #E7C77A;border-radius:12px'>
      <div style='font-size:12px;letter-spacing:0.1em;text-transform:uppercase;color:#A8730F;font-weight:800;margin-bottom:6px'>Cost should never be the barrier</div>
      <div style='font-size:16px;font-weight:800;color:#1A120B;margin-bottom:6px'>Can&rsquo;t afford the fees? Apply for a scholarship.</div>
      <div style='font-size:13.5px;color:#5C4326;line-height:1.6;margin-bottom:10px'>" . htmlspecialchars($s['blurb'], ENT_QUOTES) . "</div>
      <a href='" . $url . "' style='display:inline-block;color:#A8730F;font-weight:800;text-decoration:none;font-size:14px'>" . htmlspecialchars($s['cta'], ENT_QUOTES) . " &rarr;</a>
    </div>";
}

/**
 * Bulletproof CTA button. Renders VML for Outlook (which ignores padding and
 * border-radius on <a>) and a normal padded anchor everywhere else, so the
 * button looks right across Gmail, Apple Mail, Outlook desktop and mobile.
 */
function mam_email_button(string $url, string $label, string $accent = ''): string {
    $u = htmlspecialchars($url, ENT_QUOTES);
    $l = htmlspecialchars($label, ENT_QUOTES);
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="left" style="margin:24px 0"><tr>'
      . '<td align="left" bgcolor="#C17B2A" style="border-radius:8px">'
      . '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $u . '" style="height:46px;v-text-anchor:middle;width:260px;" arcsize="9%" strokecolor="#C17B2A" fillcolor="#C17B2A"><w:anchorlock/><center style="color:#141413;font-family:Arial,sans-serif;font-size:14px;font-weight:600">' . $l . ' &rarr;</center></v:roundrect><![endif]-->'
      . '<!--[if !mso]><!--><a href="' . $u . '" style="display:inline-block;padding:13px 24px;background:#C17B2A;color:#141413;border-radius:8px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;font-weight:600;text-decoration:none;font-size:14px">' . $l . ' &rarr;</a><!--<![endif]-->'
      . '</td></tr></table>';
}

/**
 * The MAM brand badge as a base64 PNG data URI, generated once with GD so it
 * renders in EVERY email client (Gmail/Outlook don't render SVG, and the SVG
 * logo is 150KB). A dark gold-ringed disc with a gold "equalizer" motif —
 * small (~a few KB), retina-friendly at 64px. Cached per request; returns ''
 * if GD is unavailable, in which case the header falls back to the wordmark.
 */
function mam_email_logo_png_b64(): string {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = '';
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) return $cache;
    try {
        $sz = 220;
        $im = imagecreatetruecolor($sz, $sz);
        imagealphablending($im, true);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); // transparent ground
        $dark = imagecolorallocate($im, 0x1A, 0x12, 0x0B);
        $gold = imagecolorallocate($im, 0xFB, 0xBF, 0x24);
        $c = intdiv($sz, 2);
        imagefilledellipse($im, $c, $c, $sz - 6, $sz - 6, $gold);   // gold ring …
        imagefilledellipse($im, $c, $c, $sz - 22, $sz - 22, $dark); // … over a dark disc
        // Gold "equalizer" bars (a music/audio motif), centred, varied heights.
        $heights = [52, 96, 70, 116, 60];
        $bw = 16; $gap = 12;
        $totalW = count($heights) * $bw + (count($heights) - 1) * $gap;
        $x = $c - intdiv($totalW, 2);
        $baseY = $c + 58;
        foreach ($heights as $h) {
            imagefilledrectangle($im, $x, $baseY - $h, $x + $bw, $baseY, $gold);
            imagefilledellipse($im, $x + intdiv($bw, 2), $baseY - $h, $bw, $bw, $gold); // rounded top
            $x += $bw + $gap;
        }
        ob_start();
        imagepng($im, null, 9);
        $png = ob_get_clean();
        imagedestroy($im);
        if (is_string($png) && $png !== '') $cache = 'data:image/png;base64,' . base64_encode($png);
    } catch (\Throwable $e) { $cache = ''; }
    return $cache;
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
    // Light, editorial layout. Single warm-paper ground, single dark accent
    // capsule on the wordmark, restrained typography. Designed to read
    // identical in Outlook 2007+, Gmail iOS dark mode (forced light via the
    // color-scheme meta), Apple Mail, and Yahoo. No gradients, no rgba —
    // only solid hexes so dark-mode inverters don't shift things weirdly.
    $inner     = $o['body'] ?? '';
    $preheader = htmlspecialchars($o['preheader'] ?? '', ENT_QUOTES);
    $reason    = htmlspecialchars($o['reason'] ?? 'You received this email from MAM Academy.', ENT_QUOTES);
    $marketing = !empty($o['unsubscribe']);
    $year      = date('Y');
    $appUrl    = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');

    // Logo: admin-configurable hosted image URL. Falls back to text wordmark when blank.
    $logoUrl = mam_setting('email_logo_url', '');
    $safeLogoUrl = '';
    if ($logoUrl !== '') {
        $s = strtolower(substr($logoUrl, 0, 8));
        if ($s === 'https://' || substr($s, 0, 7) === 'http://') {
            $safeLogoUrl = htmlspecialchars($logoUrl, ENT_QUOTES);
        }
    }

    // Promotional banner: inserted after the header rule when an active banner exists.
    $bannerRow = mam_email_active_banner();

    $unsubLine = $marketing
        ? '<p style="margin:8px 0 0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:12px;color:#8a8578;line-height:1.6">' . $reason
          . ' <a href="{{UNSUB_URL}}" style="color:#8a8578">Unsubscribe here</a> &mdash; we honour every request promptly.</p>'
        : '<p style="margin:8px 0 0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:12px;color:#8a8578;line-height:1.6">' . $reason . '</p>';

    return '<!doctype html><html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">'
      . '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
      . '<meta http-equiv="X-UA-Compatible" content="IE=edge"><meta name="x-apple-disable-message-reformatting">'
      . '<meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light only">'
      . '<title>MAM Academy</title>'
      . '<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->'
      . '<style>'
      . 'body{margin:0;padding:0;width:100%!important;background:#f0eee6;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}'
      . 'table{border-collapse:collapse}td{mso-line-height-rule:exactly}'
      . 'img{border:0;line-height:100%;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;max-width:100%;height:auto}'
      . 'a{color:#C17B2A;text-decoration:none}'
      . '@media(prefers-color-scheme:dark){body{background:#f0eee6!important}}'
      . '@media only screen and (max-width:600px){'
      . '.container{width:100%!important;border-radius:0!important;margin:0!important}'
      . '.px{padding-left:24px!important;padding-right:24px!important}'
      . '.h1{font-size:24px!important;line-height:1.25!important}'
      . '.h2{font-size:18px!important}'
      . '.stack{display:block!important;width:100%!important;padding:0!important;border-left:0!important;border-top:1px solid #e8e6dd!important;padding-top:14px!important;margin-top:14px!important}'
      . '}'
      . '</style></head>'
      . '<body style="margin:0;padding:0;background:#f0eee6">'
      . '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f0eee6">' . $preheader . '&#8203;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;&#847;</div>'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0eee6;padding:40px 0"><tr><td align="center">'
      . '<!--[if mso]><table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" width="560"><tr><td><![endif]-->'
      . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" class="container" style="width:560px;max-width:90%;background:#faf9f5;border-radius:12px">'

      // Header: image logo when configured, text wordmark as fallback
      . ($safeLogoUrl !== ''
          ? '<tr><td style="padding:32px 44px 0 44px;line-height:0">'
            . '<img src="' . $safeLogoUrl . '" alt="MAM Academy" '
            .   'style="max-height:48px;height:auto;max-width:200px;width:auto;display:block;border:0">'
            . '</td></tr>'
          : '<tr><td style="padding:36px 44px 0 44px">'
            . '<p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:13px;font-weight:600;letter-spacing:0.3px;color:#C17B2A">MAM ACADEMY</p>'
            . '</td></tr>')

      // Rule
      . '<tr><td style="padding:18px 44px 0 44px"><hr style="border:none;border-top:1px solid #e8e6dd;margin:0"></td></tr>'

      // Promotional banner — only rendered when an active banner is scheduled today
      . $bannerRow

      // Body — Georgia for an editorial, personal feel; individual templates can override inline
      . '<tr><td class="px" style="padding:28px 44px 36px 44px;font-family:Georgia,\'Iowan Old Style\',\'Times New Roman\',serif;font-size:16px;line-height:1.7;color:#3d3929">'
      .   $inner
      . '</td></tr>'

      // Footer rule
      . '<tr><td style="padding:0 44px"><hr style="border:none;border-top:1px solid #e8e6dd;margin:0"></td></tr>'

      // Footer
      . '<tr><td class="px" style="padding:18px 44px 36px 44px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif">'
      .   '<p style="margin:0 0 8px 0;font-size:12px;color:#8a8578;line-height:1.6"><strong style="color:#6a655d">MAM Academy</strong> &mdash; a programme of MAM Centre.<br>'
      .   '2 Oremeji Street, Egbeda, Lagos, Nigeria &middot; <a href="mailto:academy@mamcentre.org" style="color:#8a8578">academy@mamcentre.org</a> &middot; +234 704 444 6517</p>'
      .   '<p style="margin:0 0 0;font-size:12px;color:#8a8578;line-height:1.6">'
      .   '<a href="' . htmlspecialchars($appUrl, ENT_QUOTES) . '/privacy" style="color:#8a8578">Privacy</a> &middot; '
      .   '<a href="' . htmlspecialchars($appUrl, ENT_QUOTES) . '" style="color:#8a8578">academy.mamcentre.org</a></p>'
      .   $unsubLine
      .   '<p style="margin:10px 0 0;font-size:11px;color:#a09888;letter-spacing:0.04em">&copy; ' . $year . ' MAM Centre &middot; Crafting the Timeless in Time</p>'
      . '</td></tr>'

      . '</table>'
      . '<!--[if mso]></td></tr></table><![endif]-->'
      . '</td></tr></table></body></html>';
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
        : "<p style='color:#7A5C3D;font-size:14px;text-align:center;margin:24px 0'>We'll email you the Google Meet link 24 hours before the session.</p>";
    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>You're registered for Power Play.</h2>
      <p style='color:#4A3624;font-size:15px;margin:0'>A free, raw, 90-minute session with <strong>Van. BOJA</strong> for musicians, artists and culture-shapers. Here's the next one:</p>
      <div style='background:#FBF4E8;border:1px dashed #A5B4FC;border-radius:10px;padding:18px;text-align:center;margin:18px 0'>
        <div style='font-size:13px;color:#A5B4FC;text-transform:uppercase;letter-spacing:0.1em;font-weight:800'>Next Session</div>
        <div style='font-size:18px;color:#fff;font-weight:700;margin-top:6px'>$date</div>
        <div style='font-size:15px;color:#FBBF24;margin-top:2px'>$time</div>
      </div>
      $cta
      <p style='color:#7A5C3D;font-size:14px;margin:0'>Power Play runs the <strong>first Wednesday of every month</strong> — add it to your calendar so you never miss it.</p>
      <p style='font-size:13px;color:#9A8264;margin:18px 0 0'>Want the full structured path — certification, instructors, ensemble &amp; stage time? <a href='https://academy.mamcentre.org/apply' style='color:#B8721B'>Apply to MAM Academy</a>.</p>
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

/**
 * Internal team alert (new application, contact message, subscriber, …).
 * Renders a polished, scannable card through the shared layout: an "Internal
 * notification" eyebrow, a title, an optional lede, a clean key/value detail
 * table, an optional free-text block (e.g. a contact message), and an optional
 * CTA into the admin panel. Values are HTML-escaped here, so callers pass plain
 * strings.
 *
 * @param string $title  e.g. "New application"
 * @param array  $rows   ordered ['Label' => 'value', …] (values escaped here)
 * @param array  $opts   lede:string, note:string (free text, nl2br'd),
 *                        cta:['url'=>, 'label'=>], badge:string
 */
function mam_email_admin_notice(string $title, array $rows, array $opts = []): string {
    $t     = htmlspecialchars($title, ENT_QUOTES);
    $badge = htmlspecialchars($opts['badge'] ?? 'Internal notification', ENT_QUOTES);
    $inner = "<p style='margin:0 0 6px;font-size:10.5px;letter-spacing:0.22em;text-transform:uppercase;color:#B8721B;font-weight:700'>$badge</p>"
           . "<h2 class='h2' style='font-family:Georgia,\"Times New Roman\",serif;margin:0 0 14px;font-size:22px;color:#1A120B'>$t</h2>";

    if (!empty($opts['lede'])) {
        $inner .= "<p style='margin:0 0 18px;font-size:15px;color:#4A3624'>" . htmlspecialchars($opts['lede'], ENT_QUOTES) . "</p>";
    }

    // Detail table — label/value rows, hairline dividers, reads in every client.
    if ($rows) {
        $inner .= "<table role='presentation' width='100%' border='0' cellpadding='0' cellspacing='0' style='border:1px solid #ECE0CC;border-radius:12px;overflow:hidden;margin:0 0 20px'>";
        $i = 0;
        foreach ($rows as $label => $value) {
            $bg = ($i % 2 === 0) ? '#FBF7F0' : '#FFFFFF';
            $inner .= "<tr>"
                . "<td style='background:$bg;padding:11px 16px;font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#9A8264;font-weight:700;white-space:nowrap;border-bottom:1px solid #F0E7D6;vertical-align:top;width:38%'>" . htmlspecialchars((string)$label, ENT_QUOTES) . "</td>"
                . "<td style='background:$bg;padding:11px 16px;font-size:14px;color:#1A120B;font-weight:600;border-bottom:1px solid #F0E7D6'>" . htmlspecialchars((string)$value, ENT_QUOTES) . "</td>"
                . "</tr>";
            $i++;
        }
        $inner .= "</table>";
    }

    if (!empty($opts['note'])) {
        $inner .= "<p style='margin:0 0 6px;font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#9A8264;font-weight:700'>Message</p>"
               . "<div style='padding:14px 16px;background:#FBF7F0;border:1px solid #ECE0CC;border-radius:12px;font-size:14px;color:#4A3624;line-height:1.7;margin:0 0 20px'>"
               . nl2br(htmlspecialchars($opts['note'], ENT_QUOTES)) . "</div>";
    }

    if (!empty($opts['cta']['url'])) {
        $inner .= mam_email_button($opts['cta']['url'], $opts['cta']['label'] ?? 'Open in admin');
    }

    return mam_email_layout([
        'header'    => 'MAM ACADEMY',
        'preheader' => $title,
        'reason'    => 'You received this internal notification because your address is set as the MAM Academy NOTIFY_EMAIL recipient.',
        'body'      => $inner,
    ]);
}

/** New portal account created by an admin — sends the temporary credentials. */
function mam_email_account_created(string $name, string $email, string $tempPassword, string $role, string $loginUrl): string {
    $n = htmlspecialchars($name ?: 'there', ENT_QUOTES);
    $e = htmlspecialchars($email, ENT_QUOTES);
    $p = htmlspecialchars($tempPassword, ENT_QUOTES);
    $r = htmlspecialchars(ucfirst($role), ENT_QUOTES);
    $u = htmlspecialchars($loginUrl, ENT_QUOTES);
    $inner = "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>Your MAM Academy $r account is ready, $n</h2>
      <p style='color:#4A3624;font-size:15px'>An administrator created a portal account for you. Sign in with these temporary credentials and you'll be asked to set your own password right away.</p>
      <div style='background:#FBF4E8;border:1px dashed #B8721B;border-radius:10px;padding:14px 18px;font-family:monospace;font-size:14px;color:#FBBF24;margin:16px 0'>
        <div>Email: <strong>$e</strong></div><div>Temporary password: <strong>$p</strong></div></div>
      " . mam_email_button($loginUrl, 'Sign in to your portal') . "
      <p style='font-size:13px;color:#9A8264;margin:0'>If you weren't expecting this, please contact us — don't share these credentials.</p>";
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
    $inner = "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>Your password was $how</h2>
      <p style='color:#4A3624;font-size:15px'>Hi $n — this is a security notice that the password on your MAM Academy account was just $how. $extra</p>
      <p style='font-size:13px;color:#7A5C3D;margin:14px 0 0'><strong>Didn't do this?</strong> Contact us immediately at <a href='mailto:academy@mamcentre.org' style='color:#B8721B'>academy@mamcentre.org</a> so we can secure your account.</p>";
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
    $inner = "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>You're enrolled, $n!</h2>
      <p style='color:#4A3624;font-size:15px'>You've been placed into <strong style='color:#B8721B'>$c</strong>. Here's what we have on the schedule:</p>
      <div style='background:#FBF4E8;border:1px dashed #B8721B;border-radius:10px;padding:14px 18px;color:#FBBF24;margin:16px 0'>$s</div>
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
 * Session reminder — sent by the automated sweep or on demand by instructors/admins.
 *
 * $meetingUrl  Optional redirect URL (e.g. https://academy.mamcentre.org/session/N/join).
 *              Pass the auth-gated academy redirect, not the raw Zoom/Meet link.
 * $role        'student' (default) or 'instructor' — changes the subject copy.
 */
function mam_email_session_reminder(
    string $name,
    string $sessionTitle,
    string $whenLabel,
    string $loginUrl,
    string $meetingUrl = '',
    string $role = 'student'
): string {
    $n = htmlspecialchars($name ?: 'there', ENT_QUOTES);
    $t = htmlspecialchars($sessionTitle ?: 'your session', ENT_QUOTES);
    $w = htmlspecialchars($whenLabel, ENT_QUOTES);

    $joinBtn = '';
    if ($meetingUrl !== '') {
        $joinBtn = "<div style='margin:14px 0 4px'>" . mam_email_button($meetingUrl, 'Join the session', '#27AE60') . "</div>";
    }

    if ($role === 'instructor') {
        $portalUrl = str_contains($loginUrl, '?') ? $loginUrl . '&role=instructor' : $loginUrl . '?role=instructor';
        $inner = "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>Coming up: $t</h2>
          <p style='color:#4A3624;font-size:15px'>Hi $n — a reminder that you have a session scheduled:</p>
          <div style='background:#FBF4E8;border:1px dashed #B8721B;border-radius:10px;padding:14px 18px;text-align:center;color:#B8721B;font-weight:700;margin:16px 0'>$w</div>
          <p style='color:#7A5C3D;font-size:14px'>Warm up the room and be ready for your students. See you there!</p>
          $joinBtn" . mam_email_button($loginUrl, 'Open instructor portal');
        $preheader = 'Reminder: you have a session coming up — ' . ($sessionTitle ?: 'your session') . '.';
        $reason = 'You received this because you are the instructor for an upcoming MAM Academy session.';
    } else {
        $inner = "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>See you soon, $n</h2>
          <p style='color:#4A3624;font-size:15px'>A friendly reminder that <strong style='color:#B8721B'>$t</strong> is coming up:</p>
          <div style='background:#FBF4E8;border:1px dashed #B8721B;border-radius:10px;padding:14px 18px;text-align:center;color:#B8721B;font-weight:700;margin:16px 0'>$w</div>
          <p style='color:#7A5C3D;font-size:14px'>Warm up a little beforehand and bring any questions from your practice. See you there!</p>
          $joinBtn" . mam_email_button($loginUrl, 'Open my portal');
        $preheader = 'A reminder about your upcoming MAM Academy session.';
        $reason = 'You received this because you are enrolled in an upcoming MAM Academy session.';
    }

    return mam_email_layout([
        'header'      => 'MAM ACADEMY',
        'preheader'   => $preheader,
        'reason'      => $reason,
        'unsubscribe' => true,
        'body'        => $inner,
    ]);
}

/**
 * Playful birthday note for a KTAMS (Kids @ MAM) student — sent by the
 * 'ktams_birthday' automation sweep. Warm and kid-appropriate, on-brand.
 */
function mam_email_ktams_birthday(string $name): string {
    $n = htmlspecialchars($name ?: 'superstar', ENT_QUOTES);
    $inner = "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:24px;color:#1A120B'>Happy birthday, $n! 🎂</h2>
      <p style='color:#4A3624;font-size:15px'>Everyone at MAM Academy is sending you the biggest, brightest birthday cheer. We hope your day is full of music, cake and fun! 🎶🎉</p>
      <p style='color:#4A3624;font-size:15px'>Here's a little challenge for your special day: play your favourite piece for someone you love — they'll be your best audience yet.</p>
      <p style='color:#7A5C3D;font-size:14px'>With love, your teachers at MAM Academy.</p>";
    return mam_email_layout([
        'header' => 'MAM ACADEMY',
        'preheader' => 'A birthday cheer from your friends at MAM Academy!',
        'reason' => 'You received this birthday note because you are a MAM Academy (Kids @ MAM) student.',
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
    $tot  = number_format((float)($app['total_usd'] ?? 0), 0);
    $resumeUrl = 'https://academy.mamcentre.org/apply?ref=' . rawurlencode((string)($app['reference'] ?? ''));
    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>You're one step away, $name.</h2>
      <p style='color:#4A3624;font-size:15px;margin:0'>We saved your MAM Academy application (<strong style='color:#B8721B'>$ref</strong>) but it looks like checkout didn't finish. Your seat for the next intake isn't confirmed until payment is complete.</p>
      <div style='background:#FBF4E8;border:1px dashed #B8721B;border-radius:10px;padding:14px 18px;text-align:center;margin:18px 0'>
        <span style='color:#7A5C3D;font-size:13px'>Balance to confirm your seat</span><br>
        <strong style='color:#FBBF24;font-size:20px'>USD \${$tot}</strong>
      </div>
      " . mam_email_button($resumeUrl, 'Complete my enrolment') . "
      <p style='color:#7A5C3D;font-size:14px;margin:0'>Intakes fill on a first-paid basis. If you have a question or want to pay by bank transfer, just reply to this email or call <strong>+234 704 444 6517</strong> — a real person will help.</p>
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
    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>Reset your password, $n</h2>
      <p style='color:#4A3624;font-size:15px;margin:0'>Click the button below to set a new password. The link works once and expires in <strong>60 minutes</strong>.</p>
      " . mam_email_button($resetUrl, 'Set new password') . "
      <p style='font-size:13px;color:#7A5C3D;line-height:1.65;margin:0'>If the button doesn't work, copy and paste this link into your browser:<br><span style='word-break:break-all;color:#B8721B'>$u</span></p>
      <p style='font-size:13px;color:#9A8264;margin:24px 0 0'>Didn't request this? You can ignore this email — your password stays the same. The link expires automatically.</p>";
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
  try {
    // Marketing emails embed {{UNSUB_URL}} via the layout. When present, fill
    // the visible link AND attach RFC 8058 one-click List-Unsubscribe headers,
    // keyed to the recipient — so every promotional email is CAN-SPAM / GDPR /
    // NDPA compliant automatically, with no work for the calling route.
    if (strpos($body, '{{UNSUB_URL}}') !== false) {
        $url = mam_unsubscribe_url($to);
        $body = str_replace('{{UNSUB_URL}}', htmlspecialchars($url, ENT_QUOTES), $body);
        $mailto = 'mailto:' . mam_env('NOTIFY_EMAIL', 'academy@mamcentre.org') . '?subject=unsubscribe';
        $opts['headers'] = array_merge($opts['headers'] ?? [], [
            'List-Unsubscribe'      => '<' . $url . '>, <' . $mailto . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
    $result = mam_send_mail_verbose($to, $subject, $body, $html, $opts);
    // Durable outbox row: success is audited; failure is RETAINED with its
    // rendered body so it can be re-sent once the SMTP config is fixed. This is
    // why a failed transactional email is no longer lost. Uses the final body +
    // opts (post-{{UNSUB_URL}}) so a retry reproduces the message faithfully.
    mam_mail_log_record($to, $subject, $context, $body, $html, $opts, $result);
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
  } catch (\Throwable $e) {
    @error_log('[mam mail] mam_send exception: ' . $e->getMessage());
    if (function_exists('mam_log')) mam_log('error', 'mail_exception', ['context' => $context, 'err' => $e->getMessage(), 'to' => $to]);
    return false;
  }
}

/**
 * Welcome email sent to every new newsletter subscriber.
 */
function mam_email_newsletter_welcome(string $source = 'site'): string {
    $site = htmlspecialchars($source, ENT_QUOTES);
    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>You're on the inside.</h2>
      <p style='color:#4A3624;font-size:15px;margin:0'>Thanks for joining the MAM Academy circle. Once a month you'll get:</p>
      <ul style='color:#4A3624;font-size:15px;line-height:1.85;padding-left:18px;margin:14px 0'>
        <li>The date &amp; theme of the next free <strong style='color:#B8721B'>Power Play with Van. BOJA</strong></li>
        <li>Open intake dates &amp; new programme launches</li>
        <li>Student showcases, faculty essays &amp; long-form notes on the MAM Method</li>
      </ul>
      <p style='color:#4A3624;font-size:15px;margin:0'>If you're ready to enrol, you can start your application in five guided steps:</p>
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
 * Magic-link email to resume a saved application on any device (A1).
 */
function mam_email_resume_link(string $url): string {
    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>Pick up where you left off</h2>
      <p style='color:#4A3624;font-size:15px;margin:0'>Your MAM Academy application is saved. Continue on <strong>any device</strong> — this link reopens it with everything you'd already entered:</p>
      " . mam_email_button($url, 'Resume my application') . "
      <p style='font-size:13px;color:#9A8264;margin:18px 0 0'>The link is personal to your application — don't forward it. If you didn't request this, you can safely ignore it; nothing changes until you submit.</p>";
    return mam_email_layout([
        'header' => 'MAM ACADEMY',
        'preheader' => 'Continue your MAM Academy application on any device.',
        'reason' => 'You received this because you asked for a link to resume your application.',
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
    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>Got it, $n.</h2>
      <p style='color:#4A3624;font-size:15px;margin:0'>Your message about <strong>$t</strong> is with our team. We reply to every contact-form message within <strong>24 hours</strong> (often much sooner during Lagos working hours).</p>
      <p style='color:#4A3624;font-size:15px;margin:18px 0 0'>If your question is time-sensitive, these are the fastest routes:</p>
      <div style='margin:16px 0;padding:16px 18px;background:#FBF4E8;border:1px solid #3A2A1A;border-radius:10px'>
        <p style='margin:0 0 8px;font-size:14px'>📞 <strong style='color:#B8721B'>+234 704 444 6517</strong> · Mon–Fri 9 am – 6 pm WAT</p>
        <p style='margin:0 0 8px;font-size:14px'>✉️ <a href='mailto:academy@mamcentre.org' style='color:#B8721B'>academy@mamcentre.org</a> · WhatsApp on the same number</p>
        <p style='margin:0;font-size:14px'>🎓 Ready to apply? <a href='https://academy.mamcentre.org/apply' style='color:#B8721B'>apply</a> · 5 steps, auto-saved on this device</p>
      </div>
      " . mam_email_promo_block() . "
      <p style='font-size:13px;color:#9A8264;margin:24px 0 0'>You're getting this once — it's our acknowledgement that we received your note. A real human will reply from this same address shortly.</p>";
    return mam_email_layout([
        'header' => 'MAM ACADEMY',
        'preheader' => 'We received your message — here is how to reach us faster.',
        'reason' => 'You received this because you contacted MAM Academy through our website.',
        'unsubscribe' => true,
        'body' => $inner,
    ]);
}

function mam_email_application_confirmation(array $app, array $customer, ?array $tier = null, string $invoiceNumber = ''): string {
    // Editorial-grade transactional email — premium feel without leaving
    // Outlook 2007 behind. Light ground, generous whitespace, single
    // accent column, real typographic hierarchy. The invoice + signed
    // policy used to be .html attachments that mail clients rendered as
    // source code; they're now standalone web pages linked from here so
    // recipients view + Save-as-PDF directly from their browser.
    $ref      = htmlspecialchars($app['reference'], ENT_QUOTES);
    $first    = htmlspecialchars(($customer['first_name'] ?? '') ?: 'there', ENT_QUOTES);
    $appUrl   = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');
    $bookUrl  = htmlspecialchars($appUrl . '/book?ref=' . rawurlencode($app['reference']), ENT_QUOTES);
    $invUrl   = htmlspecialchars($appUrl . '/api/invoice/' . rawurlencode($app['reference']), ENT_QUOTES);
    $polUrl   = htmlspecialchars($appUrl . '/api/policy/'  . rawurlencode($app['reference']), ENT_QUOTES);
    $label    = htmlspecialchars($tier['label'] ?? '$5', ENT_QUOTES);
    $invNo    = $invoiceNumber ? htmlspecialchars($invoiceNumber, ENT_QUOTES) : '';
    $year     = date('Y');
    $hasPolicy = !empty($app['policy_mode']);

    $pathwayMap = [
        'certified-musician' => 'Certified Musician', 'grand-musician' => 'Grand Musician',
        'crash' => 'Crash Programme', 'meta-order' => 'The MetaMusic Order',
        // Legacy keys retained for historical applications.
        'pre-cert' => 'Pre-Certification', 'cert' => 'Certification',
        'pre-dip' => 'Pre-Diploma', 'diploma' => 'Diploma',
    ];
    $pathwayLabel = htmlspecialchars($pathwayMap[$app['pathway'] ?? ''] ?? (string)($app['pathway'] ?? '—'), ENT_QUOTES);
    $modeLabel = htmlspecialchars(ucfirst((string)($app['mode'] ?? '—')), ENT_QUOTES);
    $discLabel = htmlspecialchars(ucwords((string)($app['discipline'] ?? '')), ENT_QUOTES);

    $html = '<!doctype html><html lang="en"><head>'
      . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
      . '<meta name="x-apple-disable-message-reformatting"><meta name="color-scheme" content="light dark">'
      . '<title>Application received — MAM Academy</title>'
      . '<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->'
      . '<style>'
      . 'body{margin:0;padding:0;background:#F6F1E8;-webkit-text-size-adjust:100%}'
      . 'table{border-collapse:collapse}td{mso-line-height-rule:exactly}'
      . 'a{color:#B8721B;text-decoration:none}'
      . 'a.btn-primary:hover{background:#9C5E12!important}'
      . '.link:hover{color:#1A120B!important;border-color:#1A120B!important}'
      . '@media only screen and (max-width:600px){'
      . '  .container{width:100%!important;border-radius:0!important}'
      . '  .px{padding-left:26px!important;padding-right:26px!important}'
      . '  .py{padding-top:32px!important;padding-bottom:32px!important}'
      . '  .h1{font-size:28px!important;line-height:1.22!important}'
      . '  .btn-primary{display:block!important;padding:18px!important}'
      . '  .grid-half{display:block!important;width:100%!important;padding:0!important}'
      . '  .grid-divider{display:block!important;height:1px!important;background:#ECDFC4!important;margin:18px 0!important}'
      . '}'
      . '</style></head>'
      . '<body style="margin:0;padding:28px 0;background:#F6F1E8;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif">'
      // Preheader (inbox preview text)
      . '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#F6F1E8">Application ' . $ref . ' — reserve your seat with a one-time ' . $label . '. View invoice + policy inside.</div>'
      . '<center>'
      . '<!--[if mso]><table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" width="620"><tr><td><![endif]-->'
      . '<table role="presentation" class="container" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:620px;margin:0 auto;background:#FFFFFF;border-radius:18px;overflow:hidden;box-shadow:0 12px 36px rgba(26,18,11,0.10)">'

      // ── Hairline gold accent strip (single thin bar) — sets the tone without
      //    being heavy. Real designers use this; gradients break in Outlook.
      . '<tr><td style="background:#B8721B;height:5px;line-height:5px;font-size:0">&nbsp;</td></tr>'

      // ── Editorial header: wordmark + small uppercase eyebrow above
      . '<tr><td class="px" style="padding:32px 44px 0;text-align:center">'
      .   '<p style="margin:0;font-size:10.5px;letter-spacing:0.32em;text-transform:uppercase;color:#B8721B;font-weight:700">A Creative Intelligence &amp; Performance Academy</p>'
      .   '<p style="margin:8px 0 0;font-family:Georgia,\'Times New Roman\',serif;font-size:22px;letter-spacing:0.18em;color:#1A120B;font-weight:bold">MAM ACADEMY</p>'
      .   '<table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" style="margin:18px auto 0"><tr><td style="height:1px;background:#ECDFC4;width:60px;line-height:1px;font-size:0">&nbsp;</td></tr></table>'
      . '</td></tr>'

      // ── Hero block — large, editorial-style serif headline
      . '<tr><td class="px py" style="padding:32px 44px 4px;text-align:left">'
      .   '<p style="margin:0 0 14px;font-size:11px;letter-spacing:0.16em;text-transform:uppercase;color:#9A6B1E;font-weight:700">Application received</p>'
      .   '<h1 class="h1" style="margin:0 0 16px;font-family:Georgia,\'Times New Roman\',serif;font-size:32px;line-height:1.18;color:#1A120B;font-weight:700;letter-spacing:-0.005em">Welcome aboard,<br>' . $first . '.</h1>'
      .   '<p style="margin:0;color:#4A3624;font-size:16px;line-height:1.7">We have your application. Our Admin Officer and the MAM Auditer Judges will review it shortly — the next step is to lock in your seat.</p>'
      . '</td></tr>'

      // ── Reference (large, prominent) and meta in a clean stacked card
      . '<tr><td class="px" style="padding:28px 44px 4px">'
      .   '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #ECDFC4;border-radius:12px">'
      .     '<tr><td style="padding:22px 24px 18px;background:#FBF4E8;border-radius:12px 12px 0 0">'
      .       '<p style="margin:0 0 6px;font-size:10.5px;letter-spacing:0.14em;text-transform:uppercase;color:#9A6B1E;font-weight:700">Reference</p>'
      .       '<p style="margin:0;font-family:\'SF Mono\',Menlo,Consolas,monospace;font-size:20px;color:#1A120B;font-weight:700;letter-spacing:0.04em">' . $ref . '</p>'
      .     '</td></tr>'
      .     '<tr><td style="padding:20px 24px 22px;background:#FFFFFF;border-radius:0 0 12px 12px">'
      .       '<table role="presentation" width="100%"><tr>'
      .         '<td class="grid-half" style="width:50%;padding-right:16px;vertical-align:top">'
      .           '<p style="margin:0 0 4px;font-size:10.5px;letter-spacing:0.12em;text-transform:uppercase;color:#9A6B1E;font-weight:700">Programme</p>'
      .           '<p style="margin:0;color:#1A120B;font-size:15px;font-weight:600;line-height:1.4">' . $pathwayLabel . '</p>'
      .         '</td>'
      .         '<td class="grid-half" style="width:50%;padding-left:16px;border-left:1px solid #ECE0CC;vertical-align:top">'
      .           '<p style="margin:0 0 4px;font-size:10.5px;letter-spacing:0.12em;text-transform:uppercase;color:#9A6B1E;font-weight:700">Mode &middot; Discipline</p>'
      .           '<p style="margin:0;color:#1A120B;font-size:15px;font-weight:600;line-height:1.4">' . $modeLabel . ($discLabel ? ' &middot; <span style="color:#4A3624;font-weight:500">' . $discLabel . '</span>' : '') . '</p>'
      .         '</td>'
      .       '</tr></table>'
      .     '</td></tr>'
      .   '</table>'
      . '</td></tr>'

      // ── Next step — section with a thicker headline and the primary CTA
      . '<tr><td class="px" style="padding:36px 44px 0">'
      .   '<table role="presentation" align="left" border="0" cellpadding="0" cellspacing="0" style="margin:0 0 18px"><tr><td style="height:1px;background:#1A120B;width:32px;line-height:1px;font-size:0">&nbsp;</td></tr></table>'
      .   '<p style="margin:0 0 8px;font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#1A120B;font-weight:700">Next step</p>'
      .   '<h2 style="margin:0 0 12px;font-family:Georgia,serif;font-size:22px;color:#1A120B;font-weight:700;line-height:1.3">Reserve your seat — <span style="color:#B8721B">' . $label . '</span> &middot; one-time</h2>'
      .   '<p style="margin:0 0 24px;color:#4A3624;font-size:15px;line-height:1.75">Confirms your slot with our Admin Officer and the MAM Auditer Judges. Covers registration, your <strong style="color:#1A120B">MAM Academy student ID card</strong>, your <strong style="color:#1A120B">student file</strong>, and a <strong style="color:#1A120B">30-minute Auditer assessment</strong>. Tuition is discussed afterwards — never at this step.</p>'
      // CTA button
      .   '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $bookUrl . '" style="height:56px;v-text-anchor:middle;width:300px" arcsize="14%" stroke="f" fillcolor="#1A120B"><w:anchorlock/><center style="color:#FBBF24;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;letter-spacing:0.04em">RESERVE MY SEAT &nbsp;→</center></v:roundrect><![endif]-->'
      .   '<!--[if !mso]><!-- -->'
      .   '<a href="' . $bookUrl . '" class="btn-primary" style="display:inline-block;background:#1A120B;color:#FBBF24;font-size:14px;font-weight:700;text-decoration:none;padding:18px 36px;border-radius:12px;letter-spacing:0.12em;text-transform:uppercase;mso-padding-alt:0">Reserve my seat &nbsp;→</a>'
      .   '<!--<![endif]-->'
      .   '<p style="margin:14px 0 0;color:#7A5C3D;font-size:12.5px;line-height:1.6;letter-spacing:0.02em">Slots fill weekly. Bookings without payment release after 48 hours.</p>'
      . '</td></tr>'

      // ── Documents: links to the web-viewable invoice + policy (NOT attached
      //    as .html which most mail clients render as source code).
      . '<tr><td class="px" style="padding:36px 44px 0">'
      .   '<p style="margin:0 0 14px;font-size:11px;letter-spacing:0.16em;text-transform:uppercase;color:#9A6B1E;font-weight:700">Your documents</p>'
      .   '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #ECDFC4;border-radius:10px;background:#FBF4E8">'
      .     '<tr><td style="padding:16px 20px">'
      .       '<table role="presentation" width="100%"><tr>'
      .         '<td style="vertical-align:middle">'
      .           '<p style="margin:0;color:#1A120B;font-size:14px;font-weight:600">Invoice ' . ($invNo ?: $ref) . '</p>'
      .           '<p style="margin:2px 0 0;color:#7A5C3D;font-size:12.5px;line-height:1.5">View in your browser &middot; Print or Save as PDF</p>'
      .         '</td>'
      .         '<td align="right" style="vertical-align:middle;white-space:nowrap">'
      .           '<a href="' . $invUrl . '" class="link" style="display:inline-block;padding:9px 16px;border:1px solid #B8721B;border-radius:8px;color:#B8721B;font-size:12.5px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase">View →</a>'
      .         '</td>'
      .       '</tr></table>'
      .     '</td></tr>'
      .     ($hasPolicy ? '<tr><td style="padding:0 20px"><div style="height:1px;background:#ECE0CC"></div></td></tr>'
      .     '<tr><td style="padding:16px 20px">'
      .       '<table role="presentation" width="100%"><tr>'
      .         '<td style="vertical-align:middle">'
      .           '<p style="margin:0;color:#1A120B;font-size:14px;font-weight:600">Signed class policy</p>'
      .           '<p style="margin:2px 0 0;color:#7A5C3D;font-size:12.5px;line-height:1.5">' . htmlspecialchars(ucfirst((string)$app['policy_mode'])) . ' &middot; e-signed on submission</p>'
      .         '</td>'
      .         '<td align="right" style="vertical-align:middle;white-space:nowrap">'
      .           '<a href="' . $polUrl . '" class="link" style="display:inline-block;padding:9px 16px;border:1px solid #B8721B;border-radius:8px;color:#B8721B;font-size:12.5px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase">View →</a>'
      .         '</td>'
      .       '</tr></table>'
      .     '</td></tr>' : '')
      .   '</table>'
      . '</td></tr>'

      // ── Scholarship — for applicants who may need financial help
      . '<tr><td class="px" style="padding:28px 44px 0">' . mam_email_scholarship_block() . '</td></tr>'

      // ── Quiet pull-quote / brand note
      . '<tr><td class="px" style="padding:36px 44px 0">'
      .   '<p style="margin:0;font-family:Georgia,serif;font-style:italic;font-size:15px;color:#7A5C3D;line-height:1.7;text-align:center;border-top:1px solid #ECE0CC;padding-top:24px">Crafting the Timeless in Time.</p>'
      . '</td></tr>'

      // ── Footer (transactional — no unsubscribe noise)
      . '<tr><td class="px" style="padding:28px 44px 36px">'
      .   '<p style="margin:0 0 12px;color:#4A3624;font-size:13.5px;line-height:1.7">Questions? Reply directly to this email, or write to <a href="mailto:academy@mamcentre.org" style="color:#B8721B;font-weight:600">academy@mamcentre.org</a>.</p>'
      .   '<p style="margin:18px 0 0;color:#9A8264;font-size:11.5px;line-height:1.7">MAM Academy &middot; MAM Centre &middot; 2 Oremeji Street, Egbeda, Lagos, Nigeria<br>+234 704 444 6517 &middot; <a href="' . $appUrl . '/privacy" style="color:#B8721B">Privacy</a> &middot; <a href="' . $appUrl . '" style="color:#B8721B">academy.mamcentre.org</a></p>'
      .   '<p style="margin:10px 0 0;color:#C2AC85;font-size:10.5px;letter-spacing:0.06em">&copy; ' . $year . ' MAM Centre</p>'
      . '</td></tr>'

      . '</table>'
      . '<!--[if mso]></td></tr></table><![endif]-->'
      . '</center></body></html>';
    return $html;
}

/**
 * MAM Academy invoice (HTML, self-contained, print-friendly).
 *
 * Modernised layout: rounded card on a wash, gradient brand header with the
 * logo + wordmark, billed-to/contact cards, a programme summary, a "Due now —
 * booking & assessment" line-item table with a gold total pill, a clearly-
 * separated "Estimated tuition" card (NOT payable now — confirmed after the
 * MAM Auditer placement), a configurable bank-transfer card (no fixed bank
 * brand), terms, and a finance signature. Self-contained inline CSS + responsive
 * so it renders identically in a browser, on mobile, or printed to PDF.
 *
 * Bank/finance details come from settings (invoice_bank_name / _account_name /
 * _account_number / _finance_name) with safe defaults, so they're editable
 * without a code change. $app + $customer from the DB; $tier from
 * mam_booking_tier(); the tuition estimate is the application's total_usd.
 */
function mam_invoice_print_html(array $app, array $customer, array $tier, string $invoiceNumber): string {
    $name  = htmlspecialchars(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')), ENT_QUOTES);
    $phone = htmlspecialchars((string)($customer['phone'] ?? ''), ENT_QUOTES);
    $email = htmlspecialchars((string)($customer['email'] ?? ''), ENT_QUOTES);
    $loc   = htmlspecialchars(trim(implode(', ', array_filter([$customer['lga'] ?? '', $customer['state'] ?? '', $customer['country'] ?? '']))) ?: 'Nigeria', ENT_QUOTES);
    $ref   = htmlspecialchars((string)$app['reference'], ENT_QUOTES);
    $dateLong = date('l, jS \\of F, Y');

    $pathwayMap = [
        // Current programmes.
        'certified-musician' => ['Certified Musician',            'One major tradition · 12 weeks'],
        'grand-musician'     => ['Grand Musician',                'All three traditions · 12 weeks'],
        'crash'              => ['Crash Programme',               '6-week accelerated intensive'],
        'meta-order'         => ['The MetaMusic Order',           'Premium track'],
        // Legacy keys kept so historical invoices still render their names.
        'pre-cert'   => ['Pre-Certification (Foundation)',    'Online · 12 weeks'],
        'cert'       => ['Certification (KTAMS / MAM)',       'Online · 12 weeks'],
        'pre-dip'    => ['Pre-Diploma',                       'Online · 12 weeks'],
        'diploma'    => ['Diploma',                           'Online · 12 weeks'],
    ];
    $pathwayKey = (string)($app['pathway'] ?? '');
    $pathwayName = $pathwayMap[$pathwayKey][0] ?? ucfirst($pathwayKey);
    $pathwaySub  = $pathwayMap[$pathwayKey][1] ?? '';
    $mode = htmlspecialchars((string)($app['mode'] ?? '—'), ENT_QUOTES);
    $disc = htmlspecialchars((string)($app['discipline'] ?? '—'), ENT_QUOTES);
    $track = htmlspecialchars((string)($app['program_track'] ?? 'MAM'), ENT_QUOTES);

    $cur = (string)$tier['currency'];
    $label = (string)$tier['label'];
    $fmt = function ($n) use ($cur) {
        $sym = ['NGN'=>'₦','USD'=>'$','GBP'=>'£','EUR'=>'€','CAD'=>'CA$','AUD'=>'AU$','GHS'=>'GH₵','KES'=>'KSh ','ZAR'=>'R'][$cur] ?? ($cur.' ');
        return $sym . number_format($n, 0);
    };
    // The booking fee is one fee covering four deliverables — shown as line
    // items that sum to the exact regional total (last line absorbs rounding).
    $bookingTotal = (float)$tier['amount'];
    $each  = floor($bookingTotal / 4);
    $remainder = $bookingTotal - ($each * 3);

    // Estimated tuition — the server-computed programme cost stored on the
    // application (NOT payable now; confirmed after the MAM Auditer placement).
    $tuitionUsd  = (float)($app['total_usd'] ?? 0);
    $ngnRate     = function_exists('mam_usd_to_ngn_rate') ? mam_usd_to_ngn_rate() : 0;
    $tuitionNgn  = $ngnRate > 0 ? (int)round($tuitionUsd * $ngnRate) : 0;
    $tuitionMain = '$' . number_format($tuitionUsd, 0);
    $tuitionAlt  = ($tuitionNgn > 0 && $cur !== 'USD') ? ' &middot; approx ₦' . number_format($tuitionNgn) : '';

    // Bank-transfer details — configurable (GTCO branding removed). Admin sets
    // these in Pricing & Settings; sensible defaults keep existing invoices intact.
    $bankName = htmlspecialchars((string)mam_setting('invoice_bank_name', ''), ENT_QUOTES);
    $acctName = htmlspecialchars((string)mam_setting('invoice_account_name', 'The Just Folks Limited'), ENT_QUOTES);
    $acctNo   = htmlspecialchars((string)mam_setting('invoice_account_number', '0117579643'), ENT_QUOTES);
    $finName  = htmlspecialchars((string)mam_setting('invoice_finance_name', 'Anuoluwapo O. Ogunbanjo'), ENT_QUOTES);

    $logoUrl = htmlspecialchars(rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/mam-academy-logo.svg', ENT_QUOTES);
    $invNo   = htmlspecialchars($invoiceNumber, ENT_QUOTES);

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
      . '<title>Invoice ' . $invNo . ' — MAM Academy</title>'
      . '<meta name="viewport" content="width=device-width,initial-scale=1">'
      . '<style>'
      . ':root{--ink:#1A120B;--muted:#7A6A56;--faint:#A09080;--hair:#E2D9CC;--gold:#C17B2A;--paper:#FDFCF9;--wash:#F6F1E8}'
      . '@page{size:A4;margin:0}'
      . '*{box-sizing:border-box}'
      . 'body{margin:0;font-family:Inter,-apple-system,Segoe UI,Arial,sans-serif;color:var(--ink);background:#EDE9E3;-webkit-print-color-adjust:exact;print-color-adjust:exact;font-size:14px;line-height:1.5}'
      . '.sheet{max-width:840px;margin:28px auto;background:var(--paper);border-left:5px solid var(--gold);border-radius:0 8px 8px 0;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.11)}'
      . '.print-bar{padding:16px 52px 0;text-align:right}'
      . '.btn-p{display:inline-flex;align-items:center;gap:7px;background:var(--ink);color:#fff;border:none;border-radius:6px;padding:9px 18px;font-size:11px;font-weight:700;letter-spacing:.08em;cursor:pointer;text-transform:uppercase}'
      . '.btn-p:hover{opacity:.85}'
      . '.mast{display:flex;justify-content:space-between;align-items:flex-start;gap:32px;padding:28px 52px 26px}'
      . '.inv-word{font-family:Georgia,"Times New Roman",serif;font-size:64px;font-weight:700;letter-spacing:-.01em;line-height:.95;color:var(--ink);margin:0}'
      . '.inv-tag{margin-top:10px;font-size:10.5px;letter-spacing:.13em;text-transform:uppercase;color:var(--muted)}'
      . '.mast-right{text-align:right}'
      . '.brand{display:flex;align-items:center;gap:11px;justify-content:flex-end;margin-bottom:10px}'
      . '.logo-box{width:42px;height:42px;background:var(--ink);border-radius:8px;display:flex;align-items:center;justify-content:center;padding:6px;flex-shrink:0}'
      . '.logo-box img{width:100%;height:auto;display:block}'
      . '.brand-copy{text-align:right}'
      . '.brand-nm{font-family:Georgia,serif;font-size:15.5px;font-weight:700;letter-spacing:.01em;line-height:1.2}'
      . '.brand-sub{font-size:9.5px;letter-spacing:.22em;text-transform:uppercase;color:var(--gold);margin-top:2px;font-weight:600}'
      . '.issuer{font-size:11.5px;line-height:1.7;color:var(--muted)}'
      . '.issuer .tax{display:block;margin-top:4px;font-size:9.5px;letter-spacing:.06em;color:var(--faint)}'
      . '.hdivide{height:1px;background:var(--hair);margin:0 52px}'
      . '.body{padding:28px 52px 38px}'
      . '.info-row{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:26px;padding-bottom:26px;border-bottom:1px solid var(--hair)}'
      . '.fl{font-size:9.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--faint);margin-bottom:7px;font-weight:600}'
      . '.bill-nm{font-size:15px;font-weight:700;margin-bottom:5px}'
      . '.bill-dt{font-size:13px;color:var(--muted);line-height:1.55}'
      . '.meta-stack{display:flex;flex-direction:column;gap:6px}'
      . '.mr{display:flex;justify-content:space-between;gap:16px;font-size:12.5px}'
      . '.mr span{color:var(--muted)}'
      . '.mr b{color:var(--ink);font-weight:600}'
      . '.ref-val{color:var(--gold)}'
      . '.prog-pill{background:var(--wash);border-left:3px solid var(--gold);border-radius:0 6px 6px 0;padding:11px 16px;margin-bottom:26px;font-size:12.5px;color:var(--muted);line-height:1.65}'
      . '.prog-pill b{color:var(--ink)}'
      . '.sec-eye{font-size:9.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--gold);font-weight:700;margin-bottom:11px}'
      . '.sec-eye2{font-size:9.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);font-weight:700;margin-bottom:11px}'
      . 'table.it{width:100%;border-collapse:collapse;font-size:13px}'
      . 'table.it thead th{font-size:9.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--faint);font-weight:600;padding:0 0 10px;border-bottom:1px solid var(--hair);text-align:left}'
      . 'table.it thead th.r{text-align:right}'
      . 'table.it tbody td{padding:13px 0;border-bottom:1px solid var(--hair);vertical-align:top}'
      . 'table.it tbody tr:last-child td{border-bottom:none}'
      . 'table.it tbody td.r{text-align:right;font-variant-numeric:tabular-nums}'
      . 'table.it td .nm{font-weight:600;color:var(--ink);display:block}'
      . 'table.it td .sub{display:block;font-size:11px;color:var(--faint);margin-top:3px}'
      . '.total-row{display:flex;justify-content:flex-end;padding:18px 0 26px;border-top:1px solid var(--hair)}'
      . '.total-box .tl{font-size:9.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:5px}'
      . '.total-box .ta{font-family:Georgia,"Times New Roman",serif;font-size:38px;font-weight:700;letter-spacing:-.01em;color:var(--ink);font-variant-numeric:tabular-nums;line-height:1}'
      . '.tuit-row{display:flex;justify-content:space-between;align-items:center;gap:16px;border:1px solid var(--hair);border-left:3px solid var(--hair);border-radius:0 6px 6px 0;padding:14px 18px;margin-bottom:28px;background:var(--wash)}'
      . '.tuit-desc{font-size:13px;color:var(--muted);line-height:1.55}'
      . '.tuit-desc b{color:var(--ink)}'
      . '.tuit-val{text-align:right;white-space:nowrap}'
      . '.tuit-num{font-family:Georgia,serif;font-size:26px;font-weight:700;color:var(--ink);font-variant-numeric:tabular-nums;line-height:1}'
      . '.tuit-cur{font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);margin-top:3px}'
      . '.pay-row{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px}'
      . '.pay-col h4{font-size:9.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--gold);font-weight:700;margin:0 0 10px}'
      . '.bkrow{display:flex;justify-content:space-between;font-size:12.5px;padding:5px 0;border-bottom:1px dotted var(--hair)}'
      . '.bkrow:last-of-type{border-bottom:none}'
      . '.bkrow span{color:var(--muted)}'
      . '.bkrow b{color:var(--ink);font-weight:600}'
      . '.pay-note{margin-top:8px;font-size:11px;color:var(--faint);line-height:1.6}'
      . '.terms-txt{font-size:12px;color:var(--muted);line-height:1.65}'
      . '.sign{display:flex;justify-content:flex-end;padding-top:4px;margin-bottom:20px}'
      . '.sig{text-align:right;min-width:200px;border-top:1px solid var(--ink);padding-top:8px}'
      . '.sig .snm{font-size:13px;font-weight:600}'
      . '.sig .srl{font-size:11px;color:var(--gold);font-weight:600;margin-top:3px;letter-spacing:.04em}'
      . '.foot{border-top:1px solid var(--hair);padding:14px 52px;font-size:11px;color:var(--faint);text-align:center;line-height:1.8}'
      . '@media print{.print-bar{display:none}body{background:#fff}.sheet{box-shadow:none;margin:0;max-width:none;border-radius:0;border-left-width:4px}}'
      . '@media(max-width:580px){.mast,.body{padding-left:24px;padding-right:24px}.hdivide{margin:0 24px}.mast{flex-direction:column;gap:16px}.mast-right{text-align:left}.brand{justify-content:flex-start}.brand-copy{text-align:left}.issuer{text-align:left}.info-row,.pay-row{grid-template-columns:1fr}.total-row{justify-content:flex-start}.tuit-row{flex-direction:column;align-items:flex-start}}'
      . '</style></head><body>'
      . '<div class="sheet">'

      . '<div class="print-bar"><button class="btn-p" onclick="window.print()">&#9113; Print / Save as PDF</button></div>'

      // Masthead: large serif title left, brand + issuer right
      . '<div class="mast">'
      .   '<div>'
      .     '<h1 class="inv-word">Invoice</h1>'
      .     '<div class="inv-tag">Booking confirmation &amp; tuition estimate</div>'
      .   '</div>'
      .   '<div class="mast-right">'
      .     '<div class="brand">'
      .       '<div class="brand-copy"><div class="brand-nm">MAM Academy</div><div class="brand-sub">Music &middot; Arts &middot; Mastery</div></div>'
      .       '<div class="logo-box"><img src="' . $logoUrl . '" alt="MAM Academy"></div>'
      .     '</div>'
      .     '<div class="issuer">2 Abolude / Oremeji Street, Bakery B/S<br>Egbeda, Lagos, Nigeria<span class="tax">TAX ID &middot; 119 491 230001</span></div>'
      .   '</div>'
      . '</div>'

      . '<div class="hdivide"></div>'

      . '<div class="body">'

      // Billed-to + invoice details
      .   '<div class="info-row">'
      .     '<div><div class="fl">Billed to</div>'
      .       '<div class="bill-nm">' . $name . '</div>'
      .       '<div class="bill-dt">' . $loc . ($phone ? '<br>' . $phone : '') . ($email ? '<br>' . $email : '') . '</div>'
      .     '</div>'
      .     '<div><div class="fl">Invoice details</div>'
      .       '<div class="meta-stack">'
      .         '<div class="mr"><span>Invoice number</span><b>' . $invNo . '</b></div>'
      .         '<div class="mr"><span>Date</span><b>' . $dateLong . '</b></div>'
      .         '<div class="mr"><span>Reference</span><b><span class="ref-val">' . $ref . '</span></b></div>'
      .       '</div>'
      .     '</div>'
      .   '</div>'

      // Programme summary
      .   '<div class="prog-pill">'
      .     '<b>Programme:</b> ' . htmlspecialchars($pathwayName) . ($pathwaySub ? ' &middot; <span style="color:var(--faint)">' . htmlspecialchars($pathwaySub) . '</span>' : '')
      .     '<br><b>Track:</b> ' . $track . ' &middot; <b>Mode:</b> ' . $mode . ' &middot; <b>Discipline:</b> ' . $disc
      .   '</div>'

      // Due now line items
      .   '<div class="sec-eye">Due now &mdash; booking &amp; assessment</div>'
      .   '<table class="it"><thead><tr><th>Item</th><th class="r" style="width:50px">Qty</th><th class="r" style="width:90px">Unit</th><th class="r" style="width:100px">Amount</th></tr></thead><tbody>'
      .     '<tr><td><span class="nm">Registration &amp; admission</span><span class="sub">Application processing &amp; admission</span></td><td class="r">1</td><td class="r">' . $fmt($each) . '</td><td class="r">' . $fmt($each) . '</td></tr>'
      .     '<tr><td><span class="nm">MAM Academy student ID card</span><span class="sub">Physical ID issued at the Centre</span></td><td class="r">1</td><td class="r">' . $fmt($each) . '</td><td class="r">' . $fmt($each) . '</td></tr>'
      .     '<tr><td><span class="nm">Student file</span><span class="sub">Onboarding documentation kept on record</span></td><td class="r">1</td><td class="r">' . $fmt($each) . '</td><td class="r">' . $fmt($each) . '</td></tr>'
      .     '<tr><td><span class="nm">MAM Auditer assessment</span><span class="sub">30-minute placement session with our judges</span></td><td class="r">1</td><td class="r">' . $fmt($remainder) . '</td><td class="r">' . $fmt($remainder) . '</td></tr>'
      .   '</tbody></table>'

      // Total
      .   '<div class="total-row"><div class="total-box"><div class="tl">Total due now</div><div class="ta">' . $label . '</div></div></div>'

      // Estimated tuition
      .   '<div class="sec-eye2">Estimated tuition</div>'
      .   '<div class="tuit-row">'
      .     '<div class="tuit-desc"><b>Programme tuition &mdash; payable after placement.</b><br>This is an estimate for the ' . htmlspecialchars($pathwayName) . ' programme. It is <b>not payable now</b>: your exact tuition is confirmed after the MAM Auditer judges place you, and flexible plans are available.</div>'
      .     '<div class="tuit-val"><div class="tuit-num">' . $tuitionMain . '</div><div class="tuit-cur">USD' . $tuitionAlt . '</div></div>'
      .   '</div>'

      // Payment details + terms
      .   '<div class="pay-row">'
      .     '<div class="pay-col"><h4>Bank transfer</h4>'
      .       ($bankName !== '' ? '<div class="bkrow"><span>Bank</span><b>' . $bankName . '</b></div>' : '')
      .       '<div class="bkrow"><span>Account name</span><b>' . $acctName . '</b></div>'
      .       '<div class="bkrow"><span>Account number</span><b>' . $acctNo . '</b></div>'
      .       '<p class="pay-note">Use reference <b style="color:var(--ink)">' . $ref . '</b> as the transfer narration, or pay by card via the link in your email.</p>'
      .     '</div>'
      .     '<div class="pay-col"><h4>Terms &amp; conditions</h4>'
      .       '<p class="terms-txt">The booking-confirmation fee is due before your scheduled session and is final once paid. Tuition is billed separately, only after the MAM Auditer judges have placed you. Other terms follow the academy class policy.</p>'
      .     '</div>'
      .   '</div>'

      // Signature
      .   '<div class="sign"><div class="sig"><div class="snm">' . $finName . '</div><div class="srl">Finance Manager</div></div></div>'

      .   mam_email_scholarship_block()
      . '</div>'

      . '<div class="foot">academy@mamcentre.org &nbsp;&middot;&nbsp; academy.mamcentre.org &nbsp;&middot;&nbsp; +234 704 444 6517</div>'
      . '</div></body></html>';
}

/**
 * Standard programme invoice — a clean business invoice for the full programme
 * tuition. This is distinct from mam_invoice_print_html() (the booking
 * confirmation / assessment-fee document). Use this when the client needs a
 * proper invoice showing the course cost as the billable line item, e.g. for
 * corporate reimbursement, accounting records, or a formal payment request.
 *
 * Layout: issuer block, billed-to block, itemised line table (programme +
 * optional registration), total, payment instructions, payment status stamp.
 * Accessible at GET /api/tuition-invoice/{reference}.
 */
function mam_programme_invoice_html(array $app, array $customer, string $invoiceNumber): string {
    $name    = htmlspecialchars(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')), ENT_QUOTES);
    $email   = htmlspecialchars((string)($customer['email'] ?? ''), ENT_QUOTES);
    $phone   = htmlspecialchars((string)($customer['phone'] ?? ''), ENT_QUOTES);
    $loc     = htmlspecialchars(trim(implode(', ', array_filter([
        $customer['address'] ?? '', $customer['lga'] ?? '', $customer['state'] ?? '', $customer['country'] ?? ''
    ]))) ?: 'Nigeria', ENT_QUOTES);
    $ref      = htmlspecialchars((string)$app['reference'], ENT_QUOTES);
    $invNo    = htmlspecialchars($invoiceNumber, ENT_QUOTES);
    $dateIso  = substr((string)($app['created_at'] ?? date('Y-m-d')), 0, 10);
    $dateDisp = htmlspecialchars(date('j F Y', strtotime($dateIso) ?: time()), ENT_QUOTES);
    $isPaid   = in_array((string)($app['status'] ?? ''), ['paid', 'enrolled', 'accepted'], true);

    $pathwayMap = [
        'standard'           => 'Standard Programme',
        'certified-musician' => 'Certified Musician Programme',
        'grand-musician'     => 'Grand Musician Programme',
        'crash'              => 'Crash Programme (6-week)',
        'meta-order'         => 'The MetaMusic Order',
        'pre-cert'  => 'Pre-Certification', 'cert' => 'Certification',
        'pre-dip'   => 'Pre-Diploma',        'diploma' => 'Diploma',
    ];
    $pathwayLabel = htmlspecialchars($pathwayMap[$app['pathway'] ?? ''] ?? ucwords(str_replace('-', ' ', (string)($app['pathway'] ?? '—'))), ENT_QUOTES);
    $modeLabel    = htmlspecialchars(ucfirst((string)($app['mode'] ?? '')), ENT_QUOTES);
    $levelLabel   = htmlspecialchars((string)($app['level'] ?? ''), ENT_QUOTES);
    $discLabel    = htmlspecialchars(ucwords(str_replace('-', ' ', (string)($app['discipline'] ?? ''))), ENT_QUOTES);
    $session      = htmlspecialchars(strtoupper((string)($app['session'] ?? '')), ENT_QUOTES);

    $tuitionUsd   = (float)($app['total_usd'] ?? 0);
    $regFee       = (bool)($app['include_registration'] ?? false) ? (float)mam_setting_num('registration_fee', 5) : 0;
    $programmeAmt = $tuitionUsd - $regFee;

    $bankName = htmlspecialchars((string)mam_setting('invoice_bank_name', ''), ENT_QUOTES);
    $acctName = htmlspecialchars((string)mam_setting('invoice_account_name', 'The Just Folks Limited'), ENT_QUOTES);
    $acctNo   = htmlspecialchars((string)mam_setting('invoice_account_number', '0117579643'), ENT_QUOTES);
    $finName  = htmlspecialchars((string)mam_setting('invoice_finance_name', 'Anuoluwapo O. Ogunbanjo'), ENT_QUOTES);
    $logoUrl  = htmlspecialchars(rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/mam-academy-logo.svg', ENT_QUOTES);
    $appUrl   = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');

    $fmt = function (float $n): string {
        return '$' . number_format($n, 2);
    };

    $statusLabel = $isPaid ? 'PAID' : 'AMOUNT DUE';
    $statusColor = $isPaid ? '#16A34A' : '#B8721B';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
      . '<title>Invoice ' . $invNo . ' — MAM Academy</title>'
      . '<meta name="viewport" content="width=device-width,initial-scale=1">'
      . '<style>'
      . ':root{--ink:#1A120B;--muted:#7A6A56;--faint:#A09080;--hair:#E2D9CC;--gold:#C17B2A;--paper:#FDFCF9;--wash:#F6F1E8}'
      . '@page{size:A4;margin:0}'
      . '*{box-sizing:border-box}'
      . 'body{margin:0;font-family:Inter,-apple-system,Segoe UI,Arial,sans-serif;color:var(--ink);background:#EDE9E3;font-size:14px;line-height:1.5;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
      . '.sheet{max-width:800px;margin:28px auto;background:var(--paper);border-left:5px solid var(--gold);border-radius:0 8px 8px 0;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.11)}'
      . '.print-bar{padding:16px 52px 0;text-align:right}'
      . '.btn-p{display:inline-flex;align-items:center;gap:7px;background:var(--ink);color:#fff;border:none;border-radius:6px;padding:9px 18px;font-size:11px;font-weight:700;letter-spacing:.08em;cursor:pointer;text-transform:uppercase}'
      . '.btn-p:hover{opacity:.85}'
      . '.mast{display:flex;justify-content:space-between;align-items:flex-start;gap:32px;padding:28px 52px 26px}'
      . '.inv-word{font-family:Georgia,"Times New Roman",serif;font-size:64px;font-weight:700;letter-spacing:-.01em;line-height:.95;color:var(--ink);margin:0}'
      . '.status-badge{display:inline-flex;align-items:center;margin-top:12px;padding:5px 14px;border-radius:999px;font-size:10.5px;font-weight:800;letter-spacing:.1em;text-transform:uppercase}'
      . '.mast-right{text-align:right}'
      . '.brand{display:flex;align-items:center;gap:11px;justify-content:flex-end;margin-bottom:10px}'
      . '.logo-box{width:42px;height:42px;background:var(--ink);border-radius:8px;display:flex;align-items:center;justify-content:center;padding:6px;flex-shrink:0}'
      . '.logo-box img{width:100%;height:auto;display:block}'
      . '.brand-copy{text-align:right}'
      . '.brand-nm{font-family:Georgia,serif;font-size:15.5px;font-weight:700;letter-spacing:.01em;line-height:1.2}'
      . '.brand-sub{font-size:9.5px;letter-spacing:.22em;text-transform:uppercase;color:var(--gold);margin-top:2px;font-weight:600}'
      . '.issuer{font-size:11.5px;line-height:1.7;color:var(--muted)}'
      . '.issuer .tax{display:block;margin-top:4px;font-size:9.5px;letter-spacing:.06em;color:var(--faint)}'
      . '.hdivide{height:1px;background:var(--hair);margin:0 52px}'
      . '.body{padding:28px 52px 38px}'
      . '.info-row{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:26px;padding-bottom:26px;border-bottom:1px solid var(--hair)}'
      . '.fl{font-size:9.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--faint);margin-bottom:7px;font-weight:600}'
      . '.bill-nm{font-size:15px;font-weight:700;margin-bottom:5px}'
      . '.bill-dt{font-size:13px;color:var(--muted);line-height:1.55}'
      . '.meta-stack{display:flex;flex-direction:column;gap:6px}'
      . '.mr{display:flex;justify-content:space-between;gap:16px;font-size:12.5px}'
      . '.mr span{color:var(--muted)}'
      . '.mr b{color:var(--ink);font-weight:600}'
      . '.ref-val{color:var(--gold)}'
      . '.prog-pill{background:var(--wash);border-left:3px solid var(--gold);border-radius:0 6px 6px 0;padding:11px 16px;margin-bottom:26px;font-size:12.5px;color:var(--muted);line-height:1.65}'
      . '.prog-pill b{color:var(--ink)}'
      . 'table.it{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:4px}'
      . 'table.it thead th{font-size:9.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--faint);font-weight:600;padding:0 0 10px;border-bottom:1px solid var(--hair);text-align:left}'
      . 'table.it thead th.r{text-align:right}'
      . 'table.it tbody td{padding:13px 0;border-bottom:1px solid var(--hair);vertical-align:top}'
      . 'table.it tbody tr:last-child td{border-bottom:none}'
      . 'table.it tbody td.r{text-align:right;font-variant-numeric:tabular-nums;font-weight:600}'
      . 'table.it td .nm{font-weight:600;color:var(--ink);display:block}'
      . 'table.it td .sub{display:block;font-size:11px;color:var(--faint);margin-top:3px}'
      . '.totals-block{display:flex;justify-content:flex-end;margin:4px 0 28px}'
      . '.total-box{min-width:240px;background:var(--wash);border:1px solid var(--hair);border-radius:8px;padding:16px 20px}'
      . '.tline{display:flex;justify-content:space-between;gap:32px;font-size:12.5px;padding:4px 0;color:var(--muted)}'
      . '.tgrand{display:flex;justify-content:space-between;gap:32px;font-family:Georgia,serif;font-size:28px;font-weight:700;padding:12px 0 0;border-top:2px solid var(--ink);margin-top:8px;color:var(--ink)}'
      . '.tgrand span:last-child{font-variant-numeric:tabular-nums;letter-spacing:-.01em}'
      . '.pay-row{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px}'
      . '.pay-col h4{font-size:9.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--gold);font-weight:700;margin:0 0 10px}'
      . '.bkrow{display:flex;justify-content:space-between;font-size:12.5px;padding:5px 0;border-bottom:1px dotted var(--hair)}'
      . '.bkrow:last-of-type{border-bottom:none}'
      . '.bkrow span{color:var(--muted)}'
      . '.bkrow b{color:var(--ink);font-weight:600}'
      . '.pay-note{margin-top:8px;font-size:11px;color:var(--faint);line-height:1.6}'
      . '.terms-txt{font-size:12px;color:var(--muted);line-height:1.65}'
      . '.sign{display:flex;justify-content:flex-end;padding-top:4px;margin-bottom:20px}'
      . '.sig{text-align:right;min-width:200px;border-top:1px solid var(--ink);padding-top:8px}'
      . '.sig .snm{font-size:13px;font-weight:600}'
      . '.sig .srl{font-size:11px;color:var(--gold);font-weight:600;margin-top:3px;letter-spacing:.04em}'
      . '.foot{border-top:1px solid var(--hair);padding:14px 52px;font-size:11px;color:var(--faint);text-align:center;line-height:1.8}'
      . '.foot a{color:var(--gold);text-decoration:none}'
      . '@media print{.print-bar{display:none}body{background:#fff}.sheet{box-shadow:none;margin:0;max-width:none;border-radius:0;border-left-width:4px}}'
      . '@media(max-width:580px){.mast,.body{padding-left:24px;padding-right:24px}.hdivide{margin:0 24px}.mast{flex-direction:column;gap:16px}.mast-right{text-align:left}.brand{justify-content:flex-start}.brand-copy{text-align:left}.issuer{text-align:left}.info-row,.pay-row{grid-template-columns:1fr}.totals-block{justify-content:flex-start}}'
      . '</style></head><body>'
      . '<div class="sheet">'

      . '<div class="print-bar"><button class="btn-p" onclick="window.print()">&#9113; Print / Save as PDF</button></div>'

      // Masthead: large serif "Invoice" + status badge left; brand + issuer right
      . '<div class="mast">'
      .   '<div>'
      .     '<h1 class="inv-word">Invoice</h1>'
      .     '<div class="status-badge" style="background:' . ($isPaid ? '#DCFCE7' : '#FEF3C7') . ';color:' . $statusColor . '">' . $statusLabel . '</div>'
      .   '</div>'
      .   '<div class="mast-right">'
      .     '<div class="brand">'
      .       '<div class="brand-copy"><div class="brand-nm">MAM Academy</div><div class="brand-sub">Music &middot; Arts &middot; Mastery</div></div>'
      .       '<div class="logo-box"><img src="' . $logoUrl . '" alt="MAM Academy"></div>'
      .     '</div>'
      .     '<div class="issuer">2 Abolude / Oremeji Street, Bakery B/S<br>Egbeda, Lagos, Nigeria<br>'
      .       '<a href="mailto:academy@mamcentre.org" style="color:var(--gold);text-decoration:none">academy@mamcentre.org</a>'
      .       '<span class="tax">TAX ID &middot; 119 491 230001</span></div>'
      .   '</div>'
      . '</div>'

      . '<div class="hdivide"></div>'

      . '<div class="body">'

      // Billed-to + invoice details
      .   '<div class="info-row">'
      .     '<div><div class="fl">Bill to</div>'
      .       '<div class="bill-nm">' . $name . '</div>'
      .       '<div class="bill-dt">' . $loc . ($phone ? '<br>' . $phone : '') . ($email ? '<br>' . $email : '') . '</div>'
      .     '</div>'
      .     '<div><div class="fl">Invoice details</div>'
      .       '<div class="meta-stack">'
      .         '<div class="mr"><span>Invoice number</span><b>' . $invNo . '</b></div>'
      .         '<div class="mr"><span>Date</span><b>' . $dateDisp . '</b></div>'
      .         '<div class="mr"><span>Reference</span><b><span class="ref-val">' . $ref . '</span></b></div>'
      .         ($session ? '<div class="mr"><span>Session</span><b>' . $session . '</b></div>' : '')
      .       '</div>'
      .     '</div>'
      .   '</div>'

      // Programme summary
      .   '<div class="prog-pill">'
      .     '<b>Programme:</b> ' . $pathwayLabel
      .     ($modeLabel ? ' &nbsp;&middot;&nbsp; <b>Mode:</b> ' . $modeLabel : '')
      .     ($levelLabel ? ' &nbsp;&middot;&nbsp; <b>Level:</b> ' . $levelLabel : '')
      .     ($discLabel  ? ' &nbsp;&middot;&nbsp; <b>Discipline:</b> ' . $discLabel : '')
      .   '</div>'

      // Line items
      .   '<table class="it"><thead><tr><th>Description</th><th class="r" style="width:60px">Qty</th><th class="r" style="width:110px">Amount</th></tr></thead><tbody>'
      .   '<tr><td><span class="nm">' . $pathwayLabel . '</span>'
      .     '<span class="sub">Programme tuition' . ($session ? ' &middot; ' . $session . ' intake' : '') . ($modeLabel ? ' &middot; ' . $modeLabel : '') . '</span>'
      .   '</td><td class="r">1</td><td class="r">' . $fmt($programmeAmt > 0 ? $programmeAmt : $tuitionUsd) . '</td></tr>'
      .   ($regFee > 0 ? '<tr><td><span class="nm">Registration fee</span><span class="sub">One-time onboarding &amp; student ID card</span></td><td class="r">1</td><td class="r">' . $fmt($regFee) . '</td></tr>' : '')
      .   '</tbody></table>'

      // Total
      .   '<div class="totals-block"><div class="total-box">'
      .   ($regFee > 0 ? '<div class="tline"><span>Programme tuition</span><span>' . $fmt($programmeAmt > 0 ? $programmeAmt : $tuitionUsd) . '</span></div>'
      .     '<div class="tline"><span>Registration fee</span><span>' . $fmt($regFee) . '</span></div>' : '')
      .   '<div class="tgrand"><span>Total (USD)</span><span>' . $fmt($tuitionUsd) . '</span></div>'
      .   '</div></div>'

      // Payment details + terms
      .   '<div class="pay-row">'
      .     '<div class="pay-col"><h4>Bank transfer</h4>'
      .       ($bankName !== '' ? '<div class="bkrow"><span>Bank</span><b>' . $bankName . '</b></div>' : '')
      .       '<div class="bkrow"><span>Account name</span><b>' . $acctName . '</b></div>'
      .       '<div class="bkrow"><span>Account number</span><b>' . $acctNo . '</b></div>'
      .       '<p class="pay-note">Use reference <b style="color:var(--ink)">' . $ref . '</b> as the narration.<br>'
      .       'Online: <a href="' . htmlspecialchars($appUrl . '/tuition?ref=' . rawurlencode((string)$app['reference']), ENT_QUOTES) . '" style="color:var(--gold)">pay by card &rarr;</a></p>'
      .     '</div>'
      .     '<div class="pay-col"><h4>Payment terms</h4>'
      .       '<p class="terms-txt">This invoice covers programme tuition at MAM Academy. Payment is due before the term start date. Instalment plans are available &mdash; contact the finance office to arrange one. All fees are in US dollars; bank transfer equivalents follow the rate quoted by your remitting institution.</p>'
      .     '</div>'
      .   '</div>'

      // Signature
      .   '<div class="sign"><div class="sig"><div class="snm">' . $finName . '</div><div class="srl">Finance Manager</div></div></div>'

      . '</div>'
      . '<div class="foot">MAM Academy &nbsp;&middot;&nbsp; academy.mamcentre.org &nbsp;&middot;&nbsp; <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a> &nbsp;&middot;&nbsp; +234 704 444 6517</div>'
      . '</div></body></html>';
}

/**
 * Store order receipt — sent on successful payment of a store_orders row.
 * Digital items get their file URLs inline (instant delivery). Physical
 * items get an "we'll ship it" line and the order reference for tracking.
 */
function mam_email_store_receipt(array $order, array $items, array $digitalLinks): string {
    $name  = htmlspecialchars((string)($order['customer_name'] ?? 'there'), ENT_QUOTES);
    $ref   = htmlspecialchars((string)$order['reference'], ENT_QUOTES);
    $total = '$' . number_format((float)$order['total_usd'], 2);

    $rows = '';
    foreach ($items as $i) {
        $rows .= "<tr><td style='padding:10px 0;color:#4A3624;font-size:14px'>"
              . htmlspecialchars((string)$i['name']) . " &times; " . (int)$i['qty']
              . "</td><td style='padding:10px 0;text-align:right;color:#fff;font-weight:600;font-variant-numeric:tabular-nums'>$"
              . number_format((float)$i['line_total'], 2) . "</td></tr>";
    }

    $links = '';
    if (!empty($digitalLinks)) {
        $links = "<p style='color:#4A3624;font-size:15px;margin:20px 0 8px'><strong style='color:#1A120B'>Your downloads</strong></p><ul style='padding-left:18px;margin:0;color:#4A3624;font-size:14px;line-height:1.95'>";
        foreach ($digitalLinks as $d) {
            $u = htmlspecialchars((string)$d['url'], ENT_QUOTES);
            $n = htmlspecialchars((string)$d['name'], ENT_QUOTES);
            $links .= "<li>$n &mdash; <a href='$u' style='color:#B8721B'>download</a></li>";
        }
        $links .= "</ul><p style='color:#9A8264;font-size:12px;margin:8px 0 0'>Links are valid for repeated download. Save your files locally.</p>";
    }

    $hasPhysical = false; foreach ($items as $i) if (($i['goods_type'] ?? '') === 'physical') { $hasPhysical = true; break; }
    $ship = $hasPhysical
        ? "<p style='color:#4A3624;font-size:15px;margin:18px 0 0'><i class='fa-solid fa-truck-fast'></i> We'll ship your physical items within 2 business days. You'll get a tracking email when they leave the Centre.</p>"
        : '';

    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>Order confirmed, $name.</h2>
      <p style='color:#4A3624;font-size:15px;margin:0'>Reference: <strong style='color:#B8721B'>$ref</strong></p>
      <table style='width:100%;border-collapse:collapse;margin-top:16px'>$rows
        <tr><td style='padding:14px 0 4px;color:#1A120B;font-weight:800;border-top:1px solid #ECDFC4'>Total</td>
        <td style='padding:14px 0 4px;text-align:right;color:#B8721B;font-weight:800;font-size:18px;border-top:1px solid #ECDFC4'>$total</td></tr></table>
      $links $ship";
    return mam_email_layout([
        'header' => 'MAM ACADEMY STORE',
        'preheader' => 'Order ' . $ref . ' confirmed.',
        'reason' => 'You received this because you purchased from the MAM Academy Store.',
        'unsubscribe' => true,
        'body' => $inner,
    ]);
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
    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>Your seat is reserved, $name.</h2>
      <p style='color:#4A3624;font-size:15px;margin:0'>We received your \$5 confirmation for application <strong style='color:#B8721B'>$ref</strong>. Pick a time on the booking page to lock in your meeting with our Admin Officer and the MAM Auditer Judges.</p>
      " . mam_email_button($bookUrl, 'Pick my time') . "
      <p style='color:#4A3624;font-size:14px;margin:0'>Your \$5 covers:</p>
      <ul style='color:#4A3624;font-size:14px;line-height:1.85;padding-left:18px;margin:8px 0 0'>
        <li>Registration &amp; admission processing</li>
        <li>Your MAM Academy student ID card</li>
        <li>Your student file (kept on record at the Centre)</li>
        <li>A 30-minute MAM Auditer assessment with our judges</li>
      </ul>
      <p style='color:#7A5C3D;font-size:13px;margin:16px 0 0'>Note: tuition is separate and only charged after we have placed you. The \$5 is final &mdash; payment terms apply per our published policy.</p>
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
    <div class='meta'>+234&nbsp;704&nbsp;444&nbsp;6517<br>2 Oremeji Street, Egbeda, Lagos<br>academy@mamcentre.org</div></div>
  <h1>" . htmlspecialchars($title) . "</h1>
  <p class='slogan'>&ldquo;Crafting the Timeless in Time.&rdquo;</p>
  <p class='intro'>Welcome to MAM Academy. The following policies ensure a seamless and rewarding learning experience. Please keep this signed copy for your records.</p>
  <div class='print-btn'><button onclick='window.print()'>Print / Save as PDF</button></div>
  <ol>" . $rows . "</ol>
  <p style='font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#333'>By enrolling you join us in crafting a timeless legacy of growth, excellence and achievement.</p>
  <div class='sign'><div class='lbl'>Agreed &amp; electronically signed</div>
    <div class='name'>" . $sig . "</div>
    <div style='font-size:12px;color:#666;margin-top:2px'>" . $date . " &middot; Application " . $ref . "</div></div>
  <div class='foot'>ACADEMIC DIRECTOR &middot; MAM Centre, 2 Oremeji Street, Egbeda, Lagos &middot; academy@mamcentre.org &middot; +234 704 444 6517</div>
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
        $rows .= "<tr><td style='padding:10px 0;border-bottom:1px solid #ECDFC4;vertical-align:top'>"
            . "<strong style='color:#B8721B'>{$i}. " . htmlspecialchars($p[0]) . "</strong><br>"
            . "<span style='color:#4A3624;font-size:14px'>" . htmlspecialchars($p[1]) . "</span></td></tr>";
        $i++;
    }
    $sig = htmlspecialchars($signedName, ENT_QUOTES);
    $ref = htmlspecialchars($reference, ENT_QUOTES);
    $date = date('F j, Y');

    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 6px;font-size:20px;color:#1A120B'>" . htmlspecialchars($title) . "</h2>
      <p style='color:#7A5C3D;font-size:13px;margin:0 0 18px'>Welcome to MAM Academy — \"Crafting the Timeless in Time.\" Please keep this policy for your records.</p>
      <table role='presentation' cellpadding='0' cellspacing='0' style='width:100%'>{$rows}</table>
      <div style='margin-top:22px;padding:16px 18px;background:#FBF4E8;border:1px dashed #B8721B;border-radius:10px'>
        <div style='color:#7A5C3D;font-size:12px;text-transform:uppercase;letter-spacing:.08em'>Agreed &amp; electronically signed</div>
        <div style='color:#fff;font-size:16px;margin-top:4px'><strong>{$sig}</strong></div>
        <div style='color:#7A5C3D;font-size:13px;margin-top:2px'>{$date} · Application {$ref}</div>
      </div>
      <p style='margin:24px 0 0;color:#7A5C3D;font-size:13px'>ACADEMIC DIRECTOR · MAM Centre</p>";
    return mam_email_layout([
        'header' => 'MAM ACADEMY',
        'preheader' => 'Your MAM Academy class policy agreement — please keep for your records.',
        'reason' => 'You received this because you agreed to the class policy when applying to MAM Academy.',
        'body' => $inner,
    ]);
}

/**
 * Summer programme application confirmation email.
 * Sent immediately after POST /api/summer/apply succeeds.
 */
function mam_email_summer_confirmation(array $app): string {
    $name     = htmlspecialchars(trim($app['first_name'] ?? '') . ' ' . trim($app['last_name'] ?? ''), ENT_QUOTES);
    $ref      = htmlspecialchars((string)($app['reference'] ?? ''), ENT_QUOTES);
    $prog     = htmlspecialchars(mam_setting('summer_prog_name', 'MAM Summer Music Camp'), ENT_QUOTES);
    $start    = (string)mam_setting('summer_session_start', '');
    $end      = (string)mam_setting('summer_session_end', '');
    $location = htmlspecialchars(mam_setting('summer_location', ''), ENT_QUOTES);
    $contact  = htmlspecialchars(mam_setting('summer_contact_email', ''), ENT_QUOTES);
    $fee      = (float)mam_setting_num('summer_fee', 0);
    $minAge   = (int)mam_setting_num('summer_min_age', 8);
    $maxAge   = (int)mam_setting_num('summer_max_age', 18);

    $dateRange = '';
    if ($start && $end) {
        try {
            $s = new DateTimeImmutable($start); $e = new DateTimeImmutable($end);
            $dateRange = $s->format('F j') . ' – ' . $e->format('F j, Y');
        } catch (Throwable $ignored) {}
    } elseif ($start) {
        try { $dateRange = (new DateTimeImmutable($start))->format('F j, Y'); } catch (Throwable $ignored) {}
    }

    $detailRows = '';
    if ($dateRange) $detailRows .= "<tr><td style='padding:6px 0;color:#7A5C3D;font-size:13px;width:130px'>Programme dates</td><td style='padding:6px 0;color:#1A120B;font-size:13px'><strong>$dateRange</strong></td></tr>";
    if ($location) $detailRows .= "<tr><td style='padding:6px 0;color:#7A5C3D;font-size:13px'>Location</td><td style='padding:6px 0;color:#1A120B;font-size:13px'><strong>$location</strong></td></tr>";
    if ($fee > 0)  $detailRows .= "<tr><td style='padding:6px 0;color:#7A5C3D;font-size:13px'>Programme fee</td><td style='padding:6px 0;color:#1A120B;font-size:13px'><strong>USD $" . number_format($fee, 2) . "</strong></td></tr>";
    if ($minAge)   $detailRows .= "<tr><td style='padding:6px 0;color:#7A5C3D;font-size:13px'>Age range</td><td style='padding:6px 0;color:#1A120B;font-size:13px'><strong>$minAge" . ($maxAge > 0 ? "–$maxAge" : '+') . " years</strong></td></tr>";
    $detailRows .= "<tr><td style='padding:6px 0;color:#7A5C3D;font-size:13px'>Reference</td><td style='padding:6px 0;color:#1A120B;font-size:13px'><strong>$ref</strong></td></tr>";

    $statusUrl = htmlspecialchars(rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/status', ENT_QUOTES);
    $contactBlock = $contact ? "<p style='margin:20px 0 0;color:#7A5C3D;font-size:13px'>Have a question? Reply to this email or reach us at <a href='mailto:$contact' style='color:#E08A1A'>$contact</a>.</p>" : '';

    $inner = "
      <h2 style='font-family:Georgia,serif;margin:0 0 4px;font-size:20px;color:#1A120B'>Application received, $name!</h2>
      <p style='color:#7A5C3D;font-size:14px;margin:0 0 22px;line-height:1.6'>
        Thank you for applying to the <strong>$prog</strong>. We've received your application
        and our team will be in touch soon to confirm your spot.
      </p>
      " . ($detailRows ? "<table role='presentation' cellpadding='0' cellspacing='0' border='0' style='width:100%;border-top:1px solid #F0E4D0;border-bottom:1px solid #F0E4D0;margin-bottom:22px;padding:8px 0'>$detailRows</table>" : '') . "
      <p style='color:#7A5C3D;font-size:13px;margin:0 0 8px'>What happens next:</p>
      <ol style='margin:0 0 22px;padding-left:18px;color:#1A120B;font-size:13px;line-height:1.8'>
        <li>Our team reviews your application (usually within 2–3 business days).</li>
        <li>You'll receive a confirmation email with your spot details.</li>
        <li>Further instructions (schedule, what to bring, etc.) follow closer to the start date.</li>
      </ol>
      <p style='margin:0 0 16px'>
        <a href='$statusUrl?ref=" . rawurlencode($ref) . "' style='display:inline-block;background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#1A120B;font-weight:800;font-size:14px;padding:12px 24px;border-radius:8px;text-decoration:none'>Check application status →</a>
      </p>
      $contactBlock
      <p style='margin:24px 0 0;color:#7A5C3D;font-size:13px'>MAM Academy — Music, Arts, Mystery</p>
    ";
    return mam_email_layout([
        'header'    => 'MAM SUMMER',
        'preheader' => "We've received your application for $prog. Your reference is $ref.",
        'reason'    => 'You received this because you applied to the MAM Summer Music Camp.',
        'body'      => $inner,
    ]);
}

/**
 * The recital programme, as an email.
 *
 * A LINK IS NOT A PROGRAMME. The obvious version of this email — "the
 * programme for X is published, click here" — makes a parent open a browser,
 * find their child in a list, and work out when to arrive. The programme
 * belongs IN the message: the full running order with clock times, and the
 * recipient's own line pulled out and made unmissable at the top.
 *
 * Table-based and inline-styled throughout, like every other template here:
 * Outlook has no flexbox, and a running order that collapses into one column
 * in a desktop client is worse than no email.
 *
 * @param array  $recital  the recital row
 * @param array  $items    running order from mam_recital_programme_full()['items']
 * @param array  $o        highlight_item_id:int, highlight_lede:string,
 *                         performer_name:string, url:string
 */
function mam_email_recital_programme(array $recital, array $items, array $o = []): string {
    $esc   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $url   = (string) ($o['url'] ?? '');
    $mineId = (int) ($o['highlight_item_id'] ?? 0);

    $when = !empty($recital['starts_at']) ? date('l j F Y', strtotime((string) $recital['starts_at'])) : 'Date to be confirmed';
    $time = !empty($recital['starts_at']) ? date('H:i', strtotime((string) $recital['starts_at'])) : '';
    $doors = !empty($recital['doors_open_at']) ? date('H:i', strtotime((string) $recital['doors_open_at'])) : '';

    // ── The masthead: what it is, when, and where.
    $inner = "<p style='margin:0 0 6px;font-size:10.5px;letter-spacing:0.22em;text-transform:uppercase;color:#B8721B;font-weight:700'>The programme</p>"
      . "<h1 style='margin:0 0 4px;font-family:Georgia,\"Times New Roman\",serif;font-size:26px;line-height:1.2;color:#1B2430'>" . $esc($recital['title']) . "</h1>";
    if (!empty($recital['subtitle'])) {
        $inner .= "<p style='margin:0 0 12px;font-family:Georgia,serif;font-style:italic;font-size:15px;color:#69727f'>" . $esc($recital['subtitle']) . "</p>";
    }
    $facts = array_values(array_filter([$when, $time ? "Begins $time" : '', $doors ? "Doors $doors" : '', (string) ($recital['venue'] ?? '')]));
    // Escape each FACT, then join with the raw entity. Escaping the joined
    // string turns the separator into a literal "&middot;" on the page — which
    // is exactly what shipped until someone actually looked at the email.
    $inner .= "<p style='margin:0 0 20px;font-size:14px;color:#3a4250;line-height:1.7'><strong>"
      . implode(' &middot; ', array_map($esc, $facts)) . "</strong></p>";

    // ── The recipient's own slot, lifted out. This is the one fact they need,
    //    and burying it inside a list of twenty is how a family arrives late.
    if ($mineId > 0) {
        foreach ($items as $it) {
            if (empty($it['id']) || (int) $it['id'] !== $mineId) continue;
            $slot = $it['at'] ? $esc($it['at']) : 'a time to be confirmed';
            $lede = (string) ($o['highlight_lede'] ?? 'You perform');
            $inner .= "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='margin:0 0 22px;border:2px solid #B8892B;border-radius:10px;background:#FDF8EE'>"
              . "<tr><td style='padding:16px 18px'>"
              . "<p style='margin:0 0 3px;font-size:10.5px;letter-spacing:0.2em;text-transform:uppercase;color:#8C6417;font-weight:700'>" . $esc($lede) . "</p>"
              . "<p style='margin:0;font-family:Georgia,serif;font-size:30px;line-height:1.1;color:#8C6417'>" . $slot . "</p>"
              . "<p style='margin:6px 0 0;font-size:15px;color:#1B2430'><strong>" . $esc($it['title']) . "</strong>"
              . ($it['composer'] ? " <span style='color:#69727f'>&middot; " . $esc($it['composer']) . "</span>" : '')
              . "</p></td></tr></table>";
            break;
        }
    }

    // ── The running order itself.
    $inner .= "<p style='margin:0 0 10px;font-size:10.5px;letter-spacing:0.2em;text-transform:uppercase;color:#B8721B;font-weight:700'>Running order</p>"
      . "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='margin:0 0 18px'>";

    foreach ($items as $it) {
        if (!empty($it['interval'])) {
            $inner .= "<tr><td colspan='3' style='padding:12px 0;border-top:1px solid #E2E0DA;border-bottom:1px solid #E2E0DA;text-align:center'>"
              . "<span style='font-size:10.5px;letter-spacing:0.2em;text-transform:uppercase;color:#69727f;font-weight:700'>Interval</span>"
              . "<span style='display:block;font-size:12px;color:#959ca7'>" . (int) $it['duration_min'] . " minutes"
              . ($it['at'] ? ' &middot; ' . $esc($it['at']) : '') . "</span></td></tr>";
            continue;
        }
        $mine = ($mineId > 0 && !empty($it['id']) && (int) $it['id'] === $mineId);
        // The recipient's row is tinted rather than bolded alone: a bold row in
        // a list of bold titles does not stand out, but a tinted one does.
        $bg   = $mine ? "background:#FDF8EE;" : '';
        $performer = (string) ($it['performer_display'] ?? $it['student_name'] ?? '');
        $inner .= "<tr><td style='{$bg}padding:11px 8px 11px 0;border-bottom:1px solid #E2E0DA;vertical-align:top;width:26px'>"
          . "<span style='font-family:Georgia,serif;font-size:16px;color:#B8892B'>" . (int) $it['position'] . "</span></td>"
          . "<td style='{$bg}padding:11px 8px;border-bottom:1px solid #E2E0DA;vertical-align:top'>"
          . "<span style='font-family:Georgia,serif;font-size:17px;color:#1B2430'>" . $esc($it['title']) . "</span>"
          . ($it['composer'] ? "<span style='display:block;font-size:12.5px;color:#69727f'>" . $esc($it['composer']) . "</span>" : '')
          . "<span style='display:block;font-size:13px;color:#8C6417;font-weight:600;padding-top:3px'>" . $esc($performer)
          . ($it['instrument'] ? " <span style='color:#69727f;font-weight:400'>&mdash; " . $esc($it['instrument']) . "</span>" : '') . "</span></td>"
          . "<td style='{$bg}padding:11px 0 11px 8px;border-bottom:1px solid #E2E0DA;vertical-align:top;text-align:right;white-space:nowrap'>"
          . "<span style='font-size:13px;color:#959ca7'>" . ($it['at'] ? $esc($it['at']) : '') . "</span></td></tr>";
    }
    $inner .= "</table>";

    if (!empty($recital['programme_note'])) {
        $inner .= "<p style='margin:0 0 18px;font-family:Georgia,serif;font-size:14px;color:#69727f;line-height:1.7'>"
          . nl2br($esc($recital['programme_note'])) . "</p>";
    }
    if ($url !== '') {
        $inner .= "<p style='margin:18px 0 0;font-size:12.5px;color:#8a8578;line-height:1.6'>"
          . "Share this link with anyone who would like to come.</p>"
          . mam_email_button($url, 'View the programme online')
          // mam_email_button's table is align="left" (a float), so without this
          // the layout's footer rides up beside the button.
          . "<div style='clear:both;font-size:0;line-height:0'>&nbsp;</div>";
    }

    return mam_email_layout([
        'header'     => 'Recital',
        'preheader'  => trim($recital['title'] . ' — ' . implode(' · ', $facts)),
        'body'       => $inner,
        // Transactional, not marketing: nobody unsubscribes from being told
        // when their own child is on stage.
        'reason'     => 'You received this because you or your child are performing at MAM Academy.',
    ]);
}

/**
 * "Set up your account" — the invitation that replaces emailing a password.
 *
 * Deliberately different in tone from the reset mail: this is the first thing a
 * family receives from the academy, and it is welcoming rather than a security
 * notice. Two things it must NOT do — claim they requested it (they did not; the
 * academy enrolled them), and tell them to ignore it if unexpected (an
 * unexpected enrolment mail is something to query, not discard).
 */
function mam_email_account_invite(string $name, string $inviteUrl, string $role = 'student', int $days = 7): string {
    $n = htmlspecialchars($name ?: 'there', ENT_QUOTES);
    $u = htmlspecialchars($inviteUrl, ENT_QUOTES);
    $d = max(1, $days);
    $window = $d === 1 ? '24 hours' : ($d . ' days');
    $what = $role === 'parent'
        ? "You'll be able to follow your child's lessons, attendance, results and recitals."
        : ($role === 'instructor'
            ? "You'll be able to take the register, set work and record results."
            : "You'll be able to see your classes, check in to sessions, log practice and follow your results.");
    $inner = "<h2 class='h1' style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>Welcome to MAM Academy, $n</h2>
      <p style='color:#4A3624;font-size:15px;margin:0 0 14px'>Your place is confirmed. Choose your own password to open your portal — we never send passwords by email. $what</p>
      " . mam_email_button($inviteUrl, 'Create your password') . "
      <p style='font-size:13px;color:#7A5C3D;line-height:1.65;margin:0'>If the button doesn't work, copy and paste this link into your browser:<br><span style='word-break:break-all;color:#B8721B'>$u</span></p>
      <p style='font-size:13px;color:#9A8264;margin:24px 0 0'>The link works once and expires in <strong>$window</strong>. If it has already expired, use “Forgot password” on the sign-in page and we'll send a fresh one — or reply to this email and we'll help.</p>";
    return mam_email_layout([
        'header'    => 'MAM ACADEMY',
        'preheader' => "Create your MAM Academy password — link valid for $window.",
        'reason'    => 'You received this because MAM Academy enrolled you and created your account.',
        'body'      => $inner,
    ]);
}
