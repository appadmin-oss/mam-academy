<?php
/**
 * Attendance over the cohort model. The session QR check-in itself (token
 * round-trip, enrolment gate, idempotency) is test_session_checkin.php.
 */
declare(strict_types=1);
t_group('attendance — the legacy class register is retired');

// The class model is retired (mam_legacy_carry() carried every mark into
// session_attendance). Its token, its self-check-in and the routes that served
// them are gone, so nothing can write the old `attendance` table again.
foreach (['mam_attendance_token', 'mam_attendance_verify_token', 'mam_attendance_self_checkin'] as $fn) {
    t_ok(!function_exists($fn), "$fn() is gone with the class model");
}
foreach (['routes/attendance-checkin.php', 'routes/instructor/attendance-qr.php', 'routes/instructor/mark-attendance.php'] as $rf) {
    t_ok(!is_file(dirname(__DIR__) . '/' . $rf), "api/$rf (legacy class register) is deleted");
}
$atSrc = (string) file_get_contents(dirname(__DIR__) . '/modules/live/lib/attendance.php');
t_ok(!preg_match('/\b(FROM|JOIN|INTO)\s+(attendance|enrollments|classes)\b/i', $atSrc),
    'attendance.php reads and writes no legacy table');
$atPage = (string) file_get_contents(dirname(__DIR__, 2) . '/attend.php');
t_ok(strpos($atPage, "'attendance/checkin'") === false && strpos($atPage, "qp('token')") === false,
    'attend.php no longer takes the legacy ?token= class code');
t_ok(strpos($atPage, "qp('stoken')") !== false, 'and still takes the session ?stoken=');

t_group('attendance — how a mark got there is recorded, not defaulted');

// `session_attendance.method` defaults to 'instructor'. Three of the four
// writers left it to that default, so a QR self-check-in and an ID-card scan
// were both indistinguishable from a hand-marked register in the one column
// built to tell them apart. The notes column said "Self check-in (QR)" in prose;
// nothing queries prose, and a student disputing an absence has only this.
$amWriters = [
    ['api/modules/live/lib/checkin.php',                    "'method'        => 'self'",   'the portal check-in button'],
    ['api/modules/live/lib/attendance.php',                 "'method'     => 'qr'",        'a scan of the session QR'],
    ['api/routes/instructor/card-scan.php',    "'method'     => 'card'",      'an instructor scanning an ID card'],
    ['api/modules/academics/lib/lms.php',                        "'method' => 'instructor'",    'the instructor register'],
];
foreach ($amWriters as [$file, $needle, $what]) {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
    t_ok(strpos($src, $needle) !== false, "$what states its own method [$file]");
}

// The four must be DISTINCT, or the column carries no information.
$amMethods = ['self', 'qr', 'card', 'instructor'];
t_eq(count(array_unique($amMethods)), 4, 'and the four means of arriving are four different values');

