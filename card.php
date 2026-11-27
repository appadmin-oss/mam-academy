<?php
/**
 * Printable ID card page.
 * Route: /card/{serial}  →  card.php?serial={serial}  (via .htaccess rewrite)
 *
 * Auth-gated: the viewer must be signed in AND own the card (or be admin/instructor).
 * Students see their own card; instructors and admins can see any card.
 *
 * The QR code is rendered client-side by the vendored qrcode.min.js so
 * no server-side QR library is needed. The QR encodes the signed payload
 * (MAMC1.{serial}.{sig}) so the instructor scans this code to mark attendance.
 */
declare(strict_types=1);
define('MAM_API_ROOT', __DIR__ . '/api');
define('MAM_STORAGE',  MAM_API_ROOT . '/storage');
define('MAM_LOGS',     MAM_API_ROOT . '/logs');
foreach ([MAM_LOGS, MAM_STORAGE] as $d) { if (!is_dir($d)) @mkdir($d, 0775, true); }
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
require_once MAM_API_ROOT . '/modules/kernel/lib/log.php';
require_once MAM_API_ROOT . '/modules/kernel/lib/db.php';
require_once MAM_API_ROOT . '/modules/kernel/lib/settings.php';
require_once MAM_API_ROOT . '/modules/kernel/lib/validation.php';
require_once MAM_API_ROOT . '/modules/kernel/lib/session.php';
require_once MAM_API_ROOT . '/modules/identity/lib/auth.php';
require_once MAM_API_ROOT . '/modules/integrations/lib/mailer.php';
require_once MAM_API_ROOT . '/modules/comms/lib/automations.php';
require_once MAM_API_ROOT . '/modules/identity/lib/cards.php';

// Load .env the same way bootstrap does.
function mam_env(string $key, $default = null) {
    static $env = null;
    if ($env === null) {
        $env = [];
        $path = MAM_API_ROOT . '/.env';
        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $t = trim($line);
                if ($t === '' || $t[0] === '#' || strpos($t, '=') === false) continue;
                $parts = explode('=', $t, 2);
                $env[trim($parts[0])] = trim($parts[1], " \t\n\r\0\x0B\"'");
            }
        }
    }
    if (array_key_exists($key, $env) && $env[$key] !== '') return $env[$key];
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    return $default;
}

