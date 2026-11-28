<?php
/**
 * A termly report reaching a family, end to end (console Assessment & boards).
 *
 *   draft (the instructor sends it) → moderating (first second-marking) →
 *   ratify REFUSED with the lib's own reason until the rules are met → ratified
 *   → a standard guardian sees it and is told; a billing-only guardian neither
 *   sees it nor is told → the guardian appeals inside the window → the panel
 *   decides → the guardian sees the decision.
 *
 * Plus the console's contract: the new admin actions are named in $actionCaps
 * (deny by default), the status set is the schema CHECK, and the screen takes
 * "can this be ratified?" from mam_report_ratify_refusal() rather than
 * re-deriving the rules.
 */
declare(strict_types=1);

$RR_root = dirname(MAM_API_ROOT);
$RR_tag  = 'rr' . substr((string) mt_rand(100000, 999999), 0, 6);
$RR_mk   = fn(string $t, string $role) => (int) mam_db_insert('users', ['email' => "{$RR_tag}_$t@test.local", 'password_hash' => 'x',
    'role' => $role, 'name' => 'RR ' . strtoupper($t), 'status' => 'active']);

/* ─── 1 · the contract ───────────────────────────────────────────────────── */
t_group('report release — the status set is the schema CHECK, on both engines');

$RR_db = (string) file_get_contents(MAM_API_ROOT . '/modules/kernel/lib/db.php');
$RR_my = (string) file_get_contents($RR_root . '/db/schema-mysql.sql');
$RR_set = "'" . implode("','", MAM_REPORT_STATUSES) . "'";
t_ok(str_contains($RR_db, "CHECK(status IN ($RR_set))"), 'MAM_REPORT_STATUSES is exactly the SQLite termly_reports CHECK');
t_ok(str_contains($RR_my, "chk_treport_status CHECK (status IN ($RR_set))"), '…and exactly the MySQL one');

t_group('report release — the new admin actions are deny-by-default mapped');

$RR_admin = (string) file_get_contents($RR_root . '/admin/index.php');
preg_match('/\$actionCaps = \[(.*?)\n    \];/s', $RR_admin, $RR_m);
foreach (['report_moderate', 'report_ratify', 'report_withdraw', 'appeal_decide'] as $RR_a) {
    t_ok((bool) preg_match("/'$RR_a' => 'curriculum'/", $RR_m[1] ?? ''), "$RR_a is named in \$actionCaps with the curriculum capability");
    t_ok(str_contains($RR_admin, "\$action === '$RR_a'"), "…and has a handler");
}
t_eq(mam_admin_capabilities()['curriculum'] ?? null, 'super', 'curriculum is super-only, so a manager can neither ratify nor overturn a ratified mark');

$RR_partial = (string) file_get_contents($RR_root . '/partials/admin-console/assessment.php');
t_ok(str_contains($RR_partial, "\$det['refusal']") && str_contains($RR_partial, "\$det['refusal_text']"),
    'the screen reads the refusal and its sentence from the provider');
t_ok(!str_contains($RR_partial, 'MAM_REPORT_MODERATION') && !str_contains($RR_partial, 'min_sample\'] ?? 0) &&'),
    'and does not restate the moderation floor as a rule of its own');
t_ok(!preg_match('/\sstyle=/', $RR_partial), 'no inline style on the screen');
$RR_console = (string) file_get_contents(MAM_API_ROOT . '/modules/admin/lib/console.php');
t_ok(str_contains($RR_console, 'mam_report_ratify_refusal($id, $actor)'), 'the provider asks the lib, with the viewing admin as the ratifier');

/* ─── 2 · draft → moderating ─────────────────────────────────────────────── */
t_group('report release — the instructor sends a draft, the panel moderates it');

$RR_kid   = $RR_mk('kid', 'student');
$RR_teach = $RR_mk('teach', 'instructor');
$RR_chair = $RR_mk('chair', 'admin');
$RR_mod2  = $RR_mk('mod2', 'instructor');

