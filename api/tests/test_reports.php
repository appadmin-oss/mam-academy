<?php
/**
 * The ratified termly report (api/lib/reports.php, schema v85-86).
 *
 * The subsystem's whole value is a refusal, so most of this file tests failure
 * paths: a report reaches a family only after a board has ratified it, and
 * ratification is refused while an appeal is open or the moderation sample is
 * short. Everything else here exists because the schema claims to enforce
 * something, and a claim in a comment is not a control.
 */
declare(strict_types=1);

t_group('reports — the band is the stored value, and it is a real band');

// mam_report_band() and the v85 CHECK must admit exactly the same set, or a
// correct report cannot be written / an incorrect one can.
$RB = mam_report_band(74.3);
t_eq($RB['low'], 70, 'a 74.3 score bands at 70');
t_eq($RB['high'], 79, 'and tops at 79');
t_eq($RB['label'], '70–79', 'the label is the printed band, with an en dash');
t_ok($RB['low'] === (int) $RB['low'] && $RB['high'] === (int) $RB['high'], 'both bounds are whole numbers');

// The case the original CHECK refused: a perfect score.
$RBfull = mam_report_band(100.0);
t_eq($RBfull['low'], 100, 'a perfect 100 bands at 100');
t_eq($RBfull['high'], 100, 'and tops at 100 — clamped, so low === high');
t_ok(mam_report_band(0.0)['label'] === '0–9', 'the bottom of the scale bands too');
t_ok(mam_report_band(null) === null, 'no score, no band');

// The five grade words and the four levels are DIFFERENT axes, per the design:
// the mock labels both 68% and 46% "Pass" while colouring their bars differently.
t_eq(mam_report_grade_for(95.0), 'Distinction', '95 is a Distinction');
t_eq(mam_report_grade_for(88.0), 'Merit',       '88 is a Merit (the mock labels it so)');
t_eq(mam_report_grade_for(68.0), 'Pass',        '68 is a Pass');
t_eq(mam_report_grade_for(46.0), 'Pass',        'and so is 46 — the mock labels it so');
t_eq(mam_report_grade_for(30.0), 'Not yet at pass', 'below 40 is not yet at pass');
t_eq(mam_report_grade_for(null), 'Not assessed', 'no evidence is not a grade');

t_eq(mam_report_level_for(88.0), 'strong',  '88 renders as strong (green in the mock)');
t_eq(mam_report_level_for(68.0), 'secure',  '68 renders as secure (accent in the mock)');
t_eq(mam_report_level_for(46.0), 'working', '46 renders as working (warn in the mock)');
t_eq(mam_report_level_for(null), 'insufficient', 'and no evidence has its own level');
t_ok(count(MAM_REPORT_LEVELS) === 4, 'four levels, matching the schema CHECK');
t_ok(count(mam_report_legend()['grades']) === 5, 'five printed grade words, matching the design legend');

t_group('reports — the schema refuses what the lib refuses');

$RP_stu = mam_db_insert('users', ['name' => 'Report Kid', 'email' => 'reportkid@test.local',
    'role' => 'student', 'password_hash' => 'x', 'status' => 'active', 'enrollment_ref' => 'MAM/26/RP1']);
$RP_ins = mam_db_insert('users', ['name' => 'Report Ins', 'email' => 'reportins@test.local',
    'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$RP_chair = mam_db_insert('users', ['name' => 'Panel Chair', 'email' => 'panelchair@test.local',
    'role' => 'admin', 'password_hash' => 'x', 'status' => 'active']);

/** Try a raw INSERT and report whether the engine took it. */
function rp_write(array $row): bool {
    try { mam_db_insert('termly_reports', $row); return true; }
    catch (\Throwable $e) { return false; }
}
$RP_base = ['student_id' => $RP_stu, 'coverage_evidenced' => 8, 'coverage_total' => 11, 'coverage_weight_pct' => 72];

t_ok(!rp_write($RP_base + ['term_label' => 'RP-a', 'status' => 'ratified']),
    "'ratified' with no ratifier is refused — ratification is a named person on a stated date");
t_ok(!rp_write($RP_base + ['term_label' => 'RP-b', 'band_low' => 74.3, 'band_high' => 84.3]),
    'a DECIMAL band is refused: SQLite INTEGER is an affinity, not a type, so 74.3 stored verbatim until the CHECK said otherwise');
t_ok(!rp_write($RP_base + ['term_label' => 'RP-c', 'band_low' => 70, 'band_high' => 71]),
    'a one-point "band" is refused — that is false precision wearing a band\'s clothes');
