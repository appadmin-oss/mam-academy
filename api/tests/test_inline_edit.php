<?php
/**
 * Inline field editing with undo (Advanced Modernization §4.4).
 *
 * The interesting surface here is NOT that an edit works — it is everything
 * the endpoint must refuse. "Set $table.$column = $value where id = $id" is,
 * unconstrained, a privilege-escalation endpoint, so the allowlist is the
 * security boundary and most of this file exercises it.
 */
declare(strict_types=1);

t_group('inline edit — the allowlist IS the security boundary');

// Nothing touching identity, authentication or authorisation may ever be
// editable. If one of these starts returning a spec, someone has widened the
// allowlist into a privilege-escalation hole.
foreach ([
    ['users', 'role'], ['users', 'admin_tier'], ['users', 'password_hash'],
    ['users', 'email'], ['users', 'status'], ['users', 'student_type'],
] as $pair) {
    t_ok(mam_inline_field($pair[0], $pair[1]) === null, "users.{$pair[1]} is NOT inline-editable");
}
// Money already taken, and the audit trail itself, are equally off-limits.
t_ok(mam_inline_field('payments', 'amount') === null, 'payments.amount is not inline-editable');
t_ok(mam_inline_field('payments', 'status') === null, 'payments.status is not inline-editable');
t_ok(mam_inline_field('wallet_ledger', 'amount_cents') === null, 'the wallet ledger is not inline-editable');
t_ok(mam_inline_field('audit_log', 'action') === null, 'the audit log is not inline-editable');
// What a customer is CHARGED keeps its full form; only the item's name and
// stock are inline. This is a deliberate line, not an oversight.
t_ok(mam_inline_field('store_items', 'price_usd') === null, 'store price is deliberately not inline-editable');
t_ok(mam_inline_field('store_items', 'name') !== null, 'but the item name is');
t_ok(mam_inline_field('store_items', 'stock') !== null, 'and its stock is');

// An unknown table or column is simply not editable — no fallthrough.
t_ok(mam_inline_field('sqlite_master', 'name') === null, 'an internal table is not editable');
t_ok(mam_inline_field('cohorts', 'instructor_id') === null, 'an unlisted column on a listed table is not editable');
t_ok(mam_inline_field('nope', 'title') === null, 'a table that does not exist is not editable');

// Every entry must declare a capability and a type, or the route cannot guard
// it. A spec missing either would silently become world-editable.
foreach (mam_inline_fields() as $key => $spec) {
    t_ok(!empty($spec['cap']), "$key declares a required capability");
    t_ok(!empty($spec['type']), "$key declares a type");
}

t_group('inline edit — value validation');

$IE_spec = mam_inline_field('cohorts', 'title');
$bad = function ($spec, $v) { try { mam_inline_normalise($spec, $v); return null; } catch (\InvalidArgumentException $e) { return $e->getMessage(); } };

t_eq(mam_inline_normalise($IE_spec, '  Strings A  '), 'Strings A', 'text is trimmed');
t_eq($bad($IE_spec, ''), 'empty', 'an empty title is refused');
t_eq($bad($IE_spec, '   '), 'empty', 'whitespace only is refused');
t_eq($bad($IE_spec, str_repeat('x', 161)), 'too_long', 'over the length cap is refused');
t_eq(mam_inline_normalise($IE_spec, str_repeat('x', 160)), str_repeat('x', 160), 'exactly the cap is accepted');
// Length is counted in CHARACTERS. A byte-based check would refuse a title of
// accented or non-Latin text far below the real limit.
t_eq(mam_inline_normalise($IE_spec, str_repeat('é', 160)), str_repeat('é', 160), 'the cap counts characters, not bytes');
// Control characters would corrupt a single-line cell and can smuggle terminal
// escapes into logs that someone later cats.
t_eq($bad($IE_spec, "Strings\nA"), 'bad_characters', 'an embedded newline is refused');
t_eq($bad($IE_spec, "Strings\x1b[31mA"), 'bad_characters', 'a terminal escape is refused');
t_eq($bad($IE_spec, "Strings\x00A"), 'bad_characters', 'a null byte is refused');
// Markup is NOT rejected — it is stored verbatim and escaped on output, the
// same as every other field. Rejecting it here would be security theatre and
// would refuse a legitimate title like "Theory < Practice".
t_eq(mam_inline_normalise($IE_spec, 'Theory < Practice'), 'Theory < Practice', 'an angle bracket is stored, not rejected (output escapes)');

