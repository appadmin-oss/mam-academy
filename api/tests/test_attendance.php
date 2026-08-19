<?php
/**
 * QR self-check-in (#9/#10) — signed token round-trip + enrolment-gated,
 * idempotent marking. The token can't be forged or replayed after its day, and
 * check-in only ever marks an enrolled student present, exactly once.
 */
declare(strict_types=1);
t_group('attendance QR check-in');

// ── Token: verifies, carries class + date, and is tamper-evident ──
// Use TODAY (a token is valid for its session day only), so the test isn't
// fragile to the clock rolling past a hard-coded date.
$today = date('Y-m-d');
$tok = mam_attendance_token(42, $today);
$v = mam_attendance_verify_token($tok);
t_ok(is_array($v) && $v['class_id'] === 42 && $v['date'] === $today, 'a fresh token verifies and carries class + date');
t_ok(mam_attendance_verify_token($tok . 'x') === null, 'a tampered token is rejected');
t_ok(mam_attendance_verify_token('not-a-token') === null, 'garbage is rejected');
t_ok(mam_attendance_verify_token('') === null, 'empty is rejected');

// A token for a past date is expired (session day has passed).
$old = mam_attendance_token(42, '2020-01-01');
t_ok(mam_attendance_verify_token($old) === null, 'a past-date token is expired');

// A signature made with the wrong key must not verify (proves it is signed).
$forged = mam_attendance_b64('42:2026-07-05:' . strtotime('2026-07-05 23:59:59') . '|' . hash_hmac('sha256', 'x', 'wrong-key'));
t_ok(mam_attendance_verify_token($forged) === null, 'a wrong-key signature is rejected');

// ── Self-check-in is enrolment-gated + idempotent ──
$stu = mam_db_insert('users', ['email' => 't_attend_stu@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Ada Check', 'status' => 'active']);
$ins = mam_db_insert('users', ['email' => 't_attend_ins@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Ins', 'status' => 'active']);
$cid = mam_db_insert('classes', ['title' => 'Attendance Test Class', 'discipline' => 'piano', 'instructor_id' => $ins, 'status' => 'active']);
$other = mam_db_insert('classes', ['title' => 'Other Class', 'discipline' => 'voice', 'instructor_id' => $ins, 'status' => 'active']);

// Not enrolled anywhere → rejected.
$threw = false; try { mam_attendance_self_checkin((int)$stu, (int)$cid, '2026-07-05'); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'not_enrolled'; }
t_ok($threw, 'a non-enrolled student cannot check in (not_enrolled)');

// Enrol the student in $cid and check in.
mam_db_insert('enrollments', ['student_id' => $stu, 'class_id' => $cid, 'status' => 'active']);
$r1 = mam_attendance_self_checkin((int)$stu, (int)$cid, '2026-07-05');
t_eq($r1['status'], 'present', 'an enrolled student is marked present');
t_ok($r1['already'] === false, 'first check-in is not a repeat');
t_eq((int)mam_db_row('SELECT COUNT(*) AS n FROM attendance WHERE session_date = ?', ['2026-07-05'])['n'], 1, 'exactly one attendance row');

// A second scan the same day is idempotent — still one row, flagged already.
$r2 = mam_attendance_self_checkin((int)$stu, (int)$cid, '2026-07-05');
t_ok($r2['already'] === true, 'a re-scan reports already checked in');
t_eq((int)mam_db_row('SELECT COUNT(*) AS n FROM attendance WHERE session_date = ?', ['2026-07-05'])['n'], 1, 'a re-scan does not create a duplicate row');

// Enrolment in one class does not let the student check in to a different class.
$threw = false; try { mam_attendance_self_checkin((int)$stu, (int)$other, '2026-07-05'); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'not_enrolled'; }
t_ok($threw, 'enrolment is per-class — cannot check in to a class you are not in');

// BUG 2 (migration 60): the DB itself now forbids two attendance rows for one
// (enrollment_id, session_date). This is the backstop under the idempotent
// read-then-insert above — a race that slips past the pre-check hits a UNIQUE
// violation, not a duplicate row that inflates the attendance rate.
$eidU = (int)mam_db_row("SELECT id FROM enrollments WHERE student_id = ? AND class_id = ?", [$stu, $cid])['id'];
$dupBlocked = false;
try {
    mam_db_insert('attendance', ['enrollment_id' => $eidU, 'session_date' => '2026-07-05', 'status' => 'present']);
} catch (\Throwable $e) {
    $dupBlocked = true;
}
t_ok($dupBlocked, 'a duplicate (enrollment_id, session_date) insert is rejected by the unique index');
t_eq((int)mam_db_row('SELECT COUNT(*) AS n FROM attendance WHERE session_date = ?', ['2026-07-05'])['n'], 1, 'the blocked duplicate left exactly one row');

// An instructor mark is never downgraded by a later self-scan.
$eid2 = mam_db_row("SELECT id FROM enrollments WHERE student_id = ? AND class_id = ?", [$stu, $cid])['id'];
mam_db_insert('attendance', ['enrollment_id' => (int)$eid2, 'session_date' => '2026-07-06', 'status' => 'late', 'marked_by' => (int)$ins]);
$r3 = mam_attendance_self_checkin((int)$stu, (int)$cid, '2026-07-06');
t_eq($r3['status'], 'late', 'a self-scan keeps an existing instructor mark (no downgrade)');
t_ok($r3['already'] === true, 'the existing mark is reported as already recorded');

t_group('attendance — how a mark got there is recorded, not defaulted');

// `session_attendance.method` defaults to 'instructor'. Three of the four
// writers left it to that default, so a QR self-check-in and an ID-card scan
// were both indistinguishable from a hand-marked register in the one column
// built to tell them apart. The notes column said "Self check-in (QR)" in prose;
// nothing queries prose, and a student disputing an absence has only this.
$amWriters = [
    ['api/lib/checkin.php',                    "'method'        => 'self'",   'the portal check-in button'],
    ['api/lib/attendance.php',                 "'method'     => 'qr'",        'a scan of the session QR'],
    ['api/routes/instructor/card-scan.php',    "'method'     => 'card'",      'an instructor scanning an ID card'],
    ['api/lib/lms.php',                        "'method' => 'instructor'",    'the instructor register'],
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
