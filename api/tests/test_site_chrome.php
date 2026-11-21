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
t_ok(str_contains($footer, 'id="cookieBanner"'), 'the cookie notice survives the move: MAM_PRIVACY.mayMeasure() reads the choice it records');
t_ok(str_contains($header, 'id="contrastToggle"') && str_contains($header, 'id="floatingEnroll"'),
    'the accessibility toolbar and the floating Apply pill survive on the pages that had them');
$legacy = array_filter(glob($ROOT . '/*.php') ?: [], static fn($f) => str_contains((string) file_get_contents($f), "partials/header.html"));
$unset  = array_values(array_map('basename', array_filter($legacy, static function ($f) {
    $src = (string) file_get_contents($f);
    $pre = substr($src, 0, (int) strpos($src, '<!DOCTYPE'));
    return !str_contains($pre, 'partials/site/shell.php') && !str_contains($pre, 'api/lib/bootstrap.php');
})));
t_eq($unset, [], 'every page using the shared header sets up the shell before its first byte (headers cannot be sent after output)');

/* What the 2026-09 chrome promised, held against the new one. */
t_ok(preg_match('/<a href="\/apply" class="btn arrow"[^>]*>Apply/', $inner) === 1,
    'Apply stays in the bar at every width (the bar is the persistent Apply path on touch)');
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
$authL  = (string)@file_get_contents($ROOT . '/api/lib/auth.php');

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
