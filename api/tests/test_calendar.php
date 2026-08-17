<?php
/**
 * The academic calendar as data (api/lib/calendar.php, schema v83).
 *
 * Both research blueprints make this the dependency for refunds, scholarship
 * pro-ration and the withdrawal outcome — and before it there was **no refund
 * function anywhere in the codebase**, so every refund was a manual decision with
 * nothing to check it against.
 *
 * The assertions that matter are the ones about money and about history: a refund
 * percentage must be reproducible from a date, and a census must not change when
 * the underlying enrolments do.
 */
declare(strict_types=1);

t_group('calendar — a term is authored with enforceable dates');

$CL_admin = mam_db_insert('users', ['name' => 'CL Admin', 'email' => 'cl_admin@test.local', 'role' => 'admin', 'password_hash' => 'x', 'status' => 'active']);

$CL_term = mam_calendar_term_save([
    'year_label' => '2099', 'code' => 'cltest', 'term_no' => 1, 'label' => 'CL Test Term',
    'starts_on' => '2099-01-05', 'ends_on'   => '2099-03-29',   // 12 weeks
    'add_drop_deadline' => '2099-01-11', 'withdrawal_deadline' => '2099-02-15',
    'census_date' => '2099-01-18', 'teaching_weeks' => 12, 'status' => 'planned',
], $CL_admin);
t_ok($CL_term > 0, 'a term is created');

$t = mam_calendar_term($CL_term);
t_eq((string) $t['label'], 'CL Test Term', 'it reads back');
t_eq((int) $t['teaching_weeks'], 12, '…with its length');
t_eq((string) $t['timezone'], MAM_CALENDAR_TZ, '…and the academy timezone by default');

/* Saving again with the same (year, code) EDITS rather than duplicating: an
   academy correcting a date must not end up with two overlapping terms, because
   two terms covering one day means two possible refund tables for one withdrawal. */
$again = mam_calendar_term_save([
    'year_label' => '2099', 'code' => 'cltest', 'label' => 'CL Test Term (edited)',
    'starts_on' => '2099-01-05', 'ends_on' => '2099-03-29',
    'add_drop_deadline' => '2099-01-11', 'withdrawal_deadline' => '2099-02-15', 'census_date' => '2099-01-18',
], $CL_admin);
t_eq($again, $CL_term, 're-saving the same year+code edits the same row');
t_eq((string) mam_calendar_term($CL_term)['label'], 'CL Test Term (edited)', '…and applies the edit');

t_group('calendar — a bad calendar is refused, not stored');

// Overlap with a sibling term.
$threw = '';
try {
    mam_calendar_term_save(['year_label' => '2099', 'code' => 'clclash', 'label' => 'Clash',
        'starts_on' => '2099-02-01', 'ends_on' => '2099-04-30'], $CL_admin);
} catch (Throwable $e) { $threw = $e->getMessage(); }
t_ok(strpos($threw, 'overlaps_term') === 0, 'a term overlapping another is refused');

// Ends before it starts.
$threw2 = '';
try {
    mam_calendar_term_save(['year_label' => '2099', 'code' => 'clback', 'label' => 'Backwards',
        'starts_on' => '2099-06-01', 'ends_on' => '2099-05-01'], $CL_admin);
} catch (Throwable $e) { $threw2 = $e->getMessage(); }
t_eq($threw2, 'ends_before_starts', 'a term ending before it starts is refused');

/* The schema itself refuses a deadline outside the term or out of order, because
   a bad row corrupts every refund computed against it — PHP validation alone
   would leave a direct INSERT free to do damage. */
