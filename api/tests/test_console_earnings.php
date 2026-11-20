<?php
/**
 * Instructor earnings in the console (HANDOFF §4y — the button went with the
 * retired dashboard). The handler `wallet_credit` already existed; what is
 * pinned here is that the screen reads the ledger's own balance, that it posts
 * to that handler under the same capability, and the two rules the handler
 * gained: a correction is entered as a direction (never a typed minus sign)
 * and must say what it corrects — it is never a fine.
 */
declare(strict_types=1);

t_group('earnings — the screen reads the statement the instructor reads');

$EA_ins = (int) mam_db_insert('users', ['name' => 'Earn Ins', 'email' => 'earn_ins@test.local', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
mam_wallet_post($EA_ins, 12000, 'earnings', null, null, 'October lessons');
mam_wallet_post($EA_ins, -2000, 'earnings_adjust', null, null, 'Lesson on the 3rd counted twice');

$EA_d = mam_console_earnings();
$EA_row = null;
foreach ($EA_d['people'] as $p) if ((int) $p['id'] === $EA_ins) $EA_row = $p;
t_ok($EA_row !== null, 'an active instructor is offered');
t_eq((int) ($EA_row['balance'] ?? -1), 10000, 'their balance is the ledger\'s running total, after the correction');
t_eq(mam_wallet_balance($EA_ins), (int) $EA_row['balance'], 'and agrees with the figure their own Earnings tab shows');
$EA_notes = array_column(array_filter($EA_d['recent'], static fn ($r) => $r['instructor'] === 'Earn Ins'), 'note');
t_ok(in_array('Lesson on the 3rd counted twice', $EA_notes, true), 'a correction is listed with what it corrected');
t_ok($EA_d['owed_cents'] >= 10000, 'the total owed includes them');
t_eq(mam_console_failures(), [], 'every query on the screen ran');

t_group('earnings — the console reaches the existing handler');

$EA_adm = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
$EA_ui  = (string) file_get_contents(dirname(__DIR__, 2) . '/partials/admin-console/earnings.php');
t_ok(str_contains($EA_ui, "ac_form('wallet_credit', 'earnings')"), 'the screen posts to `wallet_credit`, never a second implementation');
t_ok(str_contains($EA_adm, "'wallet_credit' => 'users'"), 'which the `users` capability gates');
$EA_nav = [];
foreach (mam_console_nav() as $a) foreach ($a['sections'] as $s) $EA_nav[$s['key']] = $s;
t_eq($EA_nav['earnings']['cap'] ?? null, 'users', 'and the screen is offered under that same capability');
t_ok(str_contains($EA_ui, 'name="direction" value="subtract"') && !str_contains($EA_ui, 'min="-'),
    'a correction is a choice, not a minus sign somebody can forget');
t_ok(str_contains($EA_adm, "=== 'subtract') \$amountUsd = -abs(\$amountUsd)"), 'the handler applies that direction');
t_ok(str_contains($EA_adm, "\$amountUsd < 0 && mb_strlen((string) \$note) < 5"), 'and refuses a correction that does not say what it corrects');

mam_db()->exec('DELETE FROM wallet_ledger WHERE user_id = ' . $EA_ins);
mam_db()->exec('DELETE FROM users WHERE id = ' . $EA_ins);
