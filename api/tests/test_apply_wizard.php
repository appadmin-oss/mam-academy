<?php
/**
 * The student wizard v2 — one question per screen.
 *
 * Source: docs/design/2026-09-site-chrome Part 2 (+ BUILD-NOTES-apply.md).
 * Step 1 asked seven questions and showed 38 choice cards on one ~4,600px
 * scroll, and level and expression appeared and disappeared with the programme
 * so the scroll length changed under the applicant mid-answer.
 *
 * Static, like `test_apply_form.php` and `test_instructor_apply.php`: executing
 * a root page emits headers and writes the REAL api/storage dev DB. Geometry is
 * NOT asserted here — the boxes were driven in Playwright at 320/390/430/768/
 * 1024/1280/1440, and those numbers are in the build notes. A rule saying
 * `padding:0` proves nothing about the box; on this page main.css's own
 * `section { padding: clamp(56px,8vw,96px) … }` silently put 96px above every
 * question, which no stylesheet grep would have found.
 *
 * The eleven "invariants — do not regress" from the handoff are the spine of
 * this file; `test_apply_form.php` still holds the ones about the form element
 * itself and is not duplicated here.
 */
declare(strict_types=1);
t_group('apply wizard v2 — one question per screen');

$ROOT  = dirname(MAM_API_ROOT);
$page  = (string)@file_get_contents($ROOT . '/apply.php');
$js    = (string)@file_get_contents($ROOT . '/assets/js/apply.js');
$css   = (string)@file_get_contents($ROOT . '/assets/css/apply.css');
$val   = (string)@file_get_contents($ROOT . '/api/lib/validation.php');
$strip = static fn(string $h): string => (string)preg_replace(['~<\?php.*?\?>~s', '~<!--.*?-->~s'], '', $h);
$pageB = $strip($page);
// Strip BOTH comment forms. A `//` line explaining a removed branch names it,
// so an absence assertion against the raw file reads the explanation as the bug.
$jsB   = (string)preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $js);

t_ok($page !== '' && $js !== '' && $css !== '', 'the three wizard sources are readable');

/* ── The shape of the split ──────────────────────────────────────────────── */

preg_match_all('/<section class="a-sub[^"]*" data-sub="(\d+)" data-q="([a-z]+)"/', $page, $m, PREG_SET_ORDER);
$byStep = [];
foreach ($m as $hit) { $byStep[$hit[2]] = (int)$hit[1]; }
t_eq(count($m), 12, 'twelve screens across the five steps');
t_eq(implode(',', array_keys($byStep)),
    'mode,pathway,level,expression,discipline,program,minors,who,address,guardian,background,review',
    'and they are the questions the handoff names, in order');
t_ok(substr_count($page, 'class="a-sub active"') === 4,
    'exactly one screen starts active in each of the four question steps');

/* ── The one deliberate refusal ──────────────────────────────────────────── */

// The handoff asks for "one searchable list of 14 rows" replacing BOTH the
// focus-area select and the 14 discipline cards, on the reading that they ask
// "two versions of what will you study". They do not.
t_ok(isset($byStep['discipline']) && isset($byStep['program']),
    'the major and the focus area are SEPARATE screens: the major is an instrument '
    . '(piano, clarinet) and the focus area is a career (film scoring, music business)');
t_ok((bool)preg_match("/\\\$allowedPrograms = \[/", $val),
    '…and `program` is a stored column the server validates against its own allowlist, '
    . 'so there is nothing on the fourteen to map the eleven onto');
// Both are searchable rows, which is the part of the instruction that fits.
t_eq(substr_count($page, 'data-filter="majorRows"'), 1, 'the major list is searchable');
t_eq(substr_count($page, 'data-filter="focusRows"'), 1, 'and so is the focus list');
t_eq(substr_count($page, '<input type="radio" name="discipline"'), 14, 'fourteen majors');
t_eq(substr_count($page, '<input type="radio" name="program"'), 11, 'eleven focus areas');
t_eq(substr_count($page, 'class="a-group"'), 2,
    'the Creation & Production / Leadership taxonomy survives as group heads — the '
    . 'handoff\'s own open question 5 asks whether it should');

/* ── Skipping works in BOTH directions ───────────────────────────────────── */

t_ok(str_contains($js, 'function skipSub('), 'a screen that does not apply is SKIPPED');
t_ok((bool)preg_match('/skipSub\(0, \'level\',\s+!levelled\)/', $js)
  && (bool)preg_match('/skipSub\(0, \'expression\',\s+!needsExpr\)/', $js)
  && (bool)preg_match('/skipSub\(0, \'minors\',\s+!hasMinors\)/', $js),
    'by the same predicates that drove substepLevel[hidden] before');
