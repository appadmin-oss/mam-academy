<?php
/**
 * Regression tests for the 2026-08-03 enrolment audit fixes.
 *
 *   E1 — capacity is enforced on the self-service enrol path: a full cohort
 *        auto-diverts the student to the waitlist instead of over-filling.
 *   E2 — the duplicate guard is level-aware in BOTH directions: a level-less
 *        add no longer slips past an existing levelled enrolment (and vice
 *        versa), so one course can't hold two concurrent enrolments.
 *   E3 — mam_lms_find_enrollment() resolves an existing enrolment
 *        deterministically and level-aware (the 'already_enrolled' recovery
 *        used by payment settlement / admin enrol previously picked any row).
 *   E4 — a zero/invalid actor id no longer silently voids the lifecycle audit
 *        row (actor_id = 0 violated the users FK and was swallowed).
 *   E5 — waitlist promote distinguishes its failure modes instead of returning
 *        null (== "empty waitlist") or reporting success without enrolling.
 *   E6 — cohort fill count and roster both count DISTINCT students.
 *   S2/S3 — summer status transitions are enforced; the application window is
 *        enforced when EITHER bound is configured.
 *   S1 — 'enrolled' provisions a no-login roster identity + a dedicated
 *        SUMMER-<year> cohort enrolment.
 */
declare(strict_types=1);
t_group('enrolment fixes (audit 2026-08-03)');

$pid = (int)mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];
$vid = (int)mam_db_row("SELECT id FROM courses WHERE code = 'voice'")['id'];
$precert = (int)mam_db_row("SELECT id FROM levels WHERE code = 'precert'")['id'];
$cert    = (int)mam_db_row("SELECT id FROM levels WHERE code = 'cert'")['id'];

$n = 0;
$mk = function (string $tag) use (&$n): int {
    $n++;
    return mam_db_insert('users', [
        'email' => "t_enrfix_{$tag}_{$n}@x.dev", 'password_hash' => 'x',
        'role' => 'student', 'name' => "EnrFix $tag$n", 'status' => 'active',
    ]);
};

/* ── E1: capacity auto-diverts to the waitlist ───────────────────────────── */
$s1 = $mk('cap');
$e1 = mam_lms_add_course($s1, 'piano', 'precert', 'standard');
t_eq($e1['status'], 'pending', 'E1: first enrolment into an empty cohort is accepted');
$coh = (int)mam_db_row('SELECT cohort_id FROM course_enrollments WHERE id = ?', [$e1['id']])['cohort_id'];
t_ok($coh > 0, 'E1: the enrolment is linked to a cohort');
mam_db_update('cohorts', ['capacity' => 1], ['id' => $coh]);

$s2 = $mk('cap');
$e2 = mam_lms_add_course($s2, 'piano', 'precert', 'standard');
t_eq($e2['status'], 'waitlisted', 'E1: a full cohort diverts the next student to the waitlist');
t_eq($e2['position'], 1, 'E1: the diverted student gets waitlist position 1');
t_eq($e2['cohort_id'], $coh, 'E1: the waitlist entry names the full cohort');
t_eq($e2['id'], null, 'E1: no course_enrollment row is created when waitlisted');
t_eq(mam_lms_cohort_fill_count($coh)['enrolled'], 1, 'E1: the cohort was NOT over-filled');
t_eq(mam_lms_waitlist_status($coh, $s2)['status'], 'waiting', 'E1: the student is really on the waitlist');

// A second diverted student queues behind the first.
$s3 = $mk('cap');
t_eq(mam_lms_add_course($s3, 'piano', 'precert', 'standard')['position'], 2, 'E1: the next diverted student queues at position 2');

// Freeing a seat lets the ordinary path work again.
mam_db_update('cohorts', ['capacity' => 12], ['id' => $coh]);
$s4 = $mk('cap');
t_eq(mam_lms_add_course($s4, 'piano', 'precert', 'standard')['status'], 'pending', 'E1: raising capacity restores normal enrolment');

