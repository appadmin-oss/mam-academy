<?php
/**
 * ETL — identity resolution (v1 `customers` + `users` → v2 `users`).
 * ─────────────────────────────────────────────────────────────
 * v1 keeps a person in two places: `customers` is who applied (name, DOB,
 * address, phone) and `users` is who can log in (email, password hash, role).
 * They overlap only where somebody both applied and was given a portal account,
 * and neither table references the other. v2 has ONE `users` row per human with
 * a `memberships` row per tenant, so the merge has to happen here, once,
 * deliberately — not implicitly at INSERT time where a unique-key collision
 * would decide it.
 *
 * Measured on the real export: 16 customers, 10 users, 5 shared addresses →
 * 21 distinct people.
 *
 * ── The rule that matters most ──
 * A person who exercised their right to erasure MUST stay erased. v1 marks
 * those rows with a sentinel address (`erased-c1@deleted.invalid`) and blanks
 * the name. Two exist in the live data. An ETL that "resolves identity" by
 * looking at the surrounding rows — the six applications still attached to one
 * of them, say — would quietly rebuild the person the academy was asked to
 * forget. So the sentinel is detected FIRST and the row is carried across as a
 * tombstone: still present, so its applications keep a valid owner and the
 * counts reconcile, but carrying no recoverable identity and never emailable.
 *
 * `.invalid` is reserved by RFC 2606 and can never route, which is why it is a
 * safe sentinel and also why such an address must never reach the mailer.
 *
 * ── How a merge is decided ──
 * v1 records the link EXPLICITLY: `users.customer_id`. Three of the five merges
 * in the real snapshot are stated there, and an email-keyed rule that ignores
 * it is doing by inference what the data already asserts. So:
 *
 *   1. `users.customer_id` wins outright. It is the previous system's own
 *      statement that these two rows are one person.
 *   2. A shared address merges ONLY when the two rows agree about the name.
 *   3. A shared address where the names DISAGREE and no explicit link exists
 *      does NOT merge. It is raised for review instead.
 *
 * Rule 3 is the one that matters. In the live data one address belongs to an
 * instructor and was also used to apply on behalf of a child: merging them
 * would fuse a learner's application into a staff record, hand that instructor
 * the child's answers, and there is no way back from it without the snapshot.
 * Splitting them wrongly leaves two rows a human joins in a minute. The
 * asymmetry decides it — the same asymmetry that already governs `mam_v2_email_key()`.
 *
 * A split needs distinct addresses, because `users.email` is UNIQUE: the LOGIN
 * owner keeps the real one and the applicant gets an unroutable derived
 * address, with the real address preserved on the review item so nobody has to
 * go back to the export to act on it.
 */
declare(strict_types=1);

/** Addresses under this TLD are tombstones, never destinations. */
const MAM_V2_ERASED_SUFFIX = '.invalid';

/**
 * Normalise an address for MATCHING only.
 *
 * Deliberately conservative: lowercase and trim, nothing else. Gmail dot- and
 * plus-folding would merge `a.b@gmail.com` with `ab@gmail.com`, which is true
 * for Gmail and false for most other hosts — and a wrong merge fuses two
 * people's records together, which is unrecoverable without going back to the
 * snapshot. Under-merging leaves two rows a human can join later; over-merging
 * destroys data. The asymmetry decides the rule.
 *
 * The normalised form is used to compare, never to store: the address written
 * to v2 is the one the person actually gave.
 */
function mam_v2_email_key(?string $email): string
{
    return strtolower(trim((string) $email));
}

