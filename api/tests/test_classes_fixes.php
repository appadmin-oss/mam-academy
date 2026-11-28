<?php
/**
 * Classes audit (2026-11-28) — the first fixes, each reproduced before fixing.
 */
declare(strict_types=1);

t_group('classes — looking at an application never creates a class');

$CF_tag = substr(md5((string) microtime(true)), 0, 6);
$CF_course = mam_db_row("SELECT id, code FROM courses WHERE code NOT IN (SELECT DISTINCT c.code FROM courses c JOIN cohorts co ON co.course_id = c.id) ORDER BY id LIMIT 1")
    ?: mam_db_row("SELECT id, code FROM courses ORDER BY id DESC LIMIT 1");
$CF_cust = (int) mam_db_insert('customers', ['email' => "classfix.$CF_tag@test.local", 'first_name' => 'Class', 'last_name' => 'Fix']);
$CF_app = (int) mam_db_insert('applications', ['reference' => 'MAM-CF' . strtoupper($CF_tag), 'customer_id' => $CF_cust,
    'pathway' => 'crash', 'mode' => 'online', 'discipline' => (string) $CF_course['code'], 'status' => 'submitted', 'created_at' => date('Y-m-d H:i:s')]);
$CF_before = (int) mam_db_row('SELECT COUNT(*) AS n FROM cohorts')['n'];
$CF_plan = mam_enrol_resolve($CF_app);
$CF_plan2 = mam_enrol_resolve($CF_app);
t_eq((int) mam_db_row('SELECT COUNT(*) AS n FROM cohorts')['n'], $CF_before, 'resolving an application (what the console does on view) creates no cohort');
if (($CF_plan['cohort']['id'] ?? null) === null) {
    t_ok(!empty($CF_plan['cohort']['new']) && ($CF_plan['cohort']['title'] ?? '') !== '', 'when no class exists yet, the plan says one will be made, and names it');
}
t_ok(function_exists('mam_lms_find_cohort'), 'a pure cohort lookup exists beside the creating one');
$CF_src = (string) file_get_contents(MAM_API_ROOT . '/modules/admissions/lib/enrol-complete.php');
$CF_body = substr($CF_src, strpos($CF_src, 'function mam_enrol_resolve'));
$CF_body = substr($CF_body, 0, (int) strpos($CF_body, 'function mam_enrol_complete'));
$CF_code = '';
foreach (token_get_all('<?php ' . $CF_body) as $CF_t) {
    if (is_array($CF_t) && in_array($CF_t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
    $CF_code .= is_array($CF_t) ? $CF_t[1] : $CF_t;
}
t_ok(!str_contains($CF_code, 'mam_lms_ensure_cohort('), 'mam_enrol_resolve() no longer calls the creating mam_lms_ensure_cohort()');

t_group('classes — the seat count is a count');

$CF_coh = (int) mam_db_insert('cohorts', ['course_id' => (int) $CF_course['id'], 'title' => "Seat Test $CF_tag", 'status' => 'active', 'capacity' => 2]);
foreach ([1, 2] as $CF_i) {
    $CF_s = (int) mam_db_insert('users', ['name' => "Seat $CF_i", 'email' => "seat$CF_i.$CF_tag@test.local", 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
    mam_db_insert('course_enrollments', ['student_id' => $CF_s, 'course_id' => (int) $CF_course['id'], 'cohort_id' => $CF_coh, 'status' => 'active']);
}
t_ok(str_contains($CF_src, "mam_lms_cohort_fill_count(\$cohortId)['enrolled']"), 'the plan reads the enrolled count from the fill-count array (casting the array gave 1)');
t_eq((int) mam_lms_cohort_fill_count($CF_coh)['enrolled'], 2, 'two students in a class of two count as two');

t_group('classes — one stored format for a session time');

t_eq(mam_lms_norm_datetime('2026-11-28T08:07'), '2026-11-28 08:07:00', "the browser's datetime-local value is stored as 'Y-m-d H:i:s'");
t_eq(mam_lms_norm_datetime('2026-11-28 08:07:30'), '2026-11-28 08:07:30', 'an already-normal value is unchanged');
t_eq(mam_lms_norm_datetime(''), null, 'blank is null');
$CF_sess = mam_lms_create_session($CF_coh, ['scheduled_at' => '2026-12-01T09:30']);
t_eq((string) mam_db_row('SELECT scheduled_at FROM class_sessions WHERE id = ?', [$CF_sess])['scheduled_at'], '2026-12-01 09:30:00',
    'a session created from the instructor form is stored in the one format, so day queries and clash checks see it');

t_group('classes — the check-in message fits the class');

$CF_w = mam_checkin_window('2030-01-01 10:00:00', 60, strtotime('2030-01-01 09:00:00'), 'onsite');
t_ok(str_contains($CF_w['message'], 'in the room') && !str_contains($CF_w['message'], 'online'), 'an onsite class does not tell students to be online');
$CF_w2 = mam_checkin_window('2030-01-01 10:00:00', 60, strtotime('2030-01-01 09:00:00'), 'online');
t_ok(str_contains($CF_w2['message'], 'online'), 'an online class says online');
