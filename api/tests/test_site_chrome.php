<?php
/**
 * Site chrome v2 — static guards on the header, menu and footer rework.
 *
 * Source: docs/design/2026-09-site-chrome (handoff + BUILD-NOTES.md). Every
 * assertion here is one line of that handoff, or one defect the browser pass
 * found. Static on purpose, like `test_root_pages.php` and
 * `test_instructor_apply.php`: executing a root page would emit headers and
 * write to the REAL api/storage dev DB. These read the source.
 *
 * What is NOT here, deliberately: geometry. A rule saying `min-height:44px`
 * proves nothing about the box a finger lands on — it can be overridden, and
 * on this repo it repeatedly has been. The boxes were driven in Playwright at
 * 320/360/390/430/768/900/1024/1280/1440, and the numbers are in BUILD-NOTES.
 */
declare(strict_types=1);
/* The 2026-09 chrome (navbar, seven-row mobile menu, <details> footer) was
   RETIRED on 2026-11-21 when every public page moved to the 2026-11 redesign's
   header, Programmes panel and footer (partials/site/; HANDOFF §4z16). Its
   assertions went with it. The promises that outlive the markup are held here
   against the new chrome, and test_site_redesign.php holds the rest. */
t_group('site chrome — every public page wears the redesign');

$ROOT   = dirname(MAM_API_ROOT);
$header = (string)@file_get_contents($ROOT . '/partials/header.html');
$footer = (string)@file_get_contents($ROOT . '/partials/footer.html');
$inner  = (string)@file_get_contents($ROOT . '/partials/site/header-inner.php');
$panel  = (string)@file_get_contents($ROOT . '/partials/site/panel.php');
$sfoot  = (string)@file_get_contents($ROOT . '/partials/site/footer.php');
$sjs    = (string)@file_get_contents($ROOT . '/assets/js/site.js');

t_ok(str_contains($header, "site_header('inner'") && !str_contains($header, 'id="navbar"'),
    'the shared header partial renders the redesign\'s bar, not the retired navbar');
t_ok(str_contains($footer, 'site_footer_chrome()'), 'the shared footer partial renders the redesign\'s footer');
$shell = (string)@file_get_contents($ROOT . '/partials/site/shell.php');
$a11y  = (string)@file_get_contents($ROOT . '/partials/site/a11y.php');
$acss  = (string)@file_get_contents($ROOT . '/assets/css/a11y.css');
$ajs   = (string)@file_get_contents($ROOT . '/assets/js/a11y.js');
$apply = (string)@file_get_contents($ROOT . '/apply.php');
t_ok(preg_match('/function site_footer_chrome\(\): void \{[^}]*site_a11y_panel\(\)/s', $shell) === 1
     && str_contains($a11y, 'data-cookie aria-label="Cookie notice"') && str_contains($sjs, "store('mam.cookie'"),
    'every page that wears the footer gets the cookie notice, and its answer is stored where MAM_PRIVACY.mayMeasure() reads it');
t_ok(str_contains($header, 'site_a11y_head()') && str_contains($shell, '<?= site_a11y_head() ?>')
     && str_contains($apply, 'site_a11y_head()') && str_contains($apply, 'site_a11y_panel(false)'),
    'the accessibility settings are applied before paint on the older pages, the redesigned ones and Apply');
t_ok(!str_contains($header, 'id="contrastToggle"') && str_contains($header, 'id="floatingEnroll"'),
    'the old four-button toolbar is gone (the panel replaces it); the floating Apply pill stays');
t_ok(str_contains($apply, 'data-a11y-open'), 'Apply, which draws no launcher, opens the panel from its own top bar');

t_group('site chrome — the accessibility panel does what it offers');
// The settings are one table, site_a11y_options(); the panel draws it and the statement describes it.
preg_match('/function site_a11y_options\(\): array \{(.*?)\n\}/s', $shell, $ob);
preg_match_all("/'([a-z]+)'\s*=>\s*\['/", $ob[1] ?? '', $m);
$A11Y_keys = $m[1];
t_ok(str_contains($a11y, 'site_a11y_options()') && str_contains((string)@file_get_contents($ROOT . '/accessibility.php'), 'site_a11y_options()'),
    'the panel and the accessibility statement read the same list, so the statement cannot promise a setting the panel lacks');
