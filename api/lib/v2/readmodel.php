<?php
/**
 * The v2 READ MODEL — option B of docs/v2-cutover-readiness-2026-08-18.md.
 * ─────────────────────────────────────────────────────────────
 * The ETL can load a v2 database and prove it correct; the application cannot
 * serve from it, and rewriting ~56K lines of PHP against a different schema is
 * not a step anybody takes in one go. So v2 arrives the cheap way first: loaded,
 * verified, and read for reporting — where the queries are new code anyway —
 * while every screen that runs the academy keeps reading v1 exactly as it does
 * today.
 *
 * Four properties, and the first two are the whole design:
 *
 *  1. **Read-only by construction, not by convention.** The connection is opened
 *     with SQLite's `mode=ro` URI *and* `PRAGMA query_only = 1`, and a MySQL one
 *     gets `SET SESSION TRANSACTION READ ONLY`. There is no insert, update or
 *     delete helper in this file to call. A read model that can write is a
 *     second source of truth, which is strictly worse than no read model.
 *
 *  2. **It fails CLOSED, and never falls back to v1.** Not configured,
 *     unreachable, or missing a table → every function returns an absent shape
 *     and says why. It must never quietly answer from the live database: a
 *     figure attributed to the wrong source is worse than a figure that is
 *     missing, because the reader cannot tell.
 *
 *  3. **Staleness travels with every answer.** This is a SNAPSHOT. `loaded_at`
 *     comes back beside the numbers so a screen cannot present a week-old total
 *     as today's — the characteristic way a read model misleads.
 *
 *  4. **It only answers what v2 can answer better.** Three things, because only
 *     four v1 tables are loaded: the *merged person* (v1 keeps customers and
 *     users apart and cannot join them), money in exact minor units with
 *     `awaiting_transfer` distinguished from `pending`, and resolution of a
 *     legacy reference to whatever it became. Anything else belongs in v1.
 *
 * Configuration is by ENV, not by a setting: this is infrastructure, and a
 * pointer to another database is not something to edit in a web form. Unset →
 * the feature is simply absent.
 *
 *   V2_READ_DB=/path/to/v2.db                 # SQLite
 *   V2_READ_DSN=mysql:host=…;dbname=…         # or a full PDO DSN
 *   V2_READ_USER= / V2_READ_PASS=             # for MySQL
 *   V2_READ_TENANT=mam                        # which tenant to report on
 */
declare(strict_types=1);

/** Is a v2 read model pointed at anything at all? */
function mam_v2_rm_configured(): bool
{
    return trim((string) mam_env('V2_READ_DB', '')) !== ''
        || trim((string) mam_env('V2_READ_DSN', '')) !== '';
}

/**
 * The read-only handle, or null. Cached per request; a failure is remembered so
 * a broken pointer costs one attempt rather than one per query.
 */
function mam_v2_rm_db(): ?PDO
{
    static $db = null, $tried = false, $err = '', $gen = -1;
    $now = (int) ($GLOBALS['__mam_v2_rm_gen'] ?? 0);
    if ($gen !== $now) { $db = null; $tried = false; $err = ''; $gen = $now; }
    if ($tried) { $GLOBALS['__mam_v2_rm_err'] = $err; return $db; }
    $tried = true;

    if (!mam_v2_rm_configured()) { $err = 'not_configured'; $GLOBALS['__mam_v2_rm_err'] = $err; return null; }

    try {
        $dsn = trim((string) mam_env('V2_READ_DSN', ''));
        if ($dsn === '') {
            $path = trim((string) mam_env('V2_READ_DB', ''));
            if (!is_file($path)) throw new \RuntimeException('file_not_found');
            // mode=ro is refused at OPEN by the engine, which is a stronger
            // guarantee than anything this file could enforce afterwards.
            $h = new PDO('sqlite:file:' . $path . '?mode=ro');
            $h->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Belt and braces: also refuse at the statement level, in case a
            // future caller is handed a handle opened some other way.
            $h->exec('PRAGMA query_only = 1');
        } else {
            $h = new PDO($dsn, (string) mam_env('V2_READ_USER', ''), (string) mam_env('V2_READ_PASS', ''));
            $h->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // MySQL has no per-connection read-only flag; the session-level
            // transaction mode is the closest equivalent. A dedicated
            // SELECT-only grant is the real control and belongs in deployment.
            try { $h->exec('SET SESSION TRANSACTION READ ONLY'); } catch (\Throwable $e) {}
        }
        $db = $h;
    } catch (\Throwable $e) {
        $err = 'unreachable: ' . $e->getMessage();
        if (function_exists('mam_log')) mam_log('warn', 'v2_readmodel_unreachable', ['err' => $e->getMessage()]);
        $db = null;
    }
    $GLOBALS['__mam_v2_rm_err'] = $err;
    return $db;
}

