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

function mam_log(...$a) {} // not needed for card page
function mam_audit(...$a) {}
function mam_client_ip() { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function mam_after_response(callable $fn) { register_shutdown_function($fn); }
function mam_automation_touch(string $key): void {}

$serial = trim((string)($_GET['serial'] ?? ''));

// Auth check via session cookie.
$sessionRow = null;
$currentUser = null;
if (isset($_COOKIE['mam_session'])) {
    $sessionRow = mam_session_get($_COOKIE['mam_session']);
    if ($sessionRow) {
        $currentUser = mam_db_row('SELECT id, name, role, avatar_url FROM users WHERE id = ?', [(int)$sessionRow['user_id']]);
    }
}

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

$holderName = $cardFound ? mam_card_holder_name($card) : '';
$qrPayload  = $cardFound ? mam_card_qr_payload($card) : '';
$role       = $cardFound ? ucfirst((string)$card['role']) : '';
$sessionCode = $cardFound ? (string)$card['session_code'] : '';
$issuedYear  = $cardFound ? substr((string)$card['issued_at'], 0, 4) : '';

$h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($holderName ?: 'ID Card') ?> — MAM Academy</title>
<meta name="robots" content="noindex,nofollow">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    background: #F3EFE8;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    padding: 32px 16px;
    color: #1A120B;
  }

  .card-wrap {
    width: 100%;
    max-width: 520px;
  }

  /* CR80 card: 85.6 × 53.98 mm ≈ 3.37 × 2.13 in */
  .id-card {
    width: 100%;
    aspect-ratio: 85.6 / 53.98;
    background: #FDFCF9;
    border-radius: 10px;
    box-shadow: 0 4px 24px rgba(26,18,11,0.18);
    display: flex;
    overflow: hidden;
    position: relative;
  }

  .card-rail {
    width: 10px;
    background: linear-gradient(180deg, #E8961F 0%, #C17B2A 100%);
    flex-shrink: 0;
  }

  .card-body {
    flex: 1;
    display: flex;
    flex-direction: column;
  }

  .card-header {
    background: #1A120B;
    padding: 8px 12px 7px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .card-school {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 2px;
    color: #E8961F;
    text-transform: uppercase;
  }

  .card-role-badge {
    font-size: 8px;
    font-weight: 600;
    letter-spacing: 1.5px;
    color: #9C845A;
    text-transform: uppercase;
  }

  .card-main {
    flex: 1;
    padding: 10px 12px 8px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
  }

  .card-info { flex: 1; }

  .card-name {
    font-size: 15px;
    font-weight: 700;
    color: #1A120B;
    line-height: 1.25;
    margin-bottom: 4px;
    word-break: break-word;
  }

  .card-serial {
    font-family: 'Courier New', monospace;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 2px;
    color: #1A120B;
    margin-bottom: 2px;
  }

  .card-serial-label {
    font-size: 7px;
    font-weight: 600;
    letter-spacing: 1.5px;
    color: #9C845A;
    text-transform: uppercase;
    margin-bottom: 6px;
  }

  .card-session {
    font-size: 8px;
    color: #7A6A56;
    font-weight: 500;
  }

  .card-qr {
    width: 68px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .card-qr canvas,
  .card-qr img { width: 68px !important; height: 68px !important; }

  .card-footer {
    padding: 5px 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 1px solid #F0EBE3;
  }

  .card-footer-text {
    font-size: 7px;
    color: #B8A890;
    letter-spacing: 0.5px;
  }

  .card-issued {
    font-size: 7px;
    color: #B8A890;
  }

  .card-revoked-banner {
    background: #FEF2F2;
    color: #B91C1C;
    text-align: center;
    font-size: 12px;
    font-weight: 600;
    padding: 8px;
    border-radius: 6px;
    margin-top: 12px;
  }

  /* Controls below the card */
  .card-actions {
    margin-top: 20px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
  }

  .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: opacity 0.15s;
  }
  .btn:hover { opacity: 0.85; }
  .btn-primary { background: #1A120B; color: #E8961F; }
  .btn-outline { background: transparent; border: 1.5px solid #9C845A; color: #1A120B; }

  .card-hint {
    margin-top: 12px;
    font-size: 12px;
    color: #9C845A;
    text-align: center;
    line-height: 1.5;
  }

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

  @media print {
    body { background: #fff; padding: 0; }
    .card-wrap { max-width: 100%; }
    .id-card { box-shadow: none; border: 1px solid #ddd; }
    .card-actions, .card-hint { display: none; }
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
    <div class="card-rail"></div>
    <div class="card-body">
      <div class="card-header">
        <span class="card-school">MAM Academy</span>
        <span class="card-role-badge"><?= $h($role) ?></span>
      </div>
      <div class="card-main">
        <div class="card-info">
          <div class="card-name"><?= $h($holderName) ?></div>
          <div class="card-serial"><?= $h((string)$card['serial']) ?></div>
          <div class="card-serial-label">ID Serial</div>
          <?php if ($sessionCode !== ''): ?>
          <div class="card-session"><?= $h($sessionCode) ?></div>
          <?php endif; ?>
        </div>
        <div class="card-qr">
          <div id="qrcode"></div>
        </div>
      </div>
      <div class="card-footer">
        <span class="card-footer-text">academy.mamcentre.org</span>
        <span class="card-issued"><?= $h($issuedYear) ?></span>
      </div>
    </div>
  </div>

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
    new QRCode(el, {
      text: payload,
      width: 68,
      height: 68,
      colorDark: '#1A120B',
      colorLight: '#FDFCF9',
      correctLevel: QRCode.CorrectLevel.M,
    });
  } else {
    // Fallback: plain text for screen readers / no-JS
    el.textContent = payload;
    el.style.cssText = 'font-size:6px;word-break:break-all;max-width:68px;color:#9C845A';
  }
})();
</script>
<?php endif; ?>

</body>
</html>
