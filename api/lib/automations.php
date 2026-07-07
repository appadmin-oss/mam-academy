<?php
/**
 * Lifecycle automations — admin-toggleable trigger→action rules.
 *
 * Why this exists: the academy runs a handful of "when X happens, do Y"
 * behaviours (provision a portal on payment, drip curriculum, remind before a
 * session, …). They used to be implicit — buried in whichever handler happened
 * to own the trigger, with no single place to see or govern them. This lib is
 * that place: a canonical registry of the rules (`mam_automations_registry`)
 * plus a persisted enable/disable flag per rule the admin edits from
 * /admin/?v=automations.
 *
 * Execution model (mirrors the notification/jobs spine, see jobs.php): a rule's
 * ACTION lives wherever its trigger fires. That code asks `mam_automation_on()`
 * before acting, so a toggle governs live behaviour. Rules whose action isn't
 * wired yet still persist their flag here, ready for the handler that adopts it.
 *
 * Resilience: like settings.php, every read tolerates a missing `automations`
 * table (a freshly-pointed MySQL DB behind on migrations) by falling back to the
 * rule's shipped default — a toggle store must never take the site down.
 */
declare(strict_types=1);

/**
 * The canonical rules. Keep keys STABLE — they're the persisted flag names and
 * the gate argument at every call site. `default` is the shipped state (all on:
 * the academy wants these behaviours by default); `live` marks whether the
 * action is wired to honour the flag today (vs. persisted config awaiting its
 * handler). `group` sections the admin screen.
 */
function mam_automations_registry(): array {
    return [
        'payment_provision' => [
            'group' => 'Admissions & payments',
            'trigger' => 'A tuition payment succeeds',
            'action'  => 'Provision the student portal and email their login details',
            'icon'    => 'right-to-bracket',
            'default' => true,
            'live'    => true,
        ],
        'enrol_welcome' => [
            'group' => 'Admissions & payments',
            'trigger' => 'A student is enrolled',
            'action'  => 'Send the welcome & orientation sequence',
            'icon'    => 'envelope-open-text',
            'default' => true,
            'live'    => false,
        ],
        'payment_retry' => [
            'group' => 'Admissions & payments',
            'trigger' => 'A payment fails',
            'action'  => 'Auto-retry once, then notify the applicant',
            'icon'    => 'rotate',
            'default' => true,
            'live'    => false,
        ],
        'auditer_placement' => [
            'group' => 'Admissions & payments',
            'trigger' => 'A MAM Auditer placement completes',
            'action'  => 'Assign the level, enrol, and attach the curriculum',
            'icon'    => 'clipboard-check',
            'default' => true,
            'live'    => false,
        ],
        'session_reminder' => [
            'group' => 'Learning',
            'trigger' => '24 hours before a session',
            'action'  => 'Email a reminder to the student and guardian',
            'icon'    => 'clock',
            'default' => true,
            'live'    => false,
        ],
        'week_drip' => [
            'group' => 'Learning',
            'trigger' => 'A course week starts',
            'action'  => 'Release the next module (drip)',
            'icon'    => 'droplet',
            'default' => true,
            'live'    => false,
        ],
        'store_receipt' => [
            'group' => 'Commerce & delight',
            'trigger' => 'A store order is paid',
            'action'  => 'Email the receipt and any certificate',
            'icon'    => 'receipt',
            'default' => true,
            'live'    => false,
        ],
        'ktams_birthday' => [
            'group' => 'Commerce & delight',
            'trigger' => "A KTAMS student's birthday",
            'action'  => 'Send a playful birthday note',
            'icon'    => 'cake-candles',
            'default' => true,
            'live'    => false,
        ],
    ];
}

/** Mutable holder for the per-request state cache (so a set() stays consistent). */
function &mam_automations_cache(): ?array {
    static $cache = null;
    return $cache;
}

/**
 * The persisted per-rule enabled flags, keyed by rule. Returns [] (all defaults
 * apply) when the table is missing. Cached once per request.
 */
function mam_automations_state(): array {
    $cache = &mam_automations_cache();
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach (mam_db_all('SELECT akey, enabled FROM automations') as $row) {
            $cache[(string)$row['akey']] = ((int)$row['enabled'] === 1);
        }
    } catch (Throwable $e) {
        // Table missing / DB behind on migrations — every rule falls back to
        // its shipped default. Never fatal.
    }
    return $cache;
}

/**
 * Is a rule enabled? Unknown keys are off (a typo must not silently "run").
 * A known key with no stored flag uses the rule's shipped default.
 */
function mam_automation_on(string $key): bool {
    $reg = mam_automations_registry();
    if (!isset($reg[$key])) return false;
    $state = mam_automations_state();
    if (array_key_exists($key, $state)) return $state[$key];
    return (bool)$reg[$key]['default'];
}

/** Persist a rule's enabled flag (upsert). Caller owns auth + CSRF. No-op for
 *  unknown keys so a crafted POST can't create junk rows. */
function mam_automation_set(string $key, bool $on): void {
    if (!isset(mam_automations_registry()[$key])) return;
    $now = date('Y-m-d H:i:s');
    mam_db_upsert(
        'automations',
        ['akey' => $key, 'enabled' => $on ? 1 : 0, 'updated_at' => $now],
        ['akey'],
        ['enabled' => $on ? 1 : 0, 'updated_at' => $now]
    );
    $cache = &mam_automations_cache();
    if (is_array($cache)) $cache[$key] = $on;
}

/** Record that a rule's action fired (best-effort — powers the "last ran" hint
 *  in the admin). Never throws; a telemetry write must not break the action. */
function mam_automation_touch(string $key): void {
    if (!isset(mam_automations_registry()[$key])) return;
    $now = date('Y-m-d H:i:s');
    try {
        // Ensure a row exists, then bump its counters. Two statements keep this
        // portable across SQLite + MySQL (no dialect-specific upsert-with-expr).
        mam_db_upsert(
            'automations',
            ['akey' => $key, 'enabled' => mam_automation_on($key) ? 1 : 0, 'updated_at' => $now],
            ['akey'],
            ['updated_at' => $now]
        );
        mam_db()->prepare('UPDATE automations SET last_run_at = ?, run_count = run_count + 1 WHERE akey = ?')
                ->execute([$now, $key]);
    } catch (Throwable $e) {
        // best-effort only
    }
}

/** How many rules are currently enabled — the admin badge / "N active" count. */
function mam_automations_active_count(): int {
    $n = 0;
    foreach (array_keys(mam_automations_registry()) as $k) if (mam_automation_on($k)) $n++;
    return $n;
}

/**
 * The rules decorated with their live enabled state + last-run metadata, ready
 * for the admin view. Shape per rule: registry fields + enabled + last_run_at +
 * run_count.
 */
function mam_automations_list(): array {
    $out = [];
    $meta = [];
    try {
        foreach (mam_db_all('SELECT akey, last_run_at, run_count FROM automations') as $row) {
            $meta[(string)$row['akey']] = $row;
        }
    } catch (Throwable $e) { /* table missing — no metadata */ }
    foreach (mam_automations_registry() as $key => $rule) {
        $out[$key] = $rule + [
            'key'         => $key,
            'enabled'     => mam_automation_on($key),
            'last_run_at' => $meta[$key]['last_run_at'] ?? null,
            'run_count'   => (int)($meta[$key]['run_count'] ?? 0),
        ];
    }
    return $out;
}
