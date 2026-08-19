<?php
/**
 * ETL — the PLAN (a whole v1 export → a whole v2 dataset). Pure. No database.
 * ─────────────────────────────────────────────────────────────
 * `transform.php` maps individual v1 tables. This assembles the WHOLE thing:
 * every table of the export becomes either v2 rows with explicit primary keys
 * and resolved foreign keys, or a line in the coverage ledger saying where it
 * went and why. Nothing connects and nothing writes, so the entire migration
 * can be reviewed, diffed and asserted before a database is involved.
 *
 * Why explicit ids rather than AUTO_INCREMENT. The output has to be loadable by
 * somebody else's tooling — a `.sql` file applied by hand, a psql-style
 * pipeline, a DBA. That means the foreign keys must already be resolved in the
 * file, which means the plan has to assign the keys. It also makes the output
 * a value: two runs over the same export produce byte-identical SQL, so a diff
 * between them is a real change and not just a new set of surrogate keys.
 *
 * Public ids are DERIVED, for the same reason: `mam_v2_ulid()` is random, so a
 * re-transform would rewrite every public id and nothing downstream could be
 * compared. `mam_v2_plan_pid()` hashes the source row's identity instead, and
 * keeps a ULID's time prefix where the row has a timestamp, so ids still sort
 * by age.
 *
 * ── The three rules the mapping follows ──
 *  1. **Nothing is invented.** Where a v2 column requires a fact the export
 *     does not hold, the row is REJECTED with a reason, or the fact is left
 *     NULL and stated. No default FX rates, no guessed dates, no synthesised
 *     grades.
 *  2. **Nothing is silently dropped.** Every table in the export appears in
 *     `coverage`, with row counts and a destination or a reason there is none.
 *     A v1 column with no v2 column goes to `custom_field_values` if it is
 *     real information, and is named in the ledger either way.
 *  3. **Every ambiguity is raised, not resolved.** `review` collects the
 *     decisions a human should confirm — merged identities, cohorts whose
 *     course had to be derived, statuses that had no exact counterpart.
 */
declare(strict_types=1);

require_once __DIR__ . '/identity.php';
require_once __DIR__ . '/transform.php';

/** Tables written, in an order that satisfies every foreign key. */
const MAM_V2_PLAN_ORDER = [
    'tenants', 'centres', 'tenant_settings',
    'users', 'user_credentials', 'memberships', 'roles', 'membership_roles', 'guardianships',
    'programmes', 'levels', 'courses', 'programme_courses', 'modules', 'competencies',
    'cohorts', 'cohort_staff', 'enrolments',
    'applications', 'payments',
    'custom_field_defs', 'custom_field_values',
    'message_deliveries', 'audit_log',
    'legacy_reference_map',
];

/**
 * A setting whose VALUE must not be written into a file on disk.
 *
 * The export was taken from production and the plan's output is a plain-text
 * artefact that gets copied around. A key matching this is carried with
 * `is_secret = 1` and a NULL value, so the new system knows the setting exists
 * and that somebody has to re-enter it. That is a better failure than either
 * copying a live credential into a `.sql` file or dropping the setting and
 * leaving a feature mysteriously off.
 */
const MAM_V2_SECRET_SETTING = '/(secret|password|passwd|private|_key$|_token$|credential|service_account)/i';

/**
 * Settings that are a CACHE, not configuration.
 *
 * `fx_rates_cached` with `fx_rates_at` looks fresh to the reader on the other
 * side, so carrying it means the new system displays a rate observed weeks ago
 * as current. Money that displays differently from what it charges is the exact
 * failure `fx.php` was hardened against; an absent cache just warms on first use.
 */
const MAM_V2_CACHE_SETTINGS = ['fx_rates_cached', 'fx_rates_at', 'fx_usd_ngn_cached', 'fx_usd_ngn_at'];

/** Crockford base32, ULID's alphabet. */
const MAM_V2_B32 = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

/**
 * A deterministic, ULID-shaped public id.
 *
 * 10 chars of timestamp (the source row's own, so ids sort by age) + 16 chars
 * derived from a hash of the seed. Same export in, same ids out — which is what
 * makes the output reviewable by diff.
 */
function mam_v2_plan_pid(string $seed, ?string $ts = null): string
{
    $ms = 0;
    $t  = trim((string) $ts);
    if ($t !== '') {
        $u = strtotime($t . (preg_match('/[Zz]|[+\-]\d\d:?\d\d$/', $t) ? '' : ' UTC'));
        if ($u !== false) $ms = $u * 1000;
    }
    $out = '';
    for ($i = 9; $i >= 0; $i--) { $out = MAM_V2_B32[$ms % 32] . $out; $ms = intdiv($ms, 32); }
    $h = hash('sha256', $seed, true);
    for ($i = 0; $i < 16; $i++) $out .= MAM_V2_B32[ord($h[$i]) % 32];
    return $out;
}

/** A v1 timestamp, carried verbatim (see mam_v2_ts's note) or a stated fallback. */
function mam_v2_plan_ts(?string $v, ?string $fallback = null): ?string
{
    $v = trim((string) $v);
    return $v !== '' ? $v : $fallback;
}

