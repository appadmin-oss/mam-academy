<?php
/**
 * The calendar's writes back in the console (HANDOFF §4y), and two display
 * faults found on the way: the refund card read `refund_pct`/`until_on` and the
 * census column `headcount`/`taken_at` — none of which exist — so every
 * published refund read 0% and every census taken read 0, on the screen that is
 * the only authority on a refund.
 */
declare(strict_types=1);

t_group('console calendar — the screen reads the keys the calendar writes');

$CC_ui  = (string) file_get_contents(dirname(__DIR__, 2) . '/partials/admin-console/calendar.php');
$CC_adm = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
t_ok(str_contains($CC_ui, "\$r['percent']") && !str_contains($CC_ui, 'refund_pct'),
    'a refund band shows its stored `percent`, not a key that does not exist');
t_ok(str_contains($CC_ui, "['census']['enrolled']") && !str_contains($CC_ui, 'headcount'),
    'a census shows its stored `enrolled` count');
$CC_row = mam_calendar_refund_schedule(0);
t_eq($CC_row, [], 'the schedule reader returns rows shaped week_from / week_to / percent (none for an unknown term)');

t_group('console calendar — each form posts to the existing handler');

foreach (['term_save', 'term_seed_year', 'term_refund_reseed', 'closure_add', 'closure_delete', 'term_census'] as $CC_a) {
    t_ok(str_contains($CC_ui, "'$CC_a', 'calendar'"), "the screen posts `$CC_a`");
    t_ok(str_contains($CC_adm, "'$CC_a' => 'settings'"), "…which the `settings` capability gates");
}
t_ok(str_contains($CC_ui, "mam_admin_can(\$adminUser, 'settings')"), 'and the forms are offered under that same capability');
t_ok(str_contains($CC_adm, "'concern', 't'] as \$k"),
    'a handler\'s `t=` survives the console redirect, so a saved term comes back open for editing');

$CC_d = mam_console_calendar(['t' => 999999]);
t_eq($CC_d['selected'], null, 'an unknown term in the URL selects nothing (a blank form authors a new term)');
t_eq(mam_console_failures(), [], 'every query on the screen ran');
