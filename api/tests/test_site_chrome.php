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
t_group('site chrome v2 (header, menu, footer)');

$ROOT   = dirname(MAM_API_ROOT);
$header = (string)@file_get_contents($ROOT . '/partials/header.html');
$footer = (string)@file_get_contents($ROOT . '/partials/footer.html');
$css    = (string)@file_get_contents($ROOT . '/assets/css/main.css');
$js     = (string)@file_get_contents($ROOT . '/assets/js/main.js');

t_ok($header !== '' && $footer !== '' && $css !== '' && $js !== '', 'the four chrome sources are readable');

/* ⚠️ Strip HTML comments before any ABSENCE assertion. The notes explaining
   why a thing was removed necessarily NAME it, so a bare str_contains() on
   the raw file matches the explanation and reports the removal as a
   regression. (Same trap `test_instructor_apply.php` hit with its
   <details>-inside-<p> check.) */
$strip   = static fn(string $h): string => (string)preg_replace('/<!--.*?-->/s', '', $h);
$headerB = $strip($header);
$footerB = $strip($footer);

/* ── The bar ─────────────────────────────────────────────────────────────── */

t_ok((bool)preg_match('/--nav-h:56px/', $css), 'the bar is 56px, not the old 80');
t_ok((bool)preg_match('/#navbar\{position:sticky;top:0;[^}]*border-radius:0;/', $css),
    'it is flush: top:0 and no radius, not a pill at top:10px with a 24px radius');
t_ok(!(bool)preg_match('/#navbar\{[^}]*backdrop-filter/', $css),
    'and carries no backdrop-filter — a 26px blur is a real cost on a mid-range Android '
    . 'and is invisible behind the page heroes anyway');
t_ok((bool)preg_match('/#navbar\{[^}]*background:rgba\(26,18,11,0\.92\)/', $css),
    'it is opaque enough to read against any hero');

// The four brand-shrinking breakpoints existed only to stop the bar
// overflowing. A 56px bar with two icon buttons does not need them.
t_ok(!(bool)preg_match('/\.nav-brand-name\{font-size:1(\.18|\.05)?rem\}/', $css),
    'the wordmark no longer shrinks 1.45rem → 1.18 → 1.05 → 1rem across four breakpoints');
t_ok(!(bool)preg_match('/\.btn-nav-ghost\{[^}]*font-size:0[;}]/', $css),
    'and "Sign in" is no longer degraded to font-size:0 to make room');
t_ok((bool)preg_match('/\.nav-brand-name\{[^}]*color:var\(--gold-bright\)/', $css)
    && !(bool)preg_match('/\.nav-brand-name\{[^}]*background-clip:text/', $css),
    'the wordmark is a FLAT colour: a clipped gradient at 19px costs a paint layer and '
    . 'fails to a transparent word wherever the clip is unsupported');

// Exactly two icon controls, plus Apply. Sign in moves into the menu.
t_ok(substr_count($header, 'class="nav-icon-btn"') >= 1 && str_contains($header, 'id="navSearchBtn"'),
    'the bar gains a search control');
t_ok(substr_count($header, 'class="hamburger-line"') === 2,
    'the menu glyph is TWO bars, not three — with no "Menu" label beside it, three read as decoration');
t_ok((bool)preg_match('/\.btn-nav-ghost\{display:none\}/', $css),
    'Sign in leaves the bar on touch, where it has no room for a label');
t_ok((bool)preg_match('/@media \(min-width:1025px\)\{[^@]*\.btn-nav-ghost\{display:inline-flex/s', $css),
    '…and returns from the desktop breakpoint up');
t_ok(str_contains($header, 'class="btn-nav-apply"'),
    'Apply stays in the bar — it is what makes deleting the floating pill on touch honest');

/* ── The announce strip ──────────────────────────────────────────────────── */

t_ok((bool)preg_match('/\.announce\{[^}]*white-space:nowrap|\.announce-msg\{[^}]*white-space:nowrap/', $css),
    'the message is ONE line, always');