t_ok(count($A11Y_keys) >= 9, 'the panel offers at least nine settings beyond text size (it had four buttons)');
preg_match("/var FLAGS = \[([^\]]*)\]/", $ajs, $fm);
foreach ($A11Y_keys as $k) {
    // A switch with no rule behind it is a control that does nothing, which is worse than no switch.
    $hasRule = str_contains($acss, "html.a11y-$k") || ($k === 'guide' && str_contains($acss, '.a11y-guide{'));
    t_ok($hasRule && str_contains($fm[1] ?? '', "'$k'"), "'$k' is applied by the script and has a rule in a11y.css");
    if ($k !== 'guide') t_ok(str_contains($shell, "'$k'"), "'$k' is applied before the first paint, not after it");
}
preg_match("/foreach \(\[('[^\]]*')\] as \\\$i => \\\$t\)/", $a11y, $lab);
preg_match('/var z=\[([0-9.,]+)\]/', $shell, $zt);
$A11Y_labels = array_map(static fn($x) => trim($x, " '%"), explode(',', $lab[1] ?? ''));
$A11Y_z      = array_map(static fn($x) => (string) round((float) $x * 100, 1), explode(',', $zt[1] ?? ''));
t_eq($A11Y_z, array_map(static fn($x) => (string) round((float) $x, 1), $A11Y_labels),
    'each text size does what its button says (the zoom is the figure on the button)');
t_ok(str_contains($acss, 'zoom:var(--a11y-z') && str_contains($shell, 'innerWidth/320'),
    'text size never lays the page out narrower than 320px (WCAG 1.4.10), so it cannot cause sideways scrolling');
t_ok(str_contains($shell, "localStorage.getItem('mam.contrast')") && str_contains($shell, "localStorage.getItem('mam.fontScale')")
     && str_contains($ajs, "localStorage.getItem('mam.contrast')"),
    'a contrast or text size chosen on the old toolbar carries across, before paint and after');
t_ok(str_contains($a11y, 'role="dialog" aria-modal="true"') && str_contains($ajs, "e.key === 'Escape'")
     && str_contains($ajs, "e.key === 'Tab'") && str_contains($ajs, 'opener.focus()'),
    'aria-modal is a promise the script keeps: focus moves in, Tab stays inside, Escape closes, focus returns');
t_ok(str_contains($ajs, "'speechSynthesis' in window") && !preg_match('/fetch\(|XMLHttpRequest/', $ajs),
    'read-aloud uses the device\'s own voice and sends nothing anywhere (the panel says so)');

t_group('site chrome — one currency choice, at the rates a charge is made at');
preg_match_all('/"([A-Z]{3})" => "/', $sfoot, $cm);
t_eq($cm[1], ['USD', 'NGN', 'GBP'], 'the footer switch offers the three currencies the server prices in');
$siteLib = (string)@file_get_contents(MAM_API_ROOT . '/modules/site/lib/site.php');
foreach ($cm[1] as $c) t_ok($c === 'USD' || str_contains($siteLib, "'$c' => (float)"),
    "$c has a rate in site_fees(), the rates a charge is made at (a missing rate hides the button)");
t_ok(str_contains($sjs, 'F.rates[cur]') && !preg_match('/open\.er-api|ipapi/', $sjs),
    'the redesigned pages convert at the server\'s rates, never a third-party feed');
t_ok(str_contains((string)@file_get_contents($ROOT . '/assets/js/site-programmes.js'), "addEventListener('mam:currency'"),
    'the estimator and the footer switch are one choice: changing either moves the other');
$legacy = array_filter(glob($ROOT . '/*.php') ?: [], static fn($f) => str_contains((string) file_get_contents($f), "partials/header.html"));
$unset  = array_values(array_map('basename', array_filter($legacy, static function ($f) {
    $src = (string) file_get_contents($f);
    $pre = substr($src, 0, (int) strpos($src, '<!DOCTYPE'));
    return !str_contains($pre, 'partials/site/shell.php') && !str_contains($pre, 'api/lib/bootstrap.php');
})));
t_eq($unset, [], 'every page using the shared header sets up the shell before its first byte (headers cannot be sent after output)');