/* ── E2: level-aware duplicate guard, both directions ────────────────────── */
$s5 = $mk('dup');
mam_lms_add_course($s5, 'voice', 'precert', 'standard');
$blocked = false;
try { mam_lms_add_course($s5, 'voice'); } catch (RuntimeException $e) { $blocked = ($e->getMessage() === 'already_enrolled'); }
t_ok($blocked, 'E2: a level-less add is blocked by an existing levelled enrolment');

$s6 = $mk('dup');
mam_lms_add_course($s6, 'voice');
$blocked2 = false;
try { mam_lms_add_course($s6, 'voice', 'precert', 'standard'); } catch (RuntimeException $e) { $blocked2 = ($e->getMessage() === 'already_enrolled'); }
t_ok($blocked2, 'E2: a levelled add is blocked by an existing level-less enrolment');

// Genuinely different courses are still allowed.
$s7 = $mk('dup');
mam_lms_add_course($s7, 'voice', 'precert', 'standard');
$other = mam_lms_add_course($s7, 'piano', 'precert', 'standard');
t_ok(!empty($other['id']), 'E2: a different course is still allowed');

// A withdrawn enrolment must not block a re-enrolment.
$s8 = $mk('dup');
$w = mam_lms_add_course($s8, 'voice', 'precert', 'standard');
mam_lms_withdraw((int)$w['id'], null, 'changed mind');
$re = mam_lms_add_course($s8, 'voice', 'precert', 'standard');
t_ok(!empty($re['id']), 'E2: a withdrawn enrolment does not block re-enrolling');

/* ── E3: deterministic, level-aware enrolment lookup ─────────────────────── */
$s9 = $mk('find');
$ePre = (int)mam_lms_add_course($s9, 'guitar', 'precert', 'standard')['id'];
// Level-aware: asking for precert returns the precert row.
t_eq((int)mam_lms_find_enrollment($s9, 'guitar', 'precert')['id'], $ePre, 'E3: lookup by level returns that level\'s enrolment');
// Asking for a level the student has no enrolment at returns null, NOT another level's row.
t_eq(mam_lms_find_enrollment($s9, 'guitar', 'cert'), null, 'E3: lookup for an un-enrolled level returns null, not another level');
// Level-less lookup falls back to the student's live enrolment in that course.
t_eq((int)mam_lms_find_enrollment($s9, 'guitar')['id'], $ePre, 'E3: a level-less lookup finds the live enrolment');
t_eq(mam_lms_find_enrollment($s9, 'no-such-course'), null, 'E3: an unknown course returns null');

/* ── E4: the lifecycle audit row survives a zero actor id ────────────────── */
$s10 = $mk('audit');
$e10 = (int)mam_lms_add_course($s10, 'voice', 'cert', 'standard')['id'];
$before = (int)mam_db_row('SELECT COUNT(*) AS n FROM enrolment_events WHERE enrollment_id = ?', [$e10])['n'];
mam_lms_approve($e10, null, 0);          // what payment provisioning passes
$after = (int)mam_db_row('SELECT COUNT(*) AS n FROM enrolment_events WHERE enrollment_id = ?', [$e10])['n'];
t_eq($after, $before + 1, 'E4: approve with actor id 0 still records the pending→active event');
$ev = mam_db_row('SELECT actor_id, to_status FROM enrolment_events WHERE enrollment_id = ? ORDER BY id DESC LIMIT 1', [$e10]);
t_eq($ev['actor_id'], null, 'E4: a zero actor id is normalised to NULL (FK-safe)');
t_eq($ev['to_status'], 'active', 'E4: the recorded event is the activation');

/* ── E5: waitlist promote reports its failure modes ──────────────────────── */
// (a) cohort with no course mapping — must NOT look like an empty waitlist.
$cohNo = mam_lms_create_cohort(['title' => 'EnrFix no-course', 'capacity' => 10]);
$s11 = $mk('wl');
mam_lms_waitlist_add($cohNo, $s11);
$thrown = '';
try { mam_lms_waitlist_promote($cohNo, null, true); } catch (RuntimeException $e) { $thrown = $e->getMessage(); }
t_eq($thrown, 'no_course_mapping', 'E5: promoting from an unmapped cohort throws no_course_mapping');
t_eq(mam_lms_waitlist_status($cohNo, $s11)['status'], 'waiting', 'E5: the student stays on the waitlist');

