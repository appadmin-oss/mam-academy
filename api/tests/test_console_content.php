<?php
/**
 * What the console's screens SAY must come from keys their data actually has.
 *
 * A read of a key that does not exist is not an error here: `$row['x'] ?? 0`
 * quietly shows the default, and the default is always plausible. Seven of
 * them were live at once (2026-11-20 sweep), each on a screen somebody acts on:
 * Staff & vetting and Governance read `gaps`/`expiring` and said "0 need
 * action" with 61 instructors holding no check at all; the rail badge read the
 * same and never flagged it; the Dashboard read `state`/`days`/`detail` and
 * called an instructor with nothing on file "clearance lapsing", never
 * blocking; Attendance read `notice`; Data protection read a breach `summary`
 * and `breach_hours`; System and Settings read an automation's `label`/`when`.
 * These checks pin each read to its source's real keys.
 */
declare(strict_types=1);

$CT_p = static fn(string $f): string => (string) file_get_contents(dirname(__DIR__, 2) . '/' . $f);

t_group('console content — vetting figures read the summary\'s own keys');

$CT_sum = mam_vetting_summary();
foreach (['needs_action', 'expiring_soon'] as $k) t_ok(array_key_exists($k, $CT_sum), "mam_vetting_summary() returns `$k`");
foreach (['partials/admin-console/vetting.php' => '$s', 'partials/admin-console/governance.php' => '$vet'] as $f => $v) {
    $src = $CT_p($f);
    t_ok(!preg_match('/' . preg_quote($v, '/') . "\\['(gaps|expiring)'\\]/", $src), "$f reads no key the summary has never returned");
    t_ok(str_contains($src, $v . "['needs_action']"), "$f shows the number that need action");
}
t_ok(str_contains($CT_p('api/modules/admin/lib/console.php'), "\$b['vetting'] = (int) ((\$v['needs_action'] ?? 0) + (\$v['expiring_soon'] ?? 0));"),
    'the rail badge counts the same two figures');

t_group('console content — the Dashboard names an instructor\'s real vetting state');

