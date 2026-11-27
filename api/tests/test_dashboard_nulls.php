<?php
/**
 * Dashboard upgrade — "not measured yet" is not "zero".
 *
 * The bug this pins was one line: `$mastery = $gpa['avg_score'] !== null ? … : 0.0;`
 * in parents.php, plus `mam_checkin_attendance_rate()` returning 0.0 when there
 * was nothing to average. Together they told a guardian opening a brand-new
 * child's page that the child was at 0% attendance and 0% mastery, behind two
 * RED lights — and sent the same parent a weekly email reading
 * "Attendance: 0%".
 *
 * The rest of the system already knows better: mastery.php returns null at
 * level 0 for "not assessed", and upper-admin.php sorts a null group last
 * rather than first because "an unassessed group is not a failing one". The
 * guardian surface was the one place that rule was being undone, which is the
 * worst possible place for it — it is the screen a paying parent judges the
 * academy by.
 */
declare(strict_types=1);

t_group('dashboards — a light distinguishes "not measured" from "failing"');

t_eq(mam_parent_light(null), 'none', 'no measurement gives its own state, not the bottom of the scale');
t_eq(mam_parent_light(0.0),  'red',   'a real zero is still red — the fix must not hide genuine failure');
t_eq(mam_parent_light(49.9), 'red',   'below 50 is red');
t_eq(mam_parent_light(50.0), 'amber', '50 is amber');
t_eq(mam_parent_light(79.9), 'amber', 'below 80 is amber');
t_eq(mam_parent_light(80.0), 'green', '80 is green');
t_eq(mam_parent_light(100.0),'green', 'and the top of the scale is green');

// The colour has to have a text equivalent, or the dot is a colour-only signal.
t_eq(mam_parent_light_label('none'), 'Not measured yet', 'the absent state says so in words');
t_ok(mam_parent_light_label('red', 'Attendance') !== mam_parent_light_label('none', 'Attendance'),
    'and never shares a sentence with "behind"');
foreach (['green', 'amber', 'red', 'none'] as $l) {
    t_ok(trim(mam_parent_light_label($l)) !== '', "the '$l' light carries a label (WCAG 1.4.1 — colour is not the only channel)");
}

t_group('dashboards — a student with nothing measured yet');

// A student with no cohort, no session and no grade: every engine should say
// "nothing to report", and none of them should say "zero".
$dnU = mam_db_insert('users', [
    'email' => 'nulldash-' . substr(md5((string) mt_rand()), 0, 8) . '@test.invalid',
    'name' => 'Brand New Student', 'role' => 'student', 'status' => 'active',
    'password_hash' => password_hash('x', PASSWORD_BCRYPT), 'created_at' => date('Y-m-d H:i:s'),
]);

t_ok(mam_checkin_attendance_rate($dnU) === null,
    'attendance is NULL with no sessions — 0.0 could not be told apart from "attended none of twelve"');
$dnGpa = mam_lms2_student_gpa($dnU);
t_ok($dnGpa['avg_score'] === null, 'the grading engine already reported "not assessed" correctly');

$dnW = mam_parent_weekly_status($dnU);
t_ok($dnW['attendance_rate'] === null, 'the guardian payload keeps attendance null rather than flattening it');
t_eq($dnW['attendance'], 'none', 'so the attendance light is neutral, NOT red');
t_eq($dnW['mastery_light'], 'none', 'and so is mastery');
t_eq($dnW['attendance_label'], 'Not measured yet', 'with the reason stated in text');
t_eq($dnW['mastery_label'], 'Not enough assessed work yet', 'and mastery names its own absence');

/* The `mastery` key is GONE, not null. It carried the raw gradebook mean, and
 * "null when there is nothing" was only half the problem: once there WAS
 * something, every consumer printed the mean as a percentage, which is exactly
 * the thin composite the coverage floor exists to withhold. Absent rather than
 * null so a client still reading it fails visibly instead of rendering 0%. */
t_ok(!array_key_exists('mastery', $dnW),
    'the raw gradebook mean is absent from the guardian payload, not merely nulled');
t_ok(array_key_exists('mastery_band', $dnW), 'the band replaces it');

t_group('dashboards — a student who genuinely is behind still reads as behind');

// The fix must not become a way of hiding a real problem: a measured zero is
// still red, and that is the whole point of keeping the two apart.
t_eq(mam_parent_light(0.0), 'red', 'a measured 0% is red');
t_ok(mam_parent_light_label('red', 'Attendance') === 'Attendance is behind',
    'and says so plainly, in the subject the caller names');

t_group('dashboards — the client renders the absent state, and never as 0%');