/**
 * Drop the cached handle so a new pointer takes effect.
 *
 * TEST-ONLY, and named so nobody reaches for it by accident. The connection is
 * cached for the life of a request, which is right in production — a request
 * never re-points itself — and useless in a test that needs to exercise
 * configured, misconfigured and unconfigured in one process.
 */
function mam_v2_rm_reset_for_test(): void
{
    static $warned = false;
    if (PHP_SAPI !== 'cli' && !$warned) {
        $warned = true;
        if (function_exists('mam_log')) mam_log('warn', 'v2_readmodel_reset_outside_cli', []);
    }
    // The cache lives in a static inside mam_v2_rm_db(); a generation counter is
    // the least invasive way to invalidate it without making the handle global.
    $GLOBALS['__mam_v2_rm_gen'] = (int) ($GLOBALS['__mam_v2_rm_gen'] ?? 0) + 1;
}

/** The last reason the read model could not answer, for the screen to show. */
function mam_v2_rm_error(): string
{
    return (string) ($GLOBALS['__mam_v2_rm_err'] ?? '');
}

/** The tenant this read model reports on. */
function mam_v2_rm_tenant_slug(): string
{
    $s = trim((string) mam_env('V2_READ_TENANT', 'mam'));
    return $s !== '' ? $s : 'mam';
}

/**
 * The ONLY query path. Read-only in name as well as in configuration: a
 * statement that is not a SELECT is refused here before the driver sees it, so
 * a mistake in a caller cannot become a write even if the connection somehow
 * permits one.
 */
function mam_v2_rm_all(string $sql, array $args = []): array
{
    $db = mam_v2_rm_db();
    if (!$db) return [];
    if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
        if (function_exists('mam_log')) mam_log('error', 'v2_readmodel_write_attempt', ['sql' => substr($sql, 0, 120)]);
        return [];
    }
    try {
        $st = $db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        if (function_exists('mam_log')) mam_log('warn', 'v2_readmodel_query_failed', ['err' => $e->getMessage()]);
        return [];
    }
}

/** First row, or null. */
function mam_v2_rm_row(string $sql, array $args = []): ?array
{
    $r = mam_v2_rm_all($sql, $args);
    return $r[0] ?? null;
}

/** The tenant's internal id in the read model, or null. */
function mam_v2_rm_tenant_id(): ?int
{
    $r = mam_v2_rm_row("SELECT id FROM tenants WHERE slug = ?", [mam_v2_rm_tenant_slug()]);
    return $r ? (int) $r['id'] : null;
}

/**
 * Is this snapshot usable, how old is it, and what is in it?
 *
 * `loaded_at` is the newest `legacy_reference_map.migrated_at` — the ETL stamps
 * every row it writes, so the map is the load's own record of when it ran. There
 * is no separate "last loaded" field to fall out of step with it.
 */
function mam_v2_rm_status(): array
{
    $out = [
        'configured' => mam_v2_rm_configured(),
        'available'  => false,
        'error'      => '',
        'tenant'     => mam_v2_rm_tenant_slug(),
        'loaded_at'  => null,
        'age_hours'  => null,
        'counts'     => [],
    ];
    if (!$out['configured']) { $out['error'] = 'not_configured'; return $out; }
    if (!mam_v2_rm_db())     { $out['error'] = mam_v2_rm_error() ?: 'unreachable'; return $out; }

    $tid = mam_v2_rm_tenant_id();
    if ($tid === null) {
        $out['error'] = 'tenant_not_loaded';
        return $out;
    }
    $out['available'] = true;

    $stamp = mam_v2_rm_row("SELECT MAX(migrated_at) AS m FROM legacy_reference_map WHERE tenant_id = ?", [$tid]);
    $out['loaded_at'] = $stamp['m'] ?? null;
    if ($out['loaded_at']) {
        $out['age_hours'] = (int) floor((time() - strtotime((string) $out['loaded_at'])) / 3600);
    }

    foreach ([
        'people'       => "SELECT COUNT(*) c FROM users u JOIN memberships m ON m.user_id = u.id WHERE m.tenant_id = ?",
        'applications' => "SELECT COUNT(*) c FROM applications WHERE tenant_id = ?",
        'payments'     => "SELECT COUNT(*) c FROM payments WHERE tenant_id = ?",
        'mapped_rows'  => "SELECT COUNT(*) c FROM legacy_reference_map WHERE tenant_id = ?",
    ] as $k => $sql) {
        $r = mam_v2_rm_row($sql, [$tid]);
        $out['counts'][$k] = $r ? (int) $r['c'] : 0;
    }
    return $out;
}

/**
 * The merged-person view — the thing v1 genuinely cannot produce.
 *
 * In v1 a human is a `customers` row, a `users` row, or both, with no reliable
 * join: the addresses differ in case, one may be a tombstone. The ETL resolved
 * that once and recorded BOTH source rows against the one person, so this can
 * show which v1 rows folded together — which is the only place that merge is
 * visible to anybody.
 */
