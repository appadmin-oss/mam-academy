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
$lrCls = mam_db_insert('classes', ['title' => 'Report Piano', 'discipline' => 'piano', 'pathway' => 'precert', 'instructor_id' => $lrIns, 'status' => 'active']);
$lrEnr = mam_db_insert('enrollments', ['student_id' => $lrStu, 'class_id' => $lrCls, 'status' => 'active']);
mam_db_insert('attendance', ['enrollment_id' => $lrEnr, 'session_date' => '2026-08-01', 'status' => 'present', 'marked_by' => $lrIns]);
mam_db_insert('practice_logs', ['student_id' => $lrStu, 'minutes' => 30, 'focus' => 'scales', 'logged_on' => '2026-08-02']);

// ── Happy path: the full report assembles with all sections present ──
$rep = mam_progress_report_data((int)$lrStu);
foreach (['student','enrollments','course_enrollments','class_summary','sessions','upcoming_sessions',
          'assessments','results','certificates','announcements','practice_logs','total_practice_min','generated_at'] as $k) {
    t_ok(array_key_exists($k, $rep), "report includes the '$k' section");
}
t_eq((int)$rep['student']['id'], (int)$lrStu, 'report is for the requested student');
t_eq(count($rep['enrollments']), 1, 'the active legacy enrolment is listed');
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
// Done inside a transaction so the DROP is rolled back and the shared test DB
// is left intact for the tests that run after this one.
$pdo = mam_db();
$pdo->beginTransaction();
$pdo->exec('DROP TABLE practice_logs');   // simulate a not-yet-migrated table
$degraded = null; $degradeThrew = false;
try { $degraded = mam_progress_report_data((int)$lrStu); } catch (\Throwable $e) { $degradeThrew = true; }
$pdo->rollBack();                          // restore practice_logs for later tests

t_ok(!$degradeThrew, 'a missing section table does NOT throw (report degrades instead of 500)');
t_ok(is_array($degraded) && $degraded['practice_logs'] === [], 'the missing section degrades to an empty list');
t_eq((int)($degraded['total_practice_min'] ?? -1), 0, 'the roll-up over the missing section is a safe 0');
t_eq(count($degraded['enrollments']), 1, 'unaffected sections still render in a degraded report');
