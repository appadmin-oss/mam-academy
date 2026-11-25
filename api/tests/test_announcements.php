<?php
/** Announcement visibility — the cohort model only (the old class model is retired and carried). */
declare(strict_types=1);
t_group('announcements (cohort visibility)');

// A course/cohort-only student (course_enrollments, no legacy class).
$insId = mam_db_insert('users', ['email' => 't_ann_ins@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Ann Instr', 'status' => 'active']);
$stuId = mam_db_insert('users', ['email' => 't_ann_stu@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Ann Stu', 'status' => 'active']);
$pianoId = (int)mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];
$coId = mam_lms_create_cohort(['title' => 'Announce Cohort', 'instructor_id' => $insId, 'course_id' => $pianoId]);
mam_db_insert('course_enrollments', ['student_id' => $stuId, 'course_id' => $pianoId, 'instructor_id' => $insId, 'cohort_id' => $coId, 'status' => 'active']);

// Baseline: nothing posted yet.
t_eq(count(mam_student_announcements($stuId)), 0, 'a student with no announcements sees none');

// A cohort announcement is visible to the enrolled student.
$a1 = mam_db_insert('announcements', ['cohort_id' => $coId, 'author_id' => $insId, 'title' => 'Recital Friday', 'body' => 'Prepare your piece.']);
$seen = mam_student_announcements($stuId);
t_eq(count($seen), 1, 'a cohort announcement reaches the enrolled student');
t_eq($seen[0]['title'], 'Recital Friday', 'the announcement title comes through');
t_eq($seen[0]['class_title'], 'Announce Cohort', 'class_title carries the cohort name');
t_eq($seen[0]['author'], 'Ann Instr', 'the author name resolves');

// A second cohort announcement — newest first (guards the ORDER BY + id tiebreak).
$a2 = mam_db_insert('announcements', ['cohort_id' => $coId, 'author_id' => $insId, 'title' => 'Bring a metronome', 'body' => 'Second note.']);
$seen2 = mam_student_announcements($stuId);
t_eq(count($seen2), 2, 'both cohort announcements are visible');
t_eq($seen2[0]['title'], 'Bring a metronome', 'the compound query orders newest first');

// An announcement to a cohort/class the student is NOT in must not leak.
$otherCohort = mam_lms_create_cohort(['title' => 'Other Cohort', 'instructor_id' => $insId, 'course_id' => $pianoId]);
mam_db_insert('announcements', ['cohort_id' => $otherCohort, 'author_id' => $insId, 'title' => 'Not yours', 'body' => 'x']);
$otherClass = mam_db_insert('classes', ['title' => 'Other Class', 'status' => 'active']);
mam_db_insert('announcements', ['class_id' => $otherClass, 'author_id' => $insId, 'title' => 'Also not yours', 'body' => 'x']);
$titles = array_map(fn($a) => $a['title'], mam_student_announcements($stuId));
t_ok(!in_array('Not yours', $titles, true), 'an announcement to another cohort does not leak');
t_ok(!in_array('Also not yours', $titles, true), 'an announcement to another class does not leak');

// The old class model is retired: mam_legacy_carry() sets cohort_id on every old
// class notice, and the cohort is all that is read. A carried notice (class_id AND
// cohort_id) reaches the cohort's student; an enrolment left only in the old
// `enrollments` table reaches nothing, because that table is no longer read.
$legClass = mam_db_insert('classes', ['title' => 'Legacy Class', 'instructor_id' => $insId, 'status' => 'active']);
mam_db_insert('announcements', ['class_id' => $legClass, 'cohort_id' => $coId, 'author_id' => $insId, 'title' => 'Carried note', 'body' => 'carried from the class']);
$carried = array_map(fn($a) => $a['title'], mam_student_announcements($stuId));
t_ok(in_array('Carried note', $carried, true), 'a class notice carried into the cohort reaches the cohort student');
$legStu = mam_db_insert('users', ['email' => 't_ann_leg@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Leg Stu', 'status' => 'active']);
mam_db_insert('enrollments', ['student_id' => $legStu, 'class_id' => $legClass, 'status' => 'active']);
mam_db_insert('announcements', ['class_id' => $legClass, 'author_id' => $insId, 'title' => 'Class note', 'body' => 'never carried']);
t_eq(count(mam_student_announcements($legStu)), 0, 'the old class tables are not read: an uncarried class enrolment receives nothing');

// A dropped (cancelled) cohort enrolment stops delivery.
$dropStu = mam_db_insert('users', ['email' => 't_ann_drop@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Drop Stu', 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $dropStu, 'course_id' => $pianoId, 'cohort_id' => $coId, 'status' => 'cancelled']);
t_eq(count(mam_student_announcements($dropStu)), 0, 'a cancelled cohort enrolment does not receive announcements');

// ── Learning resources — the cohort model only ──
t_group('learning resources (cohort visibility)');

// class_id must now be nullable (v40 rebuild) so a cohort resource can exist.
$cohortRes = mam_db_insert('class_resources', ['cohort_id' => $coId, 'title' => 'Scales PDF', 'url' => 'https://x.dev/scales.pdf', 'type' => 'sheet', 'posted_by' => $insId]);
t_ok($cohortRes > 0, 'a cohort resource inserts with a NULL class_id (class_id nullable after v40)');

$res = mam_student_resources($stuId);
t_eq(count($res), 1, 'a cohort resource reaches the course-only student');
t_eq($res[0]['class_title'], 'Announce Cohort', 'the resource carries the cohort name');
t_eq($res[0]['type'], 'sheet', 'the resource type comes through');

// A resource carried from a class (class_id AND cohort_id) reaches the cohort's
// student; one left on the class alone reaches nobody — the old tables are unread.
mam_db_insert('class_resources', ['class_id' => $legClass, 'cohort_id' => $coId, 'title' => 'Class handout', 'url' => 'https://x.dev/h.pdf', 'type' => 'doc', 'posted_by' => $insId]);
t_ok(in_array('Class handout', array_map(fn($r) => $r['title'], mam_student_resources($stuId)), true), 'a carried class resource reaches the cohort student');
mam_db_insert('class_resources', ['class_id' => $legClass, 'title' => 'Uncarried handout', 'url' => 'https://x.dev/u.pdf', 'type' => 'doc', 'posted_by' => $insId]);
t_eq(count(mam_student_resources($legStu)), 0, 'an uncarried class enrolment receives no resources');

// No cross-cohort leak, and a cancelled enrolment gets nothing.
mam_db_insert('class_resources', ['cohort_id' => $otherCohort, 'title' => 'Other cohort res', 'url' => 'https://x.dev/o.pdf', 'type' => 'link', 'posted_by' => $insId]);
$rtitles = array_map(fn($r) => $r['title'], mam_student_resources($stuId));
t_ok(!in_array('Other cohort res', $rtitles, true), 'a resource on another cohort does not leak');
t_eq(count(mam_student_resources($dropStu)), 0, 'a cancelled cohort enrolment receives no resources');
