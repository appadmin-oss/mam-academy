<?php
/**
 * The student portal v2 surface — static guards.
 *
 * `test_student_portal.php` covers the PROVIDERS (and the refusals they keep).
 * This file covers the three files a browser actually loads:
 * `portal/student/index.html`, `assets/js/portal-student.js` and the student
 * block of `assets/css/portal-v2.css`.
 *
 * Static on purpose, like `test_console.php`. What is NOT here is geometry:
 * target sizes, the 120px scroll reserve and the desktop rail were driven in a
 * real browser at 390 and 1440, because a test asserting `min-height: 44px`
 * appears in a stylesheet proves nothing about the box a finger lands on. Four
 * defects on this surface were found that way and none of them would have
 * failed an assertion here: the practice figure labelled "this week" above a
 * Mon–Sun strip it contradicted, a next step rendered above the class it was
 * subordinate to, the same session listed twice under two different names, and
 * competency bars run out to 1,110px where a proportion cannot be read.
 */
declare(strict_types=1);
t_group('student portal v2 (the surface)');

$ROOT  = dirname(MAM_API_ROOT);
$html  = (string) @file_get_contents($ROOT . '/portal/student/index.html');
$js    = (string) @file_get_contents($ROOT . '/assets/js/portal-student.js');
$css   = (string) @file_get_contents($ROOT . '/assets/css/portal-v2.css');

t_ok($html !== '' && $js !== '' && $css !== '', 'the three surface files are readable');

/* ── One palette per page ────────────────────────────────────────────────── */

// main.css and portal.css own --bg/--text site-wide and portal.css reads them
// ~30 times while declaring none, so loading either beside portal-v2.css
// repaints this light surface with the warm dark set — and the surfaces v2
// does not override keep their dark values, giving dark text on dark ground.
t_ok((bool) preg_match('~<html lang="en" data-portal="v2">~', $html),
    'the scope attribute is on <html>, or portal-v2.css is inert');
t_ok(strpos($html, 'portal-v2.css') !== false, 'portal-v2.css is loaded');
t_ok(strpos($html, 'assets/css/main.css') === false && strpos($html, 'assets/css/portal.css') === false,
    'neither warm-dark stylesheet is loaded beside it');

/* ── No inline styles: every visual decision is a named component ────────── */

$markup = preg_replace('/<!--.*?-->/s', '', $html);
preg_match_all('/\sstyle="[^"]*"/', $markup, $sm);
t_eq($sm[0], [], 'the markup carries no style attribute');
$jsCode = preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $js);
preg_match_all('/\sstyle="[^"]*"/', $jsCode, $js_sm);
t_eq($js_sm[0], [], 'no template in the app writes a style attribute either');

/* ── Every destination resolves ──────────────────────────────────────────── */

// A bar item or sheet row naming a screen the router does not know renders the
// home screen instead, silently.
preg_match_all('/data-screen="([a-z0-9]+)"/', $html, $ds);
$linked = array_values(array_unique($ds[1]));
preg_match('/var SCREENS = \{(.*?)\};/s', $js, $sc);
preg_match_all('/(\w+):\s*render/', (string) ($sc[1] ?? ''), $known);
$routed = $known[1] ?? [];
t_eq(array_values(array_diff($linked, $routed)), [], 'every data-screen the markup links to has a renderer');
// And every server screen has a client renderer, so a provider cannot be built
// for a screen nothing can open.
t_eq(array_values(array_diff(MAM_SPORTAL_SCREENS, array_merge($routed, ['more']))), [],
    'every screen the server serves has a renderer (More is the sheet itself)');

/* ── No dead buttons ─────────────────────────────────────────────────────── */

// CLAUDE.md is explicit about this ("Don't ship dead buttons").
foreach (['pracSave', 'tbSend', 'repAdd', 'signOut'] as $id) {
    t_ok(substr_count($js, "'#" . $id . "'") >= 1 || substr_count($js, 'id="' . $id . '"') >= 1,
        'the action #' . $id . ' is rendered');
    t_ok((bool) preg_match("/closest\('#" . $id . "'\)/", $js),
        'the action #' . $id . ' has a handler');
}
// Every data- action the templates emit is handled by the one delegated listener.
foreach (['checkin', 'clockout', 'prepare', 'warm', 'waive', 'repstatus', 'repdel'] as $act) {
    t_ok((bool) preg_match('/\[data-' . $act . '(?:="|\])/', $js), "data-$act is emitted");
    t_ok((bool) preg_match("/closest\('\[data-" . $act . "\]'\)/", $js), "…and data-$act is handled");
}

