<?php
/**
 * /checkin — the student's one-tap check-in page.
 *
 * A static, token-based test: it never executes the page (that would emit
 * headers and write the real dev database — see the note in test_root_pages.php).
 * What it pins is the set of properties that make this page safe to put in a
 * student's hand, each of which has a matching way to get it wrong:
 *
 *   • it is a THIN client — no second implementation of "may this student check
 *     in", because the copy in a student's hand would be the wrong one;
 *   • every dynamic value is escaped before it reaches innerHTML;
 *   • the sign-in return path is a same-origin path, not an open redirect;
 *   • its touch targets win the cascade rather than losing to main.css.
 */
declare(strict_types=1);

t_group('/checkin — the one-tap check-in page');

$ciPath = dirname(__DIR__, 2) . '/checkin.php';
t_ok(is_file($ciPath), 'the page exists at the repo root, so /checkin resolves via the extensionless rule');
$ci = (string) file_get_contents($ciPath);

// Thin client. The gates live in api/lib/checkin.php and must not be restated.
t_ok(substr_count($ci, "'student/checkin'") >= 2,
    'it reads AND writes through the existing student/checkin endpoint');
foreach (['mam_checkin_', 'SELECT ', 'mam_db_'] as $forbidden) {
    t_ok(strpos($ci, $forbidden) === false,
        "it never reaches past the API to do its own work [$forbidden]");
}
// The window boundaries are the server's. A number typed here could disagree
// with the gate, which is the bug the portal tab already had once.
t_ok(!preg_match('/\b(?:15|30)\s*\*\s*60\b/', $ci), 'no check-in window arithmetic in the browser');
t_ok(strpos($ci, 'cfg.open_before_min') !== false,
    'the opening threshold is read from the server config, not assumed');
t_ok(strpos($ci, 'w.message') !== false, 'and the notice sentence is the server\'s wording');

// Escaping. Every one of these is attacker-influenced or user-supplied.
t_ok(strpos($ci, 'function esc(') !== false, 'it defines an escaper');
// Checked per LINE rather than by matching `esc(value` directly: several of
// these are wrapped in a fallback — `esc((nc && nc.cohort_title) || …)` — and a
// pattern that only recognises the bare form would pass a page that stopped
// escaping and fail one that never did.
foreach (['sess.title', 'cohort_title', 'sess.status', 'att.minutes_late'] as $val) {
    $lines = array_values(array_filter(explode("\n", $ci),
        static fn(string $l): bool => strpos($l, $val) !== false && strpos($l, '+') !== false));
    t_ok($lines !== [], "$val is interpolated somewhere [sanity]");
    // `say()` sets textContent, where escaping would be the bug — a screen
    // reader would announce "&amp;". Only markup-bound interpolation counts.
    $unescaped = array_values(array_filter($lines, static fn(string $l): bool =>
        strpos($l, 'esc(') === false && strpos($l, 'say(') === false));
    t_eq(count($unescaped), 0, "every line that interpolates $val escapes it"
        . ($unescaped ? ' — ' . trim($unescaped[0]) : ''));
}
// The error copy is set as TEXT, never interpolated into markup.
t_ok(strpos($ci, "querySelector('span').textContent = msg") !== false,
    'an error message is written as text, so a server string cannot inject markup');

// Open-redirect guard: the return path is a literal, and login.html re-checks it.
t_ok(strpos($ci, "encodeURIComponent('/checkin')") !== false,
    'the sign-in return path is a fixed same-origin path, not taken from the URL');
$ciLogin = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/login.html');
t_ok(strpos($ciLogin, 'if (next && !/^\/(?!\/)/.test(next)) next = null;') !== false,
    'and login.html still refuses anything that is not a same-origin path');

// Touch targets, and the cascade they have to win. main.css sets
// `.btn,button.btn,a.btn,…{min-height:44px}` — a bare `.ci-go` loses to
// `button.btn` on specificity, which measured 51px against a stated 62px.
t_ok(preg_match('/button\.ci-go,\s*a\.ci-go\{[^}]*min-height:62px/', $ci) === 1,
    'the primary button is selected as button.ci-go so its height is the one written down');
