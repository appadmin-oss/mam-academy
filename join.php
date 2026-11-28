<?php
/**
 * Auth-gated meeting link redirect.
 *
 * Routes (via .htaccess):
 *   /session/{id}/join  →  join.php?type=session&id={id}
 *   /class/{id}/join    →  join.php?type=class&id={id}   (a bookmarked OLD class link)
 *
 * Checks the visitor is signed in and is enrolled in (or teaches / admins)
 * the session's cohort, then 302-redirects to the stored meeting_link URL.
 *
 * The old class model is retired (its classes were carried into cohorts,
 * `cohorts.legacy_class_id`), but a family's bookmarked /class/{id}/join must
 * still work: the class id resolves to the cohort it became, and the link
 * joins that cohort's current or next session that has a meeting link.
 * Unenrolled visitors and guests get a clear, friendly error page.
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
// Only what this page's requires DON'T already provide. PHP declares top-level
// functions at compile time — before the require_once calls above run — so
// stubbing mam_log/mam_audit (modules/kernel/lib/log.php, required above) made every request an
// unconditional "Cannot redeclare" fatal. mam_client_ip lives in bootstrap.php,
// which this page deliberately doesn't load, so it DOES still need defining.
function mam_client_ip() { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$id   = (int)($_GET['id'] ?? 0);

$appUrl   = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');
$selfUrl  = $appUrl . '/' . ($type === 'class' ? 'class' : 'session') . '/' . $id . '/join';
$loginUrl = $appUrl . '/portal/login.html?next=' . urlencode($selfUrl);

// Auth — defer to modules/kernel/lib/session.php rather than reading the cookie by hand: it uses
// the real cookie name (MAM_COOKIE = 'mam_sid', NOT 'mam_session'), validates the
// token format, hashes it, and enforces expiry + users.status='active'. The
// previous inline version called a mam_session_get() that does not exist and read
// the wrong cookie name, so it could never have resolved a session.
$currentUser = mam_session_user();
if (!$currentUser) {
    header('Location: ' . $loginUrl);
    exit;
}

$userId   = (int)$currentUser['id'];
// Admins may open any class. An instructor is NOT staff for this purpose: being
// an instructor somewhere must not open every other teacher's class of children.
// They pass only for a class they teach — see $teaches below. (Was: any
// instructor bypassed the enrolment check and could walk session ids.)
$userRole = (string)$currentUser['role'];
$isAdmin  = mam_user_has_role($currentUser, 'admin');
/** Does the signed-in instructor teach this cohort (lead, substitute for this session, or named on an enrolment)? */
$teaches = function (int $cohortId, int $leadId, int $substituteId = 0) use ($currentUser, $userId): bool {
    if (!mam_user_has_role($currentUser, 'instructor')) return false;
    if ($leadId === $userId || ($substituteId > 0 && $substituteId === $userId)) return true;
    return (bool) mam_db_row(
        "SELECT 1 FROM course_enrollments WHERE cohort_id = ? AND instructor_id = ? AND status IN ('active','completed') LIMIT 1",
        [$cohortId, $userId]
    );
};

$h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE);

// ── look up the record and check enrolment ────────────────────────────────
$meetingLink = null;
$sessionTitle = '';
$errorCode = '';

if ($type === 'session' && $id > 0) {
    $sess = mam_db_row(
        "SELECT cs.id, cs.title, cs.meeting_link, cs.cohort_id, cs.substitute_id, co.instructor_id
           FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id
          WHERE cs.id = ?",
        [$id]
    );
    if (!$sess) {
        $errorCode = 'not_found';
    } else {
        $sessionTitle = (string)($sess['title'] ?: 'Session #' . $id);
        $meetingLink  = $sess['meeting_link'] ? (string)$sess['meeting_link'] : null;

        if (!$isAdmin && !$teaches((int)$sess['cohort_id'], (int)$sess['instructor_id'], (int)($sess['substitute_id'] ?? 0))) {
            // Check course_enrollment for the cohort
            $enrolled = mam_db_row(
                "SELECT 1 FROM course_enrollments WHERE cohort_id = ? AND student_id = ? AND status IN ('active','completed')",
                [(int)$sess['cohort_id'], $userId]
            );
            if (!$enrolled) $errorCode = 'not_enrolled';
        }
    }
} elseif ($type === 'class' && $id > 0) {
    // An old class link: the class is now the cohort it was carried into.
    $co = mam_db_row('SELECT id, title, instructor_id FROM cohorts WHERE legacy_class_id = ? ORDER BY id LIMIT 1', [$id]);
    if (!$co) {
        $errorCode = 'not_found';
    } else {
        $sessionTitle = (string)($co['title'] ?: 'Class #' . $id);
        // The session in progress (started up to three hours ago) or the next one.
        $sess = mam_db_row(
            "SELECT id, title, meeting_link FROM class_sessions
              WHERE cohort_id = ? AND status = 'scheduled' AND meeting_link IS NOT NULL AND meeting_link <> ''
                AND scheduled_at >= ?
              ORDER BY scheduled_at ASC, id ASC LIMIT 1",
            [(int)$co['id'], gmdate('Y-m-d H:i:s', time() - 3 * 3600)]
        );
        $meetingLink = $sess ? (string)$sess['meeting_link'] : null;

        if (!$isAdmin && !$teaches((int)$co['id'], (int)$co['instructor_id'])) {
            $enrolled = mam_db_row(
                "SELECT 1 FROM course_enrollments WHERE cohort_id = ? AND student_id = ? AND status IN ('active','completed')",
                [(int)$co['id'], $userId]
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
                        'entity'     => $type === 'class' ? 'cohort' : 'class_session',
                        'entity_id'  => $type === 'class' ? (int)$co['id'] : $id,
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
    border-radius:12px;
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