// What POST /api/instructor/report does: build the draft, attach the comment.
$RR_rid = mam_report_build($RR_kid, null, $RR_teach);
mam_report_set_comment($RR_rid, 'Plays the C major scale evenly now. Next: hands together.', $RR_teach);
$RR_route = (string) file_get_contents(MAM_API_ROOT . '/routes/instructor/report.php');
t_ok(str_contains($RR_route, 'mam_report_build(') && str_contains($RR_route, 'mam_report_set_comment('),
    'the instructor route is exactly build + comment, so this is the path a draft takes');
t_ok(str_contains((string) file_get_contents($RR_root . '/assets/js/portal-instructor.js'), "API.request('instructor/report'"),
    'the instructor portal Report screen posts to it');
t_eq((string) mam_report_get($RR_rid)['status'], 'draft', 'the sent report is a draft');
t_ok(in_array($RR_rid, array_column(mam_report_pending(), 'id'), true), 'and is in the panel\'s queue');

// The mastery engine has no evidence for a fresh student, so score eight
// criteria directly — the test is about release, not about mastery.
$RR_codes = array_column(mam_db_all('SELECT competency_code FROM termly_report_criteria WHERE report_id = ? ORDER BY sort_order', [$RR_rid]), 'competency_code');
foreach (array_slice($RR_codes, 0, 8) as $RR_i => $RR_c) {
    mam_db()->prepare("UPDATE termly_report_criteria SET level = 'secure', pct = ?, grade_label = 'Pass' WHERE report_id = ? AND competency_code = ?")
        ->execute([62 + $RR_i, $RR_rid, $RR_c]);
}
$RR_ms = mam_report_moderation_state($RR_rid);
t_eq([$RR_ms['scored'], $RR_ms['required']], [8, 2], 'eight scored criteria need a sample of two (the floor beats 25%)');

/* ─── 3 · ratify refused, with the reason, until the rules are met ───────── */
t_group('report release — ratification is refused with the lib\'s reason until it is not');

t_eq(mam_report_ratify_refusal($RR_rid, $RR_chair), 'moderation_short', 'unmoderated: refused, sample short');
t_ok(str_contains(mam_report_refusal_text('moderation_short', $RR_ms), '0 of 2'), 'the sentence gives the exact figure [' . mam_report_refusal_text('moderation_short', $RR_ms) . ']');
t_throws(fn() => mam_report_ratify($RR_rid, $RR_chair), 'moderation_short', 'and ratify() throws the same code the refusal named');
t_eq(mam_report_ratify_refusal($RR_rid, null), 'no_ratifier', 'with no named person: refused, no ratifier (checked before the sample)');

mam_report_moderate($RR_rid, $RR_codes[0], $RR_mod2, true, null, null);
t_eq((string) mam_report_get($RR_rid)['status'], 'moderating', 'the first moderation moves the draft to moderating');
t_eq(mam_report_ratify_refusal($RR_rid, $RR_chair), 'moderation_short', 'one of two sampled: still short');

mam_report_moderate($RR_rid, $RR_codes[1], $RR_mod2, false, 'working', 'Two wrong notes in the second phrase on the recording.');
$RR_staffAppeal = mam_report_appeal_open($RR_rid, $RR_chair, 'staff', 'Recording for criterion 3 is the wrong week.');
t_eq(mam_report_ratify_refusal($RR_rid, $RR_chair), 'appeal_open', 'sample met but a staff appeal is open: refused, appeal open');
t_throws(fn() => mam_report_ratify($RR_rid, $RR_chair), 'appeal_open', 'ratify() agrees');

// The console shows the same refusal the lib gives — and its sentence.
$RR_view = mam_console_assessment(['id' => $RR_rid]);
t_eq($RR_view['detail']['refusal'] ?? null, mam_report_ratify_refusal($RR_rid, null),
    'the console provider\'s refusal is the lib\'s, for the viewing admin');
$RR_list = mam_console_assessment(['status' => 'moderating']);
t_ok(in_array($RR_rid, array_map('intval', array_column($RR_list['list'], 'id')), true)
    && !array_filter($RR_list['list'], fn($r) => $r['status'] !== 'moderating'), 'the status filter lists only that status');
