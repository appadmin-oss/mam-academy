<?php
/**
 * MAM Academy — admin console (the 2026-08 redesign)
 *
 * Thirty-one destinations in four groups, built from the canonical design frame
 * `Admin_Console.dc.html` (#5a desktop, #5b phone). Server-rendered, one screen
 * per request, no build step — the same idiom as the rest of the repo.
 *
 * ── What this file is, and is not ───────────────────────────────────────────
 * It is a READ surface plus the forms that drive the existing write handlers.
 * Every action form posts to `/admin/` (admin/index.php), which owns the
 * capability table, the audit call and the transition rules; `_console` on the
 * form tells that dispatcher to redirect back here instead of to the legacy
 * view its handler names. So there is exactly one implementation of "approve",
 * "waive", "enrol" — and this console cannot drift from it.
 *
 * Reads live in `api/lib/console.php`, one provider per screen, all never-throw
 * with a failure register. A screen that cannot read its data says so on the
 * page rather than rendering an empty table that looks fine (the failure mode
 * admin/index.php shipped for months — CLAUDE.md gotcha #9).
 *
 * The legacy admin is NOT retired by this file. It still serves 37 views and
 * every POST. Both are reachable; the rail links back.
 */
declare(strict_types=1);

require_once __DIR__ . '/../api/lib/bootstrap.php';

/* ── Auth. Identical to admin/index.php: a session admin passes through, else
      env Basic Auth (break-glass), else the portal login. ─────────────────── */
$adminUser = mam_current_user();
if (!($adminUser && mam_user_has_role($adminUser, 'admin'))) {
    if (mam_env('ADMIN_USER', '') !== '' && mam_env('ADMIN_PASSWORD_HASH', '') !== '') {
        mam_require_admin();
    } else {
        header('Location: /portal/login?next=' . rawurlencode('/admin/console.php'));
        if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
        exit;
    }
}

/* ── This is HTML, not JSON. bootstrap forces application/json and appends a
      no_response envelope at shutdown unless told otherwise (gotcha #5). ─── */
header_remove('Content-Type');
header('Content-Type: text/html; charset=utf-8');
header('Referrer-Policy: same-origin');
if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);

/* An admin on a temporary password sets a new one before using the console. */
if ($adminUser && (int) ($adminUser['must_change_password'] ?? 0) === 1) {
    header('Location: /admin/?v=account'); exit;
}

require_once __DIR__ . '/../partials/admin-console/_ui.php';

$screens = mam_console_screens();
$screen  = (string) ($_GET['v'] ?? 'dashboard');
if (!in_array($screen, $screens, true)) $screen = 'dashboard';

$MAM_CONSOLE_CSRF = mam_csrf_token();
$nav    = mam_console_nav();
$activeArea = mam_console_area_of($screen);   // decision 3: the rail follows the AREA
$badges = mam_console_badges();
$term   = mam_console_term();

/* The rail hides a destination this admin's tier cannot act on. The capability
   table in admin/index.php is still the enforcement — this only stops us
   advertising a dead end. A break-glass admin (no user row) is the super. */
$canSee = function (?string $cap) use ($adminUser): bool {
    if ($cap === null) return true;
    if (!$adminUser) return true;                       // break-glass
    return (bool) mam_admin_can($adminUser, $cap);
};

/* Row density and rail state are the reader's preference, so they live in a
   cookie rather than a setting — one registrar's choice must not change
   another's console. */
$density = (($_COOKIE['mam_ac_density'] ?? '') === 'compact') ? 'compact' : 'comfortable';
$railState = (($_COOKIE['mam_ac_rail'] ?? '') === 'collapsed') ? 'collapsed' : 'open';

/* Flash. The legacy dispatcher redirects with ?ok=/?err= and the string value
   carries the message, which is the convention every other screen uses. */
