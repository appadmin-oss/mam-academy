<?php
/**
 * GET|POST /api/setup — first-run administrator installer.
 *
 * Makes login work on a fresh deploy with no shell access. It is safe because
 * it SELF-DISABLES the moment any user exists, and (optionally) requires a
 * SETUP_TOKEN from the environment to be passed as ?token= / form field.
 *
 *   • No users yet  → shows a form (or accepts a POST) to create the first admin.
 *   • A user exists → shows "already set up", links to the login page. Never
 *     creates a second account here.
 *
 * After setup, sign in at /portal/login.html. Admins land on /admin/.
 */
declare(strict_types=1);

header_remove('Content-Type'); // serve HTML, not the bootstrap JSON default

$appUrl    = rtrim((string)mam_env('APP_URL', ''), '/');
$loginUrl  = ($appUrl !== '' ? $appUrl : '') . '/portal/login.html';
$setupTok  = (string)mam_env('SETUP_TOKEN', '');
$provided  = (string)($_REQUEST['token'] ?? '');
$method    = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$alreadyUp = mam_user_count() > 0;

// In production the installer must be explicitly unlocked with a SETUP_TOKEN.
// Without this, an attacker could POST /api/setup during the window between
// deploy and the operator's first login and seize the first admin account
// (which then permanently disables the env-seeded admin). Outside production
// (local/dev) the first-run guard alone is enough for a frictionless setup.
$requireToken = strtolower((string)mam_env('APP_ENV', 'production')) === 'production';

// A configured token must match (constant-time).
$tokenOk = (!$requireToken && $setupTok === '')
        || ($setupTok !== '' && hash_equals($setupTok, $provided));
$tokenQS = $setupTok !== '' ? ('?token=' . rawurlencode($provided)) : '';

// Normally the installer self-disables once a user exists. But when a valid
// SETUP_TOKEN is supplied it doubles as a no-shell RECOVERY tool: it can
// (re)set the administrator even if accounts already exist — gated entirely by
// the secret token. mam_create_admin() is idempotent by email, so this resets
// an existing admin's password or creates one.
$canManage = $tokenOk && (!$alreadyUp || $setupTok !== '');
$isReset   = $alreadyUp && $canManage;

$error = null; $created = false;

if ($method === 'POST' && $canManage && $tokenOk) {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password_confirm'] ?? '');
    $name  = trim((string)($_POST['name'] ?? 'Administrator')) ?: 'Administrator';
    if ($pass !== $pass2) {
        $error = 'Passwords do not match.';
    } else {
        try {
            mam_create_admin($email, $pass, $name);
            $created = true;
            // Loud audit trail — this endpoint can (re)set an admin via the
            // SETUP_TOKEN recovery path, so every use must be traceable.
            mam_audit('setup_admin', 'user', null, ['email' => strtolower($email), 'reset' => $isReset, 'ip' => mam_client_ip()]);
            mam_log('warn', 'setup_admin_used', ['email' => strtolower($email), 'reset' => $isReset]);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage() === 'weak_password'
                ? 'Password must be at least 8 characters.'
                : 'Please enter a valid email address.';
        } catch (Throwable $e) {
            mam_log('error', 'setup_failed', ['err' => $e->getMessage()]);
            $error = 'Could not save the account. Check the server logs.';
        }
    }
}

$esc = static fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES);

// ---- Decide which screen to show -------------------------------------------
$state = 'form';
if ($created)               $state = 'success';
elseif (!$tokenOk)          $state = 'locked'; // wrong token, or prod without SETUP_TOKEN
elseif (!$canManage)        $state = 'done';

