<?php
/**
 * Enrolment lifecycle (LMS L1) — the enforced state machine + append-only
 * transition audit. Replaces the old free-text status writes: every change is
 * now validated and recorded (who/when/why) in enrolment_events.
 */
declare(strict_types=1);
t_group('enrolment lifecycle (state machine + audit)');

// ── Pure transition graph ────────────────────────────────────────────────
t_ok(mam_lms_can_transition('pending', 'active'),     'pending → active is legal');
t_ok(mam_lms_can_transition('pending', 'rejected'),   'pending → rejected is legal');
t_ok(mam_lms_can_transition('pending', 'withdrawn'),  'pending → withdrawn is legal');
t_ok(mam_lms_can_transition('active', 'completed'),   'active → completed is legal');
t_ok(mam_lms_can_transition('active', 'failed'),      'active → failed is legal');
t_ok(mam_lms_can_transition('active', 'withdrawn'),   'active → withdrawn is legal');
t_ok(!mam_lms_can_transition('active', 'pending'),    'active → pending is ILLEGAL (no going back)');
t_ok(!mam_lms_can_transition('completed', 'active'),  'completed is terminal');
t_ok(!mam_lms_can_transition('withdrawn', 'active'),  'withdrawn is terminal');
t_ok(!mam_lms_can_transition('pending', 'completed'), 'pending → completed must route through active');
t_ok(!mam_lms_can_transition('bogus', 'active'),      'an unknown source state has no legal move');

// ── Setup ────────────────────────────────────────────────────────────────
$sid = mam_db_insert('users', ['email' => 't_life@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Lifecycle Student', 'status' => 'active']);
$iid = mam_db_insert('users', ['email' => 't_life_ins@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Life Instructor', 'status' => 'active']);

// ── add_course writes a creation event ─────────────────────────────────────
$r = mam_lms_add_course($sid, 'piano', 'precert', 'standard');
t_eq($r['status'], 'pending', 'manual policy → enrolment starts pending');
$eid = (int)$r['id'];
$ev = mam_lms_enrolment_events($eid);
t_eq(count($ev), 1, 'creation writes exactly one lifecycle event');
t_ok($ev[0]['from_status'] === null, 'creation event has no from-status (the enrolment is born)');
t_eq($ev[0]['to_status'], 'pending', 'creation event records the initial state');

// ── approve → active (transition + event + approved_at + instructor) ───────
mam_lms_approve($eid, $iid, 1);
$row = mam_db_row('SELECT status, approved_by, approved_at, status_changed_at, instructor_id FROM course_enrollments WHERE id = ?', [$eid]);
t_eq($row['status'], 'active', 'approve activates the enrolment');
t_eq((int)$row['approved_by'], 1, 'approver recorded');
t_ok(!empty($row['approved_at']), 'approved_at stamped');
t_ok(!empty($row['status_changed_at']), 'status_changed_at stamped on transition');
t_eq((int)$row['instructor_id'], $iid, 'instructor assigned on approval');
$ev = mam_lms_enrolment_events($eid);
t_eq(count($ev), 2, 'approval appends a second event');
t_eq($ev[1]['from_status'], 'pending', 'transition event records the from-status');
t_eq($ev[1]['to_status'], 'active', 'transition event records the to-status');
t_eq($ev[1]['reason'], 'approved', 'transition reason captured');

// ── Idempotent: re-approving an active enrolment writes no duplicate event ──
mam_lms_approve($eid, $iid, 1);
t_eq(count(mam_lms_enrolment_events($eid)), 2, 're-approving an active enrolment is a no-op (no duplicate event)');

// ── Illegal transitions throw ──────────────────────────────────────────────
$threw = false; try { mam_lms_transition($eid, 'pending', 1, 'x'); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'illegal_transition'; }
t_ok($threw, 'active → pending throws illegal_transition');
$threw = false; try { mam_lms_transition(99999, 'active'); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'enrollment_not_found'; }
t_ok($threw, 'transition on a missing enrolment throws enrollment_not_found');

// ── finalize: refuses ungraded, then closes a passing course as completed ──
$threw = false; try { mam_lms_finalize($eid, 1); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'not_graded'; }
t_ok($threw, 'finalize refuses an ungraded enrolment (not_graded)');
mam_lms_record_assessment($eid, 'final', $iid, 90, 100, 12, null);   // passing mark
$fin = mam_lms_finalize($eid, 1);
t_eq($fin['status'], 'completed', 'a passing finalize → completed');
t_eq(mam_db_row('SELECT status FROM course_enrollments WHERE id = ?', [$eid])['status'], 'completed', 'enrolment is now completed');
$threw = false; try { mam_lms_withdraw($eid, 1); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'illegal_transition'; }
t_ok($threw, 'cannot withdraw a completed enrolment (terminal)');

// ── Withdraw a pending enrolment, with an audited reason ───────────────────
$eid2 = (int)mam_lms_add_course($sid, 'voice', 'precert', 'standard')['id'];
$t = mam_lms_withdraw($eid2, $sid, 'changed mind');
t_eq($t['to'], 'withdrawn', 'a pending enrolment withdraws');
t_eq($t['from'], 'pending', 'withdraw records the prior state');
$ev2 = mam_lms_enrolment_events($eid2);
t_eq(end($ev2)['reason'], 'changed mind', 'withdraw reason captured in the audit trail');

// ── finalize a failing enrolment → failed ──────────────────────────────────
$eid3 = (int)mam_lms_add_course($sid, 'guitar', 'precert', 'standard')['id'];
mam_lms_approve($eid3, $iid, 1);
mam_lms_record_assessment($eid3, 'final', $iid, 10, 100, 12, null);  // failing mark
t_eq(mam_lms_finalize($eid3, 1)['status'], 'failed', 'a failing finalize → failed');
