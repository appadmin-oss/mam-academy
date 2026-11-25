<?php
/**
 * Learner progress report (mam_progress_report_data) — the public parent-facing
 * report behind /api/learner-lookup and /api/report.
 *
 * REGRESSION: the report stitches together ~11 independent sections. It used to
 * run every query bare, so a single missing table/column on a deployment whose
 * DB is behind on migrations (the #1 production gotcha) 500'd the WHOLE report.
 * It now mirrors the student dashboard's $soft() degradation: a broken section
 * is omitted (and logged), the rest of the report still renders.
 */
declare(strict_types=1);
t_group('learner progress report');

$lrStu = mam_db_insert('users', ['email' => 't_lr_stu@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Report Kid', 'status' => 'active', 'enrollment_ref' => 'MAM/26/700']);
$lrIns = mam_db_insert('users', ['email' => 't_lr_ins@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Report Ins', 'status' => 'active']);
// The cohort model only — the old class tables are retired, and a class's marks
// were carried into held class_sessions + session_attendance (method 'legacy').
$lrCourse = (int) mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];
$lrCoh = mam_db_insert('cohorts', ['course_id' => $lrCourse, 'instructor_id' => $lrIns, 'title' => 'Report Piano', 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $lrStu, 'course_id' => $lrCourse, 'cohort_id' => $lrCoh, 'instructor_id' => $lrIns, 'status' => 'active']);
foreach ([['2026-08-01 10:00:00', 'present'], ['2026-08-08 10:00:00', 'late'], ['2026-08-15 10:00:00', 'absent']] as [$lrAt, $lrSt]) {
    $lrSess = mam_db_insert('class_sessions', ['cohort_id' => $lrCoh, 'title' => 'Report Piano', 'scheduled_at' => $lrAt, 'status' => 'held']);
    mam_db_insert('session_attendance', ['session_id' => $lrSess, 'student_id' => $lrStu, 'status' => $lrSt, 'method' => 'legacy']);
}
// An enrolment left only in the old table is not read.
$lrCls = mam_db_insert('classes', ['title' => 'Old Piano', 'discipline' => 'piano', 'instructor_id' => $lrIns, 'status' => 'active']);
mam_db_insert('enrollments', ['student_id' => $lrStu, 'class_id' => $lrCls, 'status' => 'active']);
mam_db_insert('practice_logs', ['student_id' => $lrStu, 'minutes' => 30, 'focus' => 'scales', 'logged_on' => '2026-08-02']);

// ── Happy path: the full report assembles with all sections present ──
$rep = mam_progress_report_data((int)$lrStu);
foreach (['student','course_enrollments','class_summary','sessions','upcoming_sessions',
          'assessments','results','certificates','announcements','practice_logs','total_practice_min','generated_at'] as $k) {
    t_ok(array_key_exists($k, $rep), "report includes the '$k' section");
}
t_eq((int)$rep['student']['id'], (int)$lrStu, 'report is for the requested student');
t_ok(!array_key_exists('enrollments', $rep), 'the old class enrolments are not a section any more');
t_eq(count($rep['course_enrollments']), 1, 'the course enrolment is listed (and the old class one is not)');
t_eq(count($rep['class_summary']), 1, 'one attendance summary per cohort');
$lrSum = $rep['class_summary'][0];
t_eq($lrSum['cohort_title'], 'Report Piano', 'the summary names the cohort');
t_eq((int) $lrSum['total_sessions'], 3, 'the summary counts the held sessions');
t_eq((int) $lrSum['attended'], 2, 'present and late both count as attended');
t_eq((int) $lrSum['absent'], 1, 'absences are counted');
t_eq((int) $lrSum['late'], 1, 'lateness is counted');
t_eq((int) $lrSum['attendance_rate'], 67, 'the rate is attended over held');
t_eq((int)$rep['total_practice_min'], 30, 'practice minutes roll up');

// A ref lookup resolves the active student; a bad ref does not.
t_eq(mam_enrollment_ref_lookup('MAM/26/700'), (int)$lrStu, 'enrollment_ref lookup resolves the student');
t_ok(mam_enrollment_ref_lookup('MAM/26/000-nope') === null, 'an unknown ref resolves to null');

// A non-existent student is a hard (caller-visible 404) error, not a soft empty.
$threw = false;
try { mam_progress_report_data(99999999); } catch (\RuntimeException $e) { $threw = ($e->getMessage() === 'student_not_found'); }
t_ok($threw, 'a missing student throws student_not_found (core identity stays strict)');

// ── Degradation: a section whose table is missing (DB behind on migrations)
// must NOT 500 the report — that section degrades to empty, the rest survives.
//
// The table is RENAMED aside and renamed back, not dropped inside a transaction.
// DDL is transactional on SQLite, so the original version rolled the DROP back;
// on MySQL a DROP is an implicit COMMIT, so the rollBack() threw "there is no
// active transaction" AND `practice_logs` stayed dropped, breaking every test
// that ran afterwards. A rename is portable and reversible on both engines.
$pdo = mam_db();
$pdo->exec('ALTER TABLE practice_logs RENAME TO practice_logs_hidden');
$degraded = null; $degradeThrew = false;
try { $degraded = mam_progress_report_data((int)$lrStu); } catch (\Throwable $e) { $degradeThrew = true; }
$pdo->exec('ALTER TABLE practice_logs_hidden RENAME TO practice_logs');

t_ok(!$degradeThrew, 'a missing section table does NOT throw (report degrades instead of 500)');
t_ok(is_array($degraded) && $degraded['practice_logs'] === [], 'the missing section degrades to an empty list');
t_eq((int)($degraded['total_practice_min'] ?? -1), 0, 'the roll-up over the missing section is a safe 0');
t_eq(count($degraded['course_enrollments']), 1, 'unaffected sections still render in a degraded report');
