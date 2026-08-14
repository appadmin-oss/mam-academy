<?php
/**
 * Tests for pre-class learning + teach-back (api/lib/preclass.php, PDF §8-9,18-19):
 * module content, the next-class card, preparation status, teach-back submit/
 * resubmit + evidence guards, instructor review + readiness decision, the review
 * queue, and the per-session pre-class roster view.
 */
declare(strict_types=1);

t_group('preclass — module + next-class card');

$PC_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($PC_course === 0) $PC_course = mam_db_insert('courses', ['code' => 'pc_c', 'name' => 'PC', 'kind' => 'core']);
$PC_ins = mam_db_insert('users', ['name' => 'PC Ins', 'email' => 'pc_ins@test.local', 'role' => 'instructor', 'password_hash' => '', 'status' => 'active']);
$PC_stu = mam_db_insert('users', ['name' => 'PC Stu', 'email' => 'pc_stu@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
$PC_coh = mam_db_insert('cohorts', ['course_id' => $PC_course, 'title' => 'PC Cohort', 'status' => 'active', 'capacity' => 10, 'instructor_id' => $PC_ins]);
mam_db_insert('course_enrollments', ['student_id' => $PC_stu, 'course_id' => $PC_course, 'cohort_id' => $PC_coh, 'status' => 'active']);

// Instructor authors the week module.
$PC_les = mam_preclass_lesson_save([
    'cohort_id' => $PC_coh, 'created_by' => $PC_ins, 'title' => 'C Major Scale & Arpeggio',
    'week_no' => 4, 'objectives' => 'Play C major two octaves', 'prep_instructions' => 'Watch the video',
    'resources' => [['kind' => 'video', 'url' => 'https://youtu.be/x', 'title' => 'C major']],
    'teachback_required' => true,
], null);
t_ok($PC_les > 0, 'module created on a lesson');
$lrow = mam_db_row('SELECT week_no, teachback_required FROM lessons WHERE id = ?', [$PC_les]);
t_eq((int) $lrow['week_no'], 4, 'week number stored');
t_eq((int) $lrow['teachback_required'], 1, 'teach-back requirement stored');

// A session that delivers the module.
$PC_sess = mam_db_insert('class_sessions', ['cohort_id' => $PC_coh, 'title' => 'Week 4 class', 'scheduled_at' => date('Y-m-d H:i:s', time() + 2 * 86400), 'status' => 'scheduled', 'duration_min' => 60, 'kind' => 'class', 'lesson_id' => $PC_les]);

$next = mam_preclass_next($PC_stu);
t_ok($next !== null && (int) $next['session']['id'] === $PC_sess, 'next-class card resolves the upcoming session');
t_eq($next['module']['lesson_id'], $PC_les, 'card carries the module');
t_eq(count($next['module']['resources']), 1, 'module resources decoded');
t_eq($next['prep'], 'not_started', 'preparation starts not_started');
t_ok($next['teachback'] === null, 'no teach-back yet');

t_group('preclass — preparation status');

mam_preclass_mark_prepared($PC_les, $PC_stu, true);
$next = mam_preclass_next($PC_stu);
t_eq($next['prep'], 'completed', 'marking prepared shows completed (🟢)');

t_group('preclass — teach-back submit + guards');

$threw = '';
try { mam_preclass_teachback_submit($PC_les, $PC_stu, $PC_coh, 'written', '', ''); } catch (\RuntimeException $e) { $threw = $e->getMessage(); }
t_eq($threw, 'evidence_required', 'a teach-back needs a link or text');
$threw = '';
try { mam_preclass_teachback_submit($PC_les, $PC_stu, $PC_coh, 'video', 'ftp://nope', null); } catch (\RuntimeException $e) { $threw = $e->getMessage(); }
t_eq($threw, 'bad_url', 'a non-http(s)/first-party URL is rejected');

$tb = mam_preclass_teachback_submit($PC_les, $PC_stu, $PC_coh, 'video', 'https://youtu.be/demo', 'Here is my explanation');
t_eq($tb['status'], 'submitted', 'teach-back submitted');
t_eq($tb['kind'], 'video', 'kind stored');
// Resubmit replaces (one row per lesson+student) and resets review.
$tb2 = mam_preclass_teachback_submit($PC_les, $PC_stu, $PC_coh, 'written', null, 'Rewritten');
t_eq((int) mam_db_row('SELECT COUNT(*) c FROM teachbacks WHERE lesson_id = ? AND student_id = ?', [$PC_les, $PC_stu])['c'], 1, 'one teach-back row per (lesson, student)');
t_eq($tb2['body'], 'Rewritten', 'resubmission replaced the evidence');

t_group('preclass — instructor review + queue');

$q = mam_preclass_instructor_queue($PC_ins);
t_eq(count(array_filter($q, fn($r) => (int) $r['id'] === (int) $tb2['id'])), 1, 'submitted teach-back is in the instructor queue');
$rev = mam_preclass_teachback_review((int) $tb2['id'], $PC_ins, 'ready', 6, 'Strong understanding');
t_eq($rev['decision'], 'ready', 'review records the readiness decision');
t_eq((int) $rev['understanding'], 5, 'understanding score clamps to 0–5');
t_eq($rev['status'], 'reviewed', 'teach-back marked reviewed');
t_eq(count(mam_preclass_instructor_queue($PC_ins)), count($q) - 1, 'reviewed teach-back leaves the submitted queue');
$threw = false;
try { mam_preclass_teachback_review((int) $tb2['id'], $PC_ins, 'bogus'); } catch (\InvalidArgumentException $e) { $threw = true; }
t_ok($threw, 'an unknown decision is rejected');

t_group('preclass — per-session roster view');

$view = mam_preclass_session_view($PC_sess);
t_eq($view['lesson_id'], $PC_les, 'session view resolves the module');
$mine = array_values(array_filter($view['roster'], fn($r) => (int) $r['student_id'] === $PC_stu))[0];
t_eq($mine['prep'], 'completed', 'roster shows the student prepared');
t_ok($mine['teachback'] !== null && $mine['teachback']['status'] === 'reviewed', 'roster shows the reviewed teach-back');
