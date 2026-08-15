<?php
declare(strict_types=1);
t_group('instructor cohort analytics (§5.3)');
$IA_ins = mam_db_insert('users', ['name' => 'IA Ins', 'email' => 'ia_ins@test.local', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$IA_s1  = mam_db_insert('users', ['name' => 'IA S1', 'email' => 'ia_s1@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$IA_s2  = mam_db_insert('users', ['name' => 'IA S2', 'email' => 'ia_s2@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$IA_coh = mam_db_insert('cohorts', ['instructor_id' => $IA_ins, 'title' => 'IA Cohort', 'status' => 'active', 'capacity' => 10]);
$IA_les = mam_db_insert('lessons', ['cohort_id' => $IA_coh, 'title' => 'Week 1', 'published' => 1]);
mam_db_insert('lesson_progress', ['lesson_id' => $IA_les, 'student_id' => $IA_s1, 'status' => 'completed', 'percent' => 80]);
mam_db_insert('lesson_progress', ['lesson_id' => $IA_les, 'student_id' => $IA_s2, 'status' => 'carried_forward', 'percent' => 40, 'attempts' => 2]);

$co = mam_analytics2_instructor_cohorts($IA_ins);
$row = null; foreach ($co as $c) { if ((int)$c['cohort_id'] === $IA_coh) { $row = $c; break; } }
t_ok($row !== null, 'the instructor\'s cohort is returned');
t_eq($row['completion_pct'], 50, 'completion = 1 of 2 lesson_progress completed');
t_eq($row['avg_mastery'], 60, 'avg mastery = (80+40)/2');
t_eq($row['at_risk'], 1, 'one at-risk student (carried_forward)');

$risk = mam_analytics2_instructor_at_risk($IA_ins);
t_ok(count(array_filter($risk, fn($x) => (int)$x['student_id'] === $IA_s2)) === 1, 'at-risk list includes the carried-forward student');

$other = mam_db_insert('users', ['name' => 'IA Oth', 'email' => 'ia_oth@test.local', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
t_ok(!in_array($IA_coh, array_column(mam_analytics2_instructor_cohorts($other), 'cohort_id'), true), 'another instructor does not see this cohort');