t_ok(str_contains($js, "skipSub(1, 'guardian', !isKid)"),
    'and the guardian screen is skipped for an adult applicant');
t_ok(str_contains($js, 'function liveSubs('),
    'navigation walks the LIVE screens, so going back from the major screen with '
    . '`crash` chosen lands on programme, not on an empty level screen');
t_ok((bool)preg_match('/\.a-sub\[hidden\]\{display:none!important\}/', $css),
    'a skipped screen stays hidden even where the desktop shows the whole step at once');

/* ── The progress line ───────────────────────────────────────────────────── */

t_ok(str_contains($js, 'var BANDS = [[0, 40], [40, 60], [60, 80], [80, 100], [100, 100]]'),
    'the fill advances WITHIN a step — a bar that sits still for six screens teaches '
    . 'people the form is not progressing');
t_ok(str_contains($js, 'function subTotal()'),
    'and until a programme is chosen it assumes the LONGEST path');
t_ok(str_contains($jsB, "if (current === 0 && !data.pathway) return subsOf(0).length"),
    '…because computing it from the live set made the bar run 9% → 18% → 15% the moment '
    . 'Certified Musician added three screens, and a progress bar that goes backwards is '
    . 'the plainest way a form can tell somebody it is lying');

/* ── Announcements and focus ─────────────────────────────────────────────── */

t_ok(str_contains($page, 'id="applyAnnounce"') && str_contains($page, 'aria-live="polite"'),
    'every screen change is announced — moving focus alone never says which question of how many');
t_ok(str_contains($js, 'function announceScreen()') && str_contains($js, 'function focusScreen()'),
    'and both the announcement and the focus move are wired');
t_ok(str_contains($js, 'function scrollToScreen()'),
    'the viewport moves too: advancing from the fourteen-row major list left the applicant '
    . 'scrolled two thirds down a list they had never seen');

/* ── Validation is per QUESTION now ──────────────────────────────────────── */

t_ok(str_contains($js, 'var QRULES = {') && str_contains($js, 'function validateSub('),
    'the rules are addressable one at a time');
t_ok(str_contains($js, 'function validateStep(idx) {')
  && (bool)preg_match('/liveSubs\(idx\)\.forEach\(function \(el\) \{ runRule/', $js),
    '…and validateStep still runs every rule for the step, which a desktop fold-back '
    . 'and a server-side rejection both need');
foreach (['mode', 'pathway', 'level', 'expression', 'discipline', 'program'] as $q) {
    t_ok((bool)preg_match('/\b' . $q . ':\s+function \(c, check, need\)/', $js), "there is a rule for `$q`");
}
t_ok((bool)preg_match("/sub: 'address'/", $js),
    'a server rejection names the SCREEN as well as the step — a rejected postcode used to '
    . 'land the applicant on the first screen of step 2 and leave them to find it');

/* ── Review ──────────────────────────────────────────────────────────────── */

t_ok(str_contains($js, 'class="a-rev-edit"') && str_contains($js, "data-sub=\"' + q + '\""),
    'each review row carries its own Edit, naming the question');
t_ok(str_contains($js, 'function chosen(name, fallback)'),
    'and the value is read off the row the applicant chose rather than title-cased from the '
    . 'token — `drums` is "Drums & Percussion" on screen, and a review that renames an answer '
    . 'invites a correction to something already right');
// The $5 is stated BEFORE the consents. A fee disclosed under a box you have
// already ticked is disclosed too late to inform the tick.
$due = strpos($page, 'class="a-due"');
$con = strpos($page, 'class="a-consents"');
t_ok($due !== false && $con !== false && $due < $con,
    'the price is stated BEFORE the consents');
t_ok(str_contains($page, 'id="policySignName"') && str_contains($page, 'id="policySignDate"'),
    'the typed signature and its date stay — a legal artefact, not chrome');
t_ok(str_contains($page, '<details class="a-disc a-disc-quiet" id="policyBlock">'),
    'the policy excerpt collapses behind the consent it belongs to — the full version is '
    . 'emailed on submit and a scrollable excerpt in a phone form is read by nobody');

/* ── Invariants carried over from the 2026-08-17 forms audit ─────────────── */

t_ok((bool)preg_match('/<form id="applyForm"[^>]*novalidate/', $page),
    'I2: novalidate stays — splitting step 1 into seven screens INCREASES the number of '
    . 'hidden required controls, so it matters more after the change, not less');