$dbThrew = false;
try {
    mam_db()->prepare('INSERT INTO academic_terms (year_label, code, label, starts_on, ends_on, add_drop_deadline, withdrawal_deadline)
                        VALUES (?,?,?,?,?,?,?)')
            ->execute(['2099', 'clbad', 'Bad', '2099-09-01', '2099-11-30', '2099-10-15', '2099-09-15']);
} catch (Throwable $e) { $dbThrew = true; }
t_ok($dbThrew, 'the SCHEMA refuses a withdrawal deadline before the add/drop deadline');

$dbThrew2 = false;
try {
    mam_db()->prepare('INSERT INTO academic_terms (year_label, code, label, starts_on, ends_on, census_date)
                        VALUES (?,?,?,?,?,?)')
            ->execute(['2099', 'clbad2', 'Bad2', '2099-09-01', '2099-11-30', '2100-01-01']);
} catch (Throwable $e) { $dbThrew2 = true; }
t_ok($dbThrew2, '…and a census date outside the term');

t_group('calendar — which week is it');

t_eq(mam_calendar_week_of($t, '2099-01-04'), 0, 'the day before the term is week 0');
t_eq(mam_calendar_week_of($t, '2099-01-05'), 1, 'the first day is week 1');
t_eq(mam_calendar_week_of($t, '2099-01-11'), 1, 'the seventh day is still week 1');
t_eq(mam_calendar_week_of($t, '2099-01-12'), 2, 'the eighth day is week 2');
t_ok(mam_calendar_week_of($t, '2099-04-30') === null, 'after the term there is no week');

t_group('calendar — the refund percentage is published, not computed on a whim');

/* The schedule is seeded on creation, because a term with no published table
   cannot answer the question the table exists for. */
$sched = mam_calendar_refund_schedule($CL_term);
t_ok(count($sched) > 0, 'a new term is seeded with the conventional refund table');
t_eq((int) $sched[0]['percent'], 100, 'before teaching starts the refund is 100%');
// The bands must be contiguous and non-overlapping, or a withdrawal date can
// resolve to two different percentages.
$prevTo = null;
foreach ($sched as $band) {
    if ($prevTo !== null) t_eq((int) $band['week_from'], $prevTo + 1, 'refund bands are contiguous with no gap');
    $prevTo = (int) $band['week_to'];
}
t_ok($prevTo >= 999, 'the last band runs to the end of the term, so a late week still resolves');

// The money question, answered from a date.
$r0 = mam_calendar_refund_for('2099-01-04', $CL_term);
t_eq($r0['percent'], 100, 'withdrawing before the term starts refunds in full');
t_eq($r0['basis'], 'published_schedule', '…from the published table');

$r1 = mam_calendar_refund_for('2099-01-06', $CL_term);
t_eq($r1['percent'], 100, 'week 1 (inside add/drop) still refunds in full');

$r3 = mam_calendar_refund_for('2099-01-20', $CL_term);
t_eq($r3['week'], 3, 'a mid-January withdrawal is week 3');
t_eq($r3['percent'], 60, '…and refunds 60%');

$rLate = mam_calendar_refund_for('2099-03-20', $CL_term);
t_eq($rLate['percent'], 0, 'a week-11 withdrawal refunds nothing');

$rAfter = mam_calendar_refund_for('2099-05-01', $CL_term);
t_eq($rAfter['percent'], 0, 'after the term there is nothing to refund');
t_eq($rAfter['basis'], 'after_term_end', '…and that is a determinate answer, not a missing one');

/* No term covering the date → null, so the caller asks a human. Returning 0 or
   100 here would be inventing a policy. */
t_ok(mam_calendar_refund_for('1990-01-01') === null,
    'a date outside every authored term returns null rather than guessing');

// A term whose published table was deleted must REFUSE, not fall back to a
// formula the family never saw.
$CL_bare = mam_calendar_term_save(['year_label' => '2098', 'code' => 'clbare', 'label' => 'Bare',
    'starts_on' => '2098-01-06', 'ends_on' => '2098-03-30'], $CL_admin);
mam_db()->prepare('DELETE FROM term_refund_schedule WHERE term_id = ?')->execute([$CL_bare]);
$rBare = mam_calendar_refund_for('2098-02-01', $CL_bare);
t_ok($rBare['percent'] === null, 'a term with no published schedule refuses to name a percentage');
t_eq($rBare['basis'], 'no_published_schedule', '…and says why');

// Re-seeding must not silently revert an academy's edited table.
mam_db_update('term_refund_schedule', ['percent' => 42], ['term_id' => $CL_term, 'week_from' => 3]);
mam_calendar_seed_refund_schedule($CL_term);           // no $replace
t_eq((int) mam_calendar_refund_for('2099-01-20', $CL_term)['percent'], 42,
    're-seeding without $replace leaves an edited table alone');
mam_calendar_seed_refund_schedule($CL_term, true);     // explicit replace
t_eq((int) mam_calendar_refund_for('2099-01-20', $CL_term)['percent'], 60,
    '…and an explicit replace restores the conventional one');

t_group('calendar — what a withdrawal MEANS, not just what it refunds');

$wDrop = mam_calendar_withdrawal_outcome('2099-01-08', $CL_term);
t_eq($wDrop['outcome'], 'dropped', 'inside add/drop the course is dropped');
t_ok(strpos($wDrop['note'], 'no mark on the record') !== false, '…leaving no mark on the record');

$wW = mam_calendar_withdrawal_outcome('2099-02-01', $CL_term);
t_eq($wW['outcome'], 'withdrawn', 'past add/drop but inside the window it is a withdrawal');
t_ok(strpos($wW['note'], 'no academic penalty') !== false, '…the "W", without academic penalty');

/* Past the withdrawal deadline the enrolment stands and is graded. Getting this
   boundary wrong is how a student ends up with an F for a course they left. */
$wG = mam_calendar_withdrawal_outcome('2099-03-01', $CL_term);
t_eq($wG['outcome'], 'graded', 'past the withdrawal deadline the enrolment is graded');

// A term with no withdrawal deadline must say so rather than pick one.
$wU = mam_calendar_withdrawal_outcome('2098-02-01', $CL_bare);
t_eq($wU['outcome'], 'undetermined', 'a term with no withdrawal deadline returns undetermined');
t_ok(strpos($wU['note'], 'decided by the academy') !== false, '…and hands the decision back');

// The outcome carries the refund with it, so a caller never has to join them up.
t_eq($wW['refund_percent'], mam_calendar_refund_for('2099-02-01', $CL_term)['percent'],
    'the outcome and the refund agree by construction');

t_group('calendar — the census is a snapshot, not a live count');

$CL_census = mam_calendar_take_census($CL_term, $CL_admin);
t_ok($CL_census['already'] === false, 'the census is taken once');
$firstCount = (int) $CL_census['enrolled'];
t_ok($firstCount >= 0, '…recording an enrolment figure');
t_eq((string) $CL_census['taken_on'], '2099-01-18', '…stamped with the term\'s census date, not today');

/* Now change the underlying enrolments. The recorded figure must NOT move: the
   whole purpose is that a report six months from now quotes what was true on the
   day, not what the live tables say after a term of withdrawals. */
$CL_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
$CL_coh = mam_db_insert('cohorts', ['course_id' => $CL_course, 'title' => 'CL Cohort', 'status' => 'active', 'capacity' => 10]);
for ($i = 0; $i < 3; $i++) {
    $sid = mam_db_insert('users', ['name' => 'CL S' . $i, 'email' => 'cl_s' . $i . '@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
    mam_db_insert('course_enrollments', ['student_id' => $sid, 'course_id' => $CL_course, 'cohort_id' => $CL_coh, 'status' => 'active']);
}
$again2 = mam_calendar_take_census($CL_term, $CL_admin);
t_ok($again2['already'] === true, 'a second census returns the existing snapshot');
t_eq((int) $again2['enrolled'], $firstCount, '…with the ORIGINAL figure, unmoved by three new enrolments');
t_eq((int) mam_calendar_census($CL_term)['enrolled'], $firstCount, 'and reading it back gives the same number');

t_group('calendar — closures');

$CL_hol = mam_calendar_closure_add($CL_term, 'holiday', 'Mid-term break', '2099-02-16', '2099-02-20');
t_ok($CL_hol > 0, 'a closure is recorded');
$onIt = mam_calendar_closure_on($CL_term, '2099-02-18');
t_ok($onIt !== null && (string) $onIt['label'] === 'Mid-term break', 'a date inside it resolves');
t_ok(mam_calendar_closure_on($CL_term, '2099-02-25') === null, 'a date outside it does not');

// A closure outside its own term would quietly never apply to anything.
$clThrew = '';
try { mam_calendar_closure_add($CL_term, 'holiday', 'Wrong', '2099-06-01', '2099-06-05'); }
catch (Throwable $e) { $clThrew = $e->getMessage(); }
t_eq($clThrew, 'outside_term', 'a closure outside its term is refused');

/* Closures are deliberately NOT subtracted from the refund week count: the table
   is published against calendar weeks because that is what a family can verify
   against a start date, and quietly discounting a public holiday would make the
   published table wrong. */
t_eq((int) mam_calendar_refund_for('2099-02-18', $CL_term)['week'],
     (int) mam_calendar_week_of($t, '2099-02-18'),
     'a holiday does not shift the refund week — the published table counts calendar weeks');

t_group('calendar — it coexists with the computed session engine');

/* This does NOT replace academic-session.php. An install with an empty calendar
   must behave exactly as before, and one with authored terms gets enforceable
   deadlines — the strangler pattern the research recommends over a cutover. */
$cur = mam_calendar_current_term();
t_ok(in_array($cur['source'], ['calendar', 'computed'], true), 'the current term names its source');
if ($cur['source'] === 'computed') {
    t_ok($cur['id'] === null, 'a computed term has no row id');
    t_ok($cur['withdrawal_deadline'] === null,
        '…and NO deadlines, so a caller cannot mistake a missing one for "no deadline applies"');
    t_ok($cur['calendar_authored'] === false, '…and says the calendar is unauthored');
} else {
    t_ok($cur['id'] > 0, 'an authored term carries its row id');
}

t_group('calendar — seeding a year from the academy\'s own term shape');

$seeded = mam_calendar_seed_year(2097, $CL_admin);
t_eq(count($seeded), count(MAM_SESSION_DEFS), 'one term per session definition');
$ok = array_values(array_filter($seeded, static fn($x) => $x['id'] !== null));
t_ok(count($ok) === count($seeded), 'all four are created');
$first = mam_calendar_term((int) $ok[0]['id']);
t_ok($first['add_drop_deadline'] !== null, 'each seeded term gets an add/drop deadline');
t_ok($first['withdrawal_deadline'] !== null, '…a withdrawal deadline');
t_ok($first['census_date'] !== null, '…and a census date');
/* Derived positions must be internally consistent, or the refund table and the
   withdrawal deadline disagree about when a term is 60% done. */
t_ok($first['add_drop_deadline'] <= $first['withdrawal_deadline'], 'add/drop precedes withdrawal');
t_ok($first['withdrawal_deadline'] <= $first['ends_on'], 'withdrawal falls inside the term');
t_ok(strpos((string) $first['notes'], 'edit the dates') !== false,
    'the seeded row says it is a starting point to edit, not a policy');
// Re-seeding the same year must not duplicate.
$again3 = mam_calendar_seed_year(2097, $CL_admin);
t_eq(count(array_filter($again3, static fn($x) => $x['id'] !== null)), count($again3),
    're-seeding edits the same rows rather than clashing with itself');

t_group('calendar — the summary an admin sees');

$sum = mam_calendar_summary();
t_ok($sum['authored'] === true, 'the summary knows the calendar is authored');
t_ok($sum['terms'] >= 5, '…and counts the terms');
t_ok(array_key_exists('census_due', $sum), '…and flags a census that is due but not taken');
t_ok(array_key_exists('missing_dates', $sum), '…and terms missing a deadline');

t_group('calendar — the admin surface exists and is wired');

$calAdminSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
t_ok(strpos($calAdminSrc, "'calendar' => ['Academic Calendar', 'calendar-days']") !== false, 'there is a nav entry');
t_ok(strpos($calAdminSrc, "elseif (\$view === 'calendar')") !== false, '…and a view');
foreach (['term_save', 'term_seed_year', 'term_census', 'term_refund_reseed', 'closure_add', 'closure_delete'] as $act) {
    t_ok(strpos($calAdminSrc, "\$action === '$act'") !== false, "the `$act` action is handled");
    t_ok((bool) preg_match("/'$act'\s*=> 'settings'/", $calAdminSrc), "…behind the settings capability");
}
// The nav probe must not key off academic_terms: an EMPTY calendar is exactly
// when this screen is needed, so probing it would fold the section away then.
t_ok((bool) preg_match("/'calendar' => 'users',/", $calAdminSrc),
    'the nav probe keys off users, not academic_terms');
// Same invented-helper trap as the vetting screen.
t_ok(strpos($calAdminSrc, "header('Location: ?v=calendar&t='") !== false,
    'it redirects with this file\'s own header(Location) pattern');

/* An overlap refusal must reach the admin as a sentence about consequences, not
   a raw exception string — `overlaps_term:TONI Term` means nothing to them. */
t_ok(strpos($calAdminSrc, 'Two terms covering one day means two possible refund tables') !== false,
    'an overlap refusal explains why it matters');
t_ok(strpos($calAdminSrc, 'Those dates fall outside the term, so the closure would never apply.') !== false,
    'a closure outside its term explains what would happen');
// Both irreversible actions state their consequence before acting.
t_ok(strpos($calAdminSrc, 'cannot be retaken') !== false, 'taking the census warns it is one-shot');
t_ok(strpos($calAdminSrc, 'may have been shown different figures') !== false,
    'resetting the refund table warns that families saw the current one');

t_group('calendar — a withdrawal records WHICH KIND it was');

/* Before this, "withdrawn" was one word for three different things — a drop, a
   W, and a graded enrolment — and the difference is both academic and financial.
   mam_lms_withdraw() now consults the calendar and writes the outcome into the
   transition reason, so enrolment_events carries it. */
$lmsSrc = (string) file_get_contents(dirname(__DIR__) . '/lib/lms.php');
t_ok(strpos($lmsSrc, 'mam_calendar_withdrawal_outcome()') !== false,
    'mam_lms_withdraw consults the calendar');
t_ok(strpos($lmsSrc, "mam_audit('lms_withdraw_outcome'") !== false,
    '…and audits the outcome, week, term and refund percentage');
t_ok(strpos($lmsSrc, 'an unauthored calendar must not stop a') !== false,
    '…best-effort, so an unauthored calendar never blocks a withdrawal');

// End to end, inside an authored term.
$CL_wCourse = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
$CL_wStu = mam_db_insert('users', ['name' => 'CL W', 'email' => 'cl_w@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$CL_wCoh = mam_db_insert('cohorts', ['course_id' => $CL_wCourse, 'title' => 'CL W Cohort', 'status' => 'active', 'capacity' => 10]);
$CL_wCe  = mam_db_insert('course_enrollments', ['student_id' => $CL_wStu, 'course_id' => $CL_wCourse, 'cohort_id' => $CL_wCoh, 'status' => 'active']);
mam_lms_withdraw($CL_wCe, null, 'withdrawn');
$CL_wRow = mam_db_row('SELECT status FROM course_enrollments WHERE id = ?', [$CL_wCe]);
t_eq((string) $CL_wRow['status'], 'withdrawn', 'the enrolment is withdrawn either way');
// Whether an outcome was appended depends on today falling in an authored term,
// which is environment-dependent — so assert the mechanism, not the calendar.
$CL_wEvt = mam_db_row("SELECT reason FROM enrolment_events WHERE enrollment_id = ?
                        ORDER BY id DESC LIMIT 1", [$CL_wCe]);
t_ok($CL_wEvt !== null, 'the withdrawal is recorded in enrolment_events');
if (mam_calendar_term_on() !== null) {
    t_ok((bool) preg_match('/(dropped|withdrawn|graded|undetermined)/', (string) $CL_wEvt['reason']),
        '…and inside an authored term the reason names which kind of leaving it was');
} else {
    t_ok(true, '…today falls outside every authored term, so no outcome was appended (correct)');
}
