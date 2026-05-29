<?php
/**
 * Unsubscribe endpoint — compliance backbone for marketing email.
 *
 *  GET  /api/newsletter/unsubscribe?e=<email>&t=<token>   → human click; shows
 *       a friendly confirmation page and removes the address.
 *  POST (same URL)                                        → RFC 8058 one-click
 *       (List-Unsubscribe-Post); the mail client calls this automatically.
 *
 * The token is an HMAC over the app key, so a link can't be forged to
 * unsubscribe arbitrary addresses, and no login is required (CAN-SPAM/GDPR/
 * NDPA all require frictionless opt-out). Honoured immediately.
 */
declare(strict_types=1);

$email = strtolower(trim((string)($_GET['e'] ?? '')));
$token = (string)($_GET['t'] ?? '');
$valid = $email !== '' && hash_equals(mam_unsubscribe_token($email), $token);

if ($valid) {
    try {
        mam_db()->prepare('UPDATE newsletter SET status = ? WHERE email = ?')->execute(['unsubscribed', $email]);
        if (function_exists('mam_audit')) mam_audit('unsubscribe', 'newsletter', null, ['email' => $email]);
        mam_log('info', 'unsubscribed', ['email' => $email]);
    } catch (Throwable $e) {
        mam_log('warn', 'unsubscribe_failed', ['err' => $e->getMessage()]);
    }
}

// RFC 8058 one-click: mail clients POST here. Respond 200 JSON, no UI needed.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    mam_ok(['unsubscribed' => $valid]);
}

// Human click: render a small, self-contained confirmation page.
header('Content-Type: text/html; charset=utf-8');
$home = htmlspecialchars(rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/'), ENT_QUOTES);
$title = $valid ? 'Unsubscribed' : 'Link not recognised';
$body  = $valid
    ? "You've been removed from MAM Academy marketing emails. You won't receive further promotional messages. Account and transactional emails (like password resets) are unaffected."
    : "We couldn't verify that unsubscribe link. Please email <a href='mailto:connect@mamcentre.org' style='color:#FBBF24'>connect@mamcentre.org</a> and we'll remove you right away.";
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
   . '<title>' . $title . ' — MAM Academy</title>'
   . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;'
   . 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;background:#120D08;color:#FBF7F3}'
   . '.card{max-width:460px;text-align:center;background:#1A120B;border:1px solid #251A10;border-radius:18px;padding:40px 30px}'
   . 'h1{font-size:20px;margin:0 0 12px;font-family:Georgia,serif}p{color:rgba(255,255,255,0.8);line-height:1.65;font-size:15px;margin:0}'
   . 'a.home{display:inline-block;margin-top:22px;color:#FBBF24;text-decoration:none;font-weight:700}</style></head>'
   . '<body><div class="card"><h1>' . $title . '</h1><p>' . $body . '</p>'
   . '<a class="home" href="' . $home . '/">Return to MAM Academy →</a></div></body></html>';
exit;
