<?php
/**
 * Upper Admin strategic intelligence (PDF §34-35).
 *
 * The behaviours worth pinning are the scoping ones: that a filter genuinely
 * narrows the numbers rather than quietly returning academy-wide figures, that
 * "no data" stays distinguishable from "zero", and that the drill-down chain
 * is the one §34 specifies.
 */
declare(strict_types=1);

t_group('upper admin — the §34 drill-down chain');

t_eq(array_keys(MAM_UPPER_DIMENSIONS), ['programme', 'instrument', 'grade', 'instructor', 'student'],
     'the chain is Academy → Programme → Instrument → Grade → Instructor → Student');
t_eq(mam_upper_next_dimension(null), 'programme', 'the academy drills into programmes first');
t_eq(mam_upper_next_dimension('programme'), 'instrument', 'then instruments');
t_eq(mam_upper_next_dimension('grade'), 'instructor', 'then instructors');
t_ok(mam_upper_next_dimension('student') === null, 'the student is the bottom of the chain');
t_eq(mam_upper_next_dimension('nonsense'), null, 'an unknown dimension has no next level');

$threw = false;
try { mam_upper_breakdown('salary'); } catch (\InvalidArgumentException $e) { $threw = true; }
t_ok($threw, 'an unknown dimension is refused rather than guessed at');

t_group('upper admin — the filter is a whitelist');

$f = mam_upper_filter(['program_id' => '3', 'course_id' => 4, 'level_id' => 0, 'instructor_id' => -2,
                       'admin_tier' => 'super', 'sql' => '1=1']);
t_eq($f, ['program_id' => 3, 'course_id' => 4], 'only positive integers on whitelisted keys survive');
t_ok(!isset($f['admin_tier']), 'an unlisted key is dropped');
t_eq(mam_upper_filter([]), [], 'an empty request filters nothing');

t_group('upper admin — scoping actually narrows');

$UA_prog = mam_db_insert('programs', ['code' => 'ua_grand', 'name' => 'UA Grand Musician', 'weeks' => 12, 'sort_order' => 90, 'active' => 1]);
$UA_piano = mam_db_insert('courses', ['code' => 'ua_piano', 'name' => 'UA Piano', 'kind' => 'core']);
$UA_drums = mam_db_insert('courses', ['code' => 'ua_drums', 'name' => 'UA Drums', 'kind' => 'core']);
$UA_lvl  = (int) (mam_db_row("SELECT id FROM levels LIMIT 1")['id'] ?? 0);
$UA_ins1 = mam_db_insert('users', ['name' => 'UA Strong', 'email' => 'ua_ins1@test.local', 'role' => 'instructor', 'password_hash' => '', 'status' => 'active']);
$UA_ins2 = mam_db_insert('users', ['name' => 'UA Weak',   'email' => 'ua_ins2@test.local', 'role' => 'instructor', 'password_hash' => '', 'status' => 'active']);

// Two cohorts under one programme, one instrument each, one instructor each.
$UA_c1 = mam_db_insert('cohorts', ['course_id' => $UA_piano, 'program_id' => $UA_prog, 'level_id' => $UA_lvl,
    'instructor_id' => $UA_ins1, 'title' => 'UA Piano cohort', 'status' => 'active', 'capacity' => 20]);
$UA_c2 = mam_db_insert('cohorts', ['course_id' => $UA_drums, 'program_id' => $UA_prog, 'level_id' => $UA_lvl,
    'instructor_id' => $UA_ins2, 'title' => 'UA Drums cohort', 'status' => 'active', 'capacity' => 20]);

