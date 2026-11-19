<?php
/**
 * The legacy admin's (admin/index.php) layout repairs, 2026-11-19.
 *
 * Measured on all 42 views at 1440 and 390 before the change: 101 runs of text
 * sat against a card's border (a .card has no padding of its own), the pager
 * was clipped by the rounded corner, every card painted a dark scroll shadow
 * down its right edge whether it scrolled or not, the phone drawer cast its
 * shadow while hidden, and inline two-column forms ran off a phone screen.
 * A browser is what found these; these pin the mechanisms so a later edit
 * cannot quietly take one back out.
 */
declare(strict_types=1);

$AL_src = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');

t_group('admin layout — cards, pager, shadows, phone');

t_ok(str_contains($AL_src, '.card > :is(p,form,h2,h4,ul,ol,dl,summary,details,.pager,.kpi-label,.kpi-value,'), 'content placed straight into a card gets the header\'s 20px gutter');
t_ok(str_contains($AL_src, ':not(:has(table))'), '— and a table, or a wrapper holding one, stays full-bleed so the pinned actions column is not inset');
t_ok(str_contains($AL_src, "\$out = '<div class=\"pager\">'"), 'the pager is styled by class, not an inline row flush to the corner');
t_ok(str_contains($AL_src, '.card:not(.is-overflowing){background-image:none}') && str_contains($AL_src, "classList.toggle('is-overflowing'"), 'scroll shadows only on a card that really overflows, and something sets that class');
t_ok(str_contains($AL_src, 'body.nav-open aside{box-shadow:'), 'the phone drawer casts its shadow only while open');
t_ok(str_contains($AL_src, 'main [style*="grid-template-columns:1fr 1fr"]'), 'inline two-column forms stack on a phone');
t_ok(!str_contains($AL_src, "\$_GET['ce'] : 'all'"), 'the enrolment filter reads its parameter once (it was null, not "all", with no filter given)');
