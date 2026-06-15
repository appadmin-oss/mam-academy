<?php
/**
 * Notification centre (LMS L3) — the per-user in-app inbox over the automation
 * spine. Covers the write/read/mark library (owner-scoped, never-throw) and the
 * three domain emitters: feedback → student, finalize → student, assign → staff.
 */
declare(strict_types=1);
t_group('notifications: centre + emitters');

$alice = mam_db_insert('users', ['email' => 't_notif_a@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Alice A', 'status' => 'active']);
$bob   = mam_db_insert('users', ['email' => 't_notif_b@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Bob B', 'status' => 'active']);

// ── Library: create / count / list ──
t_eq(mam_notifications_unread_count($alice), 0, 'a new user has no unread notifications');
$n1 = mam_notify($alice, 'test.one', 'First note', 'body one', '/portal/student/');
t_ok($n1 > 0, 'mam_notify returns a row id');
$n2 = mam_notify($alice, 'test.two', 'Second note');
t_ok($n2 > $n1, 'a second notify is a distinct row');
t_eq(mam_notifications_unread_count($alice), 2, 'both notifications are unread');
t_eq(mam_notify(0, 'x', 'no user') , 0, 'notify with no user id is a no-op');
t_eq(mam_notify($alice, 'x', '   '), 0, 'notify with an empty title is a no-op');

$list = mam_notifications($alice);
t_eq(count($list), 2, 'list returns both');
t_eq($list[0]['title'], 'Second note', 'list is newest-first');
t_eq($list[0]['type'], 'test.two', 'list carries the type');
t_eq(mam_notifications($alice, true)[0]['title'], 'Second note', 'unread-only list works');

// Owner scoping: Bob sees nothing of Alice's, and cannot mark Alice's read.
t_eq(count(mam_notifications($bob)), 0, 'notifications are owner-scoped');
t_ok(mam_notification_mark_read($bob, $n1) === false, 'a user cannot mark someone else\'s notification read');
t_eq(mam_notifications_unread_count($alice), 2, 'the failed cross-user mark did not change Alice\'s count');

// ── Mark read (one, idempotent) ──
t_ok(mam_notification_mark_read($alice, $n1) === true, 'owner marks their own notification read');
t_eq(mam_notifications_unread_count($alice), 1, 'unread count drops by one');
t_ok(mam_notification_mark_read($alice, $n1) === false, 're-marking an already-read notification is a no-op');

// ── Mark all read ──
mam_notify($alice, 'test.three', 'Third note');
t_eq(mam_notifications_unread_count($alice), 2, 'a fresh notification is unread again');
t_eq(mam_notifications_mark_all_read($alice), 2, 'mark-all returns the number cleared');
t_eq(mam_notifications_unread_count($alice), 0, 'inbox is fully read after mark-all');

// ── Emitter: feedback → student ──
$ins = mam_db_insert('users', ['email' => 't_notif_ins@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Tutor T', 'status' => 'active']);
$stu = mam_db_insert('users', ['email' => 't_notif_stu@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Pupil P', 'status' => 'active']);
$aid = mam_db_insert('assignments', ['student_id' => $stu, 'author_id' => $ins, 'title' => 'Play a blues scale', 'status' => 'open']);
$sub = mam_lms_submit($aid, $stu, 'https://example.com/blues.mp3', ['artifact_kind' => 'audio']);
mam_lms_give_feedback($sub, $ins, ['outcome' => 'revise', 'comments' => 'Slow the turnaround.']);
$fnotes = mam_notifications($stu, true);
t_eq(count($fnotes), 1, 'feedback notifies the student exactly once');
t_eq($fnotes[0]['type'], 'submission.feedback', 'feedback notification carries the right type');
t_ok(strpos((string)$fnotes[0]['title'], 'another pass') !== false, 'a revise outcome reads as "needs another pass"');
t_ok(strpos((string)$fnotes[0]['body'], 'Play a blues scale') !== false, 'the body names the assignment');
// The instructor is NOT notified of their own feedback.
t_eq(mam_notifications_unread_count($ins), 0, 'the instructor is not notified of their own feedback');

// ── Emitter: finalize → student ──
$life = mam_db_insert('users', ['email' => 't_notif_life@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Grad G', 'status' => 'active']);
$lins = mam_db_insert('users', ['email' => 't_notif_lins@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Grader G', 'status' => 'active']);
$eid  = (int)mam_lms_add_course($life, 'piano', 'precert', 'standard')['id'];
mam_lms_approve($eid, $lins, $lins);
mam_lms_record_assessment($eid, 'final', $lins, 90, 100, 12, null); // passing
mam_lms_finalize($eid, $lins);
$lnotes = mam_notifications($life, true);
t_eq(count($lnotes), 1, 'finalize notifies the student');
t_eq($lnotes[0]['type'], 'enrolment.completed', 'a pass yields an enrolment.completed notification');
t_ok(strpos((string)$lnotes[0]['title'], 'completed') !== false, 'the title reads as completed');

// ── Emitter: application assigned → staff (skips self-assign) ──
$staff = mam_db_insert('users', ['email' => 't_notif_staff@x.dev', 'password_hash' => 'x', 'role' => 'admin', 'name' => 'Admissions A', 'status' => 'active', 'admin_tier' => 'manager']);
$cid   = mam_db_insert('customers', ['email' => 't_notif_app@x.dev', 'first_name' => 'App', 'last_name' => 'Licant']);
$app   = mam_db_insert('applications', ['reference' => 'MAM-NOTIF-1', 'customer_id' => $cid, 'pathway' => 'standard', 'status' => 'submitted']);
mam_admissions_assign($app, $staff, 999999); // assigned BY someone else → notify
$snotes = mam_notifications($staff, true);
t_eq(count($snotes), 1, 'assigning an application notifies the assignee');
t_eq($snotes[0]['type'], 'application.assigned', 'assignment notification carries the right type');
t_ok(strpos((string)$snotes[0]['body'], 'MAM-NOTIF-1') !== false, 'the body carries the application reference');
t_ok(strpos((string)$snotes[0]['url'], 'app=' . $app) !== false, 'the deep link points at the application');
// Self-assignment does not notify (no point pinging yourself).
mam_admissions_assign($app, $staff, $staff);
t_eq(mam_notifications_unread_count($staff), 1, 'self-assignment adds no new notification');
