<?php
/** Standard curriculum — CSV parsing, admin catalogue, and the idempotent
 *  JSON/CSV import (upsert by course+level+week) + export. */
declare(strict_types=1);

t_group('curriculum CSV parsing');

$csv  = "Week,Title,Kind,Content\n1,Intro to Rhythm,module,\"Clap, count, feel\"\n2,Sight Reading,module,\n";
$rows = mam_lms_parse_csv($csv);
t_eq(count($rows), 2, 'CSV → 2 data rows (header consumed)');
t_eq($rows[0]['week'], '1', 'week column parsed');
t_eq($rows[0]['title'], 'Intro to Rhythm', 'title column parsed');
t_eq($rows[0]['content'], 'Clap, count, feel', 'quoted comma preserved inside a field');
t_eq($rows[0]['kind'], 'module', 'kind column parsed');

// Header normalisation + UTF-8 BOM tolerance.
$csv2  = "\xEF\xBB\xBF Week , Course Code ,Title\n3,piano,Chords\n";
$rows2 = mam_lms_parse_csv($csv2);
t_eq($rows2[0]['week'], '3', 'BOM + spaced header normalised → week');
t_eq($rows2[0]['course_code'], 'piano', '"Course Code" header → course_code key');
t_ok(mam_lms_parse_csv('') === [], 'empty CSV → []');
t_ok(mam_lms_parse_csv("Week,Title\n") === [], 'header-only CSV → []');

t_group('curriculum admin catalogue');
$cat = mam_lms_admin_catalog();
t_ok(count($cat['courses']) >= 12 && isset($cat['courses'][0]['id']), 'admin catalogue lists courses WITH ids');
t_ok(isset($cat['programs'][0]['weeks']), 'admin catalogue programmes carry the week shape');
t_ok(array_key_exists('active', $cat['courses'][0]), 'admin catalogue courses expose the active flag');

t_group('curriculum import / export (upsert by week)');
$cid  = (int)mam_db_row("SELECT id FROM courses WHERE code = 'conducting'")['id'];
$lid  = (int)mam_db_row("SELECT id FROM levels  WHERE code = 'diploma'")['id'];
$gid  = (int)mam_db_row("SELECT id FROM courses WHERE code = 'guitar'")['id'];
// Isolate this obscure combo so created/updated counts are deterministic
// regardless of what other test files authored.
mam_db()->prepare('DELETE FROM modules WHERE course_id = ?')->execute([$cid]);
mam_db()->prepare('DELETE FROM modules WHERE course_id = ? AND level_id = ? AND week = 9')->execute([$gid, $lid]);

$res = mam_lms_import_modules($cid, $lid, [
    ['week' => 1, 'title' => 'Baton Technique', 'kind' => 'module'],
    ['week' => 2, 'title' => 'Score Reading', 'content' => 'Full orchestral score'],
    ['week' => 3, 'title' => 'Final', 'kind' => 'exam'],
]);
t_eq($res['created'], 3, 'import created 3 weeks');
t_eq($res['updated'], 0, 'nothing pre-existing to update');
t_eq($res['skipped'], 0, 'no rows skipped');

// Re-import the same weeks with changed titles → all UPDATES, no duplicates.
$res2 = mam_lms_import_modules($cid, $lid, [
    ['week' => 1, 'title' => 'Baton Technique II'],
    ['week' => 2, 'title' => 'Score Reading II'],
]);
t_eq($res2['updated'], 2, 're-import upserts existing weeks');
t_eq($res2['created'], 0, 're-import creates nothing (idempotent by week)');

// Export reflects the latest titles, week-sorted.
$exp = mam_lms_export_modules($cid, $lid);
t_eq(count($exp), 3, 'export returns exactly the three authored weeks');
t_eq($exp[0]['week'], 1, 'export is week-sorted');
t_eq($exp[0]['title'], 'Baton Technique II', 'export shows the upserted title');

// Bad rows are collected in errors, never fatal.
$res3 = mam_lms_import_modules($cid, $lid, [
    ['week' => 0, 'title' => 'No week'],
    ['week' => 5, 'title' => ''],
    ['title'  => 'Missing week field'],
]);
t_eq($res3['created'], 0, 'no valid rows created from a bad batch');
t_eq($res3['skipped'], 3, 'three bad rows skipped');
t_eq(count($res3['errors']), 3, 'each bad row enumerated in errors');

// A per-row course_code overrides the default selection — one file, many courses.
$res4 = mam_lms_import_modules($cid, $lid, [
    ['week' => 9, 'title' => 'Fingerstyle', 'course_code' => 'guitar', 'level_code' => 'diploma'],
]);
t_eq($res4['created'], 1, 'per-row course_code routes the row');
t_ok(mam_lms_find_module_week($gid, $lid, 9) !== null, 'overridden row landed in guitar');
t_ok(mam_lms_find_module_week($cid, $lid, 9) === null, 'conducting did NOT receive the overridden row');

// CSV export → parse round-trip keeps every row.
$parsed = mam_lms_parse_csv(mam_lms_modules_to_csv($exp));
t_eq(count($parsed), count($exp), 'modules_to_csv round-trips through parse_csv');

t_group('curriculum import text detection');
t_eq(count(mam_lms_rows_from_text('[{"week":1,"title":"A"}]')), 1, 'bare JSON array detected');
$wrapped = mam_lms_rows_from_text('{"course":"piano","modules":[{"week":1,"title":"A"},{"week":2,"title":"B"}]}');
t_eq(count($wrapped), 2, 'wrapped {modules:[…]} unwrapped to rows');
$auto = mam_lms_rows_from_text("week,title\n1,Hello\n");
t_eq($auto[0]['title'], 'Hello', 'non-bracketed text auto-detected as CSV');
t_eq(mam_lms_rows_from_text('1,Hello', 'csv'), [], 'format=csv with no header row → []');
t_ok(mam_lms_rows_from_text('   ') === [], 'blank text → []');
t_ok(mam_lms_rows_from_text('not json', 'json') === [], 'forced json on garbage → []');

// Affinity: a level-agnostic (NULL) week is distinct from the same week at a level.
$res5 = mam_lms_import_modules($cid, null, [['week' => 1, 'title' => 'Agnostic Wk1']]);
t_eq($res5['created'], 1, 'NULL-level week 1 is a distinct row from the diploma week 1');
$nullId = mam_lms_find_module_week($cid, null, 1);
$dipId  = mam_lms_find_module_week($cid, $lid, 1);
t_ok($nullId !== null && $dipId !== null && $nullId !== $dipId, 'NULL-level and diploma week 1 are separate rows (affinity-safe)');
