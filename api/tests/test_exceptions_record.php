<?php
/**
 * §15 exception management, §37's six ledger statuses, and the §43 unified
 * student academic record.
 */
declare(strict_types=1);

t_group('accountability — the §37 status vocabulary');

// Was a hardcoded 6. A literal count breaks the moment a status is added and
// says nothing about what the set MEANS — the same lesson the automations
// registry taught twice. Assert the semantics instead.
foreach (['pending', 'outstanding', 'paid', 'waived', 'disputed', 'reversed'] as $__st) {
    t_ok(in_array($__st, MAM_ACCT_STATUSES, true), "§37 status `$__st` exists");
}
t_ok(in_array(MAM_ACCT_RECORDED, MAM_ACCT_STATUSES, true),
    '`recorded` joins them — a lateness logged with no money owed');
t_eq(array_values(array_intersect(MAM_ACCT_OWED, MAM_ACCT_STATUSES)), MAM_ACCT_OWED,
    'every owed status is a real status');
t_ok(!in_array(MAM_ACCT_RECORDED, MAM_ACCT_OWED, true),
    '…and `recorded` is never owed, so a no-charge record cannot become a debt');
foreach (['pending', 'outstanding', 'paid', 'waived', 'disputed', 'reversed'] as $s) {
    t_ok(in_array($s, MAM_ACCT_STATUSES, true), "'$s' is a ledger status");
}
t_eq(count(MAM_ACCT_EXCEPTION_REASONS), 7, '§15 lists seven exception reasons');
t_ok(isset(MAM_ACCT_EXCEPTION_REASONS['force_majeure']), 'force majeure is one of them');

t_group('accountability — the §15 request → decision flow');

