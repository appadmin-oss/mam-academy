<?php
/**
 * Authorised collectors and the sign-out record (safeguarding.php, v104) —
 * ported from NGG's member_collectors, and CHECKED at sign-out, which NGG never
 * did. Each group pins one rule that a refactor could lose with every screen
 * still looking right.
 */
declare(strict_types=1);

$CO_now = gmdate('YmdHis') . random_int(100, 999);
$CO_u = static fn(string $tag, string $role) => (int) mam_db_insert('users', ['email' => "co.$tag.$CO_now@test.local", 'name' => ucfirst($tag) . ' Collect',
    'role' => $role, 'password_hash' => 'x', 'status' => 'active']);
$CO_kid = $CO_u('kid', 'student'); $CO_grown = $CO_u('grown', 'student'); $CO_other = $CO_u('stranger', 'student');
$CO_teach = $CO_u('teacher', 'instructor'); $CO_out = $CO_u('outsider', 'instructor'); $CO_admin = $CO_u('admin', 'admin');
$CO_mum = $CO_u('mum', 'parent');
$CO_user = static fn(int $id) => mam_db_row('SELECT * FROM users WHERE id = ?', [$id]);
$CO_course = (int) mam_db_insert('courses', ['code' => 'co_' . $CO_now, 'name' => 'Collect ' . $CO_now, 'kind' => 'elective', 'active' => 1, 'sort_order' => 99]);
$CO_co = (int) mam_db_insert('cohorts', ['course_id' => $CO_course, 'instructor_id' => $CO_teach, 'title' => 'Piano C ' . $CO_now, 'status' => 'active']);
foreach ([$CO_kid, $CO_grown] as $sid) mam_db_insert('course_enrollments', ['student_id' => $sid, 'course_id' => $CO_course, 'cohort_id' => $CO_co, 'status' => 'active']);
mam_db_insert('parent_links', ['parent_id' => $CO_mum, 'student_id' => $CO_kid]);
// An adult: a date of birth on file says 30-something.
mam_db_insert('customers', ['email' => "co.grown.$CO_now@test.local", 'first_name' => 'Grown', 'last_name' => 'Up', 'dob' => '1990-04-01']);
$CO_sess = (int) mam_db_insert('class_sessions', ['cohort_id' => $CO_co, 'title' => 'Collect session', 'scheduled_at' => gmdate('Y-m-d H:i:s'),
    'status' => 'scheduled', 'duration_min' => 60, 'kind' => 'class']);

t_group('collectors — who may edit the list');

t_throws(fn() => mam_collectors_save($CO_user($CO_out), $CO_kid, [['name' => 'Aunt Bisi']]), 'forbidden',
    'a teacher who does not teach the child cannot edit their list');
t_throws(fn() => mam_collectors_save($CO_user($CO_mum), $CO_kid, [['name' => 'Aunt Bisi']]), 'forbidden',
    'nor can a guardian — a list editable from a phone is editable by whoever holds the phone');
t_throws(fn() => mam_collectors_save($CO_user($CO_teach), $CO_kid, [['name' => 'Aunt Bisi', 'phone' => 'call me']]), null,
    'a phone that is not a number is refused');
t_eq(count(mam_collectors_list($CO_kid)), 0, '…and a refused save writes nothing, not the rows before the bad one');
$CO_list = mam_collectors_save($CO_user($CO_teach), $CO_kid, [
    ['name' => 'Ngozi  Collect', 'relation' => 'Mother', 'phone' => '+234 803 000 0001'],
    ['name' => 'Aunt Bisi', 'relation' => 'Aunt', 'phone' => ''], ['name' => '   ']]);
t_eq(count($CO_list), 2, 'blank rows are skipped and the rest saved');
t_eq($CO_list[0]['name'], 'Ngozi Collect', 'runs of spaces in a name are collapsed');
t_throws(fn() => mam_collectors_save($CO_user($CO_teach), $CO_kid, array_fill(0, MAM_COLLECTORS_MAX + 1, ['name' => 'x']) ), null,
    'a duplicate name is refused');
