<?php
/**
 * Auth-gated meeting link redirect.
 *
 * Routes (via .htaccess):
 *   /session/{id}/join  →  join.php?type=session&id={id}
 *   /class/{id}/join    →  join.php?type=class&id={id}
 *
 * Checks the visitor is signed in and is enrolled in (or teaches / admins)
 * the session/class, then 302-redirects to the stored meeting_link URL.
 * Unenrolled visitors and guests get a clear, friendly error page.
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
function mam_log(...$a) {}
function mam_audit(...$a) {}
function mam_client_ip() { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$id   = (int)($_GET['id'] ?? 0);

$appUrl   = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');
$selfUrl  = $appUrl . '/' . ($type === 'class' ? 'class' : 'session') . '/' . $id . '/join';
$loginUrl = $appUrl . '/portal/login.html?next=' . urlencode($selfUrl);

// Auth
$currentUser = null;
if (isset($_COOKIE['mam_session'])) {
    $sess = mam_session_get($_COOKIE['mam_session']);
    if ($sess) {
        $currentUser = mam_db_row('SELECT id, name, role FROM users WHERE id = ?', [(int)$sess['user_id']]);
    }
}
if (!$currentUser) {
    header('Location: ' . $loginUrl);
    exit;
}

$userId   = (int)$currentUser['id'];
$userRole = (string)$currentUser['role'];
$isStaff  = in_array($userRole, ['admin', 'instructor'], true);

$h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE);

// ── look up the record and check enrolment ────────────────────────────────
$meetingLink = null;
$sessionTitle = '';
$errorCode = '';

if ($type === 'session' && $id > 0) {
    $sess = mam_db_row(
        "SELECT cs.id, cs.title, cs.meeting_link, cs.cohort_id, co.instructor_id
           FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id
          WHERE cs.id = ?",
        [$id]
    );
    if (!$sess) {
        $errorCode = 'not_found';
    } else {
        $sessionTitle = (string)($sess['title'] ?: 'Session #' . $id);
        $meetingLink  = $sess['meeting_link'] ? (string)$sess['meeting_link'] : null;

        if (!$isStaff && (int)$sess['instructor_id'] !== $userId) {
            // Check course_enrollment for the cohort
            $enrolled = mam_db_row(
                "SELECT 1 FROM course_enrollments WHERE cohort_id = ? AND student_id = ? AND status IN ('active','completed')",
                [(int)$sess['cohort_id'], $userId]
            );
            if (!$enrolled) $errorCode = 'not_enrolled';
        }
    }
} elseif ($type === 'class' && $id > 0) {
    $cls = mam_db_row('SELECT id, title, meeting_link, instructor_id FROM classes WHERE id = ?', [$id]);
    if (!$cls) {
        $errorCode = 'not_found';
    } else {
        $sessionTitle = (string)($cls['title'] ?: 'Class #' . $id);
        $meetingLink  = $cls['meeting_link'] ? (string)$cls['meeting_link'] : null;

        if (!$isStaff && (int)$cls['instructor_id'] !== $userId) {
            $enrolled = mam_db_row(
                "SELECT 1 FROM enrollments WHERE class_id = ? AND student_id = ? AND status = 'active'",
                [$id, $userId]
            );
            if (!$enrolled) $errorCode = 'not_enrolled';
        }
    }
} else {
    $errorCode = 'not_found';
}

// ── redirect or show error ────────────────────────────────────────────────
if ($errorCode === '') {
    if ($meetingLink !== null) {
        // Validate the stored URL is a safe https:// link before redirecting.
        if (preg_match('#^https?://#i', $meetingLink)) {
            // Log the join event (best-effort).
            try {
                if (function_exists('mam_db_insert')) {
                    mam_db_insert('events', [
                        'type'       => 'session.join_redirect',
                        'entity'     => $type === 'class' ? 'class' : 'class_session',
                        'entity_id'  => $id,
                        'actor_id'   => $userId,
                        'meta'       => json_encode(['role' => $userRole]),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            } catch (Throwable $_) {}
            header('Location: ' . $meetingLink, true, 302);
            exit;
        }
        // meeting_link stored but not a valid http(s) URL — treat as not set.
    }
    // Meeting link not configured — show a clear info page.
    $errorCode = 'no_link';
}

// ── error page ─────────────────────────────────────────────────────────────
$portalPath = in_array($userRole, ['admin'], true) ? '/admin/' : '/portal/' . $userRole . '/';
$portalUrl  = $appUrl . $portalPath;

$messages = [
    'not_found'    => ['Session not found', 'This link does not match any session on the system.'],
    'not_enrolled' => ['Not enrolled', 'You need to be enrolled in ' . ($sessionTitle ?: 'this session') . ' to use this link.'],
    'no_link'      => ['No meeting link set', 'The instructor hasn\'t added an online meeting link for ' . ($sessionTitle ?: 'this session') . ' yet. Check your portal or contact your instructor.'],
];
[$errTitle, $errMsg] = $messages[$errorCode] ?? ['Error', 'Something went wrong.'];
http_response_code($errorCode === 'not_found' ? 404 : ($errorCode === 'not_enrolled' ? 403 : 200));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($errTitle) ?> — MAM Academy</title>
<meta name="robots" content="noindex,nofollow">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    background: #F3EFE8;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    color: #1A120B;
  }
  .box {
    background: #fff;
    border-radius: 14px;
    padding: 40px 32px;
    max-width: 420px;
    width: 100%;
    text-align: center;
    box-shadow: 0 2px 16px rgba(26,18,11,0.10);
  }
  .icon { font-size: 2.4rem; margin-bottom: 16px; }
  h2   { font-size: 20px; margin-bottom: 10px; }
  p    { font-size: 14px; color: #7A6A56; line-height: 1.6; }
  .btn {
    display: inline-block;
    margin-top: 24px;
    padding: 11px 26px;
    background: #1A120B;
    color: #E8961F;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
  }
  .btn:hover { opacity: 0.85; }
  .sub { margin-top: 14px; font-size: 12px; color: #B8A890; }
</style>
</head>
<body>
<div class="box">
  <div class="icon"><?= $errorCode === 'no_link' ? '🔗' : ($errorCode === 'not_enrolled' ? '🔒' : '🔍') ?></div>
  <h2><?= $h($errTitle) ?></h2>
  <p><?= $h($errMsg) ?></p>
  <a class="btn" href="<?= $h($portalUrl) ?>">Back to portal</a>
  <?php if ($sessionTitle !== ''): ?>
  <p class="sub">Session: <?= $h($sessionTitle) ?></p>
  <?php endif; ?>
</div>
</body>
</html>
