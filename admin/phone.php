<?php
/**
 * MAM Academy — the admin console on a phone (design frame `#5b`).
 *
 * ── WHY THIS IS A SEPARATE ENTRY AND NOT A BREAKPOINT ─────────────────────
 * `admin/console.php` is responsive and, now that its tables stack, usable at
 * 390px. This file is not about fitting. The frame's title is "a subset, not a
 * squeeze", and its argument is about consequence rather than layout:
 *
 *   "Amending a record, voiding an invoice and closing a period are
 *    deliberately unavailable here. A consequential, hard-to-reverse action
 *    taken one-handed on a bus is how a ledger acquires a mystery."
 *
 * A breakpoint cannot express that. Thirty-one screens hidden behind a media
 * query are still thirty-one screens, and the one an admin reaches for in a
 * corridor would be whichever they used last. Four panels chosen for what is
 * safe to do standing up is a different product decision, so it is a different
 * door — linked both ways, never a redirect. An admin who wants the whole
 * console on a phone can still have it.
 *
 * ── WHAT IT SHARES, AND WHAT IT DOES NOT ──────────────────────────────────
 * Shares: the design system (`admin-v2.css`, the `.ap-*` block), the render
 * helpers (`ac_*`), the read-with-a-failure-register contract, the auth, and —
 * this is the load-bearing one — the WRITE handlers. Every action form here
 * posts to `/admin/` exactly as the desktop console's do, so there is one
 * implementation of "waive" and this surface cannot drift from it.
 * `_console=phone:<panel>` tells that dispatcher to send the admin back here.
 *
 * Does not share: the rail, the section strip, the palette. Those are the
 * desktop's way of reaching thirty-one places, and this has four.
 */
declare(strict_types=1);

require_once __DIR__ . '/../api/lib/bootstrap.php';

/* ── Auth. Identical to console.php and admin/index.php: a session admin, else
      env Basic Auth (break-glass), else the portal login. ─────────────────── */
$adminUser = mam_current_user();
if (!($adminUser && mam_user_has_role($adminUser, 'admin'))) {
    if (mam_env('ADMIN_USER', '') !== '' && mam_env('ADMIN_PASSWORD_HASH', '') !== '') {
        mam_require_admin();
    } else {
        header('Location: /portal/login?next=' . rawurlencode('/admin/phone.php'));
        if (!defined('MAM_HTML_RESPONSE')) define('MAM_HTML_RESPONSE', true);
        exit;
    }
}

/* HTML, not JSON. bootstrap forces application/json and appends a no_response
   envelope at shutdown unless told otherwise (gotcha #5). */
header_remove('Content-Type');
header('Content-Type: text/html; charset=utf-8');
header('Referrer-Policy: same-origin');
if (!defined('MAM_HTML_RESPONSE')) define('MAM_HTML_RESPONSE', true);

if ($adminUser && (int) ($adminUser['must_change_password'] ?? 0) === 1) {
    header('Location: /admin/console.php?v=account'); exit;
}

require_once __DIR__ . '/../partials/admin-console/_ui.php';

$panel = strtolower(trim((string) ($_GET['p'] ?? 'decide')));
if (!mam_phone_panel_ok($panel)) $panel = 'decide';

/* `ac_form()` reads this global — sharing it is what lets the phone reuse the
   console's form helper, and therefore the same write handlers. */
$MAM_CONSOLE_CSRF = mam_csrf_token();
$q        = trim((string) ($_GET['q'] ?? ''));
$flashOk  = isset($_GET['ok'])  ? (is_string($_GET['ok'])  ? $_GET['ok']  : 'Saved.') : null;
$flashErr = isset($_GET['err']) ? (is_string($_GET['err']) ? $_GET['err'] : 'That did not work.') : null;

$AP_TITLES = [
    'decide' => 'Needs a decision',
    'today'  => 'Today',
    'assign' => 'Assign',
    'lookup' => 'Look up',
];
$AP_ICONS = [
    'decide' => 'fa-list-check', 'today' => 'fa-calendar-day',
    'assign' => 'fa-user-plus',  'lookup' => 'fa-magnifying-glass',
];
$AP_TABS = ['decide' => 'Decide', 'today' => 'Today', 'assign' => 'Assign', 'lookup' => 'Look up'];