$flashOk  = isset($_GET['ok'])  ? (is_string($_GET['ok'])  ? $_GET['ok']  : 'Saved.') : null;
$flashErr = isset($_GET['err']) ? (is_string($_GET['err']) ? $_GET['err'] : 'That did not work.') : null;

/* ── The screen's own data. One provider, called once. ──────────────────── */
$providers = [
    'dashboard'      => fn() => mam_console_dashboard(),
    'admissions'     => fn() => mam_console_admissions($_GET),
    'auditions'      => fn() => mam_console_auditions(),
    'bursaries'      => fn() => mam_console_bursaries(),
    'funnel'         => fn() => mam_console_funnel(),
    'students'       => fn() => mam_console_students($_GET),
    'cohorts'        => fn() => mam_console_cohorts(),
    'roster'         => fn() => mam_console_roster($_GET),
    'timetable'      => fn() => mam_console_timetable($_GET),
    'curriculum'     => fn() => mam_console_curriculum($_GET),
    'assessment'     => fn() => mam_console_assessment(),
    'attendance'     => fn() => mam_console_attendance($_GET),
    'guardians'      => fn() => mam_console_guardians(),
    'calendar'       => fn() => mam_console_calendar(),
    'events'         => fn() => mam_console_events($_GET),
    'files'          => fn() => mam_console_files(),
    'invoices'       => fn() => mam_console_invoices(),
    'ledger'         => fn() => mam_console_ledger(),
    'pricebooks'     => fn() => mam_console_pricebooks(),
    'charges'        => fn() => mam_console_charges(),
    'store'          => fn() => mam_console_store(),
    'deductions'     => fn() => mam_console_deductions(),
    'access'         => fn() => mam_console_access(),
    'governance'     => fn() => mam_console_governance(),
    'reports'        => fn() => mam_console_reports(),
    'comms'          => fn() => mam_console_comms(),
    'vetting'        => fn() => mam_console_vetting(),
    'dataprotection' => fn() => mam_console_dataprotection(),
    'support'        => fn() => mam_console_support(),
    'system'         => fn() => mam_console_system(),
    'settings'       => fn() => mam_console_settings(),
];
$d = isset($providers[$screen]) ? ($providers[$screen])() : [];

