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
require_once MAM_API_ROOT . '/lib/log.php';
require_once MAM_API_ROOT . '/lib/db.php';
require_once MAM_API_ROOT . '/lib/settings.php';
require_once MAM_API_ROOT . '/lib/validation.php';
require_once MAM_API_ROOT . '/lib/session.php';
require_once MAM_API_ROOT . '/lib/auth.php';
require_once MAM_API_ROOT . '/lib/mailer.php';
require_once MAM_API_ROOT . '/lib/automations.php';
require_once MAM_API_ROOT . '/lib/cards.php';

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
// so stubbing mam_log/mam_audit (lib/log.php) or mam_automation_touch
// (lib/automations.php) here made every request a fatal "Cannot redeclare"
// error. Those libs are required above and supply the real implementations.
// mam_env / mam_client_ip / mam_after_response live in bootstrap.php, which this
// page deliberately doesn't load, so they DO still need defining.
function mam_client_ip() { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function mam_after_response(callable $fn) { register_shutdown_function($fn); }

$serial = trim((string)($_GET['serial'] ?? ''));

// Auth check via session cookie. Defer to lib/session.php rather than reading the
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
$issuedYY    = $cardFound ? substr((string)$card['issued_at'], 2, 2) : '';
$expDisplay  = $cardFound ? 'EXP.12/' . $issuedYY : '';
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
  }

  /* ─── Portrait ID card ───────────────────────────────────── */
  .id-card {
    width: 100%;
    aspect-ratio: 3 / 4;
    border-radius: 18px;
    box-shadow: 0 8px 40px rgba(26,18,11,0.22), 0 2px 8px rgba(26,18,11,0.10);
    position: relative;
    overflow: hidden;
    background: #F07B1A; /* orange fills the card; white mask covers the top */
    flex-shrink: 0;
  }

  /* White upper mask with V-notch to frame the photo */
  .card-white-mask {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: #FDFCF9;
    /* Notch bottom: (50%, 57%) — photo center sits at ~47% */
    clip-path: polygon(
      0%   0%,
      100% 0%,
      100% 59%,
      63%  53%,
      55%  55%,
      52%  57%,
      50%  58%,
      48%  57%,
      45%  55%,
      37%  53%,
      0%   57%
    );
    z-index: 1;
  }

  /* Scattered music icon watermarks in white area */
  .card-decor {
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 55%;
    z-index: 2;
    pointer-events: none;
    overflow: hidden;
  }
  .card-decor span {
    position: absolute;
    color: #B8A890;
    opacity: 0.18;
    font-style: normal;
    line-height: 1;
    user-select: none;
  }
  .card-decor .d1  { font-size: 28px; top:  6%; left:  5%; transform: rotate(-15deg); }
  .card-decor .d2  { font-size: 20px; top: 12%; right: 8%; transform: rotate(20deg); }
  .card-decor .d3  { font-size: 22px; top: 30%; left: 7%; transform: rotate(-8deg); }
  .card-decor .d4  { font-size: 16px; top: 22%; right:10%; transform: rotate(10deg); }
  .card-decor .d5  { font-size: 18px; top: 42%; left:14%; transform: rotate(5deg); }
  .card-decor .d6  { font-size: 14px; top: 50%; right:15%; transform: rotate(-12deg); }
  .card-decor .d7  { font-size: 24px; top:  4%; right:30%; transform: rotate(3deg); opacity: 0.10; }

  /* Logo at top center */
  .card-logo {
    position: absolute;
    top: 5%;
    left: 50%;
    transform: translateX(-50%);
    z-index: 3;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    text-align: center;
  }
  .card-logo img {
    width: 48px;
    height: 48px;
    object-fit: contain;
    border-radius: 6px;
  }
  .card-logo-text-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    line-height: 1;
  }
  .card-logo-name {
    font-family: 'Barlow Condensed', 'Arial Narrow', Impact, sans-serif;
    font-weight: 800;
    font-style: normal;
    font-size: 15px;
    letter-spacing: 3px;
    color: #1A120B;
    text-transform: uppercase;
  }
  .card-logo-sub {
    font-size: 7px;
    letter-spacing: 2px;
    color: #7A6A56;
    text-transform: uppercase;
    margin-top: 2px;
  }

  /* Fallback logo box (when no logo URL is configured) */
  .card-logo-box {
    width: 44px;
    height: 44px;
    background: #1A120B;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .card-logo-box-text {
    font-family: 'Barlow Condensed', Impact, sans-serif;
    font-weight: 900;
    font-size: 16px;
    color: #F07B1A;
    letter-spacing: 1px;
  }

  /* Student photo — circular, overlaps the white/orange boundary */
  .card-photo {
    position: absolute;
    /* Center at ~47% from top; photo height ≈ 28% of width = 21% of card height → top ≈ 47% - 10.5% = 36.5% */
    top: 37%;
    left: 50%;
    transform: translateX(-50%);
    width: 28%;
    aspect-ratio: 1;
    border-radius: 50%;
    border: 3.5px solid #FDFCF9;
    overflow: hidden;
    background: #D9CEBD;
    z-index: 5;
    box-shadow: 0 2px 12px rgba(26,18,11,0.20);
  }
  .card-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  /* Initials placeholder when no photo */
  .card-photo-initials {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #C9A87C;
    font-family: 'Barlow Condensed', Impact, sans-serif;
    font-weight: 800;
    font-style: italic;
    font-size: 26px;
    color: #FDFCF9;
    text-transform: uppercase;
    letter-spacing: 1px;
  }

  /* Orange section content */
  .card-lower {
    position: absolute;
    top: 52%;
    left: 0; right: 0; bottom: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 14% 10% 5%;
    z-index: 4;
    text-align: center;
  }

  /* Student name — two lines */
  .card-name-first {
    font-family: 'Barlow Condensed', 'Arial Narrow', Impact, sans-serif;
    font-weight: 700;
    font-style: italic;
    font-size: 22px;
    color: #FDFCF9;
    text-transform: uppercase;
    letter-spacing: 1px;
    line-height: 1.1;
    width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .card-name-surname {
    font-family: 'Barlow Condensed', 'Arial Narrow', Impact, sans-serif;
    font-weight: 900;
    font-style: italic;
    font-size: 38px;
    color: #FDFCF9;
    text-transform: uppercase;
    letter-spacing: 0px;
    line-height: 0.95;
    width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .card-role-label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 4px;
    color: rgba(253,252,249,0.85);
    text-transform: uppercase;
    margin-top: 8px;
  }

  /* QR code in white rounded box */
  .card-qr-box {
    background: #FDFCF9;
    border-radius: 10px;
    padding: 7px;
    margin-top: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28%;
    aspect-ratio: 1;
    flex-shrink: 0;
  }
  .card-qr-box canvas,
  .card-qr-box img { width: 100% !important; height: 100% !important; }
  .card-qr-box #qrcode { width: 100%; height: 100%; }

  /* Display ID below QR */
  .card-display-id {
    font-size: 9px;
    font-weight: 600;
    letter-spacing: 2px;
    color: rgba(253,252,249,0.75);
    text-transform: uppercase;
    margin-top: 7px;
  }

  /* EXP date — vertical on right edge of orange section */
  .card-exp {
    position: absolute;
    right: 5px;
    top: 62%;
    bottom: 8%;
    display: flex;
    align-items: center;
    z-index: 6;
  }
  .card-exp span {
    writing-mode: vertical-lr;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 2px;
    color: rgba(253,252,249,0.70);
    text-transform: uppercase;
  }

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

  /* Print: suppress controls, keep card colour-accurate */
  @media print {
    body { background: #fff; padding: 0; }
    .card-wrap { max-width: 260px; }
    .id-card { box-shadow: none; }
    .card-actions, .card-hint, .card-revoked-banner { display: none; }
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
  <div class="id-card">

    <!-- White upper mask (V-notch bottom) -->
    <div class="card-white-mask"></div>

    <!-- Music icon watermarks in white zone -->
    <div class="card-decor" aria-hidden="true">
      <span class="d1">♩</span>
      <span class="d2">♪</span>
      <span class="d3">♫</span>
      <span class="d4">♬</span>
      <span class="d5">♩</span>
      <span class="d6">♪</span>
      <span class="d7">♫</span>
    </div>

    <!-- Logo -->
    <div class="card-logo">
      <?php if ($safeLogoUrl !== ''): ?>
        <img src="<?= $safeLogoUrl ?>" alt="MAM Academy">
      <?php else: ?>
        <div class="card-logo-box"><span class="card-logo-box-text">MAM</span></div>
      <?php endif; ?>
      <div class="card-logo-text-wrap">
        <span class="card-logo-name">MAM Academy</span>
        <span class="card-logo-sub">Mystery &middot; Art &middot; Music</span>
      </div>
    </div>

    <!-- Circular student photo -->
    <div class="card-photo">
      <?php if ($safePhotoUrl !== ''): ?>
        <img src="<?= $safePhotoUrl ?>" alt="<?= $h($holderName) ?>">
      <?php else: ?>
        <div class="card-photo-initials"><?= $h(mb_substr($nameSurname, 0, 2)) ?></div>
      <?php endif; ?>
    </div>

    <!-- Orange zone content -->
    <div class="card-lower">
      <?php if ($nameFirst !== ''): ?>
      <div class="card-name-first"><?= $h(strtoupper($nameFirst)) ?></div>
      <?php endif; ?>
      <div class="card-name-surname"><?= $h(strtoupper($nameSurname)) ?></div>
      <div class="card-role-label"><?= $h($role) ?></div>

      <div class="card-qr-box">
        <div id="qrcode"></div>
      </div>

      <div class="card-display-id"><?= $h($displayId) ?></div>
    </div>

    <!-- EXP date — vertical right edge -->
    <?php if ($expDisplay !== ''): ?>
    <div class="card-exp" aria-label="Expiry <?= $h($expDisplay) ?>">
      <span><?= $h($expDisplay) ?></span>
    </div>
    <?php endif; ?>

  </div><!-- .id-card -->

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
<script>
(function () {
  var payload = <?= json_encode($qrPayload) ?>;
  var el = document.getElementById('qrcode');
  if (typeof QRCode !== 'undefined' && el) {
    // Fit the QR to the white box; el.offsetWidth reads after layout
    var size = el.parentElement ? el.parentElement.offsetWidth - 14 : 70;
    size = Math.max(48, Math.min(size, 120));
    new QRCode(el, {
      text:         payload,
      width:        size,
      height:       size,
      colorDark:    '#1A120B',
      colorLight:   '#FDFCF9',
      correctLevel: QRCode.CorrectLevel.M,
    });
  } else if (el) {
    el.textContent = payload;
    el.style.cssText = 'font-size:5px;word-break:break-all;color:#9C845A';
  }
})();
</script>
<?php endif; ?>

</body>
</html>
