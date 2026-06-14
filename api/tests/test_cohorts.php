<?php
/**
 * Cohorts (LMS L1 — unify the two enrolment systems). The v19 `cohorts` table,
 * the idempotent classes→cohorts backfill, and the course_enrollments↔cohort
 * link (the first dual-write toward unification).
 */
declare(strict_types=1);
t_group('cohorts (classes→cohorts backfill + linking)');

// Seed a legacy class + an instructor.
$iid = mam_db_insert('users', ['email' => 't_coh_ins@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Cohort Instr', 'status' => 'active']);
$classId = mam_db_insert('classes', [
    'title' => 'Tuesday Piano', 'discipline' => 'piano', 'pathway' => 'standard',
    'instructor_id' => $iid, 'mode' => 'onsite', 'schedule' => 'Tue 5pm',
    'capacity' => 8, 'status' => 'active', 'starts_on' => '2026-07-01',
]);

// ── Backfill: one cohort per legacy class, carrying its descriptive fields ──
$n = mam_lms_backfill_cohorts();
t_ok($n >= 1, 'backfill creates a cohort for the legacy class');
$co = mam_db_row('SELECT * FROM cohorts WHERE legacy_class_id = ?', [$classId]);
t_ok($co !== null, 'cohort is linked to its source class via legacy_class_id');
t_eq($co['title'], 'Tuesday Piano', 'cohort carries the class title');
t_eq((int)$co['instructor_id'], $iid, 'cohort carries the instructor');
t_eq((int)$co['capacity'], 8, 'cohort carries the capacity');
t_eq($co['meeting_pattern'], 'Tue 5pm', 'class.schedule → cohort.meeting_pattern');
t_eq($co['status'], 'active', 'cohort carries the status');

// ── Idempotent: re-running creates nothing new ──
$n2 = mam_lms_backfill_cohorts();
t_eq($n2, 0, 're-running the backfill is a no-op (idempotent on legacy_class_id)');
t_eq((int)mam_db_row('SELECT COUNT(*) AS n FROM cohorts WHERE legacy_class_id = ?', [$classId])['n'], 1, 'still exactly one cohort for the class');

// ── create_cohort + fetch (a normalized/descriptive cohort, not from a class) ──
$cid = mam_lms_create_cohort(['title' => 'Cert Voice — Fall', 'instructor_id' => $iid, 'capacity' => 10, 'term' => '2026-Fall']);
$fetched = mam_lms_cohort($cid);
t_eq($fetched['title'], 'Cert Voice — Fall', 'create_cohort + fetch round-trips');
t_eq($fetched['instructor_name'], 'Cohort Instr', 'fetch resolves the instructor name');
t_ok($fetched['legacy_class_id'] === null, 'a freshly-created cohort has no legacy_class_id');

// ── Link a course-enrolment to a cohort (the dual-write) ──
$sid = mam_db_insert('users', ['email' => 't_coh_stu@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Coh Stu', 'status' => 'active']);
$courseRow = mam_db_row("SELECT id FROM courses WHERE code = 'piano'");
$eid = mam_db_insert('course_enrollments', ['student_id' => $sid, 'course_id' => (int)$courseRow['id'], 'status' => 'active']);
mam_lms_link_enrollment_cohort($eid, $cid);
t_eq((int)mam_db_row('SELECT cohort_id FROM course_enrollments WHERE id = ?', [$eid])['cohort_id'], $cid, 'enrolment is linked to the cohort');

// ── Bad ids throw ──
$threw = false; try { mam_lms_link_enrollment_cohort($eid, 99999); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'cohort_not_found'; }
t_ok($threw, 'linking to a missing cohort throws cohort_not_found');
$threw = false; try { mam_lms_link_enrollment_cohort(99999, $cid); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'enrollment_not_found'; }
t_ok($threw, 'linking a missing enrolment throws enrollment_not_found');

// ── Dual-write: add_course auto-links the new enrolment to a cohort ──────────
$s2 = mam_db_insert('users', ['email' => 't_coh_s2@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'S2', 'status' => 'active']);
$r2 = mam_lms_add_course($s2, 'guitar', 'precert', 'standard');
$link2 = (int)(mam_db_row('SELECT cohort_id FROM course_enrollments WHERE id = ?', [(int)$r2['id']])['cohort_id'] ?? 0);
t_ok($link2 > 0, 'add_course auto-links the new enrolment to a cohort');
$guitarId = (int)mam_db_row("SELECT id FROM courses WHERE code = 'guitar'")['id'];
t_eq((int)mam_lms_cohort($link2)['course_id'], $guitarId, 'the auto-cohort is for the right course');

// Same course+level+programme → the SAME cohort (one grouping, not per-student)
$s3 = mam_db_insert('users', ['email' => 't_coh_s3@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'S3', 'status' => 'active']);
$r3 = mam_lms_add_course($s3, 'guitar', 'precert', 'standard');
$link3 = (int)(mam_db_row('SELECT cohort_id FROM course_enrollments WHERE id = ?', [(int)$r3['id']])['cohort_id'] ?? 0);
t_eq($link3, $link2, 'a second enrolment in the same course+level+programme shares the cohort');

// Different level → a DIFFERENT cohort
$s4 = mam_db_insert('users', ['email' => 't_coh_s4@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'S4', 'status' => 'active']);
$r4 = mam_lms_add_course($s4, 'guitar', 'cert', 'standard');
$link4 = (int)(mam_db_row('SELECT cohort_id FROM course_enrollments WHERE id = ?', [(int)$r4['id']])['cohort_id'] ?? 0);
t_ok($link4 > 0 && $link4 !== $link2, 'a different level resolves to a different cohort');

// ensure_cohort is idempotent for a tuple (find-or-create)
t_eq(mam_lms_ensure_cohort($guitarId, (int)mam_db_row("SELECT id FROM levels WHERE code='precert'")['id'], (int)mam_db_row("SELECT id FROM programs WHERE code='standard'")['id']), $link2, 'ensure_cohort returns the existing cohort for a known tuple');
