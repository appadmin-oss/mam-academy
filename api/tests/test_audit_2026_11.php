<?php
/**
 * Defects found by the November 2026 audit of the 2026-09-29 → 2026-11-10 work
 * (HANDOFF §4t). Each was real and measured; each assertion here fails against
 * the code as it was.
 */
declare(strict_types=1);

t_group('audit 2026-11 — a backup that could not copy a photo does not call itself complete');

$AU_root = mam_backup_dir();
@mkdir($AU_root, 0775, true);
$AU_clean = static function () use ($AU_root): void {
    foreach ((array) @scandir($AU_root) as $n) {
        if ($n === '.' || $n === '..') continue;
        mam_backup_rmdir($AU_root . '/' . $n); @rmdir($AU_root . '/' . $n);
    }
};
$AU_make = static function (array $uploads) use ($AU_root): void {
    $d = $AU_root . '/' . gmdate('Ymd-His');
    @mkdir($d, 0775, true);
    file_put_contents($d . '/manifest.json', json_encode(['format_version' => 1, 'tables' => ['users' => ['rows' => 0]],
        'total_rows' => 1, 'signing_key_fingerprint' => mam_backup_key_fingerprint(), 'uploads' => $uploads]));
};
$AU_clean();
$AU_make(['files' => 1, 'bytes' => 10, 'sha256' => ['202611/a.jpg' => 'aa'], 'skipped' => ['202611/b.jpg (copy failed)']]);
$AU_st = mam_backup_status();
t_eq($AU_st['state'], 'partial', 'a snapshot with a file it could not copy is PARTIAL, not ok — it said ok before');
t_ok(str_contains($AU_st['message'], '1 uploaded file'), 'and the message says how many are missing');

$AU_clean();
$AU_make(['files' => 0, 'bytes' => 0, 'sha256' => [], 'skipped' => []]);
t_eq(mam_backup_status()['state'], 'ok', 'a snapshot taken WITH --with-uploads on an install that has no photos yet is ok — not "add --with-uploads"');
$AU_clean();
$AU_make(['captured' => false]);
t_eq(mam_backup_status()['state'], 'partial', 'one taken without the uploads is still partial');
$AU_clean();

$AU_exp = (string) file_get_contents(dirname(__DIR__) . '/tools/export.php');
t_ok(str_contains($AU_exp, "foreach ((array) (\$up['skipped'] ?? []) as \$sk) \$bad[] ="), '--verify-only fails on a file the export skipped');
t_ok(str_contains($AU_exp, 'exit($uploadsIncomplete > 0 ? 3 : 0)'), 'and the export itself exits non-zero, so the cron sees it');
t_ok(str_contains($AU_exp, "\$stamp  = gmdate('Ymd-His')"), 'snapshot names are UTC, the zone backup.php reads them in');

t_group('audit 2026-11 — the instructor application accepts the photo its own uploader stored');

$AU_base = ['full_name' => 'Ada Teacher', 'disciplines' => 'Piano', 'bio' => str_repeat('I teach piano to children. ', 3),
            'experience_years' => 5];
$AU_ok = mam_faculty_validate($AU_base + ['headshot_url' => '/api/media/headshot?k=202611/' . str_repeat('a', 32)]);
t_ok(!in_array('headshot_url', $AU_ok['errors'], true), 'a first-party /api/media/headshot URL passes — it was refused, so a candidate without Cloudinary could not submit');
$AU_bad = mam_faculty_validate($AU_base + ['headshot_url' => 'javascript:alert(1)']);
t_ok(in_array('headshot_url', $AU_bad['errors'], true), 'a script URL is still refused');
$AU_cv = mam_faculty_validate($AU_base + ['cv_url' => '/api/media/headshot?k=x']);
t_ok(in_array('cv_url', $AU_cv['errors'], true), 'and the CV link keeps its https rule');

t_group('audit 2026-11 — a returning family\'s stored details are not blanked by a sparse submission');

$AU_route = (string) file_get_contents(dirname(__DIR__) . '/routes/application.php');
t_ok((bool) preg_match("/mam_db_update\\('customers', array_filter\\(/", $AU_route),
    'the customer update drops empty values instead of writing NULL over what an earlier application stored');
$AU_dmg = (string) file_get_contents(dirname(__DIR__) . '/lib/enrol-complete.php');
t_ok(str_contains($AU_dmg, 'AS other_apps'), 'the damaged-application listing counts the family\'s other applications');
$AU_adm = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
t_ok(!str_contains($AU_adm, "array_filter(\$rows, fn (\$r) => isset(\$incompleteIds"),
    'the "finish enrolment" filter no longer filters only the drawn page');
