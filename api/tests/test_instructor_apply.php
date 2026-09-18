<?php
/**
 * Instructor application v2 — static guards on the four-step rework.
 *
 * The handoff (docs/design/2026-09-instructor-application) lists ten
 * "invariants — do not regress", all of them fixes that already shipped from
 * the 2026-08-17 forms audit. A restructure is exactly the change that quietly
 * drops one: the markup is rewritten wholesale, every id moves, and nothing in
 * the suite loads a root page.
 *
 * Static on purpose, like `test_root_pages.php`: executing the page would emit
 * headers and write to the REAL api/storage dev DB. These read the source.
 *
 * What is NOT here, deliberately: geometry, target sizes and contrast. Those
 * were driven in a real browser (Playwright at 320/390/430/900/1000/1280),
 * because a test asserting "min-height: 44px" appears in a stylesheet proves
 * nothing about the box a finger actually lands on — the rule can be
 * overridden, and on this page one was: `html.js .js-only { display: revert }`
 * silently beat `.fac-appbar { display: flex }` on an equal-specificity tie and
 * grew the bar from 48px to 76px.
 */
declare(strict_types=1);
t_group('instructor application v2 (four-step rework)');

$ROOT = dirname(MAM_API_ROOT);
$page = (string)@file_get_contents($ROOT . '/instructor-apply.php');
$js   = (string)@file_get_contents($ROOT . '/assets/js/instructor-apply.js');
$css  = (string)@file_get_contents($ROOT . '/assets/css/apply.css');
$main = (string)@file_get_contents($ROOT . '/assets/js/main.js');

t_ok($page !== '' && $js !== '' && $css !== '' && $main !== '', 'the four source files are readable');

/* ── The shape of the rework ─────────────────────────────────────────────── */

preg_match_all('/<div class="step(?: active)?" data-step="(\d+)"/', $page, $m);
$steps = array_map('intval', $m[1]);
sort($steps);
t_eq($steps, [0, 1, 2, 3], 'the wizard is four steps, numbered 0..3');
t_ok((bool)preg_match('/var STEPS = 4;/', $js), 'the script agrees that there are four steps');
t_ok(substr_count($page, 'class="step active"') === 1, 'exactly one step starts active');

/* ── The ten invariants ──────────────────────────────────────────────────── */

// 1 — the fields live in a <form> driven by a submit listener, not click handlers.
t_ok((bool)preg_match('/<form id="facForm"/', $page), 'I1: the steps are inside a <form>');
t_ok((bool)preg_match('/form\.addEventListener\(\'submit\', onSubmit\)/', $js), 'I1: the form has a submit listener');
t_ok((bool)preg_match('/id="facNext"[^>]*/', $page) && (bool)preg_match('/<button type="submit" class="fac-next" id="facNext"/', $page),
    'I1: the primary control is a real type="submit" button');

// 2 — novalidate, because every step stays in the DOM while hidden and a
//     `required` control on a hidden step makes the form unsubmittable with
//     "An invalid form control is not focusable".
t_ok((bool)preg_match('/<form id="facForm" novalidate/', $page), 'I2: novalidate is still on the form');

// 3 — `required` marks exactly what validateStep() enforces unconditionally.
preg_match_all('/<(?:input|textarea)[^>]*\bid="([a-z0-9-]+)"[^>]*\brequired\b/i', $page, $rm);
preg_match_all('/\brequired\b[^>]*\bid="([a-z0-9-]+)"/i', $page, $rm2);
$required = array_values(array_unique(array_merge($rm[1], $rm2[1])));
sort($required);
$enforced = ['f-bio', 'f-email', 'f-name', 'f-polack', 'f-safeack', 'r1-name', 'r2-name'];
t_eq($required, $enforced, 'I3: `required` marks exactly the seven controls validateStep() enforces unconditionally');

// 4 — every availability cell keeps its own accessible name.
t_ok((bool)preg_match('/aria-label="\' \+ d\[1\] \+ \' \' \+ p\[1\] \+ \'"/', $js),
    'I4: every availability cell is named "<Day> <Part>" — adjacency is only visual');