t_ok(!rp_write($RP_base + ['term_label' => 'RP-d', 'band_low' => 0, 'band_high' => 100]),
    'a whole-scale "band" is refused — it says nothing while looking like a measurement');
t_ok(!rp_write(['student_id' => $RP_stu, 'term_label' => 'RP-e', 'coverage_evidenced' => 12, 'coverage_total' => 11, 'coverage_weight_pct' => 72]),
    'coverage above the total is refused');
t_ok(!rp_write(['student_id' => $RP_stu, 'term_label' => 'RP-f']),
    'coverage is NOT NULL with no default, so a writer that forgets it fails loudly instead of publishing "0 of 0 parts"');
// array_merge, NOT `+`: PHP's array union keeps the LEFT operand's value for a
// duplicate key, so `$RP_base + ['coverage_weight_pct' => 71.6]` silently kept
// the base's 72 and the assertion tested nothing.
/* A whole number is what prints on the face — "72% of the weight" is the
 * sentence and 71.6 is not — and the two engines keep that differently, so the
 * test asks each for what it actually guarantees. SQLite's INTEGER is an
 * AFFINITY that stores 71.6 verbatim, so the CHECK (`x = CAST(x AS INTEGER)`) is
 * the only thing refusing it. MySQL's INT COERCES the value to 72 before any
 * CHECK sees it, so the write succeeds and the printed sentence is still whole.
 * `mam_report_build()` rounds at the point of storage on both, which is where
 * the guarantee really lives; this asserts the belt as each engine fastens it. */
if (mam_db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    t_ok(!rp_write(array_merge($RP_base, ['term_label' => 'RP-g', 'coverage_weight_pct' => 71.6])),
        'a fractional coverage percentage is refused — "72% of the weight" prints on the face');
} else {
    t_ok(rp_write(array_merge($RP_base, ['term_label' => 'RP-g', 'coverage_weight_pct' => 71.6])),
        'MySQL accepts a fractional coverage figure…');
    t_eq((int) mam_db_row("SELECT coverage_weight_pct c FROM termly_reports WHERE term_label = 'RP-g'")['c'], 72,
        '…having COERCED it to a whole number, which is the guarantee the face needs');
}

// And the shapes mam_report_band() actually produces are all accepted.
t_ok(rp_write($RP_base + ['term_label' => 'RP-ok1', 'band_low' => 70, 'band_high' => 79]), 'a ten-point band is accepted');
t_ok(rp_write($RP_base + ['term_label' => 'RP-ok2', 'band_low' => 100, 'band_high' => 100]),
    'and so is (100,100) — the band a perfect score produces, which the original CHECK rejected');
t_ok(rp_write(['student_id' => $RP_stu, 'term_label' => 'RP-ok3', 'coverage_evidenced' => 0,
    'coverage_total' => 11, 'coverage_weight_pct' => 0]), 'a report with no band at all is accepted — that is "not assessed"');

t_group('reports — a criterion with no evidence is a ROW, never a zero');

$RP_r = mam_db_row("SELECT id FROM termly_reports WHERE term_label = 'RP-ok1'")['id'];
function rp_crit(int $rid, array $row): bool {
    try { mam_db_insert('termly_report_criteria', ['report_id' => $rid] + $row); return true; }
    catch (\Throwable $e) { return false; }
}
t_ok(!rp_crit((int) $RP_r, ['competency_code' => 'x1', 'label' => 'X', 'level' => 'insufficient', 'pct' => 0]),
    "'insufficient' with pct 0 is refused — a zero there reports an unassessed beginner as failing");
t_ok(!rp_crit((int) $RP_r, ['competency_code' => 'x2', 'label' => 'X', 'level' => 'strong', 'pct' => null]),
    'an evidenced level with no percentage is refused — the two are inseparable in both directions');
// Engine-dependent, and the guarantee the face needs is the same either way: what
// comes back out is a whole number. SQLite's INTEGER is an affinity, so it would store
// 88.5 verbatim and the CHECK is what refuses it; MySQL's INT coerces on the way in.
if (mam_db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    t_ok(!rp_crit((int) $RP_r, ['competency_code' => 'x3', 'label' => 'X', 'level' => 'strong', 'pct' => 88.5]),
        'a fractional criterion percentage is refused — the bar is drawn from it');
} else {
    t_ok(rp_crit((int) $RP_r, ['competency_code' => 'x3', 'label' => 'X', 'level' => 'strong', 'pct' => 88.5]),
        'MySQL accepts a fractional criterion percentage…');
    t_eq((int) mam_db_row("SELECT pct FROM termly_report_criteria WHERE report_id = " . (int) $RP_r
        . " AND competency_code = 'x3'")['pct'], 89,
        '…having COERCED it to a whole number, so the bar is still drawn from an integer');
}
t_ok(rp_crit((int) $RP_r, ['competency_code' => 'ok1', 'label' => 'Pitch', 'level' => 'strong', 'pct' => 88]),
    'a scored criterion is accepted');
