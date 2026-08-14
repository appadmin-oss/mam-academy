<?php
/**
 * Tests for the LMS 2.0 teaching helpers (api/lib/lms2.php, Parts B/D):
 * assignment-group fan-out, rubric scoring, lesson progress, submission files,
 * resource versioning, instructor availability, and the signed ICS feed.
 */
declare(strict_types=1);

t_group('lms2 — assignment groups');

$L2_author = mam_db_insert('users', ['name' => 'L2 Author', 'email' => 'l2auth@test.local', 'role' => 'instructor', 'password_hash' => '', 'status' => 'active']);
$L2_s1 = mam_db_insert('users', ['name' => 'L2 S1', 'email' => 'l2s1@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
$L2_s2 = mam_db_insert('users', ['name' => 'L2 S2', 'email' => 'l2s2@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);

$gid = mam_lms2_assignment_group_create(['author_id' => $L2_author, 'title' => 'Scales week 1', 'instructions' => 'Practice majors', 'due_on' => '2026-09-01 00:00:00']);
t_ok($gid > 0, 'group created');
$n = mam_lms2_assignment_group_fanout($gid, [$L2_s1, $L2_s2]);
t_eq($n, 2, 'fan-out created one assignment per student');
t_eq(mam_lms2_assignment_group_fanout($gid, [$L2_s1, $L2_s2]), 0, 'fan-out is idempotent (no duplicates)');
$grp = mam_lms2_assignment_group_list(null, null);
$mine = array_values(array_filter($grp, fn($g) => (int)$g['id'] === $gid))[0];
t_eq((int)$mine['member_count'], 2, 'group reports 2 members');
$a = mam_db_row('SELECT * FROM assignments WHERE group_id = ? AND student_id = ?', [$gid, $L2_s1]);
t_eq($a['title'], 'Scales week 1', 'fanned-out assignment copies the group title');

t_group('lms2 — rubric scoring');

$rid = mam_db_insert('rubrics', ['title' => 'Performance rubric', 'created_by' => $L2_author]);
$c1 = mam_db_insert('rubric_criteria', ['rubric_id' => $rid, 'label' => 'Timing', 'max_points' => 10, 'weight' => 1.0, 'sort_order' => 1]);
$c2 = mam_db_insert('rubric_criteria', ['rubric_id' => $rid, 'label' => 'Tone',   'max_points' => 10, 'weight' => 2.0, 'sort_order' => 2]);

$score = mam_lms2_rubric_score($rid, [$c1 => 8, $c2 => 10]);
t_eq($score['total_points'], 28.0, 'weighted total = 8*1 + 10*2 = 28');
t_eq($score['max_points'], 30.0, 'weighted max = 10*1 + 10*2 = 30');
$overclamp = mam_lms2_rubric_score($rid, [$c1 => 999, $c2 => -5]);
t_eq($overclamp['total_points'], 10.0, 'points clamp to [0,max] (10*1 + 0*2)');

// Persisted review computes + stores the total.
$L2_asg = mam_db_insert('assignments', ['student_id' => $L2_s1, 'title' => 'Recital', 'status' => 'open']);
$L2_sub = mam_db_insert('submissions', ['assignment_id' => $L2_asg, 'student_id' => $L2_s1, 'artifact_url' => 'https://x/y', 'artifact_kind' => 'link']);
$revId = mam_lms2_submission_review_save($L2_sub, $rid, $L2_author, [$c1 => 9, $c2 => 9], 'Good work');
$rev = mam_db_row('SELECT * FROM submission_reviews WHERE id = ?', [$revId]);
t_near((float)$rev['total_points'], 27.0, 'stored review total (9*1 + 9*2)');
t_eq($rev['feedback'], 'Good work', 'review feedback stored');

t_group('lms2 — submission files & resource versions');

$fid = mam_lms2_submission_add_file($L2_sub, 'https://x/file.pdf', 'pdf', 'file.pdf', 1024);
t_ok($fid > 0, 'submission file attached');
t_eq((int)mam_db_row('SELECT COUNT(*) AS c FROM submission_files WHERE submission_id = ?', [$L2_sub])['c'], 1, 'one file on the submission');

$L2_class = mam_db_insert('classes', ['title' => 'L2 Class', 'discipline' => 'piano']);
$resId = mam_db_insert('class_resources', ['class_id' => $L2_class, 'title' => 'Sheet', 'url' => 'https://x/v1']);
$v1 = mam_lms2_resource_version_add($resId, 'https://x/v1', 'Sheet', 'initial', $L2_author);
$v2 = mam_lms2_resource_version_add($resId, 'https://x/v2', 'Sheet', 'fixed typo', $L2_author);
t_eq((int)mam_db_row('SELECT version FROM resource_versions WHERE id = ?', [$v2])['version'], 2, 'versions auto-increment');

t_group('lms2 — lessons & progress');

$L2_cohort = mam_db_insert('cohorts', ['title' => 'L2 Cohort', 'status' => 'active', 'capacity' => 10]);
$les1 = mam_db_insert('lessons', ['cohort_id' => $L2_cohort, 'title' => 'Lesson 1', 'sort_order' => 1, 'published' => 1]);
$les2 = mam_db_insert('lessons', ['cohort_id' => $L2_cohort, 'title' => 'Lesson 2', 'sort_order' => 2, 'published' => 1]);

$p = mam_lms2_lesson_progress_set($les1, $L2_s1, 40);
t_eq((int)$p['percent'], 40, 'progress recorded at 40%');
t_eq($p['status'], 'in_progress', 'partial progress is in_progress');
$p = mam_lms2_lesson_progress_set($les1, $L2_s1, 100);
t_eq($p['status'], 'completed', 'reaching 100% completes the lesson');
t_ok($p['completed_at'] !== null, 'completion timestamp stamped');
// Idempotent upsert — one row per (lesson, student).
t_eq((int)mam_db_row('SELECT COUNT(*) AS c FROM lesson_progress WHERE lesson_id = ? AND student_id = ?', [$les1, $L2_s1])['c'], 1,
     'progress is a single upserted row');
// A later partial update must not wipe the earlier completion timestamp.
$p = mam_lms2_lesson_progress_set($les1, $L2_s1, 50);
t_ok($p['completed_at'] !== null, 'completion timestamp survives a later partial update');

$sum = mam_lms2_lesson_progress_summary($L2_s1, $L2_cohort);
t_eq($sum['total'], 2, 'cohort has 2 published lessons');
t_eq($sum['completed'], 1, 'one lesson completed');
t_eq($sum['percent'], 50.0, 'cohort progress = 50%');

t_group('lms2 — instructor availability');

$L2_instr = mam_db_insert('users', ['name' => 'L2 Instr', 'email' => 'l2instr@test.local', 'role' => 'instructor', 'password_hash' => '', 'status' => 'active']);
// No availability configured → unconstrained.
t_ok(mam_lms2_instructor_available($L2_instr, '2026-09-07 10:00:00', 60), 'no availability rows → unconstrained (true)');
// Configure Monday 09:00–12:00 (2026-09-07 is a Monday).
mam_db_insert('instructor_availability', ['instructor_id' => $L2_instr, 'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'active' => 1]);
t_ok(mam_lms2_instructor_available($L2_instr, '2026-09-07 10:00:00', 60), 'slot inside the window is available');
t_ok(!mam_lms2_instructor_available($L2_instr, '2026-09-07 11:30:00', 60), 'slot spilling past the window is unavailable');
t_ok(!mam_lms2_instructor_available($L2_instr, '2026-09-08 10:00:00', 60), 'a different weekday (Tue) is unavailable');

t_group('lms2 — rooms & ICS calendar feed');

$roomId = mam_lms2_room_create('Studio A', 8, 'Ground floor');
t_ok($roomId > 0, 'room created');
$L2_sess = mam_db_insert('class_sessions', ['cohort_id' => $L2_cohort, 'title' => 'ICS Session', 'scheduled_at' => date('Y-m-d H:i:s', time() + 3 * 86400), 'status' => 'scheduled', 'duration_min' => 90, 'kind' => 'class']);
mam_lms2_session_set_room($L2_sess, $roomId);
t_eq((int)mam_db_row('SELECT room_id FROM class_sessions WHERE id = ?', [$L2_sess])['room_id'], $roomId, 'session room_id set (legacy room text untouched)');

// Enrol a student in the cohort so the feed picks up the session.
mam_db_insert('course_enrollments', ['student_id' => $L2_s2, 'course_id' => (int)(mam_db_row('SELECT id FROM courses LIMIT 1')['id'] ?? 1), 'cohort_id' => $L2_cohort, 'status' => 'active']);
$tok = mam_lms2_calendar_token_mint($L2_s2);
t_ok(strlen($tok) >= 32, 'calendar token minted');
t_eq(mam_lms2_calendar_token_mint($L2_s2), $tok, 'minting again reuses the active token');
$ics = mam_lms2_ics_for_token($tok);
t_ok($ics !== null && strpos($ics, 'BEGIN:VCALENDAR') === 0, 'ICS feed starts with VCALENDAR');
t_ok(strpos($ics, 'SUMMARY:ICS Session') !== false, 'ICS includes the enrolled session');
t_ok(strpos($ics, 'UID:mam-session-' . $L2_sess . '@') !== false, 'ICS event carries a stable UID');
t_ok(mam_lms2_ics_for_token('not-a-real-token') === null, 'an unknown token yields no feed');
t_ok(mam_db_row('SELECT last_used_at FROM calendar_tokens WHERE token = ?', [$tok])['last_used_at'] !== null, 'feed access stamps last_used_at');