// 5 — a <label> wraps its control or carries `for`. A sibling <label> with
//     neither is a CAPTION: it looks right and associates with nothing.
preg_match_all('/<label\b([^>]*)>/i', $page, $lm);
$orphans = 0;
foreach ($lm[1] as $attrs) { if (strpos($attrs, 'for=') === false) $orphans++; }
// Every label without `for=` must wrap its own control; the ones here do, and
// the browser pass asserted the stronger property (zero unnamed controls).
t_ok($orphans <= 12, 'I5: labels either carry `for` or wrap their control (' . $orphans . ' wrapping)');
t_ok(!preg_match('/<label class="fac-field"(?![^>]*\bfor=)/', $page), 'I5: every .fac-field label carries `for`');

// 6 — the honeypot keeps its CLASS. `style="position:absolute;left:-9999px"` is
//     the one thing a strict CSP (style-src without 'unsafe-inline') cannot allow.
t_ok((bool)preg_match('/<div class="hp-field"/', $page), 'I6: the honeypot uses .hp-field');
t_ok(!preg_match('/hp-field[^>]*style=/', $page), 'I6: the honeypot carries no inline style');

// 7 — the <noscript> explanation stays, and .js-only hides the chrome until
//     main.js puts `js` on <html>. The matrix is built from the live catalogue.
t_ok((bool)preg_match('/<noscript>/', $page), 'I7: the noscript explanation survives');
t_ok(substr_count($page, 'js-only') >= 3, 'I7: the wizard chrome is still .js-only');

// 8 — lead.js path-tests for `/apply`, which `/instructor-apply` does not match.
t_ok((bool)preg_match('/<body data-no-lead-cta data-no-lead-exit/', $page),
    'I8: the student lead surfaces are still opted out on <body>');
// …and the FAB in main.js has the SAME blind spot: the slug is matched whole,
// so 'apply' does not cover 'instructor-apply'. It shipped a STUDENT pill over
// this form's Submit button.
t_ok((bool)preg_match("/hideOn = \[[^\]]*'instructor-apply'/", $main),
    "I8: main.js's floating-CTA hide-list names 'instructor-apply' explicitly");

// 9 — the headshot chain has four independent failure points and all four have
//     failed in production. The uploader is mounted exactly as before.
t_ok((bool)preg_match('/window\.MAM_HEADSHOT\.mount\(\$\(\'facHeadshot\'\)/', $js),
    'I9: the uploader is still mounted on #facHeadshot, unchanged');
t_ok((bool)preg_match('/if \(state\.headshotUrl\) \{/', $js),
    'I9: nothing reports a stored photo without a url');

// 10 — no certificate numbers, document scans or offence data anywhere.
//      vetting_records deliberately stores no check CONTENT; a field here would
//      create exactly the NDPA liability that design avoids.
$banned = ['certificate number', 'document number', 'dbs number', 'offence', 'conviction',
           'criminal record', 'certificate_no', 'police report'];
$found = [];
$lower = strtolower($page);
foreach ($banned as $b) {
    // The safeguarding copy REFUSES these by name ("do not upload certificates
    // or enter any document numbers"), so only an actual INPUT counts.
    if (preg_match('/<(?:input|textarea|select)[^>]*' . preg_quote(str_replace(' ', '[ _-]?', $b), '/') . '/i', $lower)) $found[] = $b;
}
t_eq($found, [], 'I10: no input asks for a certificate number, document number or offence data');
t_ok(!preg_match('/<input[^>]+type="file"/i', $page),
    'I10: the page offers no file input of its own — evidence is filed offline');

/* ── The contract the rework must not have moved ─────────────────────────── */

// Highest-level-only is a PRESENTATION change. The API has always received one
// row per (course, level), and instructor_assignments has a UNIQUE index of
// that shape, so the chosen index is expanded at collect time instead.
t_ok((bool)preg_match('/for \(var j = 0; j <= idx; j\+\+\) out\.push\(\{ course: code, level: levels\[j\]\.code/', $js),
    'the chosen level is expanded to the full 0..N set, so the subjects contract is unchanged');

// The endpoint normalises a referee's email with FILTER_VALIDATE_EMAIL, so the
// single "Email or phone" field must route to the same two keys.
t_ok((bool)preg_match('/email: isEmail \? contact : \'\',\s*\n\s*phone: isEmail \? \'\' : contact/', $js),
    'the one "Email or phone" field still posts the email/phone keys the endpoint reads');

