<?php
declare(strict_types=1);
t_group('audit timeline (§8.5)');
$AT_stu = mam_db_insert('users', ['name' => 'AT Stu', 'email' => 'at_stu@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
// A row about the user's account, a row the user performed, and a row on a submission they own.
mam_db_insert('audit_log', ['actor' => 'admin', 'action' => 'set_student_type', 'entity' => 'user', 'entity_id' => $AT_stu, 'meta' => '{}']);
mam_db_insert('audit_log', ['actor' => 'at_stu@test.local', 'action' => 'checkin', 'entity' => 'class_session', 'entity_id' => 5, 'meta' => '{}']);
$AT_sub = mam_db_insert('submissions', ['assignment_id' => 1, 'student_id' => $AT_stu, 'artifact_url' => 'https://x', 'artifact_kind' => 'link']);
mam_db_insert('audit_log', ['actor' => 'instructor', 'action' => 'lms_feedback', 'entity' => 'submission', 'entity_id' => $AT_sub, 'meta' => '{}']);
// An unrelated row that must NOT appear.
mam_db_insert('audit_log', ['actor' => 'someone', 'action' => 'lms_feedback', 'entity' => 'submission', 'entity_id' => 999999, 'meta' => '{}']);

$tl = mam_audit_timeline($AT_stu, 100);
t_ok(count($tl) === 3, 'timeline gathers user + actor + owned-submission rows (and excludes unrelated)');
$actions = array_column($tl, 'action');
t_ok(in_array('set_student_type', $actions, true), 'includes account changes');
t_ok(in_array('checkin', $actions, true), 'includes actions the user performed');
t_ok(in_array('lms_feedback', $actions, true), 'includes actions on their submissions');
t_eq(mam_audit_label('lms_feedback'), 'Feedback given', 'action codes get a human label');
t_eq(mam_audit_label('some_new_thing'), 'Some new thing', 'unknown actions fall back to a humanised label');
t_eq(mam_audit_timeline(99999999), [], 'a missing user yields an empty timeline');