/* ── The refusals, as they appear on this surface ────────────────────────── */

// ⛔ §2.3 — no cohort effort board, ever. The leaderboard was removed from this
// codebase in 2026-09 and test_engagement2.php guards its absence; this asserts
// the redesign did not quietly reintroduce one on the way past.
foreach (['leaderboard', 'ranking', 'percentile', 'classmates'] as $bad) {
    t_ok(stripos($jsCode, $bad) === false, "the app contains no '$bad'");
}
t_ok((bool) preg_match('/streak_private/', $js),
    'the streak renders the privacy sentence the provider sends');

// ⛔ §2.1 — no automatic scoring of a child's playing. A take renders its
// audio and its status; there is no branch that could compute a mark.
t_ok(!preg_match('/\b(autoScore|scoreTake|detectPitch|pitchScore)\b/i', $jsCode),
    'nothing in the app scores a recording');

// ⛔ §2.2 and gotcha #12 — the BAND, never the raw score.
t_ok(strpos($jsCode, 'raw_score') === false,
    'the app never reads raw_score — it is the number the coverage floor withholds');
t_ok((bool) preg_match('/m\.band \? m\.band\.label/', $js), 'it renders the band');
t_ok((bool) preg_match('/!m\.sufficient/', $js),
    'and below the coverage floor it shows no composite at all, not a composite with a warning');

// ⛔ refusal — never an optimistic write state.
t_ok((bool) preg_match('/function write\(btn, req, onOk, okMsg\)/', $js),
    'one write helper, so a screen cannot invent its own success path');
t_ok(strpos($js, 'Never an optimistic tick') !== false,
    'and it says why, in the file that would otherwise be changed');

// The §9 boundaries are the SERVER's. The old dashboard hardcoded READY = 15 in
// the browser, so an academy that changed the setting got a page that
// disagreed with the gate it was describing.
t_ok(!preg_match('/\b(READY|readyBefore|openBefore)\s*=\s*\d+/', $jsCode),
    'no check-in boundary is hardcoded in the browser');
t_ok((bool) preg_match("/'open', 'readiness_passed', 'in_progress'/", $js)
     || (bool) preg_match("/st === 'open'/", $js),
    'the check-in control follows the window STATE, which is five-way, not a boolean');

/* ── The legacy dashboard is not retired, and both link to each other ────── */

// Same arrangement as portal/instructor/classic.html and admin/index.php beside
// admin/console.php. The redesign covers five destinations; the legacy
// dashboard serves far more, and dropping them silently is not a redesign.
t_ok(is_file($ROOT . '/portal/student/classic.html'), 'the legacy dashboard is still served');
t_ok(strpos($html, '/portal/student/classic.html') !== false, 'the redesign links to it');
$classic = (string) @file_get_contents($ROOT . '/portal/student/classic.html');
t_ok(strpos($classic, '/portal/student/') !== false, 'and it links back');

/* ── Accessibility preferences are one person's, not one portal's ───────── */

// The same storage key as the guardian and instructor portals, read pre-paint
// so a reload never shows one frame of the other theme.
t_ok(substr_count($html, 'mam-guardian-a11y') >= 1 && substr_count($js, 'mam-guardian-a11y') >= 1,
    'the a11y preference shares one key across the three portals');
$guardian = (string) @file_get_contents($ROOT . '/assets/js/portal-guardian.js');
t_ok(strpos($guardian, 'mam-guardian-a11y') !== false, '…which is the key the guardian portal writes');

/* ── The CSS is scoped, so it cannot leak onto the dark portals ──────────── */

$sBlock = strstr($css, 'THE STUDENT PORTAL — the `.s-` block');
t_ok($sBlock !== false, 'the student block is present and labelled');
preg_match_all('/^\.[a-z][a-z0-9-]*\s*[,{]/m', (string) $sBlock, $unscoped);
t_eq($unscoped[0], [], 'every student rule is scoped under [data-portal="v2"]');

/* ── No inline styling, anywhere, on any v2 surface ──────────────────────── */

/* The repo-wide rule, asserted in ONE place for all three portals rather than
   once per portal — a rule stated three times drifts, and the guardian portal
   has no surface test of its own.
 *
 * Two routes in, and both are closed here. A `style=` attribute in the markup
 * is the obvious one. The other is a script assigning `.style.foo` (or
 * `setProperty`) after render, which is the same attribute arriving later: it
 * puts a visual decision where nobody looks when they change one, and it is
 * how the legacy dashboards reached 706 and 358 of them.
 *
 * The one thing that ever genuinely wanted it was a competency bar's width —
 * a length whose NUMBER is data. That is a `[data-pct="N"]` table in
 * portal-v2.css now, so there is no case left to make. */