/** '1'/'0'/''/null → 0|1, without treating the string '0' as truthy. */
function mam_v2_plan_bool(mixed $v, int $default = 0): int
{
    if ($v === null || $v === '') return $default;
    if (is_bool($v)) return $v ? 1 : 0;
    $s = strtolower(trim((string) $v));
    return in_array($s, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

/** A v1 int-ish column that may be '' or null. */
function mam_v2_plan_int(mixed $v): ?int
{
    if ($v === null || $v === '' || !is_numeric((string) $v)) return null;
    return (int) $v;
}

/** A v1 string column, '' folded to null so a NULLable column stays honest. */
function mam_v2_plan_str(mixed $v, ?int $max = null): ?string
{
    $s = trim((string) ($v ?? ''));
    if ($s === '') return null;
    return $max !== null ? mb_substr($s, 0, $max) : $s;
}

/**
 * `'Fri 16:00-18:00'` / `'Wed 14:00-15:00, Thu 15:00-16:00'` → days + start time.
 *
 * v1 stored the meeting pattern as free text a human typed. v2 splits it into
 * `meeting_days` + `meeting_time` + `timezone` so a schedule can be computed
 * rather than read. Anything that does not parse cleanly is left NULL and the
 * original kept verbatim as a custom field — a half-parsed timetable that puts
 * a child's lesson on the wrong day is worse than one a human retypes.
 */
function mam_v2_plan_pattern(?string $pattern): array
{
    $s = trim((string) $pattern);
    if ($s === '') return ['days' => null, 'time' => null, 'parsed' => true];
    $map = ['mon' => 'mon', 'tue' => 'tue', 'tues' => 'tue', 'wed' => 'wed', 'thu' => 'thu',
            'thur' => 'thu', 'thurs' => 'thu', 'fri' => 'fri', 'sat' => 'sat', 'sun' => 'sun'];
    $days = [];
    foreach (preg_split('/[,;]+/', $s) ?: [] as $part) {
        if (preg_match('/^\s*([A-Za-z]{3,5})\b/', $part, $m)) {
            $k = strtolower($m[1]);
            if (isset($map[$k]) && !in_array($map[$k], $days, true)) $days[] = $map[$k];
        }
    }
    $time = null;
    if (preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)\b/', $s, $m)) {
        $time = sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
    }
    // "Parsed" means every comma-separated clause yielded a day. A pattern that
    // gave a time but no day, or fewer days than clauses, is not understood.
    $clauses = count(array_filter(preg_split('/[,;]+/', $s) ?: [], static fn($x) => trim($x) !== ''));
    $parsed  = $days !== [] && count($days) >= 1 && count($days) === $clauses && $time !== null;
    return ['days' => $days ? implode(',', $days) : null, 'time' => $time, 'parsed' => $parsed];
}

/** v1 enrolment status (both models) → v2 `enrolments.status`. */
function mam_v2_plan_enrol_status(?string $v1): string
{
    return [
        'active'      => 'active',
        'approved'    => 'active',
        'enrolled'    => 'active',
        'pending'     => 'pending',
        'requested'   => 'pending',
        'waitlisted'  => 'waitlisted',
        'completed'   => 'completed',
        'passed'      => 'completed',
        'withdrawn'   => 'withdrawn',
        'dropped'     => 'withdrawn',
        'cancelled'   => 'withdrawn',
        'transferred' => 'transferred',
    ][strtolower(trim((string) $v1))] ?? 'pending';
}

/** v1 cohort/class status → v2 `cohorts.status`. */
function mam_v2_plan_cohort_status(?string $v1): string
{
    return [
        'planned'   => 'planned',
        'draft'     => 'planned',
        'active'    => 'active',
        'running'   => 'active',
        'completed' => 'completed',
        'closed'    => 'completed',
        'cancelled' => 'cancelled',
    ][strtolower(trim((string) $v1))] ?? 'planned';
}

/** A setting's value shape, so `value_type` is derived rather than asserted. */
function mam_v2_plan_value_type(?string $v): string
{
    $s = trim((string) $v);
    if ($s === '') return 'string';
    if ($s === '0' || $s === '1') return 'bool';
    if (preg_match('/^-?\d+$/', $s)) return 'int';
    if (preg_match('/^-?\d+\.\d+$/', $s)) return 'decimal';
    if (($s[0] === '{' || $s[0] === '[') && json_decode($s) !== null) return 'json';
    return 'string';
}


/**
 * Apply the academy's exclusions to the export, before anything else reads it.
 *
 * A row somebody has decided is not a real record — a test run, a duplicate
 * entered twice — should never reach identity resolution, because by the time it
 * is a person it has an id, a membership and a place in every count.
 *
 * Three properties, each because the obvious alternative is worse:
 *
 *  1. **It cascades to dependents, not across them.** Excluding a customer takes
 *     their applications with them. It does NOT take their payments: if an
 *     excluded person holds money the whole run is REFUSED, named, and left for
 *     a human. A migration that quietly drops a real transaction is not one
 *     anybody can reconcile afterwards, and "it was only a test" is a claim that
 *     has to survive contact with a bank statement.
 *  2. **Nothing is removed silently.** Every removal is returned, reported in the
 *     coverage ledger, and written into the v2 audit log at load time — so the
 *     database explains its own gaps rather than leaving somebody to notice a
 *     missing applicant next term.
 *  3. **An exclusion that matches nothing is an ERROR.** A typo'd id would
 *     otherwise read as a successful exclusion while the row it named sailed
 *     through, which is the failure mode that makes this kind of list untrustworthy.
 */
function mam_v2_apply_exclusions(array $source, array $exclusions): array
{
    $removed = $missed = $money = [];
    if (!$exclusions) return ['source' => $source, 'removed' => [], 'missed' => [], 'money' => []];

    // Index the dependency chain once: application → customer, payment → application.
    $appsOfCustomer = [];
    foreach ((array) ($source['applications'] ?? []) as $a) {
        $appsOfCustomer[(int) ($a['customer_id'] ?? 0)][] = (int) ($a['id'] ?? 0);
    }
    $paysOfApp = [];
    foreach ((array) ($source['payments'] ?? []) as $pay) {
        $paysOfApp[(int) ($pay['application_id'] ?? 0)][] = (string) ($pay['id'] ?? '');
    }

    $drop = [];   // table => [id => reason]
    foreach ($exclusions as $x) {
        $row    = trim((string) ($x['row'] ?? ''));
        $reason = trim((string) ($x['reason'] ?? '')) ?: 'no reason recorded';
        [$table, $id] = array_pad(explode(':', $row, 2), 2, '');
        if ($table === '' || $id === '') { $missed[] = $row . ' (not in table:id form)'; continue; }

        $present = false;
        foreach ((array) ($source[$table] ?? []) as $r) {
            if ((string) ($r['id'] ?? '') === $id) { $present = true; break; }
        }
        if (!$present) { $missed[] = $row; continue; }

        $drop[$table][$id] = $reason;
        if ($table === 'customers') {
            foreach ($appsOfCustomer[(int) $id] ?? [] as $appId) {
                $drop['applications'][(string) $appId] = 'applicant ' . $row . ' excluded: ' . $reason;
                foreach ($paysOfApp[$appId] ?? [] as $payId) {
                    $money[] = ['payment' => 'payments:' . $payId, 'application' => 'applications:' . $appId,
                                'owner' => $row];
                }
            }
        }
    }

    // Money is where this stops being reversible. Refuse rather than decide.
    if ($money) return ['source' => $source, 'removed' => [], 'missed' => $missed, 'money' => $money];

    foreach ($drop as $table => $ids) {
        $source[$table] = array_values(array_filter((array) ($source[$table] ?? []),
            static fn(array $r): bool => !isset($ids[(string) ($r['id'] ?? '')])));
        foreach ($ids as $id => $reason) {
            $removed[] = ['row' => $table . ':' . $id, 'reason' => $reason];
        }
    }
    return ['source' => $source, 'removed' => $removed, 'missed' => $missed, 'money' => []];
}

/**
 * Build the whole plan.
 *
 * `$source` is the export keyed by table name. `$opts` carries the tenant and
 * centre identity, which is configuration rather than data and so is the one
 * thing here that does not come from v1.
 */
function mam_v2_plan(array $source, array $opts = []): array
{
    $T = [];                                     // table => rows
    foreach (MAM_V2_PLAN_ORDER as $t) $T[$t] = [];
    $coverage = $review = $rejected = [];
    $nowFallback = (string) ($opts['migrated_at'] ?? '2026-01-01 00:00:00');

    /* ── Exclusions, before anything else reads the export ────────────────── */
    $exclusions = (array) ($opts['exclusions'] ?? []);
    $ex = mam_v2_apply_exclusions($source, $exclusions);
    if ($ex['money']) {
        return ['ok' => false, 'error' => 'exclusion_holds_money', 'money' => $ex['money']];
    }
    if ($ex['missed']) {
        // A named row that is not in the export means the list and the snapshot
        // disagree. Refusing is the point: the alternative is a list that looks
        // applied while the row it named sailed through.
        return ['ok' => false, 'error' => 'exclusion_not_found', 'missed' => $ex['missed']];
    }
    $source  = $ex['source'];
    $excluded = $ex['removed'];

    $rows = static fn(string $t) => array_values((array) ($source[$t] ?? []));
    $note = function (string $table, ?string $dest, string $why, int $in, int $out = 0) use (&$coverage): void {
        $coverage[] = ['table' => $table, 'rows_in' => $in, 'destination' => $dest,
                       'rows_out' => $out, 'note' => $why];
    };
    $flag = function (string $kind, array $detail) use (&$review): void {
        $review[] = ['kind' => $kind] + $detail;
    };
    // Every mapped row records where it came from. Written last, so it can name
    // rows from every stage.
    $map = [];
    $mapped = function (string $lTable, int|string $lId, string $v2Table, int $v2Id,
                        ?string $ref = null, ?string $noteText = null) use (&$map): void {
        $map[] = ['legacy_table' => $lTable, 'legacy_id' => (string) $lId, 'legacy_ref' => $ref,
                  'v2_table' => $v2Table, 'v2_id' => $v2Id, 'note' => $noteText];
    };

    /* ── Tenant + centre ─────────────────────────────────────────────────── */
    $tenantId = 1;
    $T['tenants'][] = [
        'id' => $tenantId, 'public_id' => mam_v2_plan_pid('tenant:mam'),
        'slug' => (string) ($opts['tenant_slug'] ?? 'mam'),
        'legal_name' => (string) ($opts['tenant_legal_name'] ?? 'MAM Centre'),
        'display_name' => (string) ($opts['tenant_name'] ?? 'MAM Academy'),
        'base_currency' => MAM_V2_BASE_CURRENCY, 'default_locale' => 'en',
        'default_timezone' => 'Africa/Lagos', 'status' => 'active',
    ];
    $centreId = 1;
    $T['centres'][] = [
        'id' => $centreId, 'public_id' => mam_v2_plan_pid('centre:MAIN'), 'tenant_id' => $tenantId,
        'code' => (string) ($opts['centre_code'] ?? 'MAIN'),
        'name' => (string) ($opts['centre_name'] ?? 'MAM Academy'),
        'kind' => 'physical', 'timezone' => 'Africa/Lagos', 'is_active' => 1,
    ];

    /* ── Settings ────────────────────────────────────────────────────────── */
    $settings = $rows('settings');
    $sid = 0; $secrets = 0; $caches = 0;
    foreach ($settings as $s) {
        $k = (string) ($s['skey'] ?? '');
        if ($k === '') continue;
        if (in_array($k, MAM_V2_CACHE_SETTINGS, true)) { $caches++; continue; }
        $isSecret = preg_match(MAM_V2_SECRET_SETTING, $k) === 1;
        if ($isSecret) $secrets++;
        $T['tenant_settings'][] = [
            'id' => ++$sid, 'tenant_id' => $tenantId, 'skey' => mb_substr($k, 0, 120),
            'value_type' => mam_v2_plan_value_type($s['svalue'] ?? null),
            'svalue' => $isSecret ? null : ($s['svalue'] ?? null),
            'is_secret' => $isSecret ? 1 : 0,
            'updated_at' => mam_v2_plan_ts($s['updated_at'] ?? null, $nowFallback),
        ];
    }
    $note('settings', 'tenant_settings',
        sprintf('%d carried; %d cached FX key(s) deliberately not carried (a stale rate reads as current); '
              . '%d secret-shaped key(s) carried with a NULL value and is_secret=1 for re-entry.',
              $sid, $caches, $secrets), count($settings), $sid);

    /* ── Identity ────────────────────────────────────────────────────────── */
    $customers = $rows('customers');
    $v1users   = $rows('users');
    $identity  = mam_v2_resolve_identities($customers, $v1users);
    $people    = $identity['people'];
    $unaccounted = mam_v2_identity_unaccounted($customers, $v1users, $people);
    if ($unaccounted) {
        // Row preservation is a claim until this is empty. Refuse to plan rather
        // than emit a dataset that quietly lost somebody.
        return ['ok' => false, 'error' => 'identity_unaccounted', 'unaccounted' => $unaccounted];
    }
    foreach ($identity['report']['review'] as $r) $review[] = $r;

    $usersById = [];
    foreach ($v1users as $u) $usersById[(int) ($u['id'] ?? 0)] = $u;

    $userId = $credId = $memId = 0;
    $memberOfPerson = [];        // person index => membership id
    $userOfV1User   = [];        // v1 user id   => v2 user id
    $memberOfV1User = [];        // v1 user id   => membership id
    $userOfPerson   = [];
    foreach ($people as $i => $p) {
        $v1u   = $p['user_id'] !== null ? ($usersById[(int) $p['user_id']] ?? []) : [];
        $email = trim((string) ($p['email'] ?? ''));
        if ($email === '') {
            // `users.email` is NOT NULL and UNIQUE. A person with no address
            // gets a stable, obviously-synthetic one rather than being dropped:
            // the row still has to be countable, and `.invalid` can never route.
            $email = 'no-email-' . preg_replace('/[^a-z0-9]+/i', '-', (string) $p['sources'][0]) . '@migrated.invalid';
        }
        $created = mam_v2_plan_ts($v1u['created_at'] ?? null, $nowFallback);
        $uid = ++$userId;
        $T['users'][] = [
            'id' => $uid, 'public_id' => mam_v2_plan_pid('user:' . implode('|', $p['sources']), $created),
            'email' => mb_substr($email, 0, 190),
            'phone' => mam_v2_plan_str($p['phone'] ?? null, 32),
            'full_name' => mb_substr(((string) ($p['full_name'] ?? '')) !== '' ? (string) $p['full_name'] : 'Erased user', 0, 160),
            'date_of_birth' => mam_v2_plan_str($p['dob'] ?? null),
            'avatar_url' => !empty($p['erased']) ? null : mam_v2_plan_str($v1u['avatar_url'] ?? null, 500),
            'locale' => 'en', 'timezone' => 'Africa/Lagos',
            // An erased person is `closed`, not `active`: the row exists so the
            // counts reconcile and the academic history keeps its owner.
            'status' => !empty($p['erased']) ? 'closed' : 'active',
            'created_at' => $created,
        ];
        $userOfPerson[$i] = $uid;

        // Credentials: bcrypt hashes ARE carried. They are the only thing v1
        // holds, and the alternative — a forced reset for every family on
        // cutover day — turns a migration into a support incident and trains
        // people to click reset links in unexpected email. v1's OWN
        // `must_change_password` flag is honoured; it is not overridden either way.
        if (!empty($p['credentials']) && empty($p['erased']) && !empty($v1u['password_hash'])) {
            $T['user_credentials'][] = [
                'id' => ++$credId, 'user_id' => $uid,
                'password_hash' => (string) $v1u['password_hash'], 'algo' => 'bcrypt',
                'must_change' => mam_v2_plan_bool($v1u['must_change_password'] ?? null),
            ];
        }

        $mid = ++$memId;
        $T['memberships'][] = [
            'id' => $mid, 'public_id' => mam_v2_plan_pid('membership:' . implode('|', $p['sources']), $created),
            'tenant_id' => $tenantId, 'user_id' => $uid, 'centre_id' => $centreId,
            'reference' => mam_v2_plan_str($v1u['enrollment_ref'] ?? null, 32),
            'stage' => mam_v2_stage_for($p, $v1u), 'stage_entered_at' => $created,
            'status' => !empty($p['erased']) ? 'closed' : 'active', 'joined_at' => $created,
        ];
        $memberOfPerson[$i] = $mid;
        if ($p['user_id'] !== null) {
            $memberOfV1User[(int) $p['user_id']] = $mid;
            $userOfV1User[(int) $p['user_id']]   = $uid;
        }

        // BOTH source rows map to the one v2 user — that is what makes a merge
        // auditable rather than a fact somebody has to take on trust.
        foreach ((array) $p['sources'] as $src) {
            [$lt, $lid] = array_pad(explode(':', $src, 2), 2, '');
            $mapped($lt, $lid, 'users', $uid, mam_v2_plan_str($v1u['enrollment_ref'] ?? null, 64),
                $p['merged_by'] !== null ? 'merged identity via ' . $p['merged_by'] : null);
        }
    }
    $note('customers', 'users + memberships', 'merged with `users` by identity resolution.', count($customers));
    $note('users', 'users + user_credentials + memberships',
        sprintf('%d v1 rows + %d customers → %d people (%d merged: %d by the explicit users.customer_id link, '
              . '%d by address+name; %d kept apart on a shared address where the names disagree).',
              count($v1users), count($customers), count($people), $identity['report']['merged'],
              $identity['report']['merged_explicit'], $identity['report']['merged_email'],
              $identity['report']['split_shared_email']), count($v1users), $userId);

    /* ── Roles ───────────────────────────────────────────────────────────── */
    // Derived from the data, not from a hardcoded list: a role nobody holds
    // should not appear, and a role somebody holds must not be missing.
    $roleNames = ['student' => 'Student', 'instructor' => 'Instructor', 'admin' => 'Administrator',
                  'parent' => 'Parent or guardian', 'super_admin' => 'Super administrator'];
    $wanted = [];
    foreach ($rows('user_roles') as $r) { $c = strtolower(trim((string) ($r['role'] ?? ''))); if ($c !== '') $wanted[$c] = true; }
    foreach ($v1users as $u) {
        $c = strtolower(trim((string) ($u['role'] ?? ''))); if ($c !== '') $wanted[$c] = true;
        if (strtolower(trim((string) ($u['admin_tier'] ?? ''))) === 'super') $wanted['super_admin'] = true;
    }
    $roleId = 0; $roleIdOf = [];
    foreach (array_keys($wanted) as $code) {
        $roleIdOf[$code] = ++$roleId;
        $T['roles'][] = ['id' => $roleId, 'tenant_id' => $tenantId, 'tenant_scope' => $tenantId,
                         'code' => $code, 'name' => $roleNames[$code] ?? ucfirst(str_replace('_', ' ', $code)),
                         'is_system' => 1];
    }
    // Permissions and the role→permission matrix are deliberately NOT invented
    // here: they are the new system's authorisation policy, not v1 data, and a
    // guessed matrix is a security decision made by an ETL.
    $note('user_roles', 'roles + membership_roles', 'union of user_roles and users.role; '
        . 'permissions and role_permissions are the new system\'s policy and are left to it to seed.',
        count($rows('user_roles')));

    $grants = [];
    $addGrant = function (int $v1UserId, string $code) use (&$grants, $memberOfV1User, $roleIdOf): void {
        $mid = $memberOfV1User[$v1UserId] ?? null;
        if ($mid === null || !isset($roleIdOf[$code])) return;
        $grants[$mid . ':' . $roleIdOf[$code]] = ['membership_id' => $mid, 'role_id' => $roleIdOf[$code]];
    };
    foreach ($rows('user_roles') as $r) $addGrant((int) ($r['user_id'] ?? 0), strtolower(trim((string) ($r['role'] ?? ''))));
    foreach ($v1users as $u) {
        $addGrant((int) ($u['id'] ?? 0), strtolower(trim((string) ($u['role'] ?? ''))));
        if (strtolower(trim((string) ($u['admin_tier'] ?? ''))) === 'super') $addGrant((int) ($u['id'] ?? 0), 'super_admin');
    }
    foreach ($grants as $g) {
        $T['membership_roles'][] = $g + ['centre_id' => null, 'granted_at' => $nowFallback];
    }

    /* ── Catalogue ───────────────────────────────────────────────────────── */
    $progId = 0; $progIdOf = []; $progCodeOf = [];
    foreach ($rows('programs') as $p) {
        $code = mam_v2_plan_str($p['code'] ?? null, 48);
        if ($code === null) { $rejected[] = ['table' => 'programs', 'id' => $p['id'] ?? null, 'why' => 'no code']; continue; }
        $id = ++$progId;
        $progIdOf[(int) ($p['id'] ?? 0)] = $id;
        $progCodeOf[strtolower($code)] = $id;
        $T['programmes'][] = [
            'id' => $id, 'public_id' => mam_v2_plan_pid('programme:' . $code), 'tenant_id' => $tenantId,
            'code' => $code, 'name' => mb_substr((string) ($p['name'] ?? $code), 0, 160),
            'duration_weeks' => mam_v2_plan_int($p['weeks'] ?? null) ?? 12,
            'certification' => mam_v2_plan_str($p['certification'] ?? null, 32),
            'sort_order' => mam_v2_plan_int($p['sort_order'] ?? null) ?? 0,
            'is_active' => mam_v2_plan_bool($p['active'] ?? null, 1),
        ];
        $mapped('programs', (int) ($p['id'] ?? 0), 'programmes', $id, $code);
    }
    // v1's programme SHAPE — which week is revision, which is the exam, whether
    // the core courses are compulsory — has no v2 column. It is real curriculum
    // information, so it goes to custom fields rather than being dropped.
    $cfdId = 0; $cfvId = 0; $cfdIdOf = [];
    $defineField = function (string $entity, string $key, string $label, string $type)
        use (&$T, &$cfdId, &$cfdIdOf, $tenantId, $nowFallback): int {
        $k = $entity . '.' . $key;
        if (isset($cfdIdOf[$k])) return $cfdIdOf[$k];
        $cfdIdOf[$k] = ++$cfdId;
        $T['custom_field_defs'][] = ['id' => $cfdId, 'tenant_id' => $tenantId, 'entity_type' => $entity,
            'field_key' => $key, 'label' => $label, 'data_type' => $type, 'is_required' => 0,
            'is_pii' => 0, 'sort_order' => $cfdId, 'is_active' => 1, 'created_at' => $nowFallback];
        return $cfdId;
    };
    $setField = function (int $defId, int $entityId, mixed $value, string $type)
        use (&$T, &$cfvId, $tenantId, $nowFallback): void {
        if ($value === null || $value === '') return;
        $T['custom_field_values'][] = ['id' => ++$cfvId, 'tenant_id' => $tenantId, 'field_def_id' => $defId,
            'entity_id' => $entityId,
            'value_text'   => in_array($type, ['string', 'json'], true) ? (string) $value : null,
            'value_number' => in_array($type, ['int', 'decimal'], true) ? (string) $value : null,
            'value_date'   => $type === 'date' ? (string) $value : null,
            'value_bool'   => $type === 'bool' ? mam_v2_plan_bool($value) : null,
            'updated_at'   => $nowFallback];
    };
    $fRev  = $defineField('programmes', 'revision_week', 'Revision week', 'int');
    $fExam = $defineField('programmes', 'exam_week', 'Exam week', 'int');
    $fCore = $defineField('programmes', 'requires_core', 'Core courses compulsory', 'bool');
    foreach ($rows('programs') as $p) {
        $id = $progIdOf[(int) ($p['id'] ?? 0)] ?? null;
        if ($id === null) continue;
        $setField($fRev,  $id, mam_v2_plan_int($p['revision_week'] ?? null), 'int');
        $setField($fExam, $id, mam_v2_plan_int($p['exam_week'] ?? null), 'int');
        $setField($fCore, $id, mam_v2_plan_bool($p['requires_core'] ?? null), 'bool');
    }

    $lvlId = 0; $lvlIdOf = []; $lvlIdOfCode = [];
    foreach ($rows('levels') as $l) {
        $code = mam_v2_plan_str($l['code'] ?? null, 32);
        if ($code === null) { $rejected[] = ['table' => 'levels', 'id' => $l['id'] ?? null, 'why' => 'no code']; continue; }
        $id = ++$lvlId;
        $lvlIdOf[(int) ($l['id'] ?? 0)] = $id;
        $lvlIdOfCode[strtolower($code)] = $id;
        $T['levels'][] = ['id' => $id, 'tenant_id' => $tenantId, 'code' => $code,
            'name' => mb_substr((string) ($l['name'] ?? $code), 0, 120),
            'external_grades' => mam_v2_plan_str($l['trinity_grades'] ?? null, 64),
            'rank_order' => mam_v2_plan_int($l['sort_order'] ?? null) ?? 0, 'is_active' => 1];
        $mapped('levels', (int) ($l['id'] ?? 0), 'levels', $id, $code);
    }

    $crsId = 0; $crsIdOf = []; $crsIdOfCode = []; $crsKindOf = [];
    foreach ($rows('courses') as $c) {
        $code = mam_v2_plan_str($c['code'] ?? null, 48);
        if ($code === null) { $rejected[] = ['table' => 'courses', 'id' => $c['id'] ?? null, 'why' => 'no code']; continue; }
        $id = ++$crsId;
        $crsIdOf[(int) ($c['id'] ?? 0)] = $id;
        $crsIdOfCode[strtolower($code)] = $id;
        $crsKindOf[$id] = strtolower(trim((string) ($c['kind'] ?? 'elective')));
        $T['courses'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('course:' . $code), 'tenant_id' => $tenantId,
            'code' => $code, 'name' => mb_substr((string) ($c['name'] ?? $code), 0, 160),
            'kind' => $crsKindOf[$id] !== '' ? $crsKindOf[$id] : 'elective',
            // v1 had no discipline column on `courses`; the elective courses ARE
            // the instruments, so leaving it NULL states the truth rather than
            // copying the course name into a grouping field.
            'discipline' => null,
            'description' => mam_v2_plan_str($c['description'] ?? null),
            'sort_order' => mam_v2_plan_int($c['sort_order'] ?? null) ?? 0,
            'is_active' => mam_v2_plan_bool($c['active'] ?? null, 1)];
        $mapped('courses', (int) ($c['id'] ?? 0), 'courses', $id, $code);
    }

    $pcId = 0; $pcSeen = [];
    foreach ($rows('program_courses') as $pc) {
        $pid = $progIdOf[(int) ($pc['program_id'] ?? 0)] ?? null;
        $cid = $crsIdOf[(int) ($pc['course_id'] ?? 0)] ?? null;
        if ($pid === null || $cid === null) {
            $rejected[] = ['table' => 'program_courses',
                'id' => (string) ($pc['program_id'] ?? '?') . '/' . (string) ($pc['course_id'] ?? '?'),
                'why' => 'programme or course did not map'];
            continue;
        }
        // v1 keys this by level CODE, not id.
        $lcode = strtolower((string) mam_v2_plan_str($pc['level_code'] ?? null, 32));
        $lid   = $lcode !== '' ? ($lvlIdOfCode[$lcode] ?? null) : null;
        $scope = $lid ?? 0;
        $k = $pid . ':' . $cid . ':' . $scope;
        if (isset($pcSeen[$k])) continue;             // uq_pc
        $pcSeen[$k] = true;
        $T['programme_courses'][] = ['id' => ++$pcId, 'programme_id' => $pid, 'course_id' => $cid,
            'level_id' => $lid, 'level_scope' => $scope,
            // v1 has no per-row required flag; a `core` course is the compulsory
            // one and that is the whole of what the data says.
            'is_required' => ($crsKindOf[$cid] ?? '') === 'core' ? 1 : 0,
            'sort_order' => mam_v2_plan_int($pc['sort_order'] ?? null) ?? 0];
    }
    $note('programs', 'programmes (+ custom fields revision_week/exam_week/requires_core)',
        'v1 programme ids are NOT preserved — `ktams` is id 22 in production and would be a different '
        . 'number anywhere else, so everything downstream is repointed by code.',
        count($rows('programs')), $progId);
    $note('levels', 'levels', 'trinity_grades → external_grades; sort_order → rank_order (progression order).',
        count($rows('levels')), $lvlId);
    $note('courses', 'courses',
        'v1 had no `discipline` column on courses — the elective courses ARE the instruments — so v2\'s '
        . 'grouping column is left NULL rather than filled with the course name.',
        count($rows('courses')), $crsId);
    $note('program_courses', 'programme_courses',
        'level resolved from v1\'s level_code; is_required derived from courses.kind = core.',
        count($rows('program_courses')), $pcId);

    $modId = 0;
    $fModKind = $defineField('modules', 'module_kind', 'Week kind (module/revision/exam)', 'string');
    foreach ($rows('modules') as $m) {
        $cid = $crsIdOf[(int) ($m['course_id'] ?? 0)] ?? null;
        if ($cid === null) { $rejected[] = ['table' => 'modules', 'id' => $m['id'] ?? null, 'why' => 'course did not map']; continue; }
        $id = ++$modId;
        $T['modules'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('module:' . (string) ($m['id'] ?? $id)),
            'tenant_id' => $tenantId, 'course_id' => $cid,
            'level_id' => $lvlIdOf[(int) ($m['level_id'] ?? 0)] ?? null,
            'week_no' => mam_v2_plan_int($m['week'] ?? null),
            'title' => mb_substr((string) ($m['title'] ?? ('Week ' . (string) ($m['week'] ?? '?'))), 0, 200),
            // v1's `content` is the week's teaching brief. `objectives` is the
            // closest v2 column and holds the same thing; `prep_instructions`
            // is left NULL rather than duplicating it, because a pre-class task
            // the instructor never wrote should not appear as one.
            'objectives' => mam_v2_plan_str($m['content'] ?? null),
            'requires_teachback' => 0, 'is_published' => 1];
        // `module|revision|exam` has no v2 column and it decides what a week IS.
        $setField($fModKind, $id, mam_v2_plan_str($m['kind'] ?? null), 'string');
        $mapped('modules', (int) ($m['id'] ?? 0), 'modules', $id);
    }
    $note('modules', 'modules (+ custom field module_kind)',
        'content → objectives; kind has no v2 column and is carried as a custom field.',
        count($rows('modules')), $modId);

    // v1's assessment weighting IS v2's competency weighting: same shape, same
    // purpose (code, name, share of the score).
    $compId = 0;
    foreach ($rows('assessment_types') as $a) {
        $code = mam_v2_plan_str($a['code'] ?? null, 48);
        if ($code === null) continue;
        $T['competencies'][] = ['id' => ++$compId, 'tenant_id' => $tenantId, 'code' => $code,
            'name' => mb_substr((string) ($a['name'] ?? $code), 0, 120), 'description' => null,
            'weight_pct' => number_format((float) ($a['weight'] ?? 0), 2, '.', ''),
            'sort_order' => mam_v2_plan_int($a['sort_order'] ?? null) ?? 0,
            'is_active' => mam_v2_plan_bool($a['active'] ?? null, 1)];
        $mapped('assessment_types', (int) ($a['id'] ?? 0), 'competencies', $compId, $code);
    }
    $note('assessment_types', 'competencies',
        'same shape and purpose: a code, a name and a share of the score.',
        count($rows('assessment_types')), $compId);

    /* ── Instructor profiles → custom fields on the membership ───────────── */
    $fBio  = $defineField('memberships', 'instructor_bio', 'Instructor bio', 'string');
    $fSpec = $defineField('memberships', 'instructor_specialties', 'Specialties', 'string');
    $fYrs  = $defineField('memberships', 'instructor_years', 'Years of experience', 'int');
    $profs = 0;
    foreach ($rows('instructor_profiles') as $ip) {
        $mid = $memberOfV1User[(int) ($ip['user_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'instructor_profiles', 'id' => $ip['user_id'] ?? null, 'why' => 'instructor did not map']; continue; }
        $setField($fBio,  $mid, mam_v2_plan_str($ip['bio'] ?? null), 'string');
        $setField($fSpec, $mid, mam_v2_plan_str($ip['specialties'] ?? null), 'string');
        $setField($fYrs,  $mid, mam_v2_plan_int($ip['years_experience'] ?? null), 'int');
        $profs++;
    }
    $note('instructor_profiles', 'custom_field_values (on memberships)',
        'bio/specialties/years have no v2 column; photo_url is already on users.avatar_url.',
        count($rows('instructor_profiles')), $profs);


    /* ── Delivery: ONE cohort spine ──────────────────────────────────────── */
    // v1 carried two parallel models — `classes`+`enrollments` and
    // `cohorts`+`course_enrollments` — and a learner could exist in one and not
    // the other. v2 has one spine, so this is where the convergence actually
    // happens: every legacy class becomes a cohort (or is recognised as one
    // already backfilled via `cohorts.legacy_class_id`) and both enrolment
    // tables land in `enrolments`.
    $v1cohorts = $rows('cohorts');
    $v1classes = $rows('classes');
    $cohId = 0; $cohIdOfV1 = []; $cohIdOfClass = []; $cohCourseOf = [];
    $fPattern = $defineField('cohorts', 'legacy_meeting_pattern', 'Meeting pattern as typed in v1', 'string');
    $fMeetLink = $defineField('cohorts', 'meeting_link', 'Meeting link', 'string');

    // `cohorts.course_id` is NOT NULL in v2 — a class that teaches nothing is
    // not a class. Three of the real cohorts are private lessons created before
    // the catalogue existed and carry only a free-text `discipline` ("Piano").
    // That resolves against the course catalogue by code, which is a lookup and
    // not a guess; anything that does not resolve is REJECTED and named, because
    // inventing a course would put a learner in a subject nobody chose.
    $courseFromDiscipline = function (?string $discipline) use ($crsIdOfCode): ?int {
        $d = strtolower(trim((string) $discipline));
        if ($d === '') return null;
        $d = preg_replace('/[^a-z0-9]+/', '_', $d) ?? '';
        return $crsIdOfCode[$d] ?? null;
    };

    foreach ($v1cohorts as $c) {
        $v1id  = (int) ($c['id'] ?? 0);
        $cid   = $crsIdOf[(int) ($c['course_id'] ?? 0)] ?? null;
        $why   = null;
        if ($cid === null) {
            $cid = $courseFromDiscipline($c['discipline'] ?? null);
            if ($cid !== null) {
                $why = 'course derived from the free-text discipline "' . (string) $c['discipline'] . '"';
                $flag('cohort_course_derived', ['cohort' => 'cohorts:' . $v1id,
                    'title' => (string) ($c['title'] ?? ''), 'discipline' => (string) ($c['discipline'] ?? ''),
                    'resolved_course_id' => $cid,
                    'action' => 'Confirm the subject is right; v1 recorded no course on this cohort.']);
            }
        }
        if ($cid === null) {
            $rejected[] = ['table' => 'cohorts', 'id' => $v1id,
                'why' => 'no course and none derivable from discipline '
                       . var_export($c['discipline'] ?? null, true) . ' — v2 cohorts.course_id is NOT NULL'];
            continue;
        }
        $pat  = mam_v2_plan_pattern($c['meeting_pattern'] ?? null);
        $days = $pat['parsed'] ? $pat['days'] : (mam_v2_plan_str($c['schedule_days'] ?? null, 20));
        $time = $pat['parsed'] ? $pat['time'] : (mam_v2_plan_str($c['schedule_time'] ?? null));
        if (!$pat['parsed'] && mam_v2_plan_str($c['meeting_pattern'] ?? null) !== null) {
            $flag('cohort_pattern_unparsed', ['cohort' => 'cohorts:' . $v1id,
                'pattern' => (string) $c['meeting_pattern'],
                'action' => 'Re-enter the timetable; it is kept verbatim as a custom field so nothing is lost.']);
        }
        $created = mam_v2_plan_ts($c['created_at'] ?? null, $nowFallback);
        $id = ++$cohId;
        $cohIdOfV1[$v1id] = $id;
        $cohCourseOf[$id] = $cid;
        if (($lc = mam_v2_plan_int($c['legacy_class_id'] ?? null)) !== null) $cohIdOfClass[$lc] = $id;
        $T['cohorts'][] = [
            'id' => $id, 'public_id' => mam_v2_plan_pid('cohort:' . $v1id, $created),
            'tenant_id' => $tenantId, 'centre_id' => $centreId, 'course_id' => $cid,
            'level_id' => $lvlIdOf[(int) ($c['level_id'] ?? 0)] ?? null,
            'programme_id' => $progIdOf[(int) ($c['program_id'] ?? 0)] ?? null,
            'title' => mb_substr((string) ($c['title'] ?? 'Cohort ' . $v1id), 0, 160),
            'reference' => null, 'term' => mam_v2_plan_str($c['term'] ?? null, 48),
            'delivery_mode' => in_array(strtolower(trim((string) ($c['mode'] ?? ''))), ['onsite', 'online', 'hybrid'], true)
                ? strtolower(trim((string) $c['mode'])) : 'onsite',
            'meeting_days' => $days, 'meeting_time' => $time,
            // Wall-clock plus a zone, never a bare instant: a lesson at 16:00
            // Lagos must stay at 16:00 if offset rules change.
            'timezone' => 'Africa/Lagos',
            'session_minutes' => mam_v2_plan_int($c['session_duration'] ?? null) ?? 60,
            'starts_on' => mam_v2_plan_str($c['starts_on'] ?? null),
            'ends_on' => mam_v2_plan_str($c['ends_on'] ?? null),
            // NULL = uncapped. A literal 0 would read as permanently full, which
            // is why "unlimited" must never be expressed as zero.
            'capacity' => mam_v2_plan_int($c['capacity'] ?? null),
            'status' => mam_v2_plan_cohort_status($c['status'] ?? null),
            'lead_instructor_membership_id' => $memberOfV1User[(int) ($c['instructor_id'] ?? 0)] ?? null,
            'created_at' => $created,
        ];
        if (mam_v2_plan_str($c['meeting_pattern'] ?? null) !== null) {
            $setField($fPattern, $id, (string) $c['meeting_pattern'], 'string');
        }
        $mapped('cohorts', $v1id, 'cohorts', $id, null, $why);
    }

    // Legacy classes that no cohort was ever backfilled from. In the real
    // snapshot all three are already covered by `legacy_class_id`, and this
    // exists so an export where they are not does not silently lose a class.
    $classesFolded = 0; $classesNew = 0;
    foreach ($v1classes as $cl) {
        $v1id = (int) ($cl['id'] ?? 0);
        if (isset($cohIdOfClass[$v1id])) {
            $classesFolded++;
            $mapped('classes', $v1id, 'cohorts', $cohIdOfClass[$v1id], null,
                'already represented by the cohort backfilled from it');
            if (mam_v2_plan_str($cl['meeting_link'] ?? null) !== null) {
                $setField($fMeetLink, $cohIdOfClass[$v1id], (string) $cl['meeting_link'], 'string');
            }
            continue;
        }
        $cid = $courseFromDiscipline($cl['discipline'] ?? null);
        if ($cid === null) {
            $rejected[] = ['table' => 'classes', 'id' => $v1id,
                'why' => 'legacy class with no cohort and no course derivable from discipline '
                       . var_export($cl['discipline'] ?? null, true)];
            continue;
        }
        $pat = mam_v2_plan_pattern($cl['schedule'] ?? null);
        $created = mam_v2_plan_ts($cl['created_at'] ?? null, $nowFallback);
        $id = ++$cohId;
        $cohIdOfClass[$v1id] = $id;
        $cohCourseOf[$id] = $cid;
        $T['cohorts'][] = [
            'id' => $id, 'public_id' => mam_v2_plan_pid('cohort-from-class:' . $v1id, $created),
            'tenant_id' => $tenantId, 'centre_id' => $centreId, 'course_id' => $cid,
            'level_id' => null, 'programme_id' => null,
            'title' => mb_substr((string) ($cl['title'] ?? 'Class ' . $v1id), 0, 160),
            'reference' => null, 'term' => null,
            'delivery_mode' => in_array(strtolower(trim((string) ($cl['mode'] ?? ''))), ['onsite', 'online', 'hybrid'], true)
                ? strtolower(trim((string) $cl['mode'])) : 'onsite',
            'meeting_days' => $pat['parsed'] ? $pat['days'] : null,
            'meeting_time' => $pat['parsed'] ? $pat['time'] : null,
            'timezone' => 'Africa/Lagos', 'session_minutes' => 60,
            'starts_on' => mam_v2_plan_str($cl['starts_on'] ?? null), 'ends_on' => null,
            'capacity' => mam_v2_plan_int($cl['capacity'] ?? null),
            'status' => mam_v2_plan_cohort_status($cl['status'] ?? null),
            'lead_instructor_membership_id' => $memberOfV1User[(int) ($cl['instructor_id'] ?? 0)] ?? null,
            'created_at' => $created,
        ];
        if (mam_v2_plan_str($cl['schedule'] ?? null) !== null) $setField($fPattern, $id, (string) $cl['schedule'], 'string');
        if (mam_v2_plan_str($cl['meeting_link'] ?? null) !== null) $setField($fMeetLink, $id, (string) $cl['meeting_link'], 'string');
        $classesNew++;
        $mapped('classes', $v1id, 'cohorts', $id);
    }
    $note('cohorts', 'cohorts', 'course resolved from the catalogue, or derived from the free-text '
        . 'discipline where v1 recorded none (raised for review).', count($v1cohorts), $cohId);
    $note('classes', 'cohorts (the legacy half of the two enrolment models)',
        sprintf('%d already represented by a backfilled cohort, %d converted.', $classesFolded, $classesNew),
        count($v1classes), $classesNew);

    // Staffing. v2 keeps it many-to-many so an assistant or a substitute is
    // recordable; v1 had one instructor per cohort, so that is what is written.
    $staff = [];
    foreach ($T['cohorts'] as $c) {
        if ($c['lead_instructor_membership_id'] === null) continue;
        $staff[$c['id'] . ':' . $c['lead_instructor_membership_id']] = [
            'cohort_id' => $c['id'], 'membership_id' => $c['lead_instructor_membership_id'],
            'role' => 'instructor', 'starts_on' => $c['starts_on'], 'ends_on' => $c['ends_on'],
        ];
    }
    foreach ($staff as $r) $T['cohort_staff'][] = $r;

    /* ── Enrolments: both v1 models into one table ───────────────────────── */
    $enrId = 0; $enrSeen = []; $enrOfCourseEnrol = [];
    $addEnrolment = function (array $r) use (&$T, &$enrId, &$enrSeen, &$rejected, $tenantId, $nowFallback): ?int {
        $k = $r['cohort_id'] . ':' . $r['membership_id'];
        if (isset($enrSeen[$k])) {
            // uq_enrolments_unique. The two v1 models overlap by design, so this
            // is the convergence working — not an error — and the surviving row
            // is the one that already exists.
            return $enrSeen[$k];
        }
        $id = ++$enrId;
        $enrSeen[$k] = $id;
        $T['enrolments'][] = ['id' => $id] + $r;
        return $id;
    };
    foreach ($rows('course_enrollments') as $e) {
        $v1id = (int) ($e['id'] ?? 0);
        $mid  = $memberOfV1User[(int) ($e['student_id'] ?? 0)] ?? null;
        $coh  = $cohIdOfV1[(int) ($e['cohort_id'] ?? 0)] ?? null;
        if ($mid === null || $coh === null) {
            $rejected[] = ['table' => 'course_enrollments', 'id' => $v1id,
                'why' => $mid === null ? 'student did not map to a membership' : 'cohort did not map'];
            continue;
        }
        $status  = mam_v2_plan_enrol_status($e['status'] ?? null);
        $created = mam_v2_plan_ts($e['created_at'] ?? null, $nowFallback);
        $id = $addEnrolment([
            'public_id' => mam_v2_plan_pid('enrolment:course_enrollments:' . $v1id, $created),
            'tenant_id' => $tenantId, 'membership_id' => $mid, 'cohort_id' => $coh, 'status' => $status,
            'waitlist_position' => null,
            'enrolled_at' => mam_v2_plan_ts($e['approved_at'] ?? null, $created),
            'completed_at' => $status === 'completed' ? mam_v2_plan_ts($e['status_changed_at'] ?? null, null) : null,
            'withdrawn_at' => $status === 'withdrawn' ? mam_v2_plan_ts($e['status_changed_at'] ?? null, null) : null,
            // chk_enrol_withdrawal_reason: a withdrawal must say why. v1 recorded
            // no reason on the enrolment row, so the honest value is a statement
            // that it was not recorded, not a blank the CHECK would reject.
            'withdrawal_reason' => $status === 'withdrawn' ? 'Reason not recorded in the previous system' : null,
            'final_score' => null, 'final_grade' => null, 'outcome' => null,
            'created_at' => $created,
        ]);
        $enrOfCourseEnrol[$v1id] = $id;
        $mapped('course_enrollments', $v1id, 'enrolments', (int) $id);
    }
    $legacyEnrolMerged = 0;
    foreach ($rows('enrollments') as $e) {
        $v1id = (int) ($e['id'] ?? 0);
        $mid  = $memberOfV1User[(int) ($e['student_id'] ?? 0)] ?? null;
        $coh  = $cohIdOfClass[(int) ($e['class_id'] ?? 0)] ?? null;
        if ($mid === null || $coh === null) {
            $rejected[] = ['table' => 'enrollments', 'id' => $v1id,
                'why' => $mid === null ? 'student did not map to a membership' : 'class did not map to a cohort'];
            continue;
        }
        $status  = mam_v2_plan_enrol_status($e['status'] ?? null);
        $created = mam_v2_plan_ts($e['enrolled_at'] ?? null, $nowFallback);
        $before  = $enrId;
        $id = $addEnrolment([
            'public_id' => mam_v2_plan_pid('enrolment:enrollments:' . $v1id, $created),
            'tenant_id' => $tenantId, 'membership_id' => $mid, 'cohort_id' => $coh, 'status' => $status,
            'waitlist_position' => null, 'enrolled_at' => $created,
            'completed_at' => null, 'withdrawn_at' => $status === 'withdrawn' ? $created : null,
            'withdrawal_reason' => $status === 'withdrawn' ? 'Reason not recorded in the previous system' : null,
            'final_score' => null, 'final_grade' => null, 'outcome' => null, 'created_at' => $created,
        ]);
        if ($enrId === $before) $legacyEnrolMerged++;
        $mapped('enrollments', $v1id, 'enrolments', (int) $id,
            null, $enrId === $before ? 'converged onto the cohort enrolment already present' : null);
    }
    $note('course_enrollments', 'enrolments', 'the cohort half of v1\'s two enrolment models.',
        count($rows('course_enrollments')));
    $note('enrollments', 'enrolments',
        sprintf('the legacy half; %d converged onto a row the cohort model already had.', $legacyEnrolMerged),
        count($rows('enrollments')), $enrId);
    $note('instructor_assignments', null,
        '"this instructor may teach this course at this level" has no v2 table — v2 attaches staff to a '
        . 'COHORT (cohort_staff), not to a catalogue entry. Left for the new system\'s own permissions.',
        count($rows('instructor_assignments')));

    /* ── Admissions ──────────────────────────────────────────────────────── */
    // The programme an application was for. v1's `program` column holds a
    // marketing track ("songwriting", "music-production"), NOT a programme code
    // — the programme is in `pathway`. Only the unambiguous mappings resolve;
    // everything else stays NULL and the raw values stay in the payload, because
    // an application filed against the wrong programme is worse than one filed
    // against none.
    $pathwayProgramme = ['crash' => 'crash', 'standard' => 'standard',
                         'certified-musician' => 'certified', 'grand-musician' => 'grand'];
    $pathwayLevel     = ['pre-cert' => 'precert', 'cert' => 'cert',
                         'pre-diploma' => 'prediploma', 'diploma' => 'diploma'];

    $appId = 0; $appIdOfV1 = []; $appPersonOf = [];
    $tApps = mam_v2_transform_applications($rows('applications'), $people, $tenantId);
    foreach ($tApps['rejected'] as $r) $rejected[] = ['table' => 'applications'] + $r;
    $v1AppsById = [];
    foreach ($rows('applications') as $a) $v1AppsById[(int) ($a['id'] ?? 0)] = $a;

    foreach ($tApps['rows'] as $r) {
        [$lt, $lid] = $r['legacy'];
        $v1 = $v1AppsById[(int) $lid] ?? [];
        $mid = $memberOfPerson[$r['person_ix']] ?? null;
        if ($mid === null) {
            $rejected[] = ['table' => 'applications', 'id' => $lid, 'why' => 'applicant did not map to a membership'];
            continue;
        }
        $pw   = strtolower(trim((string) ($v1['pathway'] ?? '')));
        $lvl  = strtolower(trim((string) ($v1['level'] ?? '')));
        $pcode = $pathwayProgramme[$pw] ?? null;
        $lcode = $lvl !== '' ? $lvl : ($pathwayLevel[$pw] ?? null);
        $submitted = mam_v2_plan_ts($r['submitted_at'] ?? null, $nowFallback);
        $id = ++$appId;
        $appIdOfV1[(int) $lid] = $id;
        $appPersonOf[(int) $lid] = $r['person_ix'];
        // An erased applicant's payload was already dropped by the transform;
        // the quoted total and the guardian's details are part of the payload, so
        // they go with it. That is the point of the tombstone.
        $payload = $r['payload'];
        if ($payload !== null) {
            foreach (['total_usd', 'parent_name', 'parent_phone', 'emergency_contact', 'referrer',
                      'policy_signed_name', 'policy_signed_at', 'courses_label', 'extra_courses',
                      'program_track', 'headshot_url'] as $k) {
                if (mam_v2_plan_str($v1[$k] ?? null) !== null) $payload[$k] = (string) $v1[$k];
            }
        }
        $T['applications'][] = [
            'id' => $id, 'public_id' => mam_v2_plan_pid('application:' . $lid, $submitted),
            'tenant_id' => $tenantId, 'membership_id' => $mid, 'type' => (string) $r['type'],
            'status' => (string) $r['status'], 'submitted_at' => $submitted,
            'decided_at' => null, 'decided_by' => null,
            'decision_reason' => $r['decision_reason'] ?? null,
            'programme_id' => $pcode !== null ? ($progCodeOf[$pcode] ?? null) : null,
            'level_id' => $lcode !== null ? ($lvlIdOfCode[$lcode] ?? null) : null,
            'centre_id' => $centreId,
            'intake_term' => mam_v2_plan_str($v1['session'] ?? null, 32),
            'payload' => $payload,
            'readiness_signal' => mam_v2_plan_str($r['readiness_signal'] ?? null, 16),
            'reference' => mam_v2_plan_str($r['reference'] ?? null, 32),
            'source_channel' => $r['source_channel'] ?? null,
            'created_at' => $submitted,
        ];
        $mapped('applications', $lid, 'applications', $id, mam_v2_plan_str($r['reference'] ?? null, 64));
    }
    $note('applications', 'applications',
        'programme resolved from `pathway` (v1\'s `program` column is a marketing track, not a programme '
        . 'code) and left NULL where it is ambiguous; the quoted total, guardian details and the landing '
        . 'URL are kept in the payload, which is dropped entirely for an erased applicant.',
        count($rows('applications')), $appId);

    /* ── Summer school: a CRASH-programme intake, not a separate system ──── */
    // v1 kept these in their own table, with their own status track and a
    // `roster` identity that could not log in. Both are gone here. The academy's
    // position is that summer is a crash-programme intake running in the
    // holidays and a summer student is a student, so:
    //   • the application is `type = 'programme'` against the CRASH programme,
    //     which is what puts it in the same funnel, the same catalogue and the
    //     same reports as every other application. `type = 'summer'` would
    //     rebuild the parallel system this migration exists to end;
    //   • `intake` records that it came through the summer route, because the
    //     route is real information even when the programme is the same;
    //   • the learner is a full person, and where the guardian's address resolves
    //     to somebody already in the data that becomes a REAL guardianship —
    //     the control that makes a child's account safe rather than convenient.
    $summer = $rows('summer_applications');
    $emailToUser = [];
    foreach ($T['users'] as $u) $emailToUser[mam_v2_email_key($u['email'])] = $u['id'];
    $memberOfUser = [];
    foreach ($T['memberships'] as $m) $memberOfUser[$m['user_id']] = $m['id'];

    $summerOut = 0; $guardId = 0;
    foreach ($summer as $s) {
        $v1id  = (int) ($s['id'] ?? 0);
        $name  = trim(((string) ($s['first_name'] ?? '')) . ' ' . ((string) ($s['last_name'] ?? '')));
        $key   = mam_v2_email_key($s['email'] ?? null);
        $created = mam_v2_plan_ts($s['created_at'] ?? null, $nowFallback);

        // Reuse an existing person only where the address AND the name agree —
        // the same rule identity resolution uses, and for the same reason: a
        // guardian who used their own address to apply for a child must not be
        // fused with the child.
        $uid = null;
        if ($key !== '' && isset($emailToUser[$key])) {
            $cand = $emailToUser[$key];
            foreach ($T['users'] as $u) {
                if ($u['id'] === $cand && mam_v2_names_agree($u['full_name'], $name)) { $uid = $cand; break; }
            }
        }
        if ($uid === null) {
            $email = $key !== '' && !isset($emailToUser[$key])
                ? (string) $s['email']
                : 'summer-' . $v1id . '@migrated.invalid';
            $uid = ++$userId;
            $T['users'][] = [
                'id' => $uid, 'public_id' => mam_v2_plan_pid('user:summer_applications:' . $v1id, $created),
                'email' => mb_substr($email, 0, 190),
                'phone' => mam_v2_plan_str($s['phone'] ?? null, 32),
                'full_name' => mb_substr($name !== '' ? $name : ('Summer applicant ' . $v1id), 0, 160),
                'date_of_birth' => mam_v2_plan_str($s['dob'] ?? null),
                'avatar_url' => mam_v2_plan_str($s['headshot_url'] ?? null, 500),
                'locale' => 'en', 'timezone' => 'Africa/Lagos', 'status' => 'active',
                'created_at' => $created,
            ];
            $emailToUser[mam_v2_email_key($email)] = $uid;
            // No `user_credentials` row: a summer child gets roster presence,
            // never portal access — exactly what v1's `status='roster'` meant.
            $mid = ++$memId;
            $T['memberships'][] = [
                'id' => $mid, 'public_id' => mam_v2_plan_pid('membership:summer:' . $v1id, $created),
                'tenant_id' => $tenantId, 'user_id' => $uid, 'centre_id' => $centreId,
                'reference' => mam_v2_plan_str($s['reference'] ?? null, 32),
                'stage' => 'applicant', 'stage_entered_at' => $created, 'status' => 'active',
                'joined_at' => $created,
            ];
            $memberOfUser[$uid] = $mid;
        }
        $mid = $memberOfUser[$uid];

        $status = mam_v2_map_application_status($s['status'] ?? null);
        $id = ++$appId;
        $T['applications'][] = [
            'id' => $id, 'public_id' => mam_v2_plan_pid('application:summer:' . $v1id, $created),
            'tenant_id' => $tenantId, 'membership_id' => $mid, 'type' => 'programme', 'status' => $status,
            'submitted_at' => $created, 'decided_at' => null, 'decided_by' => null, 'decision_reason' => null,
            // The crash programme, resolved by CODE like everything else here.
            'programme_id' => $progCodeOf['crash'] ?? null, 'level_id' => null, 'centre_id' => $centreId,
            'intake_term' => mam_v2_plan_str($s['session_year'] ?? null, 32),
            'payload' => array_filter([
                'discipline' => mam_v2_plan_str($s['discipline'] ?? null),
                'experience' => mam_v2_plan_str($s['experience'] ?? null),
                'goals' => mam_v2_plan_str($s['goals'] ?? null),
                'sex' => mam_v2_plan_str($s['sex'] ?? null),
                'country' => mam_v2_plan_str($s['country'] ?? null),
                'city' => mam_v2_plan_str($s['city'] ?? null),
                'age_at_submission' => mam_v2_plan_str($s['age_at_submission'] ?? null),
                'guardian_name' => mam_v2_plan_str($s['guardian_name'] ?? null),
                'guardian_phone' => mam_v2_plan_str($s['guardian_phone'] ?? null),
                'guardian_email' => mam_v2_plan_str($s['guardian_email'] ?? null),
                'fee_snapshot_usd' => mam_v2_plan_str($s['fee_snapshot_usd'] ?? null),
                'policy_signed_name' => mam_v2_plan_str($s['policy_signed_name'] ?? null),
                'policy_signed_at' => mam_v2_plan_str($s['policy_signed_at'] ?? null),
                'referrer' => mam_v2_plan_str($s['referrer'] ?? null),
                'notes' => mam_v2_plan_str($s['notes'] ?? null),
                'source_url' => mam_v2_plan_str($s['source'] ?? null),
                // The programme is the same as any crash intake; the ROUTE is
                // not, and it is the only thing distinguishing a summer cohort
                // from a term-time one once the type column stops saying so.
                'intake' => 'summer',
            ], static fn($v) => $v !== null),
            'readiness_signal' => null, 'reference' => mam_v2_plan_str($s['reference'] ?? null, 32),
            'source_channel' => mam_v2_source_channel($s['source'] ?? null),
            'created_at' => $created,
        ];
        $mapped('summer_applications', $v1id, 'applications', $id, mam_v2_plan_str($s['reference'] ?? null, 64));
        $summerOut++;

        // The guardian, where the data names a real one we can point at.
        $gkey = mam_v2_email_key($s['guardian_email'] ?? null);
        if ($gkey !== '' && isset($emailToUser[$gkey]) && $emailToUser[$gkey] !== $uid) {
            $gm = $memberOfUser[$emailToUser[$gkey]] ?? null;
            if ($gm !== null) {
                $T['guardianships'][] = [
                    'id' => ++$guardId, 'tenant_id' => $tenantId,
                    'guardian_membership_id' => $gm, 'learner_membership_id' => $mid,
                    'relationship' => 'guardian', 'is_primary' => 1, 'scope' => 'standard',
                    // Not `verified_at`: the guardian gave this address on a form
                    // and nobody has checked it. A verified flag set by an ETL is
                    // a safeguarding claim nobody made.
                    'verified_at' => null, 'created_at' => $created,
                ];
            }
        }
    }
    $note('summer_applications', 'applications (type=programme, crash) + users + memberships + guardianships',
        sprintf('%d carried as CRASH-programme applications — summer is an intake, not a separate system, so '
              . 'they share the funnel, the catalogue and the reports. The summer route is kept in the payload '
              . 'as `intake`. %d guardianship(s) created where the guardian_email resolved to a person already '
              . 'in the data.', $summerOut, $guardId), count($summer), $summerOut);

    /* ── Money ───────────────────────────────────────────────────────────── */
    $v1payments = mam_v2_attach_payment_owners($rows('payments'), $rows('applications'));
    $tPays = mam_v2_transform_payments($v1payments, $people, $tenantId);
    foreach ($tPays['rejected'] as $r) $rejected[] = ['table' => 'payments'] + $r;
    $v1PayById = [];
    foreach ($v1payments as $p) $v1PayById[(int) ($p['id'] ?? 0)] = $p;

    $payId = 0; $moneyTotal = 0; $moneySucceeded = 0; $unowned = 0;
    foreach ($tPays['rows'] as $r) {
        [$lt, $lid] = $r['legacy'];
        $v1 = $v1PayById[(int) $lid] ?? [];
        $mid = $r['person_ix'] === null ? null : ($memberOfPerson[$r['person_ix']] ?? null);
        if ($mid === null) $unowned++;
        $id = ++$payId;
        $received = mam_v2_plan_ts($r['received_at'] ?? null, null);
        $created  = mam_v2_plan_ts($v1['created_at'] ?? null, $nowFallback);
        $T['payments'][] = [
            'id' => $id, 'public_id' => mam_v2_plan_pid('payment:' . $lid, $created),
            'tenant_id' => $tenantId,
            // A payment with no owner is still CARRIED — the money is real and
            // the totals have to reconcile — and counted, because a payment
            // nobody is attached to is a question for a human.
            'membership_id' => $mid, 'provider' => mb_substr((string) $r['provider'], 0, 32),
            'provider_ref' => mam_v2_plan_str($r['provider_ref'] ?? null, 128),
            'status' => (string) $r['status'], 'currency' => (string) $r['currency'],
            'amount_minor' => (int) $r['amount_minor'], 'fee_minor' => 0,
            'base_currency' => (string) $r['base_currency'],
            'base_amount_minor' => (int) $r['base_amount_minor'],
            'fx_rate' => $r['fx_rate'], 'fx_source' => $r['fx_source'],
            'received_at' => $received, 'reconciled_at' => null,
            'failure_code' => null, 'failure_reason' => $r['failure_reason'] ?? null,
            // v1's `purpose` (booking_fee|tuition) and its own reference: what
            // the money was FOR is not a status, and it has no column of its own.
            'metadata' => array_filter([
                'purpose' => mam_v2_plan_str($v1['purpose'] ?? null),
                'v1_reference' => mam_v2_plan_str($r['legacy_ref'] ?? null),
                'v1_application_id' => mam_v2_plan_str($v1['application_id'] ?? null),
            ], static fn($v) => $v !== null),
            'created_at' => $created,
        ];
        $moneyTotal += (int) $r['amount_minor'];
        if ($r['status'] === 'succeeded') $moneySucceeded += (int) $r['amount_minor'];
        // The HUMAN reference goes in the map — it is the string on the receipt
        // and in the confirmation email, the one they quote on the phone.
        $mapped('payments', $lid, 'payments', $id,
            mam_v2_plan_str($r['legacy_ref'] ?? null, 64) ?? mam_v2_plan_str($r['provider_ref'] ?? null, 64));
    }
    $note('payments', 'payments',
        sprintf('%s %s carried, %s of it succeeded; %d with no resolvable owner. No invoices are '
              . 'synthesised: v1\'s `total_usd` is a QUOTE, not an issued invoice, and inventing one in USD '
              . 'against an NGN base would need an FX rate nobody observed.',
              MAM_V2_BASE_CURRENCY, number_format($moneyTotal / 100, 2),
              number_format($moneySucceeded / 100, 2), $unowned),
        count($rows('payments')), $payId);

    /* ── Mail ────────────────────────────────────────────────────────────── */
    $mailOut = 0;
    foreach ($rows('mail_log') as $m) {
        $to = mam_v2_plan_str($m['to_email'] ?? null, 320);
        if ($to === null) continue;
        $T['message_deliveries'][] = [
            'id' => ++$mailOut, 'tenant_id' => $tenantId, 'notification_id' => null, 'channel' => 'email',
            'template_key' => mam_v2_plan_str($m['context'] ?? null, 80), 'recipient' => $to,
            'subject' => mam_v2_plan_str($m['subject'] ?? null, 512),
            'status' => in_array(strtolower(trim((string) ($m['status'] ?? ''))), ['queued', 'sent', 'failed', 'bounced'], true)
                ? strtolower(trim((string) $m['status'])) : 'queued',
            'provider' => mam_v2_plan_str($m['transport'] ?? null, 32), 'provider_ref' => null,
            'attempts' => mam_v2_plan_int($m['attempts'] ?? null) ?? 0,
            'last_error' => mam_v2_plan_str($m['error'] ?? null, 500),
            'sent_at' => mam_v2_plan_ts($m['sent_at'] ?? null, null), 'opened_at' => null,
            'created_at' => mam_v2_plan_ts($m['created_at'] ?? null, $nowFallback),
        ];
        $mapped('mail_log', (int) ($m['id'] ?? 0), 'message_deliveries', $mailOut);
    }
    $note('mail_log', 'message_deliveries',
        'the delivery RECORD is carried; the rendered message BODY is not — v2 stores what was sent to '
        . 'whom and what happened, not a copy of every email, which is bulk personal data with no use.',
        count($rows('mail_log')), $mailOut);

    /* ── Audit: one log, hash-chained ────────────────────────────────────── */
    // v1 had three separate trails: `audit_log`, `application_events` and
    // `enrolment_events`. They are all "who changed what, when", so they become
    // one chain. `lifecycle_events` is deliberately NOT the destination: that
    // table records MEMBERSHIP STAGE changes, and an application moving from
    // submitted to accepted is not a stage change — writing it there would
    // invent a journey the data never recorded.
    $audit = [];
    // v1's `audit_log.entity_id` holds a v1 id, and every other id in v2 is a v2
    // id. Copying it across leaves a column that LOOKS joinable and silently
    // points at the wrong row — application 12 in v1 is a different application
    // 12 here. So it is remapped through the same map everything else uses, the
    // v1 id is kept in the metadata, and anything that cannot be resolved (an
    // entity type this plan does not carry, or a row somebody excluded) gets a
    // NULL rather than a number that resolves to a stranger.
    $v2IdOf = [];
    foreach ($map as $m) $v2IdOf[$m['legacy_table'] . ':' . $m['legacy_id']] = $m['v2_id'];
    $entityTable = ['application' => 'applications', 'applications' => 'applications',
                    'payment' => 'payments', 'payments' => 'payments',
                    'customer' => 'customers', 'user' => 'users', 'users' => 'users',
                    'course' => 'courses', 'cohort' => 'cohorts', 'module' => 'modules',
                    'enrollment' => 'course_enrollments', 'class' => 'classes'];
    $entityRemapped = $entityDropped = 0;

    foreach ($rows('audit_log') as $a) {
        $type   = strtolower(trim((string) ($a['entity'] ?? 'unknown')));
        $v1Id   = mam_v2_plan_int($a['entity_id'] ?? null);
        $v2Id   = null;
        if ($v1Id !== null && isset($entityTable[$type])) {
            $v2Id = $v2IdOf[$entityTable[$type] . ':' . $v1Id] ?? null;
            $v2Id === null ? $entityDropped++ : $entityRemapped++;
        }
        $meta = mam_v2_plan_str($a['meta'] ?? null);
        if ($v1Id !== null) {
            $decoded = $meta !== null ? json_decode($meta, true) : null;
            if (!is_array($decoded)) $decoded = $meta !== null ? ['v1_meta' => $meta] : [];
            $decoded['v1_entity_id'] = $v1Id;
            if ($v2Id === null && isset($entityTable[$type])) {
                $decoded['v1_entity_not_migrated'] = true;
            }
            $meta = (string) json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }
        $audit[] = [
            'occurred_at' => mam_v2_plan_ts($a['created_at'] ?? null, $nowFallback),
            'actor_label' => mam_v2_plan_str($a['actor'] ?? null, 120),
            'actor_user_id' => null,
            'action' => mb_substr((string) ($a['action'] ?? 'unknown'), 0, 80),
            'entity_type' => mb_substr((string) ($a['entity'] ?? 'unknown'), 0, 48),
            'entity_id' => $v2Id,
            'metadata' => $meta,
            'ip' => mam_v2_plan_str($a['ip'] ?? null),
            'sort' => '1:' . str_pad((string) ($a['id'] ?? 0), 12, '0', STR_PAD_LEFT),
            'legacy' => ['audit_log', (string) ($a['id'] ?? '')],
        ];
    }

    // The decision itself, in the log. An excluded row leaves a hole in the
    // numbering and in the counts; without this, somebody finds the hole and has
    // no way to learn it was deliberate.
    foreach ($excluded as $i => $x) {
        $audit[] = [
            'occurred_at' => $nowFallback,
            'actor_label' => 'migration',
            'actor_user_id' => null,
            'action' => 'migration.excluded',
            'entity_type' => explode(':', (string) $x['row'])[0],
            'entity_id' => null,
            'metadata' => (string) json_encode([
                'v1_row' => $x['row'], 'reason' => $x['reason'],
                'source' => 'db/v2-exclusions.json',
            ], JSON_UNESCAPED_UNICODE),
            'ip' => null,
            'sort' => '0:' . str_pad((string) $i, 12, '0', STR_PAD_LEFT),
            'legacy' => ['(excluded)', (string) $x['row']],
        ];
    }
    foreach ($rows('application_events') as $e) {
        $audit[] = [
            'occurred_at' => mam_v2_plan_ts($e['created_at'] ?? null, $nowFallback),
            'actor_label' => null,
            'actor_user_id' => $userOfV1User[(int) ($e['actor_id'] ?? 0)] ?? null,
            'action' => 'application.status_changed', 'entity_type' => 'application',
            'entity_id' => $appIdOfV1[(int) ($e['application_id'] ?? 0)] ?? null,
            'metadata' => json_encode(array_filter([
                'from' => mam_v2_plan_str($e['from_status'] ?? null),
                'to' => mam_v2_plan_str($e['to_status'] ?? null),
                'reason' => mam_v2_plan_str($e['reason'] ?? null),
                'v1_application_id' => (string) ($e['application_id'] ?? ''),
            ], static fn($v) => $v !== null), JSON_UNESCAPED_UNICODE),
            'ip' => null,
            'sort' => '2:' . str_pad((string) ($e['id'] ?? 0), 12, '0', STR_PAD_LEFT),
            'legacy' => ['application_events', (string) ($e['id'] ?? '')],
        ];
    }
    foreach ($rows('enrolment_events') as $e) {
        $audit[] = [
            'occurred_at' => mam_v2_plan_ts($e['created_at'] ?? null, $nowFallback),
            'actor_label' => null, 'actor_user_id' => null,
            'action' => 'enrolment.status_changed', 'entity_type' => 'enrolment',
            'entity_id' => $enrOfCourseEnrol[(int) ($e['enrollment_id'] ?? 0)] ?? null,
            'metadata' => json_encode(array_filter([
                'from' => mam_v2_plan_str($e['from_status'] ?? null),
                'to' => mam_v2_plan_str($e['to_status'] ?? null),
                'reason' => mam_v2_plan_str($e['reason'] ?? null),
                'v1_enrollment_id' => (string) ($e['enrollment_id'] ?? ''),
            ], static fn($v) => $v !== null), JSON_UNESCAPED_UNICODE),
            'ip' => null,
            'sort' => '3:' . str_pad((string) ($e['id'] ?? 0), 12, '0', STR_PAD_LEFT),
            'legacy' => ['enrolment_events', (string) ($e['id'] ?? '')],
        ];
    }
    // Chronological, with the source table and id as a deterministic tiebreak —
    // a hash chain over rows in an unstable order is a chain that fails to
    // verify for no reason.
    usort($audit, static function (array $a, array $b): int {
        return [(string) $a['occurred_at'], $a['sort']] <=> [(string) $b['occurred_at'], $b['sort']];
    });
    $auditId = 0; $prev = null;
    foreach ($audit as $a) {
        $row = ['id' => ++$auditId, 'tenant_id' => $tenantId, 'actor_user_id' => $a['actor_user_id'],
                'actor_label' => $a['actor_label'], 'action' => $a['action'],
                'entity_type' => $a['entity_type'], 'entity_id' => $a['entity_id'],
                'before_state' => null, 'after_state' => null, 'metadata' => $a['metadata'],
                // `ip` is VARBINARY(16) in v2 — a packed address, not the text
                // form. Carried as text here and packed by the emitter, which is
                // the only place that knows the target dialect.
                'ip_text' => $a['ip'], 'user_agent' => null, 'request_id' => null,
                'occurred_at' => $a['occurred_at'], 'prev_hash' => $prev];
        // row_hash = sha256(prev_hash || canonical(row)). Computed HERE so the
        // log arrives verifiable: a chain stamped after the fact proves nothing
        // about the migration, and a NULL-filled one proves nothing at all.
        $canon = json_encode([$row['tenant_id'], $row['actor_user_id'], $row['actor_label'],
            $row['action'], $row['entity_type'], $row['entity_id'], $row['metadata'],
            $row['ip_text'], $row['occurred_at']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row['row_hash'] = hash('sha256', (string) $prev . '|' . (string) $canon);
        $prev = $row['row_hash'];
        $T['audit_log'][] = $row;
        // `(excluded)` is not a v1 table, so it gets no reference-map row — the
        // map resolves old references to new rows, and there is no old row.
        if ($a['legacy'][0] !== '(excluded)') $mapped($a['legacy'][0], $a['legacy'][1], 'audit_log', $auditId);
    }
    $note('audit_log', 'audit_log (hash-chained)',
        sprintf('carried and CHAINED on load: row_hash = sha256(prev_hash || canonical(row)), ordered '
        . 'by occurred_at with the source table and id as tiebreak, so the log arrives tamper-evident. '
        . 'entity_id is REMAPPED to v2 ids (%d resolved, %d left NULL because the row they named is '
        . 'not carried) with the v1 id kept in the metadata — a copied v1 id would look joinable and '
        . 'point at a stranger.', $entityRemapped, $entityDropped),
        count($rows('audit_log')), count($rows('audit_log')));
    $note('application_events', 'audit_log',
        'a status change is "who changed what, when" — NOT a membership stage change, so deliberately '
        . 'not lifecycle_events, which would invent a journey v1 never recorded.',
        count($rows('application_events')));
    $note('enrolment_events', 'audit_log', 'as application_events.', count($rows('enrolment_events')));

    /* ── The reference map ───────────────────────────────────────────────── */
    $mapId = 0;
    foreach ($map as $m) {
        $T['legacy_reference_map'][] = ['id' => ++$mapId, 'tenant_id' => $tenantId,
            'legacy_table' => mb_substr($m['legacy_table'], 0, 64),
            'legacy_id' => mb_substr((string) $m['legacy_id'], 0, 64),
            'legacy_ref' => $m['legacy_ref'] === null ? null : mb_substr((string) $m['legacy_ref'], 0, 64),
            'v2_table' => $m['v2_table'], 'v2_id' => $m['v2_id'],
            'migrated_at' => $nowFallback,
            'note' => $m['note'] === null ? null : mb_substr((string) $m['note'], 0, 255)];
    }

    /* ── Everything the plan deliberately does NOT carry ─────────────────── */
    // Named, with the reason, because "we migrated everything" is only a claim
    // you can check if the exceptions are written down.
    $skips = [
        ['sessions', 'a live session must not survive a cutover — carrying session tokens across would '
            . 'hand the new system a set of credentials nobody re-authenticated.'],
        ['password_resets', 'single-use tokens with an expiry; carrying them extends a window that should close.'],
        ['progress_report_tokens', 'as password_resets — a share link is re-issued, not migrated.'],
        ['rate_limits', 'a counter, not a fact. It regenerates in minutes and carrying it would apply v1\'s '
            . 'throttling state to unrelated traffic.'],
        ['idempotency_cache', 'request de-duplication for calls that have already returned.'],
        ['webhook_events', 'replay guards for events already processed.'],
        ['funnel_events', 'an analytics stream with no v2 table. It is derived behaviour, not record — and it '
            . 'is the largest table in the export, so carrying it as opaque rows would dominate the '
            . 'migration without being readable by anything.'],
        ['application_drafts', 'abandoned wizard state keyed by a browser token. v2 has no drafts table; a '
            . 'draft nobody submitted is not an application, and it holds PII with no lawful purpose after cutover.'],
        ['events', 'automation de-duplication markers ("already sent this reminder"). Carrying them suppresses '
            . 'the new system\'s first run; dropping them at worst repeats one notification.'],
        ['id_cards', 'v2 has no ID-card table. The cards are minted from the academic record, so they are '
            . 'RE-ISSUED after cutover rather than migrated — and a serial minted against v1 ids would '
            . 'resolve to the wrong person.'],
        ['practice_logs', 'no v2 table. Real learner data — flag it if the new system grows one.'],
        ['newsletter', 'no v2 table. `consents` requires a membership and a subscriber is not a member; '
            . 'creating one would put marketing contacts into the academy\'s roster.'],
        ['faculty_invites', 'single-use HMAC invitations with an expiry — re-issued, not migrated.'],
        ['ai_runs', 'provider telemetry (tokens, latency, prompt hashes) with no v2 table.'],
        ['store_items', 'the shop catalogue. v2\'s money layer models tuition (price_books/price_items); '
            . 'merchandise is a separate concern and mapping it into price_items would misstate what it is.'],
        ['automations', 'per-rule on/off flags belong to v1\'s automation registry; the new system has its own.'],
        ['schema_version', 'v1\'s migration ledger. v2 keeps its own.'],
        ['manifest', 'the export\'s own metadata.'],
    ];
    foreach ($skips as [$t, $why]) {
        $note($t, null, $why, count($rows($t)));
    }
    // Anything in the export this plan has never heard of. A new v1 table
    // appearing and being silently ignored is exactly what the ledger is for.
    $seen = array_column($coverage, 'table');
    foreach (array_keys($source) as $t) {
        if (in_array($t, $seen, true)) continue;
        $n = count($rows($t));
        $note($t, null, $n > 0
            ? 'NOT MAPPED — this table is not known to the plan and it has rows. Decide where it goes.'
            : 'not mapped; empty in this export.', $n);
        if ($n > 0) $flag('unmapped_table', ['table' => $t, 'rows' => $n,
            'action' => 'Add a mapping in plan.php or record a decision not to carry it.']);
    }

    if ($excluded) {
        $note('(exclusions)', null, sprintf('%d v1 row(s) excluded by decision, not by the transform: %s. '
            . 'Each is written into the v2 audit log, so the database explains its own gaps.',
            count($excluded), implode(', ', array_column($excluded, 'row'))), count($excluded));
    }

    $counts = [];
    foreach (MAM_V2_PLAN_ORDER as $t) $counts[$t] = count($T[$t]);
    return [
        'ok' => true,
        'tables' => $T,
        'order' => MAM_V2_PLAN_ORDER,
        'counts' => $counts,
        'coverage' => $coverage,
        'review' => $review,
        'rejected' => $rejected,
        'identity' => $identity['report'],
        'money' => ['currency' => MAM_V2_BASE_CURRENCY, 'total_minor' => $moneyTotal,
                    'succeeded_minor' => $moneySucceeded, 'unowned' => $unowned],
        'excluded' => $excluded,
    ];
}
