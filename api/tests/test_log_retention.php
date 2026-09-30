<?php
/**
 * The application log: whose IP it records, and how long it is kept.
 *
 * Both were real defects. The logger recorded the CDN rather than the caller,
 * and nothing had ever deleted a log file — so the one store of personal data
 * that lived on the filesystem sat outside the retention layer built to bound
 * exactly that.
 */
declare(strict_types=1);

t_group('application log — the IP is the caller, not the proxy in front of them');

/* ⚠️ MEASURED before the fix: with REMOTE_ADDR 172.68.10.5 (a Cloudflare edge)
   and CF-Connecting-IP 102.89.44.17 (the visitor), mam_log() wrote 172.68.10.5
   while mam_audit(), from the same request, wrote 102.89.44.17. Every line in
   the file named the CDN, which is the one field anybody reaches for during an
   incident. The two writers now agree because both go through the resolver. */
$LR_src = (string) file_get_contents(dirname(__DIR__) . '/lib/log.php');
$LR_body = preg_replace('~/\*.*?\*/~s', '', $LR_src);   // strip the comment that
$LR_body = preg_replace('~^\s*//.*$~m', '', (string) $LR_body); // explains the trap
t_ok(!preg_match("/'ip'\s*=>\s*\\\$_SERVER\['REMOTE_ADDR'\]/", (string) $LR_body),
    'mam_log() does not take the ip straight from REMOTE_ADDR');
t_ok(str_contains((string) $LR_body, 'mam_client_ip'),
    '…it resolves the caller with mam_client_ip(), the way mam_audit() beside it always did');
/* card.php and join.php load log.php WITHOUT bootstrap and declare their own
   mam_client_ip (gotcha #4). A future root page that forgets must degrade to
   REMOTE_ADDR, not fatal on an undefined function. */
t_ok(str_contains((string) $LR_body, "function_exists('mam_client_ip')"),
    '…guarded, because two root pages load this file without bootstrap');

/* ⚠️ There is no end-to-end assertion here, and that is not an oversight:
   `api/tests/run.php` stubs `mam_log(...$a) {}` as a no-op (gotcha #1 — the
   harness does not load bootstrap and replaces several functions), so the
   real writer never executes inside the suite and a test that called it would
   pass against the stub while proving nothing. The behaviour was instead
   MEASURED outside the harness, against a simulated Cloudflare request, and
   the numbers are recorded in the comment above. What the suite can hold is
   the shape of the source, which is what these three assertions do. */
@mkdir(MAM_LOGS, 0750, true);

t_group('application log — the retention sweep');

/* Deliberately NOT an entry in mam_dp_retention(). That registry is asserted
   table-by-table against the live schema (test_dataprotection.php), so a
   file-based rule would either fail it or force it to be weakened — and that
   test is the thing keeping the register honest. */
$LR_reg = mam_dp_retention();
$LR_fileRules = array_filter($LR_reg, static fn(array $r): bool =>
    str_contains(strtolower((string) ($r['table'] ?? '')), 'log') && str_contains((string) $r['table'], '/'));
t_eq($LR_fileRules, [], 'no file-based rule was smuggled into the table registry');
t_ok(function_exists('mam_dp_prune_app_logs'), 'the log files have a sweep of their own');

$LR_mkday = static function (string $day, int $mtime = 0): string {
    $p = MAM_LOGS . '/app-' . $day . '.log';
    file_put_contents($p, "{\"ts\":\"$day\"}\n");
    if ($mtime) @touch($p, $mtime);
    return $p;
};
$LR_clean = static function (): void {
    foreach (glob(MAM_LOGS . '/app-*.log') ?: [] as $f) @unlink($f);
    foreach (glob(MAM_LOGS . '/keep-*') ?: [] as $f) @unlink($f);
};

// ── Only the exact pattern, and never today's file ────────────────────────
$LR_clean();
$old   = $LR_mkday(gmdate('Y-m-d', time() - 400 * 86400));
$today = $LR_mkday(gmdate('Y-m-d'));
$other = MAM_LOGS . '/keep-me.txt';   file_put_contents($other, 'not a log');
$capture = MAM_LOGS . '/app-incident.log'; file_put_contents($capture, 'a capture someone is working on');

