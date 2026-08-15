<?php
/**
 * The mastery scale and the MAM Mastery Score™ (PDF §23-24).
 *
 * The behaviours worth pinning are the ones a naive composite gets wrong:
 * a component with no evidence must be EXCLUDED rather than scored zero,
 * attendance and punctuality must stay OUT of the score, and an unrecognised
 * rubric criterion must still count for something.
 */
declare(strict_types=1);

t_group('mastery — the §23 six-level scale');

t_eq(count(MAM_MASTERY_LEVELS), 6, '§23 defines six levels, 0 through 5');
t_eq(MAM_MASTERY_LEVELS[0]['key'], 'not_assessed', 'level 0 is "not assessed"');
t_eq(MAM_MASTERY_LEVELS[3]['label'], 'Proficient', 'level 3 is Proficient');
t_eq(MAM_MASTERY_LEVELS[5]['label'], 'Mastery', 'level 5 is Mastery');

t_eq(mam_mastery_level_from_pct(95), 5, '95% is Mastery');
t_eq(mam_mastery_level_from_pct(90), 5, 'the Mastery band starts at 90');
t_eq(mam_mastery_level_from_pct(80), 4, '80% is Advanced');
t_eq(mam_mastery_level_from_pct(65), 3, '65% is Proficient');
t_eq(mam_mastery_level_from_pct(45), 2, '45% is Developing');
t_eq(mam_mastery_level_from_pct(10), 1, '10% is Emerging');
// Level 0 means "no evidence", so a real percentage must never map to it.
t_eq(mam_mastery_level_from_pct(0), 1, 'a scored zero is Emerging, not "not assessed"');
t_eq(mam_mastery_level(99)['key'], 'not_assessed', 'an out-of-range level degrades to "not assessed"');

t_group('mastery — criterion → component mapping (§20-21)');

t_eq(mam_mastery_component_for_criterion('Rhythm & Timing'), 'rhythm_timing', 'rhythm maps to rhythm & timing');
t_eq(mam_mastery_component_for_criterion('Tempo Control'), 'rhythm_timing', 'tempo maps to rhythm & timing');
t_eq(mam_mastery_component_for_criterion('Note Accuracy'), 'musical_accuracy', 'note accuracy maps to musical accuracy');
t_eq(mam_mastery_component_for_criterion('Score/Notation Accuracy'), 'theory', 'notation maps to theory');
t_eq(mam_mastery_component_for_criterion('Musical Expression'), 'expression', 'expression maps to expression');
t_eq(mam_mastery_component_for_criterion('Hand/Finger Technique'), 'technical_competence', 'finger technique maps to technical competence');
t_eq(mam_mastery_component_for_criterion('Improvisation'), 'creativity', 'improvisation maps to creativity');
t_eq(mam_mastery_component_for_criterion('Performance Confidence'), 'performance', 'confidence maps to performance');
t_eq(mam_mastery_component_for_criterion('Embouchure'), 'technical_competence', 'a saxophone criterion maps to technical competence');
t_eq(mam_mastery_component_for_criterion('Paradiddles'), 'technical_competence', 'an unrecognised label is kept, not discarded');

t_eq(array_sum(array_column(MAM_MASTERY_COMPONENTS, 'weight')), 100, 'the component weights sum to 100');

t_group('mastery — the score itself (§24)');