// Three students on piano scoring well, two on drums scoring badly — so a
// drill-down has an outlier to find.
$mk = function (int $cohort, int $course, string $tag, float $score) {
    $sid = mam_db_insert('users', ['name' => 'UA ' . $tag, 'email' => 'ua_' . $tag . '@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
    $eid = mam_db_insert('course_enrollments', ['student_id' => $sid, 'course_id' => $course, 'cohort_id' => $cohort, 'status' => 'active']);
    mam_db_insert('results', ['enrollment_id' => $eid, 'total_score' => $score, 'grade' => 'B', 'outcome' => 'pass']);
    $les = mam_db_insert('lessons', ['cohort_id' => $cohort, 'title' => 'UA module ' . $tag, 'week_no' => 1, 'published' => 1]);
    mam_db_insert('lesson_progress', ['lesson_id' => $les, 'student_id' => $sid, 'percent' => $score >= 70 ? 100 : 20,
        'status' => $score >= 70 ? 'completed' : 'in_progress', 'attempts' => 1]);
    return $sid;
};
$UA_p1 = $mk($UA_c1, $UA_piano, 'p1', 88.0);
$UA_p2 = $mk($UA_c1, $UA_piano, 'p2', 84.0);
$UA_p3 = $mk($UA_c1, $UA_piano, 'p3', 80.0);
$UA_d1 = $mk($UA_c2, $UA_drums, 'd1', 52.0);
$UA_d2 = $mk($UA_c2, $UA_drums, 'd2', 48.0);

$academy = mam_upper_health();
t_ok($academy['cohorts'] === null, 'the unscoped view is academy-wide, not a cohort list');

$prog = mam_upper_health(['program_id' => $UA_prog]);
t_eq($prog['students'], 5, 'scoping to the programme counts only its students');
t_eq($prog['cohorts'], 2, 'and only its cohorts');
t_near((float) $prog['avg_mastery'], 70.4, 'mastery is the mean across the programme');

$piano = mam_upper_health(['program_id' => $UA_prog, 'course_id' => $UA_piano]);
t_eq($piano['students'], 3, 'descending to an instrument narrows again');
t_near((float) $piano['avg_mastery'], 84.0, 'and reports that instrument’s own mastery');
t_near((float) $piano['preparation_pct'], 100.0, 'piano preparation is complete');

$drums = mam_upper_health(['program_id' => $UA_prog, 'course_id' => $UA_drums]);
t_eq($drums['students'], 2, 'the sibling instrument is separate');
t_near((float) $drums['avg_mastery'], 50.0, 'and is visibly the weaker one');
t_near((float) $drums['preparation_pct'], 0.0, 'with no completed preparation');

// The scoping must be real: a filter matching nothing must NOT fall back to
// academy-wide numbers, which would be the worst possible failure here.
$empty = mam_upper_health(['program_id' => $UA_prog, 'course_id' => 99999999]);
t_eq($empty['cohorts'], 0, 'an impossible filter matches no cohorts');
t_eq($empty['students'], 0, 'and reports no students');
t_ok($empty['avg_mastery'] === null, 'not an academy-wide average');
t_ok($empty['preparation_pct'] === null, 'and no academy-wide preparation figure');

// "No data" and "zero percent" are different findings.
t_ok($empty['attendance_pct'] === null, 'nothing measured reports null, not 0%');
t_near((float) $drums['preparation_pct'], 0.0, 'whereas measured-and-zero reports 0.0');

t_group('upper admin — the breakdown compares siblings');

$byInstrument = mam_upper_breakdown('instrument', ['program_id' => $UA_prog]);
t_eq(count($byInstrument), 2, 'both instruments appear');
// Weakest first: the whole point of the screen is to surface the problem.
t_eq($byInstrument[0]['label'], 'UA Drums', 'the weakest performer sorts first');
t_eq($byInstrument[1]['label'], 'UA Piano', 'the strongest sorts last');
t_eq($byInstrument[0]['dimension'], 'instrument', 'each row names its dimension');
// Every level carries the same metric set, or levels cannot be compared.
foreach (['students', 'preparation_pct', 'attendance_pct', 'teachback_pct', 'avg_mastery', 'module_completion_pct', 'intervention'] as $k) {
    t_ok(array_key_exists($k, $byInstrument[0]), "the breakdown row carries '$k', the same metric as the headline");
}

$byInstructor = mam_upper_breakdown('instructor', ['program_id' => $UA_prog]);
t_eq(count($byInstructor), 2, 'both instructors appear under the programme');
t_eq($byInstructor[0]['label'], 'UA Weak', 'the instructor with the weaker cohort sorts first');

$byStudent = mam_upper_breakdown('student', ['program_id' => $UA_prog, 'course_id' => $UA_drums]);
t_eq(count($byStudent), 2, 'the bottom of the chain lists the students themselves');
t_ok(in_array($byStudent[0]['id'], [$UA_d1, $UA_d2], true), 'and they are the right students');

// A member with no mastery data must not masquerade as the worst performer.
$UA_c3 = mam_db_insert('cohorts', ['course_id' => $UA_piano, 'program_id' => $UA_prog, 'level_id' => $UA_lvl,
    'instructor_id' => $UA_ins1, 'title' => 'UA Fresh cohort', 'status' => 'active', 'capacity' => 20]);
$UA_fresh = mam_db_insert('users', ['name' => 'UA Fresh', 'email' => 'ua_fresh@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $UA_fresh, 'course_id' => $UA_piano, 'cohort_id' => $UA_c3, 'status' => 'active']);
$freshRows = mam_upper_breakdown('student', ['program_id' => $UA_prog]);
$last = end($freshRows);
t_eq($last['id'], $UA_fresh, 'a student with no results sorts last, not first');
t_ok($last['avg_mastery'] === null, 'because they have no mastery figure at all');

t_group('upper admin — the breadcrumb');

$crumbs = mam_upper_breadcrumb(['program_id' => $UA_prog, 'course_id' => $UA_piano]);
t_eq(count($crumbs), 3, 'Academy → Programme → Instrument');
t_eq($crumbs[0]['label'], 'Academy', 'the root is the academy');
t_eq($crumbs[0]['filter'], [], 'and clears the filter');
t_eq($crumbs[1]['label'], 'UA Grand Musician', 'the programme is named, not numbered');
t_eq($crumbs[2]['label'], 'UA Piano', 'so is the instrument');
t_eq($crumbs[1]['filter'], ['program_id' => $UA_prog], 'each crumb carries the filter that returns to it');

// A dangling id must be visible, not silently dropped — dropping it would
// widen the scope in the reader's mind while the numbers stayed narrow.
$dangling = mam_upper_breadcrumb(['program_id' => 99999999]);
t_ok(strpos($dangling[1]['label'], 'not found') !== false, 'an unresolvable id is shown as missing rather than omitted');

t_group('upper admin — the assembled dashboard');

$dash = mam_upper_dashboard(['program_id' => $UA_prog]);
t_eq($dash['dimension'], 'programme', 'it defaults to the first drill-down level');
// Six by now: the five scored students plus the unscored one added above.
t_eq($dash['health']['students'], 6, 'the headline is scoped by the filter');
t_ok(is_array($dash['breadcrumb']) && count($dash['breadcrumb']) === 2, 'the breadcrumb reflects the scope');
t_ok(is_array($dash['instructors']), '§35 instructor comparison is included');
t_ok(is_array($dash['dimensions']) && count($dash['dimensions']) === 5, 'the chain is published for the UI');

$dash2 = mam_upper_dashboard(['program_id' => $UA_prog], 'instrument');
t_eq($dash2['dimension'], 'instrument', 'an explicit dimension is honoured');
t_eq(count($dash2['breakdown']), 2, 'and drives the breakdown');
$dash3 = mam_upper_dashboard([], 'not-a-dimension');
t_eq($dash3['dimension'], 'programme', 'an invalid dimension falls back rather than throwing');
