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
t_ok($dnW['mastery'] === null, 'and mastery too — this is the line that used to read ": 0.0"');
t_eq($dnW['attendance'], 'none', 'so the attendance light is neutral, NOT red');
t_eq($dnW['mastery_light'], 'none', 'and so is mastery');
t_eq($dnW['attendance_label'], 'Not measured yet', 'with the reason stated in text');
t_eq($dnW['mastery_label'], 'Not measured yet', 'for both');

t_group('dashboards — a student who genuinely is behind still reads as behind');

// The fix must not become a way of hiding a real problem: a measured zero is
// still red, and that is the whole point of keeping the two apart.
t_eq(mam_parent_light(0.0), 'red', 'a measured 0% is red');
t_ok(mam_parent_light_label('red', 'Attendance') === 'Attendance is behind',
    'and says so plainly, in the subject the caller names');

t_group('dashboards — the client renders the absent state, and never as 0%');

$dnHtml = file_get_contents(__DIR__ . '/../../portal/parent/index.html');
t_ok(strpos($dnHtml, ".light.none") !== false, 'a neutral light exists in the stylesheet');
t_ok(preg_match('/\.light\.none\{[^}]*background:transparent/', $dnHtml) === 1,
    'and it is hollow rather than a fourth colour nobody can name');
t_ok(strpos($dnHtml, "lightDot(cls, label)") !== false, 'the dot takes a label');
t_ok(strpos($dnHtml, "aria-label") !== false, 'which reaches assistive technology');
t_ok(strpos($dnHtml, "cls || 'none'") !== false,
    "an unknown light defaults to 'none', not to red — the old default is what made an absent value look like failure");
t_ok(strpos($dnHtml, "Not measured yet") !== false, 'and the absence is written out for everyone else');
t_ok(strpos($dnHtml, 'class="portal-top"') !== false,
    'the header uses the shared class; `.portal-head` was styled nowhere, so the subtitle ran into the title');
t_ok(strpos($dnHtml, 'tabular-nums') !== false, 'metric values use tabular numerals so they stop jittering as they update');

t_group('dashboards — the weekly email does not claim a rate that does not exist');

$dnMail = file_get_contents(__DIR__ . '/../lib/academic-emails.php');
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

$dnAdmin = file_get_contents(__DIR__ . '/../../admin/index.php');
foreach (['--pill-warn-text', '--pill-ok-text', '--pill-info-text', '--pill-err-text'] as $tok) {
    t_ok(strpos($dnAdmin, $tok) !== false, "the admin defines $tok per theme");
}
t_ok(preg_match('/\.pill\.in_review,\.pill\.pending,\.pill\.unread,\.pill\.paused\{[^}]*color:var\(--pill-warn-text\)/', $dnAdmin) === 1,
    'and the pills use them — #FBBF24 on its own tint measured 1.52:1 in the light theme');
t_ok(strpos($dnAdmin, 'width:24px;height:24px;min-width:24px;min-height:24px') !== false,
    'checkboxes meet the 24px target floor; `min-height:0` was holding them at the 13px default');

t_group('dashboards — empty panels offer something to do');

$dnStudent = file_get_contents(__DIR__ . '/../../portal/student/index.html');
$dnParent  = file_get_contents(__DIR__ . '/../../portal/parent/index.html');
t_ok(strpos($dnStudent, 'function empty(icon, text, action)') !== false,
    'the shared empty state takes an action');
t_ok(strpos($dnStudent, "{ href: '/apply', label: 'Start an application' }") !== false,
    'and "you have not applied yet" now links to the form — it read "start one" with nothing to click');
t_ok(strpos($dnStudent, 'No applications yet — start one.') === false,
    'the dead-end wording is gone');
t_ok(strpos($dnParent, "label: 'Contact the office'") !== false,
    'and a guardian with no linked child can reach the office rather than being told to');

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

t_group('dashboards — the stylesheet cache buster moved with the stylesheet');

foreach (['portal/student/index.html', 'portal/parent/index.html', 'portal/instructor/index.html', 'portal/login.html'] as $f) {
    $h = file_get_contents(__DIR__ . '/../../' . $f);
    t_ok(strpos($h, 'portal.css?v=20260815g') === false,
        basename(dirname($f)) . '/' . basename($f) . ' does not still request the pre-change stylesheet');
}