function mam_v2_rm_people(int $limit = 200): array
{
    $tid = mam_v2_rm_tenant_id();
    if ($tid === null) return [];
    return mam_v2_rm_all(
        "SELECT u.id, u.email, u.full_name, u.status, m.stage, m.reference,
                (SELECT COUNT(*) FROM user_credentials c WHERE c.user_id = u.id) AS has_login,
                (SELECT GROUP_CONCAT(l.legacy_table || ':' || l.legacy_id)
                   FROM legacy_reference_map l
                  WHERE l.tenant_id = m.tenant_id AND l.v2_table = 'users' AND l.v2_id = u.id) AS came_from
           FROM users u JOIN memberships m ON m.user_id = u.id
          WHERE m.tenant_id = ?
          ORDER BY u.full_name, u.id
          LIMIT " . max(1, min(1000, $limit)),
        [$tid]
    );
}

/** People whose record folded together from more than one v1 row. */
function mam_v2_rm_merged(): array
{
    $out = [];
    foreach (mam_v2_rm_people(1000) as $p) {
        $from = array_filter(explode(',', (string) ($p['came_from'] ?? '')));
        if (count($from) > 1) { $p['sources'] = $from; $out[] = $p; }
    }
    return $out;
}

/**
 * Merges whose v1 rows disagreed about who the email belongs to.
 *
 * The read model cannot recompute this — v2 keeps only the winning name — so the
 * load records the disagreement in `legacy_reference_map.note` at merge time and
 * this reads it back. Its purpose is a human decision: identity is keyed on the
 * email, and a guardian's address used to apply for a child is indistinguishable
 * from one person to that rule. Merging a parent and their child into one record
 * is not recoverable after cutover, which is why it wants reviewing before.
 */
function mam_v2_rm_merge_review(): array
{
    $tid = mam_v2_rm_tenant_id();
    if ($tid === null) return [];
    return mam_v2_rm_all(
        "SELECT u.id, u.email, u.full_name, m.stage, l.note,
                (SELECT GROUP_CONCAT(l2.legacy_table || ':' || l2.legacy_id)
                   FROM legacy_reference_map l2
                  WHERE l2.tenant_id = l.tenant_id AND l2.v2_table = 'users' AND l2.v2_id = u.id) AS came_from
           FROM legacy_reference_map l
           JOIN users u ON u.id = l.v2_id
           JOIN memberships m ON m.user_id = u.id AND m.tenant_id = l.tenant_id
          WHERE l.tenant_id = ? AND l.v2_table = 'users' AND l.note LIKE '%SOURCE NAMES DISAGREE%'
          GROUP BY u.id
          ORDER BY u.full_name",
        [$tid]
    );
}

/**
 * Money, by status, in exact minor units.
 *
 * v1 stores an amount as a decimal string and collapses every pending state into
 * one word. Here `awaiting_transfer` is its own status — 11 of the 15 real
 * payments — which is the difference between "waiting on a gateway" (nobody
 * needs to act) and "waiting on a bank transfer somebody must confirm".
 */
function mam_v2_rm_money(): array
{
    $tid = mam_v2_rm_tenant_id();
    if ($tid === null) return ['rows' => [], 'total_minor' => 0, 'currency' => null];
    $rows = mam_v2_rm_all(
        "SELECT status, COUNT(*) AS n, SUM(amount_minor) AS minor, base_currency
           FROM payments WHERE tenant_id = ?
          GROUP BY status, base_currency
          ORDER BY SUM(amount_minor) DESC",
        [$tid]
    );
    $total = 0; $cur = null;
    foreach ($rows as $r) { $total += (int) $r['minor']; $cur = $cur ?? ($r['base_currency'] ?? null); }
    return ['rows' => $rows, 'total_minor' => $total, 'currency' => $cur];
}

/**
 * What became of an old reference.
 *
 * The references in people's emails, receipts and memories are v1's — APP-0010,
 * a payment reference, a customer id. `legacy_reference_map` is the only thing
 * that can answer "where did that go", and it exists precisely so a cutover does
 * not make every historical reference unresolvable.
 */
function mam_v2_rm_resolve(string $ref): array
{
    $ref = trim($ref);
    if ($ref === '') return [];
    $tid = mam_v2_rm_tenant_id();
    if ($tid === null) return [];
    return mam_v2_rm_all(
        "SELECT legacy_table, legacy_id, legacy_ref, v2_table, v2_id, migrated_at, note
           FROM legacy_reference_map
          WHERE tenant_id = ? AND (legacy_ref = ? OR legacy_id = ?)
          ORDER BY legacy_table, legacy_id LIMIT 50",
        [$tid, $ref, $ref]
    );
}
