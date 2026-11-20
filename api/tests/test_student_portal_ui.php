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

/* ── Two more the audit only found once the guardian screens had data ───── */

/* ⚠️ `viewport-fit=cover` is load-bearing, not decoration. portal-v2.css reads
   `env(safe-area-inset-bottom)` in four places — the bottom bar's height and
   padding, the sheet's foot, the toast's offset — and those insets resolve to
   0 unless the viewport covers the display, so iOS letterboxes the page into
   the safe area instead. The guardian portal was missing it and rendered its
   bar differently from its two siblings on any notched device. They share one
   stylesheet; they have to share the precondition it assumes. */
t_group('the v2 portals — viewport and badge');
foreach ($MARKUP as $who => $file) {
    $m = (string) @file_get_contents($file);
    preg_match('/<meta name="viewport" content="([^"]*)"/', $m, $vp);
    t_ok(isset($vp[1]) && strpos($vp[1], 'viewport-fit=cover') !== false,
        "$who: the viewport covers the display, so env(safe-area-inset-*) is not 0");
    /* A viewport that blocks pinch zoom is a 1.4.4 failure, and it is the
       usual wrong "fix" for the iOS form auto-zoom that 16px solves properly. */
    t_ok(isset($vp[1]) && strpos($vp[1], 'user-scalable=no') === false
         && strpos($vp[1], 'maximum-scale') === false,
        "$who: …and does not block pinch zoom");
}

/* On mobile `.p-badge` is a DOT: an 8px circle with a digit in it renders as a
   smudge, and MEASURED on the guardian bar the digit came out at 1.04:1
   because the rule sets no colour of its own. The number travels in the
   aria-label (the instructor portal already did this) and the desktop rule
   turns the same element into a real pill where the text is wanted. */
t_ok((bool) preg_match('/\.p-badge \{[^}]*font-size: 0;[^}]*color: transparent;/s', $css),
    'the mobile badge paints no text — it is a dot, not a counter');
t_ok((bool) preg_match('/\[data-portal="v2"\] \.p-badge \{ position: static;[^}]*font: 800 12px\/22px/s', $css),
    '…and the desktop rule restores it as a pill that does');
$gj = preg_replace('~/\*.*?\*/~s', '', (string) @file_get_contents($APPS['guardian']));
foreach (['financeBadge', 'msgBadge', 'consentBadge'] as $b) {
    t_ok((bool) preg_match('/' . $b . "[\s\S]{0,240}setAttribute\('aria-label'/", $gj),
        "#$b carries an accessible name — a dot cannot be read aloud");
}

/* ── The styling pass (2026-09-21) — docs/HANDOFF.md §4s-2 ──────────────── */

t_group('the v2 portals — styling invariants');

/* ⚠️ A SOLID semantic state that carries a word needs the fill trio, not a
   text or bar token borrowed for the job. MEASURED with white on them,
   `--good` is 4.36:1 and `--warn-bar` 3.92:1 — two of the three register
   marks failed, from one ad-hoc pick each. The pair INVERTS in dark mode:
   there the fills are light and the ink is dark. */
foreach (['--fill-good', '--fill-warn', '--fill-bad', '--on-fill'] as $tok) {
    t_ok(substr_count($css, $tok . ':') >= 2,
        "$tok is defined for BOTH themes — the pair inverts in dark mode");
}
t_ok(!preg_match('/\.i-marks button\[aria-pressed="true"\][^{]*\{ background: var\(--(good|warn|warn-bar|bad-line)\)/', $css),
    'no register mark is filled with a text or bar token');
t_ok((bool) preg_match('/\.i-marks button\[aria-pressed="true"\] \{ color: var\(--on-fill\)/', $css),
    'and the word on them is the fill trio\'s own foreground');

/* A foot is prose, not a row, so it takes the card's gutter back — inside a
   flush card it had none and sat 1px from the wall with the rounded corner
   drawn through it. */
t_ok((bool) preg_match('/\.p-card\.is-flush > \.p-card-foot \{ padding:/', $css),
    'a card foot is inset even inside a flush card');

/* ⚠️ `flex: 1 1 0` so the main column never FORCES a wrap. A 55% basis fixed
   the 320px crush and regressed 390, because flexbox wraps on the sum of the
   bases rather than the final sizes. The wrap is asked for explicitly, and
   only at the width that needs it. */
t_ok((bool) preg_match('/\.p-row-main \{ flex: 1 1 0;/', $css),
    'the row main column never forces a wrap');
t_ok((bool) preg_match('/@media \\(max-width: 359\\.98px\\) \\{\\s*\\[data-portal="v2"\\] \\.p-row > \\.p-chip \\{ flex-basis: 100%/', $css),
    '…and the chip takes its own line only below 360');

/* The payment methods are spans that say what the Pay button accepts. At 44px
   with a border and the button radius they were indistinguishable from the
   real buttons above them. */
t_ok(!preg_match('/\.p-methods span \{[^}]*min-height: 44px/s', $css),
    'the payment methods do not look like buttons');

/* The two ledgers are not merged into one figure. */
$slib = preg_replace('~/\*.*?\*/~s', '', (string) @file_get_contents(MAM_API_ROOT . '/lib/student-portal.php'));
foreach (['charges_cents', 'fees_cents'] as $k) {
    t_ok(strpos($slib, "'" . $k . "'") !== false,
        "mam_sportal_owed() reports $k separately — a \$0.00 headline over an overdue invoice is one figure answering two questions");
}

