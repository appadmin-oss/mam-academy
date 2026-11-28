<?php
/**
 * Access hardening (2026-11-28 security review). Each group pins one verified
 * finding so it cannot quietly come back:
 *   1. admin/index.php refuses an action its capability map does not name
 *      (deny by default), and saying money arrived needs `fees`;
 *   2. a class's meeting link and a student's ID card are open to the class's
 *      own teachers, not to every instructor;
 *   3. the break-glass Basic-Auth credential is throttled on failures;
 *   4. the admin CSV exports are capability-gated and formula-safe;
 *   5. the create-user route reports whether the invite was really sent.
 */
declare(strict_types=1);

$AH_root  = dirname(MAM_API_ROOT);
$AH_admin = (string) file_get_contents($AH_root . '/admin/index.php');

t_group('access hardening — admin actions are deny-by-default');

preg_match('/\$actionCaps = \[(.*?)\n    \];/s', $AH_admin, $AH_m);
preg_match_all("/'([a-z_]+)'\s*=>/", $AH_m[1] ?? '', $AH_k);
$AH_mapped = array_flip($AH_k[1]);
preg_match("/\\\$selfActions = \[([^\]]*)\]/", $AH_admin, $AH_s);
preg_match_all("/'([a-z_]+)'/", $AH_s[1] ?? '', $AH_self);
foreach ($AH_self[1] as $AH_a) $AH_mapped[$AH_a] = true;
preg_match_all("/\\\$action === '([a-z_]+)'|case '([a-z_]+)'/", $AH_admin, $AH_h);
$AH_handlers = array_unique(array_filter(array_merge($AH_h[1], $AH_h[2])));
$AH_unnamed = array_values(array_filter($AH_handlers, fn($a) => !isset($AH_mapped[$a])));
t_ok(count($AH_handlers) > 100, 'the handler scan sees the admin actions [' . count($AH_handlers) . ']');
t_eq($AH_unnamed, [], 'every admin handler is named in $actionCaps or $selfActions — an unnamed one is now refused, so it would be dead');
t_ok(str_contains($AH_admin, "!isset(\$actionCaps[\$action]) && !in_array(\$action, \$selfActions, true)"),
    'an action the map does not name is refused (deny by default)');
t_ok((bool) preg_match("/in_array\(\\\$postedStatus, \['paid', 'enrolled'\], true\)\) \\\$requiredCap = 'fees'/", $AH_admin),
    "marking an application paid or enrolled needs the `fees` capability, not `admissions`");
t_eq(mam_admin_capabilities()['fees'] ?? null, 'super', 'fees stays super-only, so a manager cannot mark an application paid');

t_group('access hardening — a class and a card are open to their own teachers');

$AH_join = (string) file_get_contents($AH_root . '/join.php');
t_ok(!str_contains($AH_join, '$isStaff'), 'join.php no longer treats every instructor as staff');
t_ok(str_contains($AH_join, '$teaches(') && str_contains($AH_join, 'substitute_id'),
    'join.php admits an instructor only for a class they lead, cover or are named on');
$AH_card = (string) file_get_contents($AH_root . '/card.php');
t_ok(str_contains($AH_card, '$isInstructor && $teachesHolder('),
    "card.php shows a student's card to an instructor only if they teach that student");

t_group('access hardening — break-glass failures are throttled');

$AH_auth = (string) file_get_contents(MAM_API_ROOT . '/modules/identity/lib/auth.php');
t_ok(str_contains($AH_auth, "mam_rate_limit_check('breakglass_ip'") && str_contains($AH_auth, "mam_rate_limit_check('breakglass_all'"),
    'the break-glass credential is checked against a per-IP and an all-IP failure budget');
t_ok(str_contains($AH_auth, "mam_rate_limit_record('breakglass_ip'") && str_contains($AH_auth, '!$basicOk'),
    'only a FAILED break-glass attempt is charged (a success never is)');