$MS_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($MS_course === 0) $MS_course = mam_db_insert('courses', ['code' => 'ms_c', 'name' => 'MS', 'kind' => 'core']);
$MS_ins = mam_db_insert('users', ['name' => 'MS Ins', 'email' => 'ms_ins@test.local', 'role' => 'instructor', 'password_hash' => '', 'status' => 'active']);
$MS_stu = mam_db_insert('users', ['name' => 'MS Stu', 'email' => 'ms_stu@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
$MS_coh = mam_db_insert('cohorts', ['course_id' => $MS_course, 'title' => 'MS Cohort', 'status' => 'active', 'capacity' => 10, 'instructor_id' => $MS_ins]);
mam_db_insert('course_enrollments', ['student_id' => $MS_stu, 'course_id' => $MS_course, 'cohort_id' => $MS_coh, 'status' => 'active']);

// A student with no evidence at all.
$m0 = mam_mastery_score($MS_stu);
t_ok($m0['score'] === null, 'a student with no evidence has no score');
t_eq($m0['level'], 0, 'and sits at level 0 — not assessed');
t_eq($m0['coverage_pct'], 0.0, 'with zero evidence coverage');
t_ok(strpos($m0['headline'], 'not yet assessed') !== false, 'the headline says so plainly rather than showing 0/100');
t_eq(count($m0['components']), 0, 'no components are reported');
t_eq(count($m0['missing']), count(MAM_MASTERY_COMPONENTS), 'every component is disclosed as missing');

// One piece of evidence: preparation at 80%.
$MS_lesson = mam_db_insert('lessons', ['cohort_id' => $MS_coh, 'title' => 'MS Module', 'week_no' => 1, 'published' => 1]);
mam_lms2_lesson_progress_set($MS_lesson, $MS_stu, 80);

// That module is also a clean first attempt, so consistency has evidence too:
// preparation 80 @ weight 10 and consistency 100 @ weight 5 → 86.7, NOT the
// 13.3 a naive "divide by the full 100 weight" composite would have produced.
$m1 = mam_mastery_score($MS_stu);
t_near((float) mam_mastery_components($MS_stu)['preparation']['pct'], 80.0, 'the preparation component reports its own value');
t_near((float) $m1['score'], 86.7, 'the score is the weighted mean of the components WITH evidence, not of all eleven');
t_eq($m1['level'], 4, '86.7% is Advanced');
t_ok($m1['provisional'] === true, 'a thin record is flagged provisional');
t_ok($m1['coverage_pct'] < 50, 'because coverage is below half the total weight');
// Consistency also has evidence now (the module is a clean first attempt).
$keys = array_column($m1['components'], 'key');
t_ok(in_array('preparation', $keys, true), 'preparation is a reported component');
t_ok(!in_array('repertoire', $keys, true), 'repertoire has no evidence and is excluded, not scored zero');

t_group('mastery — attendance stays out of the score (§24)');

// Mark the student absent for several sessions. The score must not move.
$scoreBefore = mam_mastery_score($MS_stu)['score'];
$MS_sess = [];
for ($i = 0; $i < 3; $i++) {
    $sid = mam_db_insert('class_sessions', [
        'cohort_id' => $MS_coh, 'title' => 'MS S' . $i,
        'scheduled_at' => date('Y-m-d H:i:s', time() - (10 - $i) * 86400),
        'status' => 'held', 'duration_min' => 60, 'kind' => 'class',
    ]);
    mam_db_insert('session_attendance', ['session_id' => $sid, 'student_id' => $MS_stu, 'status' => 'absent', 'mode' => 'onsite']);
    $MS_sess[] = $sid;
}
$m2 = mam_mastery_score($MS_stu);
t_near((float) $m2['score'], (float) $scoreBefore, 'three absences do not change the Mastery Score');
t_eq($m2['attendance']['sessions'], 3, 'attendance is reported separately');
t_near((float) $m2['attendance']['attendance_pct'], 0.0, 'and reports the real attendance rate');
$ckeys = array_column($m2['components'], 'key');
t_ok(!in_array('attendance', $ckeys, true), 'attendance is never a scoring component');
t_ok(!in_array('punctuality', $ckeys, true), 'nor is punctuality');

t_group('mastery — rubric evidence lands in the right component');

$MS_rubric = mam_lms2_rubric_create('MS Piano Rubric', [
    ['label' => 'Rhythm & Timing', 'max_points' => 10, 'weight' => 1],
    ['label' => 'Note Accuracy',   'max_points' => 10, 'weight' => 1],
], $MS_coh, $MS_ins);
$MS_crit = mam_db_all('SELECT id, label FROM rubric_criteria WHERE rubric_id = ? ORDER BY id', [$MS_rubric]);
$MS_assign = mam_db_insert('assignments', ['title' => 'MS Assignment', 'student_id' => $MS_stu, 'author_id' => $MS_ins, 'status' => 'open']);
$MS_sub = mam_db_insert('submissions', ['assignment_id' => $MS_assign, 'student_id' => $MS_stu, 'artifact_url' => 'https://example.test/a', 'status' => 'submitted']);
mam_db_insert('submission_reviews', [
    'submission_id' => $MS_sub, 'rubric_id' => $MS_rubric, 'reviewer_id' => $MS_ins,
    'scores_json' => json_encode([(int) $MS_crit[0]['id'] => 9, (int) $MS_crit[1]['id'] => 5]),
    'total_points' => 14, 'max_points' => 20,
]);

$comp = mam_mastery_components($MS_stu);
t_ok(isset($comp['rhythm_timing']), 'the rhythm criterion created a rhythm & timing component');
t_near((float) $comp['rhythm_timing']['pct'], 90.0, '9 of 10 is 90%');
t_ok(isset($comp['musical_accuracy']), 'the note-accuracy criterion created a musical accuracy component');
t_near((float) $comp['musical_accuracy']['pct'], 50.0, '5 of 10 is 50%');
t_ok(in_array('rubric', $comp['rhythm_timing']['sources'], true), 'the component names its evidence source');

// A score above a criterion's maximum cannot inflate the component past 100%.
$MS_sub2 = mam_db_insert('submissions', ['assignment_id' => $MS_assign, 'student_id' => $MS_stu, 'artifact_url' => 'https://example.test/b', 'status' => 'submitted']);
mam_db_insert('submission_reviews', [
    'submission_id' => $MS_sub2, 'rubric_id' => $MS_rubric, 'reviewer_id' => $MS_ins,
    'scores_json' => json_encode([(int) $MS_crit[0]['id'] => 999]),
    'total_points' => 999, 'max_points' => 10,
]);
$comp2 = mam_mastery_components($MS_stu);
t_ok((float) $comp2['rhythm_timing']['pct'] <= 100.0, 'an out-of-range score is clamped to the criterion maximum');

// With more components present the record is no longer provisional.
$m3 = mam_mastery_score($MS_stu);
t_ok($m3['coverage_pct'] > $m1['coverage_pct'], 'more evidence raises the disclosed coverage');
t_ok($m3['score'] !== null && $m3['score'] >= 0 && $m3['score'] <= 100, 'the score stays inside 0–100');
