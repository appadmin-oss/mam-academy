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
    'academic_terms', 'term_refund_schedule', 'term_census', 'term_closures',
    'termly_reports', 'termly_report_criteria', 'report_moderations', 'mark_appeals',
    'guardian_threads', 'guardian_thread_participants', 'guardian_thread_messages',
    'guardian_channel_prefs', 'makeup_credits',
    'rubrics', 'rubric_criteria',
    'assessment_kinds', 'assignment_groups', 'assignments',
    'submissions', 'attachments', 'submission_markers',
    'assessments', 'assessment_marks',
    'teachbacks', 'enrolment_results', 'certificates', 'module_proposals', 'tool_scores',
    'applications', 'payments',
    'invoice_aid_awards', 'invoices', 'invoice_lines', 'invoice_credits',
    'invoice_payment_plans', 'invoice_plan_instalments', 'bursary_applications',
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

    $modId = 0; $modIdOf = [];
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
        $modIdOf[(int) ($m['id'] ?? 0)] = $id;
        $mapped('modules', (int) ($m['id'] ?? 0), 'modules', $id);
    }
    $note('modules', 'modules (+ custom field module_kind)',
        'content → objectives; kind has no v2 column and is carried as a custom field.',
        count($rows('modules')), $modId);

    // ⚠️ v1's `assessment_types` is NOT v2's competency vocabulary, however
    // closely the two tables rhyme. Its codes are `assignment|final|continuous|
    // practical|attendance` — KINDS of assessment, weighted for the gradebook.
    // v2's competencies are the ELEVEN mastery components (`rhythm_timing`,
    // `theory`, …) which live in mastery.php's registry and are what a report
    // criterion, a rubric criterion and the mastery score all key on.
    //
    // Seeding `competencies` from assessment_types was the inherited mapping,
    // and it silently destroyed the reports: every `termly_report_criteria` row
    // carries a mastery component code, so with the wrong vocabulary loaded
    // every one of them was rejected as "a code with no competency" and the
    // families' reports arrived with no marks on them at all.
    //
    // The registry is mirrored here rather than required from mastery.php, which
    // pulls in the whole application; `test_v2_plan.php` asserts the two agree,
    // so a component added there fails the suite instead of drifting.
    $masteryComponents = [
        'preparation'          => ['Preparation',          10],
        'teachback'            => ['Teach-back',           10],
        'technical_competence' => ['Technical competence',  15],
        'musical_accuracy'     => ['Musical accuracy',      10],
        'rhythm_timing'        => ['Rhythm & timing',       10],
        'theory'               => ['Theory',                 8],
        'expression'           => ['Expression',             8],
        'repertoire'           => ['Repertoire',             8],
        'creativity'           => ['Creativity',             6],
        'performance'          => ['Performance',           10],
        'consistency'          => ['Consistency',            5],
    ];
    $compId = 0; $compIdOfCode = [];
    foreach ($masteryComponents as $code => [$name, $weight]) {
        $T['competencies'][] = ['id' => ++$compId, 'tenant_id' => $tenantId, 'code' => $code,
            'name' => $name, 'description' => null,
            'weight_pct' => number_format((float) $weight, 2, '.', ''),
            'sort_order' => $compId, 'is_active' => 1];
        $compIdOfCode[$code] = $compId;
    }
    $note('(none)', 'competencies',
        'the ELEVEN mastery components, seeded from the registry in mastery.php. They are not migrated from '
        . 'anything: v1 held them as a code constant, which is why nothing in v1 could join a mark to one.',
        0, $compId);

    // v1's assessment_types ARE v2's assessment kinds — a code, a name and a
    // share of the gradebook — so they land there, keeping their weights.
    $kindId = 0; $kindIdOfCode = []; $kindIdOfV1 = [];
    $ensureKind = function (string $code, string $name, int $mastery = 1, float $weight = 0.0)
        use (&$T, &$kindId, &$kindIdOfCode, $tenantId): int {
        if (isset($kindIdOfCode[$code])) return $kindIdOfCode[$code];
        $id = ++$kindId;
        $kindIdOfCode[$code] = $id;
        $T['assessment_kinds'][] = ['id' => $id, 'tenant_id' => $tenantId, 'code' => $code,
            'name' => $name, 'description' => null,
            'weight_pct' => number_format($weight, 2, '.', ''),
            'counts_to_mastery' => $mastery, 'sort_order' => $id, 'is_active' => 1];
        return $id;
    };
    foreach ($rows('assessment_types') as $a) {
        $code = mam_v2_plan_str($a['code'] ?? null, 48);
        if ($code === null) continue;
        $id = $ensureKind($code, mb_substr((string) ($a['name'] ?? $code), 0, 120),
            // `attendance` is weighted in v1's gradebook, but §24 keeps
            // attendance and punctuality BESIDE the mastery score and never
            // inside it — so it is carried as a kind that does not count.
            $code === 'attendance' ? 0 : 1,
            (float) ($a['weight'] ?? 0));
        $kindIdOfV1[(int) ($a['id'] ?? 0)] = $id;
        $mapped('assessment_types', (int) ($a['id'] ?? 0), 'assessment_kinds', $id, $code);
    }
    // The kinds the migrated rows themselves need, whether or not v1 named them.
    $ensureKind('assignment', 'Assignment');
    $ensureKind('teachback', 'Teach-back');
    $note('assessment_types', 'assessment_kinds',
        'v1\'s gradebook weightings are KINDS of assessment (assignment, final, practical), not the mastery '
        . 'components — mapping them to `competencies` would load the wrong vocabulary and reject every '
        . 'report criterion. `attendance` is carried as a kind that does NOT count to mastery: §24 keeps '
        . 'attendance beside the score, never inside it.',
        count($rows('assessment_types')), $kindId);

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

    /* ── Rubrics, and why competency_id stays NULL ───────────────────────── */
    // v1's rubric is scoped to a COHORT; v2's is scoped to a course + level,
    // because a rubric that only exists for one class cannot be reused and
    // cannot be versioned. The course is derived from the cohort.
    $rubId = 0; $rubIdOf = []; $rubVersion = [];
    foreach ($rows('rubrics') as $r) {
        $v1id = (int) ($r['id'] ?? 0);
        $title = mam_v2_plan_str($r['title'] ?? null, 160) ?? 'Untitled rubric';
        // uq_rubrics_version(tenant_id, title, version, deleted_seq): two v1
        // rubrics may share a title, and in v2 that is what a version IS.
        $ver = ($rubVersion[$title] = ($rubVersion[$title] ?? 0) + 1);
        $coh = $cohIdOfV1[(int) ($r['cohort_id'] ?? 0)] ?? null;
        $created = mam_v2_plan_ts($r['created_at'] ?? null, $nowFallback);
        $active  = mam_v2_plan_bool($r['active'] ?? null, 1);
        $id = ++$rubId;
        $T['rubrics'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('rubric:' . $v1id, $created),
            'tenant_id' => $tenantId,
            'course_id' => $coh !== null ? ($cohCourseOf[$coh] ?? null) : null,
            'level_id' => null, 'title' => $title, 'version' => $ver,
            'is_published' => $active,
            // A rubric that has been used to score work IS published. v1 kept no
            // publication date, so the creation time is the honest floor rather
            // than a guessed one.
            'published_at' => $active ? $created : null,
            'created_at' => $created,
            'created_by' => $userOfV1User[(int) ($r['created_by'] ?? 0)] ?? null,
            'deleted_seq' => 0];
        $rubIdOf[$v1id] = $id;
        $mapped('rubrics', $v1id, 'rubrics', $id);
    }
    $note('rubrics', 'rubrics', 'cohort scope → the cohort\'s course; a shared title becomes a version.',
        count($rows('rubrics')), $rubId);

    $critId = 0; $critIdOf = []; $critUnmapped = 0;
    foreach ($rows('rubric_criteria') as $c) {
        $rub = $rubIdOf[(int) ($c['rubric_id'] ?? 0)] ?? null;
        if ($rub === null) { $rejected[] = ['table' => 'rubric_criteria', 'id' => $c['id'] ?? null, 'why' => 'rubric did not map']; continue; }
        $id = ++$critId;
        $T['rubric_criteria'][] = ['id' => $id, 'rubric_id' => $rub,
            // NULL, and this is the point of the migration rather than a gap in
            // it. v2 joins a criterion to a competency by FOREIGN KEY; v1 joined
            // it by matching the LABEL at read time (mam_mastery_component_for_criterion).
            // Guessing the link here would bake v1's keyword matching into v2's
            // data, and a wrong guess is invisible — it just weights the wrong
            // competency for ever. A human maps these once, after the load.
            'competency_id' => null,
            'label' => mam_v2_plan_str($c['label'] ?? null, 190) ?? 'Criterion',
            'descriptor' => null,
            'max_points' => mam_v2_plan_int($c['max_points'] ?? null) ?? 10,
            'weight' => number_format((float) ($c['weight'] ?? 1), 3, '.', ''),
            'sort_order' => mam_v2_plan_int($c['sort_order'] ?? null) ?? 0];
        $critIdOf[(int) ($c['id'] ?? 0)] = $id;
        $critUnmapped++;
        $mapped('rubric_criteria', (int) ($c['id'] ?? 0), 'rubric_criteria', $id);
    }
    $note('rubric_criteria', 'rubric_criteria',
        'competency_id is left NULL on every row: v1 linked a criterion to a mastery component by matching '
        . 'its LABEL at read time, and writing a guessed foreign key would make that guess permanent.',
        count($rows('rubric_criteria')), $critId);
    if ($critUnmapped > 0) $flag('criteria_need_competency', ['rows' => $critUnmapped,
        'action' => 'Map each rubric criterion to a competency after the load. Until then the mastery '
            . 'score has no evidence from rubric-scored work.']);

    /* ── Set work, handed-in work, and the marks awarded on it ───────────── */
    // Every row here has to resolve to an ENROLMENT, because that is what v2
    // keys academic work on. v1 keyed it on a student, so where a student has
    // more than one enrolment and the row names no class, the row is REJECTED
    // rather than attached to whichever enrolment came first.
    $enrolsOfMember = [];
    foreach ($enrSeen as $k => $eid) {
        [$c, $m] = explode(':', (string) $k);
        $enrolsOfMember[(int) $m][(int) $c] = $eid;
    }
    $enrolFor = function (?int $v1UserId, ?int $v1ClassId) use ($memberOfV1User, $cohIdOfClass, $enrolsOfMember): array {
        $mid = $memberOfV1User[(int) $v1UserId] ?? null;
        if ($mid === null) return [null, 'student did not map to a membership'];
        $mine = $enrolsOfMember[$mid] ?? [];
        if (!$mine) return [null, 'student has no enrolment in v2 to attach the work to'];
        $coh = $v1ClassId ? ($cohIdOfClass[(int) $v1ClassId] ?? null) : null;
        if ($coh !== null) {
            return isset($mine[$coh]) ? [$mine[$coh], null]
                : [null, 'student is not enrolled in the cohort the class became'];
        }
        if (count($mine) === 1) return [(int) reset($mine), null];
        // Nothing is invented: attaching a mark to the wrong course is worse
        // than leaving it for a human, and the row is named in `rejected`.
        return [null, 'student has ' . count($mine) . ' enrolments and the row names no class'];
    };

    $agId = 0; $agIdOf = [];
    foreach ($rows('assignment_groups') as $g) {
        $v1id = (int) ($g['id'] ?? 0);
        $coh  = $cohIdOfClass[(int) ($g['class_id'] ?? 0)] ?? ($cohIdOfV1[(int) ($g['cohort_id'] ?? 0)] ?? null);
        $created = mam_v2_plan_ts($g['created_at'] ?? null, $nowFallback);
        $id = ++$agId;
        $T['assignment_groups'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('asgroup:' . $v1id, $created),
            'tenant_id' => $tenantId, 'cohort_id' => $coh,
            'course_id' => $coh !== null ? ($cohCourseOf[$coh] ?? null) : null,
            'title' => mam_v2_plan_str($g['title'] ?? null, 190) ?? 'Set work',
            'description' => mam_v2_plan_str($g['description'] ?? null),
            'sort_order' => mam_v2_plan_int($g['sort_order'] ?? null) ?? 0,
            'created_at' => $created,
            'created_by' => $userOfV1User[(int) ($g['created_by'] ?? ($g['author_id'] ?? 0))] ?? null,
            'deleted_seq' => 0];
        $agIdOf[$v1id] = $id;
        $mapped('assignment_groups', $v1id, 'assignment_groups', $id);
    }
    $note('assignment_groups', 'assignment_groups', 'a named block of set work.',
        count($rows('assignment_groups')), $agId);

    $asgId = 0; $asgIdOf = []; $asgEnrolOf = [];
    foreach ($rows('assignments') as $a) {
        $v1id = (int) ($a['id'] ?? 0);
        [$enr, $why] = $enrolFor(mam_v2_plan_int($a['student_id'] ?? null), mam_v2_plan_int($a['class_id'] ?? null));
        if ($enr === null) { $rejected[] = ['table' => 'assignments', 'id' => $v1id, 'why' => $why]; continue; }
        $created = mam_v2_plan_ts($a['created_at'] ?? null, $nowFallback);
        $v1status = strtolower((string) ($a['status'] ?? 'open'));
        $status = match ($v1status) {
            'draft' => 'draft',
            'completed', 'done', 'closed' => 'closed',
            'archived' => 'archived',
            default => 'open',
        };
        // v1 held a DATE. A task was late only once that day had ENDED, so the
        // end of the day in the academy's own zone is the faithful reading —
        // midnight would move every historic deadline a day earlier.
        $dueOn = mam_v2_plan_str($a['due_on'] ?? null, 10);
        $id = ++$asgId;
        $T['assignments'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('assignment:' . $v1id, $created),
            'tenant_id' => $tenantId, 'group_id' => $agIdOf[(int) ($a['group_id'] ?? 0)] ?? null,
            // v1's assignments are per-STUDENT even when a whole class was set
            // the same work. They stay per-student: converging them would be a
            // guess that these nine rows were once one, and chk_assign_target
            // admits exactly one target either way.
            'cohort_id' => null, 'enrolment_id' => $enr,
            'module_id' => null, 'assessment_kind_id' => $kindIdOfCode['assignment'],
            'rubric_id' => null,
            'title' => mam_v2_plan_str($a['title'] ?? null, 190) ?? 'Set work',
            'instructions' => mam_v2_plan_str($a['instructions'] ?? null),
            'reference_url' => mam_v2_plan_str($a['reference_url'] ?? null, 1000),
            'reference_kind' => mam_v2_plan_str($a['reference_kind'] ?? null, 24),
            'points_max' => null,
            'due_at' => $dueOn !== null ? ($dueOn . ' 23:59:59.999') : null,
            'due_timezone' => $dueOn !== null ? 'Africa/Lagos' : null,
            'status' => $status, 'allows_resubmission' => 1,
            'created_at' => $created,
            'created_by' => $userOfV1User[(int) ($a['author_id'] ?? 0)] ?? null,
            'deleted_seq' => 0];
        $asgIdOf[$v1id] = $id;
        $asgEnrolOf[$v1id] = $enr;
        $mapped('assignments', $v1id, 'assignments', $id);
    }
    $note('assignments', 'assignments',
        'v1 set work per STUDENT (with an optional class), so each row keeps its own learner as its target. '
        . 'due_on is a DATE and becomes the END of that day in Africa/Lagos — midnight would move every '
        . 'historic deadline a day earlier.',
        count($rows('assignments')), $asgId);

    $attId = 0;
    $addAttachment = function (string $entity, ?int $entityId, ?string $url, ?string $kind,
                               ?string $created, ?int $uploadedBy = null, ?string $seed = null) use (&$T, &$attId, $tenantId): ?int {
        if ($url === null || $url === '') return null;
        $id = ++$attId;
        $T['attachments'][] = ['id' => $id,
            'public_id' => mam_v2_plan_pid('attachment:' . ($seed ?? ($entity . ':' . (string) $entityId . ':' . $url)), $created),
            'tenant_id' => $tenantId, 'entity_type' => $entity, 'entity_id' => $entityId,
            'kind' => in_array($kind, ['recording', 'take', 'document', 'score', 'headshot'], true) ? $kind : 'document',
            // The bytes are wherever v1 put them. `external` states that this
            // row is a POINTER and the file was never in the academy's store —
            // calling it 'local' would promise a file the loader does not have.
            'storage' => 'external', 'storage_key' => mb_substr($url, 0, 500),
            'mime_type' => null, 'byte_size' => null, 'checksum' => null,
            'visibility' => 'private', 'uploaded_by' => $uploadedBy, 'created_at' => $created];
        return $id;
    };

    $subId = 0; $subIdOf = [];
    foreach ($rows('submissions') as $sm) {
        $v1id = (int) ($sm['id'] ?? 0);
        $asg  = $asgIdOf[(int) ($sm['assignment_id'] ?? 0)] ?? null;
        $enr  = $asgEnrolOf[(int) ($sm['assignment_id'] ?? 0)] ?? null;
        if ($enr === null) {
            [$enr, $why] = $enrolFor(mam_v2_plan_int($sm['student_id'] ?? null), null);
            if ($enr === null) { $rejected[] = ['table' => 'submissions', 'id' => $v1id, 'why' => $why]; continue; }
        }
        $created = mam_v2_plan_ts($sm['created_at'] ?? null, $nowFallback);
        $v1status = strtolower((string) ($sm['status'] ?? 'submitted'));
        $status = match ($v1status) {
            'draft' => 'draft',
            'graded', 'marked', 'reviewed' => 'marked',
            'returned' => 'returned',
            'withdrawn' => 'withdrawn',
            default => 'submitted',
        };
        $submitted = mam_v2_plan_ts($sm['submitted_at'] ?? null, $status === 'draft' ? null : $created);
        $id = ++$subId;
        $T['submissions'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('submission:' . $v1id, $created),
            'tenant_id' => $tenantId, 'enrolment_id' => $enr, 'assignment_id' => $asg,
            'module_id' => null, 'session_id' => null, 'attempt_no' => 1,
            'kind' => mam_v2_plan_str($sm['artifact_kind'] ?? null, 24) ?? 'link',
            'body' => mam_v2_plan_str($sm['note'] ?? null),
            'status' => $status, 'submitted_at' => $submitted, 'is_late' => 0,
            'created_at' => $created, 'deleted_seq' => 0];
        $subIdOf[$v1id] = $id;
        // v1 kept ONE artifact on the submission row and any others in
        // `submission_files`. Both become attachments, so a recording, a
        // photographed score and a PDF are one mechanism.
        $addAttachment('submission', $id, mam_v2_plan_str($sm['artifact_url'] ?? null, 1000),
            mam_v2_plan_str($sm['artifact_kind'] ?? null, 24), $created, null, 'submission-artifact:' . $v1id);
        $mapped('submissions', $v1id, 'submissions', $id);
    }
    $note('submissions', 'submissions (+ attachments)',
        'attempt_no is 1 on every migrated row: v1 overwrote a resubmission in place, so the earlier '
        . 'attempts it replaced are not in the export and cannot be reconstructed.',
        count($rows('submissions')), $subId);

    $sfOut = 0;
    foreach ($rows('submission_files') as $f) {
        $sub = $subIdOf[(int) ($f['submission_id'] ?? 0)] ?? null;
        if ($sub === null) { $rejected[] = ['table' => 'submission_files', 'id' => $f['id'] ?? null, 'why' => 'submission did not map']; continue; }
        $aid = $addAttachment('submission', $sub, mam_v2_plan_str($f['url'] ?? null, 1000),
            mam_v2_plan_str($f['kind'] ?? null, 24), mam_v2_plan_ts($f['created_at'] ?? null, $nowFallback),
            null, 'submission-file:' . (string) ($f['id'] ?? ''));
        if ($aid !== null) { $sfOut++; $mapped('submission_files', (int) ($f['id'] ?? 0), 'attachments', $aid); }
    }
    $note('submission_files', 'attachments',
        'polymorphic, so takes, recordings and photographed scores are one mechanism. `storage=external`: '
        . 'the row is a pointer and the bytes were never in the academy\'s own store.',
        count($rows('submission_files')), $sfOut);
    $note('resource_versions', null,
        'v1 versioned a class resource in its own table; v2 carries version provenance on `attachments`. '
        . 'Left unmapped until class resources are carried over (batch 3).',
        count($rows('resource_versions')));

    $smkOut = 0;
    foreach ($rows('submission_markers') as $mk) {
        $sub = $subIdOf[(int) ($mk['submission_id'] ?? 0)] ?? null;
        $mid = $memberOfV1User[(int) ($mk['marker_id'] ?? ($mk['user_id'] ?? 0))] ?? null;
        if ($sub === null || $mid === null) {
            $rejected[] = ['table' => 'submission_markers', 'id' => $mk['id'] ?? null,
                'why' => $sub === null ? 'submission did not map' : 'marker did not map to a membership'];
            continue;
        }
        $role = strtolower((string) ($mk['role'] ?? 'primary'));
        $T['submission_markers'][] = ['tenant_id' => $tenantId, 'submission_id' => $sub,
            'marker_membership_id' => $mid,
            'role' => in_array($role, ['primary', 'moderator', 'second'], true) ? $role : 'primary',
            'allocated_at' => mam_v2_plan_ts($mk['created_at'] ?? null, $nowFallback),
            'allocated_by' => null,
            'completed_at' => mam_v2_plan_ts($mk['completed_at'] ?? null, null)];
        $smkOut++;
    }
    $note('submission_markers', 'submission_markers', 'who was allocated to mark, which is a different fact '
        . 'from what they marked (the moderation share the report layer requires is counted from it).',
        count($rows('submission_markers')), $smkOut);

    /* ── The marks themselves ────────────────────────────────────────────── */
    $assId = 0; $markId = 0; $marksFromJson = 0;
    foreach ($rows('submission_reviews') as $rv) {
        $v1id = (int) ($rv['id'] ?? 0);
        $sub  = $subIdOf[(int) ($rv['submission_id'] ?? 0)] ?? null;
        if ($sub === null) { $rejected[] = ['table' => 'submission_reviews', 'id' => $v1id, 'why' => 'submission did not map']; continue; }
        $enr = null;
        foreach ($T['submissions'] as $srow) if ((int) $srow['id'] === $sub) { $enr = (int) $srow['enrolment_id']; break; }
        $created = mam_v2_plan_ts($rv['created_at'] ?? null, $nowFallback);
        $marker  = $memberOfV1User[(int) ($rv['reviewer_id'] ?? 0)] ?? null;
        $pts = $rv['total_points'] ?? null; $max = $rv['max_points'] ?? null;
        $hasPts = is_numeric($pts) && is_numeric($max) && (float) $max > 0;
        // chk_assess_marked: a MARKED assessment names its marker, states when,
        // and carries an award. A review missing any of the three is carried as
        // a draft rather than dropped — the feedback is still the academy's
        // record of what was said.
        $status = ($marker !== null && $hasPts) ? 'marked' : 'draft';
        $id = ++$assId;
        $T['assessments'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('assessment:review:' . $v1id, $created),
            'tenant_id' => $tenantId, 'enrolment_id' => $enr,
            'assessment_kind_id' => $kindIdOfCode['assignment'],
            'submission_id' => $sub, 'session_id' => null, 'module_id' => null, 'title' => null,
            'rubric_id' => $rubIdOf[(int) ($rv['rubric_id'] ?? 0)] ?? null,
            // v1 had no rubric versioning, so every migrated mark was awarded
            // against version 1 by construction.
            'rubric_version' => isset($rubIdOf[(int) ($rv['rubric_id'] ?? 0)]) ? 1 : null,
            'points_awarded' => $hasPts ? number_format((float) $pts, 2, '.', '') : null,
            'points_max' => $hasPts ? number_format((float) $max, 2, '.', '') : null,
            'pct' => $hasPts ? number_format(((float) $pts / (float) $max) * 100, 2, '.', '') : null,
            'feedback' => mam_v2_plan_str($rv['feedback'] ?? null),
            'status' => $status,
            'marker_membership_id' => $status === 'marked' ? $marker : null,
            'marked_at' => $status === 'marked' ? $created : null,
            'moderated_by' => null, 'moderated_at' => null,
            // v1 had no release step: a review was visible the moment it was
            // written. Every migrated mark is therefore released, and saying so
            // is more honest than importing them as unreleased and hiding marks
            // families have already seen.
            'released_at' => $status === 'marked' ? $created : null,
            'created_at' => $created, 'deleted_seq' => 0];
        if ($status === 'marked') $T['assessments'][count($T['assessments']) - 1]['status'] = 'released';
        $mapped('submission_reviews', $v1id, 'assessments', $id);

        // scores_json is {criterion_id: points}. This is the blob becoming rows.
        $scores = json_decode((string) ($rv['scores_json'] ?? ''), true);
        if (is_array($scores)) {
            $seenLabel = [];
            foreach ($scores as $critV1 => $points) {
                $crit = $critIdOf[(int) $critV1] ?? null;
                $label = null; $critMax = null;
                foreach ($T['rubric_criteria'] as $cr) if ((int) $cr['id'] === (int) $crit) { $label = $cr['label']; $critMax = (float) $cr['max_points']; break; }
                if ($label === null) continue;
                // uq_assmark(tenant_id, assessment_id, label): two criteria of one
                // rubric may share a label, and the second would collide.
                if (isset($seenLabel[$label])) continue;
                $seenLabel[$label] = true;
                $awarded = is_numeric($points) ? min((float) $points, $critMax ?: 1.0) : null;
                $T['assessment_marks'][] = ['id' => ++$markId, 'tenant_id' => $tenantId,
                    'assessment_id' => $id, 'criterion_id' => $crit,
                    // NULL for the same reason as on the criterion itself.
                    'competency_id' => null, 'label' => $label,
                    'points_awarded' => $awarded !== null ? number_format($awarded, 2, '.', '') : null,
                    'points_max' => number_format(max(1.0, (float) ($critMax ?? 1.0)), 2, '.', ''),
                    'weight' => '1.000',
                    'is_insufficient' => $awarded === null ? 1 : 0,
                    'comment' => null, 'sort_order' => 0];
                $marksFromJson++;
            }
        }
    }
    $note('submission_reviews', 'assessments + assessment_marks',
        sprintf('scores_json became %d mark rows. A review with no marker or no points is carried as a '
            . 'DRAFT assessment rather than dropped — the feedback is still the record of what was said.',
            $marksFromJson),
        count($rows('submission_reviews')), $assId);

    // v1's `assessments` is the GRADEBOOK: a score against an enrolment, typed
    // by what v2 calls a competency. It becomes an assessment with one mark, and
    // that mark is the one place in this migration where competency_id IS known.
    $gradebookOut = 0;
    foreach ($rows('assessments') as $a) {
        $v1id = (int) ($a['id'] ?? 0);
        $enr = $enrOfCourseEnrol[(int) ($a['enrollment_id'] ?? 0)] ?? null;
        if ($enr === null) { $rejected[] = ['table' => 'assessments', 'id' => $v1id, 'why' => 'enrolment did not map']; continue; }
        $created = mam_v2_plan_ts($a['created_at'] ?? null, $nowFallback);
        $marker  = $memberOfV1User[(int) ($a['instructor_id'] ?? 0)] ?? null;
        $score = $a['score'] ?? null; $max = $a['max_score'] ?? null;
        $hasPts = is_numeric($score) && is_numeric($max) && (float) $max > 0;
        $status = ($marker !== null && $hasPts) ? 'released' : 'draft';
        $kind = $kindIdOfV1[(int) ($a['type_id'] ?? 0)] ?? $kindIdOfCode['assignment'];
        $id = ++$assId;
        $T['assessments'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('assessment:gradebook:' . $v1id, $created),
            'tenant_id' => $tenantId, 'enrolment_id' => $enr,
            'assessment_kind_id' => $kind,
            'submission_id' => null, 'session_id' => null, 'module_id' => null,
            'title' => $a['week'] !== null && $a['week'] !== '' ? ('Week ' . (string) $a['week']) : null,
            'rubric_id' => null, 'rubric_version' => null,
            'points_awarded' => $hasPts ? number_format((float) $score, 2, '.', '') : null,
            'points_max' => $hasPts ? number_format((float) $max, 2, '.', '') : null,
            'pct' => $hasPts ? number_format(((float) $score / (float) $max) * 100, 2, '.', '') : null,
            'feedback' => mam_v2_plan_str($a['notes'] ?? null),
            'status' => $status,
            'marker_membership_id' => $status === 'released' ? $marker : null,
            'marked_at' => $status === 'released' ? $created : null,
            'moderated_by' => null, 'moderated_at' => null,
            'released_at' => $status === 'released' ? $created : null,
            'created_at' => $created, 'deleted_seq' => 0];
        if ($hasPts) {
            // No competency: v1's gradebook row is typed by an assessment KIND
            // ('final', 'practical'), which says what the mark was FOR, not
            // which musical competency it evidences. Writing one here would be
            // the same guess the rubric criteria refuse to make.
            $T['assessment_marks'][] = ['id' => ++$markId, 'tenant_id' => $tenantId,
                'assessment_id' => $id, 'criterion_id' => null, 'competency_id' => null,
                'label' => mb_substr((string) ($a['type_code'] ?? 'Gradebook'), 0, 190),
                'points_awarded' => number_format((float) $score, 2, '.', ''),
                'points_max' => number_format((float) $max, 2, '.', ''),
                'weight' => '1.000', 'is_insufficient' => 0, 'comment' => null, 'sort_order' => 0];
        }
        $gradebookOut++;
        $mapped('assessments', $v1id, 'assessments', $id);
    }
    $note('assessments', 'assessments (+ one assessment_mark)',
        'the gradebook row keeps its assessment KIND. Its mark carries no competency: "final" says what '
        . 'the mark was for, not which musical competency it evidences, and writing one would be the same '
        . 'guess the rubric criteria refuse to make.',
        count($rows('assessments')), $gradebookOut);

    /* ── Teach-backs ─────────────────────────────────────────────────────── */
    // v1's teachbacks point at `lessons` — a cohort's instance of a week — while
    // v2 points at the MODULE. The lesson's own module_id is the bridge; a
    // lesson with none leaves module_id NULL rather than guessing a week.
    $lessonModule = [];
    foreach ($rows('lessons') as $l) {
        $m = $modIdOf[(int) ($l['module_id'] ?? 0)] ?? null;
        if ($m !== null) $lessonModule[(int) ($l['id'] ?? 0)] = $m;
    }
    $tbId = 0; $tbSlot = [];
    foreach ($rows('teachbacks') as $tb) {
        $v1id = (int) ($tb['id'] ?? 0);
        $coh  = $cohIdOfV1[(int) ($tb['cohort_id'] ?? 0)] ?? null;
        $mid  = $memberOfV1User[(int) ($tb['student_id'] ?? 0)] ?? null;
        $enr  = ($mid !== null && $coh !== null) ? ($enrolsOfMember[$mid][$coh] ?? null) : null;
        if ($enr === null) {
            [$enr, $why] = $enrolFor(mam_v2_plan_int($tb['student_id'] ?? null), null);
            if ($enr === null) { $rejected[] = ['table' => 'teachbacks', 'id' => $v1id, 'why' => $why]; continue; }
        }
        $mod = $lessonModule[(int) ($tb['lesson_id'] ?? 0)] ?? null;
        // uq_teachback_module(tenant_id, enrolment_id, module_id, deleted_seq).
        // v1's UNIQUE was (lesson, student); two lessons of one module could
        // therefore hold two teach-backs that collide here. The later one is
        // carried as soft-deleted so the record survives and the slot stays free.
        $slotKey = $enr . ':' . (string) $mod;
        $dup = $mod !== null && isset($tbSlot[$slotKey]);
        $tbSlot[$slotKey] = true;
        $created = mam_v2_plan_ts($tb['created_at'] ?? null, $nowFallback);
        $v1status = strtolower((string) ($tb['status'] ?? 'submitted'));
        $reviewer = $memberOfV1User[(int) ($tb['reviewer_id'] ?? 0)] ?? null;
        $decisionRaw = strtolower((string) ($tb['decision'] ?? ''));
        $decision = match ($decisionRaw) {
            'secure', 'pass', 'approved' => 'secure',
            'developing', 'partial' => 'developing',
            'revisit', 'redo', 'rejected' => 'revisit',
            default => null,
        };
        $reviewedAt = mam_v2_plan_ts($tb['reviewed_at'] ?? null, null);
        // chk_teachback_reviewed: 'reviewed' means a named reviewer, a time AND a
        // decision. Without all three the row is carried as still submitted,
        // which is what it actually was.
        $status = ($v1status === 'reviewed' && $reviewer !== null && $reviewedAt !== null && $decision !== null)
            ? 'reviewed'
            : (in_array($v1status, ['returned', 'withdrawn'], true) ? $v1status : 'submitted');
        $scale = function (mixed $v): ?int {
            $n = mam_v2_plan_int($v);
            // v1 left these unbounded. Anything above the 5-point scale is not
            // rescaled — a 5-point column and a 100-point one were being
            // averaged, and inventing a conversion would keep that mistake.
            return ($n === null || $n < 0 || $n > 5) ? null : $n;
        };
        $id = ++$tbId;
        $T['teachbacks'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('teachback:' . $v1id, $created),
            'tenant_id' => $tenantId, 'enrolment_id' => $enr, 'module_id' => $mod, 'session_id' => null,
            'kind' => in_array((string) ($tb['kind'] ?? 'written'), ['written', 'audio', 'video', 'live'], true)
                ? (string) $tb['kind'] : 'written',
            'body' => mam_v2_plan_str($tb['body'] ?? null),
            'status' => $status,
            'reviewer_membership_id' => $status === 'reviewed' ? $reviewer : null,
            'decision' => $status === 'reviewed' ? $decision : null,
            'accuracy' => $scale($tb['accuracy'] ?? null), 'vocabulary' => $scale($tb['vocabulary'] ?? null),
            'explanation' => $scale($tb['explanation'] ?? null), 'application' => $scale($tb['application'] ?? null),
            'confidence' => $scale($tb['confidence'] ?? null),
            'feedback' => mam_v2_plan_str($tb['feedback'] ?? null),
            'created_at' => $created,
            'reviewed_at' => $status === 'reviewed' ? $reviewedAt : null,
            'deleted_seq' => $dup ? $id : 0];
        $addAttachment('teachback', $id, mam_v2_plan_str($tb['artifact_url'] ?? null, 1000),
            'recording', $created, null, 'teachback-artifact:' . $v1id);
        $mapped('teachbacks', $v1id, 'teachbacks', $id,
            null, $dup ? 'soft-deleted: another teach-back already holds this module slot' : null);
    }
    $note('lessons', null,
        'v1\'s lesson is a COHORT\'s instance of a curriculum week — the same week taught to a different '
        . 'class. v2 has no such row: a cohort\'s delivery of a module is its `class_sessions`, and the '
        . 'teaching content belongs to the module. The lesson\'s only irreplaceable fact is which module '
        . 'it instanced, and that is used here to place every teach-back.',
        count($rows('lessons')));
    $note('lesson_progress', null,
        'per-lesson progress follows `lessons`: v2 tracks progress against the MODULE (module_progress), '
        . 'which the cohort spine already carries. Left unmapped until module_progress is populated (batch 3).',
        count($rows('lesson_progress')));
    $note('teachbacks', 'teachbacks (+ attachments)',
        'v1 pointed at a LESSON (a cohort\'s instance of a week); v2 points at the module, bridged by the '
        . 'lesson\'s own module_id. A dimension scored outside 0–5 is dropped, not rescaled.',
        count($rows('teachbacks')), $tbId);

    /* ── Results and certificates ────────────────────────────────────────── */
    $resId = 0;
    foreach ($rows('results') as $r) {
        $v1id = (int) ($r['id'] ?? 0);
        $enr = $enrOfCourseEnrol[(int) ($r['enrollment_id'] ?? 0)] ?? null;
        if ($enr === null) { $rejected[] = ['table' => 'results', 'id' => $v1id, 'why' => 'enrolment did not map']; continue; }
        $pct = is_numeric($r['total_score'] ?? null) ? (float) $r['total_score'] : null;
        if ($pct !== null && ($pct < 0 || $pct > 100)) {
            // v1's `total_score REAL` said nothing about its scale. A value off
            // the percentage scale is not rescaled — it is dropped and raised.
            $flag('result_off_scale', ['table' => 'results', 'id' => $v1id, 'value' => $pct,
                'action' => 'v1 stored a bare score with no stated scale. Re-enter it as a percentage.']);
            $pct = null;
        }
        $outcomeRaw = strtolower((string) ($r['outcome'] ?? ''));
        $outcome = match ($outcomeRaw) {
            'passed', 'pass' => 'passed',
            'referred', 'refer' => 'referred',
            'failed', 'fail' => 'failed',
            'withdrawn' => 'withdrawn',
            'incomplete' => 'incomplete',
            default => null,
        };
        $finalisedBy = $userOfV1User[(int) ($r['finalized_by'] ?? 0)] ?? null;
        $finalisedAt = mam_v2_plan_ts($r['finalized_at'] ?? null, null);
        // chk_enresult_final: a finalised result is SIGNED and states an outcome.
        // v1 could finalise without either, and those rows stay provisional.
        $final = $finalisedBy !== null && $finalisedAt !== null && $outcome !== null;
        if (!$final && ($finalisedAt !== null || $finalisedBy !== null)) {
            $flag('result_unsigned', ['table' => 'results', 'id' => $v1id,
                'action' => 'v1 marked this result final without a signatory, a time or an outcome. '
                    . 'It is carried as provisional; an academic decision-maker must confirm it.']);
        }
        $id = ++$resId;
        $T['enrolment_results'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('result:' . $v1id, $finalisedAt),
            'tenant_id' => $tenantId, 'enrolment_id' => $enr, 'term_id' => null,
            'total_pct' => $pct !== null ? number_format($pct, 2, '.', '') : null,
            'grade' => mam_v2_plan_str($r['grade'] ?? null, 16),
            'outcome' => $outcome,
            // v1 recorded no coverage beside the figure, and that is exactly the
            // gap the v2 column exists to close. NULL says "unknown", which is
            // a different statement from "all of it".
            'coverage_evidenced' => null, 'coverage_total' => null,
            'status' => $final ? 'finalised' : 'provisional',
            'finalised_by' => $final ? $finalisedBy : null,
            'finalised_at' => $final ? $finalisedAt : null,
            'note' => null,
            'created_at' => mam_v2_plan_ts($r['finalized_at'] ?? null, $nowFallback), 'deleted_seq' => 0];
        $mapped('results', $v1id, 'enrolment_results', $id);
    }
    $note('results', 'enrolment_results',
        'coverage is NULL on every row: v1 stored a percentage with nothing saying how much of the '
        . 'syllabus it covered, and "unknown" is a different statement from "all of it".',
        count($rows('results')), $resId);

    $certId = 0;
    foreach ($rows('certificates') as $c) {
        $v1id = (int) ($c['id'] ?? 0);
        $mid = $memberOfV1User[(int) ($c['student_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'certificates', 'id' => $v1id, 'why' => 'holder did not map to a membership']; continue; }
        $issued = mam_v2_plan_ts($c['issued_at'] ?? null, $nowFallback);
        $kindRaw = strtolower((string) ($c['kind'] ?? ''));
        $kind = match ($kindRaw) {
            'level', 'level_completion' => 'level',
            'participation' => 'participation',
            'merit', 'distinction' => 'merit',
            default => 'completion',
        };
        $ref = mam_v2_plan_str($c['reference'] ?? null, 64) ?? ('MAM-CERT-' . $v1id);
        $id = ++$certId;
        $T['certificates'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('certificate:' . $v1id, $issued),
            'tenant_id' => $tenantId, 'membership_id' => $mid, 'enrolment_id' => null,
            'programme_id' => $progIdOf[(int) ($c['program_id'] ?? 0)] ?? null,
            'course_id' => null,
            'level_id' => $lvlIdOf[(int) ($c['level_id'] ?? 0)] ?? null,
            'term_id' => null, 'kind' => $kind,
            'title' => mam_v2_plan_str($c['title'] ?? null, 190),
            // The printed reference is what a holder quotes; the verification
            // code is DERIVED from it and the row's identity so a re-run of the
            // transform produces the same code, and it is not guessable from the
            // reference alone.
            'reference' => $ref,
            'verification_code' => strtoupper(substr(hash('sha256', 'mam:v2:certificate:' . $v1id . ':' . $ref), 0, 32)),
            'status' => 'issued', 'issued_by' => $userOfV1User[(int) ($c['issued_by'] ?? 0)] ?? null,
            'issued_at' => $issued, 'revoked_at' => null, 'revoked_reason' => null];
        $mapped('certificates', $v1id, 'certificates', $id, $ref);
    }
    $note('certificates', 'certificates',
        'the verification code is DERIVED from the certificate\'s identity, so two runs of the transform '
        . 'produce the same code and a re-issued certificate is not silently re-keyed.',
        count($rows('certificates')), $certId);

    /* ── Curriculum proposals and studio practice ────────────────────────── */
    $mpId = 0;
    foreach ($rows('module_proposals') as $mp) {
        $v1id = (int) ($mp['id'] ?? 0);
        $cid = $crsIdOf[(int) ($mp['course_id'] ?? 0)] ?? null;
        if ($cid === null) { $rejected[] = ['table' => 'module_proposals', 'id' => $v1id, 'why' => 'course did not map']; continue; }
        $created = mam_v2_plan_ts($mp['created_at'] ?? null, $nowFallback);
        $statusRaw = strtolower((string) ($mp['status'] ?? 'pending'));
        $reviewedBy = $userOfV1User[(int) ($mp['reviewed_by'] ?? 0)] ?? null;
        $reviewedAt = mam_v2_plan_ts($mp['reviewed_at'] ?? null, null);
        $noteText = mam_v2_plan_str($mp['review_note'] ?? null, 1000);
        $status = match ($statusRaw) {
            'approved' => 'approved', 'rejected' => 'rejected', 'withdrawn' => 'withdrawn',
            default => 'pending',
        };
        // chk_mprop_reviewed / chk_mprop_rejected: a decision has a decider and a
        // time, and a rejection states why. v1 enforced none of it, so a decision
        // missing any of those facts is carried as still PENDING — the proposal
        // returns to the review queue rather than claiming a decision nobody made.
        if ($status === 'approved' && ($reviewedBy === null || $reviewedAt === null)) $status = 'pending';
        if ($status === 'rejected' && ($reviewedBy === null || $reviewedAt === null || $noteText === null)) $status = 'pending';
        if ($status === 'pending' && $statusRaw !== 'pending') {
            $flag('proposal_decision_incomplete', ['table' => 'module_proposals', 'id' => $v1id,
                'was' => $statusRaw, 'action' => 'The decision named no decider, no time or (for a rejection) '
                    . 'no reason. Re-decide it in the new system.']);
        }
        $id = ++$mpId;
        $T['module_proposals'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('proposal:' . $v1id, $created),
            'tenant_id' => $tenantId, 'course_id' => $cid,
            'level_id' => $lvlIdOf[(int) ($mp['level_id'] ?? 0)] ?? null,
            'week_no' => max(1, mam_v2_plan_int($mp['week'] ?? null) ?? 1),
            'title' => mam_v2_plan_str($mp['title'] ?? null, 190) ?? 'Proposed week',
            'kind' => mam_v2_plan_str($mp['kind'] ?? null, 24) ?? 'module',
            'content' => mam_v2_plan_str($mp['content'] ?? null),
            'proposed_by_membership_id' => $memberOfV1User[(int) ($mp['proposed_by'] ?? 0)] ?? null,
            'status' => $status, 'review_note' => $noteText,
            'reviewed_by' => $status === 'pending' ? null : $reviewedBy,
            'reviewed_at' => $status === 'pending' ? null : $reviewedAt,
            'module_id' => null, 'created_at' => $created, 'deleted_seq' => 0];
        $mapped('module_proposals', $v1id, 'module_proposals', $id);
    }
    $note('module_proposals', 'module_proposals',
        'a decision with no decider, no time or (for a rejection) no reason returns to PENDING rather than '
        . 'claiming a decision nobody made.',
        count($rows('module_proposals')), $mpId);

    $tsOut = 0; $tsSeen = [];
    foreach ($rows('tool_scores') as $ts) {
        $mid = $memberOfV1User[(int) ($ts['user_id'] ?? 0)] ?? null;
        $tool = mam_v2_plan_str($ts['tool'] ?? null, 48);
        if ($mid === null || $tool === null) {
            $rejected[] = ['table' => 'tool_scores', 'id' => $ts['id'] ?? null,
                'why' => $mid === null ? 'player did not map to a membership' : 'no tool named'];
            continue;
        }
        // uq_toolscore(tenant_id, membership_id, tool). Two v1 users merged into
        // one person keep the BETTER score and the SUM of the plays, which is
        // what both figures mean.
        $k = $mid . ':' . $tool;
        if (isset($tsSeen[$k])) {
            $i = $tsSeen[$k];
            $T['tool_scores'][$i]['best_score'] = max((int) $T['tool_scores'][$i]['best_score'],
                mam_v2_plan_int($ts['best_score'] ?? null) ?? 0);
            $T['tool_scores'][$i]['plays'] += mam_v2_plan_int($ts['plays'] ?? null) ?? 0;
            continue;
        }
        $tsSeen[$k] = count($T['tool_scores']);
        $T['tool_scores'][] = ['tenant_id' => $tenantId, 'membership_id' => $mid, 'tool' => $tool,
            'best_score' => mam_v2_plan_int($ts['best_score'] ?? null) ?? 0,
            'best_at' => mam_v2_plan_ts($ts['updated_at'] ?? null, null),
            'plays' => mam_v2_plan_int($ts['plays'] ?? null) ?? 0,
            'created_at' => mam_v2_plan_ts($ts['created_at'] ?? null, $nowFallback)];
        $tsOut++;
    }
    $note('tool_scores', 'tool_scores',
        'client-attested practice results, so they stay a practice record with no route into `assessments`. '
        . 'Two merged identities keep the better best-score and the sum of the plays.',
        count($rows('tool_scores')), $tsOut);

    /* ── The academic calendar: the one authority on a refund ────────────── */
    // This must land before any money, because `term_refund_schedule` is the
    // only thing in the system that can answer "what is this family owed back".
    $termId = 0; $termIdOf = [];
    foreach ($rows('academic_terms') as $t) {
        $v1id = (int) ($t['id'] ?? 0);
        $starts = mam_v2_plan_str($t['starts_on'] ?? null, 10);
        $ends   = mam_v2_plan_str($t['ends_on'] ?? null, 10);
        if ($starts === null || $ends === null) {
            $rejected[] = ['table' => 'academic_terms', 'id' => $v1id, 'why' => 'a term with no dates cannot adjudicate a deadline'];
            continue;
        }
        $id = ++$termId;
        $T['academic_terms'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('term:' . $v1id),
            'tenant_id' => $tenantId,
            'year_label' => mam_v2_plan_str($t['year_label'] ?? null, 32) ?? '',
            'code' => mam_v2_plan_str($t['code'] ?? null, 24) ?? ('T' . $v1id),
            'term_no' => mam_v2_plan_int($t['term_no'] ?? null) ?? 1,
            'label' => mam_v2_plan_str($t['label'] ?? null, 190) ?? '',
            'starts_on' => $starts, 'ends_on' => $ends,
            'add_drop_deadline' => mam_v2_plan_str($t['add_drop_deadline'] ?? null, 10),
            'withdrawal_deadline' => mam_v2_plan_str($t['withdrawal_deadline'] ?? null, 10),
            'census_date' => mam_v2_plan_str($t['census_date'] ?? null, 10),
            'teaching_weeks' => mam_v2_plan_int($t['teaching_weeks'] ?? null) ?? 12,
            // The zone the DEADLINES are adjudicated in, carried verbatim: a
            // diaspora guardian in New York must not get a different refund from
            // a Lagos guardian because their clocks disagree about the date.
            'timezone' => mam_v2_plan_str($t['timezone'] ?? null, 64) ?? 'Africa/Lagos',
            'status' => in_array((string) ($t['status'] ?? 'planned'), ['planned', 'active', 'closed'], true)
                ? (string) $t['status'] : 'planned',
            'notes' => mam_v2_plan_str($t['notes'] ?? null),
            'created_at' => mam_v2_plan_ts($t['created_at'] ?? null, $nowFallback),
            'deleted_seq' => 0];
        $termIdOf[$v1id] = $id;
        $mapped('academic_terms', $v1id, 'academic_terms', $id, (string) ($t['code'] ?? ''));
    }
    $note('academic_terms', 'academic_terms',
        'the authored calendar. Deadlines keep their stated timezone: a refund must not depend on whose '
        . 'clock is read.', count($rows('academic_terms')), $termId);

    // v1 stored a refund BAND (weeks 3–5 → 50%); v2 stores one row per week, so
    // a lookup is an equality rather than a range scan nobody can index. The
    // band is expanded, not reinterpreted.
    $refundOut = 0; $refundSeen = [];
    foreach ($rows('term_refund_schedule') as $r) {
        $term = $termIdOf[(int) ($r['term_id'] ?? 0)] ?? null;
        if ($term === null) { $rejected[] = ['table' => 'term_refund_schedule', 'id' => $r['id'] ?? null, 'why' => 'term did not map']; continue; }
        $from = mam_v2_plan_int($r['week_from'] ?? null) ?? 0;
        $to   = mam_v2_plan_int($r['week_to'] ?? null) ?? $from;
        for ($w = $from; $w <= $to; $w++) {
            // A term whose bands overlap would give one week two percentages.
            // The FIRST wins and the collision is raised — silently taking the
            // last would change a published refund table by row order.
            $k = $term . ':' . $w;
            if (isset($refundSeen[$k])) {
                $flag('refund_week_overlap', ['term_id' => $term, 'week' => $w,
                    'action' => 'Two refund bands cover this week. The first was kept; confirm the '
                        . 'published table.']);
                continue;
            }
            $refundSeen[$k] = true;
            $T['term_refund_schedule'][] = ['tenant_id' => $tenantId, 'term_id' => $term,
                'week_no' => $w, 'refund_pct' => max(0, min(100, mam_v2_plan_int($r['percent'] ?? null) ?? 0)),
                'transcript_mark' => null, 'note' => null, 'created_at' => $nowFallback];
            $refundOut++;
        }
    }
    $note('term_refund_schedule', 'term_refund_schedule',
        'a v1 week RANGE expands to one row per week. The published table a family enrolled under is '
        . 'reproduced exactly — this is the row an audit reads a year later.',
        count($rows('term_refund_schedule')), $refundOut);

    $censusOut = 0;
    foreach ($rows('term_census') as $c) {
        $term = $termIdOf[(int) ($c['term_id'] ?? 0)] ?? null;
        if ($term === null) { $rejected[] = ['table' => 'term_census', 'id' => $c['id'] ?? null, 'why' => 'term did not map']; continue; }
        // A SNAPSHOT. It is carried verbatim and never recomputed: "enrolment as
        // of the census date" must not move because somebody withdrew in week
        // nine, and recompute-on-read is the commonest way an official figure
        // quietly rewrites history.
        $T['term_census'][] = ['tenant_id' => $tenantId, 'term_id' => $term,
            'taken_on' => mam_v2_plan_str($c['taken_on'] ?? null, 10),
            'enrolled_count' => mam_v2_plan_int($c['enrolled'] ?? null) ?? 0,
            'active_count' => mam_v2_plan_int($c['active_cohorts'] ?? null) ?? 0,
            'withdrawn_count' => null,
            'breakdown' => mam_v2_plan_str($c['detail_json'] ?? null),
            'taken_by' => null,
            'created_at' => mam_v2_plan_ts($c['created_at'] ?? null, $nowFallback)];
        $censusOut++;
    }
    $note('term_census', 'term_census', 'carried verbatim, never recomputed: an official enrolment figure '
        . 'that moves is not a snapshot.', count($rows('term_census')), $censusOut);

    $closeOut = 0;
    foreach ($rows('term_closures') as $c) {
        $term = $termIdOf[(int) ($c['term_id'] ?? 0)] ?? null;
        if ($term === null) { $rejected[] = ['table' => 'term_closures', 'id' => $c['id'] ?? null, 'why' => 'term did not map']; continue; }
        $kind = (string) ($c['kind'] ?? 'holiday');
        $T['term_closures'][] = ['tenant_id' => $tenantId, 'term_id' => $term, 'centre_id' => null,
            'kind' => in_array($kind, ['holiday', 'break', 'exam', 'makeup'], true) ? $kind : 'holiday',
            'label' => mam_v2_plan_str($c['label'] ?? null, 190) ?? 'Closure',
            'from_on' => mam_v2_plan_str($c['from_on'] ?? null, 10),
            'to_on' => mam_v2_plan_str($c['to_on'] ?? null, 10),
            'created_at' => $nowFallback];
        $closeOut++;
    }
    $note('term_closures', 'term_closures', 'the days the academy is shut, which is what makes a '
        . 'teaching week countable.', count($rows('term_closures')), $closeOut);

    /* ── Guardianships ───────────────────────────────────────────────────── */
    // v1's parent_links, including the v86 ACCESS SCOPE. v2 already has
    // `guardianships.scope`, so this is a rename, not a new concept.
    $glinkOut = 0; $glinkSeen = []; $guardId = 0;
    foreach ($rows('parent_links') as $pl) {
        $g = $memberOfV1User[(int) ($pl['parent_id'] ?? 0)] ?? null;
        $l = $memberOfV1User[(int) ($pl['student_id'] ?? 0)] ?? null;
        if ($g === null || $l === null) {
            $rejected[] = ['table' => 'parent_links', 'id' => $pl['id'] ?? null,
                'why' => $g === null ? 'guardian did not map to a membership' : 'learner did not map to a membership'];
            continue;
        }
        if ($g === $l) {
            // Two v1 users that merged into one person cannot be that person's
            // own guardian, and the link is meaningless rather than harmful.
            $flag('guardianship_self', ['parent_links' => $pl['id'] ?? null,
                'action' => 'The guardian and the learner resolved to the same person; the link was dropped.']);
            continue;
        }
        $k = $g . ':' . $l;
        if (isset($glinkSeen[$k])) continue;
        $glinkSeen[$k] = true;
        // v1 (v86) spells the narrowest scope 'billing'; v2 spells it
        // 'billing_only'. One concept, two vocabularies — translated here rather
        // than left to a CHECK to refuse on cutover night.
        $scopeRaw = (string) ($pl['access_scope'] ?? 'standard');
        $scope = $scopeRaw === 'billing' ? 'billing_only' : $scopeRaw;
        $gid = ++$guardId;
        $T['guardianships'][] = ['id' => $gid, 'tenant_id' => $tenantId,
            'guardian_membership_id' => $g, 'learner_membership_id' => $l,
            'relationship' => mam_v2_plan_str($pl['relationship'] ?? null, 32) ?? 'guardian',
            'is_primary' => 0,
            // An unrecognised scope reads as the NARROWEST, never the widest: a
            // typo must not widen what a guardian can see.
            'scope' => in_array($scope, ['billing_only', 'standard', 'full'], true) ? $scope : 'billing_only',
            'permissions' => null, 'verified_at' => null,
            'created_at' => mam_v2_plan_ts($pl['created_at'] ?? null, $nowFallback),
            'deleted_seq' => 0,
            'created_by' => $userOfV1User[(int) ($pl['created_by'] ?? 0)] ?? null];
        $glinkOut++;
        $mapped('parent_links', (int) ($pl['id'] ?? 0), 'guardianships', $gid);
    }
    $note('parent_links', 'guardianships',
        'the v86 access scope becomes `guardianships.scope`, translating v1\'s "billing" to v2\'s '
        . '"billing_only" — one concept, two vocabularies. An unrecognised value reads as the NARROWEST '
        . 'scope, so a typo cannot widen what a guardian sees.',
        count($rows('parent_links')), $glinkOut);

    /* ── The ratified termly report ──────────────────────────────────────── */
    $trId = 0; $trIdOf = [];
    foreach ($rows('termly_reports') as $r) {
        $v1id = (int) ($r['id'] ?? 0);
        $mid = $memberOfV1User[(int) ($r['student_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'termly_reports', 'id' => $v1id, 'why' => 'learner did not map to a membership']; continue; }
        $coh = $cohIdOfV1[(int) ($r['cohort_id'] ?? 0)] ?? null;
        $status = (string) ($r['status'] ?? 'draft');
        $ratBy  = $userOfV1User[(int) ($r['ratified_by'] ?? 0)] ?? null;
        $ratAt  = mam_v2_plan_ts($r['ratified_at'] ?? null, null);
        // chk_treport_rat: a ratified report names the person who ratified it.
        // v1 enforced the same rule, so a row failing it here is one whose
        // ratifier did not map — and an unattributable ratification is exactly
        // what a family's appeal cannot be answered from.
        if ($status === 'ratified' && ($ratBy === null || $ratAt === null)) {
            $status = 'moderating';
            $flag('report_ratifier_lost', ['table' => 'termly_reports', 'id' => $v1id,
                'action' => 'The ratifying account did not map. The report is carried as moderating and '
                    . 'must be ratified again by a named person.']);
        }
        $id = ++$trId;
        $T['termly_reports'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('treport:' . $v1id),
            'tenant_id' => $tenantId, 'membership_id' => $mid,
            'enrolment_id' => $coh !== null ? ($enrolsOfMember[$mid][$coh] ?? null) : null,
            'cohort_id' => $coh,
            'course_id' => $crsIdOf[(int) ($r['course_id'] ?? 0)] ?? null,
            'level_id' => $lvlIdOf[(int) ($r['level_id'] ?? 0)] ?? null,
            'term_id' => $termIdOf[(int) ($r['term_id'] ?? 0)] ?? null,
            'term_label' => mam_v2_plan_str($r['term_label'] ?? null, 190) ?? '',
            'status' => $status,
            // The BAND, carried as it stands. There is no raw score column in
            // this layer on either side, and a band is what the family was sent.
            'band_low' => mam_v2_plan_int($r['band_low'] ?? null),
            'band_high' => mam_v2_plan_int($r['band_high'] ?? null),
            'coverage_evidenced' => mam_v2_plan_int($r['coverage_evidenced'] ?? null) ?? 0,
            'coverage_total' => mam_v2_plan_int($r['coverage_total'] ?? null) ?? 0,
            'coverage_weight_pct' => mam_v2_plan_int($r['coverage_weight_pct'] ?? null) ?? 0,
            'instructor_membership_id' => $memberOfV1User[(int) ($r['instructor_id'] ?? 0)] ?? null,
            'instructor_comment' => mam_v2_plan_str($r['instructor_comment'] ?? null),
            'attendance_summary' => mam_v2_plan_str($r['attendance_summary'] ?? null, 255),
            'ratified_by' => $status === 'ratified' ? $ratBy : null,
            'ratified_at' => $status === 'ratified' ? $ratAt : null,
            'ratified_note' => mam_v2_plan_str($r['ratified_note'] ?? null, 500),
            'withdrawn_at' => mam_v2_plan_ts($r['withdrawn_at'] ?? null, null),
            'withdrawn_reason' => mam_v2_plan_str($r['withdrawn_reason'] ?? null, 500),
            'created_at' => mam_v2_plan_ts($r['created_at'] ?? null, $nowFallback),
            'deleted_seq' => 0,
            'created_by' => $userOfV1User[(int) ($r['created_by'] ?? 0)] ?? null];
        $trIdOf[$v1id] = $id;
        $mapped('termly_reports', $v1id, 'termly_reports', $id);
    }
    $note('termly_reports', 'termly_reports',
        'the ratified report a family was sent, carried as a BAND — neither side has a column for the raw '
        . 'composite. A ratification whose signatory did not map returns to moderating.',
        count($rows('termly_reports')), $trId);

    // The upgrade this layer exists for: v1 stored the COMPETENCY CODE as text,
    // v2 stores a foreign key. The code is a real join (it is the same code
    // `competencies` was seeded from), so nothing is guessed — but a code with
    // no competency is REJECTED rather than written as an orphan label.
    $trcOut = 0; $trcLost = 0; $trcSeen = [];
    foreach ($rows('termly_report_criteria') as $c) {
        $rep = $trIdOf[(int) ($c['report_id'] ?? 0)] ?? null;
        $code = mam_v2_plan_str($c['competency_code'] ?? null, 48);
        $comp = $code !== null ? ($compIdOfCode[$code] ?? null) : null;
        if ($rep === null || $comp === null) {
            $trcLost++;
            $rejected[] = ['table' => 'termly_report_criteria', 'id' => $c['id'] ?? null,
                'why' => $rep === null ? 'report did not map'
                    : 'competency code "' . (string) $code . '" has no competency in v2'];
            continue;
        }
        $level = (string) ($c['level'] ?? 'insufficient');
        $pct   = mam_v2_plan_int($c['pct'] ?? null);
        // chk_tcrit_evid, on both engines: an unevidenced competency has no
        // percentage and an evidenced one must have kept its figure. A row that
        // disagrees with itself is refused, not silently reshaped.
        if ($level === 'insufficient') $pct = null;
        if ($level !== 'insufficient' && $pct === null) {
            $rejected[] = ['table' => 'termly_report_criteria', 'id' => $c['id'] ?? null,
                'why' => 'level "' . $level . '" with no percentage — an evidenced competency must state one'];
            continue;
        }
        // uq_tcrit_slot(report, competency): v1 had no unique key, so a report
        // could carry the same competency twice. The FIRST wins — a load that
        // fails outright on cutover night is the worse outcome, and a second
        // score for one competency is a question, not a fact.
        $trcKey = $rep . ':' . $comp;
        if (isset($trcSeen[$trcKey])) {
            $flag('report_criterion_duplicate', ['table' => 'termly_report_criteria', 'id' => $c['id'] ?? null,
                'action' => 'The report already scores this competency. The first row was kept; confirm '
                    . 'which score the family was sent.']);
            continue;
        }
        $trcSeen[$trcKey] = true;
        $T['termly_report_criteria'][] = ['tenant_id' => $tenantId, 'report_id' => $rep,
            'competency_id' => $comp,
            'label' => mam_v2_plan_str($c['label'] ?? null, 190) ?? (string) $code,
            'weight_pct' => max(0, min(100, mam_v2_plan_int($c['weight_pct'] ?? null) ?? 0)),
            'level' => in_array($level, ['strong', 'secure', 'working', 'insufficient'], true) ? $level : 'insufficient',
            'pct' => $pct, 'grade_label' => mam_v2_plan_str($c['grade_label'] ?? null, 32),
            'first_assessed_week' => mam_v2_plan_int($c['first_assessed_week'] ?? null),
            'note' => mam_v2_plan_str($c['note'] ?? null, 1000),
            'sort_order' => mam_v2_plan_int($c['sort_order'] ?? null) ?? 0];
        $trcOut++;
    }
    $note('termly_report_criteria', 'termly_report_criteria',
        sprintf('competency_code → a real FOREIGN KEY, resolved by the same code `competencies` was seeded '
            . 'from. %d row(s) whose code has no competency are rejected rather than written as an orphan '
            . 'label.', $trcLost),
        count($rows('termly_report_criteria')), $trcOut);

    $modOut = 0;
    foreach ($rows('report_moderations') as $m) {
        $rep = $trIdOf[(int) ($m['report_id'] ?? 0)] ?? null;
        $code = mam_v2_plan_str($m['competency_code'] ?? null, 48);
        $comp = $code !== null ? ($compIdOfCode[$code] ?? null) : null;
        if ($rep === null || $comp === null) {
            $rejected[] = ['table' => 'report_moderations', 'id' => $m['id'] ?? null,
                'why' => $rep === null ? 'report did not map' : 'competency code has no competency in v2'];
            continue;
        }
        $lvl = mam_v2_plan_str($m['moderator_level'] ?? null, 16);
        $T['report_moderations'][] = ['tenant_id' => $tenantId, 'report_id' => $rep, 'competency_id' => $comp,
            // NULL where the moderator's account did not map. The moderation
            // HAPPENED — dropping the row would take a report below the
            // moderation floor and make it unratifiable in v2.
            'moderator_membership_id' => $memberOfV1User[(int) ($m['moderator_id'] ?? 0)] ?? null,
            'agreed' => mam_v2_plan_bool($m['agreed'] ?? null, 1),
            'moderator_level' => in_array((string) $lvl, ['strong', 'secure', 'working', 'insufficient'], true) ? $lvl : null,
            'note' => mam_v2_plan_str($m['note'] ?? null, 1000),
            'moderated_at' => mam_v2_plan_ts($m['moderated_at'] ?? null, $nowFallback)];
        $modOut++;
    }
    $note('report_moderations', 'report_moderations',
        'a moderation whose moderator did not map keeps the ROW with a NULL moderator: the sample happened, '
        . 'and dropping it would take the report below the moderation floor and make it unratifiable.',
        count($rows('report_moderations')), $modOut);

    $apId = 0;
    foreach ($rows('mark_appeals') as $a) {
        $v1id = (int) ($a['id'] ?? 0);
        $mid = $memberOfV1User[(int) ($a['student_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'mark_appeals', 'id' => $v1id, 'why' => 'learner did not map to a membership']; continue; }
        $code = mam_v2_plan_str($a['competency_code'] ?? null, 48);
        $comp = $code !== null ? ($compIdOfCode[$code] ?? null) : null;
        // Both timestamps come from ONE writer at one precision. opened_at has
        // no database default for exactly this reason: a defaulted
        // CURRENT_TIMESTAMP(3) carries milliseconds a hand-supplied closed_at
        // does not, and chk_appeal_order then refuses an appeal closed twenty
        // minutes after it was raised.
        $opened = mam_v2_plan_ts($a['opened_at'] ?? null, $nowFallback);
        $closed = mam_v2_plan_ts($a['closed_at'] ?? null, null);
        if ($closed !== null && $closed < $opened) {
            // v1's own CHECK forbade this, so it means the export disagrees with
            // itself. The appeal is carried OPEN — a decision with an impossible
            // date is not a decision — and raised.
            $flag('appeal_dates_disagree', ['table' => 'mark_appeals', 'id' => $v1id,
                'action' => 'closed_at precedes opened_at. The appeal is carried as open; re-decide it.']);
            $closed = null;
        }
        $status = (string) ($a['status'] ?? 'open');
        $outcome = mam_v2_plan_str($a['outcome_note'] ?? null, 2000);
        // chk_appeal_closed: any closed appeal states its outcome. An appeal
        // missing either the time or the note is carried OPEN, which is the
        // honest reading — the family is still waiting.
        if ($status !== 'open' && ($closed === null || $outcome === null)) {
            $flag('appeal_outcome_incomplete', ['table' => 'mark_appeals', 'id' => $v1id, 'was' => $status,
                'action' => 'The decision stated no outcome or no time. The appeal is carried as open.']);
            $status = 'open';
        }
        $roleRaw = (string) ($a['raised_role'] ?? 'parent');
        $id = ++$apId;
        $T['mark_appeals'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('appeal:' . $v1id, $opened),
            'tenant_id' => $tenantId,
            'report_id' => $trIdOf[(int) ($a['report_id'] ?? 0)] ?? null,
            'membership_id' => $mid,
            'raised_by_membership_id' => $memberOfV1User[(int) ($a['raised_by'] ?? 0)] ?? null,
            // v1 said 'parent', v2 says 'guardian' — the same person, and the
            // word the redesign uses everywhere a family is addressed.
            'raised_role' => match ($roleRaw) { 'student' => 'learner', 'staff' => 'staff', default => 'guardian' },
            'competency_id' => $comp,
            'reason' => mb_substr((string) ($a['reason'] ?? ''), 0, 2000),
            'status' => $status,
            'outcome_note' => $status === 'open' ? null : $outcome,
            'decided_by' => $status === 'open' ? null : ($userOfV1User[(int) ($a['decided_by'] ?? 0)] ?? null),
            'opened_at' => $opened, 'closed_at' => $status === 'open' ? null : $closed];
        $mapped('mark_appeals', $v1id, 'mark_appeals', $id);
    }
    $note('mark_appeals', 'mark_appeals',
        'a decision with no outcome, no time or an impossible date is carried OPEN — the family is still '
        . 'waiting, and that is the honest reading.',
        count($rows('mark_appeals')), $apId);

    /* ── Guardian threads, channels, make-up credits ─────────────────────── */
    $thId = 0; $thIdOf = [];
    foreach ($rows('guardian_threads') as $th) {
        $v1id = (int) ($th['id'] ?? 0);
        $mid = $memberOfV1User[(int) ($th['student_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'guardian_threads', 'id' => $v1id, 'why' => 'learner did not map to a membership']; continue; }
        $kind = (string) ($th['kind'] ?? 'general');
        $status = (string) ($th['status'] ?? 'open');
        $closed = mam_v2_plan_ts($th['closed_at'] ?? null, null);
        if ($status === 'closed' && $closed === null) $status = 'open';
        $id = ++$thId;
        $T['guardian_threads'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('thread:' . $v1id),
            'tenant_id' => $tenantId, 'learner_membership_id' => $mid,
            'subject' => mam_v2_plan_str($th['subject'] ?? null, 190) ?? '(no subject)',
            'kind' => in_array($kind, ['general', 'academic', 'money', 'safeguarding', 'timetable'], true) ? $kind : 'general',
            'status' => $status,
            'opened_by' => $memberOfV1User[(int) ($th['opened_by'] ?? 0)] ?? null,
            'created_at' => mam_v2_plan_ts($th['created_at'] ?? null, $nowFallback),
            'last_message_at' => mam_v2_plan_ts($th['last_message_at'] ?? null, null),
            'closed_at' => $status === 'closed' ? $closed : null];
        $thIdOf[$v1id] = $id;
        $mapped('guardian_threads', $v1id, 'guardian_threads', $id);
    }
    $note('guardian_threads', 'guardian_threads', 'a conversation about a learner, between the family and '
        . 'the academy.', count($rows('guardian_threads')), $thId);

    $tpOut = 0; $tpSeen = []; $tpStudent = 0;
    foreach ($rows('guardian_thread_participants') as $p) {
        $th = $thIdOf[(int) ($p['thread_id'] ?? 0)] ?? null;
        $mid = $memberOfV1User[(int) ($p['user_id'] ?? 0)] ?? null;
        if ($th === null || $mid === null) {
            $rejected[] = ['table' => 'guardian_thread_participants', 'id' => $p['id'] ?? null,
                'why' => $th === null ? 'thread did not map' : 'participant did not map to a membership'];
            continue;
        }
        $role = (string) ($p['role'] ?? 'guardian');
        if (!in_array($role, ['guardian', 'instructor', 'office', 'admin'], true)) {
            // The role CHECK admits no learner on either side. A row claiming
            // one is dropped, not coerced into 'guardian': the whole point of
            // the vocabulary is that a child cannot be added to a thread ABOUT
            // them, and coercing the role would add them.
            $tpStudent++;
            $rejected[] = ['table' => 'guardian_thread_participants', 'id' => $p['id'] ?? null,
                'why' => 'role "' . $role . '" is not a guardian-thread role; a learner is never a participant'];
            continue;
        }
        $k = $th . ':' . $mid;
        if (isset($tpSeen[$k])) continue;
        $tpSeen[$k] = true;
        $T['guardian_thread_participants'][] = ['tenant_id' => $tenantId, 'thread_id' => $th,
            'membership_id' => $mid, 'role' => $role,
            'last_read_at' => mam_v2_plan_ts($p['last_read_at'] ?? null, null),
            'added_at' => mam_v2_plan_ts($p['added_at'] ?? null, $nowFallback)];
        $tpOut++;
    }
    $note('guardian_thread_participants', 'guardian_thread_participants',
        sprintf('%d row(s) dropped for a role the vocabulary does not admit. A learner is never a '
            . 'participant in a thread about them, and coercing the role would add them.', $tpStudent),
        count($rows('guardian_thread_participants')), $tpOut);

    $tmOut = 0;
    foreach ($rows('guardian_thread_messages') as $m) {
        $th = $thIdOf[(int) ($m['thread_id'] ?? 0)] ?? null;
        if ($th === null) { $rejected[] = ['table' => 'guardian_thread_messages', 'id' => $m['id'] ?? null, 'why' => 'thread did not map']; continue; }
        $role = (string) ($m['author_role'] ?? 'office');
        $T['guardian_thread_messages'][] = ['tenant_id' => $tenantId, 'thread_id' => $th,
            // NULL where the author's account did not map, but `author_name` is
            // NOT NULL and v1 stored it on the row: what a family reads is who
            // wrote to them, and that survives an account that did not.
            'author_membership_id' => $memberOfV1User[(int) ($m['author_id'] ?? 0)] ?? null,
            'author_name' => mam_v2_plan_str($m['author_name'] ?? null, 190) ?? 'MAM Academy',
            'author_role' => in_array($role, ['guardian', 'instructor', 'office', 'admin'], true) ? $role : 'office',
            'body' => (string) ($m['body'] ?? ''),
            'created_at' => mam_v2_plan_ts($m['created_at'] ?? null, $nowFallback)];
        $tmOut++;
    }
    $note('guardian_thread_messages', 'guardian_thread_messages',
        'the author NAME is carried even where the account did not map: what a family reads is who wrote '
        . 'to them.', count($rows('guardian_thread_messages')), $tmOut);

    $prefOut = 0; $prefSeen = [];
    foreach ($rows('guardian_channel_prefs') as $p) {
        $mid = $memberOfV1User[(int) ($p['user_id'] ?? 0)] ?? null;
        if ($mid === null || isset($prefSeen[$mid])) continue;
        $prefSeen[$mid] = true;
        // Carried as stated. The override for safeguarding and money is NOT a
        // stored column on either side — `mam_guardian_channels()` decides it,
        // so a migrated preference cannot mute a safeguarding message.
        $T['guardian_channel_prefs'][] = ['tenant_id' => $tenantId, 'membership_id' => $mid,
            'whatsapp' => mam_v2_plan_bool($p['whatsapp'] ?? null, 1),
            'email' => mam_v2_plan_bool($p['email'] ?? null, 1),
            'sms' => mam_v2_plan_bool($p['sms'] ?? null, 0),
            'updated_at' => mam_v2_plan_ts($p['updated_at'] ?? null, $nowFallback)];
        $prefOut++;
    }
    $note('guardian_channel_prefs', 'guardian_channel_prefs',
        'carried as stated. There is no per-topic column on either side: safeguarding and money override '
        . 'the toggles in code, so a migrated preference cannot mute them.',
        count($rows('guardian_channel_prefs')), $prefOut);

    $mkId = 0;
    foreach ($rows('makeup_credits') as $c) {
        $v1id = (int) ($c['id'] ?? 0);
        $mid = $memberOfV1User[(int) ($c['student_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'makeup_credits', 'id' => $v1id, 'why' => 'learner did not map to a membership']; continue; }
        $reason = (string) ($c['reason'] ?? 'goodwill');
        if (!in_array($reason, ['guardian_notice', 'teacher_cancellation', 'academy_closure', 'goodwill'], true)) $reason = 'goodwill';
        $status = (string) ($c['status'] ?? 'available');
        $spentAt = mam_v2_plan_ts($c['spent_at'] ?? null, null);
        if ($status === 'spent' && $spentAt === null) $status = 'available';
        $revoked = mam_v2_plan_str($c['revoked_reason'] ?? null, 500);
        if ($status === 'revoked' && $revoked === null) $revoked = 'Reason not recorded in the previous system';
        $id = ++$mkId;
        $T['makeup_credits'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('makeup:' . $v1id),
            'tenant_id' => $tenantId, 'membership_id' => $mid,
            'term_id' => $termIdOf[(int) ($c['term_id'] ?? 0)] ?? null,
            'year_label' => mam_v2_plan_str($c['year_label'] ?? null, 32) ?? '',
            'reason' => $reason, 'source_session_id' => null,
            'notice_hours' => is_numeric($c['notice_hours'] ?? null) ? number_format((float) $c['notice_hours'], 2, '.', '') : null,
            // chk_makeup_free, on both engines: only a guardian cancellation may
            // cost the family a slot, whatever v1 stored.
            'counts_against_allowance' => $reason === 'guardian_notice' ? mam_v2_plan_bool($c['counts_against_allowance'] ?? null, 1) : 0,
            'status' => in_array($status, ['available', 'spent', 'expired', 'revoked'], true) ? $status : 'available',
            'expires_on' => mam_v2_plan_str($c['expires_on'] ?? null, 10),
            'spent_session_id' => null, 'spent_at' => $status === 'spent' ? $spentAt : null,
            'granted_by' => $userOfV1User[(int) ($c['granted_by'] ?? 0)] ?? null,
            'granted_at' => mam_v2_plan_ts($c['granted_at'] ?? null, $nowFallback),
            'revoked_reason' => $status === 'revoked' ? $revoked : null,
            'note' => mam_v2_plan_str($c['note'] ?? null, 500)];
        $mapped('makeup_credits', $v1id, 'makeup_credits', $id);
    }
    $note('makeup_credits', 'makeup_credits',
        'the session ids are NOT carried: a make-up credit points at a class session, and those are not '
        . 'migrated yet (batch 3). The credit, its reason and its expiry are the facts a family relies on.',
        count($rows('makeup_credits')), $mkId);

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

    $summerOut = 0;
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
                    'permissions' => null,
                    // Not `verified_at`: the guardian gave this address on a form
                    // and nobody has checked it. A verified flag set by an ETL is
                    // a safeguarding claim nobody made.
                    'verified_at' => null, 'created_at' => $created,
                    'deleted_seq' => 0, 'created_by' => null,
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

    $payId = 0; $payIdOfV1 = []; $moneyTotal = 0; $moneySucceeded = 0; $unowned = 0;
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
        $payIdOfV1[(int) $lid] = $id;
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

    /* ── Fee invoices, aid, and the plans a family pays under ─────────────── */
    // AFTER payments, because a plan instalment names the payment that settled
    // it — and a v1 payment id copied straight across would point at a stranger
    // (the defect this ETL has already been bitten by five times).
    $aidId = 0; $aidIdOf = [];
    foreach ($rows('fee_aid_awards') as $a) {
        $v1id = (int) ($a['id'] ?? 0);
        $mid = $memberOfV1User[(int) ($a['student_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'fee_aid_awards', 'id' => $v1id, 'why' => 'recipient did not map to a membership']; continue; }
        $kind = (string) ($a['kind'] ?? 'bursary');
        $id = ++$aidId;
        $T['invoice_aid_awards'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('aid:' . $v1id),
            'tenant_id' => $tenantId, 'membership_id' => $mid,
            // The four kinds are four MECHANISMS, not four labels: a waiver
            // reduces the line, a bursary and a scholarship are credits, and
            // sibling is a discount on the post-aid balance. Collapsing them
            // changes what the academy's books say happened.
            'kind' => in_array($kind, ['waiver', 'bursary', 'scholarship', 'sibling'], true) ? $kind : 'bursary',
            'pct' => is_numeric($a['pct'] ?? null) ? number_format((float) $a['pct'], 2, '.', '') : null,
            'amount_minor' => mam_v2_plan_int($a['amount_minor'] ?? null),
            'currency' => mam_v2_plan_str($a['currency'] ?? null, 3),
            'term_id' => $termIdOf[(int) ($a['term_id'] ?? 0)] ?? null,
            'term_label' => mam_v2_plan_str($a['term_label'] ?? null, 190),
            'basis' => mam_v2_plan_str($a['basis'] ?? null, 190),
            'status' => mam_v2_plan_str($a['status'] ?? null, 16) ?? 'active',
            'starts_on' => mam_v2_plan_str($a['starts_on'] ?? null, 10),
            'expires_on' => mam_v2_plan_str($a['expires_on'] ?? null, 10),
            'awarded_by' => $userOfV1User[(int) ($a['awarded_by'] ?? 0)] ?? null,
            'awarded_at' => mam_v2_plan_ts($a['awarded_at'] ?? null, $nowFallback),
            'revoked_at' => mam_v2_plan_ts($a['revoked_at'] ?? null, null),
            'revoked_reason' => mam_v2_plan_str($a['revoked_reason'] ?? null, 500),
            'note' => mam_v2_plan_str($a['note'] ?? null, 500)];
        $aidIdOf[$v1id] = $id;
        $mapped('fee_aid_awards', $v1id, 'invoice_aid_awards', $id);
    }
    $note('fee_aid_awards', 'invoice_aid_awards',
        'the four kinds of aid stay four MECHANISMS — a waiver reduces the line, a bursary and a '
        . 'scholarship are credits, sibling discounts the post-aid balance.',
        count($rows('fee_aid_awards')), $aidId);

    $invId = 0; $invIdOf = []; $invRestated = 0;
    foreach ($rows('fee_invoices') as $iv) {
        $v1id = (int) ($iv['id'] ?? 0);
        $mid = $memberOfV1User[(int) ($iv['student_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'fee_invoices', 'id' => $v1id, 'why' => 'the learner billed did not map to a membership']; continue; }
        $currency = strtoupper(mam_v2_plan_str($iv['currency'] ?? null, 3) ?? MAM_V2_BASE_CURRENCY);
        $gross   = mam_v2_plan_int($iv['gross_minor'] ?? null) ?? 0;
        $waiver  = mam_v2_plan_int($iv['waiver_minor'] ?? null) ?? 0;
        $aid     = mam_v2_plan_int($iv['aid_minor'] ?? null) ?? 0;
        $sibling = mam_v2_plan_int($iv['sibling_minor'] ?? null) ?? 0;
        $stated  = mam_v2_plan_int($iv['total_minor'] ?? null);
        // chk_inv_total states the arithmetic; v1 stored the total as a column
        // and nothing checked it. Where the two disagree the ARITHMETIC wins and
        // the discrepancy is raised — an invoice whose stated total is not its
        // own lines minus its own relief reads to a parent as a pricing error.
        $total = $gross - $waiver - $aid - $sibling;
        if ($stated !== null && $stated !== $total) {
            $invRestated++;
            $flag('invoice_total_restated', ['table' => 'fee_invoices', 'id' => $v1id,
                'stated' => $stated, 'computed' => $total,
                'action' => 'v1 stored a total that does not equal gross minus its own relief. The '
                    . 'arithmetic was kept; confirm what the family was actually asked for.']);
        }
        if ($total < 0) {
            $rejected[] = ['table' => 'fee_invoices', 'id' => $v1id,
                'why' => 'relief exceeds the amount billed; a negative total is not a refund and a refund is its own row'];
            continue;
        }
        $status = (string) ($iv['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'issued', 'part_paid', 'paid', 'void', 'written_off'], true)) $status = 'draft';
        $issuedOn = mam_v2_plan_str($iv['issued_on'] ?? null, 10);
        $dueOn    = mam_v2_plan_str($iv['due_on'] ?? null, 10);
        // chk_inv_issued: anything past draft (and not void) states when it was
        // issued and what it is due by. An invoice a family was sent without
        // either is carried as a DRAFT rather than given dates nobody set.
        if (!in_array($status, ['draft', 'void'], true) && ($issuedOn === null || $dueOn === null)) {
            $flag('invoice_undated', ['table' => 'fee_invoices', 'id' => $v1id, 'was' => $status,
                'action' => 'The invoice has no issue or due date. It is carried as a draft; re-issue it.']);
            $status = 'draft';
        }
        // The rate is FROZEN at issue for reconciliation. v1 kept USD→NGN; a
        // non-base currency with no rate is refused rather than converted at
        // today's rate, which is the bug the CHECK exists to prevent.
        $fx = is_numeric($iv['fx_rate_usd_ngn'] ?? null) ? (float) $iv['fx_rate_usd_ngn'] : null;
        $baseTotal = null; $fxRate = null; $fxSource = null;
        if ($currency !== MAM_V2_BASE_CURRENCY) {
            if ($fx === null || $fx <= 0) {
                $rejected[] = ['table' => 'fee_invoices', 'id' => $v1id,
                    'why' => 'a ' . $currency . ' invoice with no frozen rate; converting it now would '
                        . 'restate what the family was charged'];
                continue;
            }
            $fxRate = number_format($fx, 8, '.', '');
            $baseTotal = (int) round($total * $fx);
            $fxSource = 'v1_invoice';
        }
        $id = ++$invId;
        $T['invoices'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('invoice:' . $v1id, $issuedOn),
            'tenant_id' => $tenantId, 'membership_id' => $mid, 'enrolment_id' => null,
            'number' => mam_v2_plan_str($iv['public_ref'] ?? null, 64) ?? ('INV-' . str_pad((string) $v1id, 6, '0', STR_PAD_LEFT)),
            'status' => $status, 'currency' => $currency,
            'subtotal_minor' => $gross, 'tax_minor' => 0,
            'waiver_minor' => $waiver, 'aid_minor' => $aid, 'sibling_minor' => $sibling,
            'discount_minor' => 0, 'total_minor' => $total,
            'amount_paid_minor' => max(0, mam_v2_plan_int($iv['paid_minor'] ?? null) ?? 0),
            'grace_until' => mam_v2_plan_str($iv['grace_until'] ?? null, 10),
            'dunning_stage' => mam_v2_plan_int($iv['dunning_stage'] ?? null) ?? 0,
            'dunning_last_at' => mam_v2_plan_ts($iv['dunning_last'] ?? null, null),
            'term_id' => $termIdOf[(int) ($iv['term_id'] ?? 0)] ?? null,
            'term_label' => mam_v2_plan_str($iv['term_label'] ?? null, 190),
            'base_currency' => MAM_V2_BASE_CURRENCY, 'base_total_minor' => $baseTotal,
            'fx_rate' => $fxRate, 'fx_source' => $fxSource,
            'issued_at' => $issuedOn !== null ? ($issuedOn . ' 00:00:00.000') : null,
            'due_at' => $dueOn !== null ? ($dueOn . ' 23:59:59.999') : null,
            'paid_at' => $status === 'paid' ? mam_v2_plan_ts($iv['updated_at'] ?? null, null) : null,
            'voided_at' => $status === 'void' ? mam_v2_plan_ts($iv['updated_at'] ?? null, $nowFallback) : null,
            'void_reason' => $status === 'void'
                ? (mam_v2_plan_str($iv['void_reason'] ?? null, 500) ?? 'Reason not recorded in the previous system')
                : null,
            'created_at' => mam_v2_plan_ts($iv['created_at'] ?? null, $nowFallback)];
        $invIdOf[$v1id] = $id;
        $mapped('fee_invoices', $v1id, 'invoices', $id, mam_v2_plan_str($iv['public_ref'] ?? null, 64));
    }
    $note('fee_invoices', 'invoices',
        sprintf('the four relief stages stay separable and the total is RECOMPUTED from them; %d v1 total(s) '
            . 'disagreed with their own arithmetic and are raised. A due DATE becomes the end of that day: '
            . 'nobody is late until the day they were given has passed.', $invRestated),
        count($rows('fee_invoices')), $invId);

    $lineOut = 0;
    foreach ($rows('fee_invoice_lines') as $ln) {
        $inv = $invIdOf[(int) ($ln['invoice_id'] ?? 0)] ?? null;
        if ($inv === null) { $rejected[] = ['table' => 'fee_invoice_lines', 'id' => $ln['id'] ?? null, 'why' => 'invoice did not map']; continue; }
        $T['invoice_lines'][] = ['invoice_id' => $inv,
            'item_type' => mam_v2_plan_str($ln['kind'] ?? null, 32) ?? 'tuition',
            'description' => mam_v2_plan_str($ln['label'] ?? null, 500) ?? 'Tuition',
            'quantity' => mam_v2_plan_int($ln['qty'] ?? null) ?? 1,
            'unit_amount_minor' => mam_v2_plan_int($ln['unit_minor'] ?? null) ?? 0,
            // 0, not NULL: the academy charges no tax on tuition, and "no tax
            // rate" is a rate of zero rather than an unknown one.
            'tax_rate_pct' => '0.00',
            'line_total_minor' => mam_v2_plan_int($ln['amount_minor'] ?? null) ?? 0,
            // The waiver belongs on the LINE, which is the whole reason v2 gained
            // the column: "the theory paper was waived" is a fact about that
            // line, not a lump sum off the invoice.
            'waiver_minor' => max(0, mam_v2_plan_int($ln['waiver_minor'] ?? null) ?? 0),
            'waiver_reason' => mam_v2_plan_str($ln['waiver_reason'] ?? null, 190),
            'price_item_id' => null, 'metadata' => null];
        $lineOut++;
    }
    $note('fee_invoice_lines', 'invoice_lines',
        'the waiver stays ON the line: "the theory paper was waived" is a fact about that line, not a lump '
        . 'sum off the invoice.', count($rows('fee_invoice_lines')), $lineOut);

    $credOut = 0;
    foreach ($rows('fee_invoice_credits') as $cr) {
        $inv = $invIdOf[(int) ($cr['invoice_id'] ?? 0)] ?? null;
        if ($inv === null) { $rejected[] = ['table' => 'fee_invoice_credits', 'id' => $cr['id'] ?? null, 'why' => 'invoice did not map']; continue; }
        $kind = (string) ($cr['kind'] ?? 'bursary');
        $T['invoice_credits'][] = ['tenant_id' => $tenantId, 'invoice_id' => $inv,
            // NULL where the award did not map: the credit was APPLIED and the
            // family's balance depends on it. A goodwill credit has no award
            // behind it in v1 either.
            'award_id' => $aidIdOf[(int) ($cr['award_id'] ?? 0)] ?? null,
            'kind' => in_array($kind, ['bursary', 'scholarship', 'goodwill', 'adjustment'], true) ? $kind : 'bursary',
            'amount_minor' => max(0, mam_v2_plan_int($cr['amount_minor'] ?? null) ?? 0),
            'currency' => strtoupper(mam_v2_plan_str($cr['currency'] ?? null, 3) ?? MAM_V2_BASE_CURRENCY),
            'base_amount_minor' => null, 'fx_rate' => null,
            'note' => mam_v2_plan_str($cr['note'] ?? null, 500),
            'applied_by' => $userOfV1User[(int) ($cr['applied_by'] ?? 0)] ?? null,
            'applied_at' => mam_v2_plan_ts($cr['applied_at'] ?? null, $nowFallback)];
        $credOut++;
    }
    $note('fee_invoice_credits', 'invoice_credits',
        'gift aid against an invoice. A credit whose award did not map keeps the ROW — the family\'s '
        . 'balance depends on it, and a goodwill credit has no award in v1 either.',
        count($rows('fee_invoice_credits')), $credOut);

    $planId = 0; $planIdOf = [];
    foreach ($rows('fee_payment_plans') as $pl) {
        $v1id = (int) ($pl['id'] ?? 0);
        $inv = $invIdOf[(int) ($pl['invoice_id'] ?? 0)] ?? null;
        if ($inv === null) { $rejected[] = ['table' => 'fee_payment_plans', 'id' => $v1id, 'why' => 'invoice did not map']; continue; }
        $status = (string) ($pl['status'] ?? 'active');
        $id = ++$planId;
        $T['invoice_payment_plans'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('plan:' . $v1id),
            'tenant_id' => $tenantId, 'invoice_id' => $inv,
            'instalments' => max(1, mam_v2_plan_int($pl['instalments'] ?? null) ?? 1),
            'status' => in_array($status, ['requested', 'active', 'completed', 'cancelled'], true) ? $status : 'active',
            'requested_by' => $userOfV1User[(int) ($pl['requested_by'] ?? 0)] ?? null,
            'approved_by' => $userOfV1User[(int) ($pl['approved_by'] ?? 0)] ?? null,
            'created_at' => mam_v2_plan_ts($pl['created_at'] ?? null, $nowFallback),
            'cancelled_at' => mam_v2_plan_ts($pl['cancelled_at'] ?? null, null),
            'cancel_reason' => mam_v2_plan_str($pl['cancel_reason'] ?? null, 500)];
        $planIdOf[$v1id] = $id;
        $mapped('fee_payment_plans', $v1id, 'invoice_payment_plans', $id);
    }
    $note('fee_payment_plans', 'invoice_payment_plans', 'the arrangement a family pays under.',
        count($rows('fee_payment_plans')), $planId);

    $instOut = 0; $instLostPay = 0;
    foreach ($rows('fee_plan_instalments') as $in) {
        $plan = $planIdOf[(int) ($in['plan_id'] ?? 0)] ?? null;
        if ($plan === null) { $rejected[] = ['table' => 'fee_plan_instalments', 'id' => $in['id'] ?? null, 'why' => 'plan did not map']; continue; }
        // The one defect this ETL keeps rediscovering: a column carrying a
        // foreign id must be REMAPPED. `payment_id` has a constraint here, so an
        // unremapped v1 id would fail the load — but the ones with no constraint
        // are the ones nobody notices, so it is spelled out.
        $payV1 = (int) ($in['payment_id'] ?? 0);
        $pay = $payV1 > 0 ? ($payIdOfV1[$payV1] ?? null) : null;
        if ($payV1 > 0 && $pay === null) $instLostPay++;
        $status = (string) ($in['status'] ?? 'pending');
        $T['invoice_plan_instalments'][] = ['tenant_id' => $tenantId, 'plan_id' => $plan,
            'seq' => max(1, mam_v2_plan_int($in['seq'] ?? null) ?? 1),
            'due_on' => mam_v2_plan_str($in['due_on'] ?? null, 10),
            'amount_minor' => max(0, mam_v2_plan_int($in['amount_minor'] ?? null) ?? 0),
            'status' => (in_array($status, ['pending', 'paid', 'waived'], true)
                && !($status === 'paid' && mam_v2_plan_ts($in['paid_at'] ?? null, null) === null))
                ? $status : 'pending',
            // chk_inst_paid, on both engines: a paid instalment states when. An
            // instalment marked paid with no time is carried as PENDING rather
            // than given a timestamp nobody recorded.
            'paid_at' => mam_v2_plan_ts($in['paid_at'] ?? null, null),
            'payment_id' => $pay];
        $instOut++;
    }
    $note('fee_plan_instalments', 'invoice_plan_instalments',
        sprintf('%d instalment(s) lost the payment they named, which is carried as NULL rather than as a '
            . 'v1 id that would resolve to a stranger.', $instLostPay),
        count($rows('fee_plan_instalments')), $instOut);

    $bursOut = 0;
    foreach ($rows('bursary_applications') as $b) {
        $v1id = (int) ($b['id'] ?? 0);
        $mid = $memberOfV1User[(int) ($b['student_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'bursary_applications', 'id' => $v1id, 'why' => 'applicant did not map to a membership']; continue; }
        $T['bursary_applications'][] = ['public_id' => mam_v2_plan_pid('bursary:' . $v1id),
            'tenant_id' => $tenantId, 'membership_id' => $mid,
            'guardian_membership_id' => $memberOfV1User[(int) ($b['guardian_id'] ?? 0)] ?? null,
            'term_id' => $termIdOf[(int) ($b['term_id'] ?? 0)] ?? null,
            'term_label' => mam_v2_plan_str($b['term_label'] ?? null, 190),
            // A declared income BAND, and nothing else. There is no hardship,
            // circumstances or case-text column on either side: a bursary is
            // assessed on the band, and the published table is a ceiling a
            // committee may go under and never over.
            'income_band' => mam_v2_plan_str($b['income_band'] ?? null, 32) ?? 'undisclosed',
            'household_size' => mam_v2_plan_int($b['household_size'] ?? null),
            'dependants_in_academy' => mam_v2_plan_int($b['dependants_in_academy'] ?? null),
            'status' => mam_v2_plan_str($b['status'] ?? null, 16) ?? 'submitted',
            'awarded_pct' => is_numeric($b['awarded_pct'] ?? null) ? number_format((float) $b['awarded_pct'], 2, '.', '') : null,
            'award_id' => $aidIdOf[(int) ($b['award_id'] ?? 0)] ?? null,
            'decided_by' => $userOfV1User[(int) ($b['decided_by'] ?? 0)] ?? null,
            'decided_at' => mam_v2_plan_ts($b['decided_at'] ?? null, null),
            'outcome_note' => mam_v2_plan_str($b['outcome_note'] ?? null, 1000),
            'note' => mam_v2_plan_str($b['note'] ?? null, 1000),
            'created_at' => mam_v2_plan_ts($b['created_at'] ?? null, $nowFallback)];
        $bursOut++;
        $mapped('bursary_applications', $v1id, 'bursary_applications', $bursOut);
    }
    $note('bursary_applications', 'bursary_applications',
        'a declared income BAND and nothing else: no hardship column, no case text, and no link to an '
        . 'admission decision, on either side.',
        count($rows('bursary_applications')), $bursOut);

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
