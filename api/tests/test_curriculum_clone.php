<?php
/** Curriculum clone — copy a course's authored weeks between levels (idempotent). */
declare(strict_types=1);
t_group('curriculum clone');

$cid = mam_db_insert('courses', ['code' => 'clone_test', 'name' => 'Clone Test', 'kind' => 'core', 'sort_order' => 99, 'active' => 1]);
$pre = (int)mam_db_row("SELECT id FROM levels WHERE code = 'precert'")['id'];
$cert = (int)mam_db_row("SELECT id FROM levels WHERE code = 'cert'")['id'];
// Author 3 weeks at Pre-Certification.
foreach ([[1, 'module', 'Intro'], [2, 'module', 'Scales'], [3, 'exam', 'Checkpoint']] as $w) {
    mam_lms_save_module(null, $cid, $pre, $w[0], $w[1], $w[2], 'Content for ' . $w[2]);
}

// Clone precert → cert.
$res = mam_lms_clone_syllabus('clone_test', 'precert', 'cert');
t_eq($res['total'], 3, 'all three weeks are cloned');
t_eq($res['created'], 3, 'first clone creates all target weeks');
t_eq((int)mam_db_row('SELECT COUNT(*) c FROM modules WHERE course_id = ? AND level_id = ?', [$cid, $cert])['c'], 3, 'target level now has three weeks');
$w2 = mam_db_row('SELECT title, kind, content FROM modules WHERE course_id = ? AND level_id = ? AND week = 2', [$cid, $cert]);
t_eq($w2['title'], 'Scales', 'week titles copy across');
t_eq($w2['kind'], 'module', 'week kind copies across');
t_ok(strpos((string)$w2['content'], 'Scales') !== false, 'week content copies across');

// Idempotent: re-cloning updates in place, never duplicates.
$res2 = mam_lms_clone_syllabus('clone_test', 'precert', 'cert');
t_eq($res2['updated'], 3, 're-clone updates the existing weeks');
t_eq($res2['created'], 0, 're-clone creates nothing new');
t_eq((int)mam_db_row('SELECT COUNT(*) c FROM modules WHERE course_id = ? AND level_id = ?', [$cid, $cert])['c'], 3, 'still exactly three weeks after re-clone (no duplicates)');

// Guards.
$threw = false; try { mam_lms_clone_syllabus('clone_test', 'cert', 'cert'); } catch (\InvalidArgumentException $e) { $threw = true; }
t_ok($threw, 'cloning a level onto itself is rejected');
$threw = false; try { mam_lms_clone_syllabus('no_such_course', 'precert', 'cert'); } catch (\InvalidArgumentException $e) { $threw = true; }
t_ok($threw, 'cloning an unknown course is rejected');

// ── Admin course creation (mam_lms_create_course) ──
t_group('course creation');
$made = mam_lms_create_course('test_theory', 'Music Theory', 'core', 'Fundamentals of harmony and rhythm.');
t_ok($made['id'] > 0 && $made['code'] === 'test_theory', 'a new course is created and returns its id + code');
$row = mam_db_row('SELECT name, kind, description, active FROM courses WHERE code = ?', ['test_theory']);
t_eq($row['name'], 'Music Theory', 'the course name is stored');
t_eq($row['kind'], 'core', 'the course kind is stored');
t_ok((int)$row['active'] === 1, 'the new course is active');

$threw = ''; try { mam_lms_create_course('test_theory', 'Dup', 'elective'); } catch (\InvalidArgumentException $e) { $threw = $e->getMessage(); }
t_eq($threw, 'duplicate_code', 'a duplicate code is rejected');
$threw = ''; try { mam_lms_create_course('Bad Code!', 'X', 'elective'); } catch (\InvalidArgumentException $e) { $threw = $e->getMessage(); }
t_eq($threw, 'bad_code', 'a non-slug code is rejected');
$threw = ''; try { mam_lms_create_course('ok_code', '', 'elective'); } catch (\InvalidArgumentException $e) { $threw = $e->getMessage(); }
t_eq($threw, 'bad_name', 'an empty name is rejected');
$made2 = mam_lms_create_course('test_elec', 'Elective X', 'nonsense');
t_eq(mam_db_row('SELECT kind FROM courses WHERE code = ?', ['test_elec'])['kind'], 'elective', 'an unknown kind falls back to elective');

// Restore the shared test DB — remove all fixture courses + their modules so
// later tests still see the pristine seeded catalogue (12 courses).
mam_db()->prepare('DELETE FROM modules WHERE course_id = ?')->execute([$cid]);
mam_db()->prepare('DELETE FROM courses WHERE id = ?')->execute([$cid]);
mam_db()->prepare("DELETE FROM courses WHERE code IN ('test_theory','test_elec')")->execute();
