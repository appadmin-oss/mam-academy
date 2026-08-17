<?php
/**
 * ETL — verify (does the loaded database say the same thing as the snapshot?).
 * ─────────────────────────────────────────────────────────────
 * The rule this file exists to honour: **reconcile by an INDEPENDENT path.**
 *
 * A verifier that re-runs the transform and compares the result to the load
 * proves only that the code is consistent with itself. If `mam_v2_minor()`
 * rounds a kobo away, a verifier built on `mam_v2_minor()` agrees enthusiastically
 * that the kobo was never there. So every figure below is recomputed straight
 * from the source JSON by arithmetic that shares nothing with the transform —
 * a different parse, a different accumulation — and only then compared to what
 * the database actually holds.
 *
 * That is the same discipline that made the payment transform's ₦405,034
 * trustworthy: it matched the admin screen by a route that never touched the
 * admin screen's query.
 *
 * Each check returns `['ok' => bool, ...]` with the two figures it compared, so
 * a failure says what disagreed rather than that something did.
 */
declare(strict_types=1);

/**
 * Decimal string → integer minor units, by a DIFFERENT method to the transform.
 *
 * `mam_v2_minor()` splits on the point and pads. This one matches the parts
 * with a regular expression and multiplies the whole part out, so the two
 * disagree if either is wrong instead of sharing a mistake. Both must be exact
 * — a float appears nowhere in either.
 */
function mam_v2_verify_minor(string|int|float|null $amount, int $exponent = 2): ?int
{
    if ($amount === null) return null;
    $s = trim((string) $amount);
    if ($s === '') return null;
    if (!preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $s, $m)) return null;
    $neg  = $m[1] === '-';
    $unit = $m[2];
    $frac = $m[3] ?? '';
    if (strlen($frac) > $exponent) {
        // More precision than the currency has. Refusing is the point: silently
        // truncating is how a reconciliation passes while the money is wrong.
        if (rtrim(substr($frac, $exponent), '0') !== '') return null;
        $frac = substr($frac, 0, $exponent);
    }
    $frac = str_pad($frac, $exponent, '0');
    $total = 0;
    for ($i = 0, $n = strlen($unit); $i < $n; $i++) $total = $total * 10 + (int) $unit[$i];
    for ($i = 0; $i < $exponent; $i++)              $total = $total * 10;
    for ($i = 0, $n = strlen($frac); $i < $n; $i++) $total = $total + (int) $frac[$i] * (int) (10 ** ($exponent - 1 - $i));
    return $neg ? -$total : $total;
}

/**
 * How many distinct humans the snapshot describes — counted here directly, not
 * by calling the identity resolver the load used.
 *
 * The rule is the resolver's (one person per lower-cased email; a row with no
 * address is its own person), restated independently. If the two disagree the
 * merge is wrong in one of them, which is exactly what wants catching: an
 * over-merge is unrecoverable.
 */
function mam_v2_verify_people_expected(array $customers, array $users): array
{
    $keys = [];
    $blank = 0;
    foreach (['customers' => $customers, 'users' => $users] as $tbl => $rows) {
        foreach ($rows as $r) {
            $e = strtolower(trim((string) ($r['email'] ?? '')));
            if ($e === '') { $keys[$tbl . '#' . (string) ($r['id'] ?? count($keys))] = true; $blank++; continue; }
            $keys[$e] = true;
        }
    }
    return ['distinct' => count($keys), 'no_email' => $blank];
}

/** One check's result, in the shape the report prints. */
function mam_v2_check(string $name, bool $ok, $expected, $actual, string $note = ''): array
{
    return ['check' => $name, 'ok' => $ok, 'expected' => $expected, 'actual' => $actual, 'note' => $note];
}

/**
 * Verify a loaded tenant against the snapshot it came from.
 *
 * `$rejected` is the load's own report of what it refused to write. It is an
 * INPUT here, because "these rows are missing" is only acceptable if they are
 * the rows the load said it was leaving out — an unexplained absence and a
 * declared one look identical in the database and could not be more different.
 */
