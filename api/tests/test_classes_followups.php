<?php
/**
 * Classes audit follow-ups (docs/classes-audit-2026-11-28.md §3.4, §3.8, §3.10, §6.5–6.7).
 *   A  cohort keying — a year-round enrolment never lands in a SUMMER cohort;
 *      default titles name the programme; "assign course" reuses the levelled class
 *   B  sessions — every create path inherits the class's mode; the register takes
 *      the session's mode; ONE series builder (closures, existing sessions, links)
 *   C  class-space marks reach the instructor's Marking queue, the student's Learn
 *      screen, and a guardian only under the 'reports' grant
 * Each was reproduced against 0df7c2d before the fix.
 */
declare(strict_types=1);

$CF_tag = 'cf' . substr((string) mt_rand(100000, 999999), 0, 6);
$CF_mk = fn(string $t, string $role) => (int) mam_db_insert('users', ['email' => "{$CF_tag}_$t@test.local", 'password_hash' => 'x',
    'role' => $role, 'name' => 'CF ' . strtoupper($t), 'status' => 'active']);
$CF_user = fn(int $id) => mam_db_row('SELECT * FROM users WHERE id = ?', [$id]);
$CF_teach = $CF_mk('teach', 'instructor'); $CF_other = $CF_mk('other', 'instructor');
$CF_s1 = $CF_mk('s1', 'student'); $CF_s2 = $CF_mk('s2', 'student'); $CF_s3 = $CF_mk('s3', 'student');
$CF_mum = $CF_mk('mum', 'parent');
$CF_wasGate = mam_setting('safeguarding_gate_mode', '');
mam_setting_set('safeguarding_gate_mode', 'warn');

$CF_course = (int) mam_db_insert('courses', ['code' => $CF_tag, 'name' => 'Followups ' . $CF_tag, 'kind' => 'elective', 'active' => 1, 'sort_order' => 99]);
$CF_prog = fn(string $code) => (int) mam_db_row('SELECT id FROM programs WHERE code = ?', [$code])['id'];
$CF_lvl  = fn(string $code) => (int) mam_db_row('SELECT id FROM levels WHERE code = ?', [$code])['id'];

/* ─── A1 ─────────────────────────────────────────────────────────────────── */
t_group('classes follow-ups — A1: a year-round enrolment never lands in a summer class');

$CF_summer = mam_summer_cohort($CF_course, 2027, 'Followups');
t_eq((int) mam_db_row('SELECT program_id FROM cohorts WHERE id = ?', [$CF_summer])['program_id'], $CF_prog('crash'),
    'the summer intake is a crash-programme class (the setup that caused the mix-up)');
t_ok(mam_lms_find_cohort($CF_course, null, $CF_prog('crash')) !== $CF_summer,
    'looking up the (course, no level, crash) class does not return the SUMMER-2027 one (was: it did)');
$CF_add = mam_lms_add_course($CF_s1, $CF_tag, null, 'crash');
t_ok((int) $CF_add['cohort_id'] > 0 && (int) $CF_add['cohort_id'] !== $CF_summer,
    'a year-round crash enrolment gets its own class, not the summer one');
t_eq(mam_summer_cohort($CF_course, 2027, 'Followups'), $CF_summer, 'the summer path still finds its own class');
t_ok(mam_lms_ensure_cohort($CF_course, null, $CF_prog('crash')) === (int) $CF_add['cohort_id'],
    'the next year-round crash enrolment (and promotion, which also ensures) reuses that class');

/* ─── A2 ─────────────────────────────────────────────────────────────────── */
t_group('classes follow-ups — A2: titles and the console say which programme and term');

$CF_title = (string) mam_db_row('SELECT title FROM cohorts WHERE id = ?', [(int) $CF_add['cohort_id']])['title'];
t_ok(str_contains($CF_title, '· Crash Program'), 'a new crash class is titled with its programme [' . $CF_title . ']');
t_eq(mam_lms_cohort_default_title($CF_course, $CF_lvl('precert'), $CF_prog('certified')), 'Followups ' . $CF_tag . ' — Pre-Certification · Certified Program',
    'the default title is "Course — Level · Programme"');
t_eq(mam_lms_cohort_default_title($CF_course, $CF_lvl('precert'), $CF_prog('standard')), 'Followups ' . $CF_tag . ' — Pre-Certification',
    'the standard programme is not named');
t_eq(mam_lms_cohort_default_title($CF_course, $CF_lvl('precert')), 'Followups ' . $CF_tag . ' — Pre-Certification',
    'a two-argument call is unchanged');
