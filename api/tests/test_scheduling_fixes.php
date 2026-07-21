<?php
/**
 * Regression tests for the 2026-07-14 audit fixes to enterprise scheduling:
 *   A4 — conflict detection now covers ROOM clashes and SUBSTITUTE instructors,
 *        and bulk-create runs detection per slot (returns a conflicts list).
 *   A5 — waitlist promote refuses to over-fill past capacity unless forced;
 *        waitlist add hands out sequential positions.
 *   A6 — booking-fee NGN amount is derived from LIVE fx, not frozen constants.
 *   A8 — bulk-create rejects a malformed date with InvalidArgumentException.
 */
declare(strict_types=1);
t_group('scheduling fixes (audit 2026-07-14)');

$pianoId = (int)mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];
$insA = mam_db_insert('users', ['email' => 't_schfix_a@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Ins A', 'status' => 'active']);
$insB = mam_db_insert('users', ['email' => 't_schfix_b@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Ins B', 'status' => 'active']);
$insC = mam_db_insert('users', ['email' => 't_schfix_c@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Ins C', 'status' => 'active']);

$cohA = mam_lms_create_cohort(['title' => 'Fix Cohort A', 'instructor_id' => $insA]);
$cohB = mam_lms_create_cohort(['title' => 'Fix Cohort B', 'instructor_id' => $insB]);

// A session in cohort A at 10:00–11:00 in room R1.
mam_lms_create_session($cohA, ['title' => 'A1', 'scheduled_at' => '2030-01-01 10:00:00', 'duration_min' => 60, 'room' => 'R1', 'created_by' => $insA]);

// ── A4: room clash across DIFFERENT instructors ──
$roomHit = mam_lms_detect_conflicts($insB, '2030-01-01 10:30:00', 60, null, 'R1');
t_eq(count($roomHit), 1, 'a different instructor booking the SAME room+time is flagged');
$roomMiss = mam_lms_detect_conflicts($insB, '2030-01-01 10:30:00', 60, null, 'R2');
t_eq(count($roomMiss), 0, 'a different room at the same time is NOT a conflict');
$noRoom = mam_lms_detect_conflicts($insB, '2030-01-01 10:30:00', 60);
t_eq(count($noRoom), 0, 'without a room, a different instructor has no clash');

// ── A4: same-instructor clash still detected (unchanged behaviour) ──
$insHit = mam_lms_detect_conflicts($insA, '2030-01-01 10:30:00', 60);
t_eq(count($insHit), 1, 'the owning instructor double-booked in time is flagged');
$apart = mam_lms_detect_conflicts($insA, '2030-01-01 12:00:00', 60);
t_eq(count($apart), 0, 'a non-overlapping slot is not a conflict');

// ── A4: substitute instructor clash ──
$subSid = mam_lms_create_session($cohB, ['title' => 'B1', 'scheduled_at' => '2030-01-02 09:00:00', 'duration_min' => 60, 'created_by' => $insB]);
mam_db_update('class_sessions', ['substitute_id' => $insC], ['id' => $subSid]);
$subHit = mam_lms_detect_conflicts($insC, '2030-01-02 09:30:00', 60);
t_eq(count($subHit), 1, 'an instructor already assigned as SUBSTITUTE is flagged for an overlapping slot');

// ── A4/A8: bulk-create returns {session_ids, conflicts}; malformed date throws ──
$bulk = mam_lms_bulk_create_sessions($cohA, [
    'start_date'  => '2030-02-04', 'end_date' => '2030-02-18', // Mondays
    'day_of_week' => [1], 'time_of_day' => '10:00', 'duration_min' => 60, 'room' => 'R9',
], $insA);
t_ok(isset($bulk['session_ids']) && isset($bulk['conflicts']), 'bulk-create returns session_ids + conflicts');
t_eq(count($bulk['session_ids']), 3, 'three Mondays generated');
t_eq(count($bulk['conflicts']), 0, 'a fresh room has no conflicts');

$threw = false;
try { mam_lms_bulk_create_sessions($cohA, ['start_date' => 'not-a-date', 'end_date' => '2030-02-18', 'day_of_week' => [1]], $insA); }
catch (InvalidArgumentException $e) { $threw = true; }
t_ok($threw, 'a malformed start_date raises InvalidArgumentException (not an uncaught 500)');

// ── A5: waitlist positions are sequential; promote respects capacity ──
$full = mam_lms_create_cohort(['title' => 'Full Cohort', 'instructor_id' => $insA, 'course_id' => $pianoId, 'capacity' => 1]);
$enrolled = mam_db_insert('users', ['email' => 't_schfix_e@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Enrolled', 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $enrolled, 'course_id' => $pianoId, 'status' => 'active', 'cohort_id' => $full]);
t_eq(mam_lms_cohort_fill_count($full)['available'], 0, 'the cohort is at capacity');

$w1 = mam_db_insert('users', ['email' => 't_schfix_w1@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Wait One', 'status' => 'active']);
$w2 = mam_db_insert('users', ['email' => 't_schfix_w2@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Wait Two', 'status' => 'active']);
t_eq(mam_lms_waitlist_add($full, $w1), 1, 'first waitlister gets position 1');
t_eq(mam_lms_waitlist_add($full, $w2), 2, 'second waitlister gets position 2');
t_eq(mam_lms_waitlist_add($full, $w1), 1, 'joining again is idempotent (same position)');

$blocked = false;
try { mam_lms_waitlist_promote($full, $insA); }
catch (RuntimeException $e) { $blocked = ($e->getMessage() === 'cohort_full'); }
t_ok($blocked, 'promote into a full cohort is refused with cohort_full');
t_eq(mam_lms_cohort_fill_count($full)['enrolled'], 1, 'the refused promote did not enrol anyone');

$promoted = mam_lms_waitlist_promote($full, $insA, true); // force override
t_eq($promoted, $w1, 'force-promote enrols the first waitlister (over capacity)');
t_eq(mam_lms_cohort_fill_count($full)['enrolled'], 2, 'the forced promote over-filled the cohort by one');

// ── A6: booking NGN amount tracks live fx, not the old frozen cross-rate ──
$rates = mam_currency_rates();
$gb = mam_booking_tier('GB'); // default tier: £4
$expUsd = round(4 / $rates['GBP'], 2);
$expNgn = (int)round($expUsd * $rates['NGN']);
t_eq($gb['amount_ngn'], $expNgn, 'GBP booking fee → NGN uses the live fx map (GBP + NGN rates)');
t_near($gb['amount_usd'], $expUsd, 'GBP booking fee → USD uses the live fx map');
