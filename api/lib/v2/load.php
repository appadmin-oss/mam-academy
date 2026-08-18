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

/** Stages, in dependency order. Named so the report reads like the plan. */
const MAM_V2_LOAD_STAGES = ['currencies', 'tenant', 'centre', 'people', 'applications', 'payments'];

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
 * The tenant row, created once and found by slug thereafter.
 *
 * `$commit` is honoured HERE too, and that is not a detail: the seed steps
 * shipped writing on a dry run because only the row loaders checked the flag,
 * so `--dry-run` left a tenant, a centre and four currencies behind — outside
 * the transaction, so not even rolled back. A dry run that writes anything is
 * not a dry run.
 */
function mam_v2_load_tenant(PDO $db, array $opts, bool $commit): array
{
    $slug = (string) ($opts['tenant_slug'] ?? 'mam');
    $st = $db->prepare("SELECT id FROM tenants WHERE slug = ?");
    $st->execute([$slug]);
    $id = $st->fetchColumn();
    if ($id !== false) return ['id' => (int) $id, 'created' => false];
    if (!$commit) return ['id' => null, 'created' => true, 'planned' => true];

    $id = mam_v2_insert($db, 'tenants', [
        'public_id'        => mam_v2_ulid(),
        'slug'             => $slug,
        'legal_name'       => (string) ($opts['tenant_legal_name'] ?? 'MAM Centre'),
        'display_name'     => (string) ($opts['tenant_name'] ?? 'MAM Academy'),
        'base_currency'    => MAM_V2_BASE_CURRENCY,
        'default_locale'   => 'en',
        'default_timezone' => 'Africa/Lagos',
        'status'           => 'active',
    ]);
    return ['id' => $id, 'created' => true];
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

/** The default centre. One per tenant for now (design doc §7.4 defers hierarchy). */
function mam_v2_load_centre(PDO $db, ?int $tenantId, array $opts, bool $commit): array
{
    $code = (string) ($opts['centre_code'] ?? 'MAIN');
    if ($tenantId !== null) {
        $st = $db->prepare("SELECT id FROM centres WHERE tenant_id = ? AND code = ?");
        $st->execute([$tenantId, $code]);
        $id = $st->fetchColumn();
        if ($id !== false) return ['id' => (int) $id, 'created' => false];
    }
    if (!$commit) return ['id' => null, 'created' => true, 'planned' => true];

    $id = mam_v2_insert($db, 'centres', [
        'public_id' => mam_v2_ulid(),
        'tenant_id' => $tenantId,
        'code'      => $code,
        'name'      => (string) ($opts['centre_name'] ?? 'MAM Academy'),
        'kind'      => 'physical',
        'timezone'  => 'Africa/Lagos',
        'is_active' => 1,
    ]);
    return ['id' => $id, 'created' => true];
}

/**
 * The v1 role a person held → the v2 membership stage.
 *
 * A stage is not a role: v2 keeps roles in `membership_roles` and the stage is
 * where the person is in their journey. Mapping is deliberately coarse — an
 * `active` student account becomes `active` and everything else becomes
 * `applicant`, because the v1 data does not record enough to claim more, and
 * a stage asserted on a guess is worse than one that is merely early.
 */
function mam_v2_stage_for(array $person, array $v1User): string
{
    if (!empty($person['erased']))                     return 'withdrawn';
    if (($v1User['role'] ?? '') === 'admin')           return 'active';
    if (($v1User['role'] ?? '') === 'instructor')      return 'active';
    if (($v1User['status'] ?? '') === 'active')        return 'active';
    if ($person['user_id'] !== null)                   return 'admitted';
    return 'applicant';
}

/**
 * People → users + credentials + memberships.
 *
 * **Password hashes ARE carried.** They are bcrypt, they are already the only
 * thing the v1 system holds, and the alternative — forcing every family to
 * reset on cutover day — turns a migration into a support incident and trains
 * people to click reset links in unexpected email. `must_change` is not set for
 * the same reason. An ERASED person gets no credential row at all, whatever v1
 * held: a tombstone that can still log in is not a tombstone.
 */
function mam_v2_load_people(PDO $db, int $tenantId, ?int $centreId, array $people, array $v1Users, bool $commit): array
{
    $usersById = [];
    foreach ($v1Users as $u) $usersById[(int) ($u['id'] ?? 0)] = $u;

    $report = ['in' => count($people), 'users_created' => 0, 'users_existing' => 0,
               'memberships_created' => 0, 'credentials_created' => 0,
               'erased' => 0, 'no_email' => 0, 'skipped' => []];
    $ids = [];   // person index => ['user_id'=>, 'membership_id'=>]

    foreach ($people as $i => $p) {
        $v1User = $p['user_id'] !== null ? ($usersById[(int) $p['user_id']] ?? []) : [];

        // Already loaded? Either source row resolves to the same v2 user.
        $existing = null;
        foreach ((array) $p['sources'] as $src) {
            [$t, $lid] = array_pad(explode(':', $src, 2), 2, '');
            $found = mam_v2_map_lookup($db, $tenantId, $t, $lid);
            if ($found !== null) { $existing = $found; break; }
        }

        $email = trim((string) ($p['email'] ?? ''));
        if ($email === '') {
            // v2 `users.email` is NOT NULL and UNIQUE. A person with no address
            // gets a stable, obviously-synthetic one derived from their source
            // row rather than being dropped: the row still has to be countable,
            // and `.invalid` guarantees nothing can ever be delivered to it.
            $email = 'no-email-' . preg_replace('/[^a-z0-9]+/i', '-', (string) $p['sources'][0]) . '@migrated.invalid';
            $report['no_email']++;
        }
        if (!empty($p['erased'])) $report['erased']++;

        if ($existing !== null) {
            $report['users_existing']++;
            $ms = $db->prepare("SELECT id FROM memberships WHERE tenant_id = ? AND user_id = ? AND deleted_seq = 0");
            $ms->execute([$tenantId, $existing]);
            $mid = $ms->fetchColumn();
            $ids[$i] = ['user_id' => $existing, 'membership_id' => $mid === false ? null : (int) $mid];
            continue;
        }

        if (!$commit) {
            $report['users_created']++;
            $report['memberships_created']++;
            if (!empty($p['credentials']) && !empty($v1User['password_hash'])) $report['credentials_created']++;
            $ids[$i] = ['user_id' => null, 'membership_id' => null];
            continue;
        }

        $userId = mam_v2_insert($db, 'users', [
            'public_id'     => mam_v2_ulid(),
            'email'         => $email,
            'phone'         => $p['phone'] ?? null,
            'full_name'     => (string) ($p['full_name'] ?? '') !== '' ? (string) $p['full_name'] : 'Erased user',
            'date_of_birth' => $p['dob'] ?? null,
            'avatar_url'    => !empty($p['erased']) ? null : ($v1User['avatar_url'] ?? null),
            'timezone'      => 'Africa/Lagos',
            // An erased person is `closed`, not `active`: the row exists so the
            // counts reconcile and the academic history keeps its owner, and
            // for no other reason.
            'status'        => !empty($p['erased']) ? 'closed' : 'active',
            'created_at'    => mam_v2_ts($v1User['created_at'] ?? null) ?? gmdate('Y-m-d H:i:s'),
        ]);
        $report['users_created']++;

        if (!empty($p['credentials']) && empty($p['erased']) && !empty($v1User['password_hash'])) {
            mam_v2_insert($db, 'user_credentials', [
                'user_id'       => $userId,
                'password_hash' => (string) $v1User['password_hash'],
                'algo'          => 'bcrypt',
                'must_change'   => 0,
            ]);
            $report['credentials_created']++;
        }

        $membershipId = mam_v2_insert($db, 'memberships', [
            'public_id'        => mam_v2_ulid(),
            'tenant_id'        => $tenantId,
            'user_id'          => $userId,
            'centre_id'        => $centreId,
            'reference'        => ($v1User['enrollment_ref'] ?? null) ?: null,
            'stage'            => mam_v2_stage_for($p, $v1User),
            'stage_entered_at' => mam_v2_ts($v1User['created_at'] ?? null) ?? gmdate('Y-m-d H:i:s'),
            'status'           => !empty($p['erased']) ? 'closed' : 'active',
            'joined_at'        => mam_v2_ts($v1User['created_at'] ?? null) ?? gmdate('Y-m-d H:i:s'),
        ]);
        $report['memberships_created']++;

        // BOTH source rows are mapped to the one v2 user — that is what makes a
        // merge auditable rather than a fact somebody has to take on trust.
        foreach ((array) $p['sources'] as $src) {
            [$t, $lid] = array_pad(explode(':', $src, 2), 2, '');
            if ($lid === '' || $lid === '?') continue;
            if (mam_v2_map_lookup($db, $tenantId, $t, $lid) !== null) continue;
            // The note carries the merge AND its disagreement, because v2 keeps
            // only the winning name — so if this is not recorded at merge time,
            // the discarded one is gone and nobody can review the decision.
            $note = null;
            if (count((array) $p['sources']) > 1) {
                $note = 'merged identity';
                if (!empty($p['name_conflict'])) {
                    $note = 'merged identity — SOURCE NAMES DISAGREE: '
                          . implode(' / ', array_slice((array) $p['name_variants'], 0, 3));
                }
            }
            mam_v2_map_write($db, $tenantId, $t, $lid, 'users', $userId,
                ($v1User['enrollment_ref'] ?? null) ?: null, $note);
        }
        $ids[$i] = ['user_id' => $userId, 'membership_id' => $membershipId];
    }

    return ['report' => $report, 'ids' => $ids];
}

/** Applications, repointed onto the membership their applicant now has. */
function mam_v2_load_applications(PDO $db, int $tenantId, array $rows, array $ids, bool $commit): array
{
    $report = ['in' => count($rows), 'created' => 0, 'existing' => 0, 'unlinked' => 0, 'skipped' => []];
    foreach ($rows as $r) {
        [$lTable, $lId] = $r['legacy'];
        if ($lId !== '' && mam_v2_map_lookup($db, $tenantId, $lTable, $lId) !== null) { $report['existing']++; continue; }

        $membershipId = $ids[$r['person_ix']]['membership_id'] ?? null;
        if ($membershipId === null && $commit) {
            // The transform already refuses an application with no resolvable
            // applicant, so reaching here means the person failed to load —
            // report it rather than writing an orphan.
            $report['unlinked']++;
            $report['skipped'][] = ['legacy_id' => $lId, 'why' => 'applicant did not load'];
            continue;
        }
        if (!$commit) { $report['created']++; continue; }

        $id = mam_v2_insert($db, 'applications', [
            'public_id'        => mam_v2_ulid(),
            'tenant_id'        => $tenantId,
            'membership_id'    => $membershipId,
            'type'             => (string) $r['type'],
            'status'           => (string) $r['status'],
            'submitted_at'     => mam_v2_ts($r['submitted_at'] ?? null),
            'decision_reason'  => $r['decision_reason'] ?? null,
            'payload'          => $r['payload'] === null ? null : json_encode($r['payload'], JSON_UNESCAPED_UNICODE),
            'readiness_signal' => $r['readiness_signal'] ?? null,
            'reference'        => $r['reference'] ?? null,
            'source_channel'   => $r['source_channel'] ?? null,
            'created_at'       => mam_v2_ts($r['submitted_at'] ?? null) ?? gmdate('Y-m-d H:i:s'),
        ]);
        mam_v2_map_write($db, $tenantId, $lTable, $lId, 'applications', $id, $r['reference'] ?? null);
        $report['created']++;
    }
    return $report;
}

/** Payments, repointed the same way. */
function mam_v2_load_payments(PDO $db, int $tenantId, array $rows, array $ids, bool $commit): array
{
    $report = ['in' => count($rows), 'created' => 0, 'existing' => 0, 'unowned' => 0,
               'total_minor' => 0, 'succeeded_minor' => 0];
    foreach ($rows as $r) {
        [$lTable, $lId] = $r['legacy'];
        if ($lId !== '' && mam_v2_map_lookup($db, $tenantId, $lTable, $lId) !== null) { $report['existing']++; continue; }

        // A payment with no owner is still LOADED — the money is real and the
        // totals have to reconcile — but it is counted and reported, because a
        // payment nobody is attached to is a question for a human, not a NULL
        // to be discovered during an audit.
        $membershipId = $r['person_ix'] === null ? null : ($ids[$r['person_ix']]['membership_id'] ?? null);
        // On a dry run there are no membership ids yet, so a missing one means
        // nothing — judging by it reported every payment as unowned, which read
        // as a catastrophic data finding and was an artefact of not writing.
        // The honest question in each mode: could an owner be attached at all?
        if ($commit ? ($membershipId === null) : ($r['person_ix'] === null)) $report['unowned']++;

        $report['total_minor'] += (int) $r['amount_minor'];
        if ($r['status'] === 'succeeded') $report['succeeded_minor'] += (int) $r['amount_minor'];
        if (!$commit) { $report['created']++; continue; }

        $id = mam_v2_insert($db, 'payments', [
            'public_id'         => mam_v2_ulid(),
            'tenant_id'         => $tenantId,
            'membership_id'     => $membershipId,
            'provider'          => (string) $r['provider'],
            'provider_ref'      => $r['provider_ref'] ?? null,
            'status'            => (string) $r['status'],
            'currency'          => (string) $r['currency'],
            'amount_minor'      => (int) $r['amount_minor'],
            'base_currency'     => (string) $r['base_currency'],
            'base_amount_minor' => (int) $r['base_amount_minor'],
            'fx_rate'           => $r['fx_rate'],
            'fx_source'         => $r['fx_source'],
            'received_at'       => mam_v2_ts($r['received_at'] ?? null),
            'failure_reason'    => $r['failure_reason'] ?? null,
        ]);
        // The HUMAN reference goes in the map (see the transform); the gateway's
        // lives on the payment row itself as provider_ref.
        mam_v2_map_write($db, $tenantId, $lTable, $lId, 'payments', $id,
            ($r['legacy_ref'] ?? null) ?: ($r['provider_ref'] ?? null));
        $report['created']++;
    }
    return $report;
}

/**
 * v1 payments hang off an application, which hangs off a customer. The
 * transform expects the customer already attached as `_customer_id`; joining it
 * is a load-phase job because it needs both tables at once.
 *
 * A payment whose application is missing keeps a null owner rather than being
 * dropped — see the note in the payment loader.
 */
function mam_v2_attach_payment_owners(array $payments, array $applications): array
{
    $appOwner = [];
    foreach ($applications as $a) $appOwner[(int) ($a['id'] ?? 0)] = $a['customer_id'] ?? null;
    foreach ($payments as $i => $p) {
        $aid = (int) ($p['application_id'] ?? 0);
        $payments[$i]['_customer_id'] = $aid && isset($appOwner[$aid]) ? $appOwner[$aid] : null;
    }
    return $payments;
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
function mam_v2_load(PDO $db, array $source, array $opts = []): array
{
    $commit = !empty($opts['commit']);
    $t0 = microtime(true);

    $customers = (array) ($source['customers'] ?? []);
    $users     = (array) ($source['users'] ?? []);
    $apps      = (array) ($source['applications'] ?? []);
    $payments  = mam_v2_attach_payment_owners((array) ($source['payments'] ?? []), $apps);

    $identity = mam_v2_resolve_identities($customers, $users);
    $people   = $identity['people'];

    // Row preservation is a CLAIM until this is empty. Refuse to load rather
    // than discover afterwards that somebody was dropped on the way in.
    $unaccounted = mam_v2_identity_unaccounted($customers, $users, $people);
    if ($unaccounted) {
        return ['ok' => false, 'error' => 'identity_unaccounted', 'unaccounted' => $unaccounted,
                'committed' => false];
    }

    $tApps = mam_v2_transform_applications($apps, $people);
    $tPays = mam_v2_transform_payments($payments, $people);

    $report = [
        'ok'        => true,
        'committed' => $commit,
        'identity'  => $identity['report'],
        'rejected'  => ['applications' => $tApps['rejected'], 'payments' => $tPays['rejected']],
        'transform' => ['applications' => $tApps['report'], 'payments' => $tPays['report']],
    ];

    $started = false;
    try {
        if ($commit) { $db->beginTransaction(); $started = true; }

        $report['currencies'] = mam_v2_load_currencies($db, $commit);
        $tenant = mam_v2_load_tenant($db, $opts, $commit);
        $report['tenant'] = $tenant;
        $centre = mam_v2_load_centre($db, $tenant['id'], $opts, $commit);
        $report['centre'] = $centre;

        // On a dry run there is no tenant id to key lookups on, so the idempotency
        // probes below read nothing and every row counts as new — which is the
        // right answer for an empty target and the reason the report says
        // "would load" rather than "loaded".
        $ppl = mam_v2_load_people($db, (int) ($tenant['id'] ?? 0), $centre['id'], $people, $users, $commit);
        $report['name_conflicts'] = (int) ($identity['report']['name_conflicts'] ?? 0);
        $report['people'] = $ppl['report'];

        $report['applications'] = mam_v2_load_applications($db, (int) ($tenant['id'] ?? 0), $tApps['rows'], $ppl['ids'], $commit);
        $report['payments']     = mam_v2_load_payments($db, (int) ($tenant['id'] ?? 0), $tPays['rows'], $ppl['ids'], $commit);

        if ($started) { $db->commit(); $started = false; }
    } catch (\Throwable $e) {
        if ($started && $db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => 'load_failed', 'message' => $e->getMessage(),
                'committed' => false, 'partial' => $report];
    }

    $report['elapsed_ms'] = (int) round((microtime(true) - $t0) * 1000);
    return $report;
}