$CF_rows = array_column(mam_console_cohorts()['rows'], null, 'id');
t_eq($CF_rows[$CF_summer]['term_label'] ?? null, 'Summer 2027', 'the console card says the summer term');
t_eq($CF_rows[(int) $CF_add['cohort_id']]['term_label'] ?? null, 'Year-round', '…and that the other class is year-round');
t_eq($CF_rows[(int) $CF_add['cohort_id']]['programme_label'] ?? null, 'Crash Program', '…and names its programme');
$CF_partial = (string) file_get_contents(dirname(__DIR__, 2) . '/partials/admin-console/cohorts.php');
t_ok(str_contains($CF_partial, "programme_label") && str_contains($CF_partial, "term_label"), 'the cohorts screen renders both labels');

/* ─── A3 ─────────────────────────────────────────────────────────────────── */
t_group('classes follow-ups — A3: "assign course" reuses the levelled class when the level is known');

$CF_lvlCohort = mam_lms_ensure_cohort($CF_course, $CF_lvl('cert'), $CF_prog('certified'));
mam_db_insert('course_enrollments', ['student_id' => $CF_s2, 'course_id' => $CF_course, 'level_id' => $CF_lvl('cert'),
    'program_id' => $CF_prog('certified'), 'cohort_id' => $CF_lvlCohort, 'status' => 'withdrawn']);
t_eq(mam_lms_known_placement($CF_s2, $CF_course), ['cert', 'certified'], 'the last placement in the course is known');
t_eq(mam_lms_known_placement($CF_s3, $CF_course), [null, null], 'a student with none is unknown');
[$CF_kl, $CF_kp] = mam_lms_known_placement($CF_s2, $CF_course);
t_eq((int) mam_lms_add_course($CF_s2, $CF_tag, $CF_kl, $CF_kp)['cohort_id'], $CF_lvlCohort, 're-adding with it lands in the levelled class');
$CF_admin = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
$CF_assign = substr($CF_admin, (int) strpos($CF_admin, "\$action === 'student_course_assign'"), 2500);
t_ok(str_contains($CF_assign, 'mam_lms_known_placement(') && !str_contains($CF_assign, 'mam_lms_add_course($uid, $code);'),
    'the admin "assign course" action passes the known level and programme (was: none)');

/* ─── B1 ─────────────────────────────────────────────────────────────────── */
t_group('classes follow-ups — B1: sessions inherit the class\'s mode; the register takes the session\'s');

$CF_online = mam_lms_create_cohort(['course_id' => $CF_course, 'title' => 'Online ' . $CF_tag, 'instructor_id' => $CF_teach, 'mode' => 'online']);
mam_db_insert('course_enrollments', ['student_id' => $CF_s3, 'course_id' => $CF_course, 'cohort_id' => $CF_online, 'status' => 'active']);
$CF_mode = fn(int $sid) => (string) mam_db_row('SELECT mode FROM class_sessions WHERE id = ?', [$sid])['mode'];
$CF_x1 = mam_lms_create_session($CF_online, ['scheduled_at' => '2034-03-01 10:00:00']);
t_eq($CF_mode($CF_x1), 'online', 'mam_lms_create_session() with no mode takes the class\'s (was: onsite)');
t_eq($CF_mode(mam_lms_create_session($CF_online, ['scheduled_at' => '2034-03-02 10:00:00', 'mode' => 'onsite'])), 'onsite', 'an explicit mode still wins');
t_eq($CF_mode(mam_lms_create_session($CF_online, ['scheduled_at' => '2034-03-03 10:00:00', 'mode' => 'bogus'])), 'online', 'a nonsense mode falls back to the class\'s');
$CF_x2 = mam_sched_add_session($CF_online, ['at' => '2034-03-04 10:00', 'duration' => 60], $CF_teach, true);
t_eq($CF_mode((int) $CF_x2['id']), 'online', 'the admin single session takes the class\'s mode (was: onsite)');
t_ok((string) mam_db_row('SELECT meeting_link FROM class_sessions WHERE id = ?', [(int) $CF_x2['id']])['meeting_link'] !== '', '…and so gets its link');
t_eq(mam_sched_rule($CF_online, ['days' => [1], 'time' => '10:00', 'from' => '2034-03-06', 'to' => '2034-03-13'])['mode'], 'online',
    'the admin series rule defaults to the class\'s mode');
t_eq(mam_sched_rule($CF_online, ['days' => [1], 'time' => '10:00', 'from' => '2034-03-06', 'to' => '2034-03-13', 'mode' => ''])['mode'], 'online',
    '…including when the form posts "the class\'s own" (an empty mode)');
mam_lms_mark_attendance($CF_x1, $CF_s3, 'present', $CF_teach);
t_eq((string) mam_db_row('SELECT mode FROM session_attendance WHERE session_id = ? AND student_id = ?', [$CF_x1, $CF_s3])['mode'], 'online',
    'an instructor-marked register row takes the session\'s mode (was: onsite)');