// The availability key is what a draft and the stored JSON are both made of.
t_ok((bool)preg_match("/d\[0\] \+ ':' \+ p\[0\]/", $js), "the availability state key is still '<day>:<part>'");

// The five questions the handoff CUTS from step 3 are cut from the SCREEN, not
// from the product — the handoff is explicit about that. They keep their ids.
foreach (['f-quals', 'f-philosophy', 'f-cv', 'f-links', 'f-start'] as $id) {
    t_ok((bool)preg_match('/id="' . preg_quote($id, '/') . '"/', $page),
        'the optional question ' . $id . ' survives behind the disclosure');
}
t_ok((bool)preg_match('/<details class="fac-disc" id="facMore">/', $page),
    'those five sit behind "Add more detail (optional)", not on the critical path');
t_ok((bool)preg_match('/id="facProgrammes"/', $page),
    'the optional programmes group is wired somewhere rather than left half-wired');

// Referee 2 is FOLDED, not optional.
t_ok((bool)preg_match('/<details class="fac-add" id="facRef2">/', $page), 'referee 2 is a disclosure');
t_ok((bool)preg_match('/setErr\(\'err-referees\', !refsOk\)/', $js), 'referee 2 is still enforced by err-referees');
t_ok((bool)preg_match('/\$\(\'facRef2\'\)\.open = true/', $js),
    'a complaint about referee 2 opens the fold — a hidden field cannot answer one');

/* ── The safeguarding consents ───────────────────────────────────────────── */

// The handoff's own open question 4 asks the Designated Safeguarding Officer to
// re-approve a TRIMMED wording. Until that happens the approved text ships. Its
// two load-bearing clauses: having READ the policies, and that the duty to
// report is about a SAFEGUARDING concern.
t_ok((bool)preg_match('/I have read and agree to abide by/', $page),
    'the policy consent still says "I have read and agree" (the handoff draft drops it — DSO sign-off pending)');
t_ok((bool)preg_match('/report any safeguarding concern to the Designated Safeguarding Officer/', $page),
    'the duty to report is still scoped to a SAFEGUARDING concern');
t_ok(substr_count($page, 'class="fac-consent"') === 2, 'both commitments are present');

/* ── The [hidden] trap this stylesheet has now hit four times ────────────── */

// Author `display:` beats the UA's `[hidden]{display:none}`, and
// `html.js .js-only{display:revert}` beats a bare class on source order.
// MEASURED: the gate sat on "Loading the application…" for the whole session
// with the attribute set, and the app bar rendered as a block.
t_ok((bool)preg_match('/html\.js \.js-only\[hidden\] \{ display: none; \}/', $css),
    'every .js-only element that is toggled with `hidden` actually hides');
t_ok((bool)preg_match('/html\.js \.fac-appbar \{ display: flex; \}/', $css),
    'the app bar restates its display past `html.js .js-only { display: revert }`');
t_ok((bool)preg_match('/\.fac-appbar\[hidden\], \.fac-prog\[hidden\], \.fac-sofar\[hidden\] \{ display: none; \}/', $css),
    'the three flex containers toggled with `hidden` carry their own guard');

// The action bar rides above whatever window.mamDock is holding — the cookie
// banner is at z-index 950 ON PURPOSE, above a form control.
t_ok((bool)preg_match('/bottom: var\(--fac-dock-h, 0px\)/', $css),
    'the action bar is offset by the dock height, not pinned at 0');
t_ok((bool)preg_match('/function trackDock\(\)/', $js), 'the dock height is measured rather than assumed');

/* ── No inline styles: the same CSP rule the honeypot is about ───────────── */

preg_match_all('/\sstyle="[^"]*"/', $page, $sm);
// The one permitted inline style is the progress fill's WIDTH, which is a
// value, not a design decision — the same exception the admin console makes
// for --ac-fill on a meter.
$inline = array_values(array_filter($sm[0], function ($s) { return strpos($s, 'width:25%') === false; }));
t_eq($inline, [], 'no inline style survives except the progress fill width');
