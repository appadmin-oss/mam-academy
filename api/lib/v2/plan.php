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
    'attachments', 'rooms', 'class_sessions', 'attendance', 'module_progress', 'module_carryforwards',
    'instructor_availability', 'instructor_courses', 'instructor_clockins', 'instructor_fines',
    'faculty_invites', 'instructor_applications', 'meeting_participants', 'session_progress_reports',
    'cohort_waitlist', 'learning_resources',
    'academic_terms', 'term_refund_schedule', 'term_census', 'term_closures',
    'termly_reports', 'termly_report_criteria', 'report_moderations', 'mark_appeals',
    'guardian_threads', 'guardian_thread_participants', 'guardian_thread_messages',
    'guardian_channel_prefs', 'makeup_credits',
    'rubrics', 'rubric_criteria',
    'assessment_kinds', 'assignment_groups', 'assignments',
    'submissions', 'submission_markers',
    'assessments', 'assessment_marks',
    'teachbacks', 'enrolment_results', 'certificates', 'module_proposals', 'tool_scores',
    'applications', 'payments',
    'invoice_aid_awards', 'invoices', 'invoice_lines', 'invoice_credits',
    'invoice_payment_plans', 'invoice_plan_instalments', 'bursary_applications',
    'reschedule_requests', 'reschedule_items',
    'announcements', 'contact_messages', 'support_tickets', 'ticket_messages',
    'surveys', 'survey_responses', 'learner_feedback', 'application_notes',
    'scholarship_applications', 'auditer_results', 'marketing_contacts',
    'promotions', 'announcement_banners',
    'consents', 'vetting_records', 'id_cards', 'card_scans',
    'achievements', 'practice_logs', 'practice_challenges', 'repertoire',
    'recitals', 'recital_items', 'referral_codes', 'referrals',
    'dp_assessments', 'dp_breaches', 'dp_processors', 'dp_register_notes',
    'automation_rules', 'ai_runs',
    'store_products', 'store_orders', 'store_order_items', 'notifications',
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
    $nameOfUser = [];            // v2 user id  => display name
    $peopleNameOfMember = [];    // membership  => display name
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
        // Membership → the person's NAME. Several later tables carry a NOT NULL
        // author_name beside a nullable author id, on purpose: what a family
        // reads is who wrote to them, and that has to survive an account being
        // closed or a person being erased.
        $nameOfUser[$uid] = mb_substr(((string) ($p['full_name'] ?? '')) !== '' ? (string) $p['full_name'] : 'MAM Academy', 0, 190);

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
            $peopleNameOfMember[$mid] = $nameOfUser[$uid] ?? 'MAM Academy';
            $userOfV1User[(int) $p['user_id']]   = $uid;
        }

        // BOTH source rows map to the one v2 user — that is what makes a merge
        // auditable rather than a fact somebody has to take on trust.
        //
        // The note also carries the DISAGREEMENT where there is one. v2 keeps
        // only the winning name, so a name the merge discarded is captured here
        // or it is gone: `mam_v2_rm_merge_review()` reads exactly this string,
        // and a merge nobody can review is a merge nobody can undo.
        $mergeNote = $p['merged_by'] !== null ? 'merged identity via ' . $p['merged_by'] : null;
        if (!empty($p['name_conflict'])) {
            $mergeNote = ($mergeNote ?? 'merged identity') . ' — SOURCE NAMES DISAGREE: '
                       . implode(' / ', array_slice((array) ($p['name_variants'] ?? []), 0, 3));
        }
        foreach ((array) $p['sources'] as $src) {
            [$lt, $lid] = array_pad(explode(':', $src, 2), 2, '');
            $mapped($lt, $lid, 'users', $uid, mam_v2_plan_str($v1u['enrollment_ref'] ?? null, 64), $mergeNote);
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
    $fSafeguard = $defineField('memberships', 'safeguarding_contact',
        'Safeguarding contact (as recorded in v1)', 'string');
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

    /* ── Rooms, sessions, attendance, module progress ─────────────────────── */
    $roomId = 0; $roomIdOf = []; $roomIdOfName = [];
    foreach ($rows('rooms') as $r) {
        $name = mam_v2_plan_str($r['name'] ?? null, 120);
        if ($name === null) continue;
        $id = ++$roomId;
        $T['rooms'][] = ['id' => $id, 'tenant_id' => $tenantId, 'centre_id' => $centreId,
            'name' => $name, 'capacity' => mam_v2_plan_int($r['capacity'] ?? null),
            'resources' => mam_v2_plan_str($r['resources'] ?? null),
            'is_active' => mam_v2_plan_bool($r['active'] ?? null, 1), 'deleted_seq' => 0];
        $roomIdOf[(int) ($r['id'] ?? 0)] = $id;
        $roomIdOfName[mb_strtolower($name)] = $id;
        $mapped('rooms', (int) ($r['id'] ?? 0), 'rooms', $id, $name);
    }
    $note('rooms', 'rooms', 'the physical spaces a session can be timetabled into.',
        count($rows('rooms')), $roomId);

    // ⚠️ THE ONE ASSUMPTION IN THIS WHOLE PLAN THAT ONLY THE ACADEMY CAN
    // CONFIRM. v1 stored ONE datetime per session and nothing recorded which
    // zone it was in. v2 stores three columns — the local wall clock, its zone,
    // and the UTC instant — because a lesson at 16:00 must stay at 16:00 if an
    // offset rule changes.
    //
    // The v1 value is carried as the UTC INSTANT, and the local time is derived
    // from it. That is the reading v1's own code used: PHP's timezone is pinned
    // to UTC in bootstrap, so every `strtotime($scheduled_at)` comparison — the
    // check-in window, the lateness figures stored on attendance, the reminder
    // sweeps — treated it as UTC. Carrying it as a Lagos wall clock instead
    // would move every historic session an hour and contradict the lateness
    // already recorded against it.
    //
    // If the academy typed local times into that field (which the admin form's
    // `datetime-local` input invites), then every migrated session displays an
    // hour early and the stored lateness was always an hour out. Raised once,
    // with a count, because it is a question about the past that nobody can
    // answer from the data.
    $sessTz = 'Africa/Lagos';
    $utcZone = new DateTimeZone('UTC');
    $locZone = new DateTimeZone($sessTz);
    $sessId = 0; $sessIdOf = [];
    foreach ($rows('class_sessions') as $cs) {
        $v1id = (int) ($cs['id'] ?? 0);
        $coh = $cohIdOfV1[(int) ($cs['cohort_id'] ?? 0)] ?? null;
        $at  = mam_v2_plan_ts($cs['scheduled_at'] ?? null, null);
        if ($coh === null || $at === null) {
            // A session with no time is not a session: it cannot be attended,
            // reported on, or moved, and every table in this layer keys off it.
            $rejected[] = ['table' => 'class_sessions', 'id' => $v1id,
                'why' => $coh === null ? 'cohort did not map' : 'the session has no scheduled time'];
            continue;
        }
        $utc = new DateTimeImmutable(substr($at, 0, 19), $utcZone);
        $status = (string) ($cs['status'] ?? 'scheduled');
        if (!in_array($status, ['scheduled', 'held', 'cancelled', 'rescheduled'], true)) $status = 'scheduled';
        $cancel = mam_v2_plan_str($cs['cancellation_reason'] ?? null, 500);
        // chk_session_cancel_reason: a cancelled session says why. v1 did not
        // require it, and "cancelled, no reason" is the row a family disputes.
        if ($status === 'cancelled' && $cancel === null) $cancel = 'Reason not recorded in the previous system';
        $mode = (string) ($cs['mode'] ?? 'onsite');
        $room = $roomIdOf[(int) ($cs['room_id'] ?? 0)] ?? null;
        if ($room === null) {
            // v1 also had a free-text `room`. A name that matches a real room is
            // the same room; anything else stays in the notes rather than
            // inventing a space.
            $room = $roomIdOfName[mb_strtolower((string) ($cs['room'] ?? ''))] ?? null;
        }
        $id = ++$sessId;
        $T['class_sessions'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('session:' . $v1id, $at),
            'tenant_id' => $tenantId, 'cohort_id' => $coh,
            'module_id' => $lessonModule[(int) ($cs['lesson_id'] ?? 0)] ?? null,
            'room_id' => $room,
            'title' => mam_v2_plan_str($cs['title'] ?? null, 190),
            'sequence_no' => null,
            'scheduled_start_local' => $utc->setTimezone($locZone)->format('Y-m-d H:i:s.v'),
            'timezone' => $sessTz,
            'scheduled_start_utc' => $utc->format('Y-m-d H:i:s.v'),
            'duration_minutes' => mam_v2_plan_int($cs['duration_min'] ?? null) ?? 60,
            'delivery_mode' => in_array($mode, ['onsite', 'online', 'hybrid'], true) ? $mode : 'onsite',
            'status' => $status,
            'meeting_provider' => mam_v2_plan_str($cs['meeting_provider'] ?? null, 24),
            'meeting_url' => mam_v2_plan_str($cs['meeting_link'] ?? null, 500),
            'meeting_code' => null,
            'rescheduled_from_id' => null,   // resolved in a second pass below
            'cancellation_reason' => $cancel,
            // v1's teaching notes, topics and outcomes are three columns of the
            // same thing and v2 has one. They are joined rather than dropped.
            'notes' => mam_v2_plan_str(implode("\n\n", array_filter([
                mam_v2_plan_str($cs['notes'] ?? null),
                mam_v2_plan_str($cs['lesson_notes'] ?? null),
                mam_v2_plan_str($cs['topics'] ?? null) !== null ? 'Topics: ' . $cs['topics'] : null,
                mam_v2_plan_str($cs['learning_outcomes'] ?? null) !== null ? 'Outcomes: ' . $cs['learning_outcomes'] : null,
            ]))),
            'created_at' => mam_v2_plan_ts($cs['created_at'] ?? null, $nowFallback)];
        $sessIdOf[$v1id] = $id;
        $mapped('class_sessions', $v1id, 'class_sessions', $id);
    }
    // Second pass: a session that replaced another can only be linked once both
    // exist. An unresolvable predecessor is NULL, never a v1 id.
    $reschLinked = 0;
    foreach ($rows('class_sessions') as $cs) {
        $me = $sessIdOf[(int) ($cs['id'] ?? 0)] ?? null;
        $from = $sessIdOf[(int) ($cs['rescheduled_from'] ?? 0)] ?? null;
        if ($me === null || $from === null) continue;
        foreach ($T['class_sessions'] as $k => $row) {
            if ((int) $row['id'] === $me) { $T['class_sessions'][$k]['rescheduled_from_id'] = $from; $reschLinked++; break; }
        }
    }
    $note('class_sessions', 'class_sessions',
        sprintf('v1\'s single `scheduled_at` is carried as the UTC INSTANT and the local time derived in %s, '
            . 'because that is how v1\'s own code read it (PHP pinned to UTC) and the lateness figures '
            . 'already stored against it. %d rescheduled-from link(s) resolved.', $sessTz, $reschLinked),
        count($rows('class_sessions')), $sessId);
    if ($sessId > 0) {
        $flag('session_timezone_assumption', ['sessions' => $sessId, 'timezone' => $sessTz,
            'action' => 'v1 stored one datetime per session with no zone recorded. It is carried as UTC, '
                . 'matching how v1 compared it. CONFIRM with the academy: if those values were typed as '
                . 'Lagos wall-clock times, every migrated session displays an hour early and the lateness '
                . 'v1 recorded was always an hour out.']);
    }

    $attOut = 0; $attSeen = [];
    foreach ($rows('session_attendance') as $a) {
        $sess = $sessIdOf[(int) ($a['session_id'] ?? 0)] ?? null;
        $mid  = $memberOfV1User[(int) ($a['student_id'] ?? 0)] ?? null;
        if ($sess === null || $mid === null) {
            $rejected[] = ['table' => 'session_attendance', 'id' => $a['id'] ?? null,
                'why' => $sess === null ? 'session did not map' : 'learner did not map to a membership'];
            continue;
        }
        // v2 keys attendance on the ENROLMENT, not the student: the register
        // belongs to the course the learner is taking. The session's cohort is
        // what resolves it.
        $coh = null;
        foreach ($T['class_sessions'] as $row) if ((int) $row['id'] === $sess) { $coh = (int) $row['cohort_id']; break; }
        $enr = $coh !== null ? ($enrolsOfMember[$mid][$coh] ?? null) : null;
        if ($enr === null) {
            // A mark against a session of a cohort the learner is not enrolled
            // in. v1 allowed it (the table was keyed on the student); v2 cannot
            // express it, and inventing an enrolment to hold it would put
            // somebody on a register they were never on.
            $rejected[] = ['table' => 'session_attendance', 'id' => $a['id'] ?? null,
                'why' => 'the learner has no enrolment in the cohort this session belongs to'];
            continue;
        }
        $k = $sess . ':' . $enr;
        if (isset($attSeen[$k])) continue;
        $attSeen[$k] = true;
        $status = (string) ($a['status'] ?? 'present');
        $mode   = (string) ($a['mode'] ?? 'onsite');
        $method = (string) ($a['method'] ?? 'instructor');
        $T['attendance'][] = ['tenant_id' => $tenantId, 'session_id' => $sess, 'enrolment_id' => $enr,
            'status' => in_array($status, ['present', 'absent', 'late', 'excused', 'left_early',
                'technical_failure', 'rescheduled'], true) ? $status : 'absent',
            'delivery_mode' => in_array($mode, ['onsite', 'online', 'hybrid'], true) ? $mode : 'onsite',
            'clocked_in_at' => mam_v2_plan_ts($a['checked_in_at'] ?? null, null),
            'clocked_out_at' => mam_v2_plan_ts($a['clocked_out_at'] ?? null, null),
            // STORED, never recomputed: a session's time can be edited
            // afterwards and what happened must not move with it.
            'minutes_present' => mam_v2_plan_int($a['duration_min'] ?? null),
            'minutes_late' => mam_v2_plan_int($a['minutes_late'] ?? null) ?? 0,
            'method' => in_array($method, ['instructor', 'self', 'qr', 'card', 'api'], true) ? $method : 'instructor',
            'geo_lat' => is_numeric($a['checkin_lat'] ?? null) ? number_format((float) $a['checkin_lat'], 7, '.', '') : null,
            'geo_lng' => is_numeric($a['checkin_lng'] ?? null) ? number_format((float) $a['checkin_lng'], 7, '.', '') : null,
            'notes' => mam_v2_plan_str($a['notes'] ?? null, 500),
            'marked_by' => $userOfV1User[(int) ($a['marked_by'] ?? 0)] ?? null,
            'created_at' => mam_v2_plan_ts($a['created_at'] ?? null, $nowFallback)];
        $attOut++;
    }
    $note('session_attendance', 'attendance',
        'keyed on the ENROLMENT rather than the student: a register belongs to the course a learner is '
        . 'taking. A mark against a cohort they were never enrolled in is rejected — inventing an enrolment '
        . 'to hold it would put somebody on a register they were never on.',
        count($rows('session_attendance')), $attOut);

    // v1 tracked progress against a LESSON (a cohort's instance of a week); v2
    // tracks it against the MODULE. Several lessons can instance one module, so
    // the FURTHEST progress wins — a learner who completed the material does not
    // become incomplete because a second lesson row existed.
    $mpOut = 0; $mpSeen = [];
    $statusRank = ['not_started' => 0, 'in_progress' => 1, 'carried_forward' => 2, 'completed' => 3];
    foreach ($rows('lesson_progress') as $lp) {
        $mod = $lessonModule[(int) ($lp['lesson_id'] ?? 0)] ?? null;
        $mid = $memberOfV1User[(int) ($lp['student_id'] ?? 0)] ?? null;
        if ($mod === null || $mid === null) {
            $rejected[] = ['table' => 'lesson_progress', 'id' => $lp['id'] ?? null,
                'why' => $mod === null ? 'the lesson does not instance a module in v2'
                    : 'learner did not map to a membership'];
            continue;
        }
        $mine = $enrolsOfMember[$mid] ?? [];
        if (count($mine) !== 1) {
            $rejected[] = ['table' => 'lesson_progress', 'id' => $lp['id'] ?? null,
                'why' => 'the learner has ' . count($mine) . ' enrolments and progress named no cohort'];
            continue;
        }
        $enr = (int) reset($mine);
        $status = (string) ($lp['status'] ?? 'not_started');
        if (!isset($statusRank[$status])) $status = 'not_started';
        $pct = mam_v2_plan_int($lp['percent'] ?? null) ?? 0;
        $k = $enr . ':' . $mod;
        if (isset($mpSeen[$k])) {
            $i = $mpSeen[$k];
            if ($statusRank[$status] > $statusRank[$T['module_progress'][$i]['status']]) {
                $T['module_progress'][$i]['status'] = $status;
            }
            $T['module_progress'][$i]['percent_complete'] = max((int) $T['module_progress'][$i]['percent_complete'], $pct);
            continue;
        }
        $mpSeen[$k] = count($T['module_progress']);
        $T['module_progress'][] = ['tenant_id' => $tenantId, 'enrolment_id' => $enr, 'module_id' => $mod,
            'status' => $status, 'percent_complete' => $pct,
            // v1 kept no per-module mastery figure; NULL says so, and 0 would
            // report an unassessed learner as having mastered nothing.
            'mastery_pct' => null,
            'week_planned' => null, 'week_started' => null, 'week_completed' => null,
            'attempts' => 0, 'due_at' => null,
            'completed_at' => $status === 'completed' ? mam_v2_plan_ts($lp['updated_at'] ?? null, null) : null];
        $mpOut++;
    }
    // A lesson with no module_id can hold no progress in v2, and in the live
    // data that is most of them. Raised as ONE flag with a count rather than as
    // a per-row rejection nobody reads: "your learners' week-by-week progress
    // did not come across" is a decision for the academy, not a footnote.
    $lpIn = count($rows('lesson_progress'));
    if ($lpIn > 0 && $mpOut < $lpIn) {
        $flag('lesson_progress_unplaceable', ['rows_in' => $lpIn, 'rows_out' => $mpOut,
            'action' => 'v1 tracked progress against a LESSON and v2 tracks it against the MODULE the '
                . 'lesson instances. Lessons authored with no module_id have nowhere to put it. Either set '
                . 'module_id on those lessons before the final export, or accept that this much '
                . 'week-by-week progress does not migrate.']);
    }
    $note('lesson_progress', 'module_progress',
        'v1 tracked a LESSON (a cohort\'s instance of a week); v2 tracks the module. Where several lessons '
        . 'instance one module the FURTHEST progress wins — completing the material must not be undone by a '
        . 'second lesson row.', count($rows('lesson_progress')), $mpOut);

    $cfOut = 0;
    foreach ($rows('module_carryforward') as $cf) {
        $mid = $memberOfV1User[(int) ($cf['student_id'] ?? 0)] ?? null;
        $mod = $modIdOf[(int) ($cf['module_id'] ?? 0)] ?? ($lessonModule[(int) ($cf['lesson_id'] ?? 0)] ?? null);
        $mine = $mid !== null ? ($enrolsOfMember[$mid] ?? []) : [];
        if ($mid === null || $mod === null || count($mine) !== 1) {
            $rejected[] = ['table' => 'module_carryforward', 'id' => $cf['id'] ?? null,
                'why' => $mod === null ? 'module did not map' : 'learner did not resolve to one enrolment'];
            continue;
        }
        $T['module_carryforwards'][] = ['tenant_id' => $tenantId, 'enrolment_id' => (int) reset($mine),
            'module_id' => $mod,
            'from_week' => mam_v2_plan_int($cf['from_week'] ?? null),
            'to_week' => mam_v2_plan_int($cf['to_week'] ?? null),
            'to_date' => mam_v2_plan_str($cf['to_date'] ?? null, 10),
            'previous_status' => mam_v2_plan_str($cf['previous_status'] ?? null, 24) ?? 'in_progress',
            'previous_mastery' => null,
            'reason' => mam_v2_plan_str($cf['reason'] ?? null, 500),
            'occurred_at' => mam_v2_plan_ts($cf['created_at'] ?? null, $nowFallback),
            'created_by' => $userOfV1User[(int) ($cf['created_by'] ?? 0)] ?? null];
        $cfOut++;
    }
    $note('module_carryforward', 'module_carryforwards', 'a week deferred, and why.',
        count($rows('module_carryforward')), $cfOut);

    /* ── Teaching operations ─────────────────────────────────────────────── */
    $availOut = 0;
    foreach ($rows('instructor_availability') as $av) {
        $mid = $memberOfV1User[(int) ($av['instructor_id'] ?? 0)] ?? null;
        $dow = mam_v2_plan_int($av['day_of_week'] ?? null);
        $from = mam_v2_plan_str($av['start_time'] ?? null, 8);
        $to   = mam_v2_plan_str($av['end_time'] ?? null, 8);
        if ($mid === null || $dow === null || $dow < 0 || $dow > 6 || $from === null || $to === null || $to <= $from) {
            $rejected[] = ['table' => 'instructor_availability', 'id' => $av['id'] ?? null,
                'why' => $mid === null ? 'instructor did not map to a membership'
                    : 'the window is not a valid day and time range'];
            continue;
        }
        $T['instructor_availability'][] = ['tenant_id' => $tenantId, 'membership_id' => $mid,
            'centre_id' => $centreId, 'day_of_week' => $dow,
            'start_time' => strlen($from) === 5 ? $from . ':00' : $from,
            'end_time' => strlen($to) === 5 ? $to . ':00' : $to,
            // v1 recorded no zone. The academy's own is the only defensible
            // reading for a teaching window, and it is stated rather than implied.
            'timezone' => $sessTz,
            'effective_from' => mam_v2_plan_str($av['effective_from'] ?? null, 10),
            'effective_to' => mam_v2_plan_str($av['effective_to'] ?? null, 10),
            'is_active' => mam_v2_plan_bool($av['active'] ?? null, 1), 'note' => null,
            'created_at' => mam_v2_plan_ts($av['created_at'] ?? null, $nowFallback)];
        $availOut++;
    }
    $note('instructor_availability', 'instructor_availability',
        'a wall-clock window plus the academy\'s zone: 16:00 must stay 16:00 if an offset rule changes.',
        count($rows('instructor_availability')), $availOut);

    $icOut = 0; $icSeen = [];
    foreach ($rows('instructor_assignments') as $ia) {
        $mid = $memberOfV1User[(int) ($ia['instructor_id'] ?? 0)] ?? null;
        $cid = $crsIdOf[(int) ($ia['course_id'] ?? 0)] ?? null;
        if ($mid === null || $cid === null) {
            $rejected[] = ['table' => 'instructor_assignments', 'id' => $ia['id'] ?? null,
                'why' => $mid === null ? 'instructor did not map to a membership' : 'course did not map'];
            continue;
        }
        $lvl = $lvlIdOf[(int) ($ia['level_id'] ?? 0)] ?? null;
        $k = $mid . ':' . $cid . ':' . (string) ($lvl ?? 0);
        if (isset($icSeen[$k])) continue;
        $icSeen[$k] = true;
        $T['instructor_courses'][] = ['tenant_id' => $tenantId, 'membership_id' => $mid, 'course_id' => $cid,
            'level_id' => $lvl, 'level_scope' => $lvl ?? 0, 'is_lead' => 0,
            'created_at' => mam_v2_plan_ts($ia['created_at'] ?? null, $nowFallback), 'created_by' => null];
        $icOut++;
    }
    $note('instructor_assignments', 'instructor_courses',
        'renamed: in v2 an "assignment" is set work. This is ELIGIBILITY — who may be given a class — which '
        . 'is a different question from `cohort_staff`, who is teaching one.',
        count($rows('instructor_assignments')), $icOut);

    $ciOut = 0; $ciSeen = [];
    foreach ($rows('instructor_clockins') as $ci) {
        $sess = $sessIdOf[(int) ($ci['session_id'] ?? 0)] ?? null;
        $mid  = $memberOfV1User[(int) ($ci['instructor_id'] ?? 0)] ?? null;
        if ($sess === null || $mid === null) {
            $rejected[] = ['table' => 'instructor_clockins', 'id' => $ci['id'] ?? null,
                'why' => $sess === null ? 'session did not map' : 'instructor did not map to a membership'];
            continue;
        }
        $k = $sess . ':' . $mid . ':instructor';
        if (isset($ciSeen[$k])) continue;
        $ciSeen[$k] = true;
        $in  = mam_v2_plan_ts($ci['clock_in_at'] ?? null, null);
        $out = mam_v2_plan_ts($ci['clock_out_at'] ?? null, null);
        if ($out !== null && $in !== null && $out < $in) {
            $flag('clockin_dates_disagree', ['table' => 'instructor_clockins', 'id' => $ci['id'] ?? null,
                'action' => 'clock-out precedes clock-in. The clock-out was dropped; the presence stands.']);
            $out = null;
        }
        $status = (string) ($ci['status'] ?? 'present');
        if (!in_array($status, ['present', 'late', 'absent', 'substituted', 'cancelled'], true)) $status = 'present';
        // chk_clockin_when: present means in the room at a stated moment. A row
        // with no time can only honestly be recorded as an absence.
        if ($in === null && !in_array($status, ['absent', 'cancelled'], true)) $status = 'absent';
        $T['instructor_clockins'][] = ['tenant_id' => $tenantId, 'session_id' => $sess, 'membership_id' => $mid,
            'role' => 'instructor', 'clocked_in_at' => $in, 'clocked_out_at' => $out,
            'minutes_late' => max(0, mam_v2_plan_int($ci['minutes_late'] ?? null) ?? 0),
            // Derived once here and STORED, because v1 stored neither and the
            // pair of timestamps is the only place it can come from.
            'minutes_present' => ($in !== null && $out !== null)
                ? (int) max(0, round((strtotime($out) - strtotime($in)) / 60)) : null,
            'status' => $status,
            'source' => in_array((string) ($ci['source'] ?? 'portal'), ['portal', 'admin', 'api'], true)
                ? (string) $ci['source'] : 'portal',
            'note' => null,
            'created_at' => mam_v2_plan_ts($ci['created_at'] ?? null, $nowFallback)];
        $ciOut++;
    }
    $note('instructor_clockins', 'instructor_clockins',
        '§11\'s official record of whether a lesson was taught. minutes_present is derived once from the two '
        . 'timestamps and STORED, because v1 stored neither.',
        count($rows('instructor_clockins')), $ciOut);

    $ifId = 0;
    foreach ($rows('instructor_fines') as $f) {
        $v1id = (int) ($f['id'] ?? 0);
        $mid = $memberOfV1User[(int) ($f['instructor_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'instructor_fines', 'id' => $v1id, 'why' => 'instructor did not map to a membership']; continue; }
        // v1 kept kobo in one column and USD cents in another, so a sum over the
        // table depended on which column the reader picked. The CHARGE currency
        // is what the academy stated; the base amount and rate are carried only
        // where they were actually recorded.
        $currency = strtoupper(mam_v2_plan_str($f['currency'] ?? null, 3) ?? 'NGN');
        $amount = max(0, mam_v2_plan_int($f['amount_kobo'] ?? null) ?? 0);
        $fx = is_numeric($f['fx_rate_usd_ngn'] ?? null) ? (float) $f['fx_rate_usd_ngn'] : null;
        $baseAmount = null; $fxRate = null; $fxSource = null;
        if ($currency !== MAM_V2_BASE_CURRENCY) {
            $usd = mam_v2_plan_int($f['amount_usd_cents'] ?? null);
            if ($usd === null && ($fx === null || $fx <= 0)) {
                $rejected[] = ['table' => 'instructor_fines', 'id' => $v1id,
                    'why' => 'a ' . $currency . ' charge with no recorded rate into ' . MAM_V2_BASE_CURRENCY];
                continue;
            }
            $fxRate = number_format($fx ?? ($usd > 0 ? $amount / $usd : 1), 8, '.', '');
            $baseAmount = (int) round($amount * (float) $fxRate);
            $fxSource = 'v1_fine';
        }
        $status = (string) ($f['status'] ?? 'pending');
        $vocab = ['pending' => 'pending', 'resolved' => 'upheld', 'upheld' => 'upheld',
                'waived' => 'waived', 'cancelled' => 'cancelled', 'disputed' => 'disputed'];
        $status = $vocab[$status] ?? 'pending';
        $resBy = $userOfV1User[(int) ($f['resolved_by'] ?? 0)] ?? null;
        $resAt = mam_v2_plan_ts($f['resolved_at'] ?? null, null);
        $resNote = mam_v2_plan_str($f['resolution_note'] ?? null, 1000);
        // chk_ifine_resolved / chk_ifine_waived: a resolution is a named person
        // on a stated date, and a waiver says why. A charge missing any of that
        // is carried as still PENDING — an unaccountable waiver of a colleague's
        // money is worse than an open question.
        if (!in_array($status, ['pending', 'disputed'], true) && ($resBy === null || $resAt === null)) {
            $flag('fine_resolution_incomplete', ['table' => 'instructor_fines', 'id' => $v1id, 'was' => $status,
                'action' => 'The resolution named no decider or no date. The charge is carried as pending.']);
            $status = 'pending';
        }
        if (in_array($status, ['waived', 'cancelled'], true) && $resNote === null) {
            $resNote = 'Reason not recorded in the previous system';
        }
        $id = ++$ifId;
        $T['instructor_fines'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('ifine:' . $v1id),
            'tenant_id' => $tenantId, 'membership_id' => $mid,
            'session_id' => $sessIdOf[(int) ($f['session_id'] ?? 0)] ?? null,
            'reason' => mam_v2_plan_str($f['reason'] ?? null, 48) ?? 'late_progress_report',
            'amount_minor' => $amount, 'currency' => $currency,
            'base_currency' => MAM_V2_BASE_CURRENCY, 'base_amount_minor' => $baseAmount,
            'fx_rate' => $fxRate, 'fx_source' => $fxSource,
            'status' => $status, 'note' => null,
            'resolved_by' => in_array($status, ['pending', 'disputed'], true) ? null : $resBy,
            'resolved_at' => in_array($status, ['pending', 'disputed'], true) ? null : $resAt,
            'resolution_note' => $resNote,
            'created_at' => mam_v2_plan_ts($f['created_at'] ?? null, $nowFallback), 'created_by' => null];
        $mapped('instructor_fines', $v1id, 'instructor_fines', $id);
    }
    $note('instructor_fines', 'instructor_fines',
        'a RECORD of a charge, with nothing linking it to pay: Labour Act s.5(1) bars deducting a fine from '
        . 'wages without an authorised labour officer\'s written consent, so the deduction is not this '
        . 'system\'s to make. A resolution with no decider or date is carried as pending.',
        count($rows('instructor_fines')), $ifId);

    $fiId = 0; $fiIdOf = [];
    foreach ($rows('faculty_invites') as $fi) {
        $v1id = (int) ($fi['id'] ?? 0);
        $email = mam_v2_plan_str($fi['email'] ?? null, 320);
        if ($email === null) { $rejected[] = ['table' => 'faculty_invites', 'id' => $v1id, 'why' => 'no address to invite']; continue; }
        $status = (string) ($fi['status'] ?? 'requested');
        $vocab = ['requested' => 'requested', 'approved' => 'approved', 'invited' => 'sent', 'sent' => 'sent',
                'used' => 'used', 'expired' => 'expired', 'declined' => 'declined'];
        $status = $vocab[$status] ?? 'requested';
        // The NONCE is deliberately not carried, in any form. v1 stored it in
        // the clear; a live invitation link is a credential, and re-issuing one
        // costs an email while migrating a table of them extends every window
        // that should have closed. A sent invitation therefore lands as
        // APPROVED — approved is true, and it is what the admin acts on.
        if (in_array($status, ['sent', 'used'], true) && $status === 'sent') $status = 'approved';
        $id = ++$fiId;
        $T['faculty_invites'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('finvite:' . $v1id),
            'tenant_id' => $tenantId, 'email' => $email,
            'name' => mam_v2_plan_str($fi['name'] ?? null, 190),
            'source' => in_array((string) ($fi['source'] ?? 'contact'), ['contact', 'referral', 'admin', 'event'], true)
                ? (string) $fi['source'] : 'contact',
            'status' => $status,
            'nonce_hash' => null, 'expires_at' => null,
            'invited_by' => $userOfV1User[(int) ($fi['invited_by'] ?? 0)] ?? null,
            'invited_at' => $status === 'used' ? mam_v2_plan_ts($fi['invited_at'] ?? null, $nowFallback) : null,
            'used_at' => $status === 'used' ? mam_v2_plan_ts($fi['used_at'] ?? null, $nowFallback) : null,
            'note' => null,
            'created_at' => mam_v2_plan_ts($fi['created_at'] ?? null, $nowFallback)];
        $fiIdOf[$v1id] = $id;
        $mapped('faculty_invites', $v1id, 'faculty_invites', $id, $email);
    }
    $note('faculty_invites', 'faculty_invites',
        'the REQUEST is carried; the nonce is not, in any form. A live invitation link is a credential, and '
        . 're-issuing one costs an email while migrating a table of them extends every window that should '
        . 'have closed. An unused "sent" invitation lands as approved, which is what an admin acts on.',
        count($rows('faculty_invites')), $fiId);

    $iaOut = 0;
    foreach ($rows('instructor_applications') as $ia) {
        $v1id = (int) ($ia['id'] ?? 0);
        $email = mam_v2_plan_str($ia['email'] ?? null, 320);
        if ($email === null) { $rejected[] = ['table' => 'instructor_applications', 'id' => $v1id, 'why' => 'no address on the application']; continue; }
        $status = (string) ($ia['status'] ?? 'submitted');
        $vocab = ['submitted' => 'submitted', 'screening' => 'screening', 'reviewing' => 'screening',
                'interview' => 'interview', 'offered' => 'offered', 'appointed' => 'appointed',
                'approved' => 'appointed', 'rejected' => 'declined', 'declined' => 'declined',
                'withdrawn' => 'withdrawn'];
        $status = $vocab[$status] ?? 'submitted';
        // A candidate's account, if they were appointed and it exists. Without
        // it 'appointed' is refused by chk_iapp_appointed, so the honest landing
        // is 'offered': the academy decided, and the account is the missing half.
        $mid = null;
        foreach ($rows('users') as $u) {
            if (strcasecmp((string) ($u['email'] ?? ''), $email) === 0) {
                $mid = $memberOfV1User[(int) ($u['id'] ?? 0)] ?? null; break;
            }
        }
        if ($status === 'appointed' && $mid === null) {
            $flag('instructor_appointed_no_account', ['table' => 'instructor_applications', 'id' => $v1id,
                'email' => $email, 'action' => 'The application says appointed but no account matches the '
                    . 'address. Carried as offered; create the membership and re-record the appointment.']);
            $status = 'offered';
        }
        $decided = !in_array($status, ['submitted', 'screening', 'interview'], true);
        $T['instructor_applications'][] = ['public_id' => mam_v2_plan_pid('iapp:' . $v1id),
            'tenant_id' => $tenantId, 'invite_id' => null, 'email' => $email,
            'full_name' => mam_v2_plan_str($ia['full_name'] ?? null, 190),
            'phone' => mam_v2_plan_str($ia['phone'] ?? null, 32),
            'disciplines' => mam_v2_plan_str($ia['disciplines'] ?? null, 500),
            'experience_years' => mam_v2_plan_int($ia['experience_years'] ?? null),
            'bio' => mam_v2_plan_str($ia['bio'] ?? null),
            'links' => mam_v2_plan_str($ia['links'] ?? null, 1000),
            'status' => $status, 'membership_id' => $status === 'appointed' ? $mid : null,
            // v1 recorded no decider and no date. Rather than refuse the row,
            // the decision is attributed to nobody and dated from the record —
            // and `chk_iapp_decided` needs both, so this is where they come from.
            'decided_by' => null,
            'decided_at' => $decided ? mam_v2_plan_ts($ia['created_at'] ?? null, $nowFallback) : null,
            'decision_note' => $decided ? 'Decision migrated from the previous system; decider not recorded.' : null,
            'source_ip' => mam_v2_plan_str($ia['ip'] ?? null, 45),
            'created_at' => mam_v2_plan_ts($ia['created_at'] ?? null, $nowFallback), 'deleted_seq' => 0];
        $iaOut++;
        $mapped('instructor_applications', $v1id, 'instructor_applications', $iaOut, $email);
    }
    $note('instructor_applications', 'instructor_applications (+ attachments for the CV and headshot)',
        'v1 recorded no decider on a decided application, so the decision is carried attributed to nobody '
        . 'and dated from the record rather than refused. An "appointed" application with no matching '
        . 'account lands as offered — the decision is real, the account is the missing half.',
        count($rows('instructor_applications')), $iaOut);

    $mrOut = 0;
    foreach ($rows('meeting_reconciliation') as $mr) {
        $sess = $sessIdOf[(int) ($mr['session_id'] ?? 0)] ?? null;
        if ($sess === null) { $rejected[] = ['table' => 'meeting_reconciliation', 'id' => $mr['id'] ?? null, 'why' => 'session did not map']; continue; }
        $subject = (string) ($mr['subject_type'] ?? 'student');
        $join  = mam_v2_plan_ts($mr['join_at'] ?? null, null);
        $leave = mam_v2_plan_ts($mr['leave_at'] ?? null, null);
        $mins  = mam_v2_plan_int($mr['minutes'] ?? null);
        // Google Reports emits only the LEAVE time and a duration. Where v1
        // holds a join time it was already derived by subtraction, and saying so
        // is the difference between evidence and a claim.
        $derived = ($join !== null && $leave !== null && $mins !== null) ? 1 : 0;
        if ($leave !== null && $join !== null && $leave < $join) { $join = null; $derived = 0; }
        $T['meeting_participants'][] = ['tenant_id' => $tenantId, 'session_id' => $sess,
            'subject_type' => match ($subject) {
                'student' => 'learner', 'instructor', 'staff' => 'staff',
                'guest' => 'guest', default => 'unknown' },
            'membership_id' => $memberOfV1User[(int) ($mr['subject_id'] ?? 0)] ?? null,
            'display_name' => mam_v2_plan_str($mr['display_name'] ?? null, 190),
            'joined_at' => $join, 'left_at' => $leave, 'minutes' => $mins,
            'joined_at_is_derived' => $derived,
            'provider' => in_array((string) ($mr['provider'] ?? 'meet'), ['meet', 'jitsi', 'zoom', 'manual'], true)
                ? (string) $mr['provider'] : 'meet',
            'source' => in_array((string) ($mr['source'] ?? 'import'), ['import', 'api', 'manual'], true)
                ? (string) $mr['source'] : 'import',
            'created_at' => mam_v2_plan_ts($mr['created_at'] ?? null, $nowFallback)];
        $mrOut++;
    }
    $note('meeting_reconciliation', 'meeting_participants',
        'corroboration beside the portal clock, never inside `attendance` — §11 makes the clock-in the '
        . 'official record. A join time derived by subtraction is flagged as derived.',
        count($rows('meeting_reconciliation')), $mrOut);

    $prOut = 0; $prSeen = [];
    foreach ($rows('progress_reports') as $pr) {
        $sess = $sessIdOf[(int) ($pr['session_id'] ?? 0)] ?? null;
        $mid  = $memberOfV1User[(int) ($pr['student_id'] ?? 0)] ?? null;
        $imid = $memberOfV1User[(int) ($pr['instructor_id'] ?? 0)] ?? null;
        if ($sess === null || $mid === null || $imid === null) {
            $rejected[] = ['table' => 'progress_reports', 'id' => $pr['id'] ?? null,
                'why' => $sess === null ? 'session did not map' : 'learner or instructor did not map to a membership'];
            continue;
        }
        $coh = null;
        foreach ($T['class_sessions'] as $row) if ((int) $row['id'] === $sess) { $coh = (int) $row['cohort_id']; break; }
        $enr = $coh !== null ? ($enrolsOfMember[$mid][$coh] ?? null) : null;
        if ($enr === null) {
            $rejected[] = ['table' => 'progress_reports', 'id' => $pr['id'] ?? null,
                'why' => 'the learner has no enrolment in the cohort this session belongs to'];
            continue;
        }
        $k = $sess . ':' . $enr;
        if (isset($prSeen[$k])) continue;
        $prSeen[$k] = true;
        $T['session_progress_reports'][] = ['public_id' => mam_v2_plan_pid('sprep:' . (int) ($pr['id'] ?? 0)),
            'tenant_id' => $tenantId, 'session_id' => $sess, 'enrolment_id' => $enr,
            'instructor_membership_id' => $imid,
            'answers' => mam_v2_plan_str($pr['answers_json'] ?? null),
            'narrative' => mam_v2_plan_str($pr['narrative'] ?? null),
            // v1 stored no deadline, so lateness cannot be reconstructed: the
            // two-hour rule was applied at the time and the figure was never
            // written down. NULL says unknown; 0 would say "on time".
            'due_at' => null, 'minutes_late' => null,
            'submitted_at' => mam_v2_plan_ts($pr['submitted_at'] ?? null, $nowFallback),
            'sent_at' => mam_v2_plan_bool($pr['email_sent'] ?? null, 0) === 1
                ? mam_v2_plan_ts($pr['email_sent_at'] ?? null, $nowFallback) : null,
            'created_at' => mam_v2_plan_ts($pr['submitted_at'] ?? null, $nowFallback), 'deleted_seq' => 0];
        $prOut++;
    }
    $note('progress_reports', 'session_progress_reports',
        'the two-hour note about ONE lesson — a different document from the board-ratified termly report, '
        . 'which is why the table is not called `progress_reports`. due_at and minutes_late are NULL: v1 '
        . 'never wrote them, and 0 would claim every migrated report was on time.',
        count($rows('progress_reports')), $prOut);

    $wlOut = 0; $wlSeen = [];
    foreach ($rows('class_waitlist') as $w) {
        $mid = $memberOfV1User[(int) ($w['student_id'] ?? 0)] ?? null;
        $coh = $cohIdOfV1[(int) ($w['cohort_id'] ?? 0)] ?? null;
        if ($mid === null || $coh === null) {
            $rejected[] = ['table' => 'class_waitlist', 'id' => $w['id'] ?? null,
                'why' => $mid === null ? 'learner did not map to a membership' : 'cohort did not map'];
            continue;
        }
        $k = $coh . ':' . $mid;
        if (isset($wlSeen[$k])) continue;
        $wlSeen[$k] = true;
        $status = (string) ($w['status'] ?? 'waiting');
        $vocab = ['waiting' => 'waiting', 'offered' => 'offered', 'notified' => 'offered',
                'promoted' => 'promoted', 'enrolled' => 'promoted', 'declined' => 'declined',
                'expired' => 'expired', 'withdrawn' => 'withdrawn', 'removed' => 'withdrawn'];
        $status = $vocab[$status] ?? 'waiting';
        $notified = mam_v2_plan_ts($w['notified_at'] ?? null, null);
        $enr = $enrolsOfMember[$mid][$coh] ?? null;
        // chk_waitlist_promoted / _offered. v1 marked entries promoted while
        // enrolling nobody, and never wrote notified_at at all — so a promotion
        // with no enrolment behind it, or an offer nobody was told about, cannot
        // be carried as either. Both fall back to WAITING, which is what the
        // family's position actually was.
        if ($status === 'promoted' && ($enr === null || $notified === null)) {
            $flag('waitlist_promotion_unevidenced', ['table' => 'class_waitlist', 'id' => $w['id'] ?? null,
                'action' => 'Marked promoted with ' . ($enr === null ? 'no enrolment behind it' : 'no record of the family being told')
                    . '. Carried as waiting; re-run the promotion.']);
            $status = 'waiting';
        }
        if ($status === 'offered' && $notified === null) {
            $flag('waitlist_offer_unnotified', ['table' => 'class_waitlist', 'id' => $w['id'] ?? null,
                'action' => 'A seat was offered with no record of the family being told. Carried as waiting.']);
            $status = 'waiting';
        }
        $T['cohort_waitlist'][] = ['tenant_id' => $tenantId, 'cohort_id' => $coh, 'membership_id' => $mid,
            'position' => max(1, mam_v2_plan_int($w['position'] ?? null) ?? 1),
            'status' => $status, 'notified_at' => $notified, 'offer_expires_at' => null,
            'promoted_enrolment_id' => $status === 'promoted' ? $enr : null,
            'created_at' => mam_v2_plan_ts($w['created_at'] ?? null, $nowFallback), 'deleted_seq' => 0];
        $wlOut++;
    }
    $note('class_waitlist', 'cohort_waitlist',
        'an entry v1 marked promoted with no enrolment behind it — or offered with no record of the family '
        . 'being told — is carried as WAITING, which is what their position actually was.',
        count($rows('class_waitlist')), $wlOut);

    // v1's class_resources dual-read both enrolment models (class_id OR
    // cohort_id); in v2 there is one spine, so both resolve to a cohort. Its
    // `resource_versions` become superseding ROWS, the idiom `rubrics` uses.
    $lrId = 0; $lrIdOf = [];
    foreach ($rows('class_resources') as $r) {
        $v1id = (int) ($r['id'] ?? 0);
        $coh = $cohIdOfV1[(int) ($r['cohort_id'] ?? 0)] ?? ($cohIdOfClass[(int) ($r['class_id'] ?? 0)] ?? null);
        $url = mam_v2_plan_str($r['url'] ?? null, 1000);
        if ($coh === null || $url === null) {
            $rejected[] = ['table' => 'class_resources', 'id' => $v1id,
                'why' => $coh === null ? 'neither the class nor the cohort mapped'
                    : 'the resource has no URL and no stored file, so nothing could be opened'];
            continue;
        }
        $id = ++$lrId;
        $T['learning_resources'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('resource:' . $v1id),
            'tenant_id' => $tenantId, 'cohort_id' => $coh,
            'course_id' => $cohCourseOf[$coh] ?? null, 'module_id' => null,
            'title' => mam_v2_plan_str($r['title'] ?? null, 190) ?? 'Resource',
            'kind' => mam_v2_plan_str($r['type'] ?? null, 24) ?? 'link',
            'url' => $url, 'attachment_id' => null, 'visibility' => 'cohort',
            'version' => 1, 'supersedes_id' => null, 'note' => null,
            'posted_by' => $userOfV1User[(int) ($r['posted_by'] ?? 0)] ?? null,
            'created_at' => mam_v2_plan_ts($r['created_at'] ?? null, $nowFallback), 'deleted_seq' => 0];
        $lrIdOf[$v1id] = $id;
        $mapped('class_resources', $v1id, 'learning_resources', $id);
    }
    $rvOut = 0;
    foreach ($rows('resource_versions') as $rv) {
        $base = $lrIdOf[(int) ($rv['resource_id'] ?? 0)] ?? null;
        $url = mam_v2_plan_str($rv['url'] ?? null, 1000);
        if ($base === null || $url === null) {
            $rejected[] = ['table' => 'resource_versions', 'id' => $rv['id'] ?? null,
                'why' => $base === null ? 'the resource did not map' : 'the version has no URL'];
            continue;
        }
        $parent = null;
        foreach ($T['learning_resources'] as $row) if ((int) $row['id'] === $base) { $parent = $row; break; }
        $ver = max(2, mam_v2_plan_int($rv['version'] ?? null) ?? 2);
        $id = ++$lrId;
        $T['learning_resources'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('resourcever:' . (int) ($rv['id'] ?? 0)),
            'tenant_id' => $tenantId, 'cohort_id' => $parent['cohort_id'] ?? null,
            'course_id' => $parent['course_id'] ?? null, 'module_id' => null,
            'title' => mam_v2_plan_str($rv['title'] ?? null, 190) ?? (string) ($parent['title'] ?? 'Resource'),
            'kind' => (string) ($parent['kind'] ?? 'link'), 'url' => $url, 'attachment_id' => null,
            'visibility' => 'cohort', 'version' => $ver, 'supersedes_id' => $base,
            'note' => mam_v2_plan_str($rv['note'] ?? null, 500),
            'posted_by' => $userOfV1User[(int) ($rv['created_by'] ?? 0)] ?? null,
            'created_at' => mam_v2_plan_ts($rv['created_at'] ?? null, $nowFallback), 'deleted_seq' => 0];
        $rvOut++;
        $mapped('resource_versions', (int) ($rv['id'] ?? 0), 'learning_resources', $id);
    }
    $note('class_resources', 'learning_resources',
        'both v1 enrolment models resolve to the one cohort spine.', count($rows('class_resources')), $lrId - $rvOut);
    $note('resource_versions', 'learning_resources (superseding rows)',
        'a new version is a NEW ROW pointing at the one it replaces — the idiom `rubrics` uses — so a '
        . 'learner given version 1 can still be shown what they were given.',
        count($rows('resource_versions')), $rvOut);

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

    $appId = 0; $appIdOfV1 = []; $appPersonOf = []; $appIdOfRef = [];
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
        // Reference → v2 id. v1's scholarship applications name an application by
        // the REFERENCE the applicant quoted, not by an id.
        $refStr = mam_v2_plan_str($r['reference'] ?? null, 64);
        if ($refStr !== null) $appIdOfRef[mb_strtolower($refStr)] = $id;
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
    // The human reference on a receipt → the v2 payment. Built here because
    // several later tables (a reschedule fee, an instalment) name a payment by
    // the string a family quotes rather than by an id.
    $payRefIndex = [];
    foreach ($map as $m) {
        if ($m['v2_table'] === 'payments' && $m['legacy_ref'] !== null && $m['legacy_ref'] !== '') {
            $payRefIndex[mb_strtolower((string) $m['legacy_ref'])] = (int) $m['v2_id'];
        }
    }
    $mapRefIndex = function (string $ref) use ($payRefIndex): array {
        $hit = $payRefIndex[mb_strtolower($ref)] ?? null;
        return $hit === null ? [] : [$hit];
    };

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

    /* ── Moving a lesson: priced by the academy, decided by a human ───────── */
    // AFTER payments, because a request that was paid names the payment that
    // settled it, and that id has to be a v2 id.
    $rrId = 0; $rrIdOf = [];
    foreach ($rows('reschedule_requests') as $rr) {
        $v1id = (int) ($rr['id'] ?? 0);
        $mid = $memberOfV1User[(int) ($rr['requester_id'] ?? 0)] ?? null;
        $coh = $cohIdOfV1[(int) ($rr['cohort_id'] ?? 0)] ?? null;
        if ($mid === null || $coh === null) {
            $rejected[] = ['table' => 'reschedule_requests', 'id' => $v1id,
                'why' => $mid === null ? 'requester did not map to a membership' : 'cohort did not map'];
            continue;
        }
        $status = (string) ($rr['status'] ?? 'pending_payment');
        $vocab = ['pending_payment' => 'pending_payment', 'pending_approval' => 'pending_approval',
                'pending' => 'pending_approval', 'approved' => 'approved', 'declined' => 'declined',
                'rejected' => 'declined', 'applied' => 'applied', 'expired' => 'expired',
                'cancelled' => 'cancelled'];
        $status = $vocab[$status] ?? 'pending_payment';
        // v1 priced in USD cents with a rate to naira beside it. The charge
        // currency is what the family was quoted; the base amount and rate are
        // required by CHECK whenever they differ.
        $currency = strtoupper(mam_v2_plan_str($rr['fee_currency'] ?? null, 3) ?? 'USD');
        $fee = max(0, mam_v2_plan_int($rr['fee_usd_cents'] ?? null) ?? 0);
        $fx = is_numeric($rr['fx_rate_usd_ngn'] ?? null) ? (float) $rr['fx_rate_usd_ngn'] : null;
        $baseFee = null; $fxRate = null;
        if ($currency !== MAM_V2_BASE_CURRENCY) {
            if ($fx === null || $fx <= 0) {
                if ($fee > 0) {
                    $rejected[] = ['table' => 'reschedule_requests', 'id' => $v1id,
                        'why' => 'a ' . $currency . ' fee with no recorded rate into ' . MAM_V2_BASE_CURRENCY
                            . '; converting it now would restate what the family was quoted'];
                    continue;
                }
                // A zero fee needs no rate: nothing was charged in either
                // currency, so there is nothing to restate.
                $currency = MAM_V2_BASE_CURRENCY;
            } else {
                $fxRate = number_format($fx, 8, '.', '');
                $baseFee = (int) round($fee * $fx);
            }
        }
        $waived = mam_v2_plan_bool($rr['waived'] ?? null, 0);
        $waivedReason = mam_v2_plan_str($rr['waived_reason'] ?? null, 500);
        if ($waived === 1 && $waivedReason === null) $waivedReason = 'Reason not recorded in the previous system';
        // v1 kept a payment REFERENCE, not an id. It resolves through the
        // payment ledger's own human reference — the string on the receipt —
        // rather than by matching an amount and a date, which is how money goes
        // missing.
        $payRef = mam_v2_plan_str($rr['payment_ref'] ?? null, 64);
        $pay = null;
        if ($payRef !== null) {
            $hit = $mapRefIndex($payRef);
            $pay = $hit ? (int) $hit[0] : null;
        }
        $paidAt = mam_v2_plan_ts($rr['paid_at'] ?? null, null);
        // chk_resch_paid: a fee marked paid names its payment, unless it was
        // waived. Where the reference resolved to nothing the payment is the
        // missing half, so the paid date is dropped and the request is raised
        // rather than carried as settled by nobody.
        if ($paidAt !== null && $pay === null && $waived === 0) {
            $flag('reschedule_payment_unmatched', ['table' => 'reschedule_requests', 'id' => $v1id,
                'reference' => $payRef,
                'action' => 'The fee is marked paid but its reference matches no payment in the ledger. '
                    . 'Carried as unpaid; match it by hand before the family is asked again.']);
            $paidAt = null;
        }
        $decBy = $userOfV1User[(int) ($rr['decided_by'] ?? 0)] ?? null;
        $decAt = mam_v2_plan_ts($rr['decided_at'] ?? null, null);
        $decNote = mam_v2_plan_str($rr['decision_note'] ?? null, 1000);
        // chk_resch_decided / _declined: a decision is a named person on a
        // stated date, and a refusal says why — the family paid a fee to ask.
        if (!in_array($status, ['pending_payment', 'pending_approval', 'expired', 'cancelled'], true)
            && ($decBy === null || $decAt === null)) {
            $flag('reschedule_decision_incomplete', ['table' => 'reschedule_requests', 'id' => $v1id,
                'was' => $status,
                'action' => 'The decision named no decider or no date. Carried as pending approval.']);
            $status = 'pending_approval';
        }
        if ($status === 'declined' && $decNote === null) $decNote = 'Reason not recorded in the previous system';
        $applied = mam_v2_plan_ts($rr['applied_at'] ?? null, null);
        if ($status === 'applied' && $applied === null) $applied = $decAt;
        $id = ++$rrId;
        $T['reschedule_requests'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('reschedule:' . $v1id),
            'tenant_id' => $tenantId, 'requester_membership_id' => $mid,
            'enrolment_id' => $enrolsOfMember[$mid][$coh] ?? null, 'cohort_id' => $coh,
            'status' => $status,
            'reason' => mam_v2_plan_str($rr['reason'] ?? null, 1000),
            'session_count' => max(0, mam_v2_plan_int($rr['session_count'] ?? null) ?? 0),
            'fee_minor' => $fee, 'currency' => $currency, 'base_currency' => MAM_V2_BASE_CURRENCY,
            'base_fee_minor' => $baseFee, 'fx_rate' => $fxRate,
            'is_waived' => $waived, 'waived_reason' => $waived === 1 ? $waivedReason : null,
            'payment_id' => $paidAt !== null ? $pay : null, 'paid_at' => $paidAt,
            'decided_by' => $decBy, 'decided_at' => $decAt,
            'decision_note' => $decNote,
            'applied_at' => $status === 'applied' ? $applied : null,
            'expires_at' => mam_v2_plan_ts($rr['expires_at'] ?? null, null),
            'created_at' => mam_v2_plan_ts($rr['created_at'] ?? null, $nowFallback)];
        $rrIdOf[$v1id] = $id;
        $mapped('reschedule_requests', $v1id, 'reschedule_requests', $id, $payRef);
    }
    $note('reschedule_requests', 'reschedule_requests',
        'the fee keeps the currency the family was quoted in, with the frozen rate beside it. A payment is '
        . 'matched through the ledger\'s own human reference, never by amount and date; an unmatched one '
        . 'reads as UNPAID and is raised.',
        count($rows('reschedule_requests')), $rrId);

    $riOut = 0; $riSeen = [];
    foreach ($rows('reschedule_items') as $ri) {
        $req = $rrIdOf[(int) ($ri['request_id'] ?? 0)] ?? null;
        $sess = $sessIdOf[(int) ($ri['session_id'] ?? 0)] ?? null;
        $newAt = mam_v2_plan_ts($ri['new_scheduled_at'] ?? null, null);
        if ($req === null || $sess === null || $newAt === null) {
            $rejected[] = ['table' => 'reschedule_items', 'id' => $ri['id'] ?? null,
                'why' => $req === null ? 'request did not map'
                    : ($sess === null ? 'session did not map' : 'no new time to move it to')];
            continue;
        }
        $k = $req . ':' . $sess;
        if (isset($riSeen[$k])) continue;
        $riSeen[$k] = true;
        $appliedFlag = mam_v2_plan_bool($ri['applied'] ?? null, 0);
        $T['reschedule_items'][] = ['tenant_id' => $tenantId, 'request_id' => $req, 'session_id' => $sess,
            // The OLD time is kept: "what was it before" is the first question
            // asked when a family says a lesson moved and nobody told them.
            'old_scheduled_at' => mam_v2_plan_ts($ri['old_scheduled_at'] ?? null, null),
            'new_scheduled_at' => $newAt, 'timezone' => $sessTz,
            'is_applied' => $appliedFlag,
            // chk_reschitem_applied: an applied move states when. v1 kept only
            // the flag, so the request's own applied_at is the honest source.
            'applied_at' => $appliedFlag === 1
                ? (mam_v2_plan_ts($ri['created_at'] ?? null, $nowFallback)) : null,
            'created_at' => mam_v2_plan_ts($ri['created_at'] ?? null, $nowFallback)];
        $riOut++;
    }
    $note('reschedule_items', 'reschedule_items',
        'one session per row, keeping the time it was moved FROM.', count($rows('reschedule_items')), $riOut);
    $note('reschedule_events', 'lifecycle_events',
        'v2 has one stage machine. A reschedule request moving from pending to approved is the same shape of '
        . 'fact as an application moving from submitted to enrolled, so it does not get a table of its own.',
        count($rows('reschedule_events')));

    /* ── Communications ───────────────────────────────────────────────────── */
    $annOut = 0;
    foreach ($rows('announcements') as $an) {
        $coh = $cohIdOfV1[(int) ($an['cohort_id'] ?? 0)] ?? ($cohIdOfClass[(int) ($an['class_id'] ?? 0)] ?? null);
        // chk_ann_target: a scope has to name what it is scoped TO. v1 allowed
        // BOTH ids to be NULL, which made an announcement with no readers — so
        // one that resolves to no cohort becomes a TENANT-wide notice, which is
        // the only honest reading of "everybody saw this".
        $scope = $coh !== null ? 'cohort' : 'tenant';
        $T['announcements'][] = ['public_id' => mam_v2_plan_pid('announcement:' . (int) ($an['id'] ?? 0)),
            'tenant_id' => $tenantId, 'cohort_id' => $coh,
            'course_id' => $coh !== null ? ($cohCourseOf[$coh] ?? null) : null,
            'centre_id' => null, 'scope' => $scope,
            // v1 had no audience column and the portals showed announcements to
            // learners AND guardians, so 'both' is what actually happened.
            'audience' => 'both',
            'title' => mam_v2_plan_str($an['title'] ?? null, 190),
            'body' => (string) ($an['body'] ?? ''),
            'author_membership_id' => $memberOfV1User[(int) ($an['author_id'] ?? 0)] ?? null,
            'publish_at' => mam_v2_plan_ts($an['created_at'] ?? null, $nowFallback),
            'expires_at' => null, 'is_pinned' => 0,
            'created_at' => mam_v2_plan_ts($an['created_at'] ?? null, $nowFallback), 'deleted_seq' => 0];
        $annOut++;
    }
    $note('announcements', 'announcements',
        'both v1 enrolment models resolve to the one cohort spine; one that resolves to neither becomes a '
        . 'TENANT-wide notice rather than an announcement with no readers.',
        count($rows('announcements')), $annOut);

    $cmOut = 0; $cmDropped = 0;
    foreach ($rows('messages') as $m) {
        $email = mam_v2_plan_str($m['email'] ?? null, 320);
        $phone = mam_v2_plan_str($m['phone'] ?? null, 32);
        // chk_cmsg_reachable. v1 accepted a message with no address and no phone
        // number — a complaint nobody could answer, sitting in the queue looking
        // actionable. Those rows are DROPPED rather than carried: there is
        // nothing to do with them and they are somebody's personal data.
        if ($email === null && $phone === null) {
            $cmDropped++;
            $rejected[] = ['table' => 'messages', 'id' => $m['id'] ?? null,
                'why' => 'no address and no phone number: nobody could ever have replied to it'];
            continue;
        }
        $status = (string) ($m['status'] ?? 'unread');
        $vocab = ['unread' => 'unread', 'read' => 'read', 'answered' => 'answered', 'replied' => 'answered',
                  'spam' => 'spam', 'closed' => 'closed', 'archived' => 'closed'];
        $status = $vocab[$status] ?? 'unread';
        $created = mam_v2_plan_ts($m['created_at'] ?? null, $nowFallback);
        // chk_cmsg_answered: v1 recorded no answer time, so "answered" would have
        // to be dated from the record. Rather than invent the moment somebody
        // replied, it is dated from the message and said so in the ledger.
        $T['contact_messages'][] = ['public_id' => mam_v2_plan_pid('contactmsg:' . (int) ($m['id'] ?? 0), $created),
            'tenant_id' => $tenantId,
            'name' => mam_v2_plan_str($m['name'] ?? null, 190),
            'email' => $email, 'phone' => $phone,
            'topic' => mam_v2_plan_str($m['topic'] ?? null, 48),
            'body' => (string) ($m['message'] ?? ''),
            'status' => $status,
            // NOT matched to a membership by address. Two people share a family
            // inbox and one of them is a child; linking an enquiry to the wrong
            // person attaches a stranger's words to a learner's record.
            'membership_id' => null,
            'answered_by' => null,
            'answered_at' => $status === 'answered' ? $created : null,
            'source_ip' => mam_v2_plan_str($m['ip'] ?? null, 45),
            'user_agent' => null, 'created_at' => $created];
        $cmOut++;
    }
    $note('messages', 'contact_messages',
        sprintf('the public contact form — a different lawful basis, retention and audience from the '
            . 'guardian threads, which is why the name is not `messages`. %d row(s) with no address and no '
            . 'phone number are dropped: nobody could ever have replied, and they are still somebody\'s '
            . 'personal data. An "answered" message is dated from the message itself, because v1 recorded '
            . 'no reply time.', $cmDropped),
        count($rows('messages')), $cmOut);

    $tkId = 0; $tkIdOf = [];
    foreach ($rows('support_tickets') as $t) {
        $v1id = (int) ($t['id'] ?? 0);
        $mid = $memberOfV1User[(int) ($t['student_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'support_tickets', 'id' => $v1id, 'why' => 'the person who raised it did not map to a membership']; continue; }
        $status = (string) ($t['status'] ?? 'open');
        $vocab = ['open' => 'open', 'pending' => 'waiting_on_them', 'waiting' => 'waiting_on_them',
                  'in_progress' => 'waiting_on_us', 'resolved' => 'resolved', 'closed' => 'closed'];
        $status = $vocab[$status] ?? 'open';
        $resolved = mam_v2_plan_ts($t['resolved_at'] ?? null, null);
        // chk_ticket_resolved: a resolved ticket states when. Without a date it
        // is still open, which is what the family experienced.
        if (in_array($status, ['resolved', 'closed'], true) && $resolved === null) $status = 'open';
        $priority = (string) ($t['priority'] ?? 'normal');
        $id = ++$tkId;
        $T['support_tickets'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('ticket:' . $v1id),
            'tenant_id' => $tenantId, 'raised_by_membership_id' => $mid, 'subject_membership_id' => $mid,
            'reference' => mam_v2_plan_str($t['reference'] ?? null, 64) ?? ('TKT-' . str_pad((string) $v1id, 6, '0', STR_PAD_LEFT)),
            'category' => mam_v2_plan_str($t['category'] ?? null, 32) ?? 'general',
            'subject' => mam_v2_plan_str($t['subject'] ?? null, 190) ?? '(no subject)',
            'status' => $status,
            'priority' => in_array($priority, ['low', 'normal', 'high', 'urgent'], true) ? $priority : 'normal',
            'assigned_to_membership_id' => $memberOfV1User[(int) ($t['assigned_to'] ?? 0)] ?? null,
            // v1 kept no response target and no first-response time, so neither
            // can be reconstructed. NULL says unknown; a computed value would
            // claim the academy met a target it never recorded.
            'first_response_due_at' => null, 'first_response_at' => null,
            'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? $resolved : null,
            'closed_at' => $status === 'closed' ? $resolved : null,
            'created_at' => mam_v2_plan_ts($t['created_at'] ?? null, $nowFallback)];
        $tkIdOf[$v1id] = $id;
        $mapped('support_tickets', $v1id, 'support_tickets', $id, (string) ($t['reference'] ?? ''));
        // v1's ticket BODY is the first message. Carrying it as a column would
        // leave one thread whose opening post is somewhere else.
        $T['ticket_messages'][] = ['tenant_id' => $tenantId, 'ticket_id' => $id,
            'author_membership_id' => $mid,
            'author_name' => (string) ($peopleNameOfMember[$mid] ?? 'The family'),
            'body' => (string) ($t['body'] ?? ''), 'visibility' => 'family',
            'created_at' => mam_v2_plan_ts($t['created_at'] ?? null, $nowFallback)];
    }
    $note('support_tickets', 'support_tickets (+ the opening ticket_message)',
        'v1 kept the first message as a column on the ticket; carrying it that way would leave one thread '
        . 'whose opening post is somewhere else. No response target is invented — v1 recorded none, and a '
        . 'computed one would claim the academy met a target it never had.',
        count($rows('support_tickets')), $tkId);

    $tmOut2 = 0;
    foreach ($rows('ticket_messages') as $m) {
        $tk = $tkIdOf[(int) ($m['ticket_id'] ?? 0)] ?? null;
        if ($tk === null) { $rejected[] = ['table' => 'ticket_messages', 'id' => $m['id'] ?? null, 'why' => 'ticket did not map']; continue; }
        $mid = $memberOfV1User[(int) ($m['author_id'] ?? 0)] ?? null;
        $T['ticket_messages'][] = ['tenant_id' => $tenantId, 'ticket_id' => $tk,
            'author_membership_id' => $mid,
            'author_name' => (string) ($peopleNameOfMember[$mid] ?? 'MAM Academy'),
            'body' => (string) ($m['body'] ?? ''),
            // The one field that must not be guessed: an internal note the
            // family cannot see. v1 recorded it, so it is carried verbatim —
            // defaulting it to 'family' would disclose staff notes about a child.
            'visibility' => mam_v2_plan_bool($m['is_internal'] ?? null, 0) === 1 ? 'internal' : 'family',
            'created_at' => mam_v2_plan_ts($m['created_at'] ?? null, $nowFallback)];
        $tmOut2++;
    }
    $note('ticket_messages', 'ticket_messages',
        '`is_internal` is carried VERBATIM: defaulting it to family-visible would disclose staff notes '
        . 'about a child, and it is the one field in this layer that must never be guessed.',
        count($rows('ticket_messages')), $tmOut2);

    /* ── Surveys, and the anonymity v1 promised but did not keep ──────────── */
    $svId = 0; $svIdOf = []; $svAnon = [];
    foreach ($rows('surveys') as $sv) {
        $v1id = (int) ($sv['id'] ?? 0);
        $status = (string) ($sv['status'] ?? 'draft');
        $vocab = ['draft' => 'draft', 'open' => 'open', 'active' => 'open', 'closed' => 'closed',
                  'archived' => 'archived'];
        $id = ++$svId;
        // v1 had no `is_anonymous` on a survey at all — every response carried a
        // NOT NULL student_id. So no migrated survey can be presented as
        // anonymous: claiming it retroactively would be a promise about data that
        // was never collected that way.
        $T['surveys'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('survey:' . $v1id),
            'tenant_id' => $tenantId,
            'kind' => in_array((string) ($sv['kind'] ?? 'survey'), ['survey', 'form', 'progress_form', 'poll'], true)
                ? (string) $sv['kind'] : 'survey',
            'title' => mam_v2_plan_str($sv['title'] ?? null, 190) ?? 'Survey',
            'description' => mam_v2_plan_str($sv['description'] ?? null),
            'questions' => mam_v2_plan_str($sv['questions_json'] ?? null) ?? '[]',
            'status' => $vocab[$status] ?? 'draft',
            'audience' => 'learners',
            'target_course_id' => null, 'target_cohort_id' => null,
            'is_anonymous' => 0,
            'opens_at' => null,
            'closes_at' => mam_v2_plan_ts($sv['closes_at'] ?? null, null),
            'created_by' => $userOfV1User[(int) ($sv['created_by'] ?? 0)] ?? null,
            'created_at' => mam_v2_plan_ts($sv['created_at'] ?? null, $nowFallback), 'deleted_seq' => 0];
        $svIdOf[$v1id] = $id;
        $svAnon[$id] = false;
        $mapped('surveys', $v1id, 'surveys', $id);
    }
    $note('surveys', 'surveys',
        'every migrated survey is marked NOT anonymous, because v1 stored a NOT NULL student_id on every '
        . 'response. Claiming anonymity retroactively would be a promise about data that was never '
        . 'collected that way.', count($rows('surveys')), $svId);

    $srOut = 0; $srSeen = [];
    foreach ($rows('survey_responses') as $sr) {
        $sv = $svIdOf[(int) ($sr['survey_id'] ?? 0)] ?? null;
        $mid = $memberOfV1User[(int) ($sr['student_id'] ?? 0)] ?? null;
        if ($sv === null || $mid === null) {
            $rejected[] = ['table' => 'survey_responses', 'id' => $sr['id'] ?? null,
                'why' => $sv === null ? 'survey did not map' : 'respondent did not map to a membership'];
            continue;
        }
        $k = $sv . ':' . $mid;
        if (isset($srSeen[$k])) continue;
        $srSeen[$k] = true;
        $T['survey_responses'][] = ['tenant_id' => $tenantId, 'survey_id' => $sv, 'membership_id' => $mid,
            'respondent_hash' => null, 'is_anonymous' => 0,
            'answers' => mam_v2_plan_str($sr['answers_json'] ?? null) ?? '{}',
            'submitted_at' => mam_v2_plan_ts($sr['submitted_at'] ?? null, $nowFallback)];
        $srOut++;
    }
    $note('survey_responses', 'survey_responses',
        'attributed, matching how they were collected.', count($rows('survey_responses')), $srOut);

    // ⚠️ v1's `student_feedback` stored `is_anonymous` BESIDE `student_id`, so
    // feedback a learner was told was anonymous named them to anybody who could
    // read the table — which includes anybody with a backup. v2 has nowhere to
    // put the identity on an anonymous row, so the migration has to make the
    // promise true: the membership and the cohort are DROPPED (a cohort of nine
    // identifies its author), and the course is kept for context.
    $lfOut = 0; $lfAnon = 0;
    foreach ($rows('student_feedback') as $fb) {
        $mid = $memberOfV1User[(int) ($fb['student_id'] ?? 0)] ?? null;
        $anon = mam_v2_plan_bool($fb['is_anonymous'] ?? null, 0);
        if ($mid === null && $anon === 0) {
            $rejected[] = ['table' => 'student_feedback', 'id' => $fb['id'] ?? null,
                'why' => 'attributed feedback whose author did not map to a membership'];
            continue;
        }
        $enr = $enrOfCourseEnrol[(int) ($fb['course_enrollment_id'] ?? 0)] ?? null;
        $course = null;
        if ($enr !== null) {
            foreach ($T['enrolments'] as $e) {
                if ((int) $e['id'] === $enr) { $course = $cohCourseOf[(int) $e['cohort_id']] ?? null; break; }
            }
        }
        if ($anon === 1) $lfAnon++;
        $T['learner_feedback'][] = ['public_id' => mam_v2_plan_pid('feedback:' . (int) ($fb['id'] ?? 0)),
            'tenant_id' => $tenantId,
            'membership_id' => $anon === 1 ? null : $mid,
            'is_anonymous' => $anon,
            'course_id' => $course,
            'cohort_id' => null,
            'rating' => min(5, max(1, mam_v2_plan_int($fb['rating'] ?? null) ?? 3)),
            'category' => mam_v2_plan_str($fb['category'] ?? null, 32) ?? 'general',
            'subject' => mam_v2_plan_str($fb['subject'] ?? null, 190),
            'body' => (string) ($fb['body'] ?? ''),
            'status' => 'new', 'reviewed_by' => null, 'reviewed_at' => null,
            'created_at' => mam_v2_plan_ts($fb['created_at'] ?? null, $nowFallback)];
        $lfOut++;
    }
    $note('student_feedback', 'learner_feedback',
        sprintf('%d row(s) marked anonymous in v1 arrive with the author and the cohort REMOVED. v1 kept '
            . '`is_anonymous` beside `student_id`, so the promise was worth nothing to anybody with a '
            . 'backup; v2 has nowhere to put the identity, and this is where the promise is made true.',
            $lfAnon),
        count($rows('student_feedback')), $lfOut);
    $note('feedback', 'assessments + assessment_marks',
        'v1\'s per-submission `feedback` (a rubric blob and comments) is a MARK. One marking mechanism, not '
        . 'two — see LAYER M.', count($rows('feedback')));

    /* ── Admissions: notes, scholarships, placements ──────────────────────── */
    $anOut = 0;
    foreach ($rows('application_notes') as $n) {
        $app = $appIdOfV1[(int) ($n['application_id'] ?? 0)] ?? null;
        if ($app === null) { $rejected[] = ['table' => 'application_notes', 'id' => $n['id'] ?? null, 'why' => 'application did not map']; continue; }
        $mid = $memberOfV1User[(int) ($n['author_id'] ?? 0)] ?? null;
        $T['application_notes'][] = ['tenant_id' => $tenantId, 'application_id' => $app,
            'author_membership_id' => $mid,
            'author_name' => (string) ($peopleNameOfMember[$mid] ?? 'Admissions'),
            'note' => (string) ($n['note'] ?? ''),
            // INTERNAL, always. These are judgements about a child written for
            // colleagues; a migration must not be the moment they become
            // family-visible.
            'visibility' => 'internal',
            'created_at' => mam_v2_plan_ts($n['created_at'] ?? null, $nowFallback)];
        $anOut++;
    }
    $note('application_notes', 'application_notes',
        'carried as INTERNAL without exception: these are judgements about a child written for colleagues, '
        . 'and a migration must not be the moment they become family-visible.',
        count($rows('application_notes')), $anOut);

    $schOut = 0;
    foreach ($rows('scholarship_applications') as $sc) {
        $v1id = (int) ($sc['id'] ?? 0);
        $email = mam_v2_plan_str($sc['email'] ?? null, 320);
        $ref = mam_v2_plan_str($sc['reference'] ?? null, 64);
        if ($email === null || $ref === null) {
            $rejected[] = ['table' => 'scholarship_applications', 'id' => $v1id,
                'why' => $email === null ? 'no address on the application' : 'no reference to quote back'];
            continue;
        }
        $status = (string) ($sc['status'] ?? 'submitted');
        $vocab = ['submitted' => 'submitted', 'screening' => 'screening', 'reviewing' => 'screening',
                  'shortlisted' => 'shortlisted', 'awarded' => 'awarded', 'approved' => 'awarded',
                  'declined' => 'declined', 'rejected' => 'declined', 'withdrawn' => 'withdrawn'];
        $status = $vocab[$status] ?? 'submitted';
        $created = mam_v2_plan_ts($sc['created_at'] ?? null, $nowFallback);
        // chk_schol_decided: a decision states when and why. v1 recorded neither,
        // so a decided application is dated from the record and given a note
        // saying the reason was not kept — which is the fact, and is what the
        // family would be told if they asked.
        $decided = !in_array($status, ['submitted', 'screening', 'shortlisted'], true);
        // The applicant's own account, matched on the address they applied with —
        // safe here, unlike a contact-form enquiry, because the address IS the
        // identity the application was made under.
        $mid = null;
        foreach ($rows('users') as $u) {
            if (strcasecmp((string) ($u['email'] ?? ''), $email) === 0) {
                $mid = $memberOfV1User[(int) ($u['id'] ?? 0)] ?? null; break;
            }
        }
        $T['scholarship_applications'][] = ['public_id' => mam_v2_plan_pid('scholarship:' . $v1id, $created),
            'tenant_id' => $tenantId, 'reference' => $ref,
            // The v1 link is a REFERENCE STRING to an application, not an id, so
            // it resolves through the reference the applicant quoted.
            'application_id' => $appIdOfRef[mb_strtolower((string) ($sc['application_reference'] ?? ''))] ?? null,
            'membership_id' => $mid,
            'full_name' => mam_v2_plan_str($sc['full_name'] ?? null, 190) ?? '',
            'email' => $email,
            'age_band' => mam_v2_plan_str($sc['age_band'] ?? null, 24),
            'experience' => mam_v2_plan_str($sc['experience'] ?? null),
            'financial_need' => mam_v2_plan_str($sc['financial_need'] ?? null),
            'musical_journey' => mam_v2_plan_str($sc['musical_journey'] ?? null),
            'values_alignment' => mam_v2_plan_str($sc['values_alignment'] ?? null),
            'community_impact' => mam_v2_plan_str($sc['community_impact'] ?? null),
            'commitment_ack' => mam_v2_plan_bool($sc['commitment_ack'] ?? null, 0),
            'readiness_signal' => mam_v2_plan_str($sc['readiness_signal'] ?? null, 24),
            'status' => $status,
            'decided_by' => null,
            'decided_at' => $decided ? $created : null,
            'outcome_note' => $decided ? 'Decision migrated from the previous system; the reason recorded at the time was not kept.' : null,
            'source_ip' => mam_v2_plan_str($sc['ip'] ?? null, 45),
            'created_at' => $created, 'deleted_seq' => 0];
        $schOut++;
        $mapped('scholarship_applications', $v1id, 'scholarship_applications', $schOut, $ref);
    }
    $note('scholarship_applications', 'scholarship_applications',
        'the v1 link to an application is a REFERENCE STRING and resolves through it. A decided application '
        . 'is dated from the record and says the reason was not kept — which is the fact, and what the '
        . 'family would be told if they asked. Nothing links it to an admission decision, on either side.',
        count($rows('scholarship_applications')), $schOut);

    $auOut = 0;
    foreach ($rows('auditer_results') as $ar) {
        $mid = $memberOfV1User[(int) ($ar['student_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'auditer_results', 'id' => $ar['id'] ?? null, 'why' => 'the learner did not map to a membership']; continue; }
        $pathway = mam_v2_plan_str($ar['recommended_pathway'] ?? null, 48);
        $T['auditer_results'][] = ['public_id' => mam_v2_plan_pid('auditer:' . (int) ($ar['id'] ?? 0)),
            'tenant_id' => $tenantId, 'membership_id' => $mid, 'application_id' => null,
            'dimensions' => mam_v2_plan_str($ar['dimensions'] ?? null),
            // The pathway is kept as the WORD as well as resolved, because a
            // programme code that no longer exists is still what the placement
            // said, and an unresolvable one must not silently become "no
            // recommendation".
            'recommended_programme_id' => $pathway !== null ? ($progCodeOf[strtolower($pathway)] ?? null) : null,
            'recommended_level_id' => null,
            'recommended_pathway' => $pathway,
            'notes' => mam_v2_plan_str($ar['notes'] ?? null),
            'audited_by_membership_id' => $memberOfV1User[(int) ($ar['audited_by'] ?? 0)] ?? null,
            'audited_at' => mam_v2_plan_ts($ar['audited_at'] ?? null, $nowFallback),
            // v1 set no expiry. It is left NULL rather than computed: how long a
            // placement stays good is the academy's policy, and inventing one
            // here would expire real placements on a date nobody chose.
            'valid_until' => null];
        $auOut++;
    }
    $note('auditer_results', 'auditer_results',
        'the recommended pathway is kept as the WORD as well as resolved to a programme: a code that no '
        . 'longer exists is still what the placement said, and an unresolvable one must not silently become '
        . '"no recommendation". No expiry is invented — how long a placement stays good is policy.',
        count($rows('auditer_results')), $auOut);

    // Newsletter subscribers. Carried, unlike v1's ETL which dropped them:
    // consent given at signup is a lawful basis the academy still holds, and
    // deleting the list also deletes the SUPPRESSION list, so the next import
    // mails the people who asked not to be mailed.
    $mkOut = 0; $mkSeen = [];
    foreach ($rows('newsletter') as $n) {
        $email = mam_v2_plan_str($n['email'] ?? null, 320);
        if ($email === null) continue;
        $k = mb_strtolower($email);
        if (isset($mkSeen[$k])) continue;
        $mkSeen[$k] = true;
        $status = (string) ($n['status'] ?? 'subscribed');
        $vocab = ['subscribed' => 'subscribed', 'active' => 'subscribed',
                  'unsubscribed' => 'unsubscribed', 'inactive' => 'unsubscribed',
                  'bounced' => 'bounced', 'suppressed' => 'suppressed'];
        $status = $vocab[$status] ?? 'subscribed';
        $created = mam_v2_plan_ts($n['created_at'] ?? null, $nowFallback);
        $T['marketing_contacts'][] = ['public_id' => mam_v2_plan_pid('marketing:' . $k, $created),
            'tenant_id' => $tenantId, 'email' => $email, 'name' => null,
            'status' => $status,
            'source' => mam_v2_plan_str($n['source'] ?? null, 48),
            // The consent moment is the subscription. v1 kept no separate
            // timestamp, and this is the closest true statement — the row exists
            // because somebody signed up then.
            'consent_at' => $created,
            'consent_evidence' => json_encode(['migrated_from' => 'v1.newsletter',
                                               'source' => $n['source'] ?? null]),
            // chk_mkt_unsub: an unsubscribe states WHEN, and v1 recorded no date.
            // Dated from the record, because the row's only job is to prove the
            // academy stopped — and a suppression with no date cannot.
            'unsubscribed_at' => $status === 'unsubscribed' ? $created : null,
            'bounced_at' => $status === 'bounced' ? $created : null,
            'membership_id' => null, 'created_at' => $created];
        $mkOut++;
    }
    $note('newsletter', 'marketing_contacts',
        'CARRIED, with consent provenance. Dropping the list would also drop the SUPPRESSION list, so the '
        . 'next import would mail the people who asked not to be mailed. A subscriber is a data subject, '
        . 'not a member, which is why this is not a `consents` row.',
        count($rows('newsletter')), $mkOut);

    // A banner and a price are different things. In v1 they were one row, and
    // `validation.php` + `store.php` read its `discount_percent` when pricing
    // tuition and orders — so editing a banner's colour and repricing every
    // family were the same operation. The split happens here.
    $promoId = 0; $bannerOut = 0;
    foreach ($rows('promo_banners') as $b) {
        $v1id = (int) ($b['id'] ?? 0);
        $pct = (float) (mam_v2_plan_int($b['discount_percent'] ?? null) ?? 0);
        $promo = null;
        if ($pct > 0) {
            $applies = (string) ($b['applies_to'] ?? 'tuition');
            $promo = ++$promoId;
            $T['promotions'][] = ['id' => $promo, 'public_id' => mam_v2_plan_pid('promotion:' . $v1id),
                'tenant_id' => $tenantId, 'code' => null,
                'name' => mam_v2_plan_str($b['title'] ?? null, 190) ?? 'Promotion',
                'applies_to' => in_array($applies, ['tuition', 'store', 'registration', 'all'], true) ? $applies : 'tuition',
                'discount_pct' => number_format(min(100.0, max(0.0, $pct)), 2, '.', ''),
                'max_discount_minor' => null, 'currency' => null,
                'starts_at' => mam_v2_plan_ts($b['starts_at'] ?? null, null),
                'ends_at' => mam_v2_plan_ts($b['ends_at'] ?? null, null),
                'is_active' => mam_v2_plan_bool($b['active'] ?? null, 1),
                'created_at' => mam_v2_plan_ts($b['created_at'] ?? null, $nowFallback),
                'created_by' => null];
            $mapped('promo_banners', $v1id, 'promotions', $promo, null,
                'the priced half; the copy became an announcement_banner');
        }
        $label = mam_v2_plan_str($b['cta_label'] ?? null, 64);
        $url   = mam_v2_plan_str($b['cta_url'] ?? null, 1000);
        // chk_banner_cta: a labelled button needs a destination and vice versa.
        // v1 defaulted both to '' so half-configured banners were normal.
        if ($label === null || $url === null) { $label = null; $url = null; }
        $T['announcement_banners'][] = ['public_id' => mam_v2_plan_pid('banner:' . $v1id),
            'tenant_id' => $tenantId, 'promotion_id' => $promo,
            'title' => mam_v2_plan_str($b['title'] ?? null, 190) ?? 'Notice',
            'body' => mam_v2_plan_str($b['body'] ?? null, 1000) ?? '',
            'cta_label' => $label, 'cta_url' => $url,
            // The hex colour is NOT carried: a value typed into a marketing form
            // is how a banner ends up at 2:1 contrast against its own text. Every
            // migrated banner takes the design system's neutral tone, which an
            // admin can change to another token.
            'tone' => 'notice',
            'audience' => mam_v2_plan_bool($b['public'] ?? null, 0) === 1 ? 'public' : 'learners',
            'starts_at' => mam_v2_plan_ts($b['starts_at'] ?? null, null),
            'ends_at' => mam_v2_plan_ts($b['ends_at'] ?? null, null),
            'is_active' => mam_v2_plan_bool($b['active'] ?? null, 1),
            'created_at' => mam_v2_plan_ts($b['created_at'] ?? null, $nowFallback)];
        $bannerOut++;
    }
    $note('promo_banners', 'promotions + announcement_banners',
        sprintf('SPLIT: %d banner(s) carried the copy, %d of them also carried a discount that priced '
            . 'tuition and store orders. In v1 those were one row, so editing a colour and repricing every '
            . 'family were the same operation. The hex colour is dropped for a palette token — a colour '
            . 'typed into a marketing form is how a banner ends up at 2:1 contrast against its own text.',
            $bannerOut, $promoId),
        count($rows('promo_banners')), $bannerOut + $promoId);

    /* ── Safeguarding, identity and consent ───────────────────────────────── */
    $vetOut = 0; $vetSeen = [];
    foreach ($rows('vetting_records') as $v) {
        $mid = $memberOfV1User[(int) ($v['instructor_id'] ?? 0)] ?? null;
        $type = mam_v2_plan_str($v['clearance_type'] ?? null, 48);
        $issued = mam_v2_plan_str($v['issued_on'] ?? null, 10);
        if ($mid === null || $type === null || $issued === null) {
            $rejected[] = ['table' => 'vetting_records', 'id' => $v['id'] ?? null,
                'why' => $mid === null ? 'instructor did not map to a membership'
                    : 'a clearance with no type or no issue date cannot be checked for currency'];
            continue;
        }
        // uq_vetting_type: re-recording REPLACES, so the LATEST record of each
        // check wins. v1 had the same unique key, but a merged instructor (two
        // v1 accounts, one person) can produce two records of one check here.
        $k = $mid . ':' . $type;
        $expires = mam_v2_plan_str($v['expires_on'] ?? null, 10);
        if (isset($vetSeen[$k])) {
            $i = $vetSeen[$k];
            $keep = ($T['vetting_records'][$i]['expires_on'] ?? null);
            // The one that stays current is the one that lasts longer. A gate
            // asking "is there a current clearance" must not be answered by an
            // older record that happened to load second.
            if ($expires !== null && ($keep === null || $expires > $keep)) {
                $T['vetting_records'][$i]['issued_on'] = $issued;
                $T['vetting_records'][$i]['expires_on'] = $expires;
                $T['vetting_records'][$i]['reference_no'] = mam_v2_plan_str($v['reference_no'] ?? null, 190);
            }
            $flag('vetting_duplicate', ['membership_id' => $mid, 'clearance_type' => $type,
                'action' => 'Two records of one check for one person (usually a merged instructor). The '
                    . 'later-expiring one was kept; confirm which certificate is on file.']);
            continue;
        }
        $vetSeen[$k] = count($T['vetting_records']);
        $verBy = $userOfV1User[(int) ($v['verified_by'] ?? 0)] ?? null;
        $verAt = mam_v2_plan_ts($v['verified_at'] ?? null, null);
        // chk_vetting_verified: both halves or neither. A record verified by
        // somebody with no date is not a verification, and the safe reading of a
        // half-record is UNVERIFIED — the gate then asks for it again, which is
        // the outcome that protects a child.
        if ($verBy === null || $verAt === null) { $verBy = null; $verAt = null; }
        $T['vetting_records'][] = ['public_id' => mam_v2_plan_pid('vetting:' . (int) ($v['id'] ?? 0)),
            'tenant_id' => $tenantId, 'membership_id' => $mid, 'clearance_type' => $type,
            'issuing_body' => mam_v2_plan_str($v['issuing_body'] ?? null, 190),
            'reference_no' => mam_v2_plan_str($v['reference_no'] ?? null, 190),
            'issued_on' => $issued,
            'expires_on' => ($expires !== null && $expires >= $issued) ? $expires : null,
            'verified_by' => $verBy, 'verified_at' => $verAt,
            // WHERE the paper is. Nothing about what it says: no scan, no
            // findings, no offence data — there is no column for any of it.
            'evidence_held' => mam_v2_plan_str($v['evidence_held'] ?? null, 255),
            'created_at' => mam_v2_plan_ts($v['created_at'] ?? null, $nowFallback), 'created_by' => null];
        $vetOut++;
    }
    $note('vetting_records', 'vetting_records',
        'the safeguarding gate. No check CONTENT and no offence data are carried, because there is nowhere '
        . 'to put them. A half-recorded verification (a verifier with no date) reads as UNVERIFIED, so the '
        . 'gate asks for it again — which is the outcome that protects a child.',
        count($rows('vetting_records')), $vetOut);

    // v1's three boolean consent flags become a ROW PER PURPOSE. v1 could not
    // say WHEN a consent was withdrawn, which is the NDPA-relevant fact — so
    // every migrated consent states its effective_from and nothing more,
    // rather than implying a history it never had.
    $consentOut = 0;
    foreach ($rows('student_consents') as $c) {
        $mid = $memberOfV1User[(int) ($c['student_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'student_consents', 'id' => $c['student_id'] ?? null, 'why' => 'the learner did not map to a membership']; continue; }
        $when = mam_v2_plan_ts($c['updated_at'] ?? null, $nowFallback);
        $by = $memberOfV1User[(int) ($c['updated_by'] ?? 0)] ?? null;
        foreach (['media_consent' => 'media', 'photo_consent' => 'photography', 'data_consent' => 'data_processing'] as $col => $purpose) {
            $granted = mam_v2_plan_bool($c[$col] ?? null, 0);
            $T['consents'][] = ['tenant_id' => $tenantId, 'membership_id' => $mid, 'purpose' => $purpose,
                'is_granted' => $granted,
                // WHO granted it, which for a child is the guardian. v1 recorded
                // only who last edited the row, and that is the honest reading of
                // it — not a claim that the guardian pressed the button.
                'granted_by_membership_id' => $by,
                'evidence' => json_encode(['migrated_from' => 'v1.student_consents.' . $col,
                                           'recorded_by_admin' => $by !== null]),
                'effective_from' => $when, 'effective_to' => null, 'created_at' => $when];
            $consentOut++;
        }
        // The safeguarding contact is a FACT ABOUT THE FAMILY, not a consent. It
        // goes to a custom field rather than being lost or turned into a fourth
        // consent row that means something different from the other three.
        $sc = mam_v2_plan_str($c['safeguarding_contact'] ?? null);
        if ($sc !== null) $setField($fSafeguard, $mid, $sc, 'string');
    }
    $note('student_consents', 'consents (one row per purpose) + a safeguarding-contact custom field',
        'v1 had three booleans and could not say WHEN a consent was withdrawn, which is the NDPA-relevant '
        . 'fact. Each migrated row states when it took effect and nothing more, rather than implying a '
        . 'history it never had. The safeguarding contact is a fact about the family, not a consent.',
        count($rows('student_consents')), $consentOut);

    $cardId = 0; $cardIdOf = [];
    foreach ($rows('id_cards') as $c) {
        $v1id = (int) ($c['id'] ?? 0);
        $mid = $memberOfV1User[(int) ($c['user_id'] ?? 0)] ?? null;
        $serial = mam_v2_plan_str($c['serial'] ?? null, 64);
        if ($mid === null || $serial === null) {
            $rejected[] = ['table' => 'id_cards', 'id' => $v1id,
                'why' => $mid === null
                    ? 'the holder did not map to a membership (a summer-only card whose child has no account)'
                    : 'no serial, so the card cannot be scanned or reprinted'];
            continue;
        }
        $issued = mam_v2_plan_ts($c['issued_at'] ?? null, $nowFallback);
        $expires = mam_v2_plan_ts($c['expires_at'] ?? null, null);
        if ($expires === null || $expires <= $issued) {
            // chk_card_dates, and the v59 lesson: before expiry was a real date
            // the face printed EXP.12/<issue year> and NOTHING enforced it, so a
            // lapsed card scanned into attendance for ever. A card with no
            // expiry is given one YEAR from issue — the shipped default — and
            // raised, because guessing a longer one keeps the old bug.
            $expires = gmdate('Y-m-d H:i:s.v', strtotime($issued . ' +1 year'));
            $flag('card_expiry_assumed', ['table' => 'id_cards', 'id' => $v1id, 'serial' => $serial,
                'action' => 'The card had no expiry (it predates schema v59). One year from issue was '
                    . 'stamped, matching the shipped default. Re-mint if the academy used another period.']);
        }
        $status = (string) ($c['status'] ?? 'active');
        $vocab = ['active' => 'active', 'printed' => 'printed', 'expired' => 'expired',
                  'revoked' => 'revoked', 'replaced' => 'replaced'];
        $status = $vocab[$status] ?? 'active';
        $revAt = mam_v2_plan_ts($c['revoked_at'] ?? null, null);
        $revWhy = mam_v2_plan_str($c['revoke_reason'] ?? null, 500);
        if ($status === 'revoked') {
            if ($revAt === null) $revAt = mam_v2_plan_ts($c['created_at'] ?? null, $nowFallback);
            if ($revWhy === null) $revWhy = 'Reason not recorded in the previous system';
        }
        $id = ++$cardId;
        $T['id_cards'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('card:' . $v1id, $issued),
            'tenant_id' => $tenantId, 'membership_id' => $mid,
            'role' => (string) ($c['role'] ?? 'student') === 'student' ? 'learner'
                : (in_array((string) $c['role'], ['staff', 'guardian', 'visitor'], true) ? (string) $c['role'] : 'learner'),
            'serial' => $serial,
            'version' => max(1, mam_v2_plan_int($c['version'] ?? null) ?? 1),
            'status' => $status,
            'session_code' => mam_v2_plan_str($c['session_code'] ?? null, 32),
            // The photo is an attachment in v2; the v1 URL is carried on the
            // attachment, not here, so a card cannot point at a file the media
            // proxy does not know about.
            'photo_attachment_id' => null,
            'issued_at' => $issued, 'expires_at' => $expires,
            'printed_at' => mam_v2_plan_ts($c['printed_at'] ?? null, null),
            'revoked_at' => $status === 'revoked' ? $revAt : null,
            'revoke_reason' => $status === 'revoked' ? $revWhy : null,
            'replaced_by_id' => null,
            'created_at' => mam_v2_plan_ts($c['created_at'] ?? null, $nowFallback),
            'created_by' => $userOfV1User[(int) ($c['created_by'] ?? 0)] ?? null];
        $cardIdOf[$v1id] = $id;
        $addAttachment('id_card', $id, mam_v2_plan_str($c['photo_url'] ?? null, 1000), 'headshot',
            $issued, null, 'card-photo:' . $v1id);
        $mapped('id_cards', $v1id, 'id_cards', $id, $serial);
    }
    $note('id_cards', 'id_cards (+ the photo as an attachment)',
        'a card with no expiry — one minted before expiry was a real date, when the face printed '
        . '"EXP.12/<issue year>" and nothing enforced it — is stamped one year from issue and raised. '
        . 'Guessing longer would keep the bug where a lapsed card scanned into attendance for ever.',
        count($rows('id_cards')), $cardId);

    $scanOut = 0;
    foreach ($rows('card_scans') as $sc) {
        $result = (string) ($sc['result'] ?? 'unknown');
        $vocab = ['ok' => 'ok', 'valid' => 'ok', 'unknown' => 'unknown', 'not_found' => 'unknown',
                  'expired' => 'expired', 'revoked' => 'revoked', 'wrong_session' => 'wrong_session',
                  'duplicate' => 'duplicate', 'not_enrolled' => 'not_enrolled'];
        $T['card_scans'][] = ['tenant_id' => $tenantId,
            'card_id' => $cardIdOf[(int) ($sc['card_id'] ?? 0)] ?? null,
            // Kept verbatim even when it matched no card: an unmatched serial at
            // a door is the row somebody investigates.
            'raw_serial' => mb_substr((string) ($sc['raw_serial'] ?? ''), 0, 190),
            'session_id' => $sessIdOf[(int) ($sc['session_id'] ?? 0)] ?? null,
            'cohort_id' => $cohIdOfClass[(int) ($sc['class_id'] ?? 0)] ?? null,
            'scanned_by_membership_id' => $memberOfV1User[(int) ($sc['scanned_by'] ?? 0)] ?? null,
            'result' => $vocab[$result] ?? 'unknown',
            'scanned_at' => mam_v2_plan_ts($sc['scanned_at'] ?? null, $nowFallback)];
        $scanOut++;
    }
    $note('card_scans', 'card_scans',
        'including every FAILED scan: a door log recording only successes cannot answer "did somebody try '
        . 'to get in with a revoked card".', count($rows('card_scans')), $scanOut);

    $note('password_resets', null,
        'single-use tokens with an expiry. v2 has ONE table for them (`signed_tokens`, holding only a hash), '
        . 'but a live token is a credential: carrying one extends a window that should close, and re-issuing '
        . 'costs an email.', count($rows('password_resets')));
    $note('progress_report_tokens', null,
        'as password_resets, and v1 stored these in the CLEAR — so the table was a set of live share links. '
        . 'Re-issued, not migrated.', count($rows('progress_report_tokens')));
    $note('calendar_tokens', null,
        'as above. v1 calendar tokens NEVER expired, which makes a leaked URL a permanent subscription to a '
        . "child's timetable; v2 requires an expiry, so these cannot be carried unchanged anyway.",
        count($rows('calendar_tokens')));
    $note('rate_limits', null,
        'a request counter, which regenerates in minutes. The AUTH failure counter it is not — that is '
        . '`auth_throttle` in v2, and it starts empty by design: carrying failure counts would lock people '
        . 'out of a system they have never signed in to.', count($rows('rate_limits')));

    /* ── Practice, repertoire, recognition ────────────────────────────────── */
    $achOut = 0; $achSeen = [];
    foreach ($rows('achievements') as $a) {
        $mid = $memberOfV1User[(int) ($a['student_id'] ?? 0)] ?? null;
        $code = mam_v2_plan_str($a['code'] ?? null, 48);
        if ($mid === null || $code === null) {
            $rejected[] = ['table' => 'achievements', 'id' => $a['id'] ?? null,
                'why' => $mid === null ? 'the learner did not map to a membership' : 'no badge code'];
            continue;
        }
        // uq_achievement: awarded once. v1 had no unique key, so the same badge
        // could be granted twice — and the second row would replay the
        // celebration on the learner's next visit.
        $k = $mid . ':' . $code;
        if (isset($achSeen[$k])) continue;
        $achSeen[$k] = true;
        $T['achievements'][] = ['tenant_id' => $tenantId, 'membership_id' => $mid, 'code' => $code,
            'title' => mam_v2_plan_str($a['title'] ?? null, 190) ?? $code,
            'awarded_at' => mam_v2_plan_ts($a['awarded_at'] ?? null, $nowFallback)];
        $achOut++;
    }
    $note('achievements', 'achievements',
        'deduplicated on (person, badge): v1 had no unique key, and a second row replays the celebration.',
        count($rows('achievements')), $achOut);

    $plogOut = 0;
    foreach ($rows('practice_logs') as $l) {
        $mid = $memberOfV1User[(int) ($l['student_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'practice_logs', 'id' => $l['id'] ?? null, 'why' => 'the learner did not map to a membership']; continue; }
        $on = mam_v2_plan_str($l['logged_on'] ?? null, 10)
            ?? substr((string) mam_v2_plan_ts($l['created_at'] ?? null, $nowFallback), 0, 10);
        $mins = mam_v2_plan_int($l['minutes'] ?? null) ?? 0;
        if ($mins > 1440) {
            // chk_plog_minutes. More practice than a day contains is a typo, and
            // a streak built on typos is worthless — so it is CLAMPED and raised
            // rather than dropped: the learner did practise that day.
            $flag('practice_minutes_clamped', ['table' => 'practice_logs', 'id' => $l['id'] ?? null,
                'value' => $mins, 'action' => 'More minutes than a day contains; clamped to 1440.']);
            $mins = 1440;
        }
        $T['practice_logs'][] = ['tenant_id' => $tenantId, 'membership_id' => $mid, 'enrolment_id' => null,
            'logged_on' => $on, 'minutes' => max(0, $mins),
            'focus' => mam_v2_plan_str($l['focus'] ?? null, 190),
            'notes' => mam_v2_plan_str($l['notes'] ?? null),
            'created_at' => mam_v2_plan_ts($l['created_at'] ?? null, $nowFallback)];
        $plogOut++;
    }
    $note('practice_logs', 'practice_logs',
        'the DAY is stored as a date, not an instant: "did I practise on Tuesday" must not depend on the '
        . "reader's timezone.", count($rows('practice_logs')), $plogOut);

    $pchOut = 0;
    foreach ($rows('practice_challenges') as $c) {
        $mid = $memberOfV1User[(int) ($c['student_id'] ?? 0)] ?? null;
        $from = mam_v2_plan_str($c['starts_on'] ?? null, 10);
        $to   = mam_v2_plan_str($c['ends_on'] ?? null, 10);
        if ($mid === null || $from === null || $to === null || $to < $from) {
            $rejected[] = ['table' => 'practice_challenges', 'id' => $c['id'] ?? null,
                'why' => $mid === null ? 'the learner did not map to a membership'
                    : 'the challenge window is missing or ends before it starts'];
            continue;
        }
        $metric = (string) ($c['metric'] ?? 'sessions');
        $T['practice_challenges'][] = ['public_id' => mam_v2_plan_pid('challenge:' . (int) ($c['id'] ?? 0)),
            'tenant_id' => $tenantId, 'membership_id' => $mid,
            'title' => mam_v2_plan_str($c['title'] ?? null, 190) ?? 'Practice challenge',
            'metric' => in_array($metric, ['sessions', 'minutes', 'days'], true) ? $metric : 'sessions',
            'target' => max(1, mam_v2_plan_int($c['target'] ?? null) ?? 1),
            'starts_on' => $from, 'ends_on' => $to,
            // v1 recorded no outcome. A finished window whose result was never
            // written is not a success: it is carried ACTIVE if still open, and
            // otherwise as 'missed' would be a claim, so it stays active and the
            // sweep decides on its own next run.
            'status' => 'active', 'completed_on' => null,
            'created_at' => mam_v2_plan_ts($c['created_at'] ?? null, $nowFallback)];
        $pchOut++;
    }
    $note('practice_challenges', 'practice_challenges',
        'carried as ACTIVE: v1 recorded no outcome, and marking a finished window "missed" would be a claim '
        . 'about a learner the data does not support. The sweep decides on its next run.',
        count($rows('practice_challenges')), $pchOut);

    $repId = 0; $repIdOf = [];
    foreach ($rows('repertoire') as $r) {
        $mid = $memberOfV1User[(int) ($r['student_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'repertoire', 'id' => $r['id'] ?? null, 'why' => 'the learner did not map to a membership']; continue; }
        $status = (string) ($r['status'] ?? 'learning');
        $vocab = ['learning' => 'learning', 'polishing' => 'polishing',
                  'performance_ready' => 'performance_ready', 'ready' => 'performance_ready',
                  'retired' => 'retired', 'shelved' => 'retired'];
        $id = ++$repId;
        $T['repertoire'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('repertoire:' . (int) ($r['id'] ?? 0)),
            'tenant_id' => $tenantId, 'membership_id' => $mid,
            'title' => mam_v2_plan_str($r['title'] ?? null, 190) ?? 'Untitled',
            'composer' => mam_v2_plan_str($r['composer'] ?? null, 190),
            'arranger' => null, 'instrument' => null,
            'status' => $vocab[$status] ?? 'learning',
            'notes' => mam_v2_plan_str($r['notes'] ?? null),
            'sort_order' => mam_v2_plan_int($r['sort_order'] ?? null) ?? 0,
            'created_at' => mam_v2_plan_ts($r['created_at'] ?? null, $nowFallback), 'deleted_seq' => 0];
        $repIdOf[(int) ($r['id'] ?? 0)] = $id;
    }
    $note('repertoire', 'repertoire', 'what a learner is working on, and how far along it is.',
        count($rows('repertoire')), $repId);

    $recId = 0; $recIdOf = [];
    foreach ($rows('recitals') as $r) {
        $v1id = (int) ($r['id'] ?? 0);
        $slug = mam_v2_plan_str($r['slug'] ?? null, 96);
        if ($slug === null) { $rejected[] = ['table' => 'recitals', 'id' => $v1id, 'why' => 'no slug, so the programme page has no address']; continue; }
        $status = (string) ($r['status'] ?? 'draft');
        $vocab = ['draft' => 'draft', 'open' => 'open', 'programmed' => 'programmed',
                  'published' => 'programmed', 'performed' => 'performed', 'done' => 'performed',
                  'cancelled' => 'cancelled'];
        $status = $vocab[$status] ?? 'draft';
        // v1 stored one datetime per recital and no zone, the same ambiguity as
        // `class_sessions` — and resolved the same way: the value is the UTC
        // instant v1's own code compared, and the LOCAL time is derived from it.
        $startUtc = mam_v2_plan_ts($r['starts_at'] ?? null, null);
        $doorsUtc = mam_v2_plan_ts($r['doors_open_at'] ?? null, null);
        $closeUtc = mam_v2_plan_ts($r['submissions_close_at'] ?? null, null);
        $toLocal = static function (?string $utc) use ($utcZone, $locZone): ?string {
            if ($utc === null) return null;
            return (new DateTimeImmutable(substr($utc, 0, 19), $utcZone))->setTimezone($locZone)->format('Y-m-d H:i:s.v');
        };
        $startLocal = $toLocal($startUtc);
        $doorsLocal = $toLocal($doorsUtc);
        $closeLocal = $toLocal($closeUtc);
        // chk_recital_when: an announced concert has a time.
        if ($startLocal === null && !in_array($status, ['draft', 'cancelled'], true)) {
            $flag('recital_no_date', ['table' => 'recitals', 'id' => $v1id,
                'action' => 'Announced with no date. Carried as a draft; set the date and re-publish.']);
            $status = 'draft';
        }
        if ($doorsLocal !== null && $startLocal !== null && $doorsLocal > $startLocal) $doorsLocal = null;
        if ($closeLocal !== null && $startLocal !== null && $closeLocal > $startLocal) $closeLocal = null;
        $id = ++$recId;
        $T['recitals'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('recital:' . $v1id, $startUtc),
            'tenant_id' => $tenantId, 'centre_id' => null, 'slug' => $slug,
            'title' => mam_v2_plan_str($r['title'] ?? null, 190) ?? 'Recital',
            'subtitle' => mam_v2_plan_str($r['subtitle'] ?? null, 190),
            'venue' => mam_v2_plan_str($r['venue'] ?? null, 190),
            'starts_local' => $startLocal, 'timezone' => $sessTz, 'doors_open_local' => $doorsLocal,
            'interval_after_item' => mam_v2_plan_int($r['interval_after'] ?? null),
            'interval_minutes' => mam_v2_plan_int($r['interval_min'] ?? null) ?? 15,
            'status' => $status, 'submissions_close_local' => $closeLocal,
            'description' => mam_v2_plan_str($r['description'] ?? null),
            'programme_note' => mam_v2_plan_str($r['programme_note'] ?? null),
            'created_by' => $userOfV1User[(int) ($r['created_by'] ?? 0)] ?? null,
            'created_at' => mam_v2_plan_ts($r['created_at'] ?? null, $nowFallback), 'deleted_seq' => 0];
        $recIdOf[$v1id] = $id;
        $mapped('recitals', $v1id, 'recitals', $id, $slug);
    }
    $note('recitals', 'recitals',
        'the concert time is a LOCAL wall clock plus a zone (families read the doors time off a poster), '
        . 'derived from the UTC instant v1 stored — the same ambiguity as class_sessions, resolved the same '
        . 'way. One announced with no date is carried as a draft.',
        count($rows('recitals')), $recId);

    $ritemOut = 0;
    foreach ($rows('recital_items') as $it) {
        $rec = $recIdOf[(int) ($it['recital_id'] ?? 0)] ?? null;
        $mid = $memberOfV1User[(int) ($it['student_id'] ?? 0)] ?? null;
        if ($rec === null || $mid === null) {
            $rejected[] = ['table' => 'recital_items', 'id' => $it['id'] ?? null,
                'why' => $rec === null ? 'recital did not map' : 'the performer did not map to a membership'];
            continue;
        }
        $status = (string) ($it['status'] ?? 'proposed');
        $vocab = ['proposed' => 'proposed', 'accepted' => 'accepted', 'approved' => 'accepted',
                  'declined' => 'declined', 'rejected' => 'declined', 'withdrawn' => 'withdrawn',
                  'performed' => 'performed'];
        $status = $vocab[$status] ?? 'proposed';
        $decBy = $userOfV1User[(int) ($it['decided_by'] ?? 0)] ?? null;
        $decAt = mam_v2_plan_ts($it['decided_at'] ?? null, null);
        $noteTxt = mam_v2_plan_str($it['note'] ?? null, 1000);
        // chk_ritem_declined: a learner told no is told why and by whom. Where v1
        // recorded none of that, the piece returns to PROPOSED rather than
        // standing as an unexplained refusal on a child's record.
        if ($status === 'declined' && ($decBy === null || $decAt === null || $noteTxt === null)) {
            $flag('recital_decline_unexplained', ['table' => 'recital_items', 'id' => $it['id'] ?? null,
                'action' => 'Declined with no decider, date or reason. Carried as proposed; decide it again.']);
            $status = 'proposed';
        }
        $T['recital_items'][] = ['tenant_id' => $tenantId, 'recital_id' => $rec, 'membership_id' => $mid,
            'repertoire_id' => $repIdOf[(int) ($it['repertoire_id'] ?? 0)] ?? null,
            'submission_id' => $subIdOf[(int) ($it['submission_id'] ?? 0)] ?? null,
            'title' => mam_v2_plan_str($it['title'] ?? null, 190) ?? 'Untitled',
            'composer' => mam_v2_plan_str($it['composer'] ?? null, 190),
            'arranger' => mam_v2_plan_str($it['arranger'] ?? null, 190),
            'movement' => mam_v2_plan_str($it['movement'] ?? null, 190),
            'instrument' => mam_v2_plan_str($it['instrument'] ?? null, 48),
            'duration_minutes' => min(120, max(1, mam_v2_plan_int($it['duration_min'] ?? null) ?? 4)),
            'section' => in_array((string) ($it['section'] ?? 'main'), ['opening', 'main', 'closing', 'encore'], true)
                ? (string) $it['section'] : 'main',
            'sort_order' => mam_v2_plan_int($it['sort_order'] ?? null) ?? 0,
            'status' => $status, 'note' => $noteTxt,
            'decided_by' => $status === 'proposed' ? null : $decBy,
            'decided_at' => $status === 'proposed' ? null : $decAt,
            'created_at' => mam_v2_plan_ts($it['created_at'] ?? null, $nowFallback)];
        $ritemOut++;
    }
    $note('recital_items', 'recital_items',
        'a decline with no decider, date or reason returns to PROPOSED rather than standing as an '
        . "unexplained refusal on a child's record.", count($rows('recital_items')), $ritemOut);

    $rcOut = 0; $rcSeen = [];
    foreach ($rows('referral_codes') as $rc) {
        $mid = $memberOfV1User[(int) ($rc['user_id'] ?? 0)] ?? null;
        $code = mam_v2_plan_str($rc['code'] ?? null, 32);
        if ($mid === null || $code === null || isset($rcSeen[$mid])) continue;
        $rcSeen[$mid] = true;
        $T['referral_codes'][] = ['tenant_id' => $tenantId, 'membership_id' => $mid, 'code' => $code,
            'is_active' => 1, 'created_at' => mam_v2_plan_ts($rc['created_at'] ?? null, $nowFallback)];
        $rcOut++;
    }
    $note('referral_codes', 'referral_codes', 'one code per person.', count($rows('referral_codes')), $rcOut);

    $refOut = 0;
    foreach ($rows('referrals') as $r) {
        $mid = $memberOfV1User[(int) ($r['referrer_id'] ?? 0)] ?? null;
        $code = mam_v2_plan_str($r['code'] ?? null, 32);
        $email = mam_v2_plan_str($r['referred_email'] ?? null, 320);
        $name = mam_v2_plan_str($r['referred_name'] ?? null, 190);
        $referred = $memberOfV1User[(int) ($r['referred_user_id'] ?? 0)] ?? null;
        if ($mid === null || $code === null || ($email === null && $name === null && $referred === null)) {
            $rejected[] = ['table' => 'referrals', 'id' => $r['id'] ?? null,
                'why' => $mid === null ? 'the referrer did not map to a membership'
                    : 'a referral of nobody: no name, no address and no account'];
            continue;
        }
        $status = (string) ($r['status'] ?? 'pending');
        $vocab = ['pending' => 'pending', 'joined' => 'joined', 'enrolled' => 'joined',
                  'rewarded' => 'rewarded', 'expired' => 'expired', 'rejected' => 'rejected'];
        $status = $vocab[$status] ?? 'pending';
        $reward = max(0, mam_v2_plan_int($r['reward_cents'] ?? null) ?? 0);
        $rewardedAt = mam_v2_plan_ts($r['rewarded_at'] ?? null, null);
        // chk_referral_rewarded: a reward is an amount on a date. v1 could mark
        // one rewarded with neither, which is a promise to a family that nothing
        // backs.
        if ($status === 'rewarded' && ($reward <= 0 || $rewardedAt === null)) {
            $flag('referral_reward_unevidenced', ['table' => 'referrals', 'id' => $r['id'] ?? null,
                'action' => 'Marked rewarded with no amount or no date. Carried as joined; pay it or '
                    . 'record what was paid.']);
            $status = 'joined';
        }
        $T['referrals'][] = ['public_id' => mam_v2_plan_pid('referral:' . (int) ($r['id'] ?? 0)),
            'tenant_id' => $tenantId, 'referrer_membership_id' => $mid, 'code' => $code,
            'referred_name' => $name, 'referred_email' => $email,
            'referred_membership_id' => $referred, 'status' => $status,
            'reward_minor' => $reward,
            // v1's column was `reward_cents` with no currency. USD is the only
            // reading consistent with the name, and it is stated rather than left
            // to whoever sums the column.
            'currency' => $reward > 0 ? 'USD' : null,
            'rewarded_at' => $status === 'rewarded' ? $rewardedAt : null,
            'created_at' => mam_v2_plan_ts($r['created_at'] ?? null, $nowFallback)];
        $refOut++;
    }
    $note('referrals', 'referrals',
        'v1\'s `reward_cents` had no currency column, so the figure meant whatever the reader assumed; USD '
        . 'is stated explicitly. A reward with no amount or no date is carried as JOINED — a reward nothing '
        . 'backs is a promise to a family.', count($rows('referrals')), $refOut);

    /* ── Compliance, automation, the shop ─────────────────────────────────── */
    $dpaOut = 0;
    foreach ($rows('dp_assessments') as $a) {
        $decision = (string) ($a['decision'] ?? 'draft');
        $risk = (string) ($a['residual_risk'] ?? 'unknown');
        $on = mam_v2_plan_str($a['assessed_on'] ?? null, 10);
        $by = $userOfV1User[(int) ($a['assessed_by'] ?? 0)] ?? null;
        // chk_dpa_decided and chk_dpa_high_risk. An unsigned decision is a draft,
        // and 'proceed' on high residual risk becomes 'proceed_with_controls' —
        // which is what the NDPA expects of it, and what the mitigations column
        // already describes.
        if ($decision !== 'draft' && ($on === null || $by === null)) {
            $flag('dp_assessment_unsigned', ['table' => 'dp_assessments', 'id' => $a['id'] ?? null,
                'was' => $decision, 'action' => 'No assessor or no date. Carried as a draft; sign it.']);
            $decision = 'draft';
        }
        if ($risk === 'high' && $decision === 'proceed') {
            $flag('dp_assessment_high_risk', ['table' => 'dp_assessments', 'id' => $a['id'] ?? null,
                'action' => 'High residual risk with a bare "proceed". Recorded as proceed_with_controls; '
                    . 'confirm the controls and whether the regulator was consulted.']);
            $decision = 'proceed_with_controls';
        }
        $T['dp_assessments'][] = ['public_id' => mam_v2_plan_pid('dpa:' . (int) ($a['id'] ?? 0)),
            'tenant_id' => $tenantId,
            'title' => mam_v2_plan_str($a['title'] ?? null, 190) ?? 'Assessment',
            'scope' => (string) ($a['scope'] ?? ''),
            'trigger_reason' => mam_v2_plan_str($a['trigger_reason'] ?? null),
            'necessity' => mam_v2_plan_str($a['necessity'] ?? null),
            'risks' => mam_v2_plan_str($a['risks'] ?? null),
            'mitigations' => mam_v2_plan_str($a['mitigations'] ?? null),
            'residual_risk' => in_array($risk, ['unknown', 'low', 'medium', 'high'], true) ? $risk : 'unknown',
            'decision' => $decision,
            'assessed_on' => $decision === 'draft' ? null : $on,
            'assessed_by' => $decision === 'draft' ? null : $by,
            'review_due_on' => mam_v2_plan_str($a['review_due_on'] ?? null, 10),
            'consulted_regulator' => mam_v2_plan_bool($a['consulted_ndpc'] ?? null, 0),
            'created_at' => mam_v2_plan_ts($a['created_at'] ?? null, $nowFallback)];
        $dpaOut++;
    }
    $note('dp_assessments', 'dp_assessments',
        'an unsigned decision is a DRAFT, and a bare "proceed" on high residual risk becomes '
        . '"proceed_with_controls" — which is what the NDPA expects and what the mitigations already say.',
        count($rows('dp_assessments')), $dpaOut);

    $brOut = 0;
    foreach ($rows('dp_breaches') as $b) {
        $disc = mam_v2_plan_ts($b['discovered_at'] ?? null, null);
        if ($disc === null) {
            // The 72-hour clock runs from AWARENESS. A breach record with no
            // discovery time cannot be measured at all, and inventing one would
            // fabricate compliance.
            $rejected[] = ['table' => 'dp_breaches', 'id' => $b['id'] ?? null,
                'why' => 'no discovery time: the NDPA clock runs from awareness, and inventing one would '
                    . 'fabricate compliance'];
            continue;
        }
        $occ = mam_v2_plan_ts($b['occurred_at'] ?? null, null);
        if ($occ !== null && $occ > $disc) $occ = null;
        $risk = (string) ($b['risk_level'] ?? 'unknown');
        $status = (string) ($b['status'] ?? 'open');
        $vocab = ['open' => 'open', 'contained' => 'contained', 'closed' => 'closed'];
        $status = $vocab[$status] ?? 'open';
        $lessons = mam_v2_plan_str($b['lessons'] ?? null);
        $notified = mam_v2_plan_ts($b['subjects_notified_at'] ?? null, null);
        $noWhy = mam_v2_plan_str($b['no_notify_reason'] ?? null);
        // chk_breach_notify / chk_breach_closed. A high-risk breach cannot be
        // closed until the affected people were told or there is a written
        // reason they were not, and a closed breach says what was learned. A
        // record failing either is REOPENED — which is the honest state, and the
        // one that makes somebody finish it.
        if ($status === 'closed' && ($lessons === null
            || ($risk === 'high' && $notified === null && $noWhy === null))) {
            $flag('breach_closed_incomplete', ['table' => 'dp_breaches', 'id' => $b['id'] ?? null,
                'action' => $lessons === null
                    ? 'Closed with nothing learned. Reopened as contained; record the lessons.'
                    : 'A HIGH-risk breach was closed without notifying the affected people and without a '
                      . 'written reason. Reopened as contained; discharge the duty or record why not.']);
            $status = 'contained';
        }
        $T['dp_breaches'][] = ['public_id' => mam_v2_plan_pid('breach:' . (int) ($b['id'] ?? 0), $disc),
            'tenant_id' => $tenantId,
            'reference' => mam_v2_plan_str($b['reference'] ?? null, 64) ?? ('BR-' . (string) ($b['id'] ?? '0')),
            'occurred_at' => $occ, 'discovered_at' => $disc,
            'nature' => (string) ($b['nature'] ?? ''),
            'data_categories' => (string) ($b['data_categories'] ?? ''),
            'subject_categories' => mam_v2_plan_str($b['subject_categories'] ?? null),
            'subjects_affected' => mam_v2_plan_int($b['subjects_affected'] ?? null),
            'risk_level' => in_array($risk, ['unknown', 'low', 'medium', 'high'], true) ? $risk : 'unknown',
            'containment' => mam_v2_plan_str($b['containment'] ?? null),
            'regulator_notified_at' => mam_v2_plan_ts($b['ndpc_notified_at'] ?? null, null),
            'regulator_reference' => mam_v2_plan_str($b['ndpc_reference'] ?? null, 190),
            'subjects_notified_at' => $notified, 'no_notify_reason' => $noWhy,
            'lessons' => $lessons, 'status' => $status,
            'recorded_by' => $userOfV1User[(int) ($b['recorded_by'] ?? 0)] ?? null,
            'created_at' => mam_v2_plan_ts($b['created_at'] ?? null, $nowFallback)];
        $brOut++;
    }
    $note('dp_breaches', 'dp_breaches',
        'the clock runs from `discovered_at` (NDPA s.40(2) measures from AWARENESS), so a record with no '
        . 'discovery time is refused rather than given one. A breach closed with nothing learned — or a '
        . 'high-risk one closed without notifying anybody and without a written reason — is REOPENED as '
        . 'contained.', count($rows('dp_breaches')), $brOut);

    $procOut = 0; $procSeen = [];
    foreach ($rows('dp_processors') as $pr) {
        $key = mam_v2_plan_str($pr['key_name'] ?? null, 64);
        if ($key === null || isset($procSeen[$key])) continue;
        $procSeen[$key] = true;
        $country = mam_v2_plan_str($pr['country'] ?? null, 64);
        $basis = mam_v2_plan_str($pr['transfer_basis'] ?? null, 190);
        $status = (string) ($pr['status'] ?? 'active');
        $vocab = ['active' => 'active', 'suspended' => 'suspended', 'retired' => 'retired'];
        $status = $vocab[$status] ?? 'active';
        // chk_dpproc_transfer: a live processor abroad states its transfer basis.
        // Naming the country records the risk and nothing about how it is
        // answered, so an unanswered one is SUSPENDED and raised — the register
        // then shows a gap somebody has to close.
        if ($status === 'active' && $country !== null
            && !in_array($country, ['Nigeria', 'NG'], true) && $basis === null) {
            $flag('processor_transfer_unstated', ['table' => 'dp_processors', 'key' => $key,
                'country' => $country,
                'action' => 'A live processor outside Nigeria with no stated transfer basis. Recorded as '
                    . 'suspended; state the basis (or confirm the vendor is no longer used).']);
            $status = 'suspended';
        }
        $T['dp_processors'][] = ['tenant_id' => $tenantId, 'key_name' => $key,
            'name' => mam_v2_plan_str($pr['name'] ?? null, 190) ?? $key,
            'role' => in_array((string) ($pr['role'] ?? 'processor'), ['processor', 'joint_controller', 'controller'], true)
                ? (string) $pr['role'] : 'processor',
            'purpose' => mam_v2_plan_str($pr['purpose'] ?? null),
            'country' => $country,
            'dpa_signed_on' => mam_v2_plan_str($pr['dpa_signed_on'] ?? null, 10),
            'dpa_reference' => mam_v2_plan_str($pr['dpa_reference'] ?? null, 190),
            'sub_processors' => mam_v2_plan_str($pr['sub_processors'] ?? null),
            'transfer_basis' => $basis,
            'review_due_on' => mam_v2_plan_str($pr['review_due_on'] ?? null, 10),
            'status' => $status,
            'notes' => mam_v2_plan_str($pr['notes'] ?? null),
            'created_at' => mam_v2_plan_ts($pr['created_at'] ?? null, $nowFallback)];
        $procOut++;
    }
    $note('dp_processors', 'dp_processors',
        'a live processor outside Nigeria with no stated transfer basis is recorded as SUSPENDED and raised: '
        . 'naming the country records the risk and nothing about how it is answered.',
        count($rows('dp_processors')), $procOut);

    $dpnOut = 0; $dpnSeen = [];
    foreach ($rows('dp_register_notes') as $n) {
        $kind = (string) ($n['kind'] ?? 'purpose');
        $ref = mam_v2_plan_str($n['ref_key'] ?? null, 64);
        if ($ref === null || !in_array($kind, ['purpose', 'retention'], true)) continue;
        $k = $kind . ':' . $ref;
        if (isset($dpnSeen[$k])) continue;
        $dpnSeen[$k] = true;
        $T['dp_register_notes'][] = ['tenant_id' => $tenantId, 'kind' => $kind, 'ref_key' => $ref,
            'keep_days' => mam_v2_plan_int($n['keep_days'] ?? null),
            'notes' => mam_v2_plan_str($n['notes'] ?? null),
            'reviewed_on' => mam_v2_plan_str($n['reviewed_on'] ?? null, 10),
            'reviewed_by' => $userOfV1User[(int) ($n['reviewed_by'] ?? 0)] ?? null];
        $dpnOut++;
    }
    $note('dp_register_notes', 'dp_register_notes',
        'the academy\'s annotations only. The ROPA and the retention schedule themselves are CODE registries '
        . 'asserted against the live schema, because a register describing what the software does must be '
        . 'derived from it.', count($rows('dp_register_notes')), $dpnOut);

    $autoOut = 0;
    foreach ($rows('automations') as $a) {
        $key = mam_v2_plan_str($a['akey'] ?? null, 64);
        if ($key === null) continue;
        $T['automation_rules'][] = ['tenant_id' => $tenantId, 'rule_key' => $key,
            'is_enabled' => mam_v2_plan_bool($a['enabled'] ?? null, 1),
            'params' => mam_v2_plan_str($a['params'] ?? null),
            // Telemetry is carried: "when did this last run" is how an admin
            // tells a broken sweep from an idle one, and resetting it to zero on
            // cutover day hides a rule that has never fired.
            'last_run_at' => mam_v2_plan_ts($a['last_run_at'] ?? null, null),
            'run_count' => max(0, mam_v2_plan_int($a['run_count'] ?? null) ?? 0),
            'last_error' => null];
        $autoOut++;
    }
    $note('automations', 'automation_rules',
        'the on/off flags AND the run telemetry: "when did this last run" is how an admin tells a broken '
        . 'sweep from an idle one, and zeroing it on cutover day hides a rule that has never fired.',
        count($rows('automations')), $autoOut);

    $aiOut = 0;
    foreach ($rows('ai_runs') as $r) {
        $ok = mam_v2_plan_bool($r['ok'] ?? null, 0);
        $err = mam_v2_plan_str($r['error'] ?? null, 500);
        // chk_airun_failed: a failed call says what went wrong. v1 could store a
        // failure with no error, which is a row that proves only that something
        // happened.
        if ($ok === 0 && $err === null) $err = 'Error not recorded in the previous system';
        $revBy = $userOfV1User[(int) ($r['reviewed_by'] ?? 0)] ?? null;
        $revAt = mam_v2_plan_ts($r['reviewed_at'] ?? null, null);
        if ($revBy === null || $revAt === null) { $revBy = null; $revAt = null; }
        $T['ai_runs'][] = ['tenant_id' => $tenantId,
            'use_case' => mam_v2_plan_str($r['use_case'] ?? null, 48) ?? 'unknown',
            'provider' => mam_v2_plan_str($r['provider'] ?? null, 32),
            'model' => mam_v2_plan_str($r['model'] ?? null, 64),
            'ok' => $ok,
            // Only the HASH. v1 kept no prompt text either, and v2 has no column
            // for it: the prompts contain applicants' words about their children.
            'prompt_hash' => mam_v2_plan_str($r['prompt_hash'] ?? null, 64),
            'input_tokens' => max(0, mam_v2_plan_int($r['input_tokens'] ?? null) ?? 0),
            'output_tokens' => max(0, mam_v2_plan_int($r['output_tokens'] ?? null) ?? 0),
            'latency_ms' => null, 'error' => $err,
            'reviewed_by' => $revBy, 'reviewed_at' => $revAt,
            'created_at' => mam_v2_plan_ts($r['created_at'] ?? null, $nowFallback)];
        $aiOut++;
    }
    $note('ai_runs', 'ai_runs',
        'cost and outcome telemetry, with no prompt or output text on either side — keeping them would turn '
        . 'a cost ledger into a second copy of the most sensitive free text in the system.',
        count($rows('ai_runs')), $aiOut);

    $prodId = 0; $prodIdOfSku = [];
    foreach ($rows('store_items') as $it) {
        $sku = mam_v2_plan_str($it['sku'] ?? null, 64);
        $slug = mam_v2_plan_str($it['slug'] ?? null, 96);
        if ($sku === null || $slug === null) { $rejected[] = ['table' => 'store_items', 'id' => $it['id'] ?? null, 'why' => 'no SKU or no slug']; continue; }
        $goods = (string) ($it['goods_type'] ?? 'digital');
        $delivery = (string) ($it['delivery_type'] ?? 'instant');
        $fileUrl = mam_v2_plan_str($it['file_url'] ?? null, 1000);
        // chk_sprod_file: an instantly-delivered digital product needs a file.
        // Where v1 has none the product is carried as NOT active — it cannot be
        // fulfilled, and listing it sells somebody nothing.
        $active = mam_v2_plan_bool($it['active'] ?? null, 1);
        $att = null;
        if ($goods === 'digital' && $delivery === 'instant') {
            if ($fileUrl === null) {
                $flag('store_digital_no_file', ['table' => 'store_items', 'sku' => $sku,
                    'action' => 'An instantly-delivered digital product with no file. Carried as inactive; '
                        . 'attach the file before listing it.']);
                $active = 0;
                $delivery = 'email';
            }
        }
        // v1 priced in REAL dollars. Converted through string arithmetic, not a
        // float multiply: measured on PHP 8.4, `8.29 * 100` is 828.99999999999989,
        // so `(int)` truncation loses a kobo on a price the academy set exactly.
        $price = (string) ($it['price_usd'] ?? '0');
        $minor = mam_v2_plan_int(str_replace('.', '', number_format((float) $price, 2, '.', '')));
        $stockRaw = mam_v2_plan_int($it['stock'] ?? null);
        $id = ++$prodId;
        $T['store_products'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('product:' . $sku),
            'tenant_id' => $tenantId, 'sku' => $sku, 'slug' => $slug,
            'name' => mam_v2_plan_str($it['name'] ?? null, 190) ?? $sku,
            'description' => mam_v2_plan_str($it['description'] ?? null),
            'goods_type' => in_array($goods, ['digital', 'physical', 'service'], true) ? $goods : 'digital',
            'delivery_type' => in_array($delivery, ['instant', 'ship', 'collect', 'email'], true) ? $delivery : 'email',
            'availability' => in_array((string) ($it['availability'] ?? 'in_stock'),
                ['in_stock', 'out_of_stock', 'preorder', 'discontinued'], true)
                ? (string) $it['availability'] : 'in_stock',
            // v1 used -1 to mean "not tracked", which sorts and sums as a real
            // quantity — "total stock" across the catalogue came out negative.
            // NULL says untracked and cannot be added up by accident.
            'stock' => ($stockRaw === null || $stockRaw < 0) ? null : $stockRaw,
            'price_minor' => $minor ?? 0,
            'currency' => strtoupper(mam_v2_plan_str($it['currency'] ?? null, 3) ?? 'USD'),
            'tax_rate_pct' => number_format((float) ($it['tax_rate'] ?? 0), 2, '.', ''),
            'weight_grams' => mam_v2_plan_int($it['weight_grams'] ?? null),
            'category' => mam_v2_plan_str($it['category'] ?? null, 48),
            'file_attachment_id' => $att,
            'sort_order' => mam_v2_plan_int($it['sort_order'] ?? null) ?? 0,
            'is_active' => $active,
            'created_at' => mam_v2_plan_ts($it['created_at'] ?? null, $nowFallback), 'deleted_seq' => 0];
        $prodIdOfSku[mb_strtolower($sku)] = $id;
        $addAttachment('store_product', $id, $fileUrl, 'document',
            mam_v2_plan_ts($it['created_at'] ?? null, $nowFallback), null, 'store-file:' . $sku);
        $mapped('store_items', (int) ($it['id'] ?? 0), 'store_products', $id, $sku);
    }
    $note('store_items', 'store_products (+ the deliverable file as an attachment)',
        'merchandise is NOT tuition: mapping a hoodie into `price_items` would misstate what it is and put '
        . 'it in every tuition report. v1\'s -1 stock sentinel becomes NULL — it sorted and summed as a real '
        . 'quantity, so "total stock" came out negative.',
        count($rows('store_items')), $prodId);

    $ordId = 0; $ordItems = 0;
    foreach ($rows('store_orders') as $o) {
        $v1id = (int) ($o['id'] ?? 0);
        $ref = mam_v2_plan_str($o['reference'] ?? null, 64);
        $email = mam_v2_plan_str($o['customer_email'] ?? null, 320);
        $mid = null;
        if ($email !== null) {
            foreach ($rows('users') as $u) {
                if (strcasecmp((string) ($u['email'] ?? ''), $email) === 0) {
                    $mid = $memberOfV1User[(int) ($u['id'] ?? 0)] ?? null; break;
                }
            }
        }
        if ($ref === null || ($email === null && $mid === null)) {
            $rejected[] = ['table' => 'store_orders', 'id' => $v1id,
                'why' => $ref === null ? 'no reference to quote back' : 'no customer and no address to send it to'];
            continue;
        }
        $toMinor = static fn($v) => (int) str_replace('.', '', number_format((float) ($v ?? 0), 2, '.', ''));
        $sub = $toMinor($o['subtotal_usd'] ?? 0);
        $tax = $toMinor($o['tax_usd'] ?? 0);
        $ship = $toMinor($o['shipping_usd'] ?? 0);
        $stated = $toMinor($o['total_usd'] ?? 0);
        // chk_sorder_total. As with an invoice, the ARITHMETIC wins and a
        // disagreement is raised: v1 stored REAL dollars, so a rounding
        // difference between the cart and the receipt was invisible until a
        // customer added it up.
        $total = $sub + $tax + $ship;
        $discount = 0;
        if ($stated !== $total) {
            if ($stated < $total) { $discount = $total - $stated; $total = $stated; }
            $flag('store_total_restated', ['table' => 'store_orders', 'id' => $v1id, 'stated' => $stated,
                'computed' => $sub + $tax + $ship,
                'action' => $stated < $sub + $tax + $ship
                    ? 'The stated total is lower than its own lines; the difference is recorded as a discount.'
                    : 'The stated total is HIGHER than its own lines. The arithmetic was kept; confirm what '
                      . 'the customer was charged.']);
        }
        $status = (string) ($o['status'] ?? 'pending');
        $vocab = ['pending' => 'pending', 'paid' => 'paid', 'fulfilled' => 'fulfilled',
                  'delivered' => 'delivered', 'cancelled' => 'cancelled', 'refunded' => 'refunded'];
        $status = $vocab[$status] ?? 'pending';
        $delivered = mam_v2_plan_ts($o['delivered_at'] ?? null, null);
        $created = mam_v2_plan_ts($o['created_at'] ?? null, $nowFallback);
        // chk_sorder_paid: anything past pending was paid at some point. v1 kept
        // no paid_at, so the delivery time (or the order time) is the honest
        // floor rather than a guess at the gateway's clock.
        $paidAt = in_array($status, ['pending', 'cancelled'], true) ? null : ($delivered ?? $created);
        $id = ++$ordId;
        $T['store_orders'][] = ['id' => $id, 'public_id' => mam_v2_plan_pid('order:' . $ref, $created),
            'tenant_id' => $tenantId, 'reference' => $ref, 'membership_id' => $mid,
            'customer_name' => mam_v2_plan_str($o['customer_name'] ?? null, 190),
            'customer_email' => $email,
            'customer_phone' => mam_v2_plan_str($o['customer_phone'] ?? null, 32),
            'customer_country' => mam_v2_plan_str($o['customer_country'] ?? null, 64),
            'shipping_address' => mam_v2_plan_str($o['shipping_address'] ?? null),
            'status' => $status,
            'subtotal_minor' => $sub, 'tax_minor' => $tax, 'shipping_minor' => $ship,
            'discount_minor' => $discount, 'total_minor' => $total,
            'currency' => 'USD', 'base_currency' => 'USD',
            'base_total_minor' => null, 'fx_rate' => null,
            'promotion_id' => null, 'payment_id' => null,
            'notes' => mam_v2_plan_str($o['notes'] ?? null),
            'paid_at' => $paidAt, 'delivered_at' => $delivered, 'created_at' => $created];
        $mapped('store_orders', $v1id, 'store_orders', $id, $ref);
        // items_json becomes ROWS. In v1 "how many hoodies did we sell" was a
        // question no query could answer.
        $items = json_decode((string) ($o['items_json'] ?? ''), true);
        if (is_array($items)) {
            foreach ($items as $line) {
                if (!is_array($line)) continue;
                $sku = mam_v2_plan_str($line['sku'] ?? null, 64);
                $qty = max(1, mam_v2_plan_int($line['qty'] ?? ($line['quantity'] ?? 1)) ?? 1);
                $unit = $toMinor($line['price_usd'] ?? ($line['price'] ?? 0));
                $T['store_order_items'][] = ['tenant_id' => $tenantId, 'order_id' => $id,
                    'product_id' => $sku !== null ? ($prodIdOfSku[mb_strtolower($sku)] ?? null) : null,
                    'sku' => $sku,
                    // The name AT THE TIME: a receipt must not change because
                    // somebody edited the catalogue afterwards.
                    'name' => mam_v2_plan_str($line['name'] ?? null, 190) ?? ($sku ?? 'Item'),
                    'quantity' => $qty, 'unit_price_minor' => $unit,
                    'line_total_minor' => $unit * $qty, 'currency' => 'USD'];
                $ordItems++;
            }
        }
    }
    $note('store_orders', 'store_orders + store_order_items',
        sprintf('`items_json` becomes %d ROW(S): in v1 "how many hoodies did we sell" was a question no '
            . 'query could answer, and a line\'s product could be renamed underneath it. Totals are '
            . 'recomputed in minor units — v1 stored REAL dollars, so a rounding difference between the '
            . 'cart and the receipt was invisible until a customer added it up.', $ordItems),
        count($rows('store_orders')), $ordId);

    $notifOut = 0;
    foreach ($rows('notifications') as $n) {
        $mid = $memberOfV1User[(int) ($n['user_id'] ?? 0)] ?? null;
        if ($mid === null) { $rejected[] = ['table' => 'notifications', 'id' => $n['id'] ?? null, 'why' => 'the recipient did not map to a membership']; continue; }
        $T['notifications'][] = ['tenant_id' => $tenantId, 'membership_id' => $mid,
            'type' => mam_v2_plan_str($n['type'] ?? null, 48) ?? 'notice',
            'title' => mam_v2_plan_str($n['title'] ?? null, 190) ?? 'Notice',
            'body' => mam_v2_plan_str($n['body'] ?? null),
            'url' => mam_v2_plan_str($n['url'] ?? null, 500),
            'action_label' => null, 'severity' => 'info',
            // No dedupe key: v1 had none, and inventing one would suppress the
            // new system's first legitimate send of the same notice.
            'dedupe_key' => null, 'group_key' => null, 'expires_at' => null,
            'read_at' => mam_v2_plan_ts($n['read_at'] ?? null, null),
            'acknowledged_at' => null,
            'created_at' => mam_v2_plan_ts($n['created_at'] ?? null, $nowFallback)];
        $notifOut++;
    }
    $note('notifications', 'notifications',
        'carried with no dedupe key: v1 had none, and inventing one would suppress the new system\'s first '
        . 'legitimate send of the same notice.', count($rows('notifications')), $notifOut);

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
