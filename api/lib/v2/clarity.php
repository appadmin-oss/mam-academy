<?php
/**
 * Layer H read model — turning v2 rows into things a person can act on.
 * ─────────────────────────────────────────────────────────────
 * The schema's Layer H makes clarity POSSIBLE; this file is where it becomes
 * real. Every surface — student portal, guardian portal, instructor console,
 * admin, and the email templates — resolves a status through `mam_v2_status()`
 * and never prints a raw column value. That is the whole point: v1's bug was
 * not a missing label, it was five screens each inventing their own.
 *
 * ⚠️ This is a **v2** lib and is deliberately NOT in `bootstrap.php`'s
 * `$mam_libs`. It queries v2 tables (`status_definitions`, `action_items`,
 * `user_preferences`) which do not exist in the v1 database the application
 * currently runs on, so autoloading it would fatal on every request. It is
 * required explicitly by `test_v2_clarity.php` and will join the autoload list
 * in the same commit that repoints the app at v2. (CLAUDE.md gotcha #1 covers
 * the normal case; this is the stated exception, not an oversight.)
 *
 * Every function takes its PDO explicitly for the same reason.
 */
declare(strict_types=1);

require_once __DIR__ . '/status-catalogue.php';

/* ── Status resolution ────────────────────────────────────────────────────── */

/**
 * Seed the platform's status_definitions from the catalogue. Idempotent —
 * re-running updates the wording in place rather than duplicating it, so a
 * copy fix ships as a re-seed and not a migration.
 *
 * Tenant rows are never touched: an academy that reworded something keeps it.
 */