/** A phone URL. */
function ap_url(string $panel, array $extra = []): string {
    return '?' . http_build_query(array_filter(array_merge(['p' => $panel], $extra),
        static fn($v) => $v !== null && $v !== ''));
}

$who = trim((string) ($adminUser['name'] ?? ($adminUser['email'] ?? 'Admin')));

/* The Decide badge is the count the tab bar dots. Read once, here, so the bar
   and the panel cannot disagree about how much is waiting. */
$AP_decide = mam_phone_decide();
$AP_open   = count(array_filter($AP_decide, static fn($i) => empty($i['desk'])));
?>
<!DOCTYPE html>
<html lang="en" data-admin="v2" data-phone="1">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= ac_h($AP_TITLES[$panel]) ?> · MAM Academy admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&amp;family=Cormorant+Garamond:wght@600;700&amp;family=Inter:wght@400;500;600;700;800&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="/assets/css/admin-v2.css?v=2026.11.20b">
</head>
<body class="ap-body">
<a class="ac-skip" href="#ap-main">Skip to the panel</a>

<header class="ap-top">
  <div class="ap-top-id">
    <div class="ap-top-name">Admin</div>
    <div class="ap-top-sub"><?= ac_h($who) ?></div>
  </div>
  <a class="ap-top-desk" href="/admin/console.php?v=<?= ac_h($panel === 'today' ? 'timetable' : 'dashboard') ?>">
    <i class="fa-solid fa-display" aria-hidden="true"></i>
    <span>Desk</span>
  </a>
</header>

<main class="ap-main" id="ap-main" tabindex="-1">
  <?php if ($flashOk !== null): ?>
    <div class="ap-flash is-good" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span><?= ac_h($flashOk) ?></span></div>
  <?php endif; ?>
  <?php if ($flashErr !== null): ?>
    <div class="ap-flash is-bad" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= ac_h($flashErr) ?></span></div>
  <?php endif; ?>

  <?php
    $file = __DIR__ . '/../partials/admin-phone/' . $panel . '.php';
    if (is_file($file)) {
        include $file;
    } else {
        echo '<h1 class="ap-h1">' . ac_h($AP_TITLES[$panel]) . '</h1>';
    }

    /* The same contract the desktop console holds: a read that could not be
       served is NAMED on the page, with the database's own words, rather than
       rendering as an empty panel that looks correct (gotcha #9). */
    $fails = mam_console_failures();
    if ($fails): ?>
      <section class="ap-card is-bad">
        <div class="ap-eyebrow is-bad">Some of this could not be read</div>
        <div class="ap-card-t"><?= count($fails) ?> failed read<?= count($fails) === 1 ? '' : 's' ?></div>
        <ul class="ap-fails">
          <?php foreach ($fails as $f): ?>
            <li><code><?= ac_h((string) ($f['where'] ?? '?')) ?></code> — <?= ac_h((string) ($f['error'] ?? '')) ?></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
</main>

<nav class="ap-tabs" aria-label="Admin panels">
  <?php foreach ($AP_TABS as $key => $label):
    $on = $key === $panel; ?>
    <?php
      /* The dot is a MARK, not a number. A count inside an 8px dot renders
         at a contrast the portal audit measured at 1.04:1, so the figure goes
         in the accessible name instead — the same rule `.p-badge` holds. */
      $badge = ($key === 'decide' && $AP_open > 0);
      $name  = $label . ($badge ? ', ' . $AP_open . ' waiting' : '');
    ?>
    <a class="ap-tab<?= $on ? ' is-on' : '' ?>" href="<?= ac_h(ap_url($key)) ?>"
       aria-label="<?= ac_h($name) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
      <span class="ap-tab-mark" aria-hidden="true"></span>
      <span class="ap-tab-ico">
        <i class="fa-solid <?= ac_h($AP_ICONS[$key]) ?>" aria-hidden="true"></i>
        <?php if ($badge): ?><span class="ap-tab-dot" aria-hidden="true"></span><?php endif; ?>
      </span>
      <span aria-hidden="true"><?= ac_h($label) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

</body>
</html>
