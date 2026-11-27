<?php
/**
 * The instructor portal v2 surface — static guards.
 *
 * `test_instructor_portal.php` covers the PROVIDERS (and asserts the refusals
 * they keep). This file covers the three files a browser actually loads:
 * `portal/instructor/index.html`, `assets/js/portal-instructor.js` and the
 * instructor block of `assets/css/portal-v2.css`.
 *
 * Static on purpose, like `test_root_pages.php` and `test_console.php`. What is
 * NOT here is geometry: target sizes, the 120px scroll reserve and the desktop
 * rail were driven in a real browser at 320/390/430/900/1440, because a test
 * asserting "min-height: 44px" appears in a stylesheet proves nothing about the
 * box a finger lands on — the rule can be overridden, and on this build one
 * was: `.i-next-acts button` fills white, so reusing that class for the
 * register's actions silently overrode `.p-primary` and produced two identical
 * outline buttons where the design has an outline and a primary.
 */
declare(strict_types=1);
t_group('instructor portal v2 (the surface)');

$ROOT = dirname(MAM_API_ROOT);
$html = (string) @file_get_contents($ROOT . '/portal/instructor/index.html');
$js   = (string) @file_get_contents($ROOT . '/assets/js/portal-instructor.js');
$css  = (string) @file_get_contents($ROOT . '/assets/css/portal-v2.css');
$prov = (string) @file_get_contents(MAM_API_ROOT . '/modules/portals/lib/instructor-portal.php');
$route= (string) @file_get_contents(MAM_API_ROOT . '/routes/instructor/portal.php');

t_ok($html !== '' && $js !== '' && $css !== '', 'the three surface files are readable');

/* ── One palette per page ────────────────────────────────────────────────── */

// main.css and portal.css own --bg/--text site-wide and portal.css reads them
// ~30 times while declaring none, so loading either beside portal-v2.css
// repaints this light surface with the warm dark set — and the surfaces v2 does
// not override keep their dark values, giving dark text on dark ground.
t_ok((bool) preg_match('~<html lang="en" data-portal="v2">~', $html),
    'the scope attribute is on <html>, or portal-v2.css is inert');
t_ok(strpos($html, 'portal-v2.css') !== false, 'portal-v2.css is loaded');
t_ok(strpos($html, 'assets/css/main.css') === false && strpos($html, 'assets/css/portal.css') === false,
    'neither warm-dark stylesheet is loaded beside it');

/* ── No inline styles: every visual decision is a named component ────────── */

$markup = preg_replace('/<!--.*?-->/s', '', $html);
preg_match_all('/\sstyle="[^"]*"/', $markup, $sm);
t_eq($sm[0], [], 'the markup carries no style attribute');
preg_match_all('/\sstyle="[^"]*"/', preg_replace('~/\*.*?\*/~s', '', $js), $js_sm);
t_eq($js_sm[0], [], 'no template in the app writes a style attribute either');

/* ── Every destination resolves ──────────────────────────────────────────── */

// A bar item or sheet row naming a screen the router does not know renders the
// home screen instead, silently — which is exactly the bug this file found in
// the ROUTE: 'report' was in MAM_IPORTAL_SCREENS, passed the unknown-screen
// check, and fell through to `default`.
preg_match_all('/data-screen="([a-z]+)"/', $html, $ds);
$linked = array_values(array_unique($ds[1]));
preg_match('/var SCREENS = \{(.*?)\};/s', $js, $sc);
preg_match_all('/(\w+):\s*render/', (string) ($sc[1] ?? ''), $known);
$routed = $known[1] ?? [];
$unrouted = array_values(array_diff($linked, $routed));
t_eq($unrouted, [], 'every data-screen the markup links to has a renderer');

foreach (['report', 'more'] as $screen) {
    t_ok((bool) preg_match("/case '" . $screen . "':/", $route),
        "the route dispatches '$screen' rather than falling through to home");
}

/* ── No dead buttons ─────────────────────────────────────────────────────── */

