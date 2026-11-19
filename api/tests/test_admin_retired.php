<?php
/**
 * The legacy admin dashboard is retired (2026-11-19).
 *
 * admin/index.php drew forty-odd screens beside the console. The owner retired
 * that interface outright; the file survives ONLY as the place every admin write
 * is carried out (both consoles post their forms to it) and as the downloads
 * under ?v=export. These hold that line, so the old screens cannot creep back a
 * view at a time, and so nothing in the console still sends somebody to one.
 * (They replace test_admin_tables.php and test_admin_layout.php, which pinned
 * the retired screens' markup.)
 */
declare(strict_types=1);

$AR_root = dirname(__DIR__, 2);
$AR_idx  = (string) file_get_contents($AR_root . '/admin/index.php');

t_group('admin retired — admin/index.php draws no screens');

t_eq(substr_count(strtolower($AR_idx), '<!doctype html'), 1,
    'one HTML document is left in the file, and it is the printable curriculum download');
t_ok(!preg_match("/\\\$view === '(?!export')[a-z_]+'/", $AR_idx), 'no view branch remains except the downloads');
t_ok(str_contains($AR_idx, "header('Location: /admin/console.php?' . http_build_query(\$__to), true, 302);"),
    'every other GET goes to the console');
t_ok(str_contains($AR_idx, "\$_GET[\$__k] === '1' ? 'Saved.'"), '…carrying ?ok=/?err= across, with a bare "1" turned into words');

// Every retired view a bookmark might name lands on a console screen that exists.
preg_match('/\$__consoleFor = \[(.*?)\];/s', $AR_idx, $AR_m);
preg_match_all("/=> '([a-z]+)'/", $AR_m[1] ?? '', $AR_targets);
$AR_screens = mam_console_screens();
$AR_bad = array_values(array_diff(array_unique($AR_targets[1]), $AR_screens));
t_ok(count($AR_targets[1]) >= 30, 'the old view names are mapped [' . count($AR_targets[1]) . ']');
t_eq($AR_bad, [], 'and every one maps to a console screen that exists');

t_group('admin retired — nothing in the console sends somebody to a retired screen');

$AR_files = array_merge(glob($AR_root . '/partials/admin-console/*.php'), glob($AR_root . '/partials/admin-phone/*.php'),
    [$AR_root . '/admin/console.php', $AR_root . '/admin/phone.php', $AR_root . '/api/lib/console.php',
     $AR_root . '/api/lib/console-phone.php', $AR_root . '/assets/js/admin-console.js']);
$AR_links = [];
foreach ($AR_files as $f) {
    preg_match_all('#/admin/\?v=(?!export)[a-z_]+#', (string) file_get_contents($f), $mm);
    foreach ($mm[0] as $l) $AR_links[] = basename($f) . ': ' . $l;
}
t_eq($AR_links, [], 'no console link opens a retired screen (only the ?v=export downloads remain)');

t_group('admin retired — what the console needed from the old dashboard');

// The temporary-password gate used to send people to the retired ?v=account; a
// gate that catches its own destination loops for ever.
$AR_con = (string) file_get_contents($AR_root . '/admin/console.php');
t_ok(in_array('account', $AR_screens, true), 'the console has a My account screen');
t_ok(str_contains($AR_con, "=== 1 && \$screen !== 'account'"), 'the temporary-password gate exempts that screen');
t_ok(str_contains($AR_idx, "header('Location: /admin/console.php?v=account'); exit;"), 'and admin/index.php sends a must-change admin there');
$AR_acct = (string) file_get_contents($AR_root . '/partials/admin-console/account.php');
foreach (['change_own_account', 'session_revoke', 'session_revoke_others'] as $a) {
    t_ok(str_contains($AR_acct, "'$a'") && str_contains($AR_idx, "\$action === '$a'"), "My account posts `$a`, which is still handled");
}
// "Run migrations" pointed at a view that never existed; there is no shell on
// production, so this button is how a database behind the code is brought level.
t_ok(str_contains($AR_idx, "\$action === 'run_migrations'") && str_contains($AR_idx, "'run_migrations' => 'migrate',"),
    'Run migrations is a real action, behind the migrate capability');
t_ok(str_contains((string) file_get_contents($AR_root . '/partials/admin-console/system.php'), "'run_migrations'"),
    '…and System health posts it');

t_group('admin retired — the handlers it keeps still hold their rules');

// Kept from test_admin_tables.php: these describe handlers, not screens.
t_ok(str_contains($AR_idx, "\$action === 'enrol_complete'"), 'there is one action that completes an enrolment');
t_ok(str_contains($AR_idx, "'enrol_complete'  => 'admissions',"), '…behind the admissions capability');
t_ok(str_contains($AR_idx, "if (isset(\$_POST['level']) && \$_POST['level'] === '') \$overrides['level'] = '';"),
    'choosing "no level" is preserved rather than filtered away as empty');
t_ok(str_contains($AR_idx, "\$st['ok'] ? '' : '⚠ '"), 'every step of the result is reported, failures included');
$AR_pw = [];
foreach ($AR_files as $f) if (preg_match('/<input[^>]*name="password"/', (string) file_get_contents($f))) $AR_pw[] = basename($f);
t_eq($AR_pw, [], 'no admin form takes a password for somebody else');

t_group('console — the data-protection tier is read as the record mam_dp_tier() returns');

/* Found by the PHP log during the retirement sweep, not by any screen: both the
   Governance and Data protection screens printed the tier as "Array" and read
   duty keys that do not exist (`registers`, `files_returns`), so "Must
   register?" and "Files returns?" said No at every size. Pin the keys against
   the function itself, so a rename on either side fails here. */
$AR_tier = mam_dp_tier(6);
t_ok(is_array($AR_tier['tier'] ?? null) && isset($AR_tier['tier']['label']), 'the tier is a record with a label');
t_ok(array_key_exists('must_register', $AR_tier) && array_key_exists('must_file_car', $AR_tier), 'and the duties are must_register / must_file_car');
foreach (['governance', 'dataprotection'] as $AR_p) {
    $AR_src = (string) file_get_contents($AR_root . "/partials/admin-console/$AR_p.php");
    t_ok(!preg_match("/\\\$tier\\['(registers|files_returns)'\\]/", $AR_src), "$AR_p reads no duty key that does not exist");
    t_ok(!preg_match("/\\(string\\) \\(\\\$tier\\['tier'\\] \\?\\?/", $AR_src), "…and never prints the tier record as a string");
}