/* Every screen's label, for the topbar title and the palette. */
$labels = [];      // screen key → section label
$areaOf = [];      // screen key → area label, for the palette
foreach ($nav as $g) {
    foreach ($g['sections'] as $sec) {
        $labels[$sec['key']] = $sec['label'];
        $areaOf[$sec['key']] = $g['label'];
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-admin="v2" data-density="<?= ac_h($density) ?>" data-rail="<?= ac_h($railState) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= ac_h($labels[$screen] ?? 'Console') ?> · MAM Academy admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&amp;family=Cormorant+Garamond:wght@600;700&amp;family=Inter:wght@400;500;600;700;800&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="/assets/css/admin-v2.css?v=2026.08.20">
</head>
<body>
<a class="ac-skip" href="#ac-main">Skip to the screen</a>

<div class="ac-shell">

  <nav class="ac-rail sc" id="ac-rail" aria-label="Console sections">
    <div class="ac-railstick" id="ac-railstick">
      <div class="ac-railhead">
        <img src="/mam-academy-logo.svg" alt="">
        <div class="ac-rt">
          <div class="ac-railname">MAM ACADEMY</div>
          <div class="ac-railsub">Admin console</div>
        </div>
        <button type="button" class="ac-railtoggle" id="ac-railtoggle"
                title="Collapse the sidebar" aria-label="Collapse the sidebar" aria-pressed="<?= $railState === 'collapsed' ? 'true' : 'false' ?>">
          <i class="fa-solid fa-angles-<?= $railState === 'collapsed' ? 'right' : 'left' ?>"></i>
        </button>
      </div>
      <a class="ac-centre" href="<?= ac_h(ac_url('calendar')) ?>">
        <i class="fa-solid fa-building-columns"></i>
        <span><?= ac_h($term['line']) ?></span>
      </a>
    </div>

    <?php /* NINE areas, never the thirty-one destinations. The rail is
             re-scanned on every navigation, so its length is paid repeatedly —
             see mam_console_nav()'s note and the design's §IA. Breadth lives in
             the section strip below the page title. */ ?>
    <div class="ac-navlist">
      <?php foreach ($nav as $area):
        $home = mam_console_area_home($area, $canSee);
        if ($home === null) continue;                 // no visible section → no dead end
        $on = ($activeArea !== null && $activeArea['key'] === $area['key']);
        // The rail badge rolls the area's sections up: an admin scanning nine
        // buttons needs to know the area holds a decision, then finds which in
        // the strip.
        $n = 0;
        foreach ($area['sections'] as $sec) { if ($canSee($sec['cap'] ?? null)) $n += (int) ($badges[$sec['key']] ?? 0); }
        $count = count(array_filter($area['sections'], fn($sec) => $canSee($sec['cap'] ?? null)));
        /* A collapsed rail is icon-only, so the accessible name is the ONLY
           label — and a bare "2" tells a screen reader nothing. Say what the
           number means, and how much is behind the area. */
        $aria = $area['label']
              . ($n > 0 ? ', ' . $n . ' needing a decision' : '')
              . ($count > 1 ? ', ' . $count . ' sections' : ''); ?>
        <a class="ac-nav" href="<?= ac_h(ac_url($home)) ?>" data-area="<?= ac_h($area['key']) ?>"
           <?= $on ? 'aria-current="page"' : '' ?>
           title="<?= ac_h($area['label']) ?>" aria-label="<?= ac_h($aria) ?>">
          <span class="ac-navbar"></span>
          <i class="fa-solid <?= ac_h($area['icon']) ?>"></i>
          <span class="ac-navlabel"><?= ac_h($area['label']) ?></span>
          <?php if ($n > 0): ?><span class="ac-navcount"><?= $n ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <div class="ac-navgroup">Legacy</div>
      <a class="ac-nav" href="/admin/" title="The 37-view admin">
        <span class="ac-navbar"></span><i class="fa-solid fa-table-columns"></i>
        <span class="ac-navlabel">Old dashboard</span>
      </a>
    </div>

    <div class="ac-railfoot">
      <?= ac_avatar($adminUser['name'] ?? 'Break glass', null) ?>
      <div class="ac-rt">
        <div class="ac-railwho"><?= ac_h($adminUser['name'] ?? 'break-glass') ?></div>
        <div class="ac-railrole"><?= ac_h($adminUser ? ucfirst((string) ($adminUser['admin_tier'] ?? 'admin')) : 'Recovery credential') ?></div>
      </div>
    </div>
  </nav>
  <button type="button" class="ac-navscrim" id="ac-navscrim" hidden aria-label="Close the menu"></button>

  <div class="ac-main">
    <div class="ac-topbar">
      <button type="button" class="ac-btn ac-btn--icon ac-hamburger" id="ac-hamburger" aria-label="Open the menu">
        <i class="fa-solid fa-bars"></i>
      </button>
      <button type="button" class="ac-search" id="ac-open-palette" aria-haspopup="dialog">
        <i class="fa-solid fa-magnifying-glass"></i>
        <span>Search or jump to…</span>
        <span class="ac-kbd">⌘K</span>
      </button>
      <span class="ac-spacer"></span>
      <span class="ac-termchip ac-hide-sm">
        <i class="fa-solid fa-calendar-week"></i><?= ac_h($term['line']) ?>
        <?php if ($term['source'] === 'computed'): ?><span class="ac-tag">computed</span><?php endif; ?>
      </span>
      <div class="ac-seg ac-hide-sm" role="group" aria-label="Row density">
        <button type="button" data-density="comfortable" aria-pressed="<?= $density === 'comfortable' ? 'true' : 'false' ?>" title="Comfortable rows"><i class="fa-solid fa-bars"></i></button>
        <button type="button" data-density="compact" aria-pressed="<?= $density === 'compact' ? 'true' : 'false' ?>" title="Compact rows"><i class="fa-solid fa-grip-lines"></i></button>
      </div>
      <a class="ac-btn ac-hide-sm" href="/admin/?v=export&amp;type=applications.csv"><i class="fa-solid fa-file-csv"></i>Export</a>
      <a class="ac-btn ac-btn--icon ac-bell" href="<?= ac_h(ac_url('dashboard')) ?>" aria-label="Things needing a decision">
        <i class="fa-regular fa-bell"></i>
        <?php if (array_sum($badges) > 0): ?><span class="ac-dot"></span><?php endif; ?>
      </a>
    </div>

    <main class="ac-scroll sc" id="ac-main" data-area="<?= ac_h($activeArea['key'] ?? '') ?>">
      <?php
        /* The section strip — level 2. A <nav>, deliberately NOT role="tablist":
           these navigate between separate screens rather than toggling panels in
           place, so tab semantics would be a lie to a screen reader.
           Omitted for a one-destination area (Dashboard, Reports): a strip
           holding one tab that is the page you are on is furniture. */
        $stripSections = [];
        if ($activeArea !== null && !mam_console_area_is_flat($activeArea)) {
            $stripSections = array_values(array_filter($activeArea['sections'], fn($sec) => $canSee($sec['cap'] ?? null)));
        }
        if (count($stripSections) > 1): ?>
        <nav class="ac-strip" aria-label="<?= ac_h($activeArea['label']) ?> sections">
          <span class="ac-strip-eyebrow"><?= ac_h($activeArea['label']) ?></span>
          <div class="ac-strip-tabs">
            <?php foreach ($stripSections as $sec):
              $sn = (int) ($badges[$sec['key']] ?? 0); ?>
              <a class="ac-strip-tab" href="<?= ac_h(ac_url($sec['key'])) ?>"
                 <?= $sec['key'] === $screen ? 'aria-current="page"' : '' ?>>
                <?= ac_h($sec['label']) ?><?php if ($sn > 0): ?><span class="ac-strip-count"><?= $sn ?></span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </nav>
      <?php endif; ?>

      <?php if ($flashOk !== null): ?>
        <?php ac_banner('good', 'fa-circle-check', '<strong>' . ac_h($flashOk) . '</strong>'); ?>
      <?php endif; ?>
      <?php if ($flashErr !== null): ?>
        <?php ac_banner('bad', 'fa-circle-exclamation', '<strong>' . ac_h($flashErr) . '</strong>'); ?>
      <?php endif; ?>

      <?php
        $file = __DIR__ . '/../partials/admin-console/' . $screen . '.php';
        if (is_file($file)) {
            /* Buffered so `ac_label_table_cells()` can stamp each body cell
               with its own column's header. The phone layout stacks a row into
               a card, and a value in a card needs to say what it is — deriving
               the label here means it cannot drift from the header, and it
               works for the screens whose columns are generated at runtime
               (vetting.php emits one per required clearance), which a
               hand-written attribute cannot. The helper is a no-op on a screen
               with no table. */
            ob_start();
            include $file;
            echo ac_label_table_cells((string) ob_get_clean());
        } else {
            ac_head($labels[$screen] ?? 'Screen', 'This screen has no renderer yet.');
        }

        /* The design ships a Failed state, and this is what it is for: a read
           that could not be served. Printing it AFTER the screen means a
           partial page still shows what it did manage to read, with the gap
           named — rather than an empty table that looks correct. */
        $fails = mam_console_failures();
        if ($fails): ?>
          <div class="ac-card">
            <div class="ac-cardhead">
              <span class="ac-cardtitle">Some of this screen could not be read</span>
              <span class="ac-cardmeta"><?= count($fails) ?> failed read<?= count($fails) === 1 ? '' : 's' ?></span>
            </div>
            <div class="ac-cardbody">
              <div class="ac-note">Nothing was changed. Each line names the read and the database's own words;
              the same text is in <span class="ac-mono">api/logs/app-*.log</span> against
              <span class="ac-mono">console_read_failed</span>.</div>
              <div class="ac-tablewrap" style="margin-top:12px">
                <table class="ac-table">
                  <thead><tr><th>Read</th><th>Error</th></tr></thead>
                  <tbody>
                    <?php foreach ($fails as $f): ?>
                      <tr><td class="ac-mono"><?= ac_h($f['where']) ?></td><td><?= ac_h($f['error']) ?></td></tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        <?php endif; ?>
    </main>
  </div>
</div>

<!-- ⌘K. Client-side over the rail's own destinations, plus a live lookup of
     people and records through the admin search endpoint the legacy console
     already exposes. Searching only what the caller's role can see is the
     server's job, not this list's. -->
<div class="ac-overlay" id="ac-palette" hidden role="dialog" aria-modal="true" aria-label="Search or jump to">
  <button type="button" class="ac-scrim" data-ac-close aria-label="Close"></button>
  <div class="ac-palette">
    <div class="ac-palettehead">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" id="ac-q" placeholder="Search or jump to…" autocomplete="off" spellcheck="false" aria-label="Search">
      <button type="button" class="ac-btn ac-btn--sm" data-ac-close>ESC</button>
    </div>
    <div class="ac-palettelist" id="ac-presults"></div>
    <?php /* The handoff is explicit that a keyboard hint lists ONLY what is
             implemented — an earlier version of its own prototype advertised
             J/K row movement, X to select and ⌘Z undo, none of which existed,
             and they were removed rather than faked. Everything named here
             works. Row selection is deliberately not built and not claimed. */ ?>
    <div class="ac-palettefoot">
      <span><strong>↑↓</strong> move</span><span><strong>↵</strong> open</span>
      <span><strong>G</strong> then <strong>D A S T C F P R G</strong> jumps to an area</span>
      <span class="ac-spacer"></span><span>Searches only what your role can see</span>
    </div>
  </div>
</div>

<script>
window.MAM_CONSOLE = {
  screen: <?= json_encode($screen) ?>,
  area: <?= json_encode($activeArea['key'] ?? '') ?>,
  <?php /* `G` then a letter jumps to an AREA, per the handoff's keyboard table:
           D A S T C F P R G. All nine area initials happen to be distinct, so
           the letter is derived rather than hand-maintained — a hardcoded map
           would drift the day an area is renamed. Each carries the section a
           rail click lands on, filtered by what this admin may see. */ ?>
  areas: <?= json_encode(array_values(array_filter(array_map(function ($a) use ($canSee) {
      $home = mam_console_area_home($a, $canSee);
      return $home === null ? null : [
          'key'    => $a['key'],
          'label'  => $a['label'],
          'letter' => strtolower(mb_substr($a['label'], 0, 1)),
          'home'   => $home,
      ];
  }, $nav)))) ?>,
  <?php /* ⌘K carries all 31 destinations and is what makes demoting 22 of them
           to a second level safe — layered navigation costs discoverability and
           the palette is the compensation. The handoff requires each to be
           findable by SECTION or by AREA name ("finance" should surface Price
           books), so the area travels with the row. */ ?>
  destinations: <?= json_encode(array_map(fn($k) => [
      'key'   => $k,
      'label' => $labels[$k] ?? $k,
      'area'  => $areaOf[$k] ?? '',
  ], $screens)) ?>,
  csrf: <?= json_encode($MAM_CONSOLE_CSRF) ?>
};
</script>
<script src="/assets/js/admin-console.js?v=2026.08.20"></script>
</body>
</html>