t_ok(rp_crit((int) $RP_r, ['competency_code' => 'ok2', 'label' => 'Ensemble', 'level' => 'insufficient', 'pct' => null]),
    'and an unevidenced one is accepted as a row with a NULL pct');
t_ok(!rp_crit((int) $RP_r, ['competency_code' => 'ok1', 'label' => 'Pitch again', 'level' => 'secure', 'pct' => 70]),
    'one row per competency per report — a duplicate is refused');

t_group('reports — build assembles a draft from the mastery engine, not a fresh mean');

$RP_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($RP_course === 0) $RP_course = mam_db_insert('courses', ['code' => 'rp_c', 'name' => 'RP', 'kind' => 'core']);
$RP_coh = mam_db_insert('cohorts', ['course_id' => $RP_course, 'title' => 'RP Cohort',
    'status' => 'active', 'capacity' => 10, 'instructor_id' => $RP_ins, 'term' => 'RP-TERM']);
mam_db_insert('course_enrollments', ['student_id' => $RP_stu, 'course_id' => $RP_course,
    'cohort_id' => $RP_coh, 'status' => 'active']);
// Evidence: one module at 80% gives preparation + consistency, ~15% coverage.
$RP_les = mam_db_insert('lessons', ['cohort_id' => $RP_coh, 'title' => 'RP Module', 'week_no' => 1, 'published' => 1]);
mam_lms2_lesson_progress_set($RP_les, $RP_stu, 80);

$RP_id = mam_report_build($RP_stu, $RP_coh, $RP_chair);
t_ok($RP_id > 0, 'a draft is built');
$RP_got = mam_report_get($RP_id);
t_eq($RP_got['status'], 'draft', 'and it starts as a draft — a family sees nothing yet');
t_eq($RP_got['coverage']['total'], count(MAM_MASTERY_COMPONENTS),
    'the denominator is the mastery engine\'s own component count, so "8 of 11" means the same on both screens');
t_eq(count($RP_got['criteria']), count(MAM_MASTERY_COMPONENTS),
    'EVERY competency is a row, including the unevidenced ones');
$RP_ins_rows = array_values(array_filter($RP_got['criteria'], fn($c) => $c['level'] === 'insufficient'));
t_ok(count($RP_ins_rows) > 0, 'the unevidenced ones are present');
t_ok($RP_ins_rows[0]['pct'] === null, 'with a NULL percentage, not a zero');
t_eq($RP_ins_rows[0]['grade_label'], 'Not assessed', 'and the printed word says so');
t_ok($RP_got['band'] === null,
    'the band is withheld at 15% coverage — the report carries the mastery engine\'s refusal, not a mean of its own');
t_ok($RP_got['coverage']['weight_pct'] === (int) $RP_got['coverage']['weight_pct'], 'the coverage percentage is whole');

// Idempotent on the slot: rebuilding replaces, never stacks.
$RP_id2 = mam_report_build($RP_stu, $RP_coh, $RP_chair);
t_eq($RP_id2, $RP_id, 'rebuilding the same draft returns the same report');
t_eq(count(mam_report_get($RP_id)['criteria']), count(MAM_MASTERY_COMPONENTS),
    'and does not duplicate its criteria');

t_group('reports — ratification refuses, and throws rather than returning false');

// No evidence at all → refused. (This draft is scored, so make a bare one.)
$RP_bare = mam_db_insert('termly_reports', ['student_id' => $RP_stu, 'term_label' => 'RP-BARE',
    'coverage_evidenced' => 0, 'coverage_total' => 11, 'coverage_weight_pct' => 0]);
mam_db_insert('termly_report_criteria', ['report_id' => $RP_bare, 'competency_code' => 'theory',
    'label' => 'Theory', 'level' => 'insufficient', 'pct' => null]);
t_throws(fn() => mam_report_ratify($RP_bare, $RP_chair), 'no_evidence',
    'a report whose every row is "not assessed" cannot be ratified — that is not a report');

