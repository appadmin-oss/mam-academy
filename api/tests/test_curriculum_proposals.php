<?php
/** Curriculum proposals — faculty author, admin approves into the live modules. */
declare(strict_types=1);
t_group('curriculum proposals');

$iid = mam_db_insert('users', ['email' => 't_cp_i@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Prof Prop', 'status' => 'active']);
$cid = (int)mam_db_row("SELECT id FROM courses WHERE code='theory'")['id'];

// Propose a week — lands as pending, does NOT touch `modules`.
$before = (int)mam_db_row('SELECT COUNT(*) AS n FROM modules WHERE course_id = ? AND week = 99', [$cid])['n'];
$pid = mam_lms_propose_module($cid, null, 99, 'module', 'Proposed week 99', 'Sight-reading drills', $iid);
t_ok($pid > 0, 'proposal created');
$p = mam_db_row('SELECT * FROM module_proposals WHERE id = ?', [$pid]);
t_eq($p['status'], 'pending', 'new proposal is pending');
$after = (int)mam_db_row('SELECT COUNT(*) AS n FROM modules WHERE course_id = ? AND week = 99', [$cid])['n'];
t_eq($after, $before, 'proposing does not write the live curriculum');

// It shows up for the instructor and in the admin pending queue.
t_ok(count(mam_lms_instructor_proposals($iid)) >= 1, 'instructor sees their proposal');
$pending = mam_lms_pending_proposals();
$found = false; foreach ($pending as $pp) { if ((int)$pp['id'] === $pid) $found = true; }
t_ok($found, 'proposal appears in the admin pending queue');

// Approve → applied to `modules` (week 99 now authored) + status approved.
$res = mam_lms_review_proposal($pid, true, null, 'looks good');
t_eq($res['status'], 'approved', 'approved proposal marked approved');
$mod = mam_db_row('SELECT * FROM modules WHERE course_id = ? AND week = 99', [$cid]);
t_ok($mod && $mod['title'] === 'Proposed week 99', 'approval upserts the week into the live curriculum');

// Re-reviewing an already-decided proposal is a no-op (idempotent).
$res2 = mam_lms_review_proposal($pid, false, null, 'changed mind');
t_eq($res2['status'], 'approved', 'already-decided proposal is not flipped by a second review');

// Reject path: a fresh proposal, rejected, leaves the curriculum untouched.
$pid2 = mam_lms_propose_module($cid, null, 98, 'module', 'Reject me', null, $iid);
mam_lms_review_proposal($pid2, false, null, 'not this term');
t_eq(mam_db_row('SELECT status FROM module_proposals WHERE id = ?', [$pid2])['status'], 'rejected', 'rejected proposal marked rejected');
t_ok(!mam_db_row('SELECT id FROM modules WHERE course_id = ? AND week = 98', [$cid]), 'rejecting does not author the week');