function mam_v2_status_seed(PDO $db, ?string $locale = 'en'): int
{
    $sql = 'INSERT INTO status_definitions
              (tenant_id, tenant_scope, entity_type, status, audience, locale,
               label, meaning, next_step, tone, is_actionable, is_terminal, sort_order)
            VALUES (NULL, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $upd = 'UPDATE status_definitions
               SET label = ?, meaning = ?, next_step = ?, tone = ?,
                   is_actionable = ?, is_terminal = ?, sort_order = ?
             WHERE tenant_scope = 0 AND entity_type = ? AND status = ? AND audience = ? AND locale = ?';

    $written = 0;
    foreach (mam_v2_status_catalogue() as $entity => $statuses) {
        foreach ($statuses as $status => $def) {
            foreach (MAM_V2_AUDIENCES as $audience) {
                $words = mam_v2_catalogue_words($def, $audience);
                if ($words === null) continue;
                [$label, $meaning, $next, $actionable] = $words;
                $args = [$label, $meaning, $next, (string) $def['tone'],
                         $actionable ? 1 : 0, !empty($def['terminal']) ? 1 : 0, (int) $def['sort']];
                $rows = $db->prepare($upd);
                $rows->execute(array_merge($args, [$entity, $status, $audience, $locale]));
                if ($rows->rowCount() === 0) {
                    // rowCount() is 0 both for "no such row" and for "identical
                    // values", so an INSERT that collides is not an error here.
                    try {
                        $db->prepare($sql)->execute(array_merge(
                            [$entity, $status, $audience, $locale], $args));
                    } catch (Throwable $e) { /* already present and unchanged */ }
                }
                $written++;
            }
        }
    }
    return $written;
}

/**
 * The wording for one audience, or null when the catalogue does not define it.
 *
 * `admin` deliberately falls back to `staff`: an administrator is a staff
 * member with more buttons, not a different reader. Writing the same terse
 * sentence twice would guarantee the two drift.
 */
function mam_v2_catalogue_words(array $def, string $audience): ?array
{
    if (isset($def[$audience])) return $def[$audience];
    if ($audience === 'admin' && isset($def['staff'])) return $def['staff'];
    return null;
}

/**
 * Resolve one status into words for one reader.
 *
 * The fallback chain is deliberate and each step is a real case:
 *   1. this tenant's wording for this audience      — an academy's own words
 *   2. the platform default for this audience       — the catalogue
 *   3. the platform default for `staff`             — a new audience, no copy yet
 *   4. a synthesised label from the raw value       — a status nobody worded
 *
 * Step 4 must never happen in a shipped build (test_v2_clarity.php fails the
 * build if any status reaches it), but it exists because the alternative is
 * throwing — and a portal that 500s because someone added a status is worse
 * than one that says "Under Assessment" a bit stiffly.
 */
function mam_v2_status(PDO $db, string $entity, ?string $status, string $audience = 'learner',
                       ?int $tenantId = null, string $locale = 'en'): array
{
    $status = (string) $status;
    if ($status === '') {
        return mam_v2_status_unknown($entity, $status, 'not_set');
    }
    if (!in_array($audience, MAM_V2_AUDIENCES, true)) $audience = 'learner';

    static $cache = [];
    $ck = ($tenantId ?? 0) . "|$entity|$status|$audience|$locale";
    if (isset($cache[$ck])) return $cache[$ck];

    $tries = [[$tenantId ?? 0, $audience], [0, $audience]];
    if ($audience !== 'staff') $tries[] = [0, 'staff'];

    $stmt = $db->prepare('SELECT label, meaning, next_step, tone, is_actionable, is_terminal, sort_order
                            FROM status_definitions
                           WHERE tenant_scope = ? AND entity_type = ? AND status = ?
                             AND audience = ? AND locale = ? LIMIT 1');
    foreach ($tries as [$scope, $aud]) {
        $stmt->execute([$scope, $entity, $status, $aud, $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $cache[$ck] = [
                'entity'     => $entity,
                'status'     => $status,
                'audience'   => $audience,
                'label'      => (string) $row['label'],
                'meaning'    => (string) $row['meaning'],
                'next_step'  => $row['next_step'] !== null ? (string) $row['next_step'] : null,
                'tone'       => (string) $row['tone'],
                'actionable' => (bool) $row['is_actionable'],
                'terminal'   => (bool) $row['is_terminal'],
                'sort'       => (int) $row['sort_order'],
                'resolved'   => $scope === ($tenantId ?? 0) && $scope !== 0 ? 'tenant'
                                : ($aud === $audience ? 'platform' : 'fallback'),
            ];
        }
    }
    return $cache[$ck] = mam_v2_status_unknown($entity, $status, 'undefined');
}

/** The last resort. Named so a test can detect it rather than eyeballing labels. */
function mam_v2_status_unknown(string $entity, string $status, string $why): array
{
    $label = $status === ''
        ? 'Not set'
        : ucfirst(str_replace('_', ' ', $status));
    return [
        'entity' => $entity, 'status' => $status, 'audience' => 'learner',
        'label' => $label,
        // No invented meaning. Guessing at what an unworded status means is how
        // a portal ends up confidently telling somebody the wrong thing.
        'meaning' => '', 'next_step' => null, 'tone' => 'neutral',
        'actionable' => false, 'terminal' => false, 'sort' => 9999,
        'resolved' => $why,
    ];
}

/** Every status of an entity, in journey order — the pipeline/progress track. */
function mam_v2_status_track(PDO $db, string $entity, string $audience = 'learner',
                             ?int $tenantId = null, string $locale = 'en'): array
{
    $out = [];
    foreach (array_keys(mam_v2_status_catalogue()[$entity] ?? []) as $status) {
        $out[] = mam_v2_status($db, $entity, $status, $audience, $tenantId, $locale);
    }
    usort($out, fn($a, $b) => $a['sort'] <=> $b['sort']);
    return $out;
}

/* ── Action items — "what do I need to do?" ───────────────────────────────── */

/**
 * Raise or refresh an obligation. Keyed on `dedupe_key`, so the nightly sweep
 * that notices an unpaid invoice can run every night and the learner still sees
 * ONE item — the failure that turns a to-do list into noise people ignore.
 *
 * Returns the item id.
 */
function mam_v2_action_upsert(PDO $db, int $tenantId, int $membershipId, string $dedupeKey, array $item): int
{
    $sel = $db->prepare('SELECT id FROM action_items
                          WHERE tenant_id = ? AND membership_id = ? AND dedupe_key = ? AND resolved_seq = 0');
    $sel->execute([$tenantId, $membershipId, $dedupeKey]);
    $id = (int) ($sel->fetchColumn() ?: 0);

    $fields = [
        'kind'         => (string) ($item['kind'] ?? 'task'),
        'title'        => (string) ($item['title'] ?? ''),
        'detail'       => $item['detail']       ?? null,
        'action_url'   => $item['action_url']   ?? null,
        'action_label' => $item['action_label'] ?? null,
        'source_type'  => $item['source_type']  ?? null,
        'source_id'    => isset($item['source_id']) ? (int) $item['source_id'] : null,
        'priority'     => (int) ($item['priority'] ?? 50),
        'is_blocking'  => !empty($item['is_blocking']) ? 1 : 0,
        'due_at'       => $item['due_at'] ?? null,
    ];

    if ($id > 0) {
        // Refresh in place. An obligation whose amount or due date changed is
        // the SAME obligation — replacing the row would reset "you have seen
        // this" and re-alert somebody who has already acted.
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $db->prepare("UPDATE action_items SET $set WHERE id = ?")
           ->execute(array_merge(array_values($fields), [$id]));
        return $id;
    }

    $cols = array_merge(['public_id', 'tenant_id', 'membership_id', 'dedupe_key'], array_keys($fields));
    $db->prepare('INSERT INTO action_items (' . implode(',', $cols) . ') VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')')
       ->execute(array_merge(
           [mam_v2_ulid(), $tenantId, $membershipId, $dedupeKey], array_values($fields)));
    return (int) $db->lastInsertId();
}

/**
 * Mark an obligation met (or set aside). Frees the dedupe key so the same
 * obligation can recur next term — a learner pays tuition every term, and a
 * key that could only be used once would silently stop raising it.
 */
function mam_v2_action_resolve(PDO $db, int $tenantId, int $membershipId, string $dedupeKey,
                               string $how = 'completed', ?string $at = null): bool
{
    $col = $how === 'dismissed' ? 'dismissed_at' : 'completed_at';
    $at  = $at ?? mam_v2_now();
    $st  = $db->prepare("UPDATE action_items SET $col = ?, resolved_seq = id
                          WHERE tenant_id = ? AND membership_id = ? AND dedupe_key = ? AND resolved_seq = 0");
    $st->execute([$at, $tenantId, $membershipId, $dedupeKey]);
    return $st->rowCount() > 0;
}

/**
 * The open list, in the order it should be read.
 *
 * Ordering is: blocking first, then priority, then soonest due, then oldest.
 * Undated items sort AFTER dated ones — "no deadline" must not jump ahead of
 * "due tomorrow", which is what a naive `ORDER BY due_at` does with NULLs on
 * SQLite (NULLs sort first) but not on MySQL. The explicit `due_at IS NULL`
 * term makes the two engines agree.
 */
function mam_v2_action_items(PDO $db, int $membershipId, int $limit = 50): array
{
    $st = $db->prepare('SELECT * FROM action_items
                         WHERE membership_id = ? AND completed_at IS NULL AND dismissed_at IS NULL
                         ORDER BY is_blocking DESC, priority ASC, due_at IS NULL ASC, due_at ASC, id ASC
                         LIMIT ' . max(1, min(200, $limit)));
    $st->execute([$membershipId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $now = mam_v2_now();
    foreach ($rows as &$r) {
        $r['is_blocking'] = (bool) $r['is_blocking'];
        $r['is_overdue']  = !empty($r['due_at']) && (string) $r['due_at'] < $now;
        $r['due_in_days'] = !empty($r['due_at'])
            ? (int) floor((strtotime((string) $r['due_at']) - strtotime($now)) / 86400)
            : null;
    }
    return $rows;
}

/** The headline the portal shows: "you have 2 things to do, 1 of them overdue". */
function mam_v2_action_summary(PDO $db, int $membershipId): array
{
    $items = mam_v2_action_items($db, $membershipId, 200);
    return [
        'total'    => count($items),
        'blocking' => count(array_filter($items, fn($i) => $i['is_blocking'])),
        'overdue'  => count(array_filter($items, fn($i) => $i['is_overdue'])),
        'next'     => $items[0] ?? null,
    ];
}

/* ── Preferences — how to speak to this person ────────────────────────────── */

/**
 * Merged preferences: stored row over tenant default over platform default.
 * Always returns a complete set, so no caller has to null-check a preference
 * and quietly pick its own default (which is how v1 ended up with three
 * different ideas of what "reduced motion" meant).
 */
function mam_v2_preferences(PDO $db, int $userId, ?array $tenant = null): array
{
    $base = [
        'locale'            => (string) ($tenant['default_locale'] ?? 'en'),
        'timezone'          => (string) ($tenant['default_timezone'] ?? 'Africa/Lagos'),
        'display_currency'  => (string) ($tenant['base_currency'] ?? 'USD'),
        'date_format'       => 'j M Y',
        'channel_email'     => true,  'channel_sms'   => false,
        'channel_whatsapp'  => false, 'channel_push'  => true,
        'digest_frequency'  => 'immediate',
        'quiet_hours_start' => null,  'quiet_hours_end' => null,
        'reduce_motion'     => false, 'high_contrast'  => false,
        'dyslexia_font'     => false, 'text_scale_pct' => 100,
        'low_bandwidth'     => false, 'captions_default' => false,
        'plain_language'    => false, 'onboarded'      => false,
    ];

    $st = $db->prepare('SELECT * FROM user_preferences WHERE user_id = ?');
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return $base;

    foreach (['locale', 'timezone', 'display_currency', 'date_format',
              'digest_frequency', 'quiet_hours_start', 'quiet_hours_end'] as $k) {
        if (isset($row[$k]) && $row[$k] !== null && $row[$k] !== '') $base[$k] = (string) $row[$k];
    }
    foreach (['channel_email', 'channel_sms', 'channel_whatsapp', 'channel_push',
              'reduce_motion', 'high_contrast', 'dyslexia_font', 'low_bandwidth',
              'captions_default', 'plain_language'] as $k) {
        if (array_key_exists($k, $row)) $base[$k] = (bool) $row[$k];
    }
    $base['text_scale_pct'] = (int) ($row['text_scale_pct'] ?? 100);
    $base['onboarded']      = !empty($row['onboarding_done_at']);
    return $base;
}

/**
 * Whether a message may be delivered on a channel right now.
 *
 * Quiet hours are honoured for everything EXCEPT critical messages. A
 * safeguarding alert or a failed payment that will cost somebody their place
 * is not something to sit on until 8am — and a preference system that cannot
 * make that distinction gets switched off entirely by the first admin who
 * needs to reach a parent.
 */
function mam_v2_may_deliver(array $prefs, string $channel, string $severity = 'info', ?string $localTime = null): bool
{
    $key = 'channel_' . $channel;
    if (!array_key_exists($key, $prefs) || !$prefs[$key]) return false;
    if ($severity === 'critical') return true;

    $start = $prefs['quiet_hours_start'] ?? null;
    $end   = $prefs['quiet_hours_end']   ?? null;
    if (!$start || !$end) return true;

    $now = substr($localTime ?? date('H:i:s'), 0, 8);
    // A window that wraps midnight (22:00 → 07:00) is the normal case, so the
    // comparison must handle it rather than silently never matching.
    return $start <= $end
        ? !($now >= $start && $now < $end)
        : !($now >= $start || $now < $end);
}

/* ── Money and time, rendered the way the reader thinks ───────────────────── */

/**
 * Format minor units for a human. The exponent comes from `currencies`, never
 * a hardcoded 100 — JPY has none and KWD has three, and a platform that
 * assumes two prints ¥1,200 as ¥12.00.
 */
function mam_v2_money(int $minor, string $currency, int $exponent = 2, ?string $symbol = null): string
{
    $sign  = $minor < 0 ? '-' : '';
    $abs   = abs($minor);
    $whole = $exponent > 0 ? intdiv($abs, 10 ** $exponent) : $abs;
    $frac  = $exponent > 0 ? str_pad((string) ($abs % (10 ** $exponent)), $exponent, '0', STR_PAD_LEFT) : '';
    $num   = number_format((float) $whole, 0, '.', ',') . ($exponent > 0 ? '.' . $frac : '');
    return $sign . ($symbol !== null && $symbol !== '' ? $symbol : $currency . ' ') . $num;
}

/** Look up a currency's exponent and symbol once. */
function mam_v2_currency(PDO $db, string $code): array
{
    static $cache = [];
    if (isset($cache[$code])) return $cache[$code];
    $st = $db->prepare('SELECT code, exponent, symbol FROM currencies WHERE code = ?');
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $cache[$code] = $row ?: ['code' => $code, 'exponent' => 2, 'symbol' => null];
}

/**
 * Render a class time for a viewer who may not be in the class's timezone.
 *
 * Returns the local wall clock AND, when the viewer's zone differs, their own
 * time beside it. A guardian in London reading "16:00" for a Lagos class will
 * otherwise turn up an hour late, and no amount of front-end polish fixes a
 * time that is simply the wrong number.
 */
function mam_v2_when(string $localWallClock, string $classZone, ?string $viewerZone = null,
                     string $format = 'j M Y, H:i'): array
{
    try {
        $dt = new DateTimeImmutable($localWallClock, new DateTimeZone($classZone));
    } catch (Throwable $e) {
        return ['text' => $localWallClock, 'zone' => $classZone, 'also' => null, 'utc' => null];
    }
    $out = [
        'text' => $dt->format($format),
        'zone' => $classZone,
        'also' => null,
        'utc'  => $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
    ];
    if ($viewerZone && $viewerZone !== $classZone) {
        try {
            $their = $dt->setTimezone(new DateTimeZone($viewerZone));
            // Only worth showing when it is genuinely a different clock reading.
            if ($their->format($format) !== $out['text']) {
                $out['also'] = $their->format($format) . ' your time';
            }
        } catch (Throwable $e) { /* unknown zone: show the class's own time only */ }
    }
    return $out;
}

/* ── small helpers ────────────────────────────────────────────────────────── */

function mam_v2_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
}

/** Crockford-base32 ULID: 48-bit time prefix + 80 bits of randomness. */
function mam_v2_ulid(?int $ms = null): string
{
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $ms = $ms ?? (int) (microtime(true) * 1000);
    $time = '';
    for ($i = 9; $i >= 0; $i--) { $time = $alphabet[($ms >> (5 * (9 - $i))) & 31] . $time; }
    $rand = '';
    for ($i = 0; $i < 16; $i++) { $rand .= $alphabet[random_int(0, 31)]; }
    return substr($time, -10) . $rand;
}
