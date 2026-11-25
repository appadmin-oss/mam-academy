<?php
/**
 * The old class model, carried into cohorts (api/lib/legacy-carry.php). Every kind of thing an
 * old class holds arrives in the cohort model; a class with no course waits and is named; a
 * second run changes nothing; a teacher's own mark is never overwritten.
 */
declare(strict_types=1);
t_group('legacy classes — carried into cohorts, nothing lost');

$LC_t = mam_db_insert('users', ['email' => 't_lc_ins@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'LC Teacher', 'status' => 'active']);
$LC_s = [];
foreach (['a', 'b', 'c', 'd'] as $k)
    $LC_s[$k] = mam_db_insert('users', ['email' => "t_lc_$k@x.dev", 'password_hash' => 'x', 'role' => 'student', 'name' => "LC Student $k", 'status' => 'active']);
$LC_course = mam_db_insert('courses', ['code' => 'lc_cello', 'name' => 'Cello (LC)', 'kind' => 'elective', 'active' => 1]);
$LC_class = mam_db_insert('classes', ['title' => 'Saturday Cello', 'discipline' => 'LC_CELLO', 'instructor_id' => $LC_t, 'status' => 'active']);
$LC_orphan = mam_db_insert('classes', ['title' => 'Old Kazoo Club', 'discipline' => 'kazoo-lc', 'status' => 'active']);

$LC_e = [];
foreach (['a' => 'active', 'b' => 'paused', 'c' => 'completed', 'd' => 'withdrawn'] as $k => $st)
    $LC_e[$k] = mam_db_insert('enrollments', ['student_id' => $LC_s[$k], 'class_id' => $LC_class, 'status' => $st]);
mam_db_insert('enrollments', ['student_id' => $LC_s['a'], 'class_id' => $LC_orphan, 'status' => 'active']);
foreach ([['a', '2026-03-07', 'present'], ['b', '2026-03-07', 'late'], ['a', '2026-03-14', 'absent']] as [$k, $d, $st])
    mam_db_insert('attendance', ['enrollment_id' => $LC_e[$k], 'session_date' => $d, 'status' => $st, 'marked_by' => $LC_t]);
$LC_ann = mam_db_insert('announcements', ['class_id' => $LC_class, 'author_id' => $LC_t, 'title' => 'Bring rosin', 'body' => 'Bring rosin on Saturday.']);
$LC_hw = mam_db_insert('assignments', ['student_id' => $LC_s['a'], 'author_id' => $LC_t, 'class_id' => $LC_class, 'title' => 'Scales']);

mam_db_update('classes', ['meeting_link' => 'https://meet.example.test/cello'], ['id' => $LC_class]);
mam_lms_backfill_cohorts();
$LC_coid = (int) mam_db_row('SELECT id FROM cohorts WHERE legacy_class_id = ?', [$LC_class])['id'];
$LC_next = mam_db_insert('class_sessions', ['cohort_id' => $LC_coid, 'title' => 'Next Saturday', 'scheduled_at' => '2099-01-03 10:00:00', 'status' => 'scheduled']);

$LC_r = mam_legacy_carry(null);
t_eq((string) mam_db_row('SELECT meeting_link FROM class_sessions WHERE id = ?', [$LC_next])['meeting_link'], 'https://meet.example.test/cello',
    "the class's meeting link reaches the cohort's next session, so a bookmarked join link still opens the class");
$LC_co = mam_db_row('SELECT * FROM cohorts WHERE legacy_class_id = ?', [$LC_class]);
t_ok($LC_co !== null && (int) $LC_co['course_id'] === $LC_course,
    'a class whose discipline names a course (any case) lands in a cohort of that course');
$LC_ce = static fn(string $k) => mam_db_row('SELECT * FROM course_enrollments WHERE student_id = ? AND course_id = ?', [$LC_s[$k], $LC_course]);
t_eq([$LC_ce('a')['status'] ?? null, $LC_ce('b')['status'] ?? null, $LC_ce('c')['status'] ?? null, $LC_ce('d')['status'] ?? null],
    ['active', 'active', 'completed', 'withdrawn'],
    'every enrolment keeps its state (paused is active in the cohort model), history included — the old bridge carried active ones only');
t_eq((int) ($LC_ce('a')['cohort_id'] ?? 0), (int) $LC_co['id'], '…in that cohort');

$LC_sess = mam_db_all("SELECT id, scheduled_at, status FROM class_sessions WHERE cohort_id = ? AND status = 'held' ORDER BY scheduled_at", [(int) $LC_co['id']]);
t_eq(array_map(fn($x) => substr($x['scheduled_at'], 0, 10) . ' ' . $x['status'], $LC_sess), ['2026-03-07 held', '2026-03-14 held'],
    'one held session per date the class met');
$LC_marks = mam_db_all('SELECT sa.status, sa.method, sa.marked_by FROM session_attendance sa JOIN class_sessions s ON s.id = sa.session_id
                         WHERE s.cohort_id = ? ORDER BY s.scheduled_at, sa.student_id', [(int) $LC_co['id']]);
t_eq(array_column($LC_marks, 'status'), ['present', 'late', 'absent'], 'every attendance mark is carried, as it was marked');
t_ok(count(array_unique(array_column($LC_marks, 'method'))) === 1 && $LC_marks[0]['method'] === 'legacy' && (int) $LC_marks[0]['marked_by'] === $LC_t,
    '…labelled as carried, keeping who marked it');
t_eq((int) mam_db_row('SELECT cohort_id FROM announcements WHERE id = ?', [$LC_ann])['cohort_id'], (int) $LC_co['id'], 'the class notice follows it into the cohort');
t_eq((int) mam_db_row('SELECT cohort_id FROM assignments WHERE id = ?', [$LC_hw])['cohort_id'], (int) $LC_co['id'], 'homework follows it too (v108)');

$LC_mine = static fn(array $rows) => array_values(array_intersect(array_column($rows, 'class_id'), [$LC_class, $LC_orphan]));
t_eq($LC_mine($LC_r['waiting']), [$LC_orphan], 'a class whose discipline names no course waits, and is named');
t_eq($LC_mine(mam_legacy_pending()), [$LC_orphan], '…and of these two classes, it is the one still pending');

$LC_again = mam_legacy_carry(null);
t_eq([$LC_again['cohorts'], $LC_again['enrolments'], $LC_again['sessions'], $LC_again['marks'], $LC_again['linked']], [0, 0, 0, 0, 0],
    'a second run changes nothing');

// A teacher marks the carried session differently: carrying again must not overwrite it.
$LC_first = (int) $LC_sess[0]['id'];
mam_db()->prepare('UPDATE session_attendance SET status = ?, method = ? WHERE session_id = ? AND student_id = ?')
    ->execute(['excused', 'instructor', $LC_first, $LC_s['a']]);
mam_legacy_carry(null);
t_eq(mam_db_row('SELECT status FROM session_attendance WHERE session_id = ? AND student_id = ?', [$LC_first, $LC_s['a']])['status'], 'excused',
    'a mark a teacher made is never overwritten by carrying again');

$LC_done = mam_legacy_carry(null, [$LC_orphan => $LC_course]);
t_ok(mam_db_row('SELECT id FROM course_enrollments WHERE student_id = ? AND course_id = ?', [$LC_s['a'], $LC_course]) !== null
    && $LC_mine($LC_done['waiting']) === [] && $LC_mine(mam_legacy_pending()) === [],
    'once an admin picks the course for the waiting class, it carries and nothing is pending');

// The suite shares one database: leave the catalogue as other files count it.
mam_db()->prepare('DELETE FROM course_enrollments WHERE course_id = ?')->execute([$LC_course]);
mam_db()->prepare('UPDATE cohorts SET course_id = NULL WHERE course_id = ?')->execute([$LC_course]);
mam_db()->prepare('DELETE FROM courses WHERE id = ?')->execute([$LC_course]);