/* The guardian portal was REBUILT from the design package, so these assertions
 * moved with it: the invariant is that an absent measurement reads as an absence
 * and never as a low score, and it is now carried by `.p-kpi b.is-none` plus the
 * words in the tile, not by a coloured dot. The traffic light itself is gone from
 * the guardian surface — the design replaced it with a BAND, and a band cannot be
 * mistaken for a percentage the way a colour could. */
$dnHtml = file_get_contents(__DIR__ . '/../../portal/parent/index.html');
$dnJs   = file_get_contents(__DIR__ . '/../../assets/js/portal-guardian.js');
$dnCss  = file_get_contents(__DIR__ . '/../../assets/css/portal-v2.css');

t_ok(strpos($dnCss, '.p-kpi b.is-none') !== false,
    'the "not measured" state has its own presentation, distinct from a figure');
t_ok(preg_match('/\.p-kpi b\.is-none \{[^}]*font-variant-numeric: normal/', $dnCss) === 1,
    'and it drops tabular-nums — a dash in a digit-width cell reads as a minus sign');
t_ok(strpos($dnJs, 'Not enough yet') !== false,
    'a child below the mastery coverage floor reads "Not enough yet" rather than a number');
t_ok(strpos($dnJs, "is-none") !== false, 'through the absent-value class, not a zero');
t_ok(strpos($dnJs, "att == null ? '—' :") !== false,
    'attendance with nothing to measure renders an em dash, never 0%');
t_ok(strpos($dnCss, 'tabular-nums') !== false,
    'and real figures ARE tabular, so they stop jittering as they update');

/* Colour is never the only channel (WCAG 1.4.1). The bars carry a grade WORD
 * beside them and an aria-label naming the competency, the grade and the figure. */
t_ok(strpos($dnJs, 'role="img" aria-label="') !== false, 'a competency bar names itself to assistive technology');
t_ok(strpos($dnJs, 'p-crit-grade') !== false, 'and prints the grade in words beside it');

/* The unevidenced competencies are VISIBLE, NAMED and inside a dashed panel.
 * ⚠️ Assert the meaning, not one sentence. This pinned the literal string
 * "Not counted as a zero." and failed when the eight rows became one named
 * set with the caveat stated once — a change that made the guarantee MORE
 * visible, not less (MEASURED: 563px of repetition down to 281px, all eight
 * names still on the page). A guard on exact copy fails the day the copy
 * improves; the phrase below is the part that carries the promise. */
t_ok(strpos($dnJs, 'p-insufficient') !== false, 'an unassessed competency sits in a visible panel');
t_ok(strpos($dnJs, 'counted as a zero') !== false, 'and says in words that it is not a zero');
t_ok(strpos($dnJs, 'p-none-list') !== false, '…with every unassessed competency NAMED, not just counted');
t_ok(strpos($dnCss, 'border: 1px dashed') !== false, 'drawn dashed rather than as a low score');

t_group('dashboards — the weekly email does not claim a rate that does not exist');

$dnMail = file_get_contents(__DIR__ . '/../modules/learning/lib/academic-emails.php');
t_ok(strpos($dnMail, 'no sessions yet') !== false,
    'a child with no sessions is described, not scored — the email said "Attendance: 0%"');
t_ok(strpos($dnMail, '$att === null') !== false, 'branching on the null the engine now returns');

t_group('dashboards — marketing spacing was leaking into the data tool');

/**
 * Every portal panel is a `<section class="panel">`, and main.css carries
 * `section,.section{padding:clamp(56px,8vw,96px) …}` for the marketing pages.
 * portal.css set no padding on `.panel`, so the marketing rule won and every
 * dashboard panel in all three portals wrapped its content in up to 96px of
 * vertical padding — a guardian's child card measured 343px tall around 149px
 * of content. Measured before and after: 343px → 187px with nothing removed.
 */
$dnPortalCss = file_get_contents(__DIR__ . '/../../assets/css/portal.css');
$dnMainCss   = file_get_contents(__DIR__ . '/../../assets/css/main.css');

t_ok(preg_match('/(^|\})section,\.section\{padding:clamp\(/m', $dnMainCss) === 1,
    'main.css still applies section padding for the marketing pages (the rule this has to beat)');
t_ok(preg_match('/\.panel\{[^}]*padding:0[^}]*\}/', $dnPortalCss) === 1,
    '.panel sets its own padding, so a <section class="panel"> does not inherit the marketing spacing');
t_ok(preg_match('/\.panel-body\{padding:0\}/', $dnPortalCss) === 1,
    '.panel-body stays at 0 — most callers wrap their own padded content and would double-pad');

