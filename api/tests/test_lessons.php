<?php
/**
 * Lessons — questions, materials and per-student progress (v97, ported from
 * NGG's curriculum). Each group pins one of the rules lessons.php's docblock
 * lists, because each is a bug NGG shipped before it had the rule.
 */
declare(strict_types=1);

$LS_now = gmdate('YmdHis') . random_int(100, 999);
$LS_course = (int) mam_db_insert('courses', ['code' => 'lsn_' . $LS_now, 'name' => 'Lesson Theory ' . $LS_now, 'kind' => 'elective', 'active' => 1, 'sort_order' => 99]);
$LS_level  = (int) mam_db_row("SELECT id FROM levels WHERE code = 'cert'")['id'];
$LS_student = (int) mam_db_insert('users', ['email' => "lsn.student.$LS_now@test.local", 'name' => 'Ada Lesson', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$LS_other   = (int) mam_db_insert('users', ['email' => "lsn.other.$LS_now@test.local", 'name' => 'Bo Elsewhere', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$LS_teacher = (int) mam_db_insert('users', ['email' => "lsn.teacher.$LS_now@test.local", 'name' => 'Tutor Lesson', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$LS_stranger = (int) mam_db_insert('users', ['email' => "lsn.stranger.$LS_now@test.local", 'name' => 'Not Theirs', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $LS_student, 'course_id' => $LS_course, 'level_id' => $LS_level, 'instructor_id' => $LS_teacher, 'status' => 'active']);

$LS_lesson = [
    'course_id' => $LS_course, 'level_id' => $LS_level, 'week' => 1, 'title' => 'Intervals', 'summary' => 'Hearing the distance between notes',
    'objectives' => "Name a major third\nSing a perfect fifth", 'pass_mark' => 60, 'status' => 'draft',
    'checks' => [
        ['kind' => 'mcq', 'prompt' => 'C to E is a…', 'options' => ['major third', 'minor third', 'fifth'], 'answer' => '0',
         'whys' => ['', 'C to E-flat would be minor', 'A fifth is C to G'], 'points' => 2],
        ['kind' => 'order', 'prompt' => 'Order these from lowest', 'options' => ['C', 'E', 'G', 'B'], 'points' => 2],
        ['kind' => 'short', 'prompt' => 'Why does a fifth sound stable?', 'rubric' => 'Mentions simple ratio 3:2', 'points' => 4],
    ],
    'materials' => [['kind' => 'video', 'title' => 'Intervals explained', 'url' => 'https://example.com/v', 'guide' => 'Watch before the class']],
];

t_group('lessons — what may be published');

t_ok(str_contains(mam_lesson_publish_refusal([], null) ?? '', 'without questions'), 'a lesson with no questions cannot be published');
t_ok(str_contains(mam_lesson_publish_refusal([['kind' => 'mcq'], ['kind' => 'order']], null) ?? '', 'teacher marks'),
    'nor one whose questions are all machine-marked — finishing it by guessing is not learning it');
t_eq(mam_lesson_publish_refusal([['kind' => 'mcq'], ['kind' => 'short']], null), null, 'one question a teacher marks is enough');
$LS_bad = $LS_lesson; $LS_bad['checks'][0]['answer'] = '7';
try { mam_lesson_save(null, $LS_bad); t_ok(false, 'an mcq answer that is not one of its options is refused'); }
catch (InvalidArgumentException $e) { t_ok(str_contains($e->getMessage(), 'mark which option is right'), 'an mcq answer that is not one of its options is refused'); }
$LS_bad = $LS_lesson; $LS_bad['materials'][0]['url'] = 'http://example.com/v';
try { mam_lesson_save(null, $LS_bad); t_ok(false, 'a plain-http material is refused'); }
catch (InvalidArgumentException $e) { t_ok(str_contains($e->getMessage(), 'https://'), 'a plain-http material is refused'); }

$LS_saved = mam_lesson_save(null, $LS_lesson, $LS_teacher);
$LS_id = $LS_saved['id'];
t_ok($LS_id > 0 && $LS_saved['version'] === 1, 'a valid lesson saves as version 1');
$LS_again = $LS_lesson; $LS_again['title'] = 'Second lesson for the same week';
try { mam_lesson_save(null, $LS_again); t_ok(false, 'a second lesson for the same course, level and week is refused'); }
catch (InvalidArgumentException $e) { t_ok(str_contains($e->getMessage(), 'already a lesson'), 'a second lesson for the same course, level and week is refused'); }

t_group('lessons — a draft reaches nobody');

try { mam_lesson_open($LS_student, $LS_id); t_ok(false, 'a draft cannot be opened'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'not_yours', 'a draft cannot be opened, even by an enrolled student'); }
t_eq(count(mam_lesson_student_board($LS_student)[0]['lessons'] ?? []), 0, 'and is not on their board');
$LS_pub = mam_lesson_set_status([$LS_id], 'published', $LS_teacher);
t_eq($LS_pub['applied'], 1, 'publishing it applies');
try { mam_lesson_open($LS_other, $LS_id); t_ok(false, 'a student not on the course cannot open it'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'not_yours', 'a student not on the course cannot open it'); }

t_group('lessons — a student opens a lesson and never sees the answers');

$LS_open = mam_lesson_open($LS_student, $LS_id);
t_eq($LS_open['progress']['state'], 'started', 'opening a lesson starts it');
mam_lesson_open($LS_student, $LS_id);
t_eq((int) mam_db_row('SELECT COUNT(*) n FROM module_progress WHERE module_id = ? AND student_id = ?', [$LS_id, $LS_student])['n'], 1, 'opening it twice is still one progress row');
$LS_json = json_encode($LS_open);
t_ok(!str_contains($LS_json, 'Mentions simple ratio') && !str_contains($LS_json, 'C to E-flat would be minor'), 'the student copy carries no rubric and no "why this is tempting"');
t_ok(!array_key_exists('answer', $LS_open['checks'][0]), 'and no answer');
$LS_shuf = array_column($LS_open['checks'][1]['options'], 'i');
t_ok($LS_shuf !== [0, 1, 2, 3], 'put-in-order steps are never shown in the right order');
t_eq(array_column(mam_lesson_open($LS_student, $LS_id)['checks'][1]['options'], 'i'), $LS_shuf, 'and the shuffle is stable, so a refresh does not reshuffle');
t_eq(count($LS_open['materials']), 1, 'the materials come with it');

t_group('lessons — answering: machine marks, people mark, reveal after three');

try { mam_lesson_submit($LS_student, $LS_id, []); t_ok(false, 'an attempt with no answers is refused'); }
catch (InvalidArgumentException $e) { t_ok(str_contains($e->getMessage(), 'Answer every question'), 'an attempt with a question unanswered stores nothing'); }
t_eq((int) mam_db_row('SELECT attempts FROM module_progress WHERE module_id = ? AND student_id = ?', [$LS_id, $LS_student])['attempts'], 0, '…and counts no attempt');

[$LS_c1, $LS_c2, $LS_c3] = array_column(mam_lesson_get($LS_id, true)['checks'], 'id');
$LS_r = mam_lesson_submit($LS_student, $LS_id, [$LS_c1 => 1, $LS_c2 => [0, 2, 1, 3], $LS_c3 => 'Because it is in tune']);
t_ok($LS_r['pending'], 'a written answer leaves the attempt waiting for a teacher');
t_eq($LS_r['progress']['state'], 'submitted', '…so it reads as submitted, not failed');
$LS_items = array_column($LS_r['review']['items'], null, 'check_id');
t_eq($LS_items[$LS_c1]['right'], false, 'a wrong multiple choice is wrong');
t_eq($LS_items[$LS_c1]['slip'], 'C to E-flat would be minor', '…and says why that option was tempting');
t_ok(!isset($LS_items[$LS_c1]['answer']), 'but the right answer is not revealed after one try');
t_ok($LS_items[$LS_c2]['awarded'] > 0 && $LS_items[$LS_c2]['awarded'] < 2, 'a nearly-right order earns part of its points, never all');
t_eq(mam_lesson_order_points([0, 1, 2, 3], 4, 2), 2.0, 'only the exact order earns full points');
t_eq($LS_items[$LS_c3]['pending'], true, 'and the written answer is waiting, not scored by the machine');

mam_lesson_submit($LS_student, $LS_id, [$LS_c1 => 2, $LS_c2 => [1, 0, 2, 3], $LS_c3 => 'try two']);
$LS_r3 = mam_lesson_submit($LS_student, $LS_id, [$LS_c1 => 2, $LS_c2 => [1, 0, 2, 3], $LS_c3 => 'try three']);
t_ok($LS_r3['review']['revealed'] && array_column($LS_r3['review']['items'], null, 'check_id')[$LS_c1]['answer'] === 0,
    'after ' . MAM_LESSON_REVEAL_AFTER . ' tries the right answers are shown');

t_group('lessons — a teacher marks the written answer; completion sticks');

$LS_q = mam_lesson_marking_queue($LS_teacher);
$LS_mine = array_values(array_filter($LS_q, fn($r) => $r['lesson_id'] === $LS_id));
t_eq(count($LS_mine), 1, 'the attempt is in its teacher\'s marking queue');
t_eq(array_column($LS_mine[0]['items'], 'check_id'), [$LS_c3], '…showing only the question a person marks');
t_eq(count(array_filter(mam_lesson_marking_queue($LS_stranger), fn($r) => $r['lesson_id'] === $LS_id)), 0, 'and in no other teacher\'s');
t_ok(mam_lesson_instructor_teaches($LS_teacher, $LS_student, $LS_course) && !mam_lesson_instructor_teaches($LS_stranger, $LS_student, $LS_course),
    'teaching is decided by the enrolment, not by being an instructor');
// Fix the machine-marked part first, then mark the written one well.
$LS_r4 = mam_lesson_submit($LS_student, $LS_id, [$LS_c1 => 0, $LS_c2 => [0, 1, 2, 3], $LS_c3 => 'A 3:2 ratio is simple, so it is stable']);
$LS_pid = (int) mam_db_row('SELECT id FROM module_progress WHERE module_id = ? AND student_id = ?', [$LS_id, $LS_student])['id'];
$LS_m = mam_lesson_mark($LS_teacher, $LS_pid, [$LS_c3 => 99, $LS_c1 => 0], [$LS_c3 => 'Exactly right']);
t_eq($LS_m['status'], 'completed', 'marking the last written answer completes a lesson over the pass mark');
$LS_row = mam_db_row('SELECT * FROM module_progress WHERE id = ?', [$LS_pid]);
t_eq(json_decode($LS_row['marks'], true)[(string) $LS_c3]['awarded'], 4, 'a mark is clamped to the question\'s worth');
t_eq(json_decode($LS_row['marks'], true)[(string) $LS_c1]['awarded'], 2, 'and a machine-marked question is not the teacher\'s to change');
t_ok(mam_db_row("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'lesson.marked'", [$LS_student]) !== null, 'the student is told it was marked');
mam_lesson_submit($LS_student, $LS_id, [$LS_c1 => 1, $LS_c2 => [3, 2, 1, 0], $LS_c3 => 'worse']);
t_eq(mam_db_row('SELECT status FROM module_progress WHERE id = ?', [$LS_pid])['status'], 'completed', 'a later, worse attempt never un-completes a lesson');

t_group('lessons — versions, ticks, and a started lesson is never deleted');

$LS_edit = $LS_lesson; $LS_edit['status'] = 'published';
$LS_edit['checks'] = mam_lesson_get($LS_id, true)['checks'];
$LS_edit['checks'][2]['rubric'] = 'Reworded rubric';
t_eq(mam_lesson_save($LS_id, $LS_edit)['version'], 1, 'rewording a rubric does not bump the version');
$LS_edit['checks'][0]['prompt'] = 'From C up to E is a…';
t_eq(mam_lesson_save($LS_id, $LS_edit)['version'], 2, 'changing what was asked, after somebody answered, does');
t_eq(array_column(mam_lesson_get($LS_id, true)['checks'], 'id'), [$LS_c1, $LS_c2, $LS_c3], 'and the questions keep their ids, so stored answers still point at them');

try { mam_lms_delete_module($LS_id); t_ok(false, 'a started lesson cannot be deleted'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'has_progress', 'a lesson somebody started cannot be deleted — only retired'); }

$LS_w2 = $LS_lesson; $LS_w2['week'] = 2; $LS_w2['title'] = 'Chords';
$LS_id2 = mam_lesson_save(null, $LS_w2)['id'];
mam_lesson_set_status([$LS_id2], 'published');
t_eq(mam_lesson_confirm($LS_teacher, $LS_id2, $LS_student, true), 'ticked', 'a teacher can tick a lesson done that happened in the room');
t_eq(mam_lesson_confirm($LS_teacher, $LS_id2, $LS_student, false), 'unticked', '…and take the tick back');
try { mam_lesson_confirm($LS_teacher, $LS_id, $LS_student, false); t_ok(false, 'a lesson done online cannot be unticked'); }
catch (RuntimeException $e) { t_eq($e->getMessage(), 'done_online', 'but a lesson the student did online cannot be unticked'); }

$LS_board = mam_lesson_student_board($LS_student);
$LS_course_row = array_values(array_filter($LS_board, fn($c) => $c['course_code'] === 'lsn_' . $LS_now))[0];
t_eq([$LS_course_row['done'], $LS_course_row['total']], [1, 2], 'the student\'s board counts what is done out of what is published');
$LS_report = array_values(array_filter(mam_lesson_course_report(), fn($r) => $r['id'] === $LS_course))[0];
t_eq([$LS_report['published'], $LS_report['students'], $LS_report['completions']], [2, 1, 1], 'and the console report agrees');

$LS_out = mam_lms_course_outline('lsn_' . $LS_now, 'cert');
mam_lesson_set_status([$LS_id2], 'retired');
$LS_out2 = mam_lms_course_outline('lsn_' . $LS_now, 'cert');
t_ok(count(array_filter($LS_out['weeks'], fn($w) => $w['authored'])) - count(array_filter($LS_out2['weeks'], fn($w) => $w['authored'])) === 1,
    'a retired lesson drops out of the public outline');

t_group('lessons — wired to the people who use them');

$LS_root = dirname(__DIR__, 2);
$LS_idx = (string) file_get_contents($LS_root . '/api/index.php');
t_ok(str_contains($LS_idx, "\$r->post('/lessons',             \$f('student/lessons.php'),             [mw_csrf(), mw_role('student')"),
    'a student answers through a CSRF-checked, student-only route');
t_ok(str_contains($LS_idx, "\$r->post('/lessons',            \$f('instructor/lessons.php'),            [mw_csrf(), mw_role('instructor', 'admin')"),
    'a teacher marks and ticks through a CSRF-checked instructor route');
$LS_iroute = (string) file_get_contents($LS_root . '/api/routes/instructor/lessons.php');
t_ok(substr_count($LS_iroute, 'mam_lesson_instructor_teaches(') >= 2, '…which checks the teacher teaches that student, for marking and for ticking alike');
t_ok(array_key_exists('lessons', mam_sportal_learn($LS_student)), 'the student\'s Learn screen carries their lessons in its one payload');
t_ok(array_key_exists('lessons', mam_iportal_marking($LS_teacher)), 'and the teacher\'s Marking screen carries the answers waiting for them');
$LS_adm = (string) file_get_contents($LS_root . '/admin/index.php');
t_ok(str_contains($LS_adm, "'lesson_save' => 'curriculum', 'lesson_status' => 'curriculum',"), 'the console\'s lesson writes sit behind the curriculum capability');
// "Why it is tempting" is keyed by option NUMBER: by line position, a browser
// dropping a textarea's leading blank line moved every reason onto the wrong option.
t_ok(str_contains($LS_adm, "preg_match('/^\\s*(\\d{1,2})\\s*[:.)\\-]\\s*(.+)$/u', \$line, \$wm)"), 'the editor reads each reason by its option number, not its line');
$LS_cur = mam_console_curriculum(['course' => $LS_course, 'lesson' => (string) $LS_id]);
t_eq($LS_cur['editing']['id'] ?? null, $LS_id, 'the console opens a lesson in its editor, answers included');
t_ok(isset($LS_cur['editing']['checks'][0]['answer']), '…because the editor is staff-only');
t_eq(mam_console_curriculum(['course' => $LS_course, 'lesson' => 'new'])['editing']['week'] ?? null, 3, 'a new lesson starts at the first week with none');

// The suite shares one database and other files count the seeded catalogue, so
// this file's course and everything under it goes (as test_curriculum_clone.php
// does with its own fixture).
$LS_mods = array_column(mam_db_all('SELECT id FROM modules WHERE course_id = ?', [$LS_course]), 'id');
foreach ($LS_mods as $LS_mid) {
    foreach (['module_progress', 'module_checks', 'module_materials'] as $LS_t) mam_db()->prepare("DELETE FROM $LS_t WHERE module_id = ?")->execute([$LS_mid]);
}
mam_db()->prepare('DELETE FROM modules WHERE course_id = ?')->execute([$LS_course]);
mam_db()->prepare('DELETE FROM course_enrollments WHERE course_id = ?')->execute([$LS_course]);
mam_db()->prepare('DELETE FROM courses WHERE id = ?')->execute([$LS_course]);
