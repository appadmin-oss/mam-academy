<?php
/**
 * MAM Academy — Paystack Payment Verification
 * -----------------------------------------------------------------
 * Server-side verification of a Paystack transaction reference.
 *
 * SHARED-HOST INSTALL
 *  1. Edit api/.env (create from .env.example) and set:
 *       PAYSTACK_SECRET_KEY=sk_live_xxxxxxxxxxxxxxxxxxxxxxxx
 *       NOTIFY_EMAIL=connect@mamcentre.org
 *  2. Make sure your hosting plan supports PHP 7.4+ and cURL.
 *  3. POST { reference: "MAM-…" } to /api/verify-payment.php
 *     and the endpoint returns { ok: true, data: { … } }.
 *
 * SECURITY
 *  - .env is denied by the project .htaccess.
 *  - Secret key never leaves the server.
 *  - CORS restricted to the configured site origin.
 * -----------------------------------------------------------------
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// --- Load .env (very small parser, PHP 7.4+ compatible) -------
function mam_load_env(string $path): array {
    $env = [];
    if (!is_readable($path)) return $env;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#') continue;
        if (strpos($t, '=') === false) continue;
        $parts = explode('=', $t, 2);
        $env[trim($parts[0])] = trim($parts[1], " \t\n\r\0\x0B\"'");
    }
    return $env;
}

$ENV = mam_load_env(__DIR__ . '/.env');
$SECRET = $ENV['PAYSTACK_SECRET_KEY'] ?? '';
$ORIGIN = $ENV['ALLOWED_ORIGIN'] ?? 'https://academy.mamcentre.org';
$NOTIFY = $ENV['NOTIFY_EMAIL']    ?? 'connect@mamcentre.org';

// --- CORS ------------------------------------------------------
$reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($reqOrigin === $ORIGIN) {
    header("Access-Control-Allow-Origin: $ORIGIN");
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

// --- Method gate ----------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// --- Config gate ----------------------------------------------
if ($SECRET === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'not_configured', 'detail' => 'Set PAYSTACK_SECRET_KEY in api/.env']);
    exit;
}

// --- Parse body ------------------------------------------------
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body) || empty($body['reference'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_reference']);
    exit;
}
$reference = preg_replace('/[^A-Za-z0-9\-_]/', '', (string)$body['reference']);

// --- Call Paystack --------------------------------------------
$url = 'https://api.paystack.co/transaction/verify/' . urlencode($reference);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $SECRET,
        'Cache-Control: no-cache',
    ],
    CURLOPT_TIMEOUT => 20,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'upstream_failure', 'detail' => $err]);
    exit;
}

$data = json_decode($resp, true);
$status = $data['data']['status'] ?? null;
$ok = ($code === 200 && $status === 'success');

// --- Notify (best-effort) -------------------------------------
if ($ok && $NOTIFY) {
    $amount = ($data['data']['amount'] ?? 0) / 100;
    $currency = $data['data']['currency'] ?? 'NGN';
    $email = $data['data']['customer']['email'] ?? '—';
    $subject = "MAM Payment Verified — $reference";
    $msg = "Paystack reference: $reference\nAmount: $currency $amount\nCustomer: $email\nStatus: $status\n";
    @mail($NOTIFY, $subject, $msg, "From: no-reply@" . ($_SERVER['SERVER_NAME'] ?? 'mamcentre.org'));
}

http_response_code($ok ? 200 : 402);
echo json_encode([
    'ok' => $ok,
    'reference' => $reference,
    'status' => $status,
    'data' => $ok ? [
        'amount' => ($data['data']['amount'] ?? 0) / 100,
        'currency' => $data['data']['currency'] ?? 'NGN',
        'email' => $data['data']['customer']['email'] ?? null,
        'paid_at' => $data['data']['paid_at'] ?? null,
        'channel' => $data['data']['channel'] ?? null,
    ] : null
]);