// CLAUDE.md is explicit about this ("Don't ship dead buttons"). Every id the
// renderers emit as an action must be reachable by the delegated handler.
foreach (['gradeSave', 'tbSend', 'reportSend', 'allPresent', 'signOut'] as $id) {
    t_ok(substr_count($js, "'#" . $id . "'") >= 1 || substr_count($js, 'id="' . $id . '"') >= 1,
        'the action #' . $id . ' is rendered');
    t_ok((bool) preg_match("/closest\('#" . $id . "'\)/", $js) || $id === 'signOut'
         || (bool) preg_match("/getElementById\('" . $id . "'\)/", $js),
        'the action #' . $id . ' has a handler');
}

/* ── The refusals, as they appear on this surface ────────────────────────── */

// Refusal 4 — no at-risk flag a student or guardian could be given. The
// provider is asserted separately; this asserts the CLIENT cannot reintroduce
// one by computing it, which is the only way it could come back.
t_ok(!preg_match('/\b(riskScore|risk_score|riskLevel|atRisk)\b/i', $js),
    'the app computes no risk score of its own');
t_ok((bool) preg_match('/There is no risk score here/', $js),
    'the Students screen states the refusal where an instructor reads it');

// Refusal 6 — never an optimistic write state. A tick painted before the server
// answers loses the record, and a register is the one thing that cannot afford it.
t_ok((bool) preg_match('/function write\(btn, req, onOk, okMsg\)/', $js),
    'one write helper, so a screen cannot invent its own success path');
t_ok(strpos($js, 'Never an optimistic tick') !== false,
    'and it says why, in the file that would otherwise be changed');

// The audio-only control is the SERVER's. A client-side flag is one devtools
// toggle away, and the instructor most likely to flip it is the one it exists for.
t_ok(!preg_match('/show_video\s*=\s*(true|false)/', $js),
    'the app never assigns show_video — it only reads what the sheet sent');
t_ok((bool) preg_match('/first && first\.show_video/', $js),
    'the player heading follows the criterion being marked, not "any criterion"');

// The three sentences that carry a refusal travel WITH their payload.
t_ok((bool) preg_match('/audio_only_notice/', $js), 'the audio-only notice is read from the payload');
t_ok((bool) preg_match('/esc\(d\.notice\)/', $js), 'the teach-back formative sentence is read from the payload');
t_ok((bool) preg_match('/esc\(p\.notice\)/', $js), 'the punctuality sentence is read from the payload');

/* ── A lateness is stated at zero, never omitted ─────────────────────────── */

t_ok((bool) preg_match('/late \' \+ plural\(p\.recorded/', $js) || (bool) preg_match('/p\.recorded/', $js),
    'a recorded lateness renders as a row');
t_ok((bool) preg_match('/money\(p\.deducted_cents\)/', $js),
    '…with its amount, which is zero — an instructor who sees nothing cannot tell whether anybody looked');

/* ── The read provider does not write ────────────────────────────────────── */

// mam_report_build() INSERTS. A GET that creates an academic record every time
// a screen is opened fills termly_reports with drafts nobody asked for and
// burns the one-report-per-slot rule on a glance.
$reportFn = strstr($prov, 'function mam_iportal_report(');
t_ok($reportFn !== false, 'the report provider exists');
t_ok(strpos((string) $reportFn, 'mam_report_build(') === false,
    'the report PROVIDER never builds a report — the POST does');
t_ok(strpos($route, 'mam_iportal_report(') !== false, 'and the route calls it');

/* ── The legacy dashboard is not retired, and both link to each other ────── */

// Same arrangement as admin/index.php beside admin/console.php. The redesign
// covers seven screens; the legacy portal serves thirteen tabs, and dropping
// six of them silently is not a redesign.
t_ok(is_file($ROOT . '/portal/instructor/classic.html'), 'the legacy dashboard is still served');
t_ok(strpos($html, '/portal/instructor/classic.html') !== false, 'the redesign links to it');
$classic = (string) @file_get_contents($ROOT . '/portal/instructor/classic.html');
t_ok(strpos($classic, '/portal/instructor/') !== false, 'and it links back');

/* ── The CSS is scoped, so it cannot leak onto the dark portals ──────────── */

$iBlock = strstr($css, 'INSTRUCTOR PORTAL — the components');
t_ok($iBlock !== false, 'the instructor block is present and labelled');
preg_match_all('/^\.[a-z][a-z0-9-]*\s*\{/m', (string) $iBlock, $unscoped);
t_eq($unscoped[0], [], 'every instructor rule is scoped under [data-portal="v2"]');
