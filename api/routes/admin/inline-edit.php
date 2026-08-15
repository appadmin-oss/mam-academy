<?php
/**
 * POST /api/admin/inline-edit {table, column, id, value}
 *   → {changed, previous, value, label}
 *
 * Sets one allowlisted field on one row (Advanced Modernization §4.4). The
 * response carries the PREVIOUS value, which is the entire undo mechanism: the
 * client offers "Undo" and, if taken, calls this same endpoint with that value.
 *
 * The route resolves the field FIRST so it can enforce that field's own admin
 * capability. A single blanket cap would mean whoever may rename a cohort may
 * also rewrite the course catalogue.
 */
declare(strict_types=1);

$u  = mam_require_user();
$in = mam_json_input();

$table  = trim((string) ($in['table'] ?? ''));
$column = trim((string) ($in['column'] ?? ''));
$id     = (int) ($in['id'] ?? 0);

if ($table === '' || $column === '' || $id < 1) {
    mam_fail('validation', 422, ['fields' => ['table', 'column', 'id']]);
}

$spec = mam_inline_field($table, $column);
// Same answer for "not on the allowlist" as for "no permission" would leak the
// allowlist's shape; but an unknown field genuinely is not editable, so 403
// with a distinct code is honest and still tells an attacker nothing useful.
if (!$spec) mam_fail('not_editable', 403);

if (!mam_admin_can($u, (string) $spec['cap'])) mam_fail('forbidden', 403);

try {
    $res = mam_inline_edit_apply($table, $column, $id, $in['value'] ?? null, (int) $u['id']);
} catch (InvalidArgumentException $e) {
    mam_fail('invalid_value', 422, ['reason' => $e->getMessage(), 'field' => $column]);
} catch (RuntimeException $e) {
    mam_fail($e->getMessage() === 'not_found' ? 'not_found' : 'not_editable', $e->getMessage() === 'not_found' ? 404 : 403);
}

mam_ok($res);