t_ok((bool)preg_match('/\.announce-msg\{[^}]*text-overflow:ellipsis/', $css),
    'and ellipsises rather than wrapping to two lines at 390px');
t_ok(!(bool)preg_match('/\.announce\{[^}]*linear-gradient/', $css),
    'it is not the full-saturation gold band — that competes with the page\'s own primary action');
t_ok((bool)preg_match('/\.announce\[hidden\]\{display:none\}/', $css),
    'a class-level `display:flex` beats the UA\'s [hidden] rule, so the guard is explicit '
    . '(the fifth time this trap has appeared in this codebase)');
t_ok(str_contains($header, 'id="announceClose"'), 'it has a real close button');
t_ok(str_contains($js, 'mam.announce.'), 'and the dismissal persists');
t_ok((bool)preg_match('/function key\(\)[^}]*data-intake-label|announceMsg/s', $js)
    && str_contains($js, "h = ((h * 33) ^ t.charCodeAt(i))"),
    'keyed to the MESSAGE, not the element, so a new campaign reappears for somebody who closed the last one');
// The strip is a SIBLING after </nav>, so dismissing it never moves the bar.
t_ok(strpos($header, 'id="announceStrip"') > strpos($header, '</nav>'),
    'the strip sits BELOW the bar, so closing it never moves the bar');

/* ── The menu ────────────────────────────────────────────────────────────── */

t_eq(substr_count($header, 'class="menu-row"'), 7, 'seven rows, not five accordions over forty links');
t_eq(substr_count($header, 'class="menu-minor"'), 1, 'plus one minor-link row');
t_ok(substr_count($header, 'menu-row-go chev') === 4 && substr_count($header, 'fa-arrow-right menu-row-go') === 3,
    'four rows open a sub-panel (chevron) and three navigate away (arrow) — the distinction is load-bearing');
t_ok(str_contains($header, 'id="menuSearch"'),
    'search comes FIRST: a forty-link site needs it more than a four-tile quick grid');
t_ok(!str_contains($headerB, 'mobile-quick'),
    'the quick grid is gone — it gave Studio and Store the same visual weight as Apply');
/* Scoped to the MENU, which is where the handoff removes them. The desktop
   mega menu keeps them on purpose: the wizard still resolves `?discipline=`
   as a prefill (the Part-2 rework maps the old optgroup values onto its own
   searchable list precisely so it keeps working), so there they are a working
   shortcut rather than the 13-link scroll they were on a phone. */
preg_match('/<aside class="mobile-menu".*?<\/aside>/s', $headerB, $mm);
t_ok($mm && !str_contains($mm[0], 'apply?discipline='),
    'the seven `apply?discipline=` deep links are gone FROM THE MENU: they were 13 of its links '
    . 'and pre-answered a question the wizard asks on its own screen');
t_ok(str_contains($header, 'id="menuCloseBtn"') && str_contains($js, "'#menuCloseBtn'"),
    'the menu carries its own head and close control, and it is wired');
t_ok(str_contains($js, "'#navSearchBtn'"),
    'the bar\'s search button opens the menu and focuses the field — no second surface for one job');

/* ── One floating object, not four ───────────────────────────────────────── */

t_ok((bool)preg_match('/function initBackToTop\(\)\s*\{.*?pointer: coarse.*?return;/s', $js),
    'the back-to-top FAB does not exist on touch — it was never in the dock (z-index 901 against '
    . 'the dock\'s 950), so it overlapped whatever was there and was painted over');
t_ok((bool)preg_match('/var coarse = window\.matchMedia/', $js),
    'and the floating Apply pill is removed on touch too');
/* "One row, not a stacked block" — but a row only where a row FITS. MEASURED
   at 320 with the actions `flex-shrink:0` and no wrap: the two buttons took
   142px of a 296px banner and squeezed the sentence into a 108px column six
   lines deep. It wraps on the TEXT'S MEASURE now, so the copy decides where
   the row stops being a row; what it must never do is go back to being a
   column of full-width stacked blocks. */