t_eq(array_keys($RR_list['reports']), MAM_REPORT_STATUSES, 'the console counts every status the CHECK allows, in order');

$GLOBALS['MAM_CONSOLE_CSRF'] = 'test-token';
require_once $RR_root . '/partials/admin-console/_ui.php';
$RR_render = (function (array $d) use ($RR_root): string {
    ob_start(); include $RR_root . '/partials/admin-console/assessment.php'; return (string) ob_get_clean();
})($RR_view);
t_ok((bool) preg_match('/<button type="submit" class="ac-btn ac-btn--primary" disabled aria-describedby="ac-ratify-why">Ratify and release/', $RR_render),
    'the Ratify button is rendered disabled');
t_ok(str_contains($RR_render, ac_h(mam_report_refusal_text((string) $RR_view['detail']['refusal'], $RR_view['detail']['moderation']))),
    'and the exact refusal sentence is shown beside it');
t_ok(str_contains($RR_render, 'name="action" value="report_moderate"') && str_contains($RR_render, 'name="action" value="report_withdraw"'),
    'the report offers moderation and withdrawal');
t_ok(str_contains($RR_render, 'Adjusted to Working towards'), 'an adjustment shows the second marker\'s level, not an average');

mam_report_appeal_decide($RR_staffAppeal, 'declined', $RR_chair, 'Checked: the recording is the right week.');
t_eq(mam_report_ratify_refusal($RR_rid, $RR_chair), null, 'appeal decided and sample met: nothing stands in the way');

$RR_empty = mam_report_build($RR_mk('kid2', 'student'), null, $RR_teach);
t_eq(mam_report_ratify_refusal($RR_empty, $RR_chair), 'no_evidence', 'a report with nothing scored is refused for no evidence');

/* ─── 4 · ratified; who sees it, who is told ─────────────────────────────── */
t_group('report release — ratified: a standard guardian sees it and is told; billing-only does not');

$RR_std  = (int) mam_parent_link("{$RR_tag}_std@test.local", $RR_kid)['parent_id'];
$RR_bill = (int) mam_parent_link("{$RR_tag}_bill@test.local", $RR_kid)['parent_id'];
mam_parent_scope_set($RR_bill, $RR_kid, 'billing', null);
t_eq(mam_parent_scope($RR_std, $RR_kid), 'standard', 'the first guardian holds the standard scope');

t_eq(mam_report_for_guardian($RR_std, $RR_kid)['reports'], [], 'before ratification the guardian sees nothing (moderating is not shown)');

$RR_rat = mam_report_ratify($RR_rid, $RR_chair, 'Board of 28 Nov');
t_eq($RR_rat['status'], 'ratified', 'ratified');

$RR_seen = mam_report_for_guardian($RR_std, $RR_kid);
t_eq(array_column($RR_seen['reports'], 'id'), [$RR_rid], 'the standard guardian sees the ratified report');
t_ok(!isset($RR_seen['reports'][0]['moderations']), 'with no staff-side moderation detail');
$RR_billSeen = mam_report_for_guardian($RR_bill, $RR_kid);
t_ok($RR_billSeen['scope_denied'] === true && $RR_billSeen['reports'] === [], 'the billing-only guardian sees no report');

$RR_note = fn(int $u) => (int) mam_db_row("SELECT COUNT(*) n FROM notifications WHERE user_id = ? AND type = 'report.ratified'", [$u])['n'];
t_eq($RR_note($RR_std), 1, 'the standard guardian got the portal notification (the url is a string — an array was a swallowed TypeError)');
t_eq($RR_note($RR_bill), 0, 'the billing-only guardian was told nothing');
t_eq($RR_note($RR_kid), 1, 'the student was told');