t_group('dashboards — accent colours that carry words use the text-safe token');

// `--gold-bright` is a FILL. As text on the light panel surface it measures
// 2.94:1, which the stylesheet's own comment already documented — the panel
// action and the inline emphasis were simply never converted.
t_ok(strpos($dnPortalCss, '.panel-head .panel-action{font-size:0.82rem;font-weight:700;color:var(--gold-text)}') !== false,
    'the panel action uses --gold-text (was 2.94:1 on every panel header in all three portals)');
t_ok(strpos($dnPortalCss, '.portal-hello p strong,.portal-hello strong{color:var(--gold-text)}') !== false,
    'and so does sentence emphasis — the figures in "You teach 0 classes" were the least legible part of the line');
t_ok(strpos($dnPortalCss, 'tabular-nums') !== false, 'table figures are tabular so they stop shifting as they update');

// (The legacy admin dashboard's pill and checkbox checks went with that
// dashboard on 2026-11-19; the console's palette is held by test_console.php.)

t_group('dashboards — empty panels offer something to do');

$dnStudent = file_get_contents(__DIR__ . '/../../portal/student/classic.html');
$dnParent  = file_get_contents(__DIR__ . '/../../portal/parent/index.html');
t_ok(strpos($dnStudent, 'function empty(icon, text, action)') !== false,
    'the shared empty state takes an action');
t_ok(strpos($dnStudent, "{ href: '/apply', label: 'Start an application' }") !== false,
    'and "you have not applied yet" now links to the form — it read "start one" with nothing to click');
t_ok(strpos($dnStudent, 'No applications yet — start one.') === false,
    'the dead-end wording is gone');
/* Same invariant, new markup: a guardian with no linked child is told who links
 * one, rather than being left on an empty screen with nothing to do. */
$dnParentJs = file_get_contents(__DIR__ . '/../../assets/js/portal-guardian.js');
t_ok(strpos($dnParentJs, 'The academy office links a') !== false,
    'a guardian with no linked child is told how a child gets linked, not just that none is');
t_ok(strpos($dnParentJs, 'Contact the academy office') !== false,
    'and there is a route to the office rather than an instruction to find one');

t_group('dashboards — a progress bar with no denominator does not claim 0%');

t_ok(strpos($dnStudent, "var attPct = prog.attendance_total ? Math.round(prog.attendance_present / prog.attendance_total * 100) : null;") !== false,
    'attendance with no sessions is null, not 0');
t_ok(strpos($dnStudent, "'No sessions yet'") !== false, 'and the bar says why it is empty');
t_ok(strpos($dnStudent, "0 of 0 sessions present") === false,
    'the KPI card said "No sessions yet" while the bar beneath it said "0 of 0 sessions present" about the same fact');

t_group('dashboards — the sign-in page names everyone who signs in');

$dnLogin = file_get_contents(__DIR__ . '/../../portal/login.html');
t_ok(stripos($dnLogin, 'guardians') !== false,
    'guardians have their own portal and were not listed on the page they sign in from');
t_ok(strpos($dnLogin, 'students, instructors and admins') === false, 'the old sentence is gone');

t_group('dashboards — every page requests the same portal assets');

/**
 * Naming the stale version here dates badly — and worse, a blanket
 * find-and-replace across the repo rewrote this very assertion, so the test
 * that guards the cache buster was itself silently retargeted at the current
 * value and failed. The durable property is not "not the old one" but "all of
 * them agree": a page left on a previous version serves users the old
 * stylesheet against new markup, which is the actual failure.
 */
$dnPages = ['portal/student/classic.html', 'portal/student/index.html', 'portal/parent/index.html',
            'portal/instructor/classic.html', 'portal/instructor/index.html', 'portal/login.html',
            'portal/signup.html', 'portal/reset.html', 'portal/choose.html'];
foreach (['css/portal.css' => 'stylesheet', 'js/portal.js' => 'portal kit'] as $asset => $label) {
    $seen = [];
    foreach ($dnPages as $f) {
        $h = file_get_contents(__DIR__ . '/../../' . $f);
        if (preg_match('#' . preg_quote($asset, '#') . '\?v=([0-9a-z]+)#', $h, $m)) {
            $seen[$m[1]][] = basename(dirname($f)) . '/' . basename($f);
        }
    }
    t_eq(count($seen), 1,
        "every portal page requests one $label version [" . implode(' | ',
            array_map(static fn($v, $k): string => $k . ': ' . implode(',', $v), $seen, array_keys($seen))) . ']');
}
