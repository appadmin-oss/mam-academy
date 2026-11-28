<?php
/**
 * The single teaching model (teaching.php, schema v111 cohort_staff).
 * The classes audit (docs/classes-audit-2026-11-28.md §2) found the same class
 * seen three different ways by three teachers. This pins the corrected table:
 *   A  the lead          — the whole class
 *   C  named on ONE enrolment at approval — that student only, never the class,
 *                          and nothing once that student withdraws
 *   D  the cover for one session — that session, on its day, and nothing else
 * plus the A→B lead change, the vetting refusal under `enforce`, the
 * idempotent backfill, and a static guard against the inline copies returning.
 */
declare(strict_types=1);

$TM_mk = fn(string $tag, string $role) => (int) mam_db_insert('users', ['email' => "tm_$tag@test.local", 'password_hash' => 'x',
    'role' => $role, 'name' => 'TM ' . strtoupper($tag), 'status' => 'active']);
$TM_A = $TM_mk('a', 'instructor'); $TM_B = $TM_mk('b', 'instructor'); $TM_C = $TM_mk('c', 'instructor');
$TM_D = $TM_mk('d', 'instructor'); $TM_E = $TM_mk('e', 'instructor'); $TM_F = $TM_mk('f', 'instructor');
$TM_s1 = $TM_mk('s1', 'student'); $TM_s2 = $TM_mk('s2', 'student');
$TM_course = (int) mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];

mam_setting_set('safeguarding_gate_mode', 'warn');
$TM_cid = (int) mam_lms_create_cohort(['title' => 'Teaching Model Cohort', 'instructor_id' => $TM_A]);
$TM_e1 = (int) mam_db_insert('course_enrollments', ['student_id' => $TM_s1, 'course_id' => $TM_course, 'status' => 'active',
    'cohort_id' => $TM_cid, 'instructor_id' => $TM_A]);
$TM_e2 = (int) mam_db_insert('course_enrollments', ['student_id' => $TM_s2, 'course_id' => $TM_course, 'status' => 'active',
    'cohort_id' => $TM_cid, 'instructor_id' => $TM_C]);
$TM_sessDay = '2031-03-10';
$TM_sess1 = (int) mam_lms_create_session($TM_cid, ['title' => 'TM One', 'scheduled_at' => $TM_sessDay . ' 10:00:00']);
$TM_sess2 = (int) mam_lms_create_session($TM_cid, ['title' => 'TM Two', 'scheduled_at' => '2031-03-17 10:00:00']);
$TM_onDay  = (new DateTimeImmutable($TM_sessDay . ' 12:00:00', new DateTimeZone('Africa/Lagos')))->getTimestamp();
$TM_dayOff = (new DateTimeImmutable('2031-03-11 12:00:00', new DateTimeZone('Africa/Lagos')))->getTimestamp();

t_group('teaching model — creating a class writes its lead');

t_ok((bool) mam_db_row("SELECT 1 FROM cohort_staff WHERE cohort_id = ? AND instructor_id = ? AND role = 'lead'", [$TM_cid, $TM_A]),
    'mam_lms_create_cohort() records the lead in cohort_staff');
t_eq(mam_cohort_lead_id($TM_cid), $TM_A, 'the lead reads back from cohort_staff');

t_group('teaching model — A (lead), C (named on one enrolment), D (cover)');

t_ok(mam_teaches($TM_A, $TM_cid), 'A teaches the class');
t_ok(mam_teaches_session($TM_A, $TM_sess1) && mam_teaches_session($TM_A, $TM_sess2), 'A may take every session\'s register');
t_ok(mam_teaches_student($TM_A, $TM_s1) && mam_teaches_student($TM_A, $TM_s2), 'A teaches both students');
t_ok(in_array($TM_cid, mam_instructor_cohort_ids($TM_A), true), 'the class is in A\'s list');

t_ok(!mam_teaches($TM_C, $TM_cid), 'C does NOT own the class (was: yes)');
t_ok(!in_array($TM_cid, mam_instructor_cohort_ids($TM_C), true), 'the class is not in C\'s list');
t_ok(mam_teaches_student($TM_C, $TM_s2), 'C teaches the student whose enrolment names them');
t_ok(!mam_teaches_student($TM_C, $TM_s1), '…and not the other student in the class');
t_ok(!mam_teaches_session($TM_C, $TM_sess1), 'C cannot take the class register');

