<?php
/**
 * The instructor overview — what you OWE, not just what has happened.
 *
 * Everything on that screen was retrospective: attendance already marked,
 * classes already filled, recent marks. Nothing said what was outstanding —
 * and progress reports carry a two-hour deadline that records an overdue entry
 * against the instructor, so the first they heard of an obligation was the
 * notification telling them they had already missed it.
 *
 * The assertions worth keeping are the two that stop the panel drifting from
 * the enforcement it reports on:
 *   • the deadline is READ from the queued job, never recomputed;
 *   • "overdue" is derived at read time, not stored.
 */
declare(strict_types=1);

t_group('instructor worklist — an instructor with nothing outstanding');

$iwIns = mam_db_insert('users', [
    'email' => 'iw-clear-' . substr(md5((string) mt_rand()), 0, 8) . '@test.invalid',
    'name' => 'Clear Instructor', 'role' => 'instructor', 'status' => 'active',
    'password_hash' => password_hash('x', PASSWORD_BCRYPT), 'created_at' => date('Y-m-d H:i:s'),
]);
$iwClear = mam_lms2_instructor_worklist($iwIns);
t_eq($iwClear['total'], 0, 'nothing outstanding for a brand-new instructor');
t_ok($iwClear['next_session'] === null, 'and no next session');
t_eq(count($iwClear['reports_due']), 0, 'no reports due');
t_eq($iwClear['reports_overdue'], 0, 'none overdue');

t_group('instructor worklist — a held session with reports outstanding');

// A cohort with one active student and a session that has been held.
$iwCourse = mam_db_row("SELECT id FROM courses ORDER BY id LIMIT 1");
$iwCohort = mam_db_insert('cohorts', [
    'course_id' => (int) ($iwCourse['id'] ?? 1), 'instructor_id' => $iwIns,
    'title' => 'Worklist Fixture Cohort', 'status' => 'active', 'capacity' => 10,
    'created_at' => date('Y-m-d H:i:s'),
]);
$iwStu = mam_db_insert('users', [
    'email' => 'iw-stu-' . substr(md5((string) mt_rand()), 0, 8) . '@test.invalid',
    'name' => 'Worklist Student', 'role' => 'student', 'status' => 'active',
    'password_hash' => password_hash('x', PASSWORD_BCRYPT), 'created_at' => date('Y-m-d H:i:s'),
]);
mam_db_insert('course_enrollments', [
    'student_id' => $iwStu, 'cohort_id' => $iwCohort, 'course_id' => (int) ($iwCourse['id'] ?? 1),
    'status' => 'active', 'created_at' => date('Y-m-d H:i:s'),
]);
$iwHeld = mam_db_insert('class_sessions', [
    'cohort_id' => $iwCohort, 'title' => 'Held session', 'status' => 'held',
    'scheduled_at' => date('Y-m-d H:i:s', strtotime('-3 hours')), 'created_at' => date('Y-m-d H:i:s'),
]);

$iw = mam_lms2_instructor_worklist($iwIns);
t_eq(count($iw['reports_due']), 1, 'the held session appears as outstanding');
t_eq($iw['reports_due'][0]['missing'], 1, 'with one report missing for the one enrolled student');
t_eq($iw['reports_due'][0]['roster'], 1, 'and the roster it is counted against');
t_ok($iw['reports_due'][0]['deadline_at'] === null,
    'no queued deadline means no deadline is shown — a date is never invented');
t_ok($iw['reports_due'][0]['overdue'] === false,
    'and without one it cannot be called overdue');
t_eq(count($iw['unmarked']), 1, 'the same session also has no attendance marked');

t_group('instructor worklist — the deadline comes from the job, not from arithmetic');

/**
 * A session can be marked held long after it was scheduled, so "+2 hours from
 * scheduled_at" would show a deadline the enforcement does not use. Reading
 * `jobs.run_after` means the dashboard and the fine cannot disagree.
 */
$iwDeadline = date('Y-m-d H:i:s', strtotime('+90 minutes'));
mam_job_enqueue('progress_report_deadline', ['session_id' => $iwHeld], $iwDeadline);
$iw2 = mam_lms2_instructor_worklist($iwIns);
t_eq($iw2['reports_due'][0]['deadline_at'], $iwDeadline, 'the queued deadline is the one reported');
t_ok($iw2['reports_due'][0]['hours_left'] >= 0 && $iw2['reports_due'][0]['hours_left'] <= 2,
    'with the time remaining derived from it [' . var_export($iw2['reports_due'][0]['hours_left'], true) . ']');
t_ok($iw2['reports_due'][0]['overdue'] === false, 'not yet overdue');
// The scheduled time is three hours ago; a recomputed "+2h from scheduled_at"
// would already be overdue, which is exactly the disagreement this avoids.
t_ok(strtotime($iw2['reports_due'][0]['scheduled_at'] . ' +2 hours') < time(),
    'and a naive +2h from the scheduled time WOULD have said overdue — the two do not agree, so the source matters');

t_group('instructor worklist — overdue is derived at read time');

$iwPast = mam_db_insert('class_sessions', [
    'cohort_id' => $iwCohort, 'title' => 'Older session', 'status' => 'held',
    'scheduled_at' => date('Y-m-d H:i:s', strtotime('-2 days')), 'created_at' => date('Y-m-d H:i:s'),
]);
mam_job_enqueue('progress_report_deadline', ['session_id' => $iwPast], date('Y-m-d H:i:s', strtotime('-1 day')));
$iw3 = mam_lms2_instructor_worklist($iwIns);
$iwLate = null;
foreach ($iw3['reports_due'] as $r) if ($r['session_id'] === $iwPast) $iwLate = $r;
t_ok($iwLate !== null, 'the older session is listed');
t_ok($iwLate['overdue'] === true, 'and reads as overdue with no cron run — derived, like card expiry and vetting validity');
t_ok($iwLate['hours_left'] < 0, 'with the time past the deadline expressed as negative [' . $iwLate['hours_left'] . ']');
t_ok($iw3['reports_overdue'] >= 1, 'and the overdue count reflects it');

