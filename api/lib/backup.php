<?php
/**
 * Backups — scheduling them is the operator's job; NOTICING is this file's.
 *
 * `api/tools/export.php` already produced a lossless, checksummed, verifiable
 * snapshot long before this lib existed: it was written as the ETL rollback
 * artifact and it is good. What was missing was everything around it. Nothing
 * ran it, DEPLOYMENT.md never mentioned backup or restore, the uploaded files
 * were in no archive, and nothing ever deleted an old one.
 *
 * ── WHY THIS DOES NOT RUN THE EXPORT ITSELF ───────────────────────────────
 * The obvious design is a sweep here that shells out to export.php. It is the
 * wrong one twice over. Shared hosting — what this academy runs on — commonly
 * disables `exec`/`proc_open`, so the backup would simply never happen and the
 * failure would be invisible, which is the exact disease being treated. And
 * export.php is deliberately STANDALONE (it loads no application code, because
 * `mam_db()` runs the migrator and a pre-migration safety net must never
 * migrate), so pulling it into a bootstrapped process would undo the property
 * that makes it trustworthy.
 *
 * So the cron runs export.php directly — DEPLOYMENT.md carries the line — and
 * this file answers the question that actually prevents the classic failure:
 * **is a backup still happening?** A backup job that quietly stopped eleven
 * months ago looks exactly like one that is working, right up until somebody
 * needs it.
 */
declare(strict_types=1);

/** Where export.php writes, unless told otherwise. */
function mam_backup_dir(): string {
    return MAM_STORAGE . '/export';
}

/**
 * The signing key's FINGERPRINT — never the key.
 *
 * A restore needs the key: `mam_card_sign()` derives a printed ID card's HMAC
 * from it, so restoring data under a different key leaves every card failing
 * at a door with nothing on screen to explain why. The key must NOT travel in
 * the archive (that turns a data backup into a credential store), so what
 * travels instead is a value that answers "is the key I have the one this
 * data was signed with" and reveals nothing if the archive leaks.
 *
 * Written to a file rather than computed inside export.php on purpose:
 * export.php loads no application code, so deriving it there would mean a
 * SECOND copy of mam_app_key()'s fallback chain — and a second copy of that
 * resolution is precisely what had ID cards signed with a public constant.
 */
function mam_backup_key_fingerprint(): string {
    return substr(hash_hmac('sha256', 'mam-backup-keyfp-v1', mam_app_key()), 0, 16);
}

/** Publish the fingerprint where the standalone exporter can read it. */
function mam_backup_write_fingerprint(): bool {
    $path = MAM_STORAGE . '/.app_key.fingerprint';
    $fp   = mam_backup_key_fingerprint();
    if (is_readable($path) && trim((string) @file_get_contents($path)) === $fp) return true;
    if (!is_dir(MAM_STORAGE)) @mkdir(MAM_STORAGE, 0750, true);
    return @file_put_contents($path, $fp, LOCK_EX) !== false;
}

/** `YYYYmmdd-HHMMSS`, the directory name export.php stamps. */
const MAM_BACKUP_DIR_RE = '/^(\d{8})-(\d{6})$/';

/**
 * Every snapshot on disk, newest first.
 *
 * ⚠️ The time comes from the DIRECTORY NAME, not mtime — the same rule the log
 * sweep holds, and for the same reason: a restore, an rsync or a plain copy
 * rewrites every mtime, which would make a year of old archives look like they
 * were all taken today and report a dead backup job as healthy.
 */