t_ok(str_contains($page, 'id="hsStatus"') && str_contains($page, 'role="status"'),
    'I4: #hsStatus stays in the DOM');
t_ok(str_contains($js, "var hsDisc = document.getElementById('headshotDisc')"),
    '…and the rule OPENS its disclosure on failure: an error written into a collapsed '
    . '<details> is an error nobody reads');
t_ok(!(bool)preg_match('/<input\b[^>]*id="city"[^>]*\brequired\b/', $page),
    'I3: city is still NOT required — the wizard does not validate it');
t_ok(str_contains($page, '<noscript>'),
    'I7: the <noscript> explanation is there — without JS the wizard shows one question per '
    . 'step and nothing advances it');
// $jsB, not $js: the comment explaining the removal necessarily NAMES the
// branch it removed, so a bare str_contains() reads the explanation as the bug.
t_ok(str_contains($pageB, 'id="headshotMount"') && !str_contains($jsB, 'hasCloudinaryConfigured'),
    'I10: the headshot uploader is untouched, and no branch reports success without a stored URL');

/* ── The shell ───────────────────────────────────────────────────────────── */

t_ok((bool)preg_match('/\.a-sub\{[^}]*padding:0/', $css),
    'each screen states `padding:0`: main.css gives EVERY <section> on the site '
    . '`clamp(56px,8vw,96px)`, which put 96px of dead space above every question');
t_ok((bool)preg_match('/\.a-bar\{position:fixed/', $css),
    'the action bar is FIXED, not sticky — it is the form\'s last child, so `sticky bottom` '
    . 'held it up against nothing and it sat 600px below the fold');
t_ok((bool)preg_match('/\.a-bar\{[^}]*bottom:var\(--a-dock-h,0px\)/', $css)
  && str_contains($js, "root.style.setProperty('--a-dock-h'"),
    '…and it rides above window.mamDock, which is at z-index:950 deliberately because a '
    . 'consent notice must not be covered');
t_ok((bool)preg_match('/html\.js \.a-appbar,html\.js \.a-prog\{display:none\}/', $css),
    'the desktop hides the app bar AND the progress line through `html.js`: a bare '
    . '`.a-prog` is (0,1,0), the same as `.js-only`, so source order would decide it');
t_ok((bool)preg_match('/\n\.a-appbar\{align-items/', $css) && !(bool)preg_match('/\n\.a-appbar\{display:flex/', $css),
    '…and the base rules carry no `display`, or they un-hide the wizard chrome for a '
    . 'visitor with no JS at all');
t_ok((bool)preg_match('/@media \(min-width:1024px\)\{[^@]*\.step\.active \.a-sub\{display:flex\}/s', $css),
    'on a desktop the questions fold back onto one page');
t_ok(str_contains($js, 'function foldedBack()') && str_contains($js, "'(min-width:1024px)'"),
    '…and the JS predicate names the same breakpoint the stylesheet does');
t_ok(!str_contains($pageB, 'class="summary-panel"'),
    'the collapsed summary panel is gone — it hid the running total behind a tap at every '
    . 'screen size; it is a 320px column on a desktop now');
t_ok(str_contains($page, 'id="aSide"') && str_contains($page, 'class="a-stepline'),
    'replaced by the summary column with its five step rows');
t_ok((bool)preg_match('/\.a-side\{[^}]*align-self:stretch/', $css),
    'which opts back out of the grid\'s `align-items:start`, or its left rule stops partway down');

/* ── Browser Back maps to the in-page control ────────────────────────────── */

t_ok(str_contains($js, 'function pushScreen()') && str_contains($js, "on(window, 'popstate'"),
    'browser Back unwinds the same screens the back control does');
t_ok(str_contains($jsB, 'if (pushedScreens <= 0) return;'),
    '…and back past the first question LEAVES the wizard rather than trapping the visitor');

/* ── Contract points that must not move ──────────────────────────────────── */

t_ok(str_contains($js, "localStorage.getItem('mam.apply.draft')"),
    'the draft key is unchanged, so a draft written before the split still loads');
t_ok(str_contains($js, "typeof data.subStep === 'number'"),
    '…and an old draft with no subStep resumes at the first screen of its step');
foreach (['pathway', 'mode', 'discipline', 'level', 'expression'] as $k) {
    t_ok(str_contains($js, "'" . $k . "'"), "the ?$k= prefill allowlist still names $k");
}
