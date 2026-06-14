<?php
/**
 * Class sessions (LMS L2 — scheduling on cohorts). The v21 class_sessions table,
 * the create/list/fetch service, and the scheduled→held|cancelled status machine.
 */
declare(strict_types=1);
t_group('class sessions (cohort scheduling, L2)');

$cid = mam_lms_create_cohort(['title' => 'Sched Cohort']);

// ── Create + fetch ──
$s1 = mam_lms_create_session($cid, ['title' => 'Week 1', 'scheduled_at' => '2026-07-10 17:00', 'kind' => 'class', 'duration_min' => 45]);
t_ok($s1 > 0, 'create_session returns an id');
$f = mam_lms_session($s1);
t_eq($f['cohort_title'], 'Sched Cohort', 'session fetch resolves the cohort title');
t_eq((int)$f['duration_min'], 45, 'duration is carried');
t_eq($f['kind'], 'class', 'kind is carried');
t_eq($f['status'], 'scheduled', 'a new session is scheduled');

// invalid kind falls back to 'class'
$sx = mam_lms_create_session($cid, ['kind' => 'bogus']);
t_eq(mam_lms_session($sx)['kind'], 'class', 'an unknown kind falls back to class');

// ── Listing is soonest-first ──
$s2 = mam_lms_create_session($cid, ['title' => 'Week 0', 'scheduled_at' => '2026-07-03 17:00']);
$list = mam_lms_cohort_sessions($cid);
t_ok(count($list) >= 3, 'all sessions are listed for the cohort');
t_eq((int)$list[0]['id'], $s2, 'sessions are ordered soonest-first');

// ── Bad cohort ──
$threw = false; try { mam_lms_create_session(99999, []); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'cohort_not_found'; }
t_ok($threw, 'scheduling on a missing cohort throws cohort_not_found');

// ── Status machine ──
t_ok(mam_lms_session_can_transition('scheduled', 'held') && mam_lms_session_can_transition('scheduled', 'cancelled'), 'scheduled → held|cancelled is legal');
t_ok(!mam_lms_session_can_transition('held', 'scheduled') && !mam_lms_session_can_transition('cancelled', 'held'), 'held and cancelled are terminal');
$t = mam_lms_set_session_status($s1, 'held', 1);
t_eq($t['to'], 'held', 'session marked held');
t_eq(mam_lms_session($s1)['status'], 'held', 'held status persisted');
t_ok(mam_lms_set_session_status($s1, 'held', 1)['changed'] === false, 're-applying the same status is a no-op');
$threw = false; try { mam_lms_set_session_status($s1, 'scheduled', 1); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'illegal_transition'; }
t_ok($threw, 'held → scheduled is illegal');
$threw = false; try { mam_lms_set_session_status(99999, 'held'); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'session_not_found'; }
t_ok($threw, 'status change on a missing session throws session_not_found');