// A ratifier is required.
t_throws(fn() => mam_report_ratify($RP_id, null), 'no_ratifier',
    'ratification without a named person is refused');
t_throws(fn() => mam_report_ratify($RP_id, 0), 'no_ratifier',
    'and 0 is not a person — it is the FK violation that destroys an audit row');

// The moderation sample.
$RP_mod = mam_report_moderation_state($RP_id);
t_ok($RP_mod['scored'] > 0, 'the report has scored criteria');
t_eq($RP_mod['sampled'], 0, 'and no moderation yet');
t_ok($RP_mod['required'] >= MAM_REPORT_MODERATION_MIN, 'so it needs at least the minimum sample');
t_ok($RP_mod['sufficient'] === false, 'and the sample is short');
t_throws(fn() => mam_report_ratify($RP_id, $RP_chair), 'moderation_short',
    'ratification is refused while the moderation sample is short');

// Second-mark enough criteria.
$RP_scored = array_values(array_filter(mam_report_get($RP_id)['criteria'], fn($c) => $c['level'] !== 'insufficient'));
$RP_need = mam_report_moderation_state($RP_id)['required'];
for ($i = 0; $i < $RP_need && $i < count($RP_scored); $i++) {
    mam_report_moderate($RP_id, $RP_scored[$i]['competency_code'], $RP_ins, true, null, 'agreed');
}
t_eq(mam_report_get($RP_id)['status'], 'moderating',
    'a moderated report reads as "with the panel" — a state a family can be told about');
t_ok(mam_report_moderation_state($RP_id)['sufficient'], 'the sample is now long enough');

// Re-recording REPLACES rather than inflating the count.
$RP_before = mam_report_moderation_state($RP_id)['sampled'];
mam_report_moderate($RP_id, $RP_scored[0]['competency_code'], $RP_ins, false, 'secure', 'on reflection');
t_eq(mam_report_moderation_state($RP_id)['sampled'], $RP_before,
    'one moderator re-marking the same criterion replaces their row — a double-submit must not inflate the sample');
t_eq(mam_report_moderation_state($RP_id)['disagreed'], 1, 'and the disagreement is recorded');

// An open appeal blocks ratification. Staff may raise one pre-publication —
// that is how an error caught in moderation stops the report going out.
$RP_ap = mam_report_appeal_open($RP_id, $RP_ins, 'staff', 'Timing mark looks wrong against the recording.');
t_throws(fn() => mam_report_ratify($RP_id, $RP_chair), 'appeal_open',
    'ratification is refused while an appeal against the report is open');
mam_report_appeal_decide($RP_ap, 'declined', $RP_chair, 'Re-listened; the mark stands.');
t_eq(count(mam_report_open_appeals($RP_id)), 0, 'deciding it clears the block');

$RP_rat = mam_report_ratify($RP_id, $RP_chair, 'Examinations panel, August sitting');
t_eq($RP_rat['status'], 'ratified', 'with a sample and no open appeal, the board can ratify');
t_throws(fn() => mam_report_ratify($RP_id, $RP_chair), 'already_ratified',
    're-ratifying is refused, not silently idempotent — it would restamp a document a family already holds');
$RP_pub = mam_report_get($RP_id);
t_ok($RP_pub['ratified_at'] !== null && $RP_pub['ratified_by'] !== null, 'the row names the person and the time');

t_group('reports — a closed appeal always states its outcome');

$RP_ap2 = mam_report_appeal_open($RP_id, $RP_ins, 'staff', 'Second look at pitch.');
try { mam_report_appeal_decide($RP_ap2, 'upheld', $RP_chair, ''); t_ok(false, 'an upheld appeal with no note is refused'); }
catch (\InvalidArgumentException $e) { t_ok(true, 'an upheld appeal with no note is refused — a mark overturned must say why'); }
mam_report_appeal_decide($RP_ap2, 'upheld', $RP_chair, 'Pitch re-marked from the recording.');
t_throws(fn() => mam_report_appeal_decide($RP_ap2, 'declined', $RP_chair, 'x'), 'already_decided',
    'an appeal is decided once');

t_group('reports — the slot rule, and why a withdrawal frees it');

$RP_row = mam_db_row('SELECT * FROM termly_reports WHERE id = ?', [$RP_id]);
t_ok(mam_report_slot_taken($RP_stu, $RP_row['term_id'] !== null ? (int) $RP_row['term_id'] : null,
        (string) $RP_row['term_label'], (int) $RP_row['course_id'], $RP_row['level_id'] !== null ? (int) $RP_row['level_id'] : null),
    'the ratified report holds its slot');