$CT_ins = (int) mam_db_insert('users', ['name' => 'CT Unvetted', 'email' => 'ct_unvetted@test.local', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$CT_row = null;
foreach (mam_console_dashboard()['decisions'] ?? [] as $q) if (str_starts_with((string) $q['title'], 'CT Unvetted')) $CT_row = $q;
foreach (array_slice(mam_vetting_attention(500), 0, 3) as $a) if ((int) $a['instructor_id'] === $CT_ins) $CT_top = true;
if ($CT_row) {
    t_ok(str_contains($CT_row['title'], 'no checks on file'), 'an instructor with nothing on file is not called "lapsing"');
    t_ok(!empty($CT_row['blocking']), 'and is blocking — not cleared to teach a child');
    t_ok(str_contains($CT_row['why'], 'required checks are not on file'), 'and the sentence says why, from the record');
} else {
    // The Dashboard lists the first three only; prove the rule on the shape instead.
    $CT_src = $CT_p('api/modules/admin/lib/console.php');
    t_ok(str_contains($CT_src, "'no checks on file'") && !preg_match("/\\\$v\\['(state|days|detail)'\\]/", $CT_src),
        'the Dashboard derives the state from ok / missing / expired, never from keys attention() has never returned');
}
mam_db()->exec('DELETE FROM users WHERE id = ' . $CT_ins);

t_group('console content — other reads pinned to their source');

$CT_w = mam_checkin_window(gmdate('Y-m-d H:i:s', time() + 3600));
t_ok(array_key_exists('open_before_min', $CT_w) && array_key_exists('ready_before_min', $CT_w), 'the check-in window carries its two boundaries');
t_ok(!str_contains($CT_p('partials/admin-console/attendance.php'), "\$w['notice']") && str_contains($CT_p('partials/admin-console/attendance.php'), "\$w['open_before_min']"),
    'Attendance states the rule from those two numbers');
t_ok(array_key_exists('breach_ndpc_hours', MAM_DP_DEADLINES) && str_contains($CT_p('partials/admin-console/dataprotection.php'), "\$dl['breach_ndpc_hours']"),
    'the breach clock reads the deadline the constant actually names');
t_ok(!str_contains($CT_p('partials/admin-console/dataprotection.php'), "\$b['summary']"), 'a breach is titled from its own record, not a key it has never had');
$CT_reg = mam_automations_registry(); $CT_first = reset($CT_reg);
t_ok(isset($CT_first['action'], $CT_first['trigger']), 'an automation rule carries `action` and `trigger`');
foreach (['system', 'settings'] as $f) {
    $src = $CT_p("partials/admin-console/$f.php");
    t_ok(str_contains($src, "\$rule['action']") && str_contains($src, "\$rule['trigger']") && !str_contains($src, "\$rule['when']"),
        "$f.php shows what each rule does and when it fires");
}

t_group('console content — instructor flags can be closed again');

$CT_adm = $CT_p('admin/index.php'); $CT_ded = $CT_p('partials/admin-console/deductions.php');
t_ok(str_contains($CT_adm, "'fine_resolve' => 'enrollments'") && str_contains($CT_adm, 'mam_fine_resolve($fid'),
    'a form handler reaches the one implementation, under the capability the JSON route uses');
t_ok(str_contains($CT_ded, "ac_form('fine_resolve', 'deductions'") && !str_contains($CT_ded, 'legacy fines screen'),
    'the Deductions screen offers it, and no longer points at a screen that was retired');
t_ok(!str_contains($CT_p('partials/admin-console/admissions.php'), 'legacy screen'), 'nor does Admissions');

t_group('console layout — the shared pieces');

t_eq(ac_join(['conducting', ' ', 'Ann']), 'conducting · Ann', 'a meta line drops a part that is only whitespace');
t_eq(ac_join(['', ' AR Ins ']), 'AR Ins', 'and never starts with a separator');
$CT_bad = [];
foreach (glob(dirname(__DIR__, 2) . '/partials/admin-console/*.php') as $f)
    if (str_contains((string) file_get_contents($f), "trim(implode(' · ', array_filter([")) $CT_bad[] = basename($f);
t_eq($CT_bad, [], 'no screen joins a meta line by hand (the hand-rolled join kept a lone space)');
ob_start(); ac_kpis([['label' => 'X', 'value' => '1']]); $CT_k = (string) ob_get_clean();
t_ok(str_contains($CT_k, 'ac-kpis--tiles'), 'figure tiles are marked as tiles, so a phone lays them two to a row');
$CT_tt = $CT_p('partials/admin-console/timetable.php');
t_ok(str_contains($CT_tt, '<div class="ac-weekwrap">') && !preg_match('/ac-tablewrap">\s*<div class="ac-week"/', $CT_tt),
    'the week grid scrolls in its own wrapper — inside .ac-tablewrap a phone clipped Wednesday to Sunday');
t_ok(str_contains($CT_p('assets/js/admin-console.js'), "querySelector('[aria-current=\"page\"]')"),
    'the section strip brings the current section into view on a phone');

t_group('console structure — each screen has its one page heading');

/* The console's page title was an <h2> and nothing on the page was an <h1>,
   so a screen reader's list of headings began one level down on every one of
   the console's screens. The phone subset had it right. */
ob_start(); ac_head('Probe', ''); $CT_h = (string) ob_get_clean();
t_ok(str_contains($CT_h, '<h1>Probe</h1>'), 'ac_head() renders the page title as the h1');
$CT_multi = [];
foreach (glob(dirname(__DIR__, 2) . '/partials/admin-console/*.php') as $f)
    if (substr_count((string) file_get_contents($f), 'ac_head(') > 1) $CT_multi[] = basename($f);
t_eq($CT_multi, [], 'no screen draws two page titles');