/* ⚠️ Focus lands on the HEADING. `querySelector('.p-back, .p-h1')` returns the
   earlier match in DOCUMENT order regardless of the selector's order, and the
   back control renders above the heading — so the focus move landed on a real
   <button> and painted a ring on every screen change, in all three portals. */
foreach ($APPS as $who => $file) {
    $j = preg_replace('~/\*.*?\*/~s', '', (string) @file_get_contents($file));
    if (strpos($j, 'p-back') === false) continue;
    t_ok(!preg_match("/querySelector\('\.p-back, \.p-h1'\)/", $j)
         && !preg_match("/querySelector\('\.p-back,/", $j),
        "$who: the focus target is not chosen by document order");
}

/* The three teach-back self-checks are a PROMPT, never appended to the body —
   the textarea is prefilled with the last submission, so appending compounded
   on every resubmit. */
t_ok(!preg_match("/body: body \+ \(ticked/", $sj), 'the self-checks are not appended to the teach-back');

/* ── The 11px .p-label exception, and the only thing keeping it honest ───────
   portal-v2.css sets a 12px floor for running text per the design's §4, and
   grants .p-label one documented exception at 11px: a tracked 800-weight
   all-caps section heading of a few words. The exception is defensible only
   while that last clause is true. At 11px, 800 weight, all-caps and 0.14em
   tracking a SENTENCE is the least legible thing on the page, and it lands on
   the audience §4 names as the reason for the floor.

   It had already drifted: the guardian consent panel headed itself "What you
   can see, and what stays theirs" — eight words and a comma — as a .p-label.
   A browser sweep found it; this keeps it found. Six words is the line: it
   admits the real headings ("Not enough evidence yet", "1 · The instructor or
   the office") and refuses anything that reads as prose. A label that wants to
   be longer wants to be a .p-title. Only tokens carrying a LETTER count — the
   "1 ·" in "1 · The instructor or the office" is an ordinal marker, not
   language, and counting the numeral and the separator as words refuses a
   heading that reads as five. */
foreach ($APPS as $PL_who => $PL_file) {
    $PL_src = (string) @file_get_contents($PL_file);
    if ($PL_src === '') continue;
    // Only literal strings — an interpolated label is data, not a heading we can measure.
    if (!preg_match_all('/class="p-label[^"]*">([^\'"<+]{1,120})</', $PL_src, $PL_m)) continue;
    foreach (array_unique($PL_m[1]) as $PL_text) {
        $PL_text = trim($PL_text);
        if ($PL_text === '') continue;
        $PL_words = array_filter(preg_split('/\s+/', $PL_text), fn($w) => preg_match('/\pL/u', $w));
        t_ok(count($PL_words) <= 6,
            "$PL_who: .p-label \"$PL_text\" is a heading, not a sentence "
            . '(11px all-caps at 0.14em tracking — make it a .p-title)');
    }
}

t_group('portal top bar — names the screen, not the person (2026-11-20)');

/* The bar used to read "Tola Teacher · Instructor · DOMI Term" on every
   screen, with the screen's own title drawn again directly underneath — two
   headers before the register. The bar now carries the screen's title through
   ONE helper in the shared kit; the page heading stays in the document,
   visually hidden, as the screen reader's heading and the focus target. */
$TB_kit = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/portal.js');
t_ok(str_contains($TB_kit, 'identity: identity, topTitle: topTitle'), 'the shared kit exports the top-bar helper');
t_ok(str_contains($TB_kit, "h.classList.add('p-sr')") && !preg_match('/h\.remove\(\)|removeChild\(h\)/', $TB_kit),
    'the page heading is hidden visually, never removed — it is still the heading and the focus target');
foreach (['instructor', 'student', 'guardian'] as $TB_p) {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . "/assets/js/portal-$TB_p.js");
    t_ok((bool) preg_match('/main\.innerHTML = html;\s*\n\s*if \(P && P\.topTitle\) P\.topTitle\(main\);/', $src), "the $TB_p portal names each screen in the bar as it paints");
    t_ok(!str_contains($src, "\$('#whoName').textContent"), "the $TB_p portal sets the person through the kit, not by writing the bar itself");
}

t_group('portal navigation — each screen is listed once (2026-11-20)');

/* On a desktop the More sheet renders inline in the rail beside the tab bar,
   so a screen in both was listed twice under two names: the instructor's
   "Marking" and "Grade entry", the student's "Learn" and "Where I stand", the
   guardian's "Reports" and "Reports & results". The top bar's icon buttons
   (clearance, fees, messages) are shortcuts, not entries, and are not counted. */
foreach (['instructor', 'student', 'parent'] as $NV_p) {
    $html = (string) file_get_contents(dirname(__DIR__, 2) . "/portal/$NV_p/index.html");
    preg_match_all('/<button\b(?![^>]*class="p-icon)[^>]*\bdata-screen="([a-z0-9-]+)"/', $html, $m);
    $dupes = array_keys(array_filter(array_count_values($m[1]), static fn($n) => $n > 1));
    t_eq($dupes, [], "the $NV_p portal lists no screen twice");
}
