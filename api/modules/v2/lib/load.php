<?php
/**
 * ETL — load (v2 rows → a v2 database).
 * ─────────────────────────────────────────────────────────────
 * `transform.php` turns v1 arrays into v2 arrays and touches nothing.
 * This is the half that writes, and it is the half where a mistake is
 * expensive: a transform bug is a failing assertion, a load bug is a database
 * somebody has to unpick.
 *
 * Five properties, each chosen because the obvious alternative fails in a way
 * that is hard to recover from:
 *
 *  1. **Idempotent, via `legacy_reference_map`.** Every write records where the
 *     row came from, and every write checks that map first. Re-running is safe
 *     and is the NORMAL case — the first attempt at a real migration is never
 *     the last, and an ETL you can only run once is one you get one attempt to
 *     get right.
 *  2. **One transaction for the whole load.** A partial load is worse than no
 *     load: it leaves a database that looks populated and reconciles against
 *     nothing. On failure everything rolls back and the map is untouched, so a
 *     re-run after the fix starts clean. (The dataset is small enough that this
 *     costs nothing; at a size where it did not, the unit would become the
 *     stage and the map would still make each stage re-runnable.)
 *  3. **Dry run is the DEFAULT.** `mam_v2_load()` reports what it would write
 *     unless `commit` is passed. The caller has to say so, in the same spirit
 *     as the retention sweep: the destructive direction is never the one you
 *     get by forgetting a flag.
 *  4. **Nothing is invented.** A row the transform rejected is reported, not
 *     written with a guess. A person with no resolvable owner does not get a
 *     NULL that somebody discovers next year.
 *  5. **It never writes what it was not given.** Reference data (programmes,
 *     courses, levels) is re-seeded by the application, not copied — copying it
 *     silently orphans children, because `ktams` is id 5 in production and id 22
 *     in a fresh install.
 *
 * Verification lives in `verify.php` and deliberately recomputes from the
 * SOURCE by a different path, so a shared bug cannot make both agree.
 */
declare(strict_types=1);

// The mapping and the SQL emitter's value rules. This file writes what
// `plan.php` produced and must encode values the same way `emit.php` does — the
// packed-address registry in particular, which is invisible on SQLite and
// mandatory on MySQL.
require_once __DIR__ . '/plan.php';
require_once __DIR__ . '/emit.php';

/**
 * There are no longer any stages: the order comes from `$plan['order']`, which
 * is the same list the SQL emitter walks. The constant that used to name six
 * stages is gone deliberately — it was the shorter of two answers to "what does
 * this migration carry", and the shorter one was the one that ran.
 */

/**
 * The map is the ledger of what has already been loaded. Returns the v2 id for
 * a v1 row, or null.
 */
