<?php
/**
 * Backups — the freshness check, the prune, and the guards on both.
 *
 * `export.php` already wrote a verifiable snapshot before any of this existed.
 * What did not exist was anything that noticed a backup had stopped, anything
 * that deleted an old one, and any archive that held the uploaded files.
 */
declare(strict_types=1);

t_group('backups — a snapshot is dated by its NAME, never by mtime');

$BK_root = mam_backup_dir();
$BK_make = static function (string $stamp, array $manifest = null, int $mtime = 0) use ($BK_root): string {
    $d = $BK_root . '/' . $stamp;
    @mkdir($d, 0775, true);
    file_put_contents($d . '/users.json', '[]');
    if ($manifest !== null) file_put_contents($d . '/manifest.json', json_encode($manifest));
    if ($mtime) @touch($d, $mtime);
    return $d;
};
$BK_manifest = static function (bool $uploads = true, string $fp = null): array {
    $m = ['format_version' => 1, 'tables' => ['users' => ['rows' => 0]], 'total_rows' => 7,
          'signing_key_fingerprint' => $fp ?? mam_backup_key_fingerprint()];
    $m['uploads'] = $uploads
        ? ['files' => 3, 'bytes' => 100, 'sha256' => ['a.jpg' => 'aa', 'b.jpg' => 'bb', 'c.jpg' => 'cc'], 'skipped' => []]
        : ['captured' => false];
    return $m;
};
$BK_clean = static function () use ($BK_root): void {
    foreach ((array) @scandir($BK_root) as $n) {
        if ($n === '.' || $n === '..') continue;
        mam_backup_rmdir($BK_root . '/' . $n);
        @rmdir($BK_root . '/' . $n);
    }
};
@mkdir($BK_root, 0775, true);
$BK_clean();

/* ⚠️ A restore, an rsync or a plain copy rewrites every mtime. Dating a
   snapshot by mtime would make a year of old archives all look like they were
   taken today — reporting a backup job that died last spring as healthy, which
   is the single failure this whole check exists to catch. */
$BK_old = gmdate('Ymd-His', time() - 240 * 3600);
$BK_make($BK_old, $BK_manifest(), time());          // old NAME, brand-new mtime
t_ok((int) filemtime($BK_root . '/' . $BK_old) >= time() - 5, 'fixture really does have a fresh mtime');
$list = mam_backup_list();
t_eq(count($list), 1, 'the snapshot is listed');
t_ok($list[0]['age_hours'] >= 239,
    'it reads as ~240 hours old from its NAME, not as new from its mtime (got ' . (int) $list[0]['age_hours'] . 'h)');
t_eq(mam_backup_status()['state'], 'stale', '…so the status reports it stale');

t_group('backups — a half-written snapshot is not a backup');

/* An export interrupted before the manifest landed leaves a directory full of
   table files. Counting it would be the worst possible failure here: it is the
   one broken state that otherwise looks exactly like success. */
$BK_clean();
$BK_make(gmdate('Ymd-His'), null);                   // no manifest
t_eq(count(mam_backup_list()), 1, 'the directory is still listed, so it can be reported');
t_ok(!mam_backup_list()[0]['complete'], '…but it is not complete');
t_eq(mam_backup_status()['state'], 'none',
    'and the status says there is NO backup rather than counting a half-written one');
t_eq(mam_backup_status()['incomplete'], 1, '…while still reporting that something is there');

t_group('backups — what the status refuses to call healthy');

$BK_clean();
$BK_make(gmdate('Ymd-His'), $BK_manifest(true));
$st = mam_backup_status();
t_eq($st['state'], 'ok', 'a fresh snapshot with files and a matching key is ok');
t_ok($st['key_matches'] === true, '…and the key is confirmed, not assumed');

/* A snapshot with no uploaded files is NOT "ok with a note". A restore from it
   comes back with every headshot and every recording of a child missing, and
   none of those can be re-derived from any table. */