mam_cohort_staff_add($TM_cid, $TM_D, 'cover', $TM_sess1, null, null, null);
t_eq((int) mam_db_row('SELECT substitute_id FROM class_sessions WHERE id = ?', [$TM_sess1])['substitute_id'], $TM_D,
    'booking a cover sets the session\'s substitute (compatibility column)');
t_ok(mam_teaches_session($TM_D, $TM_sess1), 'D may take the register of the session they cover (was: refused)');
t_ok(!mam_teaches_session($TM_D, $TM_sess2), 'D may not take another session\'s register');
t_ok(mam_teaches($TM_D, $TM_cid, $TM_onDay), 'D teaches the class on the day of the session');
t_ok(!mam_teaches($TM_D, $TM_cid, $TM_dayOff), '…and not the day after');
t_ok(!mam_teaches($TM_D, $TM_cid), '…and not today');
t_ok(mam_teaches_student($TM_D, $TM_s1, null, 'teach', false, $TM_onDay), 'D sees the class\'s students on that day');
[$TM_sq, $TM_sa] = mam_teaching_session_sql('cs', $TM_D);
$TM_seen = array_map('intval', array_column(mam_db_all("SELECT cs.id FROM class_sessions cs WHERE cs.cohort_id = ? AND $TM_sq", array_merge([$TM_cid], $TM_sa)), 'id'));
t_eq($TM_seen, [$TM_sess1], 'a session list shows D only the session they cover, not the class timetable');

t_group('teaching model — C loses the student on withdrawal');

mam_db_update('course_enrollments', ['status' => 'withdrawn'], ['id' => $TM_e2]);
t_ok(!mam_teaches_student($TM_C, $TM_s2), 'once that student withdraws, C teaches nobody here');
t_ok(mam_teaches_student($TM_C, $TM_s2, null, 'history'), '…but keeps the history');
t_ok(!mam_teaches($TM_C, $TM_cid), '…and never owned the class');
mam_db_update('course_enrollments', ['status' => 'active'], ['id' => $TM_e2]);

t_group('teaching model — assistants and ending a row');

$TM_asst = mam_cohort_staff_add($TM_cid, $TM_F, 'assistant', null, null, null, null);
t_ok(mam_teaches($TM_F, $TM_cid) && mam_teaches_student($TM_F, $TM_s1), 'an assistant teaches the whole class');
mam_cohort_staff_end($TM_asst, 'test', null);
t_ok(!mam_teaches($TM_F, $TM_cid), 'an ended assistant has no access from today');
t_ok(mam_teaches($TM_F, $TM_cid, null, 'history'), '…and keeps the history');
$TM_threw = '';
$TM_leadRow = (int) mam_db_row("SELECT id FROM cohort_staff WHERE cohort_id = ? AND role = 'lead' AND ends_on IS NULL", [$TM_cid])['id'];
try { mam_cohort_staff_end($TM_leadRow, 'x', null); } catch (Throwable $e) { $TM_threw = $e->getMessage(); }
t_eq($TM_threw, 'use_set_lead', 'a lead cannot be ended directly — the class would be left without one');
$TM_threw = '';
try { mam_cohort_staff_add($TM_cid, $TM_F, 'cover', null, null, null, null); } catch (Throwable $e) { $TM_threw = $e->getMessage(); }
t_eq($TM_threw, 'cover_needs_dates', 'a cover needs a session or two dates');

t_group('teaching model — the lead changes from A to B');

mam_db_update('course_enrollments', ['status' => 'completed'], ['id' => $TM_e1]);
$TM_r = mam_cohort_set_lead($TM_cid, $TM_B, null);
t_ok($TM_r['changed'] && $TM_r['from'] === $TM_A && $TM_r['to'] === $TM_B, 'set_lead reports A → B');
t_ok(mam_teaches($TM_B, $TM_cid), 'B teaches the class');
t_ok(!mam_teaches($TM_A, $TM_cid), 'A no longer has access (was: kept it through the enrolments)');
t_ok(mam_teaches($TM_A, $TM_cid, null, 'history'), 'A keeps read access to their past work');
t_eq((int) mam_db_row('SELECT instructor_id FROM cohorts WHERE id = ?', [$TM_cid])['instructor_id'], $TM_B,
    'cohorts.instructor_id follows the lead');
t_eq((int) mam_db_row('SELECT instructor_id FROM course_enrollments WHERE id = ?', [$TM_e1])['instructor_id'], $TM_A,
    'a completed enrolment keeps the teacher who taught it');
t_eq((int) mam_db_row('SELECT instructor_id FROM course_enrollments WHERE id = ?', [$TM_e2])['instructor_id'], $TM_C,
    'a deliberate one-off teacher on an active enrolment stays');