// Only the helpers this page's requires DON'T already provide. PHP declares
// top-level functions at compile time — before the require_once calls above run —
// so stubbing mam_log/mam_audit (modules/kernel/lib/log.php) or mam_automation_touch
// (modules/comms/lib/automations.php) here made every request a fatal "Cannot redeclare"
// error. Those libs are required above and supply the real implementations.
// mam_env / mam_client_ip / mam_after_response live in bootstrap.php, which this
// page deliberately doesn't load, so they DO still need defining.
function mam_client_ip() { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function mam_after_response(callable $fn) { register_shutdown_function($fn); }

$serial = trim((string)($_GET['serial'] ?? ''));

// Auth check via session cookie. Defer to modules/kernel/lib/session.php rather than reading the
// cookie by hand: it uses the real cookie name (MAM_COOKIE = 'mam_sid', NOT
// 'mam_session'), validates the token format, hashes it, and enforces both expiry
// and users.status='active'. The previous inline version called a
// mam_session_get() that does not exist — a fatal on every request — and read the
// wrong cookie name, so it could never have resolved a session either way.
$currentUser = mam_session_user();

$appUrl    = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');
$loginUrl  = $appUrl . '/portal/login.html?next=' . urlencode('/card/' . $serial);

if (!$currentUser) {
    header('Location: ' . $loginUrl);
    exit;
}

$userId   = (int)$currentUser['id'];
$userRole = (string)$currentUser['role'];
$isAdmin  = ($userRole === 'admin');
$isInstructor = ($userRole === 'instructor');

// Look up card.
$card = $serial !== '' ? mam_db_row('SELECT * FROM id_cards WHERE serial = ?', [$serial]) : null;

if (!$card) {
    http_response_code(404);
    $msg = 'Card not found.';
    $cardFound = false;
} else {
    $cardFound = true;
    // Permission check.
    $ownCard = ((int)$card['user_id'] === $userId);
    if (!$ownCard && !$isAdmin && !$isInstructor) {
        http_response_code(403);
        $cardFound = false;
        $msg = 'You do not have permission to view this card.';
    }
}

$holderName  = $cardFound ? mam_card_holder_name($card) : '';
$qrPayload   = $cardFound ? mam_card_qr_payload($card) : '';
$role        = $cardFound ? strtoupper((string)$card['role']) : '';
$sessionCode = $cardFound ? (string)$card['session_code'] : '';
$displayId   = $cardFound ? mam_card_display_id($card) : '';
// Real stamped expiry (EXP.mm/yy from expires_at), NOT a hard-coded "12/<issue
// year>" — that legacy formula drifted from the admin proof and from actual
// expiry enforcement (mam_card_is_expired), printing a wrong date on the
// student's own card. mam_card_exp_display reads the true expires_at.
$expDisplay  = $cardFound ? mam_card_exp_display($card) : '';
$photoUrl    = ($cardFound && !empty($card['photo_url'])) ? (string)$card['photo_url'] : '';

// Logo from email branding, or empty (CSS fallback used).
$logoUrl = '';
if (function_exists('mam_setting')) {
    $raw = mam_setting('email_logo_url', '');
    if ($raw !== '') {
        $s = strtolower(substr($raw, 0, 8));
        if ($s === 'https://' || substr($s, 0, 7) === 'http://') {
            $logoUrl = $raw;
        }
    }
}

// Split name for two-line display: first-names line (smaller) + surname (large).
$nameParts   = $cardFound ? explode(' ', trim($holderName)) : [];
$nameSurname = count($nameParts) > 1 ? array_pop($nameParts) : ($nameParts[0] ?? '');
$nameFirst   = count($nameParts) > 0 ? implode(' ', $nameParts) : '';

// Safe photo URL (same-host or data URI only for the img tag).
$safePhotoUrl = '';
if ($photoUrl !== '') {
    $ps = strtolower(substr($photoUrl, 0, 8));
    if (str_starts_with($ps, 'https://') || str_starts_with($ps, 'http://') || str_starts_with($photoUrl, '/')) {
        $safePhotoUrl = htmlspecialchars($photoUrl, ENT_QUOTES | ENT_SUBSTITUTE);
    }
}

$h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE);
$safeLogoUrl = $logoUrl !== '' ? htmlspecialchars($logoUrl, ENT_QUOTES | ENT_SUBSTITUTE) : '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($holderName ?: 'ID Card') ?> — MAM Academy</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@1,700;1,800;1,900&family=Inter:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/id-card.css">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Inter', 'Helvetica Neue', Arial, sans-serif;
    background: #F3EFE8;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    padding: 32px 16px 48px;
    color: #1A120B;
  }

  .card-wrap {
    width: 100%;
    max-width: 340px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 22px;            /* front and back stacked, both printable */
  }

  /* ── The card face itself lives in assets/css/id-card.css ───────────────
     Shared verbatim with the admin Card Studio (/card-studio) so a proofed
     sheet is exactly what the student sees. Only page chrome is styled here. */

  /* Revoked banner */
  .card-revoked-banner {
    background: #FEF2F2;
    color: #B91C1C;
    text-align: center;
    font-size: 12px;
    font-weight: 600;
    padding: 8px 12px;
    border-radius: 8px;
    margin-top: 14px;
    width: 100%;
  }

  /* Controls below the card */
  .card-actions {
    margin-top: 20px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
    width: 100%;
  }

  .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 22px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: opacity 0.15s;
    font-family: 'Inter', sans-serif;
  }
  .btn:hover { opacity: 0.82; }
  .btn-primary { background: #1A120B; color: #F07B1A; }
  .btn-outline  { background: transparent; border: 1.5px solid #9C845A; color: #1A120B; }

  .card-hint {
    margin-top: 12px;
    font-size: 11px;
    color: #9C845A;
    text-align: center;
    line-height: 1.55;
    max-width: 300px;
  }

  /* Error page */
  .error-box {
    background: #fff;
    border-radius: 12px;
    padding: 40px 24px;
    text-align: center;
    max-width: 400px;
    box-shadow: 0 2px 12px rgba(26,18,11,0.1);
  }
  .error-box h2 { font-size: 18px; margin-bottom: 8px; color: #1A120B; }
  .error-box p  { font-size: 14px; color: #7A6A56; }
  .error-box a  { color: #C17B2A; text-decoration: none; font-weight: 600; }

  /* Print: suppress controls, keep card colour-accurate. The card is pinned to a
     real-world width (60mm — the print trim) rather than a pixel guess, so a
     home printer produces a card that fits a lanyard holder. */
  @media print {
    /* A tidy sheet margin; the card carries its own physical size below. */
    @page { size: auto; margin: 8mm; }
    /* Drop the screen's full-viewport centering. min-height:100vh + flex
       centering pushed the card down the sheet and spilled an empty second
       page; in print the card should sit at the top of the page. */
    html, body { background: #fff; padding: 0; min-height: 0; display: block; }
    .card-wrap { max-width: none; width: auto; display: block; }
    /* Pin BOTH faces to the 60mm print trim (9 fit an A4 sheet). The width is
       set INLINE on each card as min(340px,86vw) for the screen, and an inline
       custom property beats a normal stylesheet rule — so without !important
       the card prints at ~90mm and will not fit a lanyard holder. */
    .mam-card {
      --mam-card-w: 60mm !important;
      box-shadow: none !important;
      break-inside: avoid;
      margin: 0 0 6mm;
    }
    .card-actions, .card-hint, .card-revoked-banner { display: none !important; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>

<?php if (!$cardFound): ?>
<div class="error-box">
  <h2>Card not found</h2>
  <p><?= $h($msg ?? 'This card could not be found.') ?></p>
  <p style="margin-top:16px"><a href="<?= $h($appUrl) ?>/portal/student/">Back to portal</a></p>
</div>

<?php else: ?>
<div class="card-wrap">
  <?php
    // One renderer for both the student card and the admin studio — see
    // partials/id-card-face.php. Passing the already-resolved holder name and
    // payload keeps the partial free of DB access.
    require_once __DIR__ . '/partials/id-card-face.php';
    echo mam_card_face_html([
        'holder_name'  => $holderName,
        'role'         => $card['role'] ?? 'student',
        'display_id'   => $displayId,
        'exp_display'  => $expDisplay,
        'qr_payload'   => $qrPayload,
        'photo_url'    => $photoUrl,
        'status'       => $card['status'] ?? '',
        'serial'       => $card['serial'] ?? '',
        'session_code' => $sessionCode,
    ], [
        'qr_id'  => 'qrcode',
        'logo'   => $logoUrl,
        'raised' => true,
        // min() keeps --mam-card-w an absolute length, which the face's calc()
        // type scale needs, while still shrinking on a narrow phone.
        'width'  => 'min(340px, 86vw)',
    ]);

    // The BACK carries the "if found" details, so a student who loses the card is
    // reachable — it belongs on their own page, not only on the admin print sheet.
    echo mam_card_back_html([
        'serial' => $card['serial'] ?? '',
    ], [
        'raised' => true,
        'width'  => 'min(340px, 86vw)',
        'tel'    => (string)mam_setting_or_default('card_found_tel'),
        'email'  => (string)mam_setting_or_default('card_found_email'),
        'url'    => (string)mam_setting_or_default('card_found_url'),
    ]);
  ?>

  <?php if ((string)$card['status'] === 'revoked'): ?>
  <div class="card-revoked-banner">This card has been revoked and is no longer valid.</div>
  <?php endif; ?>

  <div class="card-actions">
    <button class="btn btn-primary" onclick="window.print()">Print Card</button>
    <a class="btn btn-outline" href="<?= $h($appUrl) ?>/portal/student/">My Portal</a>
  </div>

  <p class="card-hint">Show this QR code to your instructor at each session.<br>
  Instructors scan it to mark your attendance automatically.</p>
</div>

<script src="/assets/js/vendor/qrcode.min.js"></script>
<script src="/assets/js/id-card-qr.js"></script>
<?php endif; ?>

</body>
</html>