// Behaviour: ten wrong passwords exhaust the per-IP budget; the eleventh is a 429.
mam_test_env(['ADMIN_USER' => 'ops-test', 'ADMIN_PASSWORD_HASH' => password_hash('right-pass', PASSWORD_BCRYPT, ['cost' => 4])]);
try { mam_db()->exec("DELETE FROM rate_limits WHERE bucket LIKE 'breakglass%'"); } catch (Throwable $e) {}
$AH_codes = [];
for ($i = 0; $i < 11; $i++) {
    $_SERVER['PHP_AUTH_USER'] = 'ops-test'; $_SERVER['PHP_AUTH_PW'] = 'wrong-' . $i;
    try { mam_require_admin(); $AH_codes[] = 'allowed'; }
    catch (Throwable $e) { $AH_codes[] = str_contains($e->getMessage(), 'rate_limited') ? '429' : 'denied'; }
}
t_eq(array_count_values($AH_codes)['denied'] ?? 0, 10, 'ten wrong break-glass passwords are each refused');
t_eq(end($AH_codes), '429', 'the eleventh attempt from the same address is rate-limited');
$_SERVER['PHP_AUTH_USER'] = 'ops-test'; $_SERVER['PHP_AUTH_PW'] = 'right-pass';
$AH_after = 'allowed';
try { mam_require_admin(); } catch (Throwable $e) { $AH_after = str_contains($e->getMessage(), 'rate_limited') ? '429' : 'denied'; }
t_eq($AH_after, '429', 'once the budget is spent even the right password waits — a guesser cannot tell when they hit it');
unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
try { mam_db()->exec("DELETE FROM rate_limits WHERE bucket LIKE 'breakglass%'"); } catch (Throwable $e) {}
mam_test_env(['ADMIN_USER' => '', 'ADMIN_PASSWORD_HASH' => '']);

t_group('access hardening — CSV exports');

t_ok(str_contains($AH_admin, "'payments' => 'fees'") && str_contains($AH_admin, "'users' => 'users'"),
    'each CSV export names the capability that owns its contents');
t_ok(str_contains($AH_admin, "!mam_admin_can(\$adminUser, \$exportCap)"), 'the export refuses an admin without that capability');
t_ok(!preg_match('/fputcsv\(\$out, \[\x27reference\x27, \x27name\x27/', $AH_admin), 'export rows go through the formula-safe writer, not bare fputcsv');
t_ok(function_exists('mam_admin_csv_row') || str_contains($AH_admin, 'function mam_admin_csv_row('), 'the formula-safe CSV writer exists');

t_group('access hardening — create-user reports the real invite result');

$AH_cu = (string) file_get_contents(MAM_API_ROOT . '/routes/admin/create-user.php');
t_ok(!str_contains($AH_cu, '(bool) mam_auth_send_invite('), 'the invite result is not cast to bool (an array is always true)');
t_ok(str_contains($AH_cu, "\$invite['sent']"), "create-user reports the invite's own `sent` flag");

t_group('access hardening — a guardian sees what their scope grants');

