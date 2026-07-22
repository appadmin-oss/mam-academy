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
            'live'    => true,
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
            'live'    => true,
        ],
        'session_reminder' => [
            'group' => 'Learning',
            'trigger' => '24 hours before a session',
            'action'  => 'Email a reminder to the student and guardian',
            'icon'    => 'clock',
            'default' => true,
            'live'    => true,
        ],
        'week_drip' => [
            'group' => 'Learning',
            'trigger' => 'A course week starts',
            'action'  => 'Release the next module (drip)',
            'icon'    => 'droplet',
            'default' => true,
            'live'    => true,
        ],
        'store_receipt' => [
            'group' => 'Commerce & delight',
            'trigger' => 'A store order is paid',
            'action'  => 'Email the receipt and any certificate',
            'icon'    => 'receipt',
            'default' => true,
            'live'    => true,
        ],
        'ktams_birthday' => [
            'group' => 'Commerce & delight',
            'trigger' => "A KTAMS student's birthday",
            'action'  => 'Send a playful birthday note',
            'icon'    => 'cake-candles',
            'default' => true,
            'live'    => true,
        ],
        'id_card_issue' => [
            'group'   => 'Identity',
            'trigger' => 'A student or instructor is enrolled or activated',
            'action'  => 'Issue an ID card and email it (PDF + image)',
            'icon'    => 'id-card',
            'default' => true,
            'live'    => true,
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

/* ─────────────────────────────────────────────────────────────
 * Time-based sweeps — the ACTIONS for automations that fire on a
 * schedule rather than at a request. Driven by the run-jobs cron; each is
 * idempotent (dedup through the `events` log), gated by its flag, and never
 * throws. See api/tools/run-jobs.php.
 * ───────────────────────────────────────────────────────────── */

/**
 * 'session_reminder' — email every enrolled student a reminder for each cohort
 * session scheduled within the next $withinHours (default 24). Deduped once per
 * session via a 'session.reminded' event, so repeated cron passes never spam.
 * Returns the number of sessions reminded.
 */
function mam_automation_session_reminder_sweep(int $withinHours = 24, int $limit = 100): int {
    if (!function_exists('mam_db') || !mam_automation_on('session_reminder')) return 0;
    $now   = date('Y-m-d H:i:s');
    $until = date('Y-m-d H:i:s', time() + max(1, $withinHours) * 3600);
    try {
        $sessions = mam_db_all(
            "SELECT cs.id, cs.cohort_id, cs.title, cs.scheduled_at, cs.meeting_link
               FROM class_sessions cs
              WHERE cs.status = 'scheduled' AND cs.scheduled_at IS NOT NULL
                AND cs.scheduled_at > ? AND cs.scheduled_at <= ?
                AND NOT EXISTS (SELECT 1 FROM events e WHERE e.type = 'session.reminded' AND e.entity_id = cs.id)
              ORDER BY cs.scheduled_at ASC LIMIT " . max(1, $limit),
            [$now, $until]
        );
    } catch (\Throwable $e) { return 0; }
    $appUrl   = rtrim((string)mam_env('APP_URL', ''), '/');
    $loginUrl = $appUrl . '/portal/login';
    $count = 0;
    foreach ($sessions as $s) {
        $sent = 0;
        // Use the academy-hosted redirect URL so the raw meeting URL stays server-side.
        $joinUrl = !empty($s['meeting_link']) ? $appUrl . '/session/' . (int)$s['id'] . '/join' : '';
        try {
            $students = mam_db_all(
                "SELECT DISTINCT u.name, u.email FROM course_enrollments ce
                   JOIN users u ON u.id = ce.student_id
                  WHERE ce.cohort_id = ? AND ce.status IN ('active', 'completed')
                    AND u.email IS NOT NULL AND u.email <> ''",
                [(int)$s['cohort_id']]
            );
            $whenLabel = date('l j M, g:i A', strtotime((string)$s['scheduled_at']));
            foreach ($students as $st) {
                if (function_exists('mam_send') && function_exists('mam_email_session_reminder')) {
                    mam_send((string)$st['email'], 'Reminder: your MAM Academy session is coming up',
                        mam_email_session_reminder((string)($st['name'] ?? ''), (string)($s['title'] ?? 'your session'), $whenLabel, $loginUrl, $joinUrl),
                        'session_reminder', true);
                    $sent++;
                }
            }
        } catch (\Throwable $e) { /* best-effort per session */ }
        // Mark reminded once per session (even if 0 students) so a later pass
        // never re-sends within the window.
        if (function_exists('mam_event')) mam_event('session.reminded', 'class_session', (int)$s['id'], ['students' => $sent]);
        if ($sent > 0) mam_automation_touch('session_reminder');
        $count++;
    }
    return $count;
}

/**
 * 'week_drip' — as each active cohort enters a new course week, release that
 * week's module by notifying the cohort's students. Forward-only (only the
 * current week is released, never a backfill) and deduped once per (cohort,
 * week) via a 'module.released' event. Returns how many modules were released.
 */
function mam_automation_week_drip_sweep(int $limit = 200): int {
    if (!function_exists('mam_db') || !mam_automation_on('week_drip') || !function_exists('mam_notify')) return 0;
    $today = strtotime(date('Y-m-d'));
    try {
        $cohorts = mam_db_all(
            "SELECT id, course_id, level_id, title, starts_on FROM cohorts
              WHERE status = 'active' AND course_id IS NOT NULL AND starts_on IS NOT NULL AND starts_on <> ''
              LIMIT " . max(1, $limit)
        );
    } catch (\Throwable $e) { return 0; }
    $count = 0;
    foreach ($cohorts as $co) {
        $start = strtotime((string)$co['starts_on']);
        if ($start === false || $start > $today) continue;            // not started yet
        $week = (int)floor(($today - $start) / (7 * 86400)) + 1;       // week containing today
        if ($week < 1 || $week > 52) continue;

        // The module for this course/level at the current week (if authored).
        try {
            if ($co['level_id'] !== null) {
                $mod = mam_db_row('SELECT id, title FROM modules WHERE course_id = ? AND level_id = ? AND week = ?', [(int)$co['course_id'], (int)$co['level_id'], $week]);
            } else {
                $mod = mam_db_row('SELECT id, title FROM modules WHERE course_id = ? AND level_id IS NULL AND week = ?', [(int)$co['course_id'], $week]);
            }
        } catch (\Throwable $e) { continue; }
        if (!$mod) continue;

        // Deduped once per (cohort, week) — collect this cohort's released weeks.
        $released = [];
        try {
            foreach (mam_db_all("SELECT payload FROM events WHERE type = 'module.released' AND entity = 'cohort' AND entity_id = ?", [(int)$co['id']]) as $ev) {
                $p = json_decode((string)$ev['payload'], true);
                if (is_array($p) && isset($p['week'])) $released[(int)$p['week']] = true;
            }
        } catch (\Throwable $e) { /* treat as none released */ }
        if (isset($released[$week])) continue;

        // Notify the cohort's active students that the week's module is live.
        $sent = 0;
        try {
            $students = mam_db_all(
                "SELECT DISTINCT student_id FROM course_enrollments WHERE cohort_id = ? AND status IN ('active', 'completed')",
                [(int)$co['id']]
            );
            $title = 'Week ' . $week . ' is here: ' . (string)$mod['title'];
            $body  = 'A new module is now available' . (!empty($co['title']) ? ' in ' . (string)$co['title'] : '') . '.';
            foreach ($students as $s) {
                mam_notify((int)$s['student_id'], 'module.released', $title, $body, '/portal/student/#learning');
                $sent++;
            }
        } catch (\Throwable $e) { /* best-effort per cohort */ }

        if (function_exists('mam_event')) mam_event('module.released', 'cohort', (int)$co['id'], ['week' => $week, 'module_id' => (int)$mod['id'], 'students' => $sent]);
        mam_automation_touch('week_drip');
        $count++;
    }
    return $count;
}

/**
 * 'ktams_birthday' — send a playful note to each KTAMS (Kids @ MAM) student
 * whose birthday is today. Deduped to once per calendar year via a
 * 'ktams.birthday' event. Returns how many notes were sent.
 */
function mam_automation_ktams_birthday_sweep(int $limit = 200): int {
    if (!function_exists('mam_db') || !mam_automation_on('ktams_birthday')) return 0;
    $today     = date('m-d');
    $yearStart = date('Y-01-01 00:00:00');
    try {
        $rows = mam_db_all(
            "SELECT DISTINCT u.id, u.name, u.email, c.dob
               FROM users u
               JOIN customers c ON c.id = u.customer_id
               JOIN applications a ON a.customer_id = c.id AND a.program_track = 'KTAMS'
              WHERE u.role = 'student' AND u.status = 'active'
                AND u.email IS NOT NULL AND u.email <> '' AND c.dob IS NOT NULL
              LIMIT " . max(1, $limit)
        );
    } catch (\Throwable $e) { return 0; }
    $count = 0;
    foreach ($rows as $r) {
        if (substr((string)$r['dob'], 5, 5) !== $today) continue; // month-day match (portable)
        try {
            if (mam_db_row("SELECT id FROM events WHERE type = 'ktams.birthday' AND entity_id = ? AND created_at >= ?", [(int)$r['id'], $yearStart])) continue;
        } catch (\Throwable $e) { /* if the check fails, fall through and rely on the event write */ }
        try {
            if (function_exists('mam_send') && function_exists('mam_email_ktams_birthday')) {
                $first = trim(explode(' ', (string)($r['name'] ?? ''))[0]);
                mam_send((string)$r['email'], 'Happy birthday from MAM Academy! 🎂',
                    mam_email_ktams_birthday($first), 'ktams_birthday', true);
            }
        } catch (\Throwable $e) { continue; }
        if (function_exists('mam_event')) mam_event('ktams.birthday', 'user', (int)$r['id'], ['ymd' => date('Y-m-d')]);
        mam_automation_touch('ktams_birthday');
        $count++;
    }
    return $count;
}