function mam_backup_list(int $limit = 50): array {
    $root = mam_backup_dir();
    if (!is_dir($root)) return [];
    $out = [];
    foreach ((array) @scandir($root) as $name) {
        if (!preg_match(MAM_BACKUP_DIR_RE, (string) $name, $m)) continue;
        $path = $root . '/' . $name;
        if (!is_dir($path)) continue;

        $ts = strtotime($m[1] . 'T' . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2) . 'Z');
        $man = null;
        if (is_readable($path . '/manifest.json')) {
            $d = json_decode((string) @file_get_contents($path . '/manifest.json'), true);
            if (is_array($d)) $man = $d;
        }
        $up = is_array($man) ? ($man['uploads'] ?? null) : null;
        $out[] = [
            'name'        => (string) $name,
            'path'        => $path,
            'at'          => $ts ?: null,
            'age_hours'   => $ts ? (int) floor((time() - $ts) / 3600) : null,
            /* A directory with no manifest is a snapshot that DIED MID-WRITE.
               It must never read as a backup — that is the one failure that
               would otherwise look like success. */
            'complete'    => $man !== null,
            'tables'      => is_array($man) ? count((array) ($man['tables'] ?? [])) : 0,
            'rows'        => is_array($man) ? (int) ($man['total_rows'] ?? 0) : 0,
            'has_uploads' => is_array($up) && !empty($up['sha256']),
            'upload_files' => is_array($up) ? (int) ($up['files'] ?? 0) : 0,
            'key_fp'      => is_array($man) ? (string) ($man['signing_key_fingerprint'] ?? 'not supplied') : '',
        ];
    }
    usort($out, static fn(array $a, array $b): int => ($b['at'] ?? 0) <=> ($a['at'] ?? 0));
    return array_slice($out, 0, max(1, $limit));
}

/** How old the newest COMPLETE snapshot may be before the academy is warned. */
function mam_backup_max_age_hours(): int {
    $h = (int) mam_setting_or_default('backup_max_age_hours', 36);
    return $h >= 1 ? $h : 36;
}

/** How long snapshots are kept. They hold a full copy of everything. */
function mam_backup_keep_days(): int {
    $d = (int) mam_setting_or_default('backup_keep_days', 30);
    return $d >= 1 ? $d : 30;
}

/**
 * Is a backup still happening, and would it restore this install?
 *
 * @return array{state:string,message:string,latest:?array,count:int,
 *               age_hours:?int,key_matches:?bool,incomplete:int}
 */
function mam_backup_status(): array {
    $all    = mam_backup_list();
    $done   = array_values(array_filter($all, static fn(array $b): bool => $b['complete']));
    $latest = $done[0] ?? null;
    $maxAge = mam_backup_max_age_hours();
    $fp     = mam_backup_key_fingerprint();

    $out = [
        'state' => 'none', 'message' => '', 'latest' => $latest, 'count' => count($done),
        'age_hours' => $latest['age_hours'] ?? null, 'key_matches' => null,
        'incomplete' => count($all) - count($done),
        'max_age_hours' => $maxAge,
    ];

    if ($latest === null) {
        $out['state'] = 'none';
        $out['message'] = 'No snapshot has ever been taken. Nothing here could be restored.';
        return $out;
    }

    $known = $latest['key_fp'] !== '' && $latest['key_fp'] !== 'not supplied';
    $out['key_matches'] = $known ? hash_equals($fp, (string) $latest['key_fp']) : null;

    if (($latest['age_hours'] ?? 0) > $maxAge) {
        $out['state'] = 'stale';
        $out['message'] = sprintf('The newest snapshot is %d hours old (the limit is %d). The backup job may have stopped.',
            (int) $latest['age_hours'], $maxAge);
        return $out;
    }
    if (!$latest['has_uploads']) {
        /* Not "ok with a note". A restore from this archive comes back with
           every headshot and every recording missing, and those cannot be
           re-derived from any table. */
        $out['state'] = 'partial';
        $out['message'] = 'The newest snapshot holds no uploaded files — headshots and recordings would not survive a restore. Add --with-uploads to the backup command.';
        return $out;
    }
    if ($out['key_matches'] === false) {
        $out['state'] = 'partial';
        $out['message'] = 'The newest snapshot was taken under a DIFFERENT signing key. Restoring it would leave every issued ID card failing verification.';
        return $out;
    }

    $out['state'] = 'ok';
    $out['message'] = sprintf('Newest snapshot %s old, %d tables, %d uploaded file(s).',
        ($latest['age_hours'] ?? 0) < 1 ? 'under an hour' : $latest['age_hours'] . ' hours',
        (int) $latest['tables'], (int) $latest['upload_files']);
    return $out;
}

