<?php
/**
 * Follow-up gaps found auditing the 2026-08-03 enrolment fixes themselves.
 *
 *   GA — ONE source of truth for "is summer accepting applications". The server
 *        guard honoured each window bound independently while /summer, /apply and
 *        config-public still demanded BOTH, so the form could be hidden while the
 *        endpoint accepted (and vice versa). An unconfigured programme also
 *        accepted applications outright.
 *   GB — a promoted student is actually told: class_waitlist.notified_at existed
 *        but nothing ever wrote it, and no notification was raised.
 *   GC — freeing a seat drains the queue. Capacity is now enforced on enrol, so
 *        without this a queue could only ever be drained by an admin clicking
 *        promote — a withdrawal left the seat empty and the queue stuck.
 *   GD — a summer cohort gets the remembered instructor, so it is visible to
 *        somebody. mam_lms_instructor_cohorts() keys off cohort.instructor_id OR
 *        enrolment.instructor_id; the summer enrolment set neither, so summer
 *        students sat on a roster no instructor could open.
 *   GE — summer cohort capacity comes from the REGISTERED `summer_capacity`
 *        setting (0 = unlimited), not an invented unregistered key.
 */
declare(strict_types=1);
t_group('enrolment gaps (audit follow-up 2026-08-03)');

$gPid = (int)mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];
$gN = 0;
$gMk = function (string $tag) use (&$gN): int {
    $gN++;
    return mam_db_insert('users', [
        'email' => "t_gap_{$tag}_{$gN}@x.dev", 'password_hash' => 'x',
        'role' => 'student', 'name' => "Gap $tag$gN", 'status' => 'active',
    ]);
};

/* ── GA: one source of truth for the summer window ───────────────────────── */
t_ok(function_exists('mam_summer_is_configured'), 'GA: a "programme configured" predicate exists');
t_ok(function_exists('mam_summer_accepting'), 'GA: an "accepting applications" predicate exists');
if (function_exists('mam_summer_accepting')) {
    $keepOpen  = (string)mam_setting('summer_app_open', '');
    $keepClose = (string)mam_setting('summer_app_close', '');

    // Nothing configured → the programme is not running, so nothing is accepted.
    mam_setting_set('summer_app_open', '');
    mam_setting_set('summer_app_close', '');
    t_eq(mam_summer_is_configured(), false, 'GA: no window configured → not configured');
    t_eq(mam_summer_accepting('2030-06-15'), false, 'GA: an unconfigured programme accepts nothing');

    // A close bound alone is enough to be "running", and it gates correctly.
    mam_setting_set('summer_app_close', '2030-06-30');
    t_eq(mam_summer_is_configured(), true, 'GA: a close bound alone counts as configured');
    t_eq(mam_summer_accepting('2030-06-15'), true, 'GA: before the close bound → accepting');
    t_eq(mam_summer_accepting('2030-07-15'), false, 'GA: after the close bound → closed');

    // An open bound alone likewise.
    mam_setting_set('summer_app_close', '');
    mam_setting_set('summer_app_open', '2030-06-01');
    t_eq(mam_summer_accepting('2030-05-15'), false, 'GA: before the open bound → closed');
    t_eq(mam_summer_accepting('2030-06-15'), true, 'GA: after the open bound → accepting');

    mam_setting_set('summer_app_open', $keepOpen);
    mam_setting_set('summer_app_close', $keepClose);
}