t_group('the v2 portals carry no inline styling');

$V2 = [
    'student'    => [$ROOT . '/portal/student/index.html',    $ROOT . '/assets/js/portal-student.js'],
    'instructor' => [$ROOT . '/portal/instructor/index.html', $ROOT . '/assets/js/portal-instructor.js'],
    'guardian'   => [$ROOT . '/portal/parent/index.html',     $ROOT . '/assets/js/portal-guardian.js'],
];
foreach ($V2 as $who => $pair) {
    $m = preg_replace('/<!--.*?-->/s', '', (string) @file_get_contents($pair[0]));
    $j = preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) @file_get_contents($pair[1]));
    t_ok($m !== '' && $j !== '', "the $who surface is readable");
    preg_match_all('/\sstyle="[^"]*"/', $m, $mm);
    t_eq($mm[0], [], "the $who markup carries no style attribute");
    preg_match_all('/\sstyle="[^"]*"/', $j, $jj);
    t_eq($jj[0], [], "no $who template writes a style attribute");
    t_ok(!preg_match('/\.style\s*\.\s*\w+\s*=/', $j),
        "the $who app assigns no element style property");
    t_ok(!preg_match('/\.setProperty\s*\(/', $j),
        "…and sets no custom property on an element either — still a style attribute");
}

/* The replacement, so nobody restores the JS by finding the CSS missing. */
t_ok((bool) preg_match('/\[data-pct="100"\] \{ width: 100%; \}/', $css),
    'bar widths are a table in the stylesheet, one rule per whole percent');
t_ok((bool) preg_match('/\.p-bar > span \{ width: 0; \}/', $css),
    'and an unmatched bar is width 0 — a missing mark must never draw a full bar');

/* The legacy dashboards are exempt and that is a SCOPE statement, not an
   exception to the rule: they are ~3,100 and ~2,300 lines of pre-redesign
   markup carrying hundreds of them, and they are retired rather than
   rewritten. What must not happen is a NEW one, so the strip each of them
   wears back to its redesigned portal — the one thing this stream added to
   either file — is a class. */
foreach (['student', 'instructor'] as $who) {
    $classic = (string) @file_get_contents($ROOT . "/portal/$who/classic.html");
    t_ok(strpos($classic, 'class="portal-classic-back"') !== false,
        "the $who back-strip is a class, not a style attribute");
    t_ok(!preg_match('/<p style="[^"]*"><a href="\/portal\//', $classic),
        "…and the style-attribute version of it is gone");
}

/* ── The accessibility findings from the 2026-09-21 UX audit ─────────────── */

/* Each of these was MEASURED in a browser and each is invisible to a static
   read of the markup, so what is pinned here is the mechanism that fixes it —
   the cheapest thing to delete by accident. The measurements are in
   docs/HANDOFF.md §4s. */
t_group('the v2 portals — audited accessibility invariants');

$APPS = [
    'student'    => $ROOT . '/assets/js/portal-student.js',
    'instructor' => $ROOT . '/assets/js/portal-instructor.js',
    'guardian'   => $ROOT . '/assets/js/portal-guardian.js',
];
$MARKUP = [
    'student'    => $ROOT . '/portal/student/index.html',
    'instructor' => $ROOT . '/portal/instructor/index.html',
    'guardian'   => $ROOT . '/portal/parent/index.html',
];

foreach ($APPS as $who => $file) {
    $j = preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) @file_get_contents($file));

    /* The More sheet declares `role="dialog" aria-modal="true"`, which tells a
       screen reader the rest of the page is inert. Setting `hidden = false`
       and nothing else makes that a lie: focus stays behind the sheet, Tab
       walks out of it, and Escape does nothing — on a sheet whose rows
       include Sign out. Same family as the `role="menu"` the site chrome
       removed for promising a keyboard contract it never implemented. */
    t_ok((bool) preg_match('/function openSheet\(/', $j), "$who: opening the sheet goes through one function");
    t_ok((bool) preg_match("/key === 'Escape'/", $j), "$who: Escape closes the sheet");
    t_ok((bool) preg_match("/key !== 'Tab'/", $j), "$who: Tab is trapped inside the sheet");
    t_ok((bool) preg_match('/sheetReturn/', $j), "$who: focus returns to the control that opened it");
    t_ok((bool) preg_match('/first\.focus\(\)/', $j), "$who: focus moves INTO the sheet on open");

    /* An ERROR is not a polite status. `role="status"` is queued until the
       screen reader is idle, and a message that auto-hides after five seconds
       is often the only record that the thing the reader pressed did not
       happen. */
    t_ok((bool) preg_match("/'alert' : 'status'/", $j), "$who: a failure is announced as an alert, not a status");
    t_ok((bool) preg_match("/'assertive' : 'polite'/", $j), "$who: …assertively");
    t_ok((bool) preg_match('/if \(!bad\) (toastTimer|.*setTimeout)/', $j),
        "$who: only a SUCCESS times out — an error waits to be dismissed");
    t_ok((bool) preg_match("/closest\('#toastClose'\)/", $j), "$who: and the dismiss control is wired");
}
foreach ($MARKUP as $who => $file) {
    $m = (string) @file_get_contents($file);
    t_ok(strpos($m, 'id="toastClose"') !== false, "$who: the toast carries a dismiss control");
    t_ok((bool) preg_match('/id="toastClose"[^>]*aria-label=/', $m), "…with an accessible name");
}

