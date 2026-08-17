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

/* The exclusion arithmetic is unchanged and still pinned — this is the behaviour
   that stops an unassessed beginner reading as a failure. `raw_score` is the
   renormalised mean over evidenced components only. */
t_near((float) $m1['raw_score'], 86.7, 'the renormalised mean is over the components WITH evidence, not all eleven');

/* What changed: at 15% coverage that mean is WITHHELD rather than published with
   a caveat. The OECD/JRC Handbook's point is that renormalising changes what the
   index means, so a 2-of-11 score is not comparable to an 11-of-11 one — and a
   reader shown "86.7" anchors on it however much coverage is printed beside it.
   "Insufficient evidence" is a different statement from a number, and the true
   one here. */
t_ok($m1['coverage_pct'] < MAM_MASTERY_MIN_COVERAGE, 'coverage is below the floor');
t_ok($m1['score'] === null, '…so no composite is reported at all');
t_ok($m1['sufficient'] === false, '…and the payload says why, as a flag callers can branch on');
t_eq($m1['level'], 0, '…the level falls back to §23 level 0, "not assessed"');
t_ok(strpos($m1['headline'], 'not enough evidence') !== false,
    'the headline states the reason rather than leaving it to be inferred');
t_ok(strpos($m1['headline'], '15%') !== false || strpos($m1['headline'], '% of the assessment') !== false,
    '…and quantifies how much has been covered');

// The subscores stay available throughout: per-component evidence is real even
// when the composite is not.
t_ok(count($m1['components']) > 0, 'components are still reported when the composite is withheld');

// The arithmetic bounds the unscored weight permits — not a confidence interval,
// which would need a sampling model this does not have.
t_ok(is_array($m1['bounds']), 'the possible range is reported');
t_ok($m1['bounds']['min'] < $m1['bounds']['max'], '…and it is a real range at partial coverage');
t_ok($m1['bounds']['min'] >= 0 && $m1['bounds']['max'] <= 100, '…inside 0–100');
// At 15% coverage and a raw 86.7, the evidenced share is ~13 points, so the
// student's eventual score could be anything from ~13 to ~98. That width IS the
// argument for withholding the composite.
t_ok($m1['bounds']['max'] - $m1['bounds']['min'] > 50, 'a thin record has a very wide possible range');
// Consistency also has evidence now (the module is a clean first attempt).
$keys = array_column($m1['components'], 'key');
t_ok(in_array('preparation', $keys, true), 'preparation is a reported component');
t_ok(!in_array('repertoire', $keys, true), 'repertoire has no evidence and is excluded, not scored zero');

t_group('mastery — attendance stays out of the score (§24)');

// Mark the student absent for several sessions. The score must not move.
$scoreBefore = mam_mastery_score($MS_stu)['raw_score'];
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
// Compare the RAW mean, not the reported score: at this coverage the reported
// score is (correctly) withheld, and comparing two nulls would pass whatever
// attendance did to the components.
$rawBefore = $scoreBefore;
t_near((float) $m2['raw_score'], (float) $rawBefore, 'three absences do not change the Mastery Score');
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
t_ok($m3['raw_score'] !== null && $m3['raw_score'] >= 0 && $m3['raw_score'] <= 100, 'the score stays inside 0–100');

t_group('mastery — the composite appears once the evidence supports it');

/* Enough components to clear the 40% floor. Built from real evidence rather than
   by lowering the threshold, because a threshold that moves to fit the data is
   not a threshold. */
$MS_full = mam_db_insert('users', ['name' => 'MS Full', 'email' => 'ms_full@test.local', 'role' => 'student', 'password_hash' => '', 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $MS_full, 'course_id' => $MS_course, 'cohort_id' => $MS_coh, 'status' => 'active']);
$MS_bigRubric = mam_lms2_rubric_create('MS Full Rubric', [
    ['label' => 'Hand/Finger Technique', 'max_points' => 10, 'weight' => 1],   // technical_competence 15
    ['label' => 'Note Accuracy',         'max_points' => 10, 'weight' => 1],   // musical_accuracy      10
    ['label' => 'Rhythm & Timing',       'max_points' => 10, 'weight' => 1],   // rhythm_timing         10
    ['label' => 'Musical Expression',    'max_points' => 10, 'weight' => 1],   // expression             8
    ['label' => 'Performance Confidence','max_points' => 10, 'weight' => 1],   // performance           10
], $MS_coh, $MS_ins);
$MS_bigCrit = mam_db_all('SELECT id FROM rubric_criteria WHERE rubric_id = ? ORDER BY id', [$MS_bigRubric]);
$MS_bigAssign = mam_db_insert('assignments', ['title' => 'MS Full Assignment', 'student_id' => $MS_full, 'author_id' => $MS_ins, 'status' => 'open']);
$MS_bigSub = mam_db_insert('submissions', ['assignment_id' => $MS_bigAssign, 'student_id' => $MS_full, 'artifact_url' => 'https://example.test/f', 'status' => 'submitted']);
mam_db_insert('submission_reviews', [
    'submission_id' => $MS_bigSub, 'rubric_id' => $MS_bigRubric, 'reviewer_id' => $MS_ins,
    'scores_json' => json_encode(array_fill_keys(array_map('intval', array_column($MS_bigCrit, 'id')), 8)),
    'total_points' => 40, 'max_points' => 50,
]);
$mFull = mam_mastery_score($MS_full);
t_ok($mFull['coverage_pct'] >= MAM_MASTERY_MIN_COVERAGE, 'coverage now clears the floor');
t_ok($mFull['score'] !== null, '…so a composite IS reported');
t_ok($mFull['sufficient'] === true, '…and marked sufficient');

// Reported as a band, never a decimal: an 11-part weighted mean of subjective
// rubric marks does not support one decimal place, and printing one invites a
// parent to read a 2-point gap between two children as a real difference.
t_ok(is_array($mFull['band']), 'the score is reported as a band');
t_eq($mFull['band']['high'] - $mFull['band']['low'], MAM_MASTERY_BAND - 1, '…ten points wide');
t_ok($mFull['band']['low'] <= $mFull['score'] && $mFull['score'] <= $mFull['band']['high'],
    '…containing the score');
t_ok(strpos($mFull['headline'], (string) $mFull['band']['label']) !== false,
    'and the headline shows the band, not a decimal');
t_ok(!preg_match('/\\d+\\.\\d/', $mFull['headline']), '…with no decimal anywhere in it');

// The floor is a declared value judgement, not a derived fact — the Handbook's
// central point. It must be stated in the payload so a consumer can show it.
t_eq((float) $mFull['min_coverage'], MAM_MASTERY_MIN_COVERAGE, 'the floor travels with the payload');