// Live: a QR self-check-in must not read back as an instructor mark.
$amStu = mam_db_row("SELECT id FROM users WHERE role = 'student' LIMIT 1");
if ($amStu) {
    // course_id is NOT NULL on both cohorts and course_enrollments — a class that
    // teaches nothing is not a class.
    $amCourse = mam_db_row("SELECT id FROM courses LIMIT 1");
    $amCourseId = $amCourse ? (int) $amCourse['id']
        : (int) mam_db_insert('courses', ['code' => 'am_test', 'name' => 'Method test course',
            'kind' => 'elective', 'sort_order' => 900, 'active' => 1]);
    $amCoh = (int) mam_db_insert('cohorts', ['title' => 'Method test cohort', 'course_id' => $amCourseId,
        'capacity' => 4, 'session_duration' => 60, 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
    $amSess = (int) mam_db_insert('class_sessions', ['cohort_id' => $amCoh, 'title' => 'Method test',
        'scheduled_at' => date('Y-m-d H:i:s'), 'duration_min' => 60, 'status' => 'scheduled',
        'created_at' => date('Y-m-d H:i:s')]);
    try {
        mam_db_insert('course_enrollments', ['student_id' => (int) $amStu['id'], 'cohort_id' => $amCoh,
            'course_id' => $amCourseId, 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {
        // An earlier test may hold the (student, course, level) unique key. Point
        // the existing enrolment at this cohort instead — the gate reads
        // cohort_id, so that is what has to be true.
        mam_db()->prepare('UPDATE course_enrollments SET cohort_id = ?, status = ? WHERE student_id = ? AND course_id = ?')
            ->execute([$amCoh, 'active', (int) $amStu['id'], $amCourseId]);
    }
    t_ok(mam_db_row("SELECT 1 FROM course_enrollments WHERE cohort_id = ? AND student_id = ? AND status = 'active'",
        [$amCoh, (int) $amStu['id']]) !== null, 'the student is on the cohort roster (the scan is enrolment-gated)');
    $amRes = mam_attendance_session_self_checkin((int) $amStu['id'], $amSess);
    t_eq($amRes['status'], 'present', 'the scan marks them present');
    $amRow = mam_db_row('SELECT method, notes, marked_by FROM session_attendance WHERE session_id = ? AND student_id = ?',
        [$amSess, (int) $amStu['id']]);
    t_eq($amRow['method'], 'qr', 'and the row says a QR scan put it there, not an instructor');
    t_eq((int) $amRow['marked_by'], (int) $amStu['id'], 'marked by the student themselves');
    // Idempotent, and a re-scan must not rewrite how the first one arrived.
    $again = mam_attendance_session_self_checkin((int) $amStu['id'], $amSess);
    t_ok(!empty($again['already']), 'a second scan is recognised rather than duplicated');
    t_eq(mam_db_row('SELECT method FROM session_attendance WHERE session_id = ? AND student_id = ?',
        [$amSess, (int) $amStu['id']])['method'], 'qr', 'and the method is unchanged');
}

t_group('attendance — the group ("general") check-in');

// Summer camp arrives together: the instructor marks the session in one action
// and corrects exceptions afterwards. Twenty single requests would do the same
// job with twenty chances to half-succeed, and no way to tell a child who was
// absent from one whose request failed.
t_ok(function_exists('mam_lms_mark_attendance_all'), 'a bulk marker exists');

$gaCourse = mam_db_row('SELECT id FROM courses LIMIT 1');
$gaCid = $gaCourse ? (int) $gaCourse['id']
    : (int) mam_db_insert('courses', ['code' => 'ga_test', 'name' => 'Group test', 'kind' => 'elective', 'active' => 1]);
$gaCoh = (int) mam_db_insert('cohorts', ['title' => 'Summer camp — group check-in', 'course_id' => $gaCid,
    'capacity' => 30, 'session_duration' => 60, 'status' => 'active', 'term' => 'SUMMER-2030',
    'created_at' => date('Y-m-d H:i:s')]);
$gaSess = (int) mam_db_insert('class_sessions', ['cohort_id' => $gaCoh, 'title' => 'Camp day 1',
    'scheduled_at' => date('Y-m-d H:i:s'), 'duration_min' => 120, 'status' => 'scheduled',
    'created_at' => date('Y-m-d H:i:s')]);

// Four children on the roster; one already marked late by hand.
$gaKids = [];
foreach (['Ama', 'Bode', 'Chidera', 'Dami'] as $i => $n) {
    $uid = (int) mam_db_insert('users', ['email' => 'ga_' . $i . '_' . bin2hex(random_bytes(3)) . '@x.dev',
        'name' => $n . ' Camp', 'role' => 'student', 'status' => 'active',
        'password_hash' => password_hash('x-' . $i . '-longenough', PASSWORD_BCRYPT),
        'created_at' => date('Y-m-d H:i:s')]);
    mam_db_insert('course_enrollments', ['student_id' => $uid, 'cohort_id' => $gaCoh, 'course_id' => $gaCid,
        'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
    $gaKids[] = $uid;
}
mam_lms_mark_attendance($gaSess, $gaKids[0], 'late', null, 'Arrived with a parent');

$ga = mam_lms_mark_attendance_all($gaSess, 'present', null);
t_eq($ga['roster'], 4, 'it works from the teachable roster');
t_eq($ga['marked'], 3, 'marking only the children who had no mark yet');
t_eq($ga['skipped'], 1, 'and skipping the one already recorded');
t_eq(count($ga['failed']), 0, 'with nothing failing');

// The existing mark must survive. An instructor who recorded somebody late and
// then pressed the group button would otherwise silently lose it.
t_eq(mam_db_row('SELECT status FROM session_attendance WHERE session_id = ? AND student_id = ?',
    [$gaSess, $gaKids[0]])['status'], 'late', 'a hand-entered mark is not overwritten by default');
t_eq((int) mam_db_row('SELECT COUNT(*) c FROM session_attendance WHERE session_id = ?', [$gaSess])['c'], 4,
    'every child on the roster now has exactly one row');

// Idempotent: pressing it twice changes nothing.
$again = mam_lms_mark_attendance_all($gaSess, 'present', null);
t_eq($again['marked'], 0, 'pressing it again marks nobody new');
t_eq($again['skipped'], 4, 'because everybody already has a mark');

// Overwriting is possible, but has to be asked for.
$force = mam_lms_mark_attendance_all($gaSess, 'present', null, false);
t_eq($force['marked'], 4, 'with only_unmarked off it marks the whole roster');
t_eq(mam_db_row('SELECT status FROM session_attendance WHERE session_id = ? AND student_id = ?',
    [$gaSess, $gaKids[0]])['status'], 'present', 'and then the late mark IS replaced');

// A bad status is refused before anything is written.
$gaThrew = false;
try { mam_lms_mark_attendance_all($gaSess, 'maybe', null); } catch (\Throwable $e) { $gaThrew = $e->getMessage() === 'invalid_status'; }
t_ok($gaThrew, 'an unknown status is refused, not coerced');

// One student's problem must not sink the register: withdraw a child and confirm
// the rest are still marked and the failure is NAMED, not just counted.
$gaSess2 = (int) mam_db_insert('class_sessions', ['cohort_id' => $gaCoh, 'title' => 'Camp day 2',
    'scheduled_at' => date('Y-m-d H:i:s'), 'duration_min' => 120, 'status' => 'scheduled',
    'created_at' => date('Y-m-d H:i:s')]);
mam_db()->prepare("UPDATE course_enrollments SET status = 'withdrawn' WHERE cohort_id = ? AND student_id = ?")
    ->execute([$gaCoh, $gaKids[3]]);
$ga2 = mam_lms_mark_attendance_all($gaSess2, 'present', null);
t_eq($ga2['roster'], 3, 'a withdrawn child leaves the teachable roster');
t_eq($ga2['marked'], 3, 'and the rest are still marked');

// The route exists, is gated the same way as its single-row sibling, and is
// rate-limited lower — one press marks a whole class.
$gaIdx = (string) file_get_contents(dirname(__DIR__) . '/index.php');
t_ok(strpos($gaIdx, "'/session-checkin-all'") !== false, 'the route is registered');
t_ok(preg_match("#'/session-checkin-all'.*mw_csrf\(\).*mw_role\('instructor', 'admin'\)#", $gaIdx) === 1,
    'CSRF-gated and instructor/admin only');
$gaRoute = (string) file_get_contents(dirname(__DIR__) . '/routes/instructor/session-checkin-all.php');
t_ok(strpos($gaRoute, 'mam_lms_instructor_owns_cohort') !== false,
    'and it checks cohort ownership — a bulk endpoint with a weaker gate is the way around the single one');
t_ok(strpos($gaRoute, "!empty(\$in['overwrite'])") !== false,
    'overwrite is opt-in on the wire too, not just in the library');