$BK_clean();
$BK_make(gmdate('Ymd-His'), $BK_manifest(false));
t_eq(mam_backup_status()['state'], 'partial', 'a snapshot with no uploaded files is not healthy');
t_ok(str_contains(mam_backup_status()['message'], 'uploaded files'), '…and says so in words');

/* The key is what makes a printed ID card verify. Restoring data taken under a
   different one leaves every card failing at a door with nothing to explain it. */
$BK_clean();
$BK_make(gmdate('Ymd-His'), $BK_manifest(true, 'deadbeefdeadbeef'));
t_eq(mam_backup_status()['state'], 'partial', 'a snapshot taken under a different signing key is not healthy');
t_ok(mam_backup_status()['key_matches'] === false, '…and the mismatch is reported, not guessed');

$BK_clean();
t_eq(mam_backup_status()['state'], 'none', 'with nothing on disk the state is none, not ok');

t_group('backups — the fingerprint identifies the key without carrying it');

$BK_fp = mam_backup_key_fingerprint();
t_ok($BK_fp !== '' && $BK_fp !== mam_app_key(), 'the fingerprint is NOT the key');
t_ok(!str_contains(mam_app_key(), $BK_fp) && !str_contains($BK_fp, mam_app_key()),
    '…and neither contains the other, so an archive manifest leaks nothing');
t_eq($BK_fp, mam_backup_key_fingerprint(), 'it is stable for one key');

/* ⚠️ export.php READS this value and never derives it. Deriving it would mean
   a second copy of mam_app_key()'s fallback chain inside a file that loads no
   application code — and a second copy of that exact resolution is what had ID
   cards signed with a constant printed in the repository. */
$BK_exp = (string) file_get_contents(dirname(dirname(__DIR__)) . '/api/tools/export.php');
$BK_expBody = (string) preg_replace('~/\*.*?\*/~s', '', $BK_exp);
$BK_expBody = (string) preg_replace('~^\s*(//|\s\*).*$~m', '', $BK_expBody);
t_ok(!str_contains($BK_expBody, 'mam-backup-keyfp-v1'),
    'export.php does not recompute the fingerprint — it reads the one the resolver published');
t_ok(str_contains($BK_expBody, '.app_key.fingerprint'), '…from the published file');
/* And the key itself must never reach an archive. */
t_ok(!preg_match('/[\'"]app_key[\'"]\s*=>/i', $BK_expBody) && !str_contains($BK_expBody, "manifest['signing_key']"),
    'no archive manifest carries the signing key itself');

t_group('backups — pruning, and the one snapshot it may never take');

$BK_clean();
foreach ([400, 300, 200, 2] as $daysAgo) {
    $BK_make(gmdate('Ymd-His', time() - $daysAgo * 86400), $BK_manifest());
}
mam_setting_set('dp_retention_mode', 'report');
mam_setting_set('backup_keep_days', 30);
$p = mam_backup_prune();
t_eq($p['deleted'], 0, 'report mode deletes nothing');
t_eq($p['overdue'], 3, '…but counts what is overdue, so the gap is visible before enforcement');

mam_setting_set('dp_retention_mode', 'enforce');
$dry = mam_backup_prune(true);
t_eq($dry['deleted'], 0, 'an explicit dry run deletes nothing even under enforce');
t_ok($dry['applied'] === false, '…and says so, so a preview cannot be made destructive by a setting');

$en = mam_backup_prune();
t_eq($en['deleted'], 3, 'enforce deletes the overdue snapshots');
t_eq(count(mam_backup_list()), 1, '…leaving the one inside the period');

/* ⚠️ The invariant that matters most. A retention rule which can leave an
   academy with NO backup has done more harm than the retention it enforced. */
$BK_clean();
foreach ([400, 300, 200] as $daysAgo) {
    $BK_make(gmdate('Ymd-His', time() - $daysAgo * 86400), $BK_manifest());
}
$all = mam_backup_prune();
t_eq($all['deleted'], 2, 'with every snapshot past the period, only the older ones go');
t_eq(count(mam_backup_list()), 1, 'the newest complete snapshot is NEVER deleted, whatever its age');

