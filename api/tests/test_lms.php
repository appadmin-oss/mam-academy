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
