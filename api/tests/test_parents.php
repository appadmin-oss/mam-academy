<?php
/**
 * Tests for parent accounts + guardian portal (api/lib/parents.php, PDF §26):
 * provisioning + role grant, guardian↔student linking (idempotent), the
 * children list + weekly status, and access control on child detail.
 */
declare(strict_types=1);

t_group('parents — provision & link');

$PR_course = (int) (mam_db_row("SELECT id FROM courses LIMIT 1")['id'] ?? 0);
if ($PR_course === 0) $PR_course = mam_db_insert('courses', ['code' => 'pr_c', 'name' => 'PR', 'kind' => 'core']);
$PR_stu = mam_db_insert('users', ['name' => 'Kid One', 'email' => 'kid1@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active', 'enrollment_ref' => 'MAM/26/PR1']);
$PR_stu2 = mam_db_insert('users', ['name' => 'Kid Two', 'email' => 'kid2@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);

$prov = mam_parent_provision('guardian@test.local', 'Pat Guardian');
t_ok($prov['user'] !== null && (int) $prov['user']['id'] > 0, 'parent user provisioned');
t_eq($prov['user']['role'], 'parent', 'primary role is parent');
t_ok($prov['password'] === null && $prov['created'] === true, 'a new guardian account is created with no password — the invitation is the way in');
t_ok(mam_user_has_role($prov['user'], 'parent'), 'parent role granted');
// Re-provision is idempotent (no new password).
t_ok(mam_parent_provision('guardian@test.local')['created'] === false, 're-provision owes no invitation');

$link = mam_parent_link('guardian@test.local', $PR_stu, 'mother', null);
t_ok($link['created'], 'guardian linked to the child');
$parentId = (int) $link['parent_id'];
// Idempotent link.
t_ok(!mam_parent_link('guardian@test.local', $PR_stu)['created'], 're-linking the same pair is a no-op');
mam_parent_link('guardian@test.local', $PR_stu2); // a second child

t_group('parents — children list & access control');

$kids = mam_parent_children($parentId);
t_eq(count($kids), 2, 'parent sees both linked children');
$k1 = array_values(array_filter($kids, fn($k) => (int) $k['student_id'] === $PR_stu))[0];
t_eq($k1['relationship'], 'mother', 'relationship recorded');
t_ok(isset($k1['weekly']['attendance']), 'weekly status carries an attendance light');

t_ok(mam_parent_can_view($parentId, $PR_stu), 'parent may view a linked child');
$stranger = mam_db_insert('users', ['name' => 'Other Kid', 'email' => 'kid9@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
t_ok(!mam_parent_can_view($parentId, $stranger), 'parent may not view an unlinked child');

$detail = mam_parent_child_detail($parentId, $PR_stu);
t_ok(isset($detail['weekly']) && isset($detail['student']), 'child detail assembles weekly status + identity');
$threw = false;
try { mam_parent_child_detail($parentId, $stranger); } catch (\RuntimeException $e) { $threw = ($e->getMessage() === 'forbidden'); }
t_ok($threw, 'child detail is refused for an unlinked student');

// §26 — instructor feedback surfaces in the guardian view.
$PR_ins = mam_db_insert('users', ['name' => 'PR Ins', 'email' => 'pr_ins@test.local', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$PR_asg = mam_db_insert('assignments', ['student_id' => $PR_stu, 'title' => 'Etude', 'status' => 'open']);
$PR_sub = mam_db_insert('submissions', ['assignment_id' => $PR_asg, 'student_id' => $PR_stu, 'artifact_url' => 'https://x/y', 'artifact_kind' => 'link']);
mam_lms_give_feedback($PR_sub, $PR_ins, ['outcome' => 'remedial', 'comments' => 'Focus on bar 4 dynamics.']);
$detailFb = mam_parent_child_detail($parentId, $PR_stu);
t_ok(!empty($detailFb['instructor_feedback']), 'child detail surfaces instructor feedback (§26)');
t_eq($detailFb['instructor_feedback'][0]['comments'], 'Focus on bar 4 dynamics.', 'feedback comment reaches the guardian view');
t_eq($detailFb['instructor_feedback'][0]['outcome'], 'remedial', 'feedback outcome reaches the guardian view');

t_group('parents — guardian KPI strip (§5.2)');

// Everything the strip reports must degrade rather than throw: a guardian
// opening a brand-new child's page sees zeros and nulls, never a 500.
$PR_fresh = mam_db_insert('users', ['name' => 'Fresh Kid', 'email' => 'kid_fresh@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$PR_k0 = mam_parent_value_kpis($PR_fresh);
t_eq($PR_k0['practice_minutes'], 0, 'a child with no practice logs reports 0 minutes, not null');
t_eq($PR_k0['practice_hours'], 0.0, 'hours derive from minutes');
t_eq($PR_k0['badges_this_month'], 0, 'no badges yet');
t_ok($PR_k0['next_session'] === null, 'no upcoming session is null, not a fabricated entry');
t_ok($PR_k0['attendance_rate'] === null, 'attendance is null when the report carries none');

mam_db_insert('practice_logs', ['student_id' => $PR_stu, 'minutes' => 45, 'logged_on' => date('Y-m-d')]);
mam_db_insert('practice_logs', ['student_id' => $PR_stu, 'minutes' => 30, 'logged_on' => date('Y-m-d', strtotime('-3 days'))]);
$PR_k = mam_parent_value_kpis($PR_stu);
t_eq($PR_k['practice_minutes'], 75, 'practice minutes sum across all logs (lifetime, not this week)');
t_eq($PR_k['practice_hours'], 1.3, 'hours are minutes/60 rounded to one decimal');

// Badges are scoped to the CALENDAR month, so a badge from last month drops
// off. Asserted as a delta: mam_ach_evaluate() runs inside child detail and has
// already awarded this student real badges earlier in this file.
$PR_bBase = mam_parent_value_kpis($PR_stu)['badges_this_month'];
mam_db_insert('achievements', ['student_id' => $PR_stu, 'code' => 'pr_thismonth', 'title' => 'This month', 'awarded_at' => date('Y-m-01') . ' 09:00:00']);
mam_db_insert('achievements', ['student_id' => $PR_stu, 'code' => 'pr_lastmonth', 'title' => 'Last month', 'awarded_at' => date('Y-m-d H:i:s', strtotime(date('Y-m-01') . ' -5 days'))]);
t_eq(mam_parent_value_kpis($PR_stu)['badges_this_month'] - $PR_bBase, 1, 'only badges awarded this calendar month are counted');

// The strip reads attendance and next-session from the report it is handed,
// rather than re-querying — so a caller can pass a report through unchanged.
$PR_kr = mam_parent_value_kpis($PR_stu, [
    'attendance'        => ['rate' => 87.456],
    'upcoming_sessions' => [['title' => 'Recital rehearsal', 'scheduled_at' => '2026-09-01 10:00:00', 'cohort_title' => 'Strings A']],
]);
t_eq($PR_kr['attendance_rate'], 87.5, 'attendance rate is rounded to one decimal for display');
t_eq($PR_kr['next_session']['title'], 'Recital rehearsal', 'the next session is the first upcoming one');
t_eq($PR_kr['next_session']['cohort_title'], 'Strings A', 'the cohort travels with the next session');

$PR_detailK = mam_parent_child_detail($parentId, $PR_stu);
t_ok(isset($PR_detailK['value_kpis']), 'child detail carries the KPI block');
t_eq($PR_detailK['value_kpis']['practice_minutes'], 75, 'the KPI block in child detail matches a direct call');

t_group('parents — unlink');

mam_parent_unlink($parentId, $PR_stu2);
t_eq(count(mam_parent_children($parentId)), 1, 'unlinking removes a child');

t_group('parents — the mastery BAND, and the mean that is no longer published');

/**
 * The guardian surface is the one the mastery coverage floor exists to protect
 * (CLAUDE.md gotcha #12), and it was the one surface reaching past it: the
 * payload carried `mastery`, the raw renormalised mean out of the gradebook, and
 * both places the screen rendered it printed `Math.round(w.mastery) + '%'`.
 *
 * Adding a band BESIDE the mean did not fix that. These assertions pin the parts
 * that made the earlier attempt inert, so a later "tidy" cannot restore any of
 * them: the mean is absent, nothing in the payload equals `raw_score`, and the
 * traffic light comes from the reported LEVEL rather than from a percentage.
 */
$MB_stu = mam_db_insert('users', [
    'name' => 'Band Kid', 'email' => 'bandkid@test.local', 'role' => 'student',
    'password_hash' => 'x', 'status' => 'active',
]);
mam_parent_link('guardian@test.local', $MB_stu);
$MB_par = (int) mam_db_row("SELECT id FROM users WHERE email = 'guardian@test.local'")['id'];

// ── A child with nothing assessed: withheld, and said so.
$MB_w0 = mam_parent_weekly_status($MB_stu);
t_ok(!array_key_exists('mastery', $MB_w0), 'the raw mean has no key in the guardian payload');
t_ok($MB_w0['mastery_band'] !== null, 'the band block is present even with no evidence');
t_ok($MB_w0['mastery_band']['band'] === null, 'and reports no band rather than a zero');
t_ok($MB_w0['mastery_band']['sufficient'] === false, 'flagged as insufficient evidence');
t_eq($MB_w0['mastery_light'], 'none', 'so the light is neutral, not red');

// ── Thin evidence: a composite EXISTS internally but must not be recoverable.
// Two lesson_progress rows evidence `preparation` only — 10 of 100 weight, far
// below MAM_MASTERY_MIN_COVERAGE — so mam_mastery_score() withholds the score.
$MB_coh = mam_db_insert('cohorts', ['course_id' => $PR_course, 'title' => 'MB Cohort', 'status' => 'active', 'capacity' => 10]);
$MB_les = mam_db_insert('lessons', ['cohort_id' => $MB_coh, 'title' => 'MB Module', 'week_no' => 1, 'published' => 1]);
mam_lms2_lesson_progress_set($MB_les, $MB_stu, 80);

$MB_raw = mam_mastery_score($MB_stu);
t_ok($MB_raw['raw_score'] !== null, 'the engine does compute a renormalised mean at this coverage');
t_ok($MB_raw['sufficient'] === false, 'but withholds it — coverage is below the floor');

$MB_pub = mam_mastery_publishable($MB_raw);
t_ok(!array_key_exists('raw_score', $MB_pub), 'mam_mastery_publishable() strips raw_score');
t_ok($MB_pub['bounds'] === null,
    'and strips the bounds while the score is withheld: raw = bounds.min x 100 / coverage_pct recovers it exactly');
t_eq($MB_pub['withheld_reason'], 'below_coverage_floor', 'the reason is stated rather than left to be inferred');

/* The whole guardian payload, flattened, must contain the raw score nowhere —
 * not as a value, not as a string, not reachable by the bounds arithmetic. */
$MB_w1 = mam_parent_weekly_status($MB_stu);
$MB_flat = [];
array_walk_recursive($MB_w1, function ($v) use (&$MB_flat) { $MB_flat[] = $v; });
$MB_needle = (float) $MB_raw['raw_score'];
$MB_hit = false;
foreach ($MB_flat as $v) {
    if (is_numeric($v) && abs((float) $v - $MB_needle) < 0.05) { $MB_hit = true; break; }
}
t_ok(!$MB_hit, 'no value anywhere in the guardian payload is the withheld composite (' . $MB_needle . ')');
t_ok($MB_w1['mastery_band']['bounds'] === null, 'the band block carries no bounds while withheld');
t_ok(!empty($MB_w1['mastery_band']['components']),
    'per-component evidence still travels — the design draws a bar per criterion, so subscores are guardian-facing');

// ── The band, when it is reported, is a BAND: whole numbers, ten points wide
// unless clamped at the top of the scale. This is the shape the v85 CHECK
// admits, so the engine and the schema cannot disagree.
$MB_band = mam_report_band($MB_raw['raw_score']);
t_ok($MB_band !== null, 'a score yields a band');
t_ok($MB_band['low'] === (int) $MB_band['low'] && $MB_band['high'] === (int) $MB_band['high'], 'both bounds are whole');
t_ok($MB_band['high'] - $MB_band['low'] === 9 || $MB_band['high'] === 100,
    'ten points wide, or clamped against the top of the scale');
t_ok(mam_report_band(100)['low'] === 100 && mam_report_band(100)['high'] === 100,
    'a perfect score bands as (100,100) — the shape the old CHECK refused');
t_ok(mam_report_band(null) === null, 'no score, no band');

// ── The memo returns the same answer, and only computes once.
t_eq(json_encode(mam_mastery_score_cached($MB_stu)), json_encode(mam_mastery_score_cached($MB_stu)),
    'the memoised score is stable within a request');

t_group('parents — the children query runs against the LIVE schema');

/**
 * `mam_parent_children()` is on the critical path of the entire guardian portal:
 * every screen starts from it, so a wrong column name there is not an empty card,
 * it is a 500 on the first page a family opens.
 *
 * This is the guardian-side twin of the check `test_admin_screens.php` runs over
 * the admin views (CLAUDE.md gotcha #9), and it exists because a real one got
 * through: the child subtitle was written as `classes.name`, and the legacy
 * `classes` table has `title`. Nothing in the suite touched it, and it was found
 * by opening the portal in a browser. Executing the query is the only check that
 * would have caught it, so the check executes it.
 */
$PC_par = (int) mam_db_row("SELECT id FROM users WHERE email = 'guardian@test.local'")['id'];
$PC_kids = mam_parent_children($PC_par);
t_ok(is_array($PC_kids), 'the children query executes against the live schema');
foreach ($PC_kids as $PC_k) {
    foreach (['student_id', 'student_name', 'weekly'] as $PC_key) {
        t_ok(array_key_exists($PC_key, $PC_k), "each child carries '$PC_key'");
    }
    // The design's card names the course and level; these may be null, but the
    // KEYS must exist or the client renders `undefined` into the subtitle.
    foreach (['course_name', 'level_name', 'legacy_class'] as $PC_key) {
        t_ok(array_key_exists($PC_key, $PC_k), "and '$PC_key' for the card subtitle (may be null)");
    }
    break;
}

/* Every guardian-facing READER has to execute. Extracting the SQL with a regex
 * was tried and is the wrong tool — a query containing `IN ('active','completed')`
 * terminates any quote-delimited pattern at the wrong place — so the check CALLS
 * the functions instead, which is both robust and closer to what breaks: a PDO
 * exception inside a guardian route is a 500 on the first page a family opens,
 * not a quietly empty panel. */
$PC_stu = $PC_kids ? (int) $PC_kids[0]['student_id'] : 0;
$PC_readers = [
    'mam_parent_children'            => fn() => mam_parent_children($PC_par),
    'mam_parent_weekly_status'       => fn() => mam_parent_weekly_status($PC_stu),
    'mam_parent_child_detail'        => fn() => mam_parent_child_detail($PC_par, $PC_stu),
    'mam_parent_guardians_of'        => fn() => mam_parent_guardians_of($PC_stu),
    'mam_parent_value_kpis'          => fn() => mam_parent_value_kpis($PC_stu),
    'mam_parent_instructor_feedback' => fn() => mam_parent_instructor_feedback($PC_stu),
    'mam_parent_scope'               => fn() => mam_parent_scope($PC_par, $PC_stu),
    'mam_parent_scope_explain'       => fn() => mam_parent_scope_explain($PC_par, $PC_stu),
];
foreach ($PC_readers as $PC_name => $PC_fn) {
    try { $PC_fn(); t_ok(true, "$PC_name() executes against the live schema"); }
    catch (\Throwable $e) { t_ok(false, "$PC_name() executes against the live schema — " . $e->getMessage()); }
}
