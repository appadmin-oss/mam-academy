<?php
/**
 * Tests for LMS 2.0 academic analytics (api/lib/analytics2.php, PDF §32-35):
 * academy health shape/bounds, per-instructor performance (scoped, exact), and
 * the today snapshot.
 */
declare(strict_types=1);

t_group('analytics2 — academy health');

$h = mam_analytics2_academy_health();
foreach (['students', 'attendance_pct', 'preparation_pct', 'teachback_pct', 'avg_mastery', 'module_completion_pct', 'intervention'] as $k) {
    t_ok(array_key_exists($k, $h), "health has '$k'");
}
t_ok($h['attendance_pct'] >= 0 && $h['attendance_pct'] <= 100, 'attendance % within 0–100');
t_ok($h['module_completion_pct'] >= 0 && $h['module_completion_pct'] <= 100, 'module completion % within 0–100');

t_group('analytics2 — instructor performance (scoped, exact)');

$AN_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($AN_course === 0) $AN_course = mam_db_insert('courses', ['code' => 'an_c', 'name' => 'AN', 'kind' => 'core']);
$AN_ins = mam_db_insert('users', ['name' => 'Analytics Ins', 'email' => 'an_ins@test.local', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$AN_coh = mam_db_insert('cohorts', ['course_id' => $AN_course, 'title' => 'AN Cohort', 'status' => 'active', 'capacity' => 10, 'instructor_id' => $AN_ins]);
$AN_s1 = mam_db_insert('users', ['name' => 'AN S1', 'email' => 'an_s1@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$AN_s2 = mam_db_insert('users', ['name' => 'AN S2', 'email' => 'an_s2@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$AN_e1 = mam_db_insert('course_enrollments', ['student_id' => $AN_s1, 'course_id' => $AN_course, 'cohort_id' => $AN_coh, 'instructor_id' => $AN_ins, 'status' => 'active']);
$AN_e2 = mam_db_insert('course_enrollments', ['student_id' => $AN_s2, 'course_id' => $AN_course, 'cohort_id' => $AN_coh, 'instructor_id' => $AN_ins, 'status' => 'active']);
mam_db_insert('results', ['enrollment_id' => $AN_e1, 'total_score' => 80.0, 'grade' => 'A', 'outcome' => 'pass']);
mam_db_insert('results', ['enrollment_id' => $AN_e2, 'total_score' => 40.0, 'grade' => 'F', 'outcome' => 'fail']);
// One intervention: a carried-forward / multi-attempt module for S1.
$AN_les = mam_db_insert('lessons', ['cohort_id' => $AN_coh, 'title' => 'AN L', 'published' => 1]);
mam_db_insert('lesson_progress', ['lesson_id' => $AN_les, 'student_id' => $AN_s1, 'status' => 'carried_forward', 'attempts' => 2]);

$perf = mam_analytics2_instructor_performance();
$mine = array_values(array_filter($perf, fn($r) => (int) $r['instructor_id'] === $AN_ins))[0];
t_eq($mine['students'], 2, 'two students under the instructor');
t_near((float) $mine['avg_mastery'], 60.0, 'avg mastery = (80+40)/2');
t_eq($mine['completion_pct'], 50.0, 'completion = 1 pass of 2 graded');
t_eq($mine['interventions'], 1, 'one student flagged for intervention');

t_group('analytics2 — today snapshot');

$AN_sessToday = mam_db_insert('class_sessions', ['cohort_id' => $AN_coh, 'title' => 'AN Today', 'scheduled_at' => date('Y-m-d H:i:s'), 'status' => 'held', 'duration_min' => 60, 'kind' => 'class']);
$before = mam_analytics2_today();
mam_db_insert('session_attendance', ['session_id' => $AN_sessToday, 'student_id' => $AN_s1, 'status' => 'present', 'method' => 'self']);
$after = mam_analytics2_today();
t_eq($after['present'] - $before['present'], 1, "today's present count reflects a new check-in");
t_ok($after['sessions_today'] >= 1, 'a session scheduled today is counted');

t_group('analytics2 — intervention list (§31/§38)');
// A fresh student with 3 absences → Level 3 (academic review).
$AN_risk = mam_db_insert('users', ['name' => 'AN Risk', 'email' => 'an_risk@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
for ($i = 0; $i < 3; $i++) {
    $sid = mam_db_insert('class_sessions', ['cohort_id' => $AN_coh, 'title' => 'Miss ' . $i, 'scheduled_at' => date('Y-m-d H:i:s', strtotime('-' . ($i + 1) . ' day')), 'status' => 'held', 'duration_min' => 60, 'kind' => 'class']);
    mam_db_insert('session_attendance', ['session_id' => $sid, 'student_id' => $AN_risk, 'status' => 'absent', 'method' => 'instructor']);
}
$list = mam_analytics2_intervention_list(100);
$riskRow = null; foreach ($list as $r) { if ($r['student_id'] === $AN_risk) { $riskRow = $r; break; } }
t_ok($riskRow !== null, 'a student with 3 absences is flagged');
t_eq($riskRow['level'], 3, '3+ absences escalates to Level 3 (academic review)');
t_ok(strpos(implode(' ', $riskRow['reasons']), '3 absences') !== false, 'reasons name the absence count');
t_ok(strpos($riskRow['escalation'], 'review') !== false, 'Level 3 escalation targets a review');
// $AN_s1 has one carried_forward module → at least Level 1, and appears in the list.
$s1Row = null; foreach ($list as $r) { if ($r['student_id'] === $AN_s1) { $s1Row = $r; break; } }
t_ok($s1Row !== null && $s1Row['level'] >= 1, 'a carried-forward student is flagged for follow-up');
// Sorted by level desc — the first row is the highest level.
t_ok(!count($list) || $list[0]['level'] >= $list[count($list) - 1]['level'], 'list is sorted by severity');
