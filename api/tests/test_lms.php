<?php
/** LMS course registration — catalogue, add, duplicate guard, approve/assign. */
declare(strict_types=1);
t_group('lms course registration');

$sid = mam_db_insert('users', ['email' => 't_stu@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Test Student', 'status' => 'active']);
$iid = mam_db_insert('users', ['email' => 't_ins@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Test Instructor', 'status' => 'active']);

$cat = mam_lms_catalog();
t_eq(count($cat['courses']), 12, 'catalogue exposes 12 courses');
t_eq(count($cat['programs']), 4, 'catalogue exposes 4 programmes');
t_ok($cat['courses'][0]['kind'] === 'core', 'core courses sort first');

// Manual policy by default → pending.
$r1 = mam_lms_add_course($sid, 'piano', 'precert', 'standard');
t_eq($r1['status'], 'pending', 'add course → pending under manual policy');

// REGRESSION: duplicate course+level must be blocked. Guards the SQLite/PDO
// affinity bug where IFNULL(level_id,0)=? never matched a TEXT-bound param.
$blocked = false;
try { mam_lms_add_course($sid, 'piano', 'precert', 'standard'); }
catch (RuntimeException $e) { $blocked = ($e->getMessage() === 'already_enrolled'); }
t_ok($blocked, 'duplicate course+level blocked (affinity-bug regression)');

// A different level of the same course is allowed.
$ok2 = false;
try { mam_lms_add_course($sid, 'piano', 'cert', 'standard'); $ok2 = true; } catch (Throwable $e) {}
t_ok($ok2, 'same course at a different level is allowed');

// Unknown course rejected.
$bad = false;
try { mam_lms_add_course($sid, 'no-such-course'); } catch (InvalidArgumentException $e) { $bad = true; }
t_ok($bad, 'unknown course rejected');

// Approve + assign instructor.
mam_lms_approve((int)$r1['id'], $iid, 1);
$mine = mam_lms_student_courses($sid);
$piano = null;
foreach ($mine as $m) { if ($m['course_code'] === 'piano' && $m['level_code'] === 'precert') $piano = $m; }
t_ok($piano !== null, 'student courses lists the enrolment');
t_eq($piano['status'], 'active', 'approval activates the enrolment');
t_eq($piano['instructor_name'], 'Test Instructor', 'instructor assigned on approval');
t_ok(count(mam_lms_instructors()) >= 1, 'assignable instructors are listed (multi-role aware)');

t_group('lms instructor-assignment memory');

// The explicit pick above must be remembered in instructor_assignments…
$mem = mam_db_row('SELECT * FROM instructor_assignments WHERE instructor_id = ? ORDER BY id DESC', [$iid]);
t_ok($mem !== null, 'explicit instructor pick remembered in instructor_assignments');

// …so approving ANOTHER student for the same course+level without picking an
// instructor defaults to the remembered one.
$sid2 = mam_db_insert('users', ['email' => 't_stu2@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Second Student', 'status' => 'active']);
$r2 = mam_lms_add_course($sid2, 'piano', 'precert', 'standard');
mam_lms_approve((int)$r2['id'], null, 1); // no instructor picked
$mine2 = mam_lms_student_courses($sid2);
t_eq($mine2[0]['instructor_name'], 'Test Instructor', 'approval w/o a pick defaults from the assignment memory');

// Level-agnostic fallback: remember (instructor, course, NULL level) and the
// default applies at any level of that course.
$course2 = mam_db_row("SELECT id, code FROM courses WHERE code != 'piano' AND active = 1 ORDER BY id LIMIT 1");
mam_lms_remember_instructor($iid, (int)$course2['id'], null);
$lvl = mam_db_row("SELECT id FROM levels WHERE code = 'cert'");
t_eq(mam_lms_default_instructor((int)$course2['id'], (int)$lvl['id']), $iid, 'level-agnostic memory backs any level of the course');
t_eq(mam_lms_default_instructor(999999, null), null, 'no memory → no default');

// Remember is idempotent — re-remembering must not violate the unique index.
mam_lms_remember_instructor($iid, (int)$course2['id'], null);
$n = mam_db_row('SELECT COUNT(*) AS c FROM instructor_assignments WHERE instructor_id = ? AND course_id = ? AND level_id IS NULL', [$iid, (int)$course2['id']]);
t_eq((int)$n['c'], 1, 're-remembering the same assignment stays a single row');
