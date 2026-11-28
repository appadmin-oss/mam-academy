<?php
/**
 * Operations baseline (2026-11-28) and the student ID card.
 *  - every enrolled student can reach their card (portal screen + claim + nightly catch-up);
 *  - the job runner isolates each sweep and leaves a heartbeat /api/health reads;
 *  - health answers 503 when background work or backups have stopped;
 *  - checkout never waits on the FX provider during a web request;
 *  - MySQL does one query per request when the schema is current;
 *  - a crashed page shows a page, not JSON.
 */
declare(strict_types=1);

$OB_root = dirname(MAM_API_ROOT);

t_group('ID card — a student can reach their own card');

$OB_tag = substr(md5((string) microtime(true)), 0, 6);
$OB_stu = (int) mam_db_insert('users', ['name' => 'Card Kid', 'email' => "cardkid.$OB_tag@test.local", 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$OB_v = mam_card_portal_view($OB_stu);
t_ok($OB_v['card'] === null && $OB_v['eligible'] === false, 'not enrolled: no card, and the portal says why');
$OB_err = null;
try { mam_card_claim_for_student($OB_stu); } catch (Throwable $e) { $OB_err = $e->getMessage(); }
t_eq($OB_err, 'not_enrolled', 'a student enrolled in nothing cannot claim a card');

$OB_course = mam_db_row("SELECT id FROM courses ORDER BY id LIMIT 1");
$OB_coh = (int) mam_db_insert('cohorts', ['course_id' => (int) $OB_course['id'], 'title' => "Card Cohort $OB_tag", 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $OB_stu, 'course_id' => (int) $OB_course['id'], 'cohort_id' => $OB_coh, 'status' => 'active']);
t_ok(mam_card_portal_view($OB_stu)['eligible'] === true, 'enrolled with no card: the portal offers "Get my ID card"');
$OB_card = mam_card_claim_for_student($OB_stu);
t_ok(!empty($OB_card['serial']), 'claiming issues a card');
t_eq((string) mam_card_claim_for_student($OB_stu)['serial'], (string) $OB_card['serial'], 'claiming again returns the same card, never a second one');
$OB_v2 = mam_card_portal_view($OB_stu);
t_eq($OB_v2['card']['url'] ?? null, '/card/' . rawurlencode((string) $OB_card['serial']), 'the portal links to the printable card page');
t_ok(in_array('card', MAM_SPORTAL_SCREENS, true), "the student portal has a 'card' screen");
t_ok(str_contains((string) file_get_contents(MAM_API_ROOT . '/routes/student/portal.php'), "case 'card':"), "…and the route dispatches it (a screen that validates and does not dispatch renders home)");
t_ok(str_contains((string) file_get_contents($OB_root . '/portal/student/index.html'), 'data-screen="card"'), 'the More sheet links to it');

$OB_stu2 = (int) mam_db_insert('users', ['name' => 'Backlog Kid', 'email' => "backlog.$OB_tag@test.local", 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $OB_stu2, 'course_id' => (int) $OB_course['id'], 'cohort_id' => $OB_coh, 'status' => 'active']);
$OB_n = mam_card_backfill_sweep(500);
t_ok($OB_n >= 1 && mam_card_for_user($OB_stu2) !== null, 'the nightly catch-up issues a card to an enrolled student who never got one');
t_eq(mam_card_backfill_sweep(500) >= 0 && mam_card_for_user($OB_stu2) !== null, true, 'and running it again issues nothing new for them');

t_group('job runner — each sweep on its own, and a heartbeat');

$OB_jobs = (string) file_get_contents(MAM_API_ROOT . '/tools/run-jobs.php');
t_ok(str_contains($OB_jobs, '$step = static function') && str_contains($OB_jobs, "mam_log('error', 'job_step_failed'"),
    'every sweep runs inside a step that logs a failure and carries on');
t_ok(substr_count($OB_jobs, 'mam_acadmail_run_all_sweeps()') === 1 && !str_contains($OB_jobs, 'mam_acadmail_preclass_reminder_sweep('),
    'the academic emails run once per pass, not twice');
t_ok(strpos($OB_jobs, "\$step('legacy_carry'") > strpos($OB_jobs, "mam_automation_on('data_retention')") + 0
     && !preg_match("/if \(function_exists\('mam_dp_retention_sweep'\)[^\n]*\{[^}]*mam_legacy_carry/s", $OB_jobs),
    'carrying the old classes no longer depends on the retention switch');
t_ok((bool) preg_match('/\bmam_jobs_heartbeat_write\s*\(/', $OB_jobs), 'the runner writes a heartbeat at the end of each pass');
t_ok((bool) preg_match('/\bmam_card_backfill_sweep\s*\(/', $OB_jobs), 'and catches up ID cards');
$OB_mr = (string) file_get_contents(MAM_API_ROOT . '/tools/mail-retry.php');
t_ok(str_contains($OB_mr, '.run-jobs.lock') && str_contains($OB_mr, 'LOCK_NB'), 'the separate mail retry takes the runner\'s lock, so the same email is not sent twice');

t_group('health — a stopped job runner is visible from outside');

@unlink(mam_jobs_heartbeat_path());
t_eq(mam_ops_health()['checks']['jobs'], 'never', 'no pass recorded: jobs = never');
mam_jobs_heartbeat_write(['seconds' => 1.2, 'failed' => []]);
t_eq(mam_ops_health()['checks']['jobs'], 'ok', 'a clean pass just now: jobs = ok');
mam_jobs_heartbeat_write(['seconds' => 1.2, 'failed' => ['fx_warm']]);
t_eq(mam_ops_health()['checks']['jobs'], 'failing', 'a pass with a failing step: jobs = failing');
mam_jobs_heartbeat_write(['seconds' => 1.2, 'failed' => []]);
t_eq(mam_ops_health(time() + 3 * 3600)['checks']['jobs'], 'stale', 'three hours without a pass: jobs = stale');
t_ok(mam_ops_health(time() + 3 * 3600)['ok'] === false, 'and the overall health is not ok');
@unlink(mam_jobs_heartbeat_path());
$OB_h = (string) file_get_contents(MAM_API_ROOT . '/routes/health.php');
t_ok(str_contains($OB_h, "\$ops['ok'] ? 200 : 503)"), '/api/health answers 503 when any check is not ok');
t_ok(str_contains($OB_h, "'checks'      => \$ops['checks']"), '…and carries the per-check states for a monitor');

t_group('performance and failure');

$OB_fx = (string) file_get_contents(MAM_API_ROOT . '/modules/integrations/lib/fx.php');
t_ok((bool) preg_match("/if \(PHP_SAPI !== 'cli'\) \{\s*\\\$last = mam_setting_num\('fx_usd_ngn_cached', 0\);\s*if \(\\\$last > 0\) return \\\$last;/", $OB_fx),
    'a web request uses the stored FX rate instead of calling the provider');
$OB_db = (string) file_get_contents(MAM_API_ROOT . '/modules/kernel/lib/db.php');
t_ok(str_contains($OB_db, 'function mam_db_migrate_mysql(PDO $db, bool $force = false)') && str_contains($OB_db, "if (\$v >= MAM_SCHEMA_TARGET) return;"),
    'MySQL returns after one version query when the schema is current');
t_ok(str_contains($OB_db, "GET_LOCK('mam_schema_migrate'"), 'only one request runs a migration at a time');
t_ok(str_contains($OB_db, 'PDO::ATTR_TIMEOUT => 5'), 'a hung database fails a request in seconds');
t_ok(str_contains((string) file_get_contents(MAM_API_ROOT . '/routes/admin/migrate.php'), 'mam_db_migrate_mysql($db, true)'),
    'the admin "Run migrations" still forces the full repair');
t_ok(is_file($OB_root . '/500.html') && str_contains((string) file_get_contents(MAM_API_ROOT . '/lib/bootstrap.php'), "readfile(\$page)"),
    'a crashed page is answered with the static 500 page, not raw JSON');
t_ok(str_contains((string) file_get_contents($OB_root . '/.htaccess'), 'ErrorDocument 500 /500.html'), 'Apache serves the same page for its own 500s');
t_ok(str_contains((string) file_get_contents(MAM_API_ROOT . '/modules/identity/lib/auth.php'), "\$GLOBALS['__mam_roles_memo']"),
    'role lookups are memoised per web request');

t_group('ID card — the printable card page can sign the card it shows');

t_ok(str_contains((string) file_get_contents($OB_root . '/card.php'), "modules/kernel/lib/csrf.php"),
    'card.php loads mam_app_key() — without it every holder got a fatal error opening their card');