/**
 * Delete snapshots past the retention period.
 *
 * A snapshot is a FULL copy of every table plus every uploaded photograph, so
 * these are the largest personal-data stores on the disk. Keeping them for
 * ever is both a disk problem on shared hosting and a data-protection one.
 * Gated by the same `dp_retention_mode` as the table sweep and the log sweep,
 * so an academy that has not opted into enforcement gets a count and no
 * deletion, and a dry run overrides `enforce` rather than the other way round.
 *
 * ⚠️ The newest complete snapshot is NEVER deleted, whatever its age. A
 * retention rule that can leave an academy with no backup at all has done more
 * harm than the retention it enforced.
 */
function mam_backup_prune(bool $dryRun = false): array {
    $mode = function_exists('mam_dp_retention_mode') ? mam_dp_retention_mode() : 'report';
    $act  = !$dryRun && $mode === 'enforce';
    $days = mam_backup_keep_days();
    $cut  = time() - ($days * 86400);

    $out = ['mode' => $mode, 'applied' => $act, 'kept' => 0, 'overdue' => 0,
            'deleted' => 0, 'bytes' => 0, 'errors' => 0, 'keep_days' => $days];
    if ($mode === 'off' && !$dryRun) { $out['skipped'] = true; return $out; }

    $all  = mam_backup_list(500);
    $done = array_values(array_filter($all, static fn(array $b): bool => $b['complete']));
    $newest = $done[0]['name'] ?? null;

    foreach ($all as $b) {
        if ($b['name'] === $newest) { $out['kept']++; continue; }   // never the last one
        if (($b['at'] ?? PHP_INT_MAX) >= $cut) { $out['kept']++; continue; }
        $out['overdue']++;
        if (!$act) continue;
        $size = mam_backup_dir_size($b['path']);
        if (mam_backup_rmdir($b['path'])) { $out['deleted']++; $out['bytes'] += $size; }
        else $out['errors']++;
    }

    if ($out['deleted'] > 0 && function_exists('mam_audit')) {
        try {
            mam_audit('backup.pruned', 'backup', null,
                ['deleted' => $out['deleted'], 'bytes' => $out['bytes'], 'keep_days' => $days]);
        } catch (\Throwable $e) { /* never fail a sweep over its own audit row */ }
    }
    return $out;
}

/** Bytes under a directory. Best effort — used for reporting, never for a decision. */
function mam_backup_dir_size(string $dir): int {
    if (!is_dir($dir)) return 0;
    $n = 0;
    try {
        foreach (new RecursiveIteratorIterator(
                     new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            /** @var SplFileInfo $f */
            if ($f->isFile() && !$f->isLink()) $n += (int) $f->getSize();
        }
    } catch (\Throwable $e) { return $n; }
    return $n;
}

/**
 * Remove a snapshot directory.
 *
 * ⚠️ Refuses anything that is not a `YYYYmmdd-HHMMSS` directory directly
 * inside the export root, resolved with realpath first. This function deletes
 * recursively; a caller that could talk it into a different path would be a
 * way to delete arbitrary directories, so the guard is the point rather than
 * defensiveness. Symlinks are unlinked, never descended.
 */
function mam_backup_rmdir(string $dir): bool {
    $root = realpath(mam_backup_dir());
    $real = realpath($dir);
    if ($root === false || $real === false) return false;
    if (dirname($real) !== $root) return false;
    if (!preg_match(MAM_BACKUP_DIR_RE, basename($real))) return false;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if ($f->isLink() || $f->isFile()) { @unlink($f->getPathname()); continue; }
        if ($f->isDir()) @rmdir($f->getPathname());
    }
    return @rmdir($real);
}