// (b) an empty waitlist still returns null.
$cohEmpty = mam_lms_create_cohort(['title' => 'EnrFix empty', 'capacity' => 10, 'course_id' => $pid]);
t_eq(mam_lms_waitlist_promote($cohEmpty, null, true), null, 'E5: an empty waitlist returns null');

// (c) the student's live enrolment belongs to ANOTHER cohort — must not be
//     silently consumed off the waitlist without enrolling them here.
$cohA = mam_lms_create_cohort(['title' => 'EnrFix A', 'capacity' => 10, 'course_id' => $pid]);
$cohB = mam_lms_create_cohort(['title' => 'EnrFix B', 'capacity' => 10, 'course_id' => $pid]);
$s12 = $mk('wl');
mam_db_insert('course_enrollments', ['student_id' => $s12, 'course_id' => $pid, 'cohort_id' => $cohA, 'status' => 'active']);
mam_lms_waitlist_add($cohB, $s12);
$thrown2 = '';
try { mam_lms_waitlist_promote($cohB, null, true); } catch (RuntimeException $e) { $thrown2 = $e->getMessage(); }
t_eq($thrown2, 'enrolled_elsewhere', 'E5: a live enrolment in another cohort throws enrolled_elsewhere');
t_eq(mam_lms_waitlist_status($cohB, $s12)['status'], 'waiting', 'E5: that student is not consumed off the waitlist');
t_eq(mam_db_row('SELECT id FROM course_enrollments WHERE student_id = ? AND cohort_id = ?', [$s12, $cohB]), null, 'E5: and was not enrolled in the target cohort');

// (d) the happy path still enrols and renumbers.
$cohC = mam_lms_create_cohort(['title' => 'EnrFix C', 'capacity' => 10, 'course_id' => $pid]);
$s13 = $mk('wl'); $s14 = $mk('wl');
mam_lms_waitlist_add($cohC, $s13);
mam_lms_waitlist_add($cohC, $s14);
t_eq(mam_lms_waitlist_promote($cohC, null, true), $s13, 'E5: promote returns the promoted student id');
t_ok(mam_db_row('SELECT id FROM course_enrollments WHERE student_id = ? AND cohort_id = ? AND status = ?', [$s13, $cohC, 'active']) !== null, 'E5: the promoted student is enrolled in the cohort');
t_eq((int)mam_lms_waitlist_status($cohC, $s14)['position'], 1, 'E5: the remaining student moves to position 1');

/* ── E6: fill count and roster both count DISTINCT students ──────────────── */
$cohD = mam_lms_create_cohort(['title' => 'EnrFix D', 'capacity' => 10, 'course_id' => $pid]);
$s15 = $mk('cnt');
mam_db_insert('course_enrollments', ['student_id' => $s15, 'course_id' => $pid, 'cohort_id' => $cohD, 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $s15, 'course_id' => $pid, 'cohort_id' => $cohD, 'status' => 'active']);
t_eq(mam_lms_cohort_fill_count($cohD)['enrolled'], 1, 'E6: two rows for one student count as one seat');
t_eq(count(mam_lms_cohort_roster($cohD)), 1, 'E6: the roster agrees');