mam_setting_set('dp_retention_mode', 'off');
$BK_clean();
$BK_make(gmdate('Ymd-His', time() - 400 * 86400), $BK_manifest());
$off = mam_backup_prune();
t_eq($off['deleted'], 0, 'off deletes nothing');
t_ok(!empty($off['skipped']), '…and says it skipped rather than reporting a clean sweep');
mam_setting_set('dp_retention_mode', 'report');

t_group('backups — the delete guard');

/* mam_backup_rmdir() removes recursively, so a caller that could talk it into
   another path would be a way to delete arbitrary directories. The guard is
   the point of the function, not defensiveness around it. */
foreach ([
    '/etc'                              => 'a system directory',
    MAM_STORAGE                         => 'the storage root itself',
    MAM_STORAGE . '/export/../../logs'  => 'a traversal out of the export root',
    MAM_STORAGE . '/export/not-a-stamp' => 'a directory that is not a YYYYmmdd-HHMMSS snapshot',
] as $BK_path => $BK_what) {
    t_ok(!mam_backup_rmdir($BK_path), "rmdir refuses $BK_what");
}

/* ⚠️ The case the OTHER half of the guard exists for, and the one the four
   above never reach: a directory whose name IS a valid stamp but which lives
   somewhere else entirely. Every path above is refused by the filename
   pattern alone, so deleting the `dirname($real) !== $root` check broke no
   test — caught by removing it and watching nothing fail. This is the
   assertion that holds it: without that check, mam_backup_rmdir() would
   recursively delete any stamp-named directory anywhere on the disk. */
$BK_outside = MAM_STORAGE . '/not-the-export-root/' . gmdate('Ymd-His');
@mkdir($BK_outside, 0775, true);
file_put_contents($BK_outside . '/precious.json', 'not a backup');
t_ok(!mam_backup_rmdir($BK_outside),
    'rmdir refuses a correctly-STAMPED directory that is not inside the export root');
t_ok(is_file($BK_outside . '/precious.json'), '…and its contents are untouched');
@unlink($BK_outside . '/precious.json');
@rmdir($BK_outside);
@rmdir(dirname($BK_outside));

t_ok(is_dir(MAM_STORAGE), 'the storage root still exists after all of that');

t_group('backups — the operator is actually told');

$BK_jobs = (string) file_get_contents(dirname(dirname(__DIR__)) . '/api/tools/run-jobs.php');
/* Match the CALL, not the name: str_contains($jobs, 'mam_backup_prune') is
   still true of mam_backup_prune_DISABLED. */
t_ok(preg_match('/\bmam_backup_prune\s*\(/', $BK_jobs) === 1, 'the job runner prunes snapshots');
t_ok(preg_match('/\bmam_backup_status\s*\(/', $BK_jobs) === 1,
    '…and checks whether a backup is still happening — the failure is a job that silently stopped');
t_ok(preg_match('/\bmam_backup_write_fingerprint\s*\(/', $BK_jobs) === 1,
    '…and publishes the fingerprint the exporter stamps into each manifest');

$BK_dep = (string) file_get_contents(dirname(dirname(__DIR__)) . '/DEPLOYMENT.md');
t_ok(str_contains($BK_dep, 'run-jobs.php'),
    'the runbook tells an operator to schedule the job runner — without it NO sweep ever runs');
t_ok(str_contains($BK_dep, '--with-uploads'),
    '…and that the snapshot needs --with-uploads, or it holds no headshots at all');
t_ok(preg_match('/##\s*8\s*·\s*Backup/u', $BK_dep) === 1,
    '…and carries a backup and restore section, which a "Recovery Runbook" had never had');
t_ok(str_contains($BK_dep, 'VACUUM INTO'),
    '…with a SQLite backup that needs no sqlite3 binary, since shared hosting often lacks one');
t_ok(str_contains($BK_dep, 'single-transaction'),
    '…and a MySQL dump that does not lock the tables while the site is up');

$BK_clean();
mam_setting_set('backup_keep_days', 30);
