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
// A bar's width is DATA and comes from the mark, so it is applied from
// `data-pct` after paint — the same mechanism the guardian portal uses.
t_ok((bool) preg_match('/function paintBars\(\)/', $js),
    'bar widths are applied from data-pct, not written into a style attribute');

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