/* ── S3: the summer window is enforced when EITHER bound is set ──────────── */
t_ok(function_exists('mam_summer_window_open'), 'S3: a summer window predicate exists');
if (function_exists('mam_summer_window_open')) {
    t_eq(mam_summer_window_open('2030-06-15', '', ''), true, 'S3: no bounds configured → open');
    t_eq(mam_summer_window_open('2030-06-15', '2030-06-01', '2030-06-30'), true, 'S3: inside both bounds → open');
    t_eq(mam_summer_window_open('2030-07-15', '2030-06-01', '2030-06-30'), false, 'S3: after the close bound → closed');
    t_eq(mam_summer_window_open('2030-05-15', '2030-06-01', '2030-06-30'), false, 'S3: before the open bound → closed');
    // The bug: a single bound used to disable the guard entirely.
    t_eq(mam_summer_window_open('2030-07-15', '', '2030-06-30'), false, 'S3: close bound ALONE still closes applications');
    t_eq(mam_summer_window_open('2030-05-15', '2030-06-01', ''), false, 'S3: open bound ALONE still gates applications');
    t_eq(mam_summer_window_open('2030-07-15', '2030-06-01', ''), true, 'S3: after an open-only bound → open');
}

/* ── S2: summer status transitions are enforced ──────────────────────────── */
t_ok(function_exists('mam_summer_can_transition'), 'S2: a summer transition predicate exists');
if (function_exists('mam_summer_can_transition')) {
    t_eq(mam_summer_can_transition('submitted', 'confirmed'), true,  'S2: submitted → confirmed is legal');
    t_eq(mam_summer_can_transition('submitted', 'waitlisted'), true, 'S2: submitted → waitlisted is legal');
    t_eq(mam_summer_can_transition('waitlisted', 'confirmed'), true, 'S2: waitlisted → confirmed is legal');
    t_eq(mam_summer_can_transition('confirmed', 'enrolled'), true,   'S2: confirmed → enrolled is legal');
    t_eq(mam_summer_can_transition('rejected', 'enrolled'), false,   'S2: rejected → enrolled is refused');
    t_eq(mam_summer_can_transition('enrolled', 'submitted'), false,  'S2: enrolled cannot walk back to submitted');
    t_eq(mam_summer_can_transition('cancelled', 'confirmed'), false, 'S2: cancelled is terminal');
    t_eq(mam_summer_can_transition('enrolled', 'cancelled'), true,   'S2: an enrolled place can still be cancelled');
}

/* ── S1: 'enrolled' provisions a FULL student account + guardian + cohort ──
 *
 * This used to assert the opposite: a `roster` identity that could never log in.
 * The academy reversed that — a summer student is a student, so they get the
 * portal, the gradebook and their own attendance record like anybody else, and
 * the guardian gets an account in the same breath. The guardian link is what
 * makes a child's login safe rather than merely convenient, so it is asserted
 * here and not left to an admin to remember.
 */
