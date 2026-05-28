<?php
/**
 * MAM Academy — SMTP smoke test (CLI only)
 * ─────────────────────────────────────────────────────────────
 * Usage:
 *   php api/tools/mail-test.php recipient@example.com
 *   php api/tools/mail-test.php recipient@example.com --subject="Hello" --body="Test"
 *
 * Reads api/.env, prints the resolved mail configuration (password
 * redacted), then attempts a real send with a full per-step SMTP
 * transcript so misconfiguration is obvious in seconds.
 *
 * Exit codes: 0 success, 1 invalid usage, 2 send failed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This tool is CLI-only.\n";
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';

$argvCopy = $argv;
array_shift($argvCopy); // drop script name

$to = null;
$subject = 'MAM Academy SMTP test — ' . date('Y-m-d H:i:s');
$body = "If you can read this, MAM Academy's SMTP configuration is working.\n\nServer: "
      . ($_SERVER['SERVER_NAME'] ?? gethostname()) . "\nSent at: " . date('c') . "\n";

foreach ($argvCopy as $a) {
    if (strpos($a, '--subject=') === 0)      $subject = substr($a, 10);
    elseif (strpos($a, '--body=') === 0)     $body    = substr($a, 7);
    elseif ($to === null && $a !== '' && $a[0] !== '-') $to = $a;
}

if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php api/tools/mail-test.php recipient@example.com [--subject=...] [--body=...]\n");
    exit(1);
}

$redact = static function (?string $s): string {
    if ($s === null || $s === '') return '(empty)';
    if (strlen($s) <= 4) return str_repeat('*', strlen($s));
    return substr($s, 0, 2) . str_repeat('*', max(0, strlen($s) - 4)) . substr($s, -2);
};

echo "── MAM Academy mail tester ──────────────────────────────\n";
echo "Recipient   : $to\n";
echo "MAIL_HOST   : " . (mam_env('MAIL_HOST', '') ?: '(empty — will fall back to PHP mail())') . "\n";
echo "MAIL_PORT   : " . mam_env('MAIL_PORT', '587') . "\n";
echo "MAIL_USER   : " . (mam_env('MAIL_USERNAME', '') ?: '(empty)') . "\n";
echo "MAIL_PASS   : " . $redact(mam_env('MAIL_PASSWORD', '')) . "\n";
echo "ENCRYPTION  : " . mam_env('MAIL_ENCRYPTION', 'tls') . "\n";
echo "FROM        : " . mam_env('MAIL_FROM_NAME', 'MAM Academy') . " <" . mam_env('MAIL_FROM_EMAIL', 'no-reply@mamcentre.org') . ">\n";
echo "─────────────────────────────────────────────────────────\n";

if (mam_env('MAIL_HOST', '') === '') {
    echo "⚠  No MAIL_HOST set. Copy api/.env.example to api/.env and fill the\n";
    echo "   MAIL_* block, then re-run this command. PHP mail() will be tried\n";
    echo "   below but almost always fails on dev/shared hosts.\n\n";
}

$result = mam_send_mail_verbose($to, $subject, $body, false);

echo "Transport   : " . $result['transport'] . "\n";
if (!empty($result['host'])) echo "SMTP host   : " . $result['host'] . "\n";
echo "Result      : " . ($result['ok'] ? 'OK ✓ sent' : 'FAIL ✗') . "\n";
if (!$result['ok'] && !empty($result['error'])) {
    echo "Error       : " . $result['error'] . "\n";
}

if (!empty($result['steps'])) {
    echo "\nSMTP transcript:\n";
    foreach ($result['steps'] as $i => $s) {
        $mark = ($s['ok'] ?? true) ? '✓' : '✗';
        printf("  [%02d] %s %-14s  %s\n", $i + 1, $mark, $s['step'], substr((string)($s['reply'] ?? ''), 0, 160));
    }
}

echo "\nLog file    : " . MAM_LOGS . '/app-' . date('Y-m-d') . ".log\n";

exit($result['ok'] ? 0 : 2);