t_ok((bool)preg_match('/\.cookie-banner\{[^}]*display:flex/', $css)
  && !(bool)preg_match('/\.cookie-banner\{[^}]*flex-direction:column/', $css),
    'the cookie banner is a row, not a stacked block');
t_ok((bool)preg_match('/\.cookie-text\{flex:1 1 \d+px/', $css),
    '…and it wraps on the text\'s own minimum measure rather than a breakpoint, so a '
    . 'longer sentence or a translation moves the wrap point with it');
t_ok((bool)preg_match('/\.cookie-actions\{[^}]*margin-left:auto/', $css),
    '…with the buttons hard right on both layouts — left-aligned on their own wrapped '
    . 'line they read as orphaned');
t_ok(str_contains($footer, 'id="cookieDecline"'),
    'Decline SURVIVES the compaction: a consent control that disappears is worse than a tight one');

/* ── The footer ──────────────────────────────────────────────────────────── */

t_eq(substr_count($footer, '<details class="footer-group">'), 4,
    'four collapsed groups instead of 35 links in one vertical run');
t_ok(str_contains($footer, '<summary class="footer-group-head">'),
    'they are <details>/<summary>, so they work with JS off at every width');
t_ok(str_contains($js, 'function initFooterGroups'),
    'JS only OPENS them where they are columns rather than accordions');
$counts = [];
preg_match_all('/class="footer-group-count">(\d+)</', $footer, $m);
$counts = array_map('intval', $m[1]);
t_eq(implode('/', $counts), '5/6/12/3', 'the stated counts are Schools 5 · Pathways 6 · Academy 12 · Portals 3');
// …and each one is TRUE. A count beside a collapsed group is a promise.
preg_match_all('/<details class="footer-group">.*?<\/details>/s', $footer, $groups);
$actual = array_map(static fn(string $g): int => substr_count($g, '<li>') + substr_count($g, '<li '), $groups[0]);
t_eq(implode('/', $actual), implode('/', $counts), 'and every count matches the links actually inside its group');

t_ok(!str_contains($footerB, 'textLength'),
    'the wordmark is plain text: the SVG stretched "MAM CENTRE" to textLength:1184, which drew '
    . '66px letters 1.18x wide on a 390px screen');
t_ok((bool)preg_match('/\.footer-wordmark span\{[^}]*font-size:34px/', $css), 'at its natural 34px on a phone');
t_ok((bool)preg_match('/\.footer-logo-name\{[^}]*white-space:nowrap/', $css),
    'and the footer wordmark cannot wrap — MEASURED: at 21px in a 196px column the Bebas '
    . 'fallback broke MAM / ACADEMY onto two lines');
t_ok((bool)preg_match('/\.footer-newsletter-btn\{[^}]*padding:0 18px/', $css)
    && str_contains($footer, '>Sign up</button>'),
    'the newsletter submit is a LABELLED button, not a 46px gradient circle holding a bare paper-plane glyph');
t_ok(str_contains($footer, 'class="footer-apply"') && str_contains($footer, 'class="footer-contact-btn"'),
    'Apply / Email us / Call us are the phone footer\'s three actions — contact details are the '
    . 'most-used thing in a footer and used to be plain text among 35 links');

/* ── The mega menus ──────────────────────────────────────────────────────── */

t_ok((bool)preg_match('/\.mega-spotlight\{display:none\}/', $css),
    'the photographic spotlight panel is gone — the widest element in the chrome and the slowest '
    . 'thing on the page to paint, for a decorative image behind a menu');
t_eq(substr_count($header, 'mega-2col'), 2, 'two mega menus declare two columns');
t_eq(substr_count($header, 'mega-3col'), 2, 'and two declare three');
t_ok(!(bool)preg_match('/@media \(max-width:1280px\)\{[^@]*grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/s', $css),
    'no column wraps under its neighbours below 1280 any more: the 2-column collapse existed only '
    . 'because three columns PLUS a 1.15fr photo panel needed 1080px');