$CF_x3 = mam_lms_create_session($CF_online, ['scheduled_at' => '2034-03-05 10:00:00']);
mam_checkin_set_status($CF_x3, $CF_s3, 'late', null, $CF_teach);
t_eq((string) mam_db_row('SELECT mode FROM session_attendance WHERE session_id = ? AND student_id = ?', [$CF_x3, $CF_s3])['mode'], 'online',
    'a correction with no mode on a new row takes the session\'s mode too');

/* ─── B2 ─────────────────────────────────────────────────────────────────── */
t_group('classes follow-ups — B2: one series builder');

$CF_term = (int) mam_db_insert('academic_terms', ['year_label' => '2033/34', 'code' => 'T' . $CF_tag, 'term_no' => 2, 'label' => 'Term ' . $CF_tag,
    'starts_on' => '2034-02-01', 'ends_on' => '2034-02-28', 'status' => 'planned']);
mam_db_insert('term_closures', ['term_id' => $CF_term, 'kind' => 'holiday', 'label' => 'Half term', 'from_on' => '2034-02-13', 'to_on' => '2034-02-13']);
mam_lms_create_session($CF_online, ['scheduled_at' => '2034-02-20 16:00:00']);   // already meets then
$CF_bulk = mam_lms_bulk_create_sessions($CF_online, ['start_date' => '2034-02-06', 'end_date' => '2034-02-27', 'day_of_week' => [1],
    'time_of_day' => '16:00', 'duration_min' => 60, 'title_prefix' => 'Week'], $CF_teach);
$CF_dates = array_map(fn($id) => substr((string) mam_db_row('SELECT scheduled_at FROM class_sessions WHERE id = ?', [$id])['scheduled_at'], 0, 10), $CF_bulk['session_ids']);
t_eq($CF_dates, ['2034-02-06', '2034-02-27'], 'the instructor series skips the holiday and the date the class already meets (was: all four)');
t_eq(array_column($CF_bulk['skipped'], 'why'), ['closed', 'exists'], '…and says why each was skipped');
t_eq(array_map($CF_mode, $CF_bulk['session_ids']), ['online', 'online'], 'its sessions take the class\'s mode');
t_ok(count(array_filter($CF_bulk['session_ids'], fn($id) => (string) mam_db_row('SELECT meeting_link FROM class_sessions WHERE id = ?', [$id])['meeting_link'] !== '')) === 2,
    '…and get their meeting links, as the admin series does');
t_eq(mam_lms_bulk_sessions_preview(['start_date' => '2034-02-06', 'end_date' => '2034-02-27', 'day_of_week' => [1]]),
    ['2034-02-06', '2034-02-20', '2034-02-27'], 'the preview leaves the holiday out too');
$CF_lms = (string) file_get_contents(dirname(__DIR__) . '/modules/academics/lib/lms.php');
$CF_bulkSrc = substr($CF_lms, (int) strpos($CF_lms, 'function mam_lms_bulk_create_sessions'), 4000);
t_ok(str_contains($CF_bulkSrc, 'mam_sched_slots(') && !str_contains($CF_bulkSrc, "modify('+1 day')"),
    'the instructor builder delegates to the shared slot generator rather than walking days itself');
$CF_sched = (string) file_get_contents(dirname(__DIR__) . '/modules/academics/lib/scheduling.php');
$CF_planSrc = substr($CF_sched, (int) strpos($CF_sched, 'function mam_sched_plan_series'), 600);
t_ok(str_contains($CF_planSrc, 'mam_sched_slots('), '…and so does the admin planner');

/* ─── C ──────────────────────────────────────────────────────────────────── */
t_group('classes follow-ups — C: class-space marks reach the Marking queue, Learn and the guardian');

$CF_space = mam_space_get(mam_space_for_cohort($CF_online, $CF_teach));
$CF_task = mam_space_task_save($CF_space, ['title' => 'Clap the rhythm ' . $CF_tag, 'wants' => 'any', 'points' => 20], $CF_user($CF_teach));
$CF_post = (int) mam_db_insert('space_posts', ['space_id' => (int) $CF_space['id'], 'author_id' => $CF_s3, 'kind' => 'submission',
    'task_id' => $CF_task, 'body' => 'Here it is', 'max_score' => 20, 'status' => 'visible', 'audience' => 'teacher']);
$CF_queue = mam_iportal_marking($CF_teach)['spaces'] ?? null;
t_ok(is_array($CF_queue) && in_array($CF_post, array_column($CF_queue, 'id'), true),
    'the instructor\'s Marking payload lists the unmarked space work (was: no `spaces` key)');
