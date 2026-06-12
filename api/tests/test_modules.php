<?php
/** Modules / week-plan — programme shape, outline merge, scaffold. */
declare(strict_types=1);
t_group('modules / week plan');

$std   = (int)mam_db_row("SELECT id FROM programs WHERE code='standard'")['id'];
$crash = (int)mam_db_row("SELECT id FROM programs WHERE code='crash'")['id'];

// 12-week shape: wk 1–10 modules · wk 11 revision · wk 12 exam.
$plan = mam_lms_program_week_plan($std);
t_eq(count($plan), 12, 'standard programme = 12 weeks');
t_eq($plan[0]['kind'], 'module', 'week 1 is a module');
t_eq($plan[10]['kind'], 'revision', 'week 11 is revision');
t_eq($plan[11]['kind'], 'exam', 'week 12 is exam');

// 6-week crash: wk 1–5 modules · wk 6 exam · no revision.
$cplan = mam_lms_program_week_plan($crash);
t_eq(count($cplan), 6, 'crash programme = 6 weeks');
t_eq($cplan[5]['kind'], 'exam', 'crash week 6 is exam');
t_ok(!in_array('revision', array_column($cplan, 'kind'), true), 'crash has no revision week');

// Outline merges authored modules with the programme plan.
$cid = (int)mam_db_row("SELECT id FROM courses WHERE code='theory'")['id'];
$pre = (int)mam_db_row("SELECT id FROM levels WHERE code='precert'")['id'];
mam_lms_save_module(null, $cid, $pre, 1, 'module', 'Foundations of Notation', 'Staves, clefs, note values.');
$out = mam_lms_course_outline('theory', 'precert', 'standard');
t_eq(count($out['weeks']), 12, 'outline framed to 12 weeks');
t_eq($out['weeks'][0]['title'], 'Foundations of Notation', 'authored title shows on week 1');
t_ok($out['weeks'][0]['authored'] === true, 'week 1 flagged authored');
t_ok($out['weeks'][1]['authored'] === false, 'week 2 unauthored (plan only)');
t_eq($out['weeks'][10]['kind'], 'revision', 'outline week 11 = revision (from plan)');
t_eq($out['weeks'][11]['kind'], 'exam', 'outline week 12 = exam');

// Scaffold fills the missing weeks, idempotently.
$made = mam_lms_scaffold_modules($cid, $pre, $std);
t_eq($made, 11, 'scaffold created the 11 missing weeks (week 1 already authored)');
t_eq(mam_lms_scaffold_modules($cid, $pre, $std), 0, 'scaffold is idempotent');
t_eq(count(mam_lms_course_modules($cid, $pre)), 12, 'course now has all 12 week rows');