$IE_stock = mam_inline_field('store_items', 'stock');
t_eq(mam_inline_normalise($IE_stock, '42'), 42, 'a numeric string becomes an int');
t_eq(mam_inline_normalise($IE_stock, 0), 0, 'zero stock is valid');
// -1 is the store's "untracked" sentinel. A 0 floor would make an untracked
// item impossible to restore once someone had set a real count.
t_eq(mam_inline_normalise($IE_stock, -1), -1, '-1 (untracked) is valid');
t_eq($bad($IE_stock, -2), 'too_small', 'below -1 is refused');
t_eq($bad($IE_stock, '1000001'), 'too_large', 'an absurd stock is refused');
// A bare (int) cast turns "12abc" into 12 and "" into 0 — which is how a
// count silently becomes something nobody typed.
t_eq($bad($IE_stock, '12abc'), 'not_a_number', 'a partly-numeric string is refused, not silently truncated');
t_eq($bad($IE_stock, 'abc'), 'not_a_number', 'a non-number is refused');
t_eq($bad($IE_stock, ''), 'empty', 'an empty number is refused, not read as 0');
t_eq($bad($IE_stock, '1.5'), 'not_a_number', 'a decimal is refused for an int field');

t_group('inline edit — apply, no-op and undo');

$IE_course = mam_db_insert('courses', ['code' => 'ie_c', 'name' => 'IE Course', 'kind' => 'core']);
$IE_coh    = mam_db_insert('cohorts', ['course_id' => $IE_course, 'title' => 'Original Title', 'status' => 'active', 'capacity' => 10]);

$r = mam_inline_edit_apply('cohorts', 'title', $IE_coh, 'Renamed Cohort', null);
t_ok($r['changed'] === true, 'a real change reports changed');
t_eq($r['previous'], 'Original Title', 'the PREVIOUS value comes back — this is the whole undo mechanism');
t_eq($r['value'], 'Renamed Cohort', 'and the new value');
t_eq(mam_db_row('SELECT title FROM cohorts WHERE id = ?', [$IE_coh])['title'], 'Renamed Cohort', 'the row is actually written');
t_eq($r['label'], 'Cohort title', 'a human label rides along for the toast');

// Undo is not a separate mechanism: it is the same call with the old value.
$u = mam_inline_edit_apply('cohorts', 'title', $IE_coh, $r['previous'], null);
t_ok($u['changed'] === true, 'undo is itself a real, audited edit');
t_eq(mam_db_row('SELECT title FROM cohorts WHERE id = ?', [$IE_coh])['title'], 'Original Title', 'undo restores the original value exactly');

// Re-saving an unchanged cell must not write. A UI does this constantly
// (focus, blur, focus, blur) and it would bury real changes in the timeline.
$n = mam_inline_edit_apply('cohorts', 'title', $IE_coh, 'Original Title', null);
t_ok($n['changed'] === false, 'an unchanged value reports changed=false');
t_eq($n['previous'], 'Original Title', 'and still reports the current value');

// A no-op must hold for ints too, where '5' and 5 are the same value.
$IE_item = mam_db_insert('store_items', ['sku' => 'IE-SKU-1', 'slug' => 'ie-item-1', 'name' => 'IE Item', 'price_usd' => 10.00, 'currency' => 'USD', 'stock' => 5]);
$s1 = mam_inline_edit_apply('store_items', 'stock', $IE_item, '5', null);
t_ok($s1['changed'] === false, 'a numeric string equal to the stored int is a no-op');
$s2 = mam_inline_edit_apply('store_items', 'stock', $IE_item, '7', null);
t_ok($s2['changed'] === true, 'a genuinely different number does change');
t_eq((int) mam_db_row('SELECT stock FROM store_items WHERE id = ?', [$IE_item])['stock'], 7, 'and is written as an int');

t_group('inline edit — refusals at the apply layer');

$threw = function (callable $fn) { try { $fn(); return null; } catch (\Throwable $e) { return $e->getMessage(); } };

t_eq($threw(fn() => mam_inline_edit_apply('users', 'role', $IE_coh, 'admin', null)), 'not_editable', 'applying to a non-allowlisted field throws not_editable');
t_eq($threw(fn() => mam_inline_edit_apply('cohorts', 'title', 999999, 'x', null)), 'not_found', 'a missing row throws not_found');
t_eq($threw(fn() => mam_inline_edit_apply('cohorts', 'title', 0, 'x', null)), 'not_found', 'a zero id throws not_found');
t_eq($threw(fn() => mam_inline_edit_apply('cohorts', 'title', -5, 'x', null)), 'not_found', 'a negative id throws not_found');
// The validator runs BEFORE the row is touched, so a bad value cannot
// half-apply.
t_eq($threw(fn() => mam_inline_edit_apply('cohorts', 'title', $IE_coh, '', null)), 'empty', 'an invalid value throws before writing');
t_eq(mam_db_row('SELECT title FROM cohorts WHERE id = ?', [$IE_coh])['title'], 'Original Title', 'and the row is untouched after a rejected edit');

// Housekeeping: drop the throwaway course so the catalogue test's absolute
// count of 12 holds. The cohort cascades with it.
mam_db()->prepare('DELETE FROM store_items WHERE id = ?')->execute([$IE_item]);
mam_db()->prepare('DELETE FROM courses WHERE id = ?')->execute([$IE_course]);