t_ok(!mam_cohort_set_lead($TM_cid, $TM_B, null)['changed'], 'setting the same lead again changes nothing');
t_eq((int) mam_db_row("SELECT COUNT(*) AS n FROM cohort_staff WHERE cohort_id = ? AND role = 'lead' AND (ends_on IS NULL OR ends_on >= ?)",
    [$TM_cid, mam_session_today()])['n'], 1, 'exactly one current lead');

t_group('teaching model — the safeguarding gate refuses under enforce');

mam_setting_set('safeguarding_gate_mode', 'enforce');
mam_setting_set('safeguarding_unknown_age_is_minor', '1');
$TM_threw = '';
try { mam_cohort_staff_add($TM_cid, $TM_E, 'assistant', null, null, null, null); } catch (Throwable $e) { $TM_threw = $e->getMessage(); }
t_ok(str_starts_with($TM_threw, 'vetting_required'), 'an uncleared assistant is refused on a class with a child [' . $TM_threw . ']');
$TM_threw = '';
try { mam_cohort_set_lead($TM_cid, $TM_E, null); } catch (Throwable $e) { $TM_threw = $e->getMessage(); }
t_ok(str_starts_with($TM_threw, 'vetting_required'), 'an uncleared lead is refused too');
t_eq(mam_cohort_lead_id($TM_cid), $TM_B, 'a refused change leaves the lead as it was');
t_ok(!mam_teaches($TM_E, $TM_cid), 'the refused instructor has no access');
mam_setting_set('safeguarding_gate_mode', 'warn');

t_group('teaching model — the backfill heals stray writes and is idempotent');

mam_cohort_staff_backfill();   // settle anything other tests left behind
mam_db_update('cohorts', ['instructor_id' => $TM_F], ['id' => $TM_cid]);       // a write around set_lead
$TM_s3 = (int) mam_lms_create_session($TM_cid, ['title' => 'TM Three', 'scheduled_at' => '2031-03-24 10:00:00']);
mam_db_update('class_sessions', ['substitute_id' => $TM_D], ['id' => $TM_s3]);  // a substitute set the old way
t_eq(mam_cohort_staff_backfill(), 2, 'the backfill adds the lead row and the cover row');
t_eq(mam_cohort_staff_backfill(), 0, 'a second run adds nothing');
t_eq(mam_cohort_lead_id($TM_cid), $TM_F, 'the healed lead is the one cohorts.instructor_id names');
t_ok(!mam_teaches($TM_B, $TM_cid), 'the lead it replaced no longer has access');
t_ok(mam_teaches_session($TM_D, $TM_s3), 'the backfilled cover may take that session\'s register');

t_group('teaching model — no inline "does this teacher teach" copies come back');

$TM_root = dirname(MAM_API_ROOT);
$TM_files = [];
foreach (['api/modules', 'api/routes', 'admin', 'partials'] as $TM_d) {
    $TM_it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($TM_root . '/' . $TM_d, FilesystemIterator::SKIP_DOTS));
    foreach ($TM_it as $TM_f) if (str_ends_with((string) $TM_f, '.php')) $TM_files[] = (string) $TM_f;
}
foreach (glob($TM_root . '/*.php') as $TM_f) $TM_files[] = $TM_f;
$TM_bad = [];
$TM_patterns = [
    '/co\.instructor_id\s*=\s*\?\s*OR\s+(ce\.instructor_id|co\.id\s+IN)/i',
    '/cohort_id\s+FROM\s+course_enrollments\s+WHERE\s+instructor_id\s*=\s*\?/i',
    '/IN\s*\(\s*SELECT\s+id\s+FROM\s+cohorts\s+WHERE\s+instructor_id\s*=\s*\?\s*\)/i',
    '/\b(ce|co|coh)\.instructor_id\s*=\s*\'\s*\.\s*\$/',   // the id concatenated in, as instructor/student.php once did
];
foreach ($TM_files as $TM_f) {
    if (str_ends_with($TM_f, '/academics/lib/teaching.php')) continue;
    $TM_src = (string) file_get_contents($TM_f);
    foreach ($TM_patterns as $TM_p) if (preg_match($TM_p, $TM_src)) { $TM_bad[] = substr($TM_f, strlen($TM_root) + 1); break; }
}
t_ok(count($TM_files) > 300, 'the guard scans the code [' . count($TM_files) . ' files]');
t_eq($TM_bad, [], 'who teaches a class is asked of teaching.php, never re-derived inline');