t_ok(preg_match('/button\.ci-mode\{[^}]*min-height:48px/', $ci) === 1,
    'and each mode button clears 48px');
$ciMain = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/main.css');
t_ok(strpos($ciMain, 'button.btn') !== false,
    'the collision this guards against is still present in main.css (if it goes, revisit the selector)');

// Reduced motion, and no navigation to get lost in.
t_ok(strpos($ci, 'prefers-reduced-motion') !== false, 'the spinner respects reduced motion');
t_ok(strpos($ci, 'partials/header.html') === false,
    'it carries no site navigation — the page has one job');
t_ok(strpos($ci, 'noindex') !== false, 'and is not indexable');

// null is not zero. A new student shown 0% reads as having missed everything.
t_ok(strpos($ci, "=== null || d.attendance_rate === undefined") !== false
     && strpos($ci, "'—'") !== false,
    'an unmeasured attendance rate renders as an em dash, never 0%');

// The two ways in both point here, so a failed scan is not a dead end.
$ciAttend = (string) file_get_contents(dirname(__DIR__, 2) . '/attend.php');
t_ok(substr_count($ciAttend, "href=\"/checkin\"") >= 1,
    'attend.php offers /checkin when the QR itself is the problem');
$ciPortal = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/student/index.html');
t_ok(strpos($ciPortal, 'href="/checkin"') !== false, 'and the portal tab links to it');

t_group('/checkin — the rate it displays cannot exceed 100%');

// The numerator and the denominator used to filter class_sessions differently:
// the total demanded status IN ('scheduled','held') and the attended count
// filtered nothing, so a session in any other state was excluded from the bottom
// and counted on the top. Measured at 300% on a roster with three marks.
$ciSrc = (string) file_get_contents(dirname(__DIR__) . '/lib/checkin.php');
t_ok(defined('MAM_CHECKIN_COUNTED_SESSION_STATUSES'),
    'the counted session states are named once, so both halves move together');
t_ok(!in_array('cancelled', MAM_CHECKIN_COUNTED_SESSION_STATUSES, true),
    'a cancelled class counts against nobody');
t_eq(substr_count($ciSrc, 'AS attended'), 1, 'and the rate is computed by ONE query');
t_ok(strpos($ciSrc, 'LEFT JOIN session_attendance') !== false,
    'a left join over the same rows, so attended is a subset by construction');