// Channel preferences: email follows mam_guardian_channels(); turning it off stops it.
mam_guardian_prefs_set($RR_std, ['email' => 1, 'whatsapp' => 0, 'sms' => 0]);
t_ok(in_array($RR_std, mam_report_notify_ratified($RR_rid)['emailed'], true), 'a guardian with email on is emailed');
mam_guardian_prefs_set($RR_std, ['email' => 0, 'whatsapp' => 0, 'sms' => 0]);
$RR_n2 = mam_report_notify_ratified($RR_rid);
t_ok(!in_array($RR_std, $RR_n2['emailed'], true) && in_array($RR_std, $RR_n2['notified'], true),
    'with email off: no email, but the portal notification still arrives');
t_ok(in_array($RR_bill, $RR_n2['out_of_scope'], true) && !in_array($RR_bill, $RR_n2['emailed'], true),
    'the billing-only guardian is out of scope for every channel');

/* ─── 5 · appeal and decision ────────────────────────────────────────────── */
t_group('report release — the guardian appeals, the panel decides, the guardian sees it');

t_ok(!empty($RR_seen['reports'][0]['appeal']['open']), 'the appeal window is open on a fresh ratification');
t_throws(fn() => mam_report_appeal_open($RR_rid, $RR_bill, 'parent', 'I want to contest this.'), 'forbidden',
    'a billing-only guardian cannot appeal a report their scope never showed them');
$RR_ap = mam_report_appeal_open($RR_rid, $RR_std, 'parent', 'The aural mark does not match the teacher\'s feedback.', $RR_codes[1]);
t_ok($RR_ap > 0, 'the standard guardian opens one appeal');
t_throws(fn() => mam_report_appeal_open($RR_rid, $RR_std, 'parent', 'again'), 'appeal_already_open', 'a second open appeal from them is refused');

$RR_q = mam_console_assessment([]);
t_ok(in_array($RR_ap, array_map('intval', array_column(array_filter($RR_q['appeals'], fn($a) => $a['status'] === 'open'), 'id')), true),
    'the appeal is in the console queue, open');

mam_report_appeal_decide($RR_ap, 'upheld', $RR_chair, 'Upheld: the aural criterion will be re-marked and a corrected report issued.');
$RR_after = mam_report_for_guardian($RR_std, $RR_kid)['reports'][0]['my_appeals'][0] ?? [];
t_eq([$RR_after['status'] ?? null, $RR_after['outcome_note'] ?? null],
    ['upheld', 'Upheld: the aural criterion will be re-marked and a corrected report issued.'], 'the guardian sees the decision and its sentence');
t_ok(str_contains((string) file_get_contents($RR_root . '/assets/js/portal-guardian.js'), 'a.outcome_note'),
    'and the guardian portal prints the outcome note');

/* ─── 6 · guardian child view carries marked class-space work, by scope ─── */
t_group('report release — marked class-space work in the guardian view respects scope');

$RR_gjs = (string) file_get_contents($RR_root . '/assets/js/portal-guardian.js');
t_ok(str_contains($RR_gjs, 'spaceMarksCard(') && str_contains($RR_gjs, 'd.space_marks'), 'the guardian portal renders space_marks');
t_ok(in_array('space_marks', mam_parent_child_detail($RR_bill, $RR_kid)['withheld'], true), 'withheld from a billing-only guardian');
t_ok(!in_array('space_marks', mam_parent_child_detail($RR_std, $RR_kid)['withheld'], true), 'sent to a standard guardian');

/* ─── 7 · withdrawal ─────────────────────────────────────────────────────── */
t_group('report release — withdrawing takes it back from the family and keeps the row');

t_throws(fn() => mam_report_withdraw($RR_rid, $RR_chair, '  '), 'reason_required', 'a withdrawal needs a reason');
mam_report_withdraw($RR_rid, $RR_chair, 'Upheld appeal: corrected report to follow.');
t_eq(mam_report_for_guardian($RR_std, $RR_kid)['reports'], [], 'a withdrawn report disappears from the family\'s list');
t_eq((string) mam_db_row('SELECT status FROM termly_reports WHERE id = ?', [$RR_rid])['status'], 'withdrawn', 'the row is kept, marked withdrawn');
t_eq(mam_report_ratify_refusal($RR_rid, $RR_chair), 'withdrawn', 'and it cannot be ratified again');