/* What the 2026-09 chrome promised, held against the new one. */
/* The owner removed Apply from the bar (21 Nov 2026): it lives in each page's own calls
   to action, the home intake strip and the footer, not in the navigation. */
t_ok(!preg_match('/<a href="\/apply" class="btn/', $inner),
    'the bar carries no Apply button, at any width (the homepage uses the same bar since the hero restyle)');
t_ok(str_contains($inner, 'aria-controls="programmes-panel"') && str_contains($panel, 'id="programmes-panel" hidden'),
    'the menu is a disclosure: its trigger names the panel, and the panel starts closed');
t_ok(str_contains($sjs, "e.key === 'Escape'") && str_contains($sjs, 'lastToggle.focus()'),
    'Escape closes the panel and focus returns to the button that opened it');
t_ok(preg_match('/<button type="submit"[^>]*>Subscribe<\/button>/', $sfoot) === 1,
    'the newsletter submit is a labelled button');
t_ok(str_contains($sfoot, 'mailto:academy@mamcentre.org') && str_contains($sfoot, 'tel:+2347044446517'),
    'the footer carries the email address and phone number as links');
t_ok(!str_contains($header, 'name="q"'), 'the old search box is not carried over: it posted ?q= to a homepage that never read it');

/* ── Portal routing: one authority, not four copies ──────────────────────── */
t_group('portal routing — the guardian portal is reachable');

$authMe = (string)@file_get_contents($ROOT . '/api/routes/auth/me.php');
$cfgJs  = (string)@file_get_contents($ROOT . '/assets/js/config.js');
$loginH = (string)@file_get_contents($ROOT . '/portal/login.html');
$chooseH= (string)@file_get_contents($ROOT . '/portal/choose.html');
$authL  = (string)@file_get_contents($ROOT . '/api/modules/identity/lib/auth.php');

/* `mam_portal_targets()` has always known about 'parent'. Three CLIENTS kept
   their own copy of that map and none of them did, so a guardian was bounced
   to the homepage by login.html, handed the STUDENT portal by choose.html,
   and given a student-portal link by MAM_AUTH.portalFor(). */
t_ok((bool)preg_match("/'parent'\\s*=> \\['role' => 'parent',[^\\]]*'\\/portal\\/parent\\/'\\]/", $authL),
    'the server map is the authority and names the parent portal');
// Single-quoted: in a double-quoted PHP string `$roles` interpolates to the
// empty string and the assertion silently tests for `mam_portal_targets()`.
t_ok(str_contains($authMe, '\'portals\' => mam_portal_targets($roles)'),
    '…and /api/auth/me carries that list to the client');
t_ok(str_contains($authMe, "'portals' => []"),
    '…including for a signed-out caller, so the client never reads undefined');

t_ok(str_contains($cfgJs, 'r.portals && r.portals.length'),
    'MAM_AUTH prefers the server list over its own map');
t_ok(str_contains($cfgJs, "parent: '/portal/parent/'"),
    '…and its fallback map, used only before the fetch lands, names parent too');
t_ok(!(bool)preg_match("/var map = \\{ admin: '\\/admin\\/', instructor:/", $loginH),
    'login.html no longer keeps a copy of the map');
t_ok(str_contains($loginH, 'var portals = r.portals || [];'), '…it reads the server list');
t_ok(str_contains($chooseH, 'var portals = r.portals || [];'), 'and so does the chooser');
/* A role the chooser has no icon for still renders. A missing entry used to
   mean `META[undefined] || META.student` — somebody else's portal. */
t_ok(str_contains($chooseH, "esc(m.icon || 'circle-user')"),
    'a portal with no presentation entry still renders, with a neutral icon');
t_ok(!str_contains($chooseH, 'META.student).url'),
    '…and no path falls back to the student portal for an unknown role');