$CO_many = [];
for ($i = 1; $i <= MAM_COLLECTORS_MAX + 1; $i++) $CO_many[] = ['name' => 'Person ' . $i];
t_throws(fn() => mam_collectors_save($CO_user($CO_teach), $CO_kid, $CO_many), 'At most ' . MAM_COLLECTORS_MAX . ' people can be on the list.',
    'the list is capped at ' . MAM_COLLECTORS_MAX);
$CO_mother = (int) $CO_list[0]['id'];
$CO_again = mam_collectors_save($CO_user($CO_admin), $CO_kid, [['name' => 'Ngozi Collect', 'relation' => 'Mother', 'phone' => '+234 803 000 0002']]);
t_eq((int) $CO_again[0]['id'], $CO_mother, 'an unchanged person keeps their id, so past sign-outs still point at them');
t_eq(count($CO_again), 1, 'a name left off the list is removed');
mam_collectors_save($CO_user($CO_teach), $CO_kid, [['name' => 'Ngozi Collect', 'relation' => 'Mother', 'phone' => '+234 803 000 0002'], ['name' => 'Aunt Bisi']]);

t_group('collectors — the sign-out is checked');

$CO_ok = mam_collection_record($CO_user($CO_teach), $CO_kid, $CO_sess, $CO_mother);
t_eq($CO_ok['verdict'], 'authorised', 'somebody on the list is an authorised collection');
t_eq($CO_ok['concern_id'], null, '…and tells nobody');
t_throws(fn() => mam_collection_record($CO_user($CO_teach), $CO_other, $CO_sess, $CO_mother), 'forbidden',
    'a collector id is only good for the child it belongs to (and the teacher must teach that child)');
$CO_otherKid = $CO_u('sibling', 'student');
mam_db_insert('course_enrollments', ['student_id' => $CO_otherKid, 'course_id' => $CO_course, 'cohort_id' => $CO_co, 'status' => 'active']);
t_throws(fn() => mam_collection_record($CO_user($CO_teach), $CO_otherKid, $CO_sess, $CO_mother), 'That person is not on this child\'s list.',
    'another child\'s collector is not this child\'s collector');
t_throws(fn() => mam_collection_record($CO_user($CO_teach), $CO_kid, $CO_sess, null, 'Mr Stranger', ''), null,
    'somebody NOT on an existing list cannot be recorded without saying how it was checked');
$CO_concerns = (int) mam_db_row('SELECT COUNT(*) AS n FROM safeguarding_concerns')['n'];
$CO_bad = mam_collection_record($CO_user($CO_teach), $CO_kid, $CO_sess, null, 'Mr Stranger', 'Phoned the mother, she said he is a neighbour');
t_eq($CO_bad['verdict'], 'not_listed', 'with a reason it is recorded as not on the list');
t_ok((int) $CO_bad['concern_id'] > 0, '…and the safeguarding officer is told at once');
$CO_c = mam_db_row('SELECT * FROM safeguarding_concerns WHERE id = ?', [(int) $CO_bad['concern_id']]);
t_eq([$CO_c['urgency'], (int) $CO_c['about_id'], $CO_c['ref_type']], ['urgent', $CO_kid, 'collection'], 'an urgent concern about this child, pointing at the collection');
t_ok(str_contains((string) $CO_c['body'], 'Mr Stranger') && str_contains((string) $CO_c['body'], 'neighbour'), 'naming who took them and the teacher\'s reason');
t_eq((int) mam_db_row('SELECT COUNT(*) AS n FROM safeguarding_concerns')['n'], $CO_concerns + 1, 'exactly one concern, not one per check');

t_group('collectors — no alarm where there is nothing to check');