mam_setting_set('dp_retention_mode', 'enforce');
mam_setting_set('dp_log_retention_days', 90);
$r = mam_dp_prune_app_logs();
t_eq($r['mode'], 'enforce', 'the sweep reports the mode it ran under');
t_ok(!is_file($old), 'a file older than the period is deleted');
t_ok(is_file($today), 'today\'s file is NEVER deleted — it is the one being written');
t_ok(is_file($other), 'a non-log file in the directory is left alone');
t_ok(is_file($capture), 'app-<not-a-date>.log is left alone — only the exact pattern is swept');

/* ⚠️ The day comes from the FILENAME, not mtime. A restore from backup, an
   rsync or a plain copy rewrites every mtime, which would make a whole history
   of logs look like it was written today and keep them all, silently, for
   ever. Pinned by giving an old-named file a brand new mtime. */
$LR_clean();
$restored = $LR_mkday(gmdate('Y-m-d', time() - 400 * 86400), time());
t_ok((int) filemtime($restored) >= time() - 5, 'fixture really does have a fresh mtime');
mam_dp_prune_app_logs();
t_ok(!is_file($restored),
    'an old log with a fresh mtime is still swept — the filename is the authority, not the clock');

// ── report counts, enforce deletes, and a dry run overrides enforce ───────
$LR_clean();
$LR_mkday(gmdate('Y-m-d', time() - 400 * 86400));
mam_setting_set('dp_retention_mode', 'report');
$rep = mam_dp_prune_app_logs();
t_eq($rep['deleted'], 0, 'report mode deletes nothing');
t_eq($rep['overdue'], 1, '…but still counts what is overdue, so the gap is visible before enforcement');

mam_setting_set('dp_retention_mode', 'enforce');
$dry = mam_dp_prune_app_logs(true);
t_eq($dry['deleted'], 0, 'an explicit dry run deletes nothing even under enforce');
t_eq($dry['overdue'], 1, '…and still reports what would go');
t_ok($dry['applied'] === false, '…and says so, so a preview button cannot be made destructive by a setting');

$en = mam_dp_prune_app_logs();
t_eq($en['deleted'], 1, 'enforce deletes');

// ── off ───────────────────────────────────────────────────────────────────
$LR_clean();
$LR_mkday(gmdate('Y-m-d', time() - 400 * 86400));
mam_setting_set('dp_retention_mode', 'off');
$off = mam_dp_prune_app_logs();
t_eq($off['deleted'], 0, 'off deletes nothing');
t_ok(!empty($off['skipped']), '…and says it skipped rather than reporting a clean sweep');

/* A mistyped 0 in the admin form must not mean "delete everything including
   today". The reader floors it back to the shipped default. */
mam_setting_set('dp_log_retention_days', 0);
t_eq(mam_dp_log_retention_days(), 90, 'a zero retention period falls back to the default, it does not mean "keep nothing"');
mam_setting_set('dp_log_retention_days', -5);
t_eq(mam_dp_log_retention_days(), 90, 'nor does a negative one');
mam_setting_set('dp_log_retention_days', 30);
t_eq(mam_dp_log_retention_days(), 30, 'a real value is honoured');

// ── the cron runs it ──────────────────────────────────────────────────────
$LR_jobs = (string) file_get_contents(dirname(dirname(__DIR__)) . '/api/tools/run-jobs.php');
/* ⚠️ Match the CALL, not the name. `str_contains($jobs, 'mam_dp_prune_app_logs')`
   passes against `mam_dp_prune_app_logs_DISABLED` — the substring is still
   there — so renaming the call to switch it off left this assertion green.
   Caught by disabling it on purpose and watching nothing fail. */
t_ok(preg_match('/\bmam_dp_prune_app_logs\s*\(/', $LR_jobs) === 1,
    'the job runner CALLS the log sweep — a retention rule nothing executes is a promise, not a control');

// ── and the schedule on the admin screen accounts for them ────────────────
$LR_screen = (string) file_get_contents(dirname(dirname(__DIR__)) . '/partials/admin-console/dataprotection.php');
t_ok(str_contains($LR_screen, 'api/logs/app-*.log'),
    'the retention schedule lists the log files, so the register accounts for every store and not only the rows');

$LR_clean();
mam_setting_set('dp_retention_mode', 'report');
mam_setting_set('dp_log_retention_days', 90);