t_group('instructor worklist — a written report stops being outstanding');

mam_db_insert('progress_reports', [
    'session_id' => $iwHeld, 'student_id' => $iwStu, 'instructor_id' => $iwIns,
    'answers_json' => '{}', 'submitted_at' => date('Y-m-d H:i:s'),
]);
$iw4 = mam_lms2_instructor_worklist($iwIns);
$iwStill = false;
foreach ($iw4['reports_due'] as $r) if ($r['session_id'] === $iwHeld) $iwStill = true;
t_ok($iwStill === false, 'a session with every report written drops off the list');

t_group('instructor worklist — the next session, and marking attendance');

$iwNext = mam_db_insert('class_sessions', [
    'cohort_id' => $iwCohort, 'title' => 'Upcoming session', 'status' => 'scheduled',
    'scheduled_at' => date('Y-m-d H:i:s', strtotime('+45 minutes')), 'created_at' => date('Y-m-d H:i:s'),
]);
$iw5 = mam_lms2_instructor_worklist($iwIns);
t_ok($iw5['next_session'] !== null, 'the next scheduled session is surfaced');
t_eq((int) $iw5['next_session']['id'], $iwNext, 'and it is the soonest one');
t_ok($iw5['next_session']['minutes_until'] > 0 && $iw5['next_session']['minutes_until'] <= 46,
    'with the minutes until it starts [' . $iw5['next_session']['minutes_until'] . ']');
t_ok($iw5['next_session']['starts_today'] === true || $iw5['next_session']['starts_today'] === false,
    'and whether it is today, which changes how the time is worded');

mam_db_insert('session_attendance', ['session_id' => $iwHeld, 'student_id' => $iwStu, 'status' => 'present']);
$iw6 = mam_lms2_instructor_worklist($iwIns);
$iwUn = false;
foreach ($iw6['unmarked'] as $u) if ((int) $u['id'] === $iwHeld) $iwUn = true;
t_ok($iwUn === false, 'a session with attendance marked stops being listed as unmarked');

t_group('instructor worklist — it never throws, whatever the data');

// Every block is soft-loaded; a nonexistent instructor must yield an empty
// worklist, not a 500 on the dashboard.
$iwNone = mam_lms2_instructor_worklist(999999);
t_eq($iwNone['total'], 0, 'an unknown instructor gets an empty worklist rather than an error');
t_ok(is_array($iwNone['reports_due']) && is_array($iwNone['unmarked']), 'with the shape callers expect');

t_group('instructor worklist — the dashboard is wired, and reads the cohort model only');

$iwRoute = file_get_contents(__DIR__ . '/../routes/instructor/dashboard.php');
t_ok(strpos($iwRoute, "'worklist' =>") !== false, 'the payload carries the worklist');
t_ok(strpos($iwRoute, "'teaching' => \$teaching") !== false, 'and the teaching counts');
t_ok(strpos($iwRoute, 'FROM course_enrollments ce') !== false && strpos($iwRoute, 'JOIN cohorts co') !== false,
    'which reach the cohort model — the hello line once counted legacy classes only, so a cohort-only instructor read "0 classes and 0 students"');
t_ok(strpos($iwRoute, 'array_unique') !== false,
    'and count students DISTINCT, so a student in two of their cohorts is one person');
t_ok(!preg_match('/\b(FROM|JOIN)\s+(classes|enrollments|attendance)\b/i', $iwRoute),
    'and never read the retired class model (classes / enrollments / attendance)');

/* ⚠️ The instructor portal was REDESIGNED (2026-09-19). `index.html` is now the
   v2 surface — class-only markup with its app in `assets/js/portal-instructor.js`
   — and the legacy 13-tab dashboard this file's guards were written against
   lives on at `portal/instructor/classic.html`, still reachable and still
   linked from the v2 More sheet. Same arrangement as `admin/index.php` beside
   `admin/console.php`: the redesign does not retire what it has not replaced.
   These assertions are about THAT file and follow it. */
$iwHtml = file_get_contents(__DIR__ . '/../../portal/instructor/classic.html');
t_ok(strpos($iwHtml, 'function worklistPanel(w)') !== false, 'the panel exists');
t_ok(strpos($iwHtml, "worklistPanel(data.worklist)") !== false, 'and renders on the overview');
t_ok(strpos($iwHtml, 'byCohort') !== false,
    'reports are grouped by cohort — ungrouped, six sessions rendered six identical rows saying "1 progress report to write"');
t_ok(strpos($iwHtml, 'function teachingLine(') !== false, 'the greeting is written from the cohort model');
t_ok(strpos($iwHtml, 'P.safeUrl') !== false, 'the meeting link goes through the scheme guard');

$iwKit = file_get_contents(__DIR__ . '/../../assets/js/portal.js');
t_ok(strpos($iwKit, 'safeUrl: safeUrl') !== false,
    'and that guard is actually exported — it lived inside a closure, so P.safeUrl was undefined at render time');
t_eq(substr_count($iwKit, 'function safeUrl'), 1,
    'defined once: two copies of a security guard can drift apart');