$CO_none = $CO_u('newkid', 'student');
mam_db_insert('course_enrollments', ['student_id' => $CO_none, 'course_id' => $CO_course, 'cohort_id' => $CO_co, 'status' => 'active']);
$CO_n = mam_collection_record($CO_user($CO_teach), $CO_none, $CO_sess, null, 'Their father', '');
t_eq([$CO_n['verdict'], $CO_n['concern_id']], ['no_list', null],
    'a child with no list yet is recorded as no_list and opens no concern — on the first day every child is in this state');
t_eq(mam_collect_is_minor($CO_none), true, 'a student of unknown age is treated as a child');
t_eq(mam_collect_is_minor($CO_grown), false, 'a student whose date of birth says adult is not');
$CO_a = mam_collection_record($CO_user($CO_teach), $CO_grown, $CO_sess, null, 'Drove themself', '');
t_eq([$CO_a['verdict'], $CO_a['concern_id']], ['adult', null], 'an adult student is recorded as adult and nothing is checked');

t_group('collectors — the register asks, and the family sees');

mam_db_insert('session_attendance', ['session_id' => $CO_sess, 'student_id' => $CO_kid, 'status' => 'present']);
$CO_reg = mam_iportal_register($CO_teach, $CO_sess);
$CO_row = null; foreach ($CO_reg['roster'] as $r) if ((int) $r['student_id'] === $CO_kid) $CO_row = $r;
t_eq($CO_row['collect']['done']['name'] ?? null, 'Mr Stranger', 'the register shows the latest sign-out for this class');
t_eq($CO_row['collect']['on_file'] ?? null, 2, '…and how many people are on file');
$CO_unmarked = null; foreach ($CO_reg['roster'] as $r) if ((int) $r['student_id'] === $CO_otherKid) $CO_unmarked = $r;
t_eq($CO_unmarked['collect'], null, 'a child not marked present is not asked about — they were not in the room');
mam_db_update('class_sessions', ['mode' => 'online'], ['id' => $CO_sess]);
$CO_online = mam_iportal_register($CO_teach, $CO_sess);
t_ok(!array_key_exists('collect', $CO_online['roster'][0] ?? []) || $CO_online['roster'][0]['collect'] === null,
    'an online class asks nothing — the child goes home from their own kitchen');
mam_db_update('class_sessions', ['mode' => 'onsite'], ['id' => $CO_sess]);
t_ok(mam_parent_scope_allows($CO_mum, $CO_kid, 'attendance'), 'a Standard guardian reaches the list (the attendance grant)');
t_ok(!in_array('attendance', MAM_PARENT_SCOPES['billing']['grants'], true), 'a Billing-only guardian does not');
$CO_route = (string) file_get_contents(dirname(__DIR__) . '/routes/parent/collectors.php');
t_ok(str_contains($CO_route, "mam_parent_scope_allows((int) \$u['id'], \$sid, 'attendance')") && !str_contains($CO_route, 'mam_collectors_save'),
    'the guardian route is scope-gated and READ-only');
t_ok(str_contains((string) file_get_contents(dirname(__DIR__) . '/index.php'), "'/collectors', \$f('collectors.php'), [mw_csrf()"),
    'the staff write route carries CSRF');

// Leave the shared test database's catalogue as we found it: later files count
// courses (test_curriculum_clone's note). Collections and lists go with the
// session and the course; the users stay, as every other file's do.
foreach (['DELETE FROM collections WHERE session_id = ?' => [$CO_sess], 'DELETE FROM session_attendance WHERE session_id = ?' => [$CO_sess],
          'DELETE FROM class_sessions WHERE id = ?' => [$CO_sess], 'DELETE FROM course_enrollments WHERE course_id = ?' => [$CO_course],
          'DELETE FROM cohorts WHERE id = ?' => [$CO_co], 'DELETE FROM courses WHERE id = ?' => [$CO_course]] as $CO_sql => $CO_args) {
    mam_db()->prepare($CO_sql)->execute($CO_args);
}
t_eq(mam_db_row('SELECT id FROM courses WHERE id = ?', [$CO_course]), null, 'the fixture course is gone again');