/** Was this row erased under a data-rights request? */
function mam_v2_is_erased(?string $email): bool
{
    $e = mam_v2_email_key($email);
    return $e !== '' && str_ends_with($e, MAM_V2_ERASED_SUFFIX);
}
/**
 * A name reduced to what can be COMPARED: lowercase, punctuation dropped,
 * tokens sorted. Sorting means "Onofua Joshua" and "Joshua Onofua" agree, which
 * they do — v1 collected given and family names in both orders across the two
 * tables.
 *
 * Deliberately NOT fuzzy. No edit distance, no prefix matching, no diacritic
 * folding: a name that differs only by an accent reads as a disagreement and
 * gets a human's eye, which is the safe direction. Every fuzzy rule that would
 * have merged "App Admin" with "Application Domain" also merges names that are
 * genuinely different, and this function decides whether two people's records
 * get fused.
 */
function mam_v2_name_key(?string $name): string
{
    $n = function_exists('mb_strtolower') ? mb_strtolower((string) $name, 'UTF-8') : strtolower((string) $name);
    $n = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $n) ?? '';
    $t = array_values(array_filter(explode(' ', trim($n)), static fn($x) => $x !== ''));
    sort($t);
    return implode(' ', $t);
}

/**
 * Do two rows agree about whose name this is?
 *
 * A blank agrees with anything — one v1 table often has the name and the other
 * does not, and refusing to merge over an absence would split almost everybody.
 */
function mam_v2_names_agree(?string $a, ?string $b): bool
{
    $ka = mam_v2_name_key($a);
    $kb = mam_v2_name_key($b);
    return $ka === '' || $kb === '' || $ka === $kb;
}

/**
 * Resolve v1 customers + users into one person per human.
 *
 * Returns ['people' => [...], 'report' => [...]]. Every input row appears in
 * exactly one person's `sources`, so the load can assert that nobody was
 * dropped — the ETL's row-preservation guarantee is only checkable if the
 * mapping is explicit.
 *
 * Each person:
 *   email          the address to write (the login address where one exists)
 *   full_name      best available; never reconstructed for an erased row
 *   erased         true = tombstone, no credentials, never emailable
 *   sources        ['customers:3', 'users:9'] — every v1 row folded in
 *   customer_id    v1 ids, kept so applications/payments can be repointed
 *   user_id
 *   merged_by      'explicit_link' | 'email_and_name' | null
 *   contact_email  set only on a SPLIT applicant whose address belongs to
 *                  somebody else's login; the address is unroutable in `email`
 *                  and the real one is here, for the review item
 *
 * `report['review']` lists every decision a human should confirm. It is not a
 * warning count — each entry names the two v1 rows, the shared address and what
 * to do about it.
 */