/* ⚠️ Focus moves on the PAINT, not on the navigation. Every screen fetches, so
   `go()` returns while #main still holds the previous screen — focusing there
   finds the OLD heading and the repaint destroys it, dropping focus to <body>
   so the next Tab restarts at the skip link. One-shot, because paint() also
   runs after a write and pulling focus would throw the reader out of the card
   they are working in. */
foreach (['student', 'instructor'] as $who) {
    $j = preg_replace('~/\*.*?\*/~s', '', (string) @file_get_contents($APPS[$who]));
    t_ok((bool) preg_match('/pendingFocus = true;\s*\n\s*SCREENS\[screen\]/', $j),
        "$who: the focus move is ARMED by go() …");
    t_ok((bool) preg_match('/if \(pendingFocus\) \{\s*pendingFocus = false;/', $j),
        "$who: … and consumed by paint(), once");
}

/* ⚠️ 16px is a FLOOR: Safari on iOS auto-zooms on focus below it and does not
   zoom back out, which on a phone-first portal is the highest-frequency
   mobile defect available. */
t_ok((bool) preg_match('/\.p-field select,\s*\n\[data-portal="v2"\] \.p-field textarea \{[^}]*font: 400 16px/s', $css),
    'every form control is at least 16px, or iOS zooms the page on focus');
t_ok((bool) preg_match('/\.p-field select/', $css), '…including <select>');

/* WCAG 2.3.3 asks for non-essential motion to be disableable; a PROGRESS
   INDICATOR is the named exemption. The blanket `* { animation: none }` under
   reduced motion froze the loading spinner into an icon that says the page
   has hung. */
t_ok((bool) preg_match('/prefers-reduced-motion[^}]*\}\s*\n\s*\/\*.*?\*\/\s*\n\s*\[data-portal="v2"\] \.p-spin \{ animation:/s', $css),
    'reduced motion slows the loading spinner rather than stopping it');

/* A loading state that appears and vanishes inside 300ms reads as a glitch.
   MEASURED: a screen repaints in 15–42ms locally, and seconds on the mobile
   data these screens are built for — so the indicator is DELAYED, not
   dropped. */
$sj = preg_replace('~/\*.*?\*/~s', '', (string) @file_get_contents($APPS['student']));
t_ok((bool) preg_match('/function deferLoading\(/', $sj), 'the loading state is deferred, not painted immediately');
t_ok((bool) preg_match('/setTimeout\(function \(\) \{ main\.innerHTML = loading\(what\); \}, 300\)/', $sj),
    '…by 300ms, the floor below which an indicator is a flicker');
t_ok(!preg_match('/paint\(loading\(/', $sj), 'and no screen paints a loading state synchronously');

/* The waiver request is a FORM. `window.prompt` ignores the design system and
   the reader's text-size setting, has no persistent label, and can only
   return one string — so every request was filed under `other` while the
   server offers seven codes and the payload ships them. A queue where every
   row reads "Other approved reason" cannot be triaged. */
t_ok(!preg_match('/window\.prompt/', $sj), 'no window.prompt anywhere in the student app');
t_ok((bool) preg_match('/S\.money && S\.money\.exception_reasons/', $sj),
    'the reason codes are the SERVER\'s, read from the payload');
t_ok(!preg_match("/reason_code: 'other'/", $sj), 'and none is hardcoded');
t_ok((bool) preg_match('/aria-describedby="waiveErr-/', $sj),
    'the validation error is associated with the control that failed');
t_ok((bool) preg_match("/setAttribute\('aria-invalid', 'true'\)/", $sj),
    '…and the control is marked invalid, not only coloured');
