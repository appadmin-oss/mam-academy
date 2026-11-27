<?php
/**
 * MAM Academy — inline field editing with undo (Advanced Modernization §4.4).
 * ─────────────────────────────────────────────────────────────
 * One endpoint that can set one field on one row, so admin tables can be
 * edited in place instead of through a full-page form round trip. Undo is not
 * a separate mechanism: the write returns the PREVIOUS value, and undoing is
 * the same call with that value put back.
 *
 * ── WHY THIS IS AN ALLOWLIST AND NOT A GENERIC UPDATER ───────────────────────
 * "Set $table.$column = $value where id = $id" is, unconstrained, a privilege
 * escalation endpoint: it would let anyone who can edit a store item's price
 * also set users.admin_tier, users.role or users.password_hash. So nothing is
 * editable unless it appears in MAM_INLINE_FIELDS, and every entry names:
 *   • the admin capability required (checked by the route),
 *   • a type, which validates AND normalises the value,
 *   • an optional label for the audit trail.
 * Adding a field here is a security decision. Anything touching identity,
 * authentication, roles, money already taken, or audit history stays out —
 * those have deliberate flows with their own guards, and the point of this
 * endpoint is convenience for low-stakes text and numbers.
 */
declare(strict_types=1);

/**
 * The editable surface. Keyed 'table.column'.
 *   cap   — admin capability the caller must hold
 *   type  — 'text' | 'int' | 'enum'
 *   max   — max length for text
 *   values— allowed values for enum
 */
function mam_inline_fields(): array {
    static $f = null;
    if ($f !== null) return $f;
    return $f = [
        // Scheduling / teaching metadata — safe, high-churn text.
        'cohorts.title'          => ['cap' => 'enrollments', 'type' => 'text', 'max' => 160, 'label' => 'Cohort title'],
        'class_sessions.title'   => ['cap' => 'enrollments', 'type' => 'text', 'max' => 160, 'label' => 'Session title'],
        'class_sessions.location'=> ['cap' => 'enrollments', 'type' => 'text', 'max' => 255, 'label' => 'Session location'],

        // Catalogue copy.
        'courses.name'           => ['cap' => 'curriculum', 'type' => 'text', 'max' => 160, 'label' => 'Course name'],
        'programs.name'          => ['cap' => 'curriculum', 'type' => 'text', 'max' => 160, 'label' => 'Programme name'],

        // Store. NOT price — `store_items.price_usd` is deliberately absent.
        // What a customer is charged deserves the full form with its own
        // confirmation, not a cell that commits on blur; the point of this
        // endpoint is convenience for low-stakes text and counts.
        'store_items.name'       => ['cap' => 'store_catalog', 'type' => 'text', 'max' => 200, 'label' => 'Item name'],
        // -1 is the store's "untracked" sentinel (see mam_store_item), so the
        // floor is -1 rather than 0 — a 0 floor would make an untracked item
        // impossible to restore once someone had set a real count.
        'store_items.stock'      => ['cap' => 'store_catalog', 'type' => 'int', 'min' => -1, 'max_val' => 1000000, 'label' => 'Item stock'],
    ];
}

/** Is this table.column editable inline? */
function mam_inline_field(string $table, string $column): ?array {
    $spec = mam_inline_fields()[$table . '.' . $column] ?? null;
    if ($spec === null) return null;
    return $spec + ['table' => $table, 'column' => $column];
}

/**
 * Validate + normalise a submitted value for a field spec.
 * Throws InvalidArgumentException with a machine code on rejection.
 */
function mam_inline_normalise(array $spec, $raw) {
    $type = (string) ($spec['type'] ?? 'text');

    if ($type === 'text') {
        $v = trim((string) $raw);
        $max = (int) ($spec['max'] ?? 255);
        // Length is counted in CHARACTERS, not bytes: a title of accented or
        // non-Latin text would otherwise be rejected far below the real limit.
        if (mb_strlen($v) > $max) throw new InvalidArgumentException('too_long');
        // Control characters would corrupt a single-line cell and can smuggle
        // terminal escapes into logs someone later cats. Everything below
        // space is refused EXCEPT tab (\x09) — note this deliberately includes
        // \x0A and \x0D, since a pasted newline is the common case, not an
        // exotic one.
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $v)) throw new InvalidArgumentException('bad_characters');
        if ($v === '' && empty($spec['nullable'])) throw new InvalidArgumentException('empty');
        return $v;
    }

    if ($type === 'int') {
        if (is_string($raw)) $raw = trim($raw);
        if ($raw === '' || $raw === null) throw new InvalidArgumentException('empty');
        // Reject anything that is not a plain integer BEFORE casting. A bare
        // (int) turns "12abc" into 12 and "" into 0, which is how a price
        // silently becomes free.
        if (!is_int($raw) && !preg_match('/^-?\d+$/', (string) $raw)) throw new InvalidArgumentException('not_a_number');
        $n = (int) $raw;
        $min = (int) ($spec['min'] ?? PHP_INT_MIN);
        $max = (int) ($spec['max_val'] ?? PHP_INT_MAX);
        if ($n < $min) throw new InvalidArgumentException('too_small');
        if ($n > $max) throw new InvalidArgumentException('too_large');
        return $n;
    }

    if ($type === 'enum') {
        $v = (string) $raw;
        if (!in_array($v, (array) ($spec['values'] ?? []), true)) throw new InvalidArgumentException('not_allowed');
        return $v;
    }

    throw new InvalidArgumentException('unknown_type');
}

/**
 * Apply one inline edit. Returns
 *   ['changed'=>bool, 'previous'=>mixed, 'value'=>mixed, 'label'=>string]
 * `previous` is what makes undo possible — the caller hands it straight back
 * to this function to reverse the change.
 *
 * Throws RuntimeException('not_editable' | 'not_found') and
 * InvalidArgumentException from the normaliser.
 */
function mam_inline_edit_apply(string $table, string $column, int $id, $raw, ?int $actorId = null): array {
    $spec = mam_inline_field($table, $column);
    if (!$spec) throw new RuntimeException('not_editable');
    if ($id < 1) throw new RuntimeException('not_found');

    $value = mam_inline_normalise($spec, $raw);

    // $table and $column are interpolated, which is only safe because both came
    // out of the allowlist above — they are never taken from the request.
    $row = mam_db_row("SELECT `$column` AS v FROM `$table` WHERE id = ?", [$id]);
    if (!$row) throw new RuntimeException('not_found');
    $previous = $row['v'];

    // A no-op edit must not write an audit row: re-saving an unchanged cell is
    // something a UI does constantly (blur, re-focus, blur) and would otherwise
    // bury the real changes in the timeline.
    $same = is_int($value) ? ((int) $previous === $value) : ((string) $previous === (string) $value);
    if ($same) {
        return ['changed' => false, 'previous' => $previous, 'value' => $value, 'label' => (string) ($spec['label'] ?? $column)];
    }

    mam_db_update($table, [$column => $value], ['id' => $id]);
    if (function_exists('mam_audit')) {
        mam_audit('inline_edit', $table, $id, [
            'field' => $column, 'from' => $previous, 'to' => $value, 'by' => $actorId,
        ]);
    }
    return ['changed' => true, 'previous' => $previous, 'value' => $value, 'label' => (string) ($spec['label'] ?? $column)];
}
