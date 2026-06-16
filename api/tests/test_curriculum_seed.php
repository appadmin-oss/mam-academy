<?php
/** Bundled starter curriculum — shape, seeding, idempotency, non-destructiveness. */
declare(strict_types=1);

t_group('starter curriculum — shape');
$syl = mam_lms_starter_curriculum();
t_eq(count($syl), 8, 'ships 8 course-level syllabi (5 cert + 3 precert)');

$mapsOk = true; $shapeOk = true;
foreach ($syl as $s) {
    if (!mam_db_row('SELECT id FROM courses WHERE code = ?', [$s['course']]) ||
        !mam_db_row('SELECT id FROM levels  WHERE code = ?', [$s['level']])) $mapsOk = false;
    if (count($s['weeks']) !== 12) $shapeOk = false;
    $byWeek = [];
    foreach ($s['weeks'] as $w) {
        $byWeek[(int)$w['week']] = (string)$w['kind'];
        if (trim((string)$w['title']) === '') $shapeOk = false; // every week has a title
    }
    if (($byWeek[11] ?? '') !== 'revision' || ($byWeek[12] ?? '') !== 'exam') $shapeOk = false;
}
t_ok($mapsOk, 'every syllabus maps to a real course + level');
t_ok($shapeOk, 'every syllabus is a titled 12-week plan (wk11 revision, wk12 exam)');

t_group('starter curriculum — seeding');
$rpId   = (int)mam_db_row("SELECT id FROM courses WHERE code = 'rhythm_pitch'")['id'];
$certId = (int)mam_db_row("SELECT id FROM levels  WHERE code = 'cert'")['id'];

$res = mam_lms_seed_starter_curriculum(false);
t_eq(count($res), 8, 'seeder reports on all 8 syllabi');
t_eq(count(mam_lms_export_modules($rpId, $certId)), 12, 'rhythm_pitch/cert (core flagship) fully seeded — 12 weeks');
// The flag is set so an auto-seed never repeats.
t_eq(mam_setting('curriculum_seeded', ''), '1', 'seeding sets the curriculum_seeded flag');

// Idempotent: a second non-forced seed creates nothing new.
$res2 = mam_lms_seed_starter_curriculum(false);
$created2 = 0; foreach ($res2 as $r) $created2 += (int)($r['created'] ?? 0);
t_eq($created2, 0, 're-seeding (non-forced) creates nothing — idempotent');

t_group('starter curriculum — non-destructive vs force');
// An educator edits week 1; a non-forced seed must preserve it.
$wk1Id = mam_lms_find_module_week($rpId, $certId, 1);
mam_lms_save_module($wk1Id, $rpId, $certId, 1, 'module', 'EDITED BY EDUCATOR', 'custom notes');
mam_lms_seed_starter_curriculum(false);
$kept = mam_db_row('SELECT title FROM modules WHERE course_id = ? AND level_id = ? AND week = 1', [$rpId, $certId]);
t_eq($kept['title'], 'EDITED BY EDUCATOR', 'non-forced seed preserves an educator edit');

// Force re-imports the starter, overwriting the edit (a deliberate reset).
mam_lms_seed_starter_curriculum(true);
$reset = mam_db_row('SELECT title FROM modules WHERE course_id = ? AND level_id = ? AND week = 1', [$rpId, $certId]);
t_ok($reset['title'] !== 'EDITED BY EDUCATOR' && trim((string)$reset['title']) !== '', 'forced seed resets week 1 back to the starter title');