// Live: a session in an uncounted state must not inflate anything.
$ciStu = mam_db_row("SELECT id FROM users WHERE role = 'student' LIMIT 1");
if ($ciStu) {
    $ciCourse = mam_db_row('SELECT id FROM courses LIMIT 1');
    $ciCid = $ciCourse ? (int) $ciCourse['id']
        : (int) mam_db_insert('courses', ['code' => 'ci_test', 'name' => 'Rate test', 'kind' => 'elective', 'active' => 1]);
    $ciCoh = (int) mam_db_insert('cohorts', ['title' => 'Rate test cohort', 'course_id' => $ciCid,
        'capacity' => 4, 'session_duration' => 60, 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
    try {
        mam_db_insert('course_enrollments', ['student_id' => (int) $ciStu['id'], 'cohort_id' => $ciCoh,
            'course_id' => $ciCid, 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {
        mam_db()->prepare('UPDATE course_enrollments SET cohort_id = ? WHERE student_id = ? AND course_id = ?')
            ->execute([$ciCoh, (int) $ciStu['id'], $ciCid]);
    }
    // One counted session, attended; two in a state that counts for neither.
    $mk = function (string $status, string $ago) use ($ciCoh): int {
        return (int) mam_db_insert('class_sessions', ['cohort_id' => $ciCoh, 'title' => 'Rate ' . $status,
            'scheduled_at' => date('Y-m-d H:i:s', strtotime($ago)), 'duration_min' => 60,
            'status' => $status, 'created_at' => date('Y-m-d H:i:s')]);
    };
    foreach ([$mk('scheduled', '-3 days'), $mk('completed', '-10 days'), $mk('cancelled', '-17 days')] as $sid) {
        mam_db_insert('session_attendance', ['session_id' => $sid, 'student_id' => (int) $ciStu['id'],
            'status' => 'present', 'method' => 'instructor', 'marked_by' => (int) $ciStu['id']]);
    }
    $rate = mam_checkin_attendance_rate((int) $ciStu['id']);
    t_ok($rate !== null, 'with sessions on the roster the rate is a number');
    t_ok($rate <= 100.0, 'and it cannot exceed 100% however the other sessions are stated [' . $rate . ']');
    t_eq($rate, 100.0, 'the one counted session was attended, so it reads 100');
}

t_group('/scan — the instructor takes the register');

// The academy's real model: children are young, so the INSTRUCTOR logs the
// session and signs each child in from the QR on their ID card. Summer camp
// arrives as a group and is marked in one action. Both live on this page.
$scPath = dirname(__DIR__, 2) . '/scan.php';
t_ok(is_file($scPath), '/scan exists at the repo root');
$sc = (string) file_get_contents($scPath);

// Thin client over three endpoints that already existed.
foreach (['instructor/sessions', 'instructor/card-scan', 'instructor/session-checkin-all'] as $ep) {
    t_ok(strpos($sc, "'" . $ep . "'") !== false, "it uses the existing $ep endpoint");
}
foreach (['mam_db_', 'SELECT ', 'mam_lms_'] as $forbidden) {
    t_ok(strpos($sc, $forbidden) === false, "and never reaches past the API [$forbidden]");
}

// The bug this page shipped with, and the reason it is worth a test: an unknown
// result key falls back to `invalid`, so a wrong map renders every SUCCESSFUL
// scan as "Not a MAM card" while the child is in fact marked. Silent and total.
$scRoute = (string) file_get_contents(dirname(__DIR__) . '/routes/instructor/card-scan.php');
preg_match_all("/'result'\s*=>\s*'([a-z_]+)'|\\\$scanResult\s*=\s*'([a-z_]+)'/", $scRoute, $m);
$scResults = array_values(array_unique(array_filter(array_merge($m[1], $m[2]))));
t_ok(count($scResults) >= 5, 'the endpoint has a known set of results [' . implode(',', $scResults) . ']');
foreach ($scResults as $res) {
    t_ok(preg_match('/^\s*' . preg_quote($res, '/') . ':\s*\[/m', $sc) === 1,
        "the page renders `$res` explicitly rather than falling through to invalid");
}

// One card, one mark: the same code stays in frame for many frames.
t_ok(strpos($sc, 'Date.now() - seen[key] > 4000') !== false,
    'a code held in frame is debounced, so one card is one mark and not twenty');
// Centre crop matching the reticle — decoding the whole frame picks up the next
// card in the queue.
t_ok(strpos($sc, 'v.videoWidth - side) / 2') !== false, 'only the framed centre is decoded');
// The decoder is ours: a CDN copy is CSP-blocked, and a camera page whose reader
// silently fails to load is worse than no page.
t_ok(strpos($sc, "'/assets/js/vendor/jsQR.min.js'") !== false, 'jsQR is loaded self-hosted');
t_ok(is_file(dirname(__DIR__, 2) . '/assets/js/vendor/jsQR.min.js'), 'and is actually vendored');
// Loaded on demand: 130KB, and most visits press the group button instead.
t_ok(strpos($sc, 'window.jsQR ? Promise.resolve()') !== false, 'and only when a camera is wanted');

// Colour is never the only signal — WCAG 1.4.1, and a doorway is exactly where
// somebody glances rather than reads.
t_ok(substr_count($sc, "'sc-ok',") + substr_count($sc, "'sc-dup',") + substr_count($sc, "'sc-bad',") >= 5,
    'each outcome carries an icon and words, not just a colour');
t_ok(strpos($sc, 'camera=(self)') !== false, 'the camera permission is declared on the page itself');
t_ok(strpos($sc, "addEventListener('pagehide'") !== false,
    'and the camera is released on leaving — a live indicator and a flat battery teach people not to use it');
t_ok(preg_match('/button\.sc-btn\{[^}]*min-height:56px/', $sc) === 1,
    'its buttons state their own height, past main.css\'s 44px floor');
