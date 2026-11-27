<?php
/**
 * Only cleared adults see children's records (vetting.php, NEXT-AGENT §4 item 3,
 * ported from NGG). The assignment gate already existed; this pins the DOORS:
 * register, a child's records (incidents, collectors, growth, media go through
 * mam_media_student_reader), and a class space's teacher role. Only `enforce`
 * refuses, and `warn` — what the academy runs today — must change nothing.
 */
declare(strict_types=1);

$VR_now = gmdate('YmdHis') . random_int(100, 999);
$VR_u = static fn(string $tag, string $role) => (int) mam_db_insert('users', ['email' => "vr.$tag.$VR_now@test.local", 'name' => ucfirst($tag) . ' Vet',
    'role' => $role, 'password_hash' => 'x', 'status' => 'active']);
$VR_kid = $VR_u('kid', 'student'); $VR_adult = $VR_u('adult', 'student');
$VR_raw = $VR_u('uncleared', 'instructor'); $VR_ok = $VR_u('cleared', 'instructor');
$VR_user = static fn(int $id) => mam_db_row('SELECT * FROM users WHERE id = ?', [$id]);
mam_db_insert('customers', ['email' => "vr.adult.$VR_now@test.local", 'first_name' => 'Adult', 'last_name' => 'Vet', 'dob' => '1985-02-02']);
$VR_admin = $VR_u('admin', 'admin');
foreach (MAM_VETTING_REQUIRED as $VR_type) {
    mam_vetting_record($VR_ok, $VR_type, $VR_admin, gmdate('Y-m-d', time() - 86400), gmdate('Y-m-d', time() + 365 * 86400));
}
$VR_course = (int) mam_db_insert('courses', ['code' => 'vr_' . $VR_now, 'name' => 'Vet ' . $VR_now, 'kind' => 'elective', 'active' => 1, 'sort_order' => 99]);
$VR_kids = (int) mam_db_insert('cohorts', ['course_id' => $VR_course, 'instructor_id' => $VR_raw, 'title' => 'Kids ' . $VR_now, 'status' => 'active']);
$VR_grown = (int) mam_db_insert('cohorts', ['course_id' => $VR_course, 'instructor_id' => $VR_raw, 'title' => 'Grown ' . $VR_now, 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $VR_kid, 'course_id' => $VR_course, 'cohort_id' => $VR_kids, 'status' => 'active', 'instructor_id' => $VR_ok]);
mam_db_insert('course_enrollments', ['student_id' => $VR_adult, 'course_id' => $VR_course, 'cohort_id' => $VR_grown, 'status' => 'active']);
$VR_sess = (int) mam_db_insert('class_sessions', ['cohort_id' => $VR_kids, 'title' => 'Vet session', 'scheduled_at' => gmdate('Y-m-d H:i:s'),
    'status' => 'scheduled', 'duration_min' => 60, 'kind' => 'class']);
$GLOBALS['__mam_vetting_nomemo'] = true;
$VR_was = mam_vetting_mode();

t_group('vetting — warn changes nothing');
mam_setting_set('safeguarding_gate_mode', 'warn');
t_ok(mam_vetting_may_see_child($VR_raw, $VR_kid), 'in warn an uncleared teacher still opens a child\'s record');
t_ok(mam_media_student_reader($VR_kid, $VR_user($VR_raw)), '…and the record doors (incidents, collectors, growth, media) stay open');
t_ok(empty(mam_iportal_register($VR_raw, $VR_sess)['refused']), '…and the register');

t_group('vetting — enforce closes every door to an uncleared teacher');
mam_setting_set('safeguarding_gate_mode', 'enforce');
t_ok(!mam_media_student_reader($VR_kid, $VR_user($VR_raw)), 'a child\'s records are closed to an uncleared teacher');
t_ok(!mam_incident_can($VR_user($VR_raw), $VR_kid), '…so are their incidents and collectors');
$VR_reg = mam_iportal_register($VR_raw, $VR_sess);
t_ok(!empty($VR_reg['refused']) && $VR_reg['roster'] === [], 'the register of a class with a child gives no roster');
t_ok(str_contains((string) $VR_reg['save_notice'], 'clearance'), '…and says why, rather than reading as an empty class');
$VR_space = ['id' => 0, 'cohort_id' => $VR_kids, 'visibility' => 'class'];
t_eq(mam_space_member_role($VR_space, $VR_raw), null, 'an uncleared teacher is not the teacher of a class space with children');
// The harness stubs mam_audit() (run.php), so the record is pinned in the source: both doors write it.
t_eq(substr_count((string) file_get_contents(dirname(__DIR__) . '/modules/safeguarding/lib/vetting.php'), "mam_audit('vetting_record_refused'"), 2, 'each refusal is audited for the officer');

t_group('vetting — enforce does not touch what it should not');
t_ok(mam_media_student_reader($VR_kid, $VR_user($VR_ok)), 'a cleared teacher opens the child\'s records');
t_ok(mam_vetting_may_see_child($VR_raw, $VR_adult), 'an adult student is never gated');
t_ok(mam_vetting_may_see_cohort($VR_raw, $VR_grown), 'nor is a class of adults');
t_eq(mam_space_member_role(['id' => 0, 'cohort_id' => $VR_grown, 'visibility' => 'class'], $VR_raw), 'teacher', '…whose uncleared teacher is still its teacher');
t_ok(mam_incident_can($VR_user($VR_admin), $VR_kid), 'an admin is not gated as an instructor (the gate is on the instructor branch)');
$VR_route = (string) file_get_contents(dirname(__DIR__) . '/routes/instructor/student.php');
t_ok(str_contains($VR_route, 'mam_vetting_may_see_child'), 'the student drill-down asks the gate too');

mam_setting_set('safeguarding_gate_mode', $VR_was);
unset($GLOBALS['__mam_vetting_nomemo']);
foreach (['DELETE FROM class_sessions WHERE id = ?' => [$VR_sess], 'DELETE FROM course_enrollments WHERE course_id = ?' => [$VR_course],
          'DELETE FROM cohorts WHERE course_id = ?' => [$VR_course], 'DELETE FROM courses WHERE id = ?' => [$VR_course]] as $VR_sql => $VR_args) {
    mam_db()->prepare($VR_sql)->execute($VR_args);
}
t_eq(mam_vetting_mode(), $VR_was, 'the gate mode is put back as it was');
