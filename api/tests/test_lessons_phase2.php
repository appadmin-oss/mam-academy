<?php
/**
 * Lessons, phase 2 (v98): notes and feedback, teacher-sent materials,
 * placement and tiers, exams, AI marking, the study helper, deadlines and
 * fines. Each group pins a rule from lessons-learn.php or lesson-exams.php.
 */
declare(strict_types=1);

$L2_now = gmdate('YmdHis') . random_int(100, 999);
$L2_course = (int) mam_db_insert('courses', ['code' => 'l2_' . $L2_now, 'name' => 'Phase Two ' . $L2_now, 'kind' => 'elective', 'active' => 1, 'sort_order' => 99]);
$L2_lv = [];
foreach (mam_db_all('SELECT id, code FROM levels') as $r) $L2_lv[$r['code']] = (int) $r['id'];
$L2_student = (int) mam_db_insert('users', ['email' => "l2.student.$L2_now@test.local", 'name' => 'Kemi Phase', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$L2_peer    = (int) mam_db_insert('users', ['email' => "l2.peer.$L2_now@test.local", 'name' => 'Tobi Phase', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$L2_teacher = (int) mam_db_insert('users', ['email' => "l2.teacher.$L2_now@test.local", 'name' => 'Teacher Phase', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$L2_enrol = (int) mam_db_insert('course_enrollments', ['student_id' => $L2_student, 'course_id' => $L2_course, 'level_id' => $L2_lv['cert'], 'instructor_id' => $L2_teacher, 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $L2_peer, 'course_id' => $L2_course, 'level_id' => $L2_lv['cert'], 'instructor_id' => $L2_teacher, 'status' => 'active']);

/** A publishable lesson: one machine-marked and one written question. */
function l2_lesson(int $course, int $level, int $week, string $title): int {
    $r = mam_lesson_save(null, ['course_id' => $course, 'level_id' => $level, 'week' => $week, 'title' => $title, 'pass_mark' => 50, 'status' => 'published',
        'checks' => [
            ['kind' => 'mcq', 'prompt' => "$title: pick A", 'options' => ['A', 'B'], 'answer' => '0', 'points' => 1],
            ['kind' => 'short', 'prompt' => "$title: explain", 'rubric' => 'Any sensible sentence', 'points' => 1],
        ]]);
    return (int) $r['id'];
}
$L2_pre  = l2_lesson($L2_course, $L2_lv['precert'], 1, 'Pre one');
$L2_c1   = l2_lesson($L2_course, $L2_lv['cert'], 1, 'Cert one');
$L2_c2   = l2_lesson($L2_course, $L2_lv['cert'], 2, 'Cert two');
$L2_c3   = l2_lesson($L2_course, $L2_lv['cert'], 3, 'Cert three');
$L2_c4   = l2_lesson($L2_course, $L2_lv['cert'], 4, 'Cert four');
$L2_c5   = l2_lesson($L2_course, $L2_lv['cert'], 5, 'Cert five');
$L2_pd   = l2_lesson($L2_course, $L2_lv['prediploma'], 1, 'Pre-dip one');
$L2_ids = static fn(int $sid) => array_column(array_merge(...array_map(fn($c) => $c['lessons'],
    array_values(array_filter(mam_lesson_student_board($sid), fn($c) => (int) $c['course_id'] === $GLOBALS['L2_course'])))), 'stage', 'id');

t_eq((string) mam_db_row('SELECT kind FROM modules WHERE id = ?', [$L2_c1])['kind'], 'module', 'a lesson saved without a kind is a module, not an empty string');

t_group('lessons phase 2 — placement decides which lessons are a student\'s, and how each counts');

$L2_map = $L2_ids($L2_student);
t_eq($L2_map[$L2_c1] ?? null, 'current', 'a lesson at the enrolment\'s level is current');
t_eq($L2_map[$L2_pre] ?? null, 'catchup', 'a level below is catch-up, not hidden');
t_ok(!isset($L2_map[$L2_pd]), 'the level above is closed until the one below is mastered');
t_ok(mam_lesson_student_can_open($L2_student, mam_lesson_get($L2_pre)), 'a catch-up lesson can be opened');
t_ok(!mam_lesson_student_can_open($L2_student, mam_lesson_get($L2_pd)), 'a closed one cannot');
foreach ([$L2_c1, $L2_c2, $L2_c3] as $id) mam_lesson_confirm($L2_teacher, $id, $L2_student, true);
t_ok(!isset($L2_ids($L2_student)[$L2_pd]), 'three of five (60%) does not open the next level');
mam_lesson_confirm($L2_teacher, $L2_c4, $L2_student, true);
t_eq($L2_ids($L2_student)[$L2_pd] ?? null, 'early', 'four of five (80%) opens it EARLY — advancing is by mastery, not the calendar');
t_ok(mam_lesson_student_can_open($L2_student, mam_lesson_get($L2_pd)), 'and it can now be opened');
$L2_board = array_values(array_filter(mam_lesson_student_board($L2_student), fn($c) => (int) $c['course_id'] === $L2_course))[0];
t_eq($L2_board['total'], 6, 'an early lesson does not count against the student\'s own total (catch-up + current only)');

$L2_place = mam_lesson_place($L2_teacher, $L2_student, $L2_course, $L2_lv['prediploma'], 'Ready for more');
t_eq($L2_place['to'], $L2_lv['prediploma'], 'a teacher can move a student up a lesson level');
t_eq((int) mam_db_row('SELECT level_id FROM course_enrollments WHERE id = ?', [$L2_enrol])['level_id'], $L2_lv['cert'],
    'placement NEVER moves the enrolment — the class, its capacity and its price stay where they were');
t_eq($L2_ids($L2_student)[$L2_pd] ?? null, 'current', 'the new level is now current');
t_eq($L2_ids($L2_student)[$L2_c5] ?? null, 'catchup', 'and the old level is catch-up');
t_ok(mam_db_row("SELECT 1 FROM certificates WHERE student_id = ? AND kind = 'promotion'", [$L2_student]) !== null, 'moving up issues a promotion certificate');
mam_lesson_place($L2_teacher, $L2_student, $L2_course, $L2_lv['prediploma']);
t_eq((int) mam_db_row("SELECT COUNT(*) n FROM certificates WHERE student_id = ? AND kind = 'promotion'", [$L2_student])['n'], 1, 'placing at the same level again issues nothing');
try { mam_lesson_place($L2_teacher, $L2_peer, $L2_course + 999, $L2_lv['cert']); t_ok(false, 'placing on a course the student is not on is refused'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'not_enrolled', 'placing on a course the student is not on is refused'); }
mam_lesson_place($L2_teacher, $L2_student, $L2_course, $L2_lv['cert'], 'Back to certificate for the test');

t_group('lessons phase 2 — tiers and certificates');

$L2_t = mam_lesson_tier($L2_peer, $L2_course);
t_eq($L2_t['tier'], 0, 'a student who has opened nothing has no tier');
mam_lesson_open($L2_peer, $L2_c1);
t_eq(mam_lesson_tier($L2_peer, $L2_course)['tier'], 1, 'opening a lesson makes them a Starter');
foreach ([$L2_pre, $L2_c1] as $id) mam_lesson_confirm($L2_teacher, $id, $L2_peer, true);
$L2_t = mam_lesson_tier($L2_peer, $L2_course);
t_eq([$L2_t['tier'], $L2_t['name']], [2, 'Apprentice'], 'a quarter of their own lessons done makes them an Apprentice');
t_ok(mam_db_row("SELECT 1 FROM certificates WHERE student_id = ? AND kind = 'course_tier' AND title LIKE ?", [$L2_peer, '%Apprentice']) !== null,
    'reaching a tier through a teacher\'s tick issues its certificate');
mam_lesson_award($L2_peer, $L2_course); mam_lesson_award($L2_peer, $L2_course);
t_eq((int) mam_db_row("SELECT COUNT(*) n FROM certificates WHERE student_id = ? AND kind = 'course_tier'", [$L2_peer])['n'], 1, 'and only once, however often it is checked');
t_ok(!mam_lesson_tier($L2_peer, $L2_course)['trial_open'], 'the course trial is closed below Master');
t_eq(MAM_LESSON_TIERS[6], 'Grand Master', 'the top tier has a name fit for a children\'s academy');

t_group('lessons phase 2 — private notes, saved for later, feedback without names');

mam_lesson_note_save($L2_student, $L2_c1, '  my own words  ');
t_eq(mam_lesson_open($L2_student, $L2_c1)['note'], 'my own words', 'a note is kept, trimmed, and comes back when the lesson opens');
t_eq((int) mam_lesson_note_row($L2_student, $L2_c1)['opens'], 1, 'opening the lesson is counted');
mam_lesson_save_for_later($L2_student, $L2_c1, true);
t_ok(mam_lesson_open($L2_student, $L2_c1)['saved'], 'saved for later sticks');
t_ok(!str_contains(json_encode(mam_lesson_marking_queue(null)), 'my own words'), 'a private note never reaches a teacher\'s queue');
try { mam_lesson_feedback_set($L2_student, $L2_c1, 'meh'); t_ok(false, 'an unknown feeling is refused'); }
catch (InvalidArgumentException $e) { t_ok(str_contains($e->getMessage(), 'got it, mostly or lost'), 'an unknown feeling is refused with the three choices named'); }
mam_lesson_feedback_set($L2_student, $L2_c1, 'lost', 'too fast');
mam_lesson_feedback_set($L2_student, $L2_c1, 'mostly');
t_eq(mam_lesson_feedback_mine($L2_student, $L2_c1)['feel'], 'mostly', 'answering again changes the one answer, it does not add a second');
$L2_fb = array_values(array_filter(mam_lesson_feedback_board(), fn($r) => (int) $r['module_id'] === $L2_c1))[0] ?? null;
t_eq($L2_fb['n'] ?? null, 1, 'the console counts it');
t_ok(!str_contains(json_encode(mam_lesson_feedback_board()), 'Kemi Phase') && !array_key_exists('student_id', $L2_fb),
    'and never says which child said it');
t_ok(!$L2_fb['flagged'], 'one answer flags nothing — a flag needs at least five');

t_group('lessons phase 2 — material a teacher sends waits for the office');

try { mam_lesson_material_submit($L2_teacher, $L2_c1, ['title' => 'Notes', 'url' => 'http://insecure.example']); t_ok(false, 'a plain-http link is refused'); }
catch (InvalidArgumentException $e) { t_ok(str_contains($e->getMessage(), 'https://'), 'a plain-http link is refused'); }
$L2_mat = mam_lesson_material_submit($L2_teacher, $L2_c1, ['title' => 'Extra scales', 'url' => 'https://example.com/scales.pdf', 'kind' => 'pdf']);
t_ok(!in_array('Extra scales', array_column(mam_lesson_open($L2_student, $L2_c1)['materials'], 'title'), true), 'a pending material does not reach the student');
t_ok(in_array($L2_mat, array_map('intval', array_column(mam_lesson_materials_pending(), 'id')), true), 'it is on the office\'s queue');
$L2_l = mam_lesson_get($L2_c1, true);
mam_lesson_save($L2_c1, ['course_id' => $L2_course, 'level_id' => $L2_lv['cert'], 'week' => 1, 'title' => 'Cert one', 'pass_mark' => 50, 'status' => 'published',
    'checks' => $L2_l['checks'], 'materials' => []]);
t_ok(mam_db_row('SELECT 1 FROM module_materials WHERE id = ?', [$L2_mat]) !== null, 'saving the lesson in the editor does not delete a material it never showed');
try { mam_lesson_material_review(1, $L2_mat, false, ''); t_ok(false, 'a refusal without a reason is refused'); }
catch (InvalidArgumentException $e) { t_ok(str_contains($e->getMessage(), 'Say why'), 'a refusal needs a reason — "rejected" alone teaches nothing'); }
mam_lesson_material_review(1, $L2_mat, true);
t_ok(in_array('Extra scales', array_column(mam_lesson_open($L2_student, $L2_c1)['materials'], 'title'), true), 'once approved, the student sees it');
try { mam_lesson_material_review(1, $L2_mat, true); t_ok(false, 'a material is reviewed once'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'not_pending', 'a material is reviewed once'); }

t_group('lessons phase 2 — AI marking is off, unsure, or held; never evidence');
$L2_prevAi = ($r = mam_db_row("SELECT svalue FROM settings WHERE skey = 'ai_provider'")) ? (string) $r['svalue'] : null;

$L2_short = ['kind' => 'short', 'prompt' => 'Why?', 'rubric' => 'Ratio', 'points' => 4];
t_eq(mam_lesson_ai_mark($L2_short, 'Because of the ratio', $L2_student), null, 'with AI marking off, a written answer waits for a teacher');
mam_setting_set('lesson_ai_marking', '1'); mam_setting_set('ai_provider', 'mock');
t_eq(mam_lesson_ai_mark($L2_short, 'Because of the ratio', $L2_student), null,
    'switched on, an answer the model is unsure of (confidence below ' . MAM_AI_MARK_MIN_CONFIDENCE . ') still waits for a teacher');
t_eq(mam_lesson_ai_mark(['kind' => 'evidence', 'points' => 2], 'https://example.com/me-playing.mp4', $L2_student), null,
    'a recording of a child playing is never sent to a model');
$L2_held = mam_lesson_ai_mark($L2_short, 'I cannot practise because he hits me at home', $L2_student, 77);
t_ok(($L2_held['hold'] ?? false) === true && $L2_held['awarded'] === null, 'words that read as a child at risk are HELD for a person, not marked');
mam_setting_set('lesson_ai_marking', '0');

$L2_sub = mam_lesson_submit($L2_peer, $L2_c2, [(string) mam_lesson_get($L2_c2, true)['checks'][0]['id'] => '0',
    (string) mam_lesson_get($L2_c2, true)['checks'][1]['id'] => 'A full sentence']);
$L2_pid = (int) mam_db_row('SELECT id FROM module_progress WHERE module_id = ? AND student_id = ?', [$L2_c2, $L2_peer])['id'];
$L2_qid = (string) mam_lesson_get($L2_c2, true)['checks'][1]['id'];
$L2_mk = json_decode((string) mam_db_row('SELECT marks FROM module_progress WHERE id = ?', [$L2_pid])['marks'], true);
$L2_mk[$L2_qid] = ['awarded' => 0.0, 'by' => 'ai', 'comment' => 'Too short'];
mam_db_update('module_progress', ['marks' => json_encode($L2_mk), 'pending' => 0], ['id' => $L2_pid]);
$L2_q = array_values(array_filter(mam_lesson_marking_queue($L2_teacher), fn($r) => $r['progress_id'] === $L2_pid));
t_ok($L2_q && $L2_q[0]['items'][0]['ai_awarded'] !== null && (float) $L2_q[0]['items'][0]['ai_awarded'] === 0.0, 'an answer the assistant marked stays on the teacher\'s queue, with its mark shown');
mam_lesson_mark($L2_teacher, $L2_pid, [$L2_qid => 1]);
$L2_mk = json_decode((string) mam_db_row('SELECT marks FROM module_progress WHERE id = ?', [$L2_pid])['marks'], true);
t_eq([$L2_mk[$L2_qid]['awarded'], $L2_mk[$L2_qid]['by']], [1.0, 'teacher'], 'and the teacher can change it — a mark the assistant gave is never final');
t_ok(!array_filter(mam_lesson_marking_queue($L2_teacher), fn($r) => $r['progress_id'] === $L2_pid), 'once a person has marked it, it leaves the queue');

t_group('lessons phase 2 — exams: pledged, server-timed, shuffled, appealable once');

try { mam_exam_start_session($L2_student, $L2_c3, false); t_ok(false, 'an exam needs the pledge'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'pledge_required', 'an exam does not start without the pledge that the work is the student\'s own'); }
try { mam_exam_start_session($L2_student, $L2_pd + 99999, true); t_ok(false, 'an exam on a lesson that is not theirs is refused'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'not_yours', 'an exam on a lesson that is not theirs is refused'); }
$L2_ex = mam_exam_start_session($L2_student, $L2_c3, true);
t_eq(mam_exam_start_session($L2_student, $L2_c5, true), $L2_ex, 'asking again returns the OPEN sitting — one clock at a time');
$L2_v = mam_exam_view($L2_student, $L2_ex);
t_ok(count($L2_v['items']) > 2, 'a lesson exam carries spaced-recall questions from lessons already finished');
t_ok(!str_contains(json_encode($L2_v), '"answer"') && !str_contains(json_encode($L2_v), 'rubric'), 'the student\'s copy carries no answers and no marking guide');
t_ok($L2_v['seconds_left'] > 0 && $L2_v['seconds_left'] <= $L2_v['time_limit_sec'], 'the time left is the SERVER\'s clock');
t_eq(mam_exam_view($L2_student, $L2_ex)['items'], $L2_v['items'], 'a refresh shows the same exam in the same order');
try { mam_exam_view($L2_peer, $L2_ex); t_ok(false, 'another student cannot see the sitting'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'not_found', 'another student cannot see the sitting'); }
$L2_ans = [];
foreach ($L2_v['items'] as $it) $L2_ans[(string) $it['check_id']] = $it['kind'] === 'mcq' ? 0 : ($it['kind'] === 'order' ? array_column($it['options'], 'i') : 'My answer');
mam_exam_save($L2_student, $L2_ex, $L2_ans);
mam_db_update('lesson_sittings', ['deadline' => gmdate('Y-m-d H:i:s', time() - 3600)], ['id' => $L2_ex]);
try { mam_exam_save($L2_student, $L2_ex, []); t_ok(false, 'nothing is saved after the deadline'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'time_up', 'nothing more is accepted after the deadline and its grace'); }
$L2_done = mam_exam_view($L2_student, $L2_ex);
t_eq($L2_done['status'], 'submitted', 'an expired sitting is handed in with what was saved — a closed laptop buys no more time');
t_ok($L2_done['result']['pending'], 'written answers wait for a teacher');
t_ok(in_array($L2_ex, array_column(mam_exam_teacher_queue($L2_teacher), 'sitting_id'), true), 'and the sitting is on the teacher\'s exam queue');
t_ok(!in_array($L2_ex, array_column(mam_exam_teacher_queue((int) mam_db_insert('users', ['email' => "l2.x.$L2_now@test.local", 'name' => 'X', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active'])), 'sitting_id'), true),
    'but not on a teacher who does not teach them');
$L2_aw = [];
foreach (mam_exam_teacher_queue($L2_teacher) as $s) if ($s['sitting_id'] === $L2_ex) foreach ($s['items'] as $it) $L2_aw[(string) $it['check_id']] = $it['points'];
try { mam_exam_appeal($L2_student, $L2_ex, 'It was unfair to me'); t_ok(false, 'no appeal before marking'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'not_marked', 'a result cannot be appealed before it is marked'); }
mam_exam_teacher_mark($L2_teacher, $L2_ex, $L2_aw);
$L2_done = mam_exam_view($L2_student, $L2_ex);
t_ok($L2_done['status'] === 'marked' && $L2_done['result']['band'] !== null, 'marked, the sitting carries a percentage and a band');
try { mam_exam_appeal($L2_student, $L2_ex, 'short'); t_ok(false, 'an appeal needs a reason'); }
catch (InvalidArgumentException $e) { t_ok(true, 'an appeal needs a reason a teacher can answer'); }
mam_exam_appeal($L2_student, $L2_ex, 'Question two was marked against the wrong guide');
try { mam_exam_appeal($L2_student, $L2_ex, 'And again, please look'); t_ok(false, 'one appeal per result'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'already_appealed', 'one appeal per result'); }
try { mam_exam_teacher_mark($L2_teacher, $L2_ex, [], [], '   '); t_ok(false, 'a blank answer to an appeal is refused'); }
catch (InvalidArgumentException $e) { t_ok(str_contains($e->getMessage(), 'upheld or not'), 'a blank answer to an appeal is refused — upheld or not, it is answered in a sentence'); }
t_ok(mam_db_row('SELECT appeal_outcome FROM lesson_sittings WHERE id = ?', [$L2_ex])['appeal_outcome'] === null, '…so the appeal stays open');
mam_exam_teacher_mark($L2_teacher, $L2_ex, [], [], 'Looked again: the mark stands.');
t_eq(mam_exam_view($L2_student, $L2_ex)['result']['appeal']['outcome'], 'Looked again: the mark stands.', 'the student reads the teacher\'s answer');
try { mam_lms_delete_module($L2_c3); t_ok(false, 'a lesson somebody sat an exam on cannot be deleted'); }
catch (Throwable $e) { t_ok(str_contains($e->getMessage(), 'has_progress'), 'a lesson somebody sat an exam on cannot be deleted — it is retired'); }
try { mam_exam_start_trial($L2_student, $L2_course, true); t_ok(false, 'the trial is closed below Master'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'not_open', 'the course trial is closed below the Master tier'); }

t_group('lessons phase 2 — the study helper');

try { mam_lesson_helper_ask($L2_student, $L2_c1, 'What is a fifth?'); t_ok(false, 'the helper is off by default'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'helper_off', 'the study helper is off until the academy switches it on'); }
mam_setting_set('lesson_helper_enabled', '1');
$L2_h = mam_lesson_helper_ask($L2_student, $L2_c1, 'What is a fifth?');
t_ok($L2_h['answer'] !== '' && $L2_h['left'] === MAM_HELPER_DAILY - 1, 'switched on, it answers and counts the question against the day');
$L2_h = mam_lesson_helper_ask($L2_student, $L2_c1, 'my dad hurts me when I play wrong');
t_ok($L2_h['held'] && str_contains($L2_h['answer'], 'adult you trust'), 'a question that reads as a child at risk is answered by pointing to a person');
t_eq($L2_h['left'], MAM_HELPER_DAILY - 1, '…is never sent to a model, and costs nothing');
try { mam_lesson_helper_ask($L2_peer, $L2_pd, 'Help'); t_ok(false, 'only about a lesson they may open'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'not_yours', 'the helper answers only about a lesson the student may open'); }
$L2_ex2 = mam_exam_start_session($L2_student, $L2_c5, true);
try { mam_lesson_helper_ask($L2_student, $L2_c1, 'Quick hint?'); t_ok(false, 'no helper during an exam'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'exam_open', 'the helper is off while an exam is open'); }
mam_exam_submit($L2_student, $L2_ex2, []);
mam_setting_set('lesson_helper_enabled', '0');
// Put the provider back exactly as it was: a later test reads it.
if ($L2_prevAi === null) {
    mam_db()->prepare("DELETE FROM settings WHERE skey = 'ai_provider'")->execute();
    $L2_cache = &mam_settings_cache(); if (is_array($L2_cache)) unset($L2_cache['ai_provider']); unset($L2_cache);
} else mam_setting_set('ai_provider', $L2_prevAi);

t_group('lessons phase 2 — deadlines: reminders, and a fine only when switched on');

mam_setting_set('lesson_term_start', gmdate('Y-m-d'));
t_eq(mam_lesson_due_run(gmdate('Y-m-d'), $L2_student)['reminded'], 0, 'nothing is chased before it is nearly due');
$L2_r = mam_lesson_due_run('2099-01-01', $L2_student);
t_eq([$L2_r['reminded'], $L2_r['fined']], [1, 0], 'overdue lessons send ONE reminder per student — and no fine while fines are off');
t_eq(mam_lesson_due_run('2099-01-01', $L2_student)['reminded'], 0, 'a second run in the same period reminds nobody again');
$L2_rem = array_map('intval', array_column(mam_db_all("SELECT module_id FROM lesson_deadline_log WHERE student_id = ? AND kind = 'reminder'", [$L2_student]), 'module_id'));
t_ok(in_array($L2_c5, $L2_rem, true) && !in_array($L2_pre, $L2_rem, true), 'a lesson at their own level is due; a catch-up lesson never is');
t_ok(!in_array($L2_c1, $L2_rem, true), 'a lesson already done is not chased');
mam_db_update('lesson_placements', ['placed_at' => '2098-12-31 00:00:00'], ['student_id' => $L2_student, 'course_id' => $L2_course]);
t_eq(mam_lesson_clock_start($L2_student, $L2_course, gmdate('Y-m-d H:i:s')), '2098-12-31 00:00:00', 'a placement restarts the clock: the later of enrolment and placement');
mam_db_update('lesson_placements', ['placed_at' => gmdate('Y-m-d H:i:s')], ['student_id' => $L2_student, 'course_id' => $L2_course]);
$L2_row = array_values(array_filter(array_merge(...array_map(fn($c) => $c['lessons'], mam_lesson_student_board($L2_student))), fn($l) => $l['id'] === $L2_c5))[0];
t_ok($L2_row['late'] === false && $L2_row['due'] > gmdate('Y-m-d'), 'so a student placed today is not overdue on that level\'s lessons — week 5 is due five weeks from today');
mam_db_update('lesson_placements', ['placed_at' => gmdate('Y-m-d H:i:s', time() - 400 * 86400)], ['student_id' => $L2_student, 'course_id' => $L2_course]);
mam_setting_set('lesson_fines_enabled', '1'); mam_setting_set('lesson_fine_usd', '2');
$L2_r = mam_lesson_due_run('2099-01-01', $L2_student);
$L2_ch = mam_db_all("SELECT * FROM accountability_charges WHERE subject_id = ? AND reason = 'lesson_overdue'", [$L2_student]);
t_ok($L2_r['fined'] >= 1 && count($L2_ch) === $L2_r['fined'], 'switched on, an overdue lesson is fined through the accountability ledger');
t_eq((int) $L2_ch[0]['amount_usd_cents'], 200, 'at the configured amount, in USD cents');
t_eq($L2_ch[0]['status'], 'outstanding', 'as an ordinary charge the student can ask to have waived');
mam_lesson_due_run('2099-02-01', $L2_student);
t_eq((int) mam_db_row("SELECT COUNT(*) n FROM accountability_charges WHERE subject_id = ? AND reason = 'lesson_overdue'", [$L2_student])['n'], count($L2_ch),
    'a lesson is fined ONCE, ever — not again every run');
mam_setting_set('lesson_fines_enabled', '0');
mam_automation_set('lesson_deadlines', false);
t_eq(mam_lesson_due_run('2099-03-01', $L2_student)['skipped'], 'automation off', 'with the automation switched off the run does nothing');
mam_automation_set('lesson_deadlines', true);
mam_setting_set('lesson_term_start', '');

t_group('lessons phase 2 — wiring');

$L2_src = static fn(string $p) => (string) file_get_contents(dirname(__DIR__, 2) . '/' . $p);
t_ok(str_contains($L2_src('api/tools/run-jobs.php'), 'mam_lesson_due_run'), 'the deadline run is in the cron');
t_ok(isset(mam_automations_registry()['lesson_deadlines']), 'and it is an automation the console can switch off');
t_ok((int) mam_setting_num('lesson_fines_enabled', 0) === 0, 'fines ship OFF — a money penalty on a child is the academy\'s decision (gotcha #11)');
t_ok(str_contains($L2_src('api/lib/lesson-exams.php'), 'if (!in_array($check[\'kind\'] ?? \'\', [\'short\', \'task\', \'reflection\'], true)) return null;'),
    'AI marking is an ALLOW-list of written kinds, so a new kind of question is never sent to a model by default');

/* Cleanup: the shared test database keeps its seeded catalogue count. */
foreach (mam_db_all('SELECT id FROM modules WHERE course_id = ?', [$L2_course]) as $r) {
    foreach (['module_progress', 'module_checks', 'module_materials', 'lesson_notes', 'lesson_feedback', 'lesson_deadline_log'] as $t) mam_db()->prepare("DELETE FROM $t WHERE module_id = ?")->execute([(int) $r['id']]);
}
foreach (['lesson_sittings', 'lesson_placements'] as $t) mam_db()->prepare("DELETE FROM $t WHERE course_id = ?")->execute([$L2_course]);
mam_db()->prepare('DELETE FROM modules WHERE course_id = ?')->execute([$L2_course]);
mam_db()->prepare('DELETE FROM course_enrollments WHERE course_id = ?')->execute([$L2_course]);
mam_db()->prepare('DELETE FROM courses WHERE id = ?')->execute([$L2_course]);