function mam_v2_resolve_identities(array $customers, array $users): array
{
    $people = [];
    $report = ['customers' => count($customers), 'users' => count($users),
               'merged' => 0, 'merged_explicit' => 0, 'merged_email' => 0,
               'split_shared_email' => 0, 'erased' => 0, 'no_email' => 0,
               'conflicts' => [], 'review' => []];

    /* ── Pass 1 · customers ────────────────────────────────────────────────
     * One person per customer row to start with, indexed by email so two
     * customer rows sharing an address can fold together under the same
     * name test the users get.
     */
    $byCustomer = [];      // v1 customer id => person index
    $byEmail    = [];      // email key      => person index
    foreach ($customers as $c) {
        $cid    = (int) ($c['id'] ?? 0);
        $key    = mam_v2_email_key($c['email'] ?? null);
        $erased = mam_v2_is_erased($c['email'] ?? null);
        $name   = trim(((string) ($c['first_name'] ?? '')) . ' ' . ((string) ($c['last_name'] ?? '')));

        // Two customer rows on one address: same rule as a user — names must
        // agree. The real snapshot has a near-miss pair (one address is a typo
        // of the other) which correctly stays two people.
        $into = null;
        if ($key !== '' && isset($byEmail[$key])
            && mam_v2_names_agree($people[$byEmail[$key]]['full_name'] ?? null, $erased ? '' : $name)) {
            $into = $byEmail[$key];
        }

        if ($into !== null) {
            $people[$into]['sources'][] = 'customers:' . $cid;
            $people[$into]['customer_ids'][] = $cid;
            if (($people[$into]['full_name'] ?? '') === '' && !$erased) $people[$into]['full_name'] = $name;
            if (($people[$into]['phone'] ?? null) === null && !$erased) $people[$into]['phone'] = $c['phone'] ?? null;
            if (($people[$into]['dob'] ?? null) === null && !$erased)   $people[$into]['dob']   = $c['dob'] ?? null;
            if ($erased) $people[$into]['erased'] = true;
            $people[$into]['merged_by'] = 'email_and_name';
            $report['merged']++; $report['merged_email']++;
            $byCustomer[$cid] = $into;
            if ($erased) $report['erased']++;
            continue;
        }

        if ($key === '') $report['no_email']++;
        $ix = count($people);
        $people[] = [
            'email'         => (string) ($c['email'] ?? ''),
            'full_name'     => $erased ? '' : $name,
            'phone'         => $erased ? null : ($c['phone'] ?? null),
            'dob'           => $erased ? null : ($c['dob'] ?? null),
            'erased'        => $erased,
            'sources'       => ['customers:' . $cid],
            'name_variants' => ($erased || $name === '') ? [] : [$name],
            'customer_id'   => $cid,
            'customer_ids'  => [$cid],
            'user_id'       => null,
            'merged_by'     => null,
            'contact_email' => null,
        ];
        $byCustomer[$cid] = $ix;
        if ($key !== '' && !isset($byEmail[$key])) $byEmail[$key] = $ix;
        if ($erased) $report['erased']++;
    }

    /* ── Pass 2 · users ────────────────────────────────────────────────────
     * Rule 1 (explicit link) before rule 2 (shared address), so a stated link
     * is never overruled by a coincidence of addresses.
     */
    foreach ($users as $u) {
        $uid    = (int) ($u['id'] ?? 0);
        $key    = mam_v2_email_key($u['email'] ?? null);
        $erased = mam_v2_is_erased($u['email'] ?? null);
        $name   = (string) ($u['name'] ?? '');

        $into = null;
        $how  = null;

        // 1 · v1's own statement that these rows are one person.
        $linked = isset($u['customer_id']) && $u['customer_id'] !== null ? (int) $u['customer_id'] : 0;
        if ($linked && isset($byCustomer[$linked]) && $people[$byCustomer[$linked]]['user_id'] === null) {
            $into = $byCustomer[$linked];
            $how  = 'explicit_link';
        }

        // 2 · a shared address, but only where the names agree.
        if ($into === null && $key !== '' && isset($byEmail[$key])) {
            $cand = $byEmail[$key];
            if ($people[$cand]['user_id'] !== null) {
                // The address already belongs to a login. Nothing to merge into.
            } elseif (mam_v2_names_agree($people[$cand]['full_name'] ?? null, $erased ? '' : $name)) {
                $into = $cand;
                $how  = 'email_and_name';
            } else {
                // 3 · Do NOT merge. The login keeps the address; the applicant
                // gets an unroutable one and the real address is carried on the
                // review item so it can be acted on without the export.
                $real = (string) $people[$cand]['email'];
                $people[$cand]['contact_email'] = $real;
                $people[$cand]['email'] = 'shared-' . preg_replace('/[^a-z0-9]+/i', '-',
                        (string) strstr($real . '@', '@', true))
                    . '-c' . (string) $people[$cand]['customer_id'] . '@migrated.invalid';
                $people[$cand]['emailable'] = false;
                $report['split_shared_email']++;
                $report['review'][] = [
                    'kind'          => 'shared_email_names_disagree',
                    'customer'      => 'customers:' . (string) $people[$cand]['customer_id'],
                    'user'          => 'users:' . $uid,
                    'shared_email'  => $real,
                    'customer_name' => (string) $people[$cand]['full_name'],
                    'user_name'     => $name,
                    'decision'      => 'kept apart — the login owns the address',
                    'action'        => 'Confirm whether this is one person (join the two v2 users) or a '
                                     . 'guardian who applied for a child (record a guardianship and give '
                                     . 'the learner a contactable address).',
                ];
            }
        }

        if ($into !== null) {
            $people[$into]['sources'][] = 'users:' . $uid;
            $people[$into]['user_id']   = $uid;
            $people[$into]['merged_by'] = $how;
            // The login address wins outright: it is the one they type.
            if ($key !== '') $people[$into]['email'] = (string) $u['email'];
            if ($name !== '' && !$erased && !in_array($name, $people[$into]['name_variants'], true)) {
                $people[$into]['name_variants'][] = $name;
            }
            if (($people[$into]['full_name'] ?? '') === '' && !$erased) $people[$into]['full_name'] = $name;
            if (($people[$into]['phone'] ?? null) === null && !$erased) $people[$into]['phone'] = $u['phone'] ?? null;
            // An erasure on EITHER row makes the person a tombstone. A customer
            // erased but still holding a live portal account is a v1
            // inconsistency, and the safe reading of "forget me" is the stricter one.
            if ($erased) { $people[$into]['erased'] = true; $people[$into]['full_name'] = ''; }
            $report['merged']++;
            $how === 'explicit_link' ? $report['merged_explicit']++ : $report['merged_email']++;
            if ($erased) $report['erased']++;
            continue;
        }

        if ($key === '') $report['no_email']++;
        $ix = count($people);
        $people[] = [
            'email'         => (string) ($u['email'] ?? ''),
            'full_name'     => $erased ? '' : $name,
            'phone'         => $erased ? null : ($u['phone'] ?? null),
            'dob'           => null,
            'erased'        => $erased,
            'sources'       => ['users:' . $uid],
            'name_variants' => ($erased || $name === '') ? [] : [$name],
            'customer_id'   => null,
            'customer_ids'  => [],
            'user_id'       => $uid,
            'merged_by'     => null,
            'contact_email' => null,
        ];
        if ($key !== '' && !isset($byEmail[$key])) $byEmail[$key] = $ix;
        if ($erased) $report['erased']++;
    }

    /* ── Derived flags ─────────────────────────────────────────────────────── */
    foreach ($people as $i => $p) {
        if (!empty($p['erased'])) {
            // A tombstone must never be handed to the mailer or given credentials.
            $people[$i]['full_name']     = '';
            $people[$i]['phone']         = null;
            $people[$i]['dob']           = null;
            $people[$i]['contact_email'] = null;
            $people[$i]['emailable']     = false;
            $people[$i]['credentials']   = false;
        } else {
            $people[$i]['emailable']   = $p['email'] !== '' && !($p['contact_email'] !== null);
            $people[$i]['credentials'] = $p['user_id'] !== null;
        }
        $v = array_values(array_unique(array_filter((array) ($p['name_variants'] ?? []))));
        $people[$i]['name_variants'] = $v;
        // Two spellings of the same person's name, kept because v2 stores only
        // the winner — if this is not recorded at merge time the other is gone.
        $people[$i]['name_conflict'] = count($v) > 1;
    }
    $report['name_conflicts'] = count(array_filter($people, static fn($p) => !empty($p['name_conflict'])));
    $report['people'] = count($people);
    return ['people' => $people, 'report' => $report];
}

/**
 * Every v1 row must land in exactly one person. Returns the rows that did not,
 * which `verify.php` gates on — "row-preserving" is a claim, and this is what
 * makes it checkable rather than asserted.
 */
function mam_v2_identity_unaccounted(array $customers, array $users, array $people): array
{
    $seen = [];
    foreach ($people as $p) foreach ($p['sources'] as $s) $seen[$s] = ($seen[$s] ?? 0) + 1;
    $missing = [];
    foreach ($customers as $c) { $k = 'customers:' . (string) ($c['id'] ?? '?'); if (($seen[$k] ?? 0) !== 1) $missing[] = $k; }
    foreach ($users as $u)     { $k = 'users:' . (string) ($u['id'] ?? '?');     if (($seen[$k] ?? 0) !== 1) $missing[] = $k; }
    return $missing;
}