t_ok(function_exists('mam_summer_enrol'), 'S1: a summer enrolment provisioner exists');
if (function_exists('mam_summer_enrol')) {
    $ref = 'SUM-ENRFIX-1';
    $sumId = mam_db_insert('summer_applications', [
        'reference' => $ref, 'first_name' => 'Sum', 'last_name' => 'Kid',
        'email' => 't_enrfix_summer@x.dev', 'dob' => '2014-05-05', 'age_at_submission' => 11,
        'guardian_name' => 'G', 'guardian_phone' => '+2340000000',
        'guardian_email' => 't_enrfix_guardian@x.dev', 'discipline' => 'piano',
        'policy_agreed' => 1, 'policy_signed_name' => 'G', 'session_year' => 2030,
        'status' => 'confirmed',
    ]);
    $res = mam_summer_enrol($sumId, null);
    t_ok(!empty($res['user_id']), 'S1: a student account is provisioned');
    t_ok(!empty($res['enrollment_id']), 'S1: a course enrolment is created');
    t_ok(!empty($res['cohort_id']), 'S1: a summer cohort is resolved');

    $u = mam_db_row('SELECT email, role, status, must_change_password FROM users WHERE id = ?', [(int)$res['user_id']]);
    t_eq($u['status'], 'active', 'S1: the account is active — a summer student is a student');
    t_eq($u['role'], 'student', 'S1: with the student role');
    t_eq((int)$u['must_change_password'], 1, 'S1: on a temporary password they must change');
    // The password is RETURNED, not emailed from the provisioner: an account
    // nobody has the password to is not access, and a mailer inside this
    // function would make it untestable and let a failed send look like a
    // failed enrolment.
    t_ok(!empty($res['password']) && strlen((string)$res['password']) >= 10,
        'S1: and the generated password is handed back for the caller to send');
    t_ok(mam_login('t_enrfix_summer@x.dev', (string)$res['password']) !== null,
        'S1: the child can actually sign in with it');

    // The guardian, from guardian_email, with a parent_links row.
    t_ok(!empty($res['guardian_id']), 'S1: the guardian named on the application gets an account');
    $g = mam_db_row('SELECT role, status FROM users WHERE id = ?', [(int)$res['guardian_id']]);
    t_eq($g['role'], 'parent', 'S1: with the parent role, so the guardian portal opens');
    t_ok(mam_db_row('SELECT 1 FROM parent_links WHERE parent_id = ? AND student_id = ?',
        [(int)$res['guardian_id'], (int)$res['user_id']]) !== null,
        'S1: and is linked to the child — the control that makes a child login safe');

    $co = mam_db_row('SELECT title, course_id, program_id FROM cohorts WHERE id = ?', [(int)$res['cohort_id']]);
    t_ok(strpos((string)$co['title'], 'SUMMER-2030') === 0, 'S1: the cohort is the dedicated SUMMER-<year> run');
    t_eq((int)$co['course_id'], $pid, 'S1: the cohort maps to the applied discipline');
    // Summer is a CRASH-programme intake, not a parallel system. Without a
    // programme the cohort drops out of the catalogue and every grouped report.
    $crash = mam_db_row("SELECT id FROM programs WHERE code = 'crash'");
    if ($crash) {
        t_eq((int)$co['program_id'], (int)$crash['id'],
            'S1: and belongs to the crash programme');
        t_eq(mam_summer_programme_id(), (int)$crash['id'], 'S1: which is what the resolver reports');
    }

    $ce = mam_db_row('SELECT status, cohort_id FROM course_enrollments WHERE id = ?', [(int)$res['enrollment_id']]);
    t_eq($ce['status'], 'active', 'S1: the summer enrolment is active');
    t_eq((int)$ce['cohort_id'], (int)$res['cohort_id'], 'S1: it is linked to the summer cohort');
    t_ok(count(mam_lms_cohort_roster((int)$res['cohort_id'])) === 1, 'S1: the student appears on the cohort roster');

    // Idempotent: re-running must not duplicate anything.
    $again = mam_summer_enrol($sumId, null);
    t_eq((int)$again['user_id'], (int)$res['user_id'], 'S1: re-running reuses the same identity');
    t_eq((int)$again['enrollment_id'], (int)$res['enrollment_id'], 'S1: re-running does not duplicate the enrolment');
    t_eq((int)$again['cohort_id'], (int)$res['cohort_id'], 'S1: re-running reuses the same summer cohort');

    // An application with no discipline can't be enrolled into a course.
    $noDisc = mam_db_insert('summer_applications', [
        'reference' => 'SUM-ENRFIX-2', 'first_name' => 'No', 'last_name' => 'Disc',
        'email' => 't_enrfix_summer2@x.dev', 'dob' => '2014-05-05', 'age_at_submission' => 11,
        'guardian_name' => 'G', 'guardian_phone' => '+2340000000', 'discipline' => null,
        'policy_agreed' => 1, 'policy_signed_name' => 'G', 'session_year' => 2030,
        'status' => 'confirmed',
    ]);
    $r2 = mam_summer_enrol($noDisc, null);
    t_ok(!empty($r2['user_id']), 'S1: an account is still provisioned without a discipline');
    // The guardian link is best-effort: an application with no guardian address
    // must still enrol the child. An admin can link one later from ?v=guardians.
    t_eq($r2['guardian_id'], null, 'S1: no guardian email means no guardian, not a failure');
    t_eq($r2['enrollment_id'], null, 'S1: but no course enrolment is invented');
    t_eq($r2['reason'], 'no_discipline', 'S1: and the reason is reported');
}
