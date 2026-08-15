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

t_group('parents — unlink');

mam_parent_unlink($parentId, $PR_stu2);
t_eq(count(mam_parent_children($parentId)), 1, 'unlinking removes a child');