/* ── GB: the promoted student is notified ────────────────────────────────── */
$gCohN = mam_lms_create_cohort(['title' => 'Gap notify', 'capacity' => 10, 'course_id' => $gPid]);
$gS1 = $gMk('notify');
mam_lms_waitlist_add($gCohN, $gS1);
$nBefore = (int)mam_db_row('SELECT COUNT(*) AS n FROM notifications WHERE user_id = ?', [$gS1])['n'];
mam_lms_waitlist_promote($gCohN, null, true);
$wlRow = mam_db_row('SELECT status, notified_at FROM class_waitlist WHERE cohort_id = ? AND student_id = ?', [$gCohN, $gS1]);
t_eq($wlRow['status'], 'promoted', 'GB: the entry is marked promoted');
t_ok(!empty($wlRow['notified_at']), 'GB: notified_at is stamped (the column is no longer dead)');
$nAfter = (int)mam_db_row('SELECT COUNT(*) AS n FROM notifications WHERE user_id = ?', [$gS1])['n'];
t_eq($nAfter, $nBefore + 1, 'GB: the student gets an in-app notification');
$note = mam_db_row('SELECT type FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$gS1]);
t_eq($note['type'], 'waitlist.promoted', 'GB: the notification is typed waitlist.promoted');

/* ── GC: freeing a seat drains the queue ─────────────────────────────────── */
t_ok(array_key_exists('waitlist_autopromote', mam_automations_registry()), 'GC: the auto-promote rule is registered');
$gCohD = mam_lms_create_cohort(['title' => 'Gap drain', 'capacity' => 1, 'course_id' => $gPid]);
$gHolder = $gMk('drain');
$gEnr = mam_db_insert('course_enrollments', ['student_id' => $gHolder, 'course_id' => $gPid, 'cohort_id' => $gCohD, 'status' => 'active']);
$gWaiter = $gMk('drain');
mam_lms_waitlist_add($gCohD, $gWaiter);
t_eq(mam_lms_cohort_fill_count($gCohD)['available'], 0, 'GC: the cohort starts full');

mam_automation_set('waitlist_autopromote', true);
mam_lms_withdraw($gEnr, null, 'gap test');
t_ok(mam_db_row('SELECT id FROM course_enrollments WHERE student_id = ? AND cohort_id = ? AND status = ?', [$gWaiter, $gCohD, 'active']) !== null,
    'GC: withdrawing the holder auto-promotes the waiting student into the seat');
t_eq(mam_lms_waitlist_status($gCohD, $gWaiter)['status'], 'promoted', 'GC: the queue entry is cleared');
t_eq(mam_lms_cohort_fill_count($gCohD)['enrolled'], 1, 'GC: the cohort is still exactly at capacity, not over');

// With the automation off the seat stays empty (admin drains manually).
$gCohO = mam_lms_create_cohort(['title' => 'Gap drain off', 'capacity' => 1, 'course_id' => $gPid]);
$gHolder2 = $gMk('off');
$gEnr2 = mam_db_insert('course_enrollments', ['student_id' => $gHolder2, 'course_id' => $gPid, 'cohort_id' => $gCohO, 'status' => 'active']);
$gWaiter2 = $gMk('off');
mam_lms_waitlist_add($gCohO, $gWaiter2);
mam_automation_set('waitlist_autopromote', false);
mam_lms_withdraw($gEnr2, null, 'gap test off');
t_eq(mam_db_row('SELECT id FROM course_enrollments WHERE student_id = ? AND cohort_id = ?', [$gWaiter2, $gCohO]), null,
    'GC: with the rule off, no auto-promotion happens');
t_eq(mam_lms_waitlist_status($gCohO, $gWaiter2)['status'], 'waiting', 'GC: and the student stays queued');
mam_automation_set('waitlist_autopromote', true);

// A withdrawal must still succeed when the queue is empty or unpromotable.
$gCohE = mam_lms_create_cohort(['title' => 'Gap drain empty', 'capacity' => 1, 'course_id' => $gPid]);
$gH3 = $gMk('empty');
$gE3 = mam_db_insert('course_enrollments', ['student_id' => $gH3, 'course_id' => $gPid, 'cohort_id' => $gCohE, 'status' => 'active']);
$t3 = mam_lms_withdraw($gE3, null, 'no queue');
t_eq($t3['to'], 'withdrawn', 'GC: a withdrawal with an empty queue still succeeds');

// Completing a course also frees the seat.
$gCohC = mam_lms_create_cohort(['title' => 'Gap drain complete', 'capacity' => 1, 'course_id' => $gPid]);
$gH4 = $gMk('done');
$gE4 = mam_db_insert('course_enrollments', ['student_id' => $gH4, 'course_id' => $gPid, 'cohort_id' => $gCohC, 'status' => 'active']);
$gW4 = $gMk('done');
mam_lms_waitlist_add($gCohC, $gW4);
mam_lms_transition($gE4, 'completed', null, 'gap test complete');
t_ok(mam_db_row('SELECT id FROM course_enrollments WHERE student_id = ? AND cohort_id = ? AND status = ?', [$gW4, $gCohC, 'active']) !== null,
    'GC: completing a course also drains the queue');

/* ── GD + GE: the summer cohort is visible and capacity is registered ────── */
if (function_exists('mam_summer_enrol')) {
    $gIns = mam_db_insert('users', ['email' => 't_gap_summer_ins@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Gap Summer Ins', 'status' => 'active']);
    mam_lms_remember_instructor($gIns, $gPid, null);   // the remembered instructor for piano

    $keepCap = (string)mam_setting('summer_capacity', '');
    mam_setting_set('summer_capacity', '25');

    $gSum = mam_db_insert('summer_applications', [
        'reference' => 'SUM-GAP-1', 'first_name' => 'Gap', 'last_name' => 'Summer',
        'email' => 't_gap_summer@x.dev', 'dob' => '2014-03-03', 'age_at_submission' => 11,
        'guardian_name' => 'G', 'guardian_phone' => '+2340000000', 'discipline' => 'piano',
        'policy_agreed' => 1, 'policy_signed_name' => 'G', 'session_year' => 2032,
        'status' => 'confirmed',
    ]);
    $gRes = mam_summer_enrol($gSum, null);
    $gCo  = mam_db_row('SELECT instructor_id, capacity FROM cohorts WHERE id = ?', [(int)$gRes['cohort_id']]);
    t_eq((int)$gCo['instructor_id'], $gIns, 'GD: the summer cohort inherits the remembered instructor');
    t_eq((int)$gCo['capacity'], 25, 'GE: capacity comes from the registered summer_capacity setting');
    $gCe = mam_db_row('SELECT instructor_id FROM course_enrollments WHERE id = ?', [(int)$gRes['enrollment_id']]);
    t_eq((int)$gCe['instructor_id'], $gIns, 'GD: the summer enrolment names the instructor too');
    $gVisible = array_filter(mam_lms_instructor_cohorts($gIns), fn($c) => (int)$c['id'] === (int)$gRes['cohort_id']);
    t_eq(count($gVisible), 1, 'GD: the instructor can actually see the summer cohort');

    /* GF: a summer student is a FIRST-CLASS student — same access as a
       year-round one. It must (a) be linked to a customers record so the portal
       profile populates and the account sits on the applicant identity spine,
       and (b) appear in the admin "assign to a class" list, which selects on
       role='student' AND status='active'. Both were the parity gap: a summer
       child provisioned only from summer_applications had a NULL customer_id. */
    $gStu = mam_db_row('SELECT id, role, status, customer_id FROM users WHERE id = ?', [(int)$gRes['user_id']]);
    t_eq($gStu['role'], 'student', 'GF: the summer account is a student');
    t_eq($gStu['status'], 'active', 'GF: and active, so it can sign in');
    t_ok((int)($gStu['customer_id'] ?? 0) > 0, 'GF: it is linked to a customers record (portal profile parity)');
    t_eq((int)$gRes['customer_id'], (int)$gStu['customer_id'], 'GF: the result reports the linked customer');
    $gCust = mam_db_row('SELECT email FROM customers WHERE id = ?', [(int)$gStu['customer_id']]);
    t_eq(strtolower((string)$gCust['email']), 't_gap_summer@x.dev', 'GF: the customer carries the applicant email');
    $gAssignable = mam_db_row("SELECT id FROM users WHERE id = ? AND role = 'student' AND status = 'active'", [(int)$gRes['user_id']]);
    t_ok($gAssignable !== null, 'GF: the summer student appears in the assign-to-class candidate list');
    // Idempotent: re-enrolling must not create a second customer or repoint the link.
    $gRes1b = mam_summer_enrol($gSum, null);
    t_eq((int)$gRes1b['customer_id'], (int)$gStu['customer_id'], 'GF: re-enrol reuses the same customer, never a duplicate');
    t_eq((int)mam_db_row('SELECT COUNT(*) n FROM customers WHERE LOWER(email) = ?', ['t_gap_summer@x.dev'])['n'], 1,
        'GF: exactly one customer row for the summer student');

    // 0 means unlimited — it must NOT read as a permanently full cohort.
    mam_setting_set('summer_capacity', '0');
    $gSum2 = mam_db_insert('summer_applications', [
        'reference' => 'SUM-GAP-2', 'first_name' => 'Gap', 'last_name' => 'Unlimited',
        'email' => 't_gap_summer2@x.dev', 'dob' => '2014-03-03', 'age_at_submission' => 11,
        'guardian_name' => 'G', 'guardian_phone' => '+2340000000', 'discipline' => 'voice',
        'policy_agreed' => 1, 'policy_signed_name' => 'G', 'session_year' => 2033,
        'status' => 'confirmed',
    ]);
    $gRes2 = mam_summer_enrol($gSum2, null);
    $gFill = mam_lms_cohort_fill_count((int)$gRes2['cohort_id']);
    t_ok($gFill['available'] > 0, 'GE: summer_capacity=0 means unlimited, not instantly full');
    t_eq($gFill['is_full'], false, 'GE: an unlimited summer cohort never reads as full');

    mam_setting_set('summer_capacity', $keepCap);
}