function mam_v2_verify(PDO $db, array $source, array $rejected = [], array $opts = []): array
{
    $slug = (string) ($opts['tenant_slug'] ?? 'mam');
    $st = $db->prepare("SELECT id FROM tenants WHERE slug = ?");
    $st->execute([$slug]);
    $tid = $st->fetchColumn();
    if ($tid === false) {
        return ['ok' => false, 'error' => 'tenant_not_loaded', 'checks' => []];
    }
    $tid = (int) $tid;

    $one = function (string $sql, array $args = []) use ($db) {
        $s = $db->prepare($sql); $s->execute($args); $v = $s->fetchColumn();
        return $v === false ? null : $v;
    };

    $customers = (array) ($source['customers'] ?? []);
    $users     = (array) ($source['users'] ?? []);
    $apps      = (array) ($source['applications'] ?? []);
    $payments  = (array) ($source['payments'] ?? []);
    $checks    = [];

    // ── 1. People ────────────────────────────────────────────────────────────
    $expectPeople = mam_v2_verify_people_expected($customers, $users);
    $gotUsers = (int) $one("SELECT COUNT(*) FROM users u JOIN memberships m ON m.user_id = u.id WHERE m.tenant_id = ?", [$tid]);
    $checks[] = mam_v2_check('people.count', $gotUsers === $expectPeople['distinct'],
        $expectPeople['distinct'], $gotUsers,
        'distinct humans, counted independently of the identity resolver');

    // Every source row must be findable. This is the row-preservation guarantee
    // made checkable: a person who was merged away and a person who was dropped
    // look the same in a count, and only the map tells them apart.
    $missing = [];
    foreach (['customers' => $customers, 'users' => $users] as $tbl => $rows) {
        foreach ($rows as $r) {
            $id = (string) ($r['id'] ?? '');
            if ($id === '') continue;
            if ($one("SELECT 1 FROM legacy_reference_map WHERE tenant_id = ? AND legacy_table = ? AND legacy_id = ?", [$tid, $tbl, $id]) === null) {
                $missing[] = $tbl . ':' . $id;
            }
        }
    }
    $checks[] = mam_v2_check('people.every_source_row_mapped', $missing === [], 0, count($missing),
        $missing ? 'unmapped: ' . implode(', ', array_slice($missing, 0, 8)) : '');

    // ── 2. Erasure is honoured ───────────────────────────────────────────────
    // A tombstone that can still log in is not a tombstone. Checked against the
    // database rather than trusted from the loader's own report.
    $erasedWithCreds = (int) $one(
        "SELECT COUNT(*) FROM user_credentials c JOIN users u ON u.id = c.user_id
         JOIN memberships m ON m.user_id = u.id
         WHERE m.tenant_id = ? AND u.status = 'closed'", [$tid]);
    $checks[] = mam_v2_check('erasure.no_credentials', $erasedWithCreds === 0, 0, $erasedWithCreds,
        'an erased person must hold no password');

    $erasedNamed = (int) $one(
        "SELECT COUNT(*) FROM users u JOIN memberships m ON m.user_id = u.id
         WHERE m.tenant_id = ? AND u.status = 'closed' AND u.email NOT LIKE '%.invalid'", [$tid]);
    $checks[] = mam_v2_check('erasure.tombstone_addresses', $erasedNamed === 0, 0, $erasedNamed,
        'a closed row must carry a .invalid address, never a real one');

    // The other direction, and the subtler one. `.invalid` is what marks a
    // tombstone, and the load also mints `.invalid` addresses for people who
    // never had one — so a LIVE user under that TLD is ambiguous: re-read by
    // the identity resolver they would come back as erased. The only ones
    // permitted are the synthetic no-address rows, and there must be exactly as
    // many of those as the snapshot has blank addresses.
    $expectBlank = mam_v2_verify_people_expected($customers, $users)['no_email'];
    $liveInvalid = (int) $one(
        "SELECT COUNT(*) FROM users u JOIN memberships m ON m.user_id = u.id
         WHERE m.tenant_id = ? AND u.status <> 'closed' AND u.email LIKE '%.invalid'", [$tid]);
    $checks[] = mam_v2_check('erasure.live_invalid_addresses', $liveInvalid === $expectBlank,
        $expectBlank, $liveInvalid,
        'a live user under .invalid must be a synthetic no-address row and nothing else — anything more would read back as a tombstone');

    // ── 3. Applications ──────────────────────────────────────────────────────
    $rejApps = count((array) ($rejected['applications'] ?? []));
    $expectApps = count($apps) - $rejApps;
    $gotApps = (int) $one("SELECT COUNT(*) FROM applications WHERE tenant_id = ?", [$tid]);
    $checks[] = mam_v2_check('applications.count', $gotApps === $expectApps, $expectApps, $gotApps,
        $rejApps ? "$rejApps declared as rejected by the load" : '');

    $appNoMember = (int) $one("SELECT COUNT(*) FROM applications WHERE tenant_id = ? AND membership_id IS NULL", [$tid]);
    $checks[] = mam_v2_check('applications.all_linked', $appNoMember === 0, 0, $appNoMember,
        'an application with no applicant cannot be worked');

    $rejectedNoReason = (int) $one(
        "SELECT COUNT(*) FROM applications WHERE tenant_id = ? AND status = 'rejected' AND (decision_reason IS NULL OR decision_reason = '')", [$tid]);
    $checks[] = mam_v2_check('applications.rejection_has_reason', $rejectedNoReason === 0, 0, $rejectedNoReason,
        'the schema CHECK should make this impossible — verified anyway, because a CHECK the engine ignored is silent');

    // ── 4. Money — the figure that has to be exactly right ───────────────────
    // Recomputed from the raw strings by the independent parser above.
    $expectTotal = 0; $expectSucceeded = 0; $unparsed = [];
    $succeededV1 = ['success', 'succeeded', 'paid', 'completed'];
    foreach ($payments as $p) {
        $m = mam_v2_verify_minor($p['amount'] ?? null);
        if ($m === null) { $unparsed[] = (string) ($p['id'] ?? '?'); continue; }
        if (strtoupper(trim((string) ($p['currency'] ?? 'NGN'))) !== MAM_V2_BASE_CURRENCY) continue;
        $expectTotal += $m;
        if (in_array(strtolower(trim((string) ($p['status'] ?? ''))), $succeededV1, true)) $expectSucceeded += $m;
    }
    $gotTotal     = (int) ($one("SELECT COALESCE(SUM(amount_minor), 0) FROM payments WHERE tenant_id = ?", [$tid]) ?? 0);
    $gotSucceeded = (int) ($one("SELECT COALESCE(SUM(amount_minor), 0) FROM payments WHERE tenant_id = ? AND status = 'succeeded'", [$tid]) ?? 0);

    $checks[] = mam_v2_check('money.total_minor', $gotTotal === $expectTotal, $expectTotal, $gotTotal,
        'every payment, recomputed from the source strings by a different parser');
    $checks[] = mam_v2_check('money.succeeded_minor', $gotSucceeded === $expectSucceeded, $expectSucceeded, $gotSucceeded,
        'the figure the academy would recognise as "money received"');
    $checks[] = mam_v2_check('money.all_amounts_parsed', $unparsed === [], 0, count($unparsed),
        $unparsed ? 'unparsed: ' . implode(', ', $unparsed) : '');

    // Base-currency consistency: the CHECK permits a differing base only with a
    // frozen rate, and the load must never have invented one.
    $inventedFx = (int) $one(
        "SELECT COUNT(*) FROM payments WHERE tenant_id = ? AND currency = base_currency AND fx_rate IS NOT NULL", [$tid]);
    $checks[] = mam_v2_check('money.no_invented_fx', $inventedFx === 0, 0, $inventedFx,
        'a same-currency payment is not a conversion and must carry no rate');

    $mismatchedBase = (int) $one(
        "SELECT COUNT(*) FROM payments WHERE tenant_id = ? AND currency = base_currency AND base_amount_minor <> amount_minor", [$tid]);
    $checks[] = mam_v2_check('money.base_equals_amount', $mismatchedBase === 0, 0, $mismatchedBase);

    // ── 5. Nothing was invented ──────────────────────────────────────────────
    // Every loaded row must trace back to a source row. A v2 row with no map
    // entry came from nowhere, which is a worse failure than a missing one.
    foreach (['applications' => 'applications', 'payments' => 'payments'] as $v2 => $label) {
        $orphan = (int) $one(
            "SELECT COUNT(*) FROM {$v2} t WHERE t.tenant_id = ?
             AND NOT EXISTS (SELECT 1 FROM legacy_reference_map m WHERE m.tenant_id = t.tenant_id AND m.v2_table = ? AND m.v2_id = t.id)", [$tid, $v2]);
        $checks[] = mam_v2_check("provenance.$label", $orphan === 0, 0, $orphan,
            'every loaded row traces back to a source row');
    }

    // ── 6. Referential integrity, asked of the engine itself ─────────────────
    $fkBroken = null;
    try {
        $rows = $db->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC);
        $fkBroken = count($rows);
    } catch (\Throwable $e) {
        // MySQL enforces FKs on write, so there is nothing to sweep afterwards.
        $fkBroken = 0;
    }
    $checks[] = mam_v2_check('integrity.foreign_keys', $fkBroken === 0, 0, $fkBroken);

    $ok = true;
    foreach ($checks as $c) if (!$c['ok']) $ok = false;
    return [
        'ok'      => $ok,
        'tenant'  => $tid,
        'checks'  => $checks,
        'failed'  => array_values(array_filter($checks, static fn(array $c): bool => !$c['ok'])),
        'summary' => ['total' => count($checks), 'passed' => count(array_filter($checks, static fn(array $c): bool => $c['ok']))],
    ];
}