$EX_admin = mam_db_insert('users', ['name' => 'EX Admin', 'email' => 'ex_admin@test.local', 'role' => 'admin', 'password_hash' => '', 'status' => 'active']);
$EX_stu   = mam_db_insert('users', ['name' => 'EX Stu', 'email' => 'ex_stu@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
$EX_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($EX_course === 0) $EX_course = mam_db_insert('courses', ['code' => 'ex_c', 'name' => 'EX', 'kind' => 'core']);
$EX_coh  = mam_db_insert('cohorts', ['course_id' => $EX_course, 'title' => 'EX Cohort', 'status' => 'active', 'capacity' => 10]);
mam_db_insert('course_enrollments', ['student_id' => $EX_stu, 'course_id' => $EX_course, 'cohort_id' => $EX_coh, 'status' => 'active']);
$EX_s1 = mam_db_insert('class_sessions', ['cohort_id' => $EX_coh, 'title' => 'EX S1', 'scheduled_at' => '2026-09-02 10:00:00', 'status' => 'held', 'duration_min' => 60, 'kind' => 'class']);
$EX_s2 = mam_db_insert('class_sessions', ['cohort_id' => $EX_coh, 'title' => 'EX S2', 'scheduled_at' => '2026-09-03 10:00:00', 'status' => 'held', 'duration_min' => 60, 'kind' => 'class']);

mam_setting_set('late_charge_enabled', '1');   // the gate is closed by default
$c1 = mam_accountability_charge('student', $EX_stu, $EX_s1, 'late_arrival', 7);
t_eq(mam_accountability_outstanding_cents('student', $EX_stu), 100, 'the charge is owed to begin with');

$req = mam_accountability_request_exception($c1, $EX_stu, 'academy_technical', 'The academy Meet room would not load.');
t_ok($req['already'] === false, 'the first request is new');
t_eq((string) $req['charge']['status'], 'pending', 'an open request moves the charge to pending');
// The point of `pending`: the student is not shown a debt while it is undecided.
t_eq(mam_accountability_outstanding_cents('student', $EX_stu), 0, 'a pending charge is not counted as owed');

$again = mam_accountability_request_exception($c1, $EX_stu, 'force_majeure', 'Second try');
t_ok($again['already'] === true, 'asking twice returns the open request rather than queueing a second decision');
t_eq((int) $again['request']['id'], (int) $req['request']['id'], 'and it is the same request');

$threw = false;
try { mam_accountability_request_exception($c1, $EX_stu, 'because_i_said_so'); } catch (\InvalidArgumentException $e) { $threw = true; }
t_ok($threw, 'a reason outside the §15 vocabulary is refused');

$queue = mam_accountability_exception_queue();
$ids   = array_map(fn($r) => (int) $r['id'], $queue);
t_ok(in_array((int) $req['request']['id'], $ids, true), 'the request appears in the admin queue');

// §15: "Rejected — Charge Maintained".
$dec = mam_accountability_decide_exception((int) $req['request']['id'], $EX_admin, false, 'Meet logs show the room was up.');
t_eq((string) $dec['request']['status'], 'rejected', 'the request is recorded as rejected');
t_eq((string) $dec['charge']['status'], 'outstanding', 'a rejected exception restores the charge, it does not drop it');
t_eq(mam_accountability_outstanding_cents('student', $EX_stu), 100, 'and the balance comes back');

$threw = false;
try { mam_accountability_decide_exception((int) $req['request']['id'], $EX_admin, true, null); } catch (\RuntimeException $e) { $threw = ($e->getMessage() === 'already_decided'); }
t_ok($threw, 'a decided request cannot be decided again');

// §15: "Approved — Charge Waived".
$c2   = mam_accountability_charge('student', $EX_stu, $EX_s2, 'late_arrival', 4);
$req2 = mam_accountability_request_exception($c2, $EX_stu, 'approved_emergency', 'Family emergency.');
$dec2 = mam_accountability_decide_exception((int) $req2['request']['id'], $EX_admin, true, null);
t_eq((string) $dec2['charge']['status'], 'waived', 'an approved exception waives the charge');
t_ok(strpos((string) $dec2['charge']['resolution_note'], 'Approved emergency') !== false,
     'and records the human-readable reason when no note was given');

// A terminal charge cannot have an exception raised against it.
$threw = false;
try { mam_accountability_request_exception($c2, $EX_stu, 'other'); } catch (\RuntimeException $e) { $threw = ($e->getMessage() === 'already_resolved'); }
t_ok($threw, 'a waived charge cannot be disputed back open');

// `disputed` is non-terminal: it still counts as owed and can still be settled.
$led = mam_accountability_ledger('student', $EX_stu);
t_ok($led['charges'][0]['exception'] !== null || $led['charges'][1]['exception'] !== null,
     'the ledger surfaces the exception alongside its charge');
mam_accountability_resolve($c1, $EX_admin, 'disputed', 'Student is contesting the rejection.');
t_eq(mam_accountability_outstanding_cents('student', $EX_stu), 100, 'a disputed charge is still owed');
$paid = mam_accountability_resolve($c1, $EX_admin, 'paid', null);
t_eq((string) $paid['status'], 'paid', 'a disputed charge can still be settled');
t_eq(mam_accountability_outstanding_cents('student', $EX_stu), 0, 'settling clears the balance');

t_group('the §43 unified academic record');

$threw = false;
try { mam_academic_record($EX_admin); } catch (\RuntimeException $e) { $threw = ($e->getMessage() === 'not_found'); }
t_ok($threw, 'a non-student id is refused rather than returning an empty shell');
$threw = false;
try { mam_academic_record(99999999); } catch (\RuntimeException $e) { $threw = ($e->getMessage() === 'not_found'); }
t_ok($threw, 'an unknown id is refused');

$rec = mam_academic_record($EX_stu);
t_eq(count(MAM_RECORD_SECTIONS), 14, '§43 names fourteen sections');
foreach (array_keys(MAM_RECORD_SECTIONS) as $section) {
    t_ok(array_key_exists($section, $rec), "the record contains the '$section' section");
}
t_eq($rec['personal_profile']['id'], $EX_stu, 'the profile identifies the student');
t_eq($rec['personal_profile']['name'], 'EX Stu', 'by name');
t_ok(is_array($rec['curriculum']['course_enrollments']), 'curriculum reads the cohort model');
t_ok(is_array($rec['curriculum']['legacy_classes']), 'and the legacy class model — a half-record would be wrong');
t_eq($rec['accountability']['occurrences'], 2, 'accountability carries both charges');
t_ok($rec['mastery'] !== null && array_key_exists('score', $rec['mastery']), 'mastery is the §24 score object');
t_ok(array_key_exists('ready', $rec['certification']), 'certification reports readiness');
t_ok($rec['certification']['ready'] === false, 'a student with no completed modules is not certification-ready');
t_ok(is_array($rec['communication']['notifications']), 'communication lists notifications');
t_ok(is_array($rec['communication']['emails']), 'and academic mail');
t_ok(is_array($rec['carry_forward']), 'carry-forward history is present even when empty');
t_ok($rec['intervention'] === null || isset($rec['intervention']['level']), 'intervention is either absent or carries a §31 level');
t_eq($rec['time']['sessions_clocked_in'], 0, 'time reports nothing clocked when nothing was clocked');