t_ok(!mam_report_slot_taken($RP_stu, $RP_row['term_id'] !== null ? (int) $RP_row['term_id'] : null,
        (string) $RP_row['term_label'], (int) $RP_row['course_id'], $RP_row['level_id'] !== null ? (int) $RP_row['level_id'] : null, $RP_id),
    'and does not count against itself');

mam_report_withdraw($RP_id, $RP_chair, 'Appeal upheld on pitch; corrected report to follow.');
t_eq(mam_report_get($RP_id)['status'], 'withdrawn', 'withdrawing keeps the row');
t_ok(mam_report_get($RP_id)['withdrawn_reason'] !== null, 'and the reason — pulling a mark is an event, not a delete');
t_ok(!mam_report_slot_taken($RP_stu, $RP_row['term_id'] !== null ? (int) $RP_row['term_id'] : null,
        (string) $RP_row['term_label'], (int) $RP_row['course_id'], $RP_row['level_id'] !== null ? (int) $RP_row['level_id'] : null),
    'and FREES the slot — the corrected report an upheld appeal produces has to have somewhere to go');

t_group('reports — a guardian sees ratified reports only, and only for their own child');

$RP_par = (int) mam_parent_link('reportguardian@test.local', $RP_stu)['parent_id'];
$RP_other = mam_db_insert('users', ['name' => 'Other Kid', 'email' => 'otherkid@test.local',
    'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
t_throws(fn() => mam_report_for_guardian($RP_par, $RP_other), 'forbidden',
    'a guardian cannot read a child they are not linked to');

// The only report is withdrawn, so the family sees nothing.
$RP_view = mam_report_for_guardian($RP_par, $RP_stu);
t_eq(count($RP_view['reports']), 0,
    'a withdrawn report disappears from the family list — it is no longer the academy\'s statement about their child');

// Build, moderate and ratify a fresh one; now it appears.
$RP_id3 = mam_report_build($RP_stu, $RP_coh, $RP_chair);
$RP_sc3 = array_values(array_filter(mam_report_get($RP_id3)['criteria'], fn($c) => $c['level'] !== 'insufficient'));
for ($i = 0; $i < mam_report_moderation_state($RP_id3)['required']; $i++) {
    mam_report_moderate($RP_id3, $RP_sc3[$i]['competency_code'], $RP_ins, true);
}
mam_report_ratify($RP_id3, $RP_chair);
$RP_view2 = mam_report_for_guardian($RP_par, $RP_stu);
t_eq(count($RP_view2['reports']), 1, 'a ratified report reaches the family');
t_ok(!isset($RP_view2['reports'][0]['moderation']),
    'and carries no moderation record — who second-marked what, and where they disagreed, is staff information');
t_ok(!isset($RP_view2['reports'][0]['moderations']), 'nor the individual moderator rows');
t_ok(isset($RP_view2['reports'][0]['legend']['grades']), 'the grading legend travels WITH the report, on the same screen');
t_ok(isset($RP_view2['reports'][0]['coverage']['evidenced']), 'and so does the coverage that prints on its face');

t_group('reports — the appeal window is three months, in Lagos time');

$RP_w = mam_report_get($RP_id3)['appeal'];
t_ok($RP_w['open'] === true, 'a freshly ratified report is inside the appeal window');
t_eq($RP_w['window_days'], MAM_REPORT_APPEAL_WINDOW_DAYS, 'which is the design\'s three months');
t_ok($RP_w['closes_on'] !== null, 'and the closing date is stated rather than left to be worked out');

// A draft has no window, because there is nothing yet to appeal.
$RP_draftW = mam_report_appeal_window(['status' => 'draft', 'ratified_at' => null]);
t_ok($RP_draftW['open'] === false && $RP_draftW['reason'] === 'not_ratified', 'a draft has no appeal window');

// Outside the window, a family is refused — staff are not.
$RP_old = mam_db_insert('termly_reports', ['student_id' => $RP_stu, 'term_label' => 'RP-OLD',
    'coverage_evidenced' => 8, 'coverage_total' => 11, 'coverage_weight_pct' => 72,
    'status' => 'ratified', 'ratified_by' => $RP_chair,
    'ratified_at' => date('Y-m-d H:i:s', strtotime('-' . (MAM_REPORT_APPEAL_WINDOW_DAYS + 5) . ' days'))]);
t_throws(fn() => mam_report_appeal_open($RP_old, $RP_par, 'parent', 'Too late.'), 'window_closed',
    'a family cannot appeal a report older than the window');
t_ok(mam_report_appeal_open($RP_old, $RP_ins, 'staff', 'Data-entry error found in an audit.') > 0,
    'staff still can — an error found later is still an error');

t_group('reports — guardian access scopes are enforced server-side');

t_eq(mam_parent_scope($RP_par, $RP_stu), 'standard',
    'an existing link defaults to standard — a migration must not narrow access families already have');
t_ok(mam_parent_scope_allows($RP_par, $RP_stu, 'reports'), 'standard reaches ratified reports');
t_ok(mam_parent_scope_allows($RP_par, $RP_stu, 'invoices'), 'and invoices');
t_ok(!mam_parent_scope_allows($RP_par, $RP_stu, 'consents'), 'but not consents — that is Full');

mam_parent_scope_set($RP_par, $RP_stu, 'billing', $RP_chair);
t_ok(!mam_parent_scope_allows($RP_par, $RP_stu, 'reports'), 'billing-only reaches no marks');
$RP_denied = mam_report_for_guardian($RP_par, $RP_stu);
t_ok($RP_denied['scope_denied'] === true, 'so the reports endpoint refuses, server-side');
t_eq(count($RP_denied['reports']), 0, 'and returns no reports rather than hiding them in the client');

mam_parent_scope_set($RP_par, $RP_stu, 'full', $RP_chair);
t_ok(mam_parent_scope_allows($RP_par, $RP_stu, 'consents'), 'full reaches consents');
t_ok(mam_parent_scope_allows($RP_par, $RP_stu, 'act_for_child'), 'and can act for the child');

/* The refusals no scope reaches. This is the assertion to keep: it must fail if
 * anybody adds one of these to `full`'s grant list. */
foreach (MAM_PARENT_NEVER as $never) {
    foreach (['billing', 'standard', 'full'] as $sc) {
        mam_parent_scope_set($RP_par, $RP_stu, $sc, $RP_chair);
        t_ok(!mam_parent_scope_allows($RP_par, $RP_stu, $never),
            "no scope reaches '$never' — not even full ($sc)");
    }
}
mam_parent_scope_set($RP_par, $RP_stu, 'standard', $RP_chair);

try { mam_parent_scope_set($RP_par, $RP_stu, 'everything', $RP_chair); t_ok(false, 'an unknown scope is refused'); }
catch (\InvalidArgumentException $e) { t_ok(true, 'an unknown scope is refused at the only writer'); }

// A stored value this code does not recognise reads as the NARROWEST scope, and
// the two engines guarantee that differently — so the test asks each for what it
// actually promises. MySQL carries `chk_plink_scope` and REFUSES the write; the
// SQLite column has no CHECK (ALTER cannot add one), so the defensive read is
// the only thing stopping a hand-edited row from widening access.
if (mam_db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    mam_db_update('parent_links', ['access_scope' => 'superuser'], ['parent_id' => $RP_par, 'student_id' => $RP_stu]);
    t_eq(mam_parent_scope($RP_par, $RP_stu), 'billing',
        'an unrecognised stored scope reads as billing — the narrowest, never the widest');
    mam_parent_scope_set($RP_par, $RP_stu, 'standard', $RP_chair);
} else {
    $RP_refused = false;
    try { mam_db_update('parent_links', ['access_scope' => 'superuser'], ['parent_id' => $RP_par, 'student_id' => $RP_stu]); }
    catch (\Throwable $e) { $RP_refused = str_contains($e->getMessage(), 'chk_plink_scope'); }
    t_ok($RP_refused, 'on MySQL the column CHECK refuses an unrecognised scope outright');
}

// The "what you cannot see, and why" panel is data, not a silence.
$RP_ex = mam_parent_scope_explain($RP_par, $RP_stu);
t_ok(count($RP_ex) >= 2, 'the boundary is explained rather than discovered');
$RP_fixed = array_values(array_filter($RP_ex, fn($e) => $e['scope_could_change'] === false));
t_ok(count($RP_fixed) >= 2, 'and the universal refusals are marked as unchangeable by any scope');

t_group('reports — the ratification queue');

$RP_q = mam_report_pending();
t_ok(is_array($RP_q), 'the queue lists reports awaiting a board');
foreach ($RP_q as $q) {
    t_ok(in_array($q['status'], ['draft', 'moderating'], true), 'the queue holds only unpublished reports');
    t_ok(isset($q['ratifiable']), 'and says of each whether it could be ratified now');
    break;
}