$AH_kid = (int) mam_db_insert('users', ['name' => 'Scope Kid', 'email' => 'scopekid.ah@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$AH_bill = (int) mam_parent_link('billingonly.ah@test.local', $AH_kid)['parent_id'];
$AH_std  = (int) mam_parent_link('standard.ah@test.local', $AH_kid)['parent_id'];
mam_parent_scope_set($AH_bill, $AH_kid, 'billing', null);

$AH_b = mam_parent_child_detail($AH_bill, $AH_kid);
t_eq($AH_b['scope'], 'billing', 'the detail says which scope it was cut to');
foreach (['results', 'attendance', 'instructor_feedback', 'academic', 'consent', 'weekly'] as $AH_key) {
    t_ok(in_array($AH_key, $AH_b['withheld'], true) && empty($AH_b[$AH_key]), "a billing-only guardian is NOT sent `$AH_key`");
}
t_ok(!in_array('announcements', $AH_b['withheld'], true), 'billing scope still receives announcements, which it grants');

$AH_s = mam_parent_child_detail($AH_std, $AH_kid);
foreach (['results', 'attendance', 'academic', 'consent'] as $AH_key) {
    t_ok(!in_array($AH_key, $AH_s['withheld'], true), "a standard guardian still receives `$AH_key`");
}

$AH_take = (string) file_get_contents(MAM_API_ROOT . '/routes/media-take.php');
t_ok(!str_contains($AH_take, 'mam_parent_can_view($uid'), "a recorded take has no guardian path — 'takes' is in MAM_PARENT_NEVER");
t_ok(in_array('takes', MAM_PARENT_NEVER, true), "'takes' is still a refusal for every scope");
$AH_cons = (string) file_get_contents(MAM_API_ROOT . '/routes/parent/consent.php');
t_ok(str_contains($AH_cons, "mam_parent_scope_allows((int) \$u['id'], \$sid, 'consents')"), "changing consent needs the Full scope's 'consents' grant");
t_ok(!mam_parent_scope_allows($AH_std, $AH_kid, 'consents') && !mam_parent_scope_allows($AH_bill, $AH_kid, 'consents'),
    'neither a standard nor a billing guardian holds the consents grant');

t_group('learning — a teach-back is scored on its own 0–5 scale');

if (mam_db_driver() === 'sqlite') {
    $AH_tbKid = (int) mam_db_insert('users', ['name' => 'Teachback Kid', 'email' => 'tbkid.ah@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
    $AH_tbRow = ['student_id' => $AH_tbKid, 'lesson_id' => 987654, 'status' => 'reviewed'];
    foreach (['understanding', 'accuracy', 'vocabulary', 'explanation', 'application', 'confidence'] as $AH_d) $AH_tbRow[$AH_d] = 5;
    // Only the scale arithmetic is under test, so the row needs no real lesson.
    mam_db()->exec('PRAGMA foreign_keys = OFF');
    try { mam_db_insert('teachbacks', $AH_tbRow); } finally { mam_db()->exec('PRAGMA foreign_keys = ON'); }
    $AH_comp = mam_mastery_components($AH_tbKid);
    $AH_tb = $AH_comp['teachback'] ?? null;
    if ($AH_tb === null) foreach ($AH_comp as $AH_c) if (($AH_c['key'] ?? '') === 'teachback') $AH_tb = $AH_c;
    t_ok($AH_tb !== null, 'a reviewed teach-back yields a teach-back mastery component');
    $AH_pct = $AH_tb['pct'] ?? (isset($AH_tb['max']) && $AH_tb['max'] > 0 ? $AH_tb['earned'] / $AH_tb['max'] * 100 : null);
    t_ok($AH_pct !== null && (float) $AH_pct >= 99.0, 'six dimensions at 5/5 read as 100%, not 5% [' . var_export($AH_pct, true) . ']');
    mam_db()->exec('DELETE FROM teachbacks WHERE student_id = ' . $AH_tbKid);
}

t_group('learning — no rank against classmates reaches a student or guardian');

foreach (['/modules/learning/lib/progress.php', '/routes/student/grades.php'] as $AH_f) {
    $AH_src = (string) file_get_contents(MAM_API_ROOT . $AH_f);
    t_ok(!str_contains($AH_src, 'mam_lms2_cohort_percentile('), "$AH_f no longer computes a cohort rank or percentile");
}
$AH_rk = (int) mam_db_insert('users', ['name' => 'Rank Kid', 'email' => 'rankkid.ah@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$AH_pr = function_exists('mam_progress_report_data') ? mam_progress_report_data($AH_rk) : [];
t_eq($AH_pr['academic']['percentiles'] ?? [], [], 'the progress summary (student, guardian, printed report) carries no percentiles');
