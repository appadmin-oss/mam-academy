<?php
/**
 * Tests for module status + carry-forward (api/modules/academics/lib/lms2.php, PDF §6-7, 29-30):
 * the §7 status vocabulary + mastery, and a carry-forward that appends history
 * (no data deleted), bumps attempts, and re-dates the module.
 */
declare(strict_types=1);

t_group('module — status & mastery');

$MD_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($MD_course === 0) $MD_course = mam_db_insert('courses', ['code' => 'md_c', 'name' => 'MD', 'kind' => 'core']);
$MD_stu = mam_db_insert('users', ['name' => 'MD Stu', 'email' => 'md_stu@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
$MD_coh = mam_db_insert('cohorts', ['course_id' => $MD_course, 'title' => 'MD Cohort', 'status' => 'active', 'capacity' => 10]);
$MD_les = mam_db_insert('lessons', ['cohort_id' => $MD_coh, 'title' => 'Module 4', 'week_no' => 4, 'published' => 1]);

$st = mam_module_state($MD_les, $MD_stu);
t_eq($st['status'], 'not_started', 'unseen module defaults to not_started');

$st = mam_module_status_set($MD_les, $MD_stu, 'developing', 55.0, 4);
t_eq($st['status'], 'developing', 'status set to developing');
t_near((float) $st['mastery_pct'], 55.0, 'mastery recorded');
t_eq((int) $st['week_started'], 4, 'week_started stamped on first movement');

$st = mam_module_status_set($MD_les, $MD_stu, 'mastered', 87.0, 6);
t_eq($st['status'], 'mastered', 'status advanced to mastered');
t_near((float) $st['mastery_pct'], 87.0, 'mastery updated to 87');
t_eq((int) $st['week_completed'], 6, 'week_completed stamped on a terminal status');
t_ok($st['completed_at'] !== null, 'completion timestamp set');

$threw = false;
try { mam_module_status_set($MD_les, $MD_stu, 'nonsense'); } catch (\InvalidArgumentException $e) { $threw = true; }
t_ok($threw, 'an unknown status is rejected');

t_group('module — carry-forward (no history deleted)');

$MD_les2 = mam_db_insert('lessons', ['cohort_id' => $MD_coh, 'title' => 'Module 5', 'week_no' => 5, 'published' => 1]);
mam_module_status_set($MD_les2, $MD_stu, 'developing', 40.0, 5);

$cf = mam_module_carry_forward($MD_les2, $MD_stu, 6, null, 'Needs more practice', null);
t_ok($cf['event_id'] > 0, 'carry-forward event recorded');
t_eq($cf['state']['status'], 'carried_forward', 'module marked carried_forward');
t_eq((int) $cf['state']['attempts'], 1, 'attempts bumped to 1');

// A second carry-forward keeps BOTH events (history is appended, never deleted).
mam_module_status_set($MD_les2, $MD_stu, 'developing', 60.0, 6);
$cf2 = mam_module_carry_forward($MD_les2, $MD_stu, 7, null, 'Still developing', null);
t_eq((int) $cf2['state']['attempts'], 2, 'attempts bumped to 2');

$hist = mam_module_history($MD_les2, $MD_stu);
t_eq(count($hist['history']), 2, 'both carry-forward events retained');
t_eq((int) $hist['history'][0]['to_week'], 7, 'newest event first (to_week 7)');
t_eq($hist['history'][1]['previous_status'], 'developing', 'first event captured the prior status');
t_near((float) $hist['history'][1]['previous_mastery'], 40.0, 'first event captured the prior mastery');
