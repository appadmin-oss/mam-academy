<?php
/**
 * POST /api/admin/mail-test
 * Body: {"to":"someone@example.com"}
 * Sends a one-off test message via the configured mailer and returns
 * the full SMTP transcript so misconfiguration is diagnosable from the
 * admin dashboard without SSH access.
 *
 * Admin-only (mw_admin) + CSRF.
 */
declare(strict_types=1);

$in = mam_json_input();
$to = strtolower(trim((string)($in['to'] ?? '')));

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    mam_fail('invalid_recipient', 422, ['fields' => ['to']]);
}

// Low-level reachability probe first: a refused/blocked/filtered SMTP port is
// the most common shared-host failure and is invisible in higher-level errors.
$probe = ['attempted' => false, 'ok' => null, 'error' => null, 'target' => null];
$pHost = (string)mam_env('MAIL_HOST', '');
if ($pHost !== '') {
    $pPort = (int)mam_env('MAIL_PORT', '587');
    $pEnc  = strtolower((string)mam_env('MAIL_ENCRYPTION', 'tls'));
    $probe['attempted'] = true;
    $probe['target'] = $pHost . ':' . $pPort;
    $errno = 0; $errstr = '';
    $s = @stream_socket_client(($pEnc === 'ssl' ? 'ssl://' : '') . $pHost . ':' . $pPort, $errno, $errstr, 6);
    if ($s) { $probe['ok'] = true; fclose($s); }
    else    { $probe['ok'] = false; $probe['error'] = trim($errno . ' ' . $errstr) ?: 'connection failed'; }
}

$subject = '[MAM Academy] SMTP test — ' . date('Y-m-d H:i:s');
$body    = "Test message from the MAM Academy admin mail-test endpoint.\n\nSent at: " . date('c') . "\n";

$result = mam_send_mail_verbose($to, $subject, $body, false);

// Redact transcript replies if they look like auth challenges or echoes.
$safeSteps = array_map(static function ($s) {
    if (in_array($s['step'] ?? '', ['auth_user', 'auth_pass'], true)) {
        $s['reply'] = '(redacted)';
    }
    return $s;
}, $result['steps'] ?? []);

mam_audit('test', 'mail', null, ['to' => $to, 'ok' => $result['ok']]);

mam_ok([
    'sent'      => $result['ok'],
    'transport' => $result['transport'],
    'host'      => $result['host'] ?? null,
    'error'     => $result['error'] ?? null,
    'probe'     => $probe,
    'readiness' => mam_mail_readiness(),
    'steps'     => $safeSteps,
]);