$CF_row = $CF_queue ? array_values(array_filter($CF_queue, fn($r) => $r['id'] === $CF_post))[0] : [];
t_eq([$CF_row['task_title'] ?? null, $CF_row['max_score'] ?? null], ['Clap the rhythm ' . $CF_tag, 20], '…with the task and what it is out of');
t_ok(!in_array($CF_post, array_column(mam_iportal_marking($CF_other)['spaces'] ?? [], 'id'), true), 'a teacher of another class does not see it');
t_eq(mam_sportal_learn($CF_s3)['space_marks'] ?? null, [], 'unmarked work is not on the student\'s Learn screen yet');

mam_space_review($CF_post, $CF_user($CF_teach), 17, 'Steady pulse, rushed bar 3.');
t_ok(!in_array($CF_post, array_column(mam_iportal_marking($CF_teach)['spaces'], 'id'), true), 'once marked it leaves the queue');
$CF_marks = mam_sportal_learn($CF_s3)['space_marks'] ?? [];
t_eq(count($CF_marks), 1, 'the student\'s Learn screen shows their marked space work (was: nowhere)');
t_eq([$CF_marks[0]['task_title'] ?? null, $CF_marks[0]['score'] ?? null, $CF_marks[0]['max_score'] ?? null, $CF_marks[0]['feedback'] ?? null],
    ['Clap the rhythm ' . $CF_tag, 17, 20, 'Steady pulse, rushed bar 3.'], '…task, score of max, and what the teacher said');
t_ok(!empty($CF_marks[0]['marked_at']), '…and when it was marked');
t_eq(mam_sportal_learn($CF_s1)['space_marks'] ?? null, [], 'another student does not see it — marks are private');

mam_db_insert('parent_links', ['parent_id' => $CF_mum, 'student_id' => $CF_s3, 'access_scope' => 'standard']);
$CF_detail = mam_parent_child_detail($CF_mum, $CF_s3);
t_eq(count($CF_detail['space_marks'] ?? []), 1, 'a guardian with the reports grant sees the mark');
mam_db_update('parent_links', ['access_scope' => 'billing'], ['parent_id' => $CF_mum, 'student_id' => $CF_s3]);
$CF_detail = mam_parent_child_detail($CF_mum, $CF_s3);
t_eq($CF_detail['space_marks'] ?? 'missing', [], 'a billing-only guardian does not');
t_ok(in_array('space_marks', $CF_detail['withheld'] ?? [], true), '…and is told it was withheld');

$CF_ijs = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/portal-instructor.js');
$CF_sjs = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/portal-student.js');
t_ok(str_contains($CF_ijs, 'd.spaces') && str_contains($CF_ijs, "action: 'review'"), 'the instructor portal renders the queue and marks through the space\'s own review');
t_ok(str_contains($CF_sjs, 'd.space_marks'), 'the student portal renders the marks');

/* Leave the shared test database as we found it: later files count courses
   (test_lms, test_migrations). */
$CF_cohorts = array_map('intval', array_column(mam_db_all('SELECT id FROM cohorts WHERE course_id = ?', [$CF_course]), 'id'));
$CF_in = implode(',', array_fill(0, max(1, count($CF_cohorts)), '?'));
$CF_cargs = $CF_cohorts ?: [0];
foreach (["DELETE FROM class_spaces WHERE cohort_id IN ($CF_in)" => $CF_cargs,
          "DELETE FROM session_attendance WHERE session_id IN (SELECT id FROM class_sessions WHERE cohort_id IN ($CF_in))" => $CF_cargs,
          "DELETE FROM class_sessions WHERE cohort_id IN ($CF_in)" => $CF_cargs,
          "DELETE FROM cohort_staff WHERE cohort_id IN ($CF_in)" => $CF_cargs,
          "DELETE FROM class_waitlist WHERE cohort_id IN ($CF_in)" => $CF_cargs,
          'DELETE FROM course_enrollments WHERE course_id = ?' => [$CF_course],
          "DELETE FROM cohorts WHERE id IN ($CF_in)" => $CF_cargs,
          'DELETE FROM courses WHERE id = ?' => [$CF_course],
          'DELETE FROM term_closures WHERE term_id = ?' => [$CF_term],
          'DELETE FROM academic_terms WHERE id = ?' => [$CF_term]] as $CF_sql => $CF_args) {
    mam_db()->prepare($CF_sql)->execute($CF_args);
}
mam_setting_set('safeguarding_gate_mode', (string) $CF_wasGate);
t_eq(mam_db_row('SELECT id FROM courses WHERE id = ?', [$CF_course]), null, 'the fixture course is gone again');