function mam_v2_map_lookup(PDO $db, int $tenantId, string $table, string $id): ?int
{
    $st = $db->prepare("SELECT v2_id FROM legacy_reference_map WHERE tenant_id = ? AND legacy_table = ? AND legacy_id = ?");
    $st->execute([$tenantId, $table, (string) $id]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

/**
 * Record that a v1 row became a v2 row. Two v1 rows may point at ONE v2 row —
 * that is how a merged person is represented, and why the unique key is on the
 * legacy side rather than the v2 side.
 */
function mam_v2_map_write(PDO $db, int $tenantId, string $lTable, string $lId, string $v2Table, int $v2Id, ?string $ref = null, ?string $note = null): void
{
    $st = $db->prepare("INSERT INTO legacy_reference_map
        (tenant_id, legacy_table, legacy_id, legacy_ref, v2_table, v2_id, migrated_at, note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $st->execute([$tenantId, $lTable, (string) $lId, $ref, $v2Table, $v2Id, gmdate('Y-m-d H:i:s'), $note]);
}

/** Insert and return the new id. Kept tiny and explicit — no magic column lists. */
function mam_v2_insert(PDO $db, string $table, array $row): int
{
    $cols = array_keys($row);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES ('
         . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $st = $db->prepare($sql);
    $st->execute(array_values($row));
    return (int) $db->lastInsertId();
}

/**
 * v1 timestamps are 'YYYY-MM-DD HH:MM:SS' local strings; v2 columns are
 * DATETIME(3) UTC. There is no timezone recorded on the v1 rows, so converting
 * would be inventing an offset. They are carried through verbatim and the fact
 * is stated here rather than being silently assumed either way — a wrong
 * conversion is worse than an honest carry-over, and Africa/Lagos has no DST,
 * so the offset is a single constant a later job can apply deliberately.
 */
function mam_v2_ts(?string $v): ?string
{
    $v = trim((string) $v);
    return $v === '' ? null : $v;
}

/**
 * Currencies the load needs. Seeded rather than assumed present because
 * `payments.currency` is a foreign key into this table — an unseeded currency
 * is a constraint violation halfway through a load, which is exactly the
 * failure mode the whole design is trying to avoid.
 */
function mam_v2_load_currencies(PDO $db, bool $commit): array
{
    $want = [
        ['NGN', 'Nigerian naira', 2, '₦'],
        ['USD', 'US dollar',      2, '$'],
        ['GBP', 'Pound sterling', 2, '£'],
        ['EUR', 'Euro',           2, '€'],
    ];
    $made = 0;
    foreach ($want as [$code, $name, $exp, $sym]) {
        $st = $db->prepare("SELECT 1 FROM currencies WHERE code = ?");
        $st->execute([$code]);
        if ($st->fetchColumn()) continue;
        if ($commit) mam_v2_insert($db, 'currencies', ['code' => $code, 'name' => $name, 'exponent' => $exp, 'symbol' => $sym, 'is_active' => 1]);
        $made++;
    }
    return ['created' => $made];
}



/**
 * Run the whole load.
 *
 * `$source` is the raw v1 export, keyed by table name — so a test can hand it
 * fixtures and the CLI can hand it the real snapshot, and neither knows the
 * difference.
 *
 * Returns a report. With `commit` unset (the default) nothing is written and
 * the report says what WOULD be: the counts are real, the ids are not.
 */

/* ═══════════════════════════════════════════════════════════════════════════
   THE PLAN-DRIVEN LOAD
   ═══════════════════════════════════════════════════════════════════════════
   `plan.php` produces every v2 row with every foreign key already resolved to
   its own synthetic ids (1..N per table). This half walks that structure and
   writes it, translating those synthetic ids to the ids the database actually
   hands out.

   It replaces six hand-written stages that carried four tables. That was the
   defect worth fixing: a table in the schema but not in the loader arrives EMPTY
   on cutover day, and the loader had drifted 108 tables behind the plan. There
   is now ONE list of what gets carried — `$plan['order']` — and both the SQL
   emitter and this loader read it, so they cannot disagree.

   Everything is derived from the TARGET's own schema rather than a
   hand-maintained list, because a hand-maintained list is the thing that
   drifted:

   • foreign keys, for id translation;
   • unique keys, for idempotency;
   • which columns are packed addresses (MAM_V2_IP_COLUMNS) and which are JSON.

   One property changed deliberately from the old loader. It refused to carry
   reference data — programmes, courses, levels — on the grounds that copying
   v1 ids "silently orphans children, because ktams is id 5 in production and 22
   in a fresh install". That reasoning was about IDS, and the plan removes it:
   every id is remapped here, and nothing in the emitted rows carries a v1 id.
   The catalogue itself IS the academy's data — a course somebody added last term
   is not something a re-seed from code can reproduce — so it is carried.
*/

/**
 * Columns holding an id whose TABLE is named by a sibling column. They have no
 * foreign key (they cannot — the target varies per row), which makes them the
 * ones nobody notices: `audit_log.entity_id` was copied straight from v1 in an
 * earlier version of this ETL and pointed at a different row in every case.
 *
 * `alias` maps the value that appears in the naming column to a real table.
 */
function mam_v2_soft_refs(): array
{
    $entityAlias = [
        'application' => 'applications', 'applications' => 'applications',
        'payment' => 'payments', 'payments' => 'payments',
        'user' => 'users', 'users' => 'users', 'customer' => 'users',
        'course' => 'courses', 'courses' => 'courses',
        'cohort' => 'cohorts', 'cohorts' => 'cohorts',
        'module' => 'modules', 'modules' => 'modules',
        'enrolment' => 'enrolments', 'enrollment' => 'enrolments',
        'class' => 'cohorts', 'membership' => 'memberships', 'memberships' => 'memberships',
        'submission' => 'submissions', 'teachback' => 'teachbacks',
        'id_card' => 'id_cards', 'store_product' => 'store_products',
        'instructor_application' => 'instructor_applications',
        'programmes' => 'programmes', 'modules ' => 'modules',
    ];
    return [
        'legacy_reference_map' => ['v2_id' => ['names' => 'v2_table', 'alias' => $entityAlias]],
        'audit_log'            => ['entity_id' => ['names' => 'entity_type', 'alias' => $entityAlias]],
        'attachments'          => ['entity_id' => ['names' => 'entity_type', 'alias' => $entityAlias]],
        // The entity type lives on the DEFINITION, not the value row, so it is
        // resolved from the plan's own defs before any id is translated.
        'custom_field_values'  => ['entity_id' => ['names_via' => ['field_def_id', 'custom_field_defs', 'entity_type'],
                                                   'alias' => $entityAlias]],
    ];
}

/**
 * The target's own foreign keys and unique keys, per table.
 *
 * Read from the database rather than declared here: a declaration is a second
 * copy of the schema, and the second copy is always the stale one.
 */
function mam_v2_schema_shape(PDO $db): array
{
    $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $shape = [];

    if ($driver === 'sqlite') {
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
                     ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $fks = [];
            foreach ($db->query('PRAGMA foreign_key_list(' . $t . ')')->fetchAll(PDO::FETCH_ASSOC) as $fk) {
                // Composite foreign keys would need the whole tuple translated
                // together; v2 has none, and one appearing silently is worse
                // than one that is refused.
                if ((int) $fk['seq'] !== 0) continue;
                // The referenced COLUMN matters as much as the table. Not every
                // foreign key points at a surrogate id: `payments.currency`
                // references `currencies(code)`, and treating 'NGN' as a plan id
                // is how a loader turns money into a constraint violation.
                $fks[(string) $fk['from']] = ['table' => (string) $fk['table'],
                                              'column' => (string) ($fk['to'] ?? 'id')];
            }
            $uniques = [];
            foreach ($db->query('PRAGMA index_list(' . $t . ')')->fetchAll(PDO::FETCH_ASSOC) as $ix) {
                if ((int) $ix['unique'] !== 1) continue;
                $cols = [];
                foreach ($db->query('PRAGMA index_info(' . $ix['name'] . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    if ($c['name'] === null) { $cols = []; break; }   // an expression index
                    $cols[] = (string) $c['name'];
                }
                if ($cols) $uniques[] = $cols;
            }
            $cols = $defaults = [];
            foreach ($db->query('PRAGMA table_info(' . $t . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cols[] = (string) $c['name'];
                if ($c['dflt_value'] !== null) $defaults[(string) $c['name']] = trim((string) $c['dflt_value'], "'");
            }
            $shape[$t] = ['fk' => $fks, 'unique' => $uniques, 'has_id' => in_array('id', $cols, true),
                          'columns' => $cols, 'defaults' => $defaults];
        }
        return $shape;
    }

    // MySQL. Both queries are scoped to the CURRENT schema: an information_schema
    // query without that clause reads every database on the server.
    foreach ($db->query("SELECT TABLE_NAME t, COLUMN_NAME c, REFERENCED_TABLE_NAME r,
                                REFERENCED_COLUMN_NAME rc
                           FROM information_schema.KEY_COLUMN_USAGE
                          WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
                            AND ORDINAL_POSITION = 1")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $shape[$row['t']]['fk'][$row['c']] = ['table' => $row['r'], 'column' => $row['rc'] ?? 'id'];
    }
    foreach ($db->query("SELECT TABLE_NAME t, INDEX_NAME i, COLUMN_NAME c, SEQ_IN_INDEX s
                           FROM information_schema.STATISTICS
                          WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
                          ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $shape[$row['t']]['unique_by_name'][$row['i']][] = $row['c'];
    }
    foreach ($db->query("SELECT TABLE_NAME t, COLUMN_NAME c, COLUMN_DEFAULT d FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $shape[$row['t']]['columns'][] = $row['c'];
        if ($row['d'] !== null) $shape[$row['t']]['defaults'][$row['c']] = $row['d'];
    }
    foreach ($shape as $t => $sh) {
        $shape[$t]['fk'] = $sh['fk'] ?? [];
        $shape[$t]['unique'] = array_values($sh['unique_by_name'] ?? []);
        $shape[$t]['columns'] = $sh['columns'] ?? [];
        $shape[$t]['defaults'] = $sh['defaults'] ?? [];
        // A join table (role_permissions, cohort_staff, user_preferences) has no
        // surrogate id, so nothing can reference it and nothing needs to probe
        // one. Knowing that is the difference between a working loader and
        // "SELECT id FROM ..." against a table that has none.
        $shape[$t]['has_id'] = in_array('id', $shape[$t]['columns'], true);
        unset($shape[$t]['unique_by_name']);
    }
    return $shape;
}

/** An address as the packed bytes the column is declared to hold. */
function mam_v2_load_ip(mixed $v): ?string
{
    if (!is_string($v) || trim($v) === '') return null;
    $packed = @inet_pton(trim($v));
    return $packed === false ? null : $packed;
}

/**
 * Load a plan into a database.
 *
 * Dry run is the default, and it is a REAL dry run: every row is inserted inside
 * a transaction and then rolled back, so a dry run exercises every CHECK, every
 * foreign key and every unique index. The previous implementation reported what
 * it "would" write without writing it, which validated nothing — the first time
 * a constraint was tested was the run that mattered.
 */
function mam_v2_load_plan(PDO $db, array $plan, array $opts = []): array
{
    $commit = !empty($opts['commit']);
    $shape  = mam_v2_schema_shape($db);
    $soft   = mam_v2_soft_refs();
    $order  = (array) ($plan['order'] ?? []);
    $tables = (array) ($plan['tables'] ?? []);

    // planId → dbId, per table. Every foreign key is translated through this.
    $idMap = [];
    // Rows whose foreign key points at their OWN table cannot be resolved on the
    // way in (the target may not exist yet), so those columns are set NULL and
    // patched afterwards.
    $deferred = [];
    $counts = [];
    $existingBefore = [];

    $started = false;
    try {
        $db->beginTransaction();
        $started = true;

        // The ONE thing the plan cannot carry, and the one stage that survives
        // from the hand-written loader: the currency table is platform reference
        // data seeded by the schema, not tenant data. A target whose currencies
        // are missing (an older schema, a hand-built database) would refuse
        // every payment on a foreign key, so they are ensured first.
        $cur = mam_v2_load_currencies($db, true);
        $counts['currencies'] = ['planned' => (int) $cur['created'], 'created' => (int) $cur['created'],
                                 'existing' => 0, 'skipped' => 0];

        foreach ($order as $table) {
            $rows = (array) ($tables[$table] ?? []);
            $counts[$table] = ['planned' => count($rows), 'created' => 0, 'existing' => 0, 'skipped' => 0];
            if (!$rows) continue;
            if (!isset($shape[$table])) {
                throw new RuntimeException("the target has no `$table` table, so the plan cannot be loaded into it");
            }
            $fks = $shape[$table]['fk'];
            $jsonCols = MAM_V2_EMIT_JSON[$table] ?? [];
            $ipCols   = MAM_V2_IP_COLUMNS[$table] ?? [];
            $derived  = MAM_V2_EMIT_DERIVED[$table] ?? [];

            // A table with no public_id and no usable unique key cannot be
            // probed, so a second load would APPEND a duplicate set. Those
            // tables are skipped wholesale when the table already holds rows —
            // the safe direction, and reported rather than silent.
            $existingBefore[$table] = (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();

            foreach ($rows as $row) {
                $planId = isset($row['id']) ? (int) $row['id'] : null;
                unset($row['id']);              // the database assigns it
                unset($row['sort']);            // a plan-side ordering hint, not a column

                // Soft references are resolved from the ORIGINAL row, because
                // the column naming the table may itself be a foreign key that
                // is about to be translated.
                $softTargets = [];
                foreach ($soft[$table] ?? [] as $col => $spec) {
                    if (!array_key_exists($col, $row) || $row[$col] === null) continue;
                    $name = null;
                    if (isset($spec['names'])) {
                        $name = (string) ($row[$spec['names']] ?? '');
                    } elseif (isset($spec['names_via'])) {
                        [$fkCol, $defTable, $defCol] = $spec['names_via'];
                        $defPlanId = (int) ($row[$fkCol] ?? 0);
                        foreach ((array) ($tables[$defTable] ?? []) as $d) {
                            if ((int) ($d['id'] ?? 0) === $defPlanId) { $name = (string) ($d[$defCol] ?? ''); break; }
                        }
                    }
                    $target = $spec['alias'][strtolower((string) $name)] ?? (string) $name;
                    $softTargets[$col] = $target;
                }

                $hasId = (bool) ($shape[$table]['has_id'] ?? true);

                // TRANSLATE FIRST, PROBE SECOND. A unique key very often
                // INCLUDES a foreign key — user_credentials(user_id),
                // membership_roles(membership_id, role_id), vetting_records
                // (tenant_id, membership_id, clearance_type) — so probing before
                // translation asks whether a row exists with somebody else's id
                // in it. In a fresh database the plan's ids and the database's
                // ids coincide and the mistake is invisible; against a database
                // that already holds anything, every second run collides.
                $localDeferred = [];
                foreach ($row as $col => $val) {
                    if ($val === null) continue;
                    if (isset($softTargets[$col])) {
                        // Unresolvable means NULL, never a number that resolves
                        // to a stranger.
                        $row[$col] = $idMap[$softTargets[$col]][(int) $val] ?? null;
                        continue;
                    }
                    if (isset($fks[$col])) {
                        // Only an id-targeted key carries a plan id. A key onto
                        // a natural column (a currency code, a settings key) is
                        // a VALUE and is written as it stands.
                        if (($fks[$col]['column'] ?? 'id') !== 'id') continue;
                        $t2 = (string) $fks[$col]['table'];
                        if ($t2 === $table) {
                            $localDeferred[] = ['table' => $table, 'plan_id' => $planId, 'column' => $col,
                                                'target_plan_id' => (int) $val];
                            $row[$col] = null;
                            continue;
                        }
                        if (!isset($idMap[$t2][(int) $val])) {
                            throw new RuntimeException(sprintf(
                                '%s.%s points at %s row %d, which the plan never wrote — a foreign key the '
                                . 'plan resolved against itself must exist by the time the row is loaded',
                                $table, $col, $t2, (int) $val));
                        }
                        $row[$col] = $idMap[$t2][(int) $val];
                    }
                }

                [$probe, $probeable] = mam_v2_load_probe($db, $table, $row, $shape[$table]['unique'], $hasId,
                    (array) ($shape[$table]['defaults'] ?? []));
                if ($probe !== null) {
                    if ($planId !== null && $hasId) $idMap[$table][$planId] = $probe;
                    $counts[$table]['existing']++;
                    continue;
                }
                // A row with no public_id and no usable unique key cannot be
                // RECOGNISED, so a second load would append a duplicate. Where
                // the table already held rows before this run, the row is
                // skipped and counted — the safe direction, and reported rather
                // than silent. It is judged per ROW, not per table:
                // `notifications` has a unique key that most of its rows cannot
                // fill (dedupe_key is null by design), which classified the
                // whole table as probeable and duplicated it on every re-run.
                if (!$probeable && $existingBefore[$table] > 0) {
                    $counts[$table]['skipped']++;
                    continue;
                }
                foreach ($localDeferred as $d) $deferred[] = $d;

                $out = [];
                foreach ($row as $col => $val) {
                    $name = $derived[$col] ?? $col;
                    if (in_array($name, $ipCols, true))        $val = mam_v2_load_ip($val);
                    elseif (in_array($name, $jsonCols, true))  $val = ($val === null || $val === [] || $val === '')
                        ? null : (is_string($val) ? $val : (string) json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    elseif (is_array($val))                    $val = (string) json_encode($val, JSON_UNESCAPED_UNICODE);
                    elseif (is_bool($val))                     $val = $val ? 1 : 0;
                    $out[$name] = $val;
                }
                $newId = mam_v2_insert($db, $table, $out);
                if ($planId !== null && $hasId) $idMap[$table][$planId] = $newId;
                $counts[$table]['created']++;
            }
        }

        // The deferred self-references, now that every row exists.
        $patched = 0;
        foreach ($deferred as $d) {
            $from = $idMap[$d['table']][$d['plan_id']] ?? null;
            $to   = $idMap[$d['table']][$d['target_plan_id']] ?? null;
            if ($from === null || $to === null) continue;
            $st = $db->prepare('UPDATE ' . $d['table'] . ' SET ' . $d['column'] . ' = ? WHERE id = ?');
            $st->execute([$to, $from]);
            $patched++;
        }

        if ($commit) { $db->commit(); }
        else         { $db->rollBack(); }
        $started = false;
    } catch (\Throwable $e) {
        if ($started && $db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => 'load_failed', 'message' => $e->getMessage(),
                'committed' => false, 'counts' => $counts];
    }

    $created = $existing = $skipped = 0;
    foreach ($counts as $c) {
        $created  += (int) ($c['created'] ?? 0);
        $existing += (int) ($c['existing'] ?? 0);
        $skipped  += (int) ($c['skipped'] ?? 0);
    }
    return ['ok' => true, 'committed' => $commit, 'counts' => $counts,
            'totals' => ['created' => $created, 'existing' => $existing, 'skipped' => $skipped,
                         'self_refs_patched' => $patched],
            'tables_written' => count(array_filter($counts, static fn($c) => $c['created'] > 0))];
}

/**
 * Is this row already in the database?
 *
 * Returns `[id|null, probeable]`. The second value answers "could this row have
 * been recognised at all" — a row with no public_id and no fillable unique key
 * cannot, and the caller must then decide between appending and skipping rather
 * than assuming the row is new.
 *
 * Probed on `public_id` where there is one (the plan derives it from the source
 * row's identity, so it is stable across runs — that is what makes a re-run
 * safe), otherwise on the first unique key whose columns are all present and
 * non-null. A row with neither cannot be recognised, and the caller treats the
 * whole table as append-only.
 */
function mam_v2_load_probe(PDO $db, string $table, array $row, array $uniques, bool $hasId = true,
                           array $defaults = []): array
{
    $tries = [];
    if (isset($row['public_id']) && $row['public_id'] !== null) $tries[] = [['public_id' => $row['public_id']]];
    foreach ($uniques as $cols) {
        if ($cols === ['id'] || in_array('id', $cols, true)) continue;
        $probe = [];
        foreach ($cols as $c) {
            if (array_key_exists($c, $row) && $row[$c] !== null) { $probe[$c] = $row[$c]; continue; }
            // A key column the plan leaves to the database's DEFAULT still takes
            // part in the key. `levels` is keyed on (tenant_id, code,
            // deleted_seq) and the plan never sets deleted_seq, so skipping the
            // key here made every re-run insert a duplicate and fail on the
            // unique index — which is the opposite of idempotent.
            if (array_key_exists($c, $defaults)) { $probe[$c] = $defaults[$c]; continue; }
            continue 2;
        }
        $tries[] = [$probe];
    }
    foreach ($tries as [$probe]) {
        $where = implode(' AND ', array_map(static fn($c) => $c . ' = ?', array_keys($probe)));
        // An id-less join table can still be RECOGNISED — 1 stands for "already
        // there" — it just cannot be pointed at.
        $select = $hasId ? 'id' : '1';
        $st = $db->prepare('SELECT ' . $select . ' FROM ' . $table . ' WHERE ' . $where . ' LIMIT 1');
        $st->execute(array_values($probe));
        $hit = $st->fetchColumn();
        if ($hit !== false) return [(int) $hit, true];
    }
    // The second value answers a DIFFERENT question from the first: "was this
    // row recognisable at all". Not found and not recognisable are the same
    // return value otherwise, and they call for opposite actions.
    return [null, $tries !== []];
}

function mam_v2_load(PDO $db, array $source, array $opts = []): array
{
    $commit = !empty($opts['commit']);
    $t0 = microtime(true);

    $customers = (array) ($source['customers'] ?? []);
    $users     = (array) ($source['users'] ?? []);

    // Row preservation is a CLAIM until this is empty. Refuse to load rather
    // than discover afterwards that somebody was dropped on the way in. Checked
    // here rather than inside the plan because it is a question about the
    // SOURCE, and the answer must stop the load before anything is written.
    $identity = mam_v2_resolve_identities($customers, $users);
    $unaccounted = mam_v2_identity_unaccounted($customers, $users, $identity['people']);
    if ($unaccounted) {
        return ['ok' => false, 'error' => 'identity_unaccounted', 'unaccounted' => $unaccounted,
                'committed' => false];
    }

    // ONE mapping, shared with the SQL emitter. Before this, the loader had six
    // hand-written stages carrying four tables while the plan carried 112 — so
    // "what does the migration cover" had two answers, and the smaller one was
    // the one that ran.
    $plan = mam_v2_plan($source, $opts);
    if (empty($plan['ok'])) {
        return ['ok' => false, 'error' => (string) ($plan['error'] ?? 'plan_failed'),
                'committed' => false] + $plan;
    }

    $res = mam_v2_load_plan($db, $plan, ['commit' => $commit]);

    // The rejected rows, grouped by the v1 table they came from. The two keys
    // the CLI and the tests read are always present, even empty: a missing key
    // reads as "no rejections" in one place and crashes in another.
    $rejected = ['applications' => [], 'payments' => []];
    foreach ((array) ($plan['rejected'] ?? []) as $r) {
        $t = (string) ($r['table'] ?? 'other');
        $rejected[$t][] = $r;
    }

    $c = static fn(string $t, string $k): int => (int) ($res['counts'][$t][$k] ?? 0);
    $report = [
        'ok'        => !empty($res['ok']),
        'committed' => !empty($res['ok']) && $commit,
        'identity'  => $plan['identity'],
        'name_conflicts' => (int) ($plan['identity']['name_conflicts'] ?? 0),
        'rejected'  => $rejected,
        'review'    => (array) ($plan['review'] ?? []),
        'coverage'  => (array) ($plan['coverage'] ?? []),
        'counts'    => (array) ($res['counts'] ?? []),
        'totals'    => (array) ($res['totals'] ?? []),
        'tables_written' => (int) ($res['tables_written'] ?? 0),
        // The three the CLI has always printed, derived from the plan's own
        // counts rather than from a second set of stage-local tallies.
        'people' => [
            'users_created'       => $c('users', 'created'),
            'users_existing'      => $c('users', 'existing'),
            'credentials_created' => $c('user_credentials', 'created'),
            'memberships_created' => $c('memberships', 'created'),
        ],
        'applications' => ['created' => $c('applications', 'created'),
                           'existing' => $c('applications', 'existing')],
        'payments' => ['created' => $c('payments', 'created'),
                       'existing' => $c('payments', 'existing'),
                       'unowned' => (int) ($plan['money']['unowned'] ?? 0),
                       // PLANNED money, not "loaded this run": on a re-run
                       // nothing is written and a per-run figure reads as "there
                       // is no money", which is a different statement.
                       'total_minor' => (int) ($plan['money']['total_minor'] ?? 0),
                       'succeeded_minor' => (int) ($plan['money']['succeeded_minor'] ?? 0)],
        'transform' => [
            'applications' => ['in' => count((array) ($source['applications'] ?? []))],
            'payments'     => ['in' => count((array) ($source['payments'] ?? []))],
        ],
    ];
    if (empty($res['ok'])) {
        $report['error'] = (string) ($res['error'] ?? 'load_failed');
        $report['message'] = (string) ($res['message'] ?? '');
        $report['partial'] = ['counts' => $res['counts'] ?? []];
    }
    $report['elapsed_ms'] = (int) round((microtime(true) - $t0) * 1000);
    return $report;
}