// ---- Shared shell ----------------------------------------------------------
$body = '';
if ($state === 'success') {
    $body = '<div class="ic ok"><i class="fa-solid fa-circle-check"></i></div>'
        . '<h1>' . ($isReset ? 'Account updated' : 'You\'re all set') . '</h1>'
        . '<p>Your administrator account is ready. Sign in to reach your dashboard.</p>'
        . '<a class="btn" href="' . $esc($loginUrl) . '">Go to sign in <i class="fa-solid fa-arrow-right"></i></a>';
} elseif ($state === 'done') {
    $body = '<div class="ic"><i class="fa-solid fa-lock"></i></div>'
        . '<h1>Already set up</h1>'
        . '<p>An account already exists, so the installer is closed. Locked out? Set a <code>SETUP_TOKEN</code> in your server environment, then reopen this page as <code>/api/setup?token=YOUR_TOKEN</code> to reset the administrator — or run <code>php api/bin/create-admin.php</code> from a shell.</p>'
        . '<a class="btn" href="' . $esc($loginUrl) . '">Go to sign in <i class="fa-solid fa-arrow-right"></i></a>';
} elseif ($state === 'locked') {
    $body = '<div class="ic"><i class="fa-solid fa-key"></i></div>'
        . '<h1>Setup token required</h1>'
        . '<p>Append <code>?token=YOUR_SETUP_TOKEN</code> to this URL. The token is the <code>SETUP_TOKEN</code> value in your server environment.</p>';
} else {
    $errHtml = $error ? '<div class="err"><i class="fa-solid fa-circle-exclamation"></i> ' . $esc($error) . '</div>' : '';
    $body = '<div class="ic"><i class="fa-solid fa-graduation-cap"></i></div>'
        . '<h1>' . ($isReset ? 'Reset administrator' : 'Create your administrator') . '</h1>'
        . '<p>' . ($isReset
            ? 'Enter the admin email to (re)set its password. This recovers access without touching anything else.'
            : 'One quick step to activate sign-in for your academy.') . '</p>'
        . $errHtml
        . '<form method="POST" action="/api/setup' . $esc($tokenQS) . '" autocomplete="on">'
        . '<label>Full name<input type="text" name="name" value="' . $esc($_POST['name'] ?? '') . '" placeholder="Administrator" autocomplete="name"></label>'
        . '<label>Email<input type="email" name="email" value="' . $esc($_POST['email'] ?? '') . '" required placeholder="you@academy.org" autocomplete="username"></label>'
        . '<label>Password<input type="password" name="password" required minlength="8" placeholder="At least 8 characters" autocomplete="new-password"></label>'
        . '<label>Confirm password<input type="password" name="password_confirm" required minlength="8" autocomplete="new-password"></label>'
        . '<button class="btn" type="submit">' . ($isReset ? 'Reset password &amp; sign in' : 'Create account &amp; activate login') . '</button>'
        . '</form>';
}

$year = date('Y');
$html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
  . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
  . '<meta name="robots" content="noindex,nofollow">'
  . '<title>Setup — MAM Academy</title>'
  . '<link rel="icon" type="image/svg+xml" href="/mam-academy-logo.svg">'
  . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">'
  . '<style>'
  . ':root{--gold:#E08A1A;--gold-bright:#FBBF24;--bg:#120D08;--surface:#1B130C;--line:rgba(224,138,26,.28);--txt:#F5EEE3;--muted:#B9A88F}'
  . '*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Arial,sans-serif;color:var(--txt);background:radial-gradient(ellipse at top,rgba(224,138,26,.16),transparent 60%),var(--bg)}'
  . '.card{width:100%;max-width:440px;background:linear-gradient(180deg,rgba(224,138,26,.07),var(--surface));border:1px solid var(--line);border-radius:20px;padding:36px 32px;box-shadow:0 30px 80px rgba(0,0,0,.5)}'
  . '.ic{width:60px;height:60px;border-radius:16px;display:grid;place-items:center;font-size:1.6rem;color:#120D08;background:linear-gradient(135deg,var(--gold),var(--gold-bright));margin-bottom:18px}'
  . '.ic.ok{background:linear-gradient(135deg,#34D399,#10B981)}'
  . 'h1{font-size:1.5rem;margin:0 0 8px;letter-spacing:-.01em}p{color:var(--muted);margin:0 0 22px;line-height:1.6;font-size:.95rem}'
  . 'code{background:rgba(255,255,255,.06);padding:2px 6px;border-radius:5px;font-size:.85em;color:var(--gold-bright)}'
  . 'label{display:block;font-size:.82rem;color:var(--muted);margin-bottom:14px;font-weight:600}'
  . 'input{display:block;width:100%;margin-top:6px;padding:13px 14px;border-radius:11px;border:1px solid var(--line);background:rgba(0,0,0,.25);color:var(--txt);font-size:1rem}'
  . 'input:focus{outline:none;border-color:var(--gold-bright);box-shadow:0 0 0 3px rgba(251,191,36,.18)}'
  . '.btn{display:inline-flex;align-items:center;gap:8px;justify-content:center;width:100%;margin-top:8px;padding:14px 20px;border:0;border-radius:11px;font-weight:800;font-size:1rem;cursor:pointer;text-decoration:none;color:#120D08;background:linear-gradient(135deg,var(--gold),var(--gold-bright))}'
  . '.btn:hover{filter:brightness(1.05)}'
  . '.err{background:rgba(244,63,94,.12);border:1px solid rgba(244,63,94,.4);color:#FDA4AF;padding:11px 14px;border-radius:10px;margin-bottom:18px;font-size:.9rem}'
  . '.foot{margin-top:22px;text-align:center;color:var(--muted);font-size:.78rem}'
  . '</style></head><body><main class="card">'
  . $body
  . '<div class="foot">MAM Academy · &copy; ' . $year . '</div>'
  . '</main></body></html>';

mam_raw_response($html, $created ? 201 : 200);
