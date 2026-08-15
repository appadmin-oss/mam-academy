<?php
/**
 * Tests for parent accounts + guardian portal (api/lib/parents.php, PDF §26):
 * provisioning + role grant, guardian↔student linking (idempotent), the
 * children list + weekly status, and access control on child detail.
 */
declare(strict_types=1);

t_group('parents — provision & link');

$PR_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($PR_course === 0) $PR_course = mam_db_insert('courses', ['code' => 'pr_c', 'name' => 'PR', 'kind' => 'core']);
$PR_stu = mam_db_insert('users', ['name' => 'Kid One', 'email' => 'kid1@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active', 'enrollment_ref' => 'MAM/26/PR1']);
$PR_stu2 = mam_db_insert('users', ['name' => 'Kid Two', 'email' => 'kid2@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);

$prov = mam_parent_provision('guardian@test.local', 'Pat Guardian');
t_ok($prov['user'] !== null && (int) $prov['user']['id'] > 0, 'parent user provisioned');
t_eq($prov['user']['role'], 'parent', 'primary role is parent');
t_ok($prov['password'] !== null, 'a temp password is returned for a new account');
t_ok(mam_user_has_role($prov['user'], 'parent'), 'parent role granted');
// Re-provision is idempotent (no new password).
t_ok(mam_parent_provision('guardian@test.local')['password'] === null, 're-provision returns no new password');

$link = mam_parent_link('guardian@test.local', $PR_stu, 'mother', null);
t_ok($link['created'], 'guardian linked to the child');
$parentId = (int) $link['parent_id'];
// Idempotent link.
t_ok(!mam_parent_link('guardian@test.local', $PR_stu)['created'], 're-linking the same pair is a no-op');
mam_parent_link('guardian@test.local', $PR_stu2); // a second child

t_group('parents — children list & access control');

$kids = mam_parent_children($parentId);
t_eq(count($kids), 2, 'parent sees both linked children');
$k1 = array_values(array_filter($kids, fn($k) => (int) $k['student_id'] === $PR_stu))[0];
t_eq($k1['relationship'], 'mother', 'relationship recorded');
t_ok(isset($k1['weekly']['attendance']), 'weekly status carries an attendance light');

t_ok(mam_parent_can_view($parentId, $PR_stu), 'parent may view a linked child');
$stranger = mam_db_insert('users', ['name' => 'Other Kid', 'email' => 'kid9@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
t_ok(!mam_parent_can_view($parentId, $stranger), 'parent may not view an unlinked child');

$detail = mam_parent_child_detail($parentId, $PR_stu);
t_ok(isset($detail['weekly']) && isset($detail['student']), 'child detail assembles weekly status + identity');
$threw = false;
try { mam_parent_child_detail($parentId, $stranger); } catch (\RuntimeException $e) { $threw = ($e->getMessage() === 'forbidden'); }
t_ok($threw, 'child detail is refused for an unlinked student');

// §26 — instructor feedback surfaces in the guardian view.
$PR_ins = mam_db_insert('users', ['name' => 'PR Ins', 'email' => 'pr_ins@test.local', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$PR_asg = mam_db_insert('assignments', ['student_id' => $PR_stu, 'title' => 'Etude', 'status' => 'open']);
$PR_sub = mam_db_insert('submissions', ['assignment_id' => $PR_asg, 'student_id' => $PR_stu, 'artifact_url' => 'https://x/y', 'artifact_kind' => 'link']);
mam_lms_give_feedback($PR_sub, $PR_ins, ['outcome' => 'remedial', 'comments' => 'Focus on bar 4 dynamics.']);
$detailFb = mam_parent_child_detail($parentId, $PR_stu);
t_ok(!empty($detailFb['instructor_feedback']), 'child detail surfaces instructor feedback (§26)');
t_eq($detailFb['instructor_feedback'][0]['comments'], 'Focus on bar 4 dynamics.', 'feedback comment reaches the guardian view');
t_eq($detailFb['instructor_feedback'][0]['outcome'], 'remedial', 'feedback outcome reaches the guardian view');

t_group('parents — guardian KPI strip (§5.2)');

// Everything the strip reports must degrade rather than throw: a guardian
// opening a brand-new child's page sees zeros and nulls, never a 500.
$PR_fresh = mam_db_insert('users', ['name' => 'Fresh Kid', 'email' => 'kid_fresh@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$PR_k0 = mam_parent_value_kpis($PR_fresh);
t_eq($PR_k0['practice_minutes'], 0, 'a child with no practice logs reports 0 minutes, not null');
t_eq($PR_k0['practice_hours'], 0.0, 'hours derive from minutes');
t_eq($PR_k0['badges_this_month'], 0, 'no badges yet');
t_ok($PR_k0['next_session'] === null, 'no upcoming session is null, not a fabricated entry');
t_ok($PR_k0['attendance_rate'] === null, 'attendance is null when the report carries none');

mam_db_insert('practice_logs', ['student_id' => $PR_stu, 'minutes' => 45, 'logged_on' => date('Y-m-d')]);
mam_db_insert('practice_logs', ['student_id' => $PR_stu, 'minutes' => 30, 'logged_on' => date('Y-m-d', strtotime('-3 days'))]);
$PR_k = mam_parent_value_kpis($PR_stu);
t_eq($PR_k['practice_minutes'], 75, 'practice minutes sum across all logs (lifetime, not this week)');
t_eq($PR_k['practice_hours'], 1.3, 'hours are minutes/60 rounded to one decimal');

// Badges are scoped to the CALENDAR month, so a badge from last month drops
// off. Asserted as a delta: mam_ach_evaluate() runs inside child detail and has
// already awarded this student real badges earlier in this file.
$PR_bBase = mam_parent_value_kpis($PR_stu)['badges_this_month'];
mam_db_insert('achievements', ['student_id' => $PR_stu, 'code' => 'pr_thismonth', 'title' => 'This month', 'awarded_at' => date('Y-m-01') . ' 09:00:00']);
mam_db_insert('achievements', ['student_id' => $PR_stu, 'code' => 'pr_lastmonth', 'title' => 'Last month', 'awarded_at' => date('Y-m-d H:i:s', strtotime(date('Y-m-01') . ' -5 days'))]);
t_eq(mam_parent_value_kpis($PR_stu)['badges_this_month'] - $PR_bBase, 1, 'only badges awarded this calendar month are counted');

// The strip reads attendance and next-session from the report it is handed,
// rather than re-querying — so a caller can pass a report through unchanged.
$PR_kr = mam_parent_value_kpis($PR_stu, [
    'attendance'        => ['rate' => 87.456],
    'upcoming_sessions' => [['title' => 'Recital rehearsal', 'scheduled_at' => '2026-09-01 10:00:00', 'cohort_title' => 'Strings A']],
]);
t_eq($PR_kr['attendance_rate'], 87.5, 'attendance rate is rounded to one decimal for display');
t_eq($PR_kr['next_session']['title'], 'Recital rehearsal', 'the next session is the first upcoming one');
t_eq($PR_kr['next_session']['cohort_title'], 'Strings A', 'the cohort travels with the next session');

$PR_detailK = mam_parent_child_detail($parentId, $PR_stu);
t_ok(isset($PR_detailK['value_kpis']), 'child detail carries the KPI block');
t_eq($PR_detailK['value_kpis']['practice_minutes'], 75, 'the KPI block in child detail matches a direct call');

t_group('parents — unlink');

mam_parent_unlink($parentId, $PR_stu2);
t_eq(count(mam_parent_children($parentId)), 1, 'unlinking removes a child');
