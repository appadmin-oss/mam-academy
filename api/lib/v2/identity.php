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
 * Resolve v1 customers + users into one person per human.
 *
 * Returns ['people' => [...], 'report' => [...]]. Every input row appears in
 * exactly one person's `sources`, so the load can assert that nobody was
 * dropped — the ETL's row-preservation guarantee is only checkable if the
 * mapping is explicit.
 *
 * Each person:
 *   email          the address to write (the user's, if both exist — that is
 *                  the one they log in with)
 *   full_name      best available; never reconstructed for an erased row
 *   erased         true = tombstone, no credentials, never emailable
 *   sources        ['customers:3', 'users:9'] — every v1 row folded in
 *   customer_id    v1 ids, kept so applications/payments can be repointed
 *   user_id
 */
function mam_v2_resolve_identities(array $customers, array $users): array
{
    $people = [];      // email key => person
    $byKey   = [];
    $report  = ['customers' => count($customers), 'users' => count($users),
                'merged' => 0, 'erased' => 0, 'no_email' => 0, 'conflicts' => []];

    $put = function (string $key, array $fields, string $source) use (&$people, &$byKey, &$report): void {
        // Every distinct name the source rows carried is kept, not only the one
        // that wins. A merge is decided on the email, and where the two rows
        // disagree about WHO the email belongs to that is worth a human's eye:
        // a guardian's address used to apply for a child looks exactly like one
        // person to an email-keyed rule, and merging them makes a parent and
        // their child a single record. Under-merging is recoverable; this is not.
        $variants = ($fields['full_name'] ?? '') !== '' ? [$fields['full_name']] : [];
        if (!isset($byKey[$key])) {
            $byKey[$key] = count($people);
            $people[] = $fields + ['sources' => [$source], 'name_variants' => $variants,
                                   'customer_id' => null, 'user_id' => null];
            return;
        }
        $i = $byKey[$key];
        $people[$i]['sources'][] = $source;
        foreach ($variants as $v) {
            if (!in_array($v, (array) ($people[$i]['name_variants'] ?? []), true)) {
                $people[$i]['name_variants'][] = $v;
            }
        }
        $report['merged']++;
        foreach ($fields as $k => $v) {
            if ($k === 'sources') continue;
            // First non-empty wins, EXCEPT where the caller marks a field
            // authoritative by passing it on the second (user) pass — a user's
            // email is the login address and outranks the applicant's.
            if (($people[$i][$k] ?? null) === null || $people[$i][$k] === '') $people[$i][$k] = $v;
        }
    };

    foreach ($customers as $c) {
        $key = mam_v2_email_key($c['email'] ?? null);
        $erased = mam_v2_is_erased($c['email'] ?? null);
        if ($key === '') {
            // No address at all: it cannot be matched to anything, so it gets
            // its own person keyed on the source row rather than being dropped
            // or — worse — all such rows collapsing into one shared blank.
            $key = 'customers:' . (string) ($c['id'] ?? count($people));
            $report['no_email']++;
        }
        $name = trim(((string) ($c['first_name'] ?? '')) . ' ' . ((string) ($c['last_name'] ?? '')));
        $put($key, [
            'email'      => (string) ($c['email'] ?? ''),
            'full_name'  => $erased ? '' : $name,
            'phone'      => $erased ? null : ($c['phone'] ?? null),
            'dob'        => $erased ? null : ($c['dob'] ?? null),
            'erased'     => $erased,
        ], 'customers:' . (string) ($c['id'] ?? '?'));
        $people[$byKey[$key]]['customer_id'] = $c['id'] ?? null;
        if ($erased) $report['erased']++;
    }

    foreach ($users as $u) {
        $key = mam_v2_email_key($u['email'] ?? null);
        $erased = mam_v2_is_erased($u['email'] ?? null);
        if ($key === '') { $key = 'users:' . (string) ($u['id'] ?? count($people)); $report['no_email']++; }
        $put($key, [
            'email'     => (string) ($u['email'] ?? ''),
            'full_name' => $erased ? '' : (string) ($u['name'] ?? ''),
            'phone'     => $erased ? null : ($u['phone'] ?? null),
            'dob'       => null,
            'erased'    => $erased,
        ], 'users:' . (string) ($u['id'] ?? '?'));
        $i = $byKey[$key];
        $people[$i]['user_id'] = $u['id'] ?? null;
        // The login address wins outright when the two rows disagree in case or
        // whitespace — it is the one the person types.
        if (!empty($u['email'])) $people[$i]['email'] = (string) $u['email'];
        // An erasure on EITHER row makes the person a tombstone. A customer
        // erased but still holding a live portal account is a v1 inconsistency,
        // and the safe reading of "forget me" is the stricter one.
        if ($erased) { $people[$i]['erased'] = true; $people[$i]['full_name'] = ''; }
        if ($erased) $report['erased']++;
    }

    // A tombstone must never be handed to the mailer or given credentials.
    foreach ($people as $i => $p) {
        if (!empty($p['erased'])) {
            $people[$i]['full_name']  = '';
            $people[$i]['phone']      = null;
            $people[$i]['dob']        = null;
            $people[$i]['emailable']  = false;
            $people[$i]['credentials'] = false;
        } else {
            $people[$i]['emailable']   = $p['email'] !== '';
            $people[$i]['credentials'] = $p['user_id'] !== null;
        }
    }

    // A merge whose source rows disagree about the person's name. Counted here
    // so the load can report it and nobody has to notice it by chance.
    $report['name_conflicts'] = 0;
    foreach ($people as $i => $p) {
        $v = array_values(array_unique(array_filter((array) ($p['name_variants'] ?? []))));
        $people[$i]['name_variants'] = $v;
        $people[$i]['name_conflict'] = count($v) > 1;
        if (count($v) > 1) $report['name_conflicts']++;
    }

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
