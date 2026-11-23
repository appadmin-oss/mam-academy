<?php
/**
 * SQLite database wrapper + schema migrator.
 * Lazy connection; safe to require multiple times.
 */
declare(strict_types=1);

/** Latest schema version. The single source of truth for the migrators, the
 *  admin overview banner and /api/diag — bump it together with a new
 *  migration in $migrations below + db/schema-mysql.sql. */
const MAM_SCHEMA_TARGET = 106;

/**
 * Open (and cache) the PDO connection.
 *
 * Production:  set DB_DSN (e.g. "mysql:host=localhost;dbname=mam;charset=utf8mb4")
 *              with DB_USERNAME + DB_PASSWORD in api/.env. Schema must be
 *              applied once from db/schema-mysql.sql (or schema-pgsql.sql).
 * Default:     SQLite file at api/storage/mam.db with auto-migrate.
 */
function mam_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = (string)mam_env('DB_DSN', '');
    $user = (string)mam_env('DB_USERNAME', '') ?: null;
    $pass = (string)mam_env('DB_PASSWORD', '') ?: null;

    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if ($dsn !== '') {
        // Production driver (MySQL, PostgreSQL, etc.)
        $pdo = new PDO($dsn, $user, $pass, $opts);
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $pdo->exec("SET NAMES utf8mb4");
            $pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
            /* Pin the SESSION time zone to UTC, because bootstrap.php pins PHP's to
             * UTC and the two have to agree.
             *
             * Every timestamp this application WRITES is `date('Y-m-d H:i:s')` in
             * UTC. Every timestamp the schema DEFAULTS is MySQL's
             * CURRENT_TIMESTAMP, which resolves in the server's time zone — SYSTEM
             * on a shared host, which for this academy's own hosting is WAT
             * (UTC+1). Mixing the two is not a cosmetic drift: `mark_appeals` has
             * `CHECK(closed_at IS NULL OR closed_at >= opened_at)`, and with
             * opened_at stamped an hour into the future by the server, an appeal
             * declined twenty minutes after it was raised is a HARD WRITE FAILURE
             * on MySQL that SQLite never sees. The same shape appears anywhere a
             * PHP-written time is compared against a defaulted one.
             *
             * DATETIME columns store no zone, so this changes nothing about rows
             * already written — it only makes new defaults agree with PHP. */
            $pdo->exec("SET SESSION time_zone = '+00:00'");
            mam_db_migrate_mysql($pdo);
        }
        return $pdo;
    }

    // Default: SQLite with auto-migrate
    $path = MAM_STORAGE . '/mam.db';
    if (!is_dir(MAM_STORAGE)) @mkdir(MAM_STORAGE, 0750, true);
    $pdo = new PDO('sqlite:' . $path, null, null, $opts);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    mam_db_migrate($pdo);
    return $pdo;
}

/**
 * MySQL-specific migrator. We don't try to replicate the SQLite migrations
 * in MySQL syntax — that would inevitably drift. Instead we treat
 * `db/schema-mysql.sql` as the canonical schema and execute it whenever
 * schema_version is behind the target. The file is intentionally idempotent
 * (every DDL guarded by IF NOT EXISTS or ON DUPLICATE KEY) so re-running is
 * cheap and safe.
 *
 * Statements are split conservatively on semicolons-at-end-of-line; lines
 * inside block comments are stripped. The dynamic FK-drop in v10 (PREPARE /
 * EXECUTE / DEALLOCATE) is handled as a single chunk to keep its variables
 * scoped together within one PDO::exec call.
 */
function mam_db_migrate_mysql(PDO $db): void {
    /* Does this server ENFORCE a CHECK constraint, or merely parse one?
     *
     * MySQL 5.7 accepts a CREATE TABLE CHECK clause and silently discards it;
     * enforcement arrived in 8.0.16. MariaDB has enforced since 10.2.1. The
     * schema now leans on CHECKs for guarantees whole subsystems assume — the
     * termly report's band and coverage rules, the appeal state machine, the money
     * invariants — so on an older server those guarantees are decoration, the
     * load reports success, and the first symptom is data that cannot be right.
     * Logged as an ERROR, loudly, because a silent inert schema is worse than one
     * that failed to load. Not fatal: refusing to serve a site over this would
     * take an academy offline for a condition an operator can fix in an upgrade
     * window. */
    try {
        $ver = (string) ($db->query('SELECT VERSION()')->fetchColumn() ?: '');
        $isMaria = stripos($ver, 'mariadb') !== false;
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $ver, $m)) {
            $num = ((int) $m[1]) * 10000 + ((int) $m[2]) * 100 + (int) $m[3];
            $enforcesChecks = $isMaria ? $num >= 100201 : $num >= 80016;
            if (!$enforcesChecks) {
                mam_log('error', 'mysql_checks_not_enforced', [
                    'version' => $ver,
                    'needs'   => $isMaria ? 'MariaDB 10.2.1+' : 'MySQL 8.0.16+',
                    'effect'  => 'every CHECK constraint in db/schema-mysql.sql is parsed and ignored on this server',
                ]);
            }
        }
    } catch (Throwable $e) { /* a version probe must never stop a migration */ }

    try { $db->exec("CREATE TABLE IF NOT EXISTS schema_version (version INT PRIMARY KEY)"); } catch (Throwable $e) {}
    $current = 0;
    try { $current = (int)($db->query('SELECT IFNULL(MAX(version),0) FROM schema_version')->fetchColumn() ?: 0); } catch (Throwable $e) {}

    // Always verify and repair critical columns — runs even when schema_version
    // is already at target. Catches columns that were never applied on this host
    // (partial deploy, manual version bump, etc). information_schema check is
    // a cheap metadata query; ALTER TABLE only fires when the column is absent.
    try {
        $criticalRepairs = [
            'course_enrollments' => [
                'cohort_id'      => "ALTER TABLE course_enrollments ADD COLUMN cohort_id INT NULL",
            ],
            'users' => [
                'enrollment_ref' => "ALTER TABLE users ADD COLUMN enrollment_ref VARCHAR(32) NULL",
                'admin_tier'     => "ALTER TABLE users ADD COLUMN admin_tier VARCHAR(16) NULL",
                'student_type'   => "ALTER TABLE users ADD COLUMN student_type VARCHAR(16) NULL",
            ],
            // Application columns that were missing from MySQL when they existed in SQLite,
            // causing every application submission to 500 (the root cause of the v49 incident).
            // Plain ALTER TABLE — 1060 (duplicate column) is caught below.
            'applications' => [
                'program'        => "ALTER TABLE applications ADD COLUMN program        VARCHAR(64)  NULL",
                'headshot_url'   => "ALTER TABLE applications ADD COLUMN headshot_url   VARCHAR(500) NULL",
                'session'        => "ALTER TABLE applications ADD COLUMN session        VARCHAR(16)  NULL",
                'idempotency_key'=> "ALTER TABLE applications ADD COLUMN idempotency_key VARCHAR(80) NULL",
                'level'          => "ALTER TABLE applications ADD COLUMN level         VARCHAR(16)  NULL",
                'expression'     => "ALTER TABLE applications ADD COLUMN expression    VARCHAR(16)  NULL",
                'extra_courses'  => "ALTER TABLE applications ADD COLUMN extra_courses INT DEFAULT 0",
                'courses_label'  => "ALTER TABLE applications ADD COLUMN courses_label VARCHAR(400) NULL",
                'readiness_signal' => "ALTER TABLE applications ADD COLUMN readiness_signal VARCHAR(16) NULL",
                'program_track'  => "ALTER TABLE applications ADD COLUMN program_track VARCHAR(64)  NULL",
                'assignee_id'    => "ALTER TABLE applications ADD COLUMN assignee_id INT NULL",
                'booking_fee_paid_at' => "ALTER TABLE applications ADD COLUMN booking_fee_paid_at DATETIME NULL",
            ],
            // class_sessions columns added in v43 (lesson_notes/topics/outcomes) and v45
            // (substitute_id, rescheduled_from, cancellation_reason, room). The v45 block
            // is a single multi-clause ALTER that fails atomically with 1060 on re-runs,
            // meaning its FK constraints don't re-execute, but they're added on first run.
            // criticalRepairs guarantees the columns exist regardless.
            // v57 adds meeting_link for the auth-gated join redirect.
            'class_sessions' => [
                'lesson_notes'        => "ALTER TABLE class_sessions ADD COLUMN lesson_notes        TEXT         NULL",
                'substitute_id'       => "ALTER TABLE class_sessions ADD COLUMN substitute_id       INT          NULL",
                'rescheduled_from'    => "ALTER TABLE class_sessions ADD COLUMN rescheduled_from    INT          NULL",
                'cancellation_reason' => "ALTER TABLE class_sessions ADD COLUMN cancellation_reason TEXT         NULL",
                'room'                => "ALTER TABLE class_sessions ADD COLUMN room                VARCHAR(120) NULL",
                'meeting_link'        => "ALTER TABLE class_sessions ADD COLUMN meeting_link        VARCHAR(500) NULL",
                // v81: the §16 delivery mode of the session itself.
                'mode'                => "ALTER TABLE class_sessions ADD COLUMN mode VARCHAR(16) NOT NULL DEFAULT 'onsite'",
            ],
            // cohorts scheduling columns added in v45.
            'cohorts' => [
                'schedule_days'    => "ALTER TABLE cohorts ADD COLUMN schedule_days    VARCHAR(20) NULL",
                'schedule_time'    => "ALTER TABLE cohorts ADD COLUMN schedule_time    VARCHAR(8)  NULL",
                'session_duration' => "ALTER TABLE cohorts ADD COLUMN session_duration INT DEFAULT 60",
            ],
            // announcements.cohort_id (v39) enables course-only students to see
            // instructor announcements; without it mam_student_announcements() 500s.
            'announcements' => [
                'cohort_id' => "ALTER TABLE announcements ADD COLUMN cohort_id INT NULL",
            ],
            // teachbacks v72 — six-dimension teach-back assessment (§19).
            'teachbacks' => [
                'accuracy'    => "ALTER TABLE teachbacks ADD COLUMN accuracy    INT NULL",
                'vocabulary'  => "ALTER TABLE teachbacks ADD COLUMN vocabulary  INT NULL",
                'explanation' => "ALTER TABLE teachbacks ADD COLUMN explanation INT NULL",
                'application' => "ALTER TABLE teachbacks ADD COLUMN application INT NULL",
                'confidence'  => "ALTER TABLE teachbacks ADD COLUMN confidence  INT NULL",
            ],
            // class_resources.cohort_id (v40) — makes class_id nullable and adds cohort_id
            // so resources are visible to course-only students.
            'class_resources' => [
                'cohort_id' => "ALTER TABLE class_resources ADD COLUMN cohort_id INT NULL",
            ],
            // store_items.available_locations (v9) — absence breaks the store page.
            'store_items' => [
                'available_locations' => "ALTER TABLE store_items ADD COLUMN available_locations TEXT NULL",
            ],
            // summer_applications.headshot_url (v53) — optional applicant photo URL.
            'summer_applications' => [
                'headshot_url' => "ALTER TABLE summer_applications ADD COLUMN headshot_url VARCHAR(500) NULL",
            ],
            // classes.meeting_link (v54) — Zoom/Meet/Teams URL for online classes.
            'classes' => [
                'meeting_link' => "ALTER TABLE classes ADD COLUMN meeting_link VARCHAR(500) NULL",
            ],
            // promo_banners public-promo mode (v58) — time-limited discount.
            'promo_banners' => [
                'public'           => "ALTER TABLE promo_banners ADD COLUMN public INT NOT NULL DEFAULT 0",
                'discount_percent' => "ALTER TABLE promo_banners ADD COLUMN discount_percent INT NOT NULL DEFAULT 0",
                'applies_to'       => "ALTER TABLE promo_banners ADD COLUMN applies_to VARCHAR(16) NULL",
            ],
        ];
        foreach ($criticalRepairs as $tbl => $columns) {
            $have = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $db->quote($tbl))->fetchAll(PDO::FETCH_COLUMN);
            foreach ($columns as $col => $ddl) {
                if (!in_array($col, $have, true)) {
                    try {
                        $db->exec($ddl);
                        mam_log('info', 'mysql_column_repaired', ['table' => $tbl, 'col' => $col]);
                    } catch (\Throwable $ex) {
                        mam_log('error', 'mysql_column_repair_failed', ['table' => $tbl, 'col' => $col, 'err' => $ex->getMessage()]);
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        mam_log('warn', 'mysql_column_check_failed', ['err' => $e->getMessage()]);
    }

    // Seed the default course catalogue if the table is empty (e.g. on a host
    // where schema-mysql.sql was applied manually before the seed rows existed).
    // INSERT IGNORE skips duplicates, so this is always safe to run.
    try {
        $hasCourses = (int)$db->query("SELECT COUNT(*) FROM courses")->fetchColumn();
        if ($hasCourses === 0) {
            $db->exec("INSERT IGNORE INTO courses (code, name, kind, sort_order) VALUES
                ('rhythm_pitch','Rhythm & Pitch Intelligence','core',1),
                ('performance_etiquette','Performance Etiquette','core',2),
                ('meta_music','Meta Music','core',3),
                ('theory','Theory of Music','core',4),
                ('piano','Piano','elective',10),
                ('voice','Voice','elective',11),
                ('guitar','Guitar','elective',12),
                ('drums','Drums','elective',13),
                ('saxophone','Saxophone','elective',14),
                ('music_production','Music Production','elective',15),
                ('songwriting','Songwriting','elective',16),
                ('conducting','Conducting','elective',17)");
            mam_log('info', 'courses_auto_seeded', ['count' => 12]);
        }
    } catch (\Throwable $e) {
        mam_log('warn', 'courses_seed_check_failed', ['err' => $e->getMessage()]);
    }

    $target = MAM_SCHEMA_TARGET;
    if ($current >= $target) return;

    $path = realpath(__DIR__ . '/../../db/schema-mysql.sql');
    if (!$path || !is_readable($path)) {
        mam_log('warn', 'mysql_schema_missing', ['path' => $path]);
        return;
    }
    $sql = file_get_contents($path);
    // Strip `--` line comments AND `/* ... */` block comments so the splitter
    // doesn't choke on inline semicolons in comment text.
    $sql = preg_replace('!/\*.*?\*/!s', '', $sql);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    // Group prepared-statement triples into one statement (PREPARE … DEALLOCATE).
    // Easiest approach: any block between SET @… and the matching DEALLOCATE is
    // joined and exec'd together. We do this by replacing newlines with spaces
    // within those blocks before splitting.
    $sql = preg_replace_callback('/(SET\s+@[\s\S]*?DEALLOCATE\s+PREPARE\s+\w+\s*;)/i', function ($m) {
        return str_replace("\n", " ", $m[1]);
    }, $sql);

    // Split on `;` followed by a newline (so `;` inside a string literal won't
    // wrongly split — there are none in our schema, but the regex is defensive).
    $statements = preg_split('/;\s*\n/', $sql);

    $ran = 0; $failed = 0;
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        try { $db->exec($stmt); $ran++; }
        catch (PDOException $e) {
            $failed++;
            // 1050 = table exists, 1060 = duplicate column, 1061 = duplicate
            // key, 1091 = can't DROP — all expected when re-running the idempotent
            // schema. NOTE: 1146 (table doesn't exist) is deliberately NOT here —
            // it signals a genuinely broken/out-of-order schema and must surface.
            $expected = [1050, 1060, 1061, 1091];
            $code = (int)$e->errorInfo[1] ?? 0;
            if (!in_array($code, $expected, true)) {
                mam_log('warn', 'mysql_migration_stmt_failed', ['code' => $code, 'msg' => $e->getMessage(), 'stmt' => mb_substr($stmt, 0, 200)]);
            }
        }
    }
    $newVersion = 0;
    try { $newVersion = (int)$db->query('SELECT IFNULL(MAX(version),0) FROM schema_version')->fetchColumn(); } catch (Throwable $e) {}
    mam_log('info', 'mysql_migration_complete', ['from' => $current, 'to' => $newVersion, 'ran' => $ran, 'failed' => $failed]);

    // Loud verification: schema_version advances via the file's own INSERTs, so
    // it can read "complete" even if a DDL statement silently failed to add a
    // column (this is exactly how the MariaDB-only `ADD COLUMN IF NOT EXISTS`
    // syntax used to leave MySQL 8 missing columns). Assert the columns the code
    // depends on actually exist and log an ERROR naming any that don't.
    try {
        $required = [
            'applications'   => ['booking_fee_paid_at', 'program', 'headshot_url', 'session', 'idempotency_key', 'level', 'expression', 'extra_courses', 'courses_label', 'assignee_id', 'program_track', 'readiness_signal'],
            'payments'       => ['purpose'],
            'users'          => ['admin_tier', 'student_type', 'enrollment_ref'],
            'course_enrollments' => ['cohort_id'],
            // Recent features (v39–v50). A missing column here means the CREATE/ALTER
            // silently failed on MySQL (e.g. TEXT literal DEFAULT, or MariaDB-only
            // `IF NOT EXISTS`) and the dependent portal endpoints will 500. Listing a
            // column of each table also detects a wholly-missing table (no columns → all flagged).
            'store_items'     => ['available_locations'],
            'surveys'         => ['kind', 'questions_json'],
            'progress_reports'=> ['answers_json', 'session_id'],
            'class_sessions'  => ['substitute_id', 'room', 'lesson_notes', 'rescheduled_from', 'cancellation_reason'],
            'class_waitlist'  => ['position'],
            'assessments'     => ['score'],
            'results'         => ['grade'],
            'cohorts'         => ['schedule_days', 'schedule_time', 'session_duration'],
            'announcements'   => ['cohort_id'],
            'class_resources'     => ['cohort_id'],
            'summer_applications' => ['reference', 'email', 'status', 'guardian_name'],
            'classes'             => ['meeting_link'],
            'id_cards'            => ['serial', 'status', 'role', 'issued_at'],
            'card_scans'          => ['card_id', 'raw_serial', 'result'],
        ];
        $missing = [];
        foreach ($required as $table => $cols) {
            $have = $db->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = " . $db->quote($table))->fetchAll(PDO::FETCH_COLUMN);
            foreach ($cols as $c) if (!in_array($c, $have, true)) $missing[] = "$table.$c";
        }
        if ($missing) {
            mam_log('error', 'mysql_migration_missing_columns', ['missing' => $missing, 'hint' => 'A migration DDL did not apply — the schema is incomplete and dependent endpoints will 500.']);
        }
        // Record the server flavour + sql_mode once per migration. This is the
        // fastest way to diagnose "works in SQLite, 500s in prod": MySQL 8 vs
        // MariaDB decides whether MariaDB-only DDL applied, and ONLY_FULL_GROUP_BY
        // / STRICT_TRANS_TABLES in sql_mode explain read/write 500s that SQLite
        // (which ignores both) never surfaces.
        $ver  = (string)($db->query('SELECT VERSION()')->fetchColumn() ?: '');
        $mode = (string)($db->query('SELECT @@SESSION.sql_mode')->fetchColumn() ?: '');
        mam_log('info', 'mysql_server_profile', ['version' => $ver, 'sql_mode' => $mode]);
    } catch (Throwable $e) {
        mam_log('warn', 'mysql_migration_verify_failed', ['err' => $e->getMessage()]);
    }
}

function mam_db_driver(): string {
    return mam_db()->getAttribute(PDO::ATTR_DRIVER_NAME);
}

function mam_db_migrate(PDO $db): void {
    // ── Driver-aware migration ────────────────────────────────────────
    // The inline migrations below use SQLite syntax (AUTOINCREMENT, datetime('now'),
    // etc). Running them against MySQL silently fails — which is why production
    // installs hit "couldn't reach our server" errors after switching DBs.
    // For MySQL we instead exec the canonical schema-mysql.sql file, which is
    // idempotent (every ALTER guarded by IF NOT EXISTS, every INSERT by ON
    // DUPLICATE KEY). We only run it when schema_version is behind, so the
    // cold-start overhead is minimal.
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') { mam_db_migrate_mysql($db); return; }

    $db->exec("CREATE TABLE IF NOT EXISTS schema_version (version INTEGER PRIMARY KEY)");
    $current = (int)($db->query('SELECT IFNULL(MAX(version),0) FROM schema_version')->fetchColumn() ?: 0);

    $migrations = [
        1 => "
            CREATE TABLE customers (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              email TEXT UNIQUE NOT NULL,
              first_name TEXT, last_name TEXT,
              phone TEXT, country TEXT, city TEXT,
              age_range TEXT, dob TEXT,
              created_at TEXT DEFAULT (datetime('now')),
              updated_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_customers_email ON customers(email);

            CREATE TABLE applications (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              reference TEXT UNIQUE NOT NULL,
              customer_id INTEGER REFERENCES customers(id) ON DELETE SET NULL,
              pathway TEXT NOT NULL,
              mode TEXT, discipline TEXT,
              experience TEXT, goals TEXT, referrer TEXT,
              parent_name TEXT, parent_phone TEXT, emergency_contact TEXT,
              auditer_optin INTEGER DEFAULT 0,
              include_registration INTEGER DEFAULT 1,
              include_certification INTEGER DEFAULT 0,
              total_usd REAL,
              status TEXT DEFAULT 'submitted',
              source TEXT, ip TEXT, user_agent TEXT,
              created_at TEXT DEFAULT (datetime('now')),
              updated_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_apps_status ON applications(status);
            CREATE INDEX idx_apps_created ON applications(created_at);

            CREATE TABLE payments (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              application_id INTEGER REFERENCES applications(id) ON DELETE CASCADE,
              reference TEXT UNIQUE NOT NULL,
              idempotency_key TEXT,
              provider TEXT NOT NULL,
              status TEXT DEFAULT 'pending',
              amount REAL NOT NULL,
              currency TEXT NOT NULL,
              provider_reference TEXT,
              provider_response TEXT,
              verified_at TEXT,
              created_at TEXT DEFAULT (datetime('now')),
              updated_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_pay_status ON payments(status);
            CREATE INDEX idx_pay_provider_ref ON payments(provider, provider_reference);

            CREATE TABLE webhook_events (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              provider TEXT NOT NULL,
              event_id TEXT,
              event_type TEXT,
              payload TEXT,
              signature_valid INTEGER DEFAULT 0,
              status TEXT DEFAULT 'received',
              error TEXT,
              received_at TEXT DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX idx_webhook_unique ON webhook_events(provider, event_id);

            CREATE TABLE newsletter (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              email TEXT UNIQUE NOT NULL,
              source TEXT,
              status TEXT DEFAULT 'subscribed',
              created_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE messages (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              name TEXT, email TEXT, phone TEXT,
              topic TEXT, message TEXT,
              ip TEXT,
              status TEXT DEFAULT 'unread',
              created_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE audit_log (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              actor TEXT, action TEXT, entity TEXT, entity_id INTEGER,
              meta TEXT, ip TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_audit_entity ON audit_log(entity, entity_id);

            CREATE TABLE rate_limits (
              bucket TEXT PRIMARY KEY,
              count INTEGER DEFAULT 0,
              reset_at INTEGER NOT NULL
            );
        ",
        2 => "
            CREATE TABLE users (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              email TEXT UNIQUE NOT NULL,
              password_hash TEXT NOT NULL,
              role TEXT NOT NULL CHECK(role IN ('admin','instructor','student')),
              name TEXT,
              phone TEXT,
              avatar_url TEXT,
              status TEXT DEFAULT 'active',
              customer_id INTEGER REFERENCES customers(id) ON DELETE SET NULL,
              last_login TEXT,
              must_change_password INTEGER DEFAULT 0,
              created_at TEXT DEFAULT (datetime('now')),
              updated_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_users_role ON users(role);
            CREATE INDEX idx_users_customer ON users(customer_id);

            CREATE TABLE sessions (
              token_hash TEXT PRIMARY KEY,
              user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              ip TEXT, ua TEXT,
              created_at TEXT DEFAULT (datetime('now')),
              expires_at TEXT NOT NULL
            );
            CREATE INDEX idx_sessions_user ON sessions(user_id);
            CREATE INDEX idx_sessions_expires ON sessions(expires_at);

            CREATE TABLE instructor_profiles (
              user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
              bio TEXT, specialties TEXT, photo_url TEXT,
              years_experience INTEGER,
              updated_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE classes (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              title TEXT NOT NULL,
              discipline TEXT,
              pathway TEXT,
              instructor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
              mode TEXT,
              schedule TEXT,
              capacity INTEGER DEFAULT 12,
              status TEXT DEFAULT 'active',
              starts_on TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE enrollments (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              class_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
              application_id INTEGER REFERENCES applications(id) ON DELETE SET NULL,
              status TEXT DEFAULT 'active',
              enrolled_at TEXT DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX idx_enrollments_unique ON enrollments(student_id, class_id);

            CREATE TABLE attendance (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              enrollment_id INTEGER NOT NULL REFERENCES enrollments(id) ON DELETE CASCADE,
              session_date TEXT NOT NULL,
              status TEXT NOT NULL CHECK(status IN ('present','absent','late','excused')),
              notes TEXT,
              marked_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_attendance_enrollment ON attendance(enrollment_id);

            CREATE TABLE class_resources (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              class_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
              title TEXT NOT NULL,
              url TEXT NOT NULL,
              type TEXT,
              posted_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE auditer_results (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              dimensions TEXT,
              recommended_pathway TEXT,
              notes TEXT,
              audited_at TEXT DEFAULT (datetime('now')),
              audited_by INTEGER REFERENCES users(id) ON DELETE SET NULL
            );

            CREATE TABLE password_resets (
              token_hash TEXT PRIMARY KEY,
              user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              expires_at TEXT NOT NULL,
              used_at TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
        ",
        // KTAMS/MAM track + class-policy agreement on applications.
        3 => "
            ALTER TABLE applications ADD COLUMN program_track TEXT DEFAULT 'MAM';
            ALTER TABLE applications ADD COLUMN policy_mode TEXT;
            ALTER TABLE applications ADD COLUMN policy_agreed INTEGER DEFAULT 0;
            ALTER TABLE applications ADD COLUMN policy_signed_name TEXT;
            ALTER TABLE applications ADD COLUMN policy_signed_at TEXT;
        ",
        // Expanded applicant profile to match the published Google Form:
        // sex/address/LGA/state on customers, programme + headshot URL on
        // applications. All additive, all nullable for backwards compat.
        4 => "
            ALTER TABLE customers    ADD COLUMN sex TEXT;
            ALTER TABLE customers    ADD COLUMN address TEXT;
            ALTER TABLE customers    ADD COLUMN lga TEXT;
            ALTER TABLE customers    ADD COLUMN state TEXT;
            ALTER TABLE applications ADD COLUMN program TEXT;
            ALTER TABLE applications ADD COLUMN headshot_url TEXT;
        ",
        // Preferred intake session (TONI / MEDI / SUMMER / DOMI — named for
        // scale degrees) so we can route applicants to the matching cohort.
        5 => "
            ALTER TABLE applications ADD COLUMN session TEXT;
        ",
        // Abandonment-recovery tracking: stamped when the cron sends a
        // "complete your enrolment" nudge so we never email twice.
        6 => "
            ALTER TABLE applications ADD COLUMN recovery_sent_at TEXT;
        ",
        // Idempotency key so a retried submission returns the original
        // application instead of creating a duplicate.
        7 => "
            ALTER TABLE applications ADD COLUMN idempotency_key TEXT;
            CREATE INDEX idx_apps_idem ON applications(idempotency_key);
        ",
        // Booking-fee flow: the application is now free; a flat \$5 booking
        // fee unlocks the Book-a-Session page (registration + ID card +
        // student file + 30-min MAM Auditer assessment). `purpose` tags
        // each payment ('booking_fee' | 'tuition' | 'store') so the same
        // payment infrastructure can serve all three flows safely.
        8 => "
            ALTER TABLE applications ADD COLUMN booking_fee_paid_at TEXT;
            ALTER TABLE payments     ADD COLUMN purpose TEXT DEFAULT 'tuition';
        ",
        // Store / Library — a public catalogue of digital + physical + service
        // items (sheet music, handouts, courses, instruments, accessories,
        // tutoring add-ons). The same payments table serves store orders via
        // purpose='store'; each order's reference is used as the payment ref.
        // Designed subdomain-ready: nothing here references academy tables.
        9 => "
            CREATE TABLE store_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sku TEXT UNIQUE NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                description TEXT,
                goods_type TEXT NOT NULL DEFAULT 'digital',
                delivery_type TEXT NOT NULL DEFAULT 'instant',
                availability TEXT NOT NULL DEFAULT 'in_stock',
                stock INTEGER DEFAULT -1,
                available_locations TEXT DEFAULT '*',
                price_usd REAL NOT NULL,
                currency TEXT DEFAULT 'USD',
                images TEXT,
                category TEXT,
                file_url TEXT,
                weight_grams INTEGER,
                tax_rate REAL DEFAULT 0,
                sort_order INTEGER DEFAULT 0,
                active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX idx_store_items_active   ON store_items(active);
            CREATE INDEX idx_store_items_category ON store_items(category);
            CREATE INDEX idx_store_items_sort     ON store_items(sort_order);
            CREATE TABLE store_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reference TEXT UNIQUE NOT NULL,
                customer_id INTEGER,
                customer_email TEXT,
                customer_name TEXT,
                customer_country TEXT,
                customer_phone TEXT,
                shipping_address TEXT,
                items_json TEXT NOT NULL,
                subtotal_usd REAL DEFAULT 0,
                tax_usd REAL DEFAULT 0,
                shipping_usd REAL DEFAULT 0,
                total_usd REAL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'pending',
                notes TEXT,
                delivered_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX idx_store_orders_status ON store_orders(status);
            CREATE INDEX idx_store_orders_email  ON store_orders(customer_email);
        ",
        // v10 — no-op on SQLite (CREATE TABLE for payments doesn't declare
        // application_id NOT NULL there, so store rows already insert fine).
        // Bumping schema_version keeps SQLite + MySQL in lockstep.
        10 => "SELECT 1;",
        // v11 — admin-editable settings (key/value). Columns are skey/svalue
        // because `key`/`value` are reserved words in MySQL. Seeded with the
        // shipped pricing defaults so a fresh install behaves identically to
        // the old hardcoded values until an admin changes them.
        11 => "
            CREATE TABLE settings (
              skey TEXT PRIMARY KEY,
              svalue TEXT,
              updated_at TEXT DEFAULT (datetime('now'))
            );
            INSERT INTO settings (skey, svalue) VALUES
              ('price_certified_musician','230'),
              ('price_grand_musician','310'),
              ('price_crash','70'),
              ('price_meta_order','60'),
              ('online_surcharge_pct','20'),
              ('registration_fee_usd','5'),
              ('store_shipping_flat_usd','5'),
              ('usd_to_ngn','1500');
        ",
        // v12 — Crash is a flat $70 with no levels (spec/review). Lift the
        // seeded price from the old $40 default. Conditional on the old value
        // so an admin who deliberately set a different Crash price is untouched.
        12 => "UPDATE settings SET svalue='70', updated_at=datetime('now')
                 WHERE skey='price_crash' AND svalue='40';",
        // v13 — level-based application model (spec §2). The rebuilt form
        // captures the academic level, the performance expression, and the
        // count/label of additional ("minor") courses beyond the primary one.
        13 => "
            ALTER TABLE applications ADD COLUMN level TEXT;
            ALTER TABLE applications ADD COLUMN expression TEXT;
            ALTER TABLE applications ADD COLUMN extra_courses INTEGER DEFAULT 0;
            ALTER TABLE applications ADD COLUMN courses_label TEXT;
        ",
        // v14 — class announcements (instructor → enrolled students) and
        // server-persisted student practice logs (was localStorage-only).
        14 => "
            CREATE TABLE announcements (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              class_id INTEGER REFERENCES classes(id) ON DELETE CASCADE,
              author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
              title TEXT,
              body TEXT NOT NULL,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_announcements_class ON announcements(class_id);
            CREATE TABLE practice_logs (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              minutes INTEGER NOT NULL DEFAULT 0,
              focus TEXT,
              notes TEXT,
              logged_on TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_practice_student ON practice_logs(student_id);
        ",
        // v15 — instructor → student assignments (practice tasks, pieces to
        // prepare, theory worksheets). One row per student; class-wide
        // assignments fan out to one row each so completion is tracked per pupil.
        15 => "
            CREATE TABLE assignments (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
              class_id INTEGER REFERENCES classes(id) ON DELETE SET NULL,
              title TEXT NOT NULL,
              instructions TEXT,
              due_on TEXT,
              status TEXT NOT NULL DEFAULT 'open',
              completed_at TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_assignments_student ON assignments(student_id);
            CREATE INDEX idx_assignments_author ON assignments(author_id);
        ",
        // v16 — Academic LMS backbone. A normalized, code-free model so the
        // academy can add programmes/levels/courses/modules and run weekly
        // assessments → results → certificates without code changes. Fully
        // additive (existing tables untouched). Multi-role via user_roles so a
        // user can be student + instructor + admin at once. Durations are data
        // (programs.weeks/exam_week), so 6-week vs 12-week is admin-configurable.
        16 => "
            CREATE TABLE user_roles (
              user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              role TEXT NOT NULL CHECK(role IN ('admin','instructor','student')),
              PRIMARY KEY (user_id, role)
            );
            INSERT OR IGNORE INTO user_roles (user_id, role) SELECT id, role FROM users;

            CREATE TABLE programs (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              code TEXT UNIQUE NOT NULL,
              name TEXT NOT NULL,
              weeks INTEGER NOT NULL DEFAULT 12,
              revision_week INTEGER,
              exam_week INTEGER,
              requires_core INTEGER NOT NULL DEFAULT 0,
              certification TEXT,
              sort_order INTEGER DEFAULT 0,
              active INTEGER NOT NULL DEFAULT 1
            );
            INSERT INTO programs (code,name,weeks,revision_week,exam_week,requires_core,certification,sort_order) VALUES
              ('crash','Crash Program',6,NULL,6,0,'participation',1),
              ('standard','Standard Program',12,11,12,0,'completion',2),
              ('certified','Certified Program',12,11,12,1,'internal',3),
              ('grand','Grand Certified Program',12,11,12,1,'internal_diploma',4);

            CREATE TABLE levels (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              code TEXT UNIQUE NOT NULL,
              name TEXT NOT NULL,
              trinity_grades TEXT,
              sort_order INTEGER NOT NULL
            );
            INSERT INTO levels (code,name,trinity_grades,sort_order) VALUES
              ('precert','Pre-Certification','Grade 1-2',1),
              ('cert','Certificate','Grade 3-4',2),
              ('prediploma','Pre-Diploma','Grade 5-6',3),
              ('diploma','Diploma','Grade 7-8',4);

            CREATE TABLE courses (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              code TEXT UNIQUE NOT NULL,
              name TEXT NOT NULL,
              kind TEXT NOT NULL DEFAULT 'elective',
              description TEXT,
              sort_order INTEGER DEFAULT 0,
              active INTEGER NOT NULL DEFAULT 1
            );
            INSERT INTO courses (code,name,kind,sort_order) VALUES
              ('rhythm_pitch','Rhythm & Pitch Intelligence','core',1),
              ('performance_etiquette','Performance Etiquette','core',2),
              ('meta_music','Meta Music','core',3),
              ('theory','Theory of Music','core',4),
              ('piano','Piano','elective',10),
              ('voice','Voice','elective',11),
              ('guitar','Guitar','elective',12),
              ('drums','Drums','elective',13),
              ('saxophone','Saxophone','elective',14),
              ('music_production','Music Production','elective',15),
              ('songwriting','Songwriting','elective',16),
              ('conducting','Conducting','elective',17);

            CREATE TABLE modules (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              course_id INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
              level_id INTEGER REFERENCES levels(id) ON DELETE SET NULL,
              week INTEGER NOT NULL,
              title TEXT NOT NULL,
              kind TEXT NOT NULL DEFAULT 'module',
              content TEXT
            );
            CREATE INDEX idx_modules_course ON modules(course_id, level_id);

            CREATE TABLE instructor_assignments (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              instructor_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              course_id INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
              level_id INTEGER REFERENCES levels(id) ON DELETE SET NULL,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX idx_instr_assign ON instructor_assignments(instructor_id, course_id, level_id);

            CREATE TABLE course_enrollments (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              course_id INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
              level_id INTEGER REFERENCES levels(id) ON DELETE SET NULL,
              program_id INTEGER REFERENCES programs(id) ON DELETE SET NULL,
              instructor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
              requested_instructor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
              status TEXT NOT NULL DEFAULT 'pending',
              approved_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              approved_at TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_cenroll_student ON course_enrollments(student_id);
            CREATE INDEX idx_cenroll_course ON course_enrollments(course_id);
            CREATE INDEX idx_cenroll_status ON course_enrollments(status);

            CREATE TABLE assessment_types (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              code TEXT UNIQUE NOT NULL,
              name TEXT NOT NULL,
              weight INTEGER NOT NULL DEFAULT 0,
              sort_order INTEGER DEFAULT 0,
              active INTEGER NOT NULL DEFAULT 1
            );
            INSERT INTO assessment_types (code,name,weight,sort_order) VALUES
              ('attendance','Attendance',10,1),
              ('assignment','Assignment',20,2),
              ('practical','Practical',30,3),
              ('continuous','Continuous Assessment',10,4),
              ('final','Final Examination',30,5);

            CREATE TABLE assessments (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              enrollment_id INTEGER NOT NULL REFERENCES course_enrollments(id) ON DELETE CASCADE,
              type_id INTEGER REFERENCES assessment_types(id) ON DELETE SET NULL,
              instructor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
              week INTEGER,
              score REAL,
              max_score REAL NOT NULL DEFAULT 100,
              notes TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_assess_enroll ON assessments(enrollment_id);

            CREATE TABLE results (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              enrollment_id INTEGER NOT NULL UNIQUE REFERENCES course_enrollments(id) ON DELETE CASCADE,
              total_score REAL,
              grade TEXT,
              outcome TEXT,
              finalized_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              finalized_at TEXT
            );

            CREATE TABLE certificates (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              reference TEXT UNIQUE NOT NULL,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              program_id INTEGER REFERENCES programs(id) ON DELETE SET NULL,
              level_id INTEGER REFERENCES levels(id) ON DELETE SET NULL,
              kind TEXT NOT NULL,
              title TEXT,
              issued_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              issued_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_certs_student ON certificates(student_id);
        ",
        // v17 — Outbound email outbox. Transactional mail used to be
        // fire-and-forget: a failed SMTP send vanished into a log file with no
        // record, no admin visibility, and no way to recover it. This makes
        // every send a durable, queryable row so an operator can SEE which
        // emails failed and why, and re-send them once config is fixed (admin
        // 'Retry failed' button + the api/tools/mail-retry.php cron). The body
        // + opts are retained ONLY while a row is retryable; cleared on success
        // so we don't hoard rendered PII. Additive — touches nothing existing.
        17 => "
            CREATE TABLE mail_log (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              to_email TEXT NOT NULL,
              subject TEXT,
              context TEXT,
              is_html INTEGER NOT NULL DEFAULT 1,
              status TEXT NOT NULL DEFAULT 'queued',
              transport TEXT,
              error TEXT,
              attempts INTEGER NOT NULL DEFAULT 0,
              body TEXT,
              opts TEXT,
              created_at TEXT DEFAULT (datetime('now')),
              updated_at TEXT DEFAULT (datetime('now')),
              sent_at TEXT
            );
            CREATE INDEX idx_mail_log_status ON mail_log(status);
            CREATE INDEX idx_mail_log_created ON mail_log(created_at);
        ",
        // v18 — Enrolment lifecycle (LMS L1). Enrolment status was free text
        // with no enforced transitions and no record of who changed it, when, or
        // why. This adds an append-only audit of every state change
        // (enrolment_events) plus a status_changed_at stamp; the legal moves
        // themselves are enforced in code (mam_lms_transition). Pays down the
        // "lifecycle is implicit" debt that the rest of the LMS roadmap would
        // otherwise inherit. Fully additive — existing rows keep their status.
        18 => "
            CREATE TABLE enrolment_events (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              enrollment_id INTEGER NOT NULL REFERENCES course_enrollments(id) ON DELETE CASCADE,
              from_status TEXT,
              to_status TEXT NOT NULL,
              actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
              reason TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_enrolment_events_enrollment ON enrolment_events(enrollment_id, id);
            ALTER TABLE course_enrollments ADD COLUMN status_changed_at TEXT;
        ",
        // v19 — Cohorts (LMS L1: unify the two enrolment systems). A cohort is
        // the single 'scheduled run of a course' that both the legacy
        // classes/enrollments world (attendance, resources) and the v16
        // course_enrollments world (gradebook) hang off. Additive: introduces
        // `cohorts`, links course_enrollments.cohort_id (nullable), and
        // idempotently backfills one cohort per legacy class (keyed by
        // legacy_class_id). No read-path is cut over here — that follows in a
        // separately-tested increment (dual-read discipline). On a fresh DB the
        // backfill is a no-op (no classes yet).
        19 => "
            CREATE TABLE cohorts (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              course_id INTEGER REFERENCES courses(id) ON DELETE SET NULL,
              level_id INTEGER REFERENCES levels(id) ON DELETE SET NULL,
              program_id INTEGER REFERENCES programs(id) ON DELETE SET NULL,
              instructor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
              title TEXT,
              discipline TEXT,
              pathway TEXT,
              mode TEXT,
              term TEXT,
              capacity INTEGER DEFAULT 12,
              meeting_pattern TEXT,
              starts_on TEXT,
              ends_on TEXT,
              status TEXT NOT NULL DEFAULT 'active',
              legacy_class_id INTEGER,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX idx_cohorts_legacy ON cohorts(legacy_class_id);
            CREATE INDEX idx_cohorts_course ON cohorts(course_id, level_id);
            CREATE INDEX idx_cohorts_instructor ON cohorts(instructor_id);

            ALTER TABLE course_enrollments ADD COLUMN cohort_id INTEGER;
            CREATE INDEX idx_cenroll_cohort ON course_enrollments(cohort_id);

            INSERT OR IGNORE INTO cohorts (instructor_id, title, discipline, pathway, mode, capacity, meeting_pattern, starts_on, status, legacy_class_id, created_at)
              SELECT instructor_id, title, discipline, pathway, mode, COALESCE(capacity,12), schedule, starts_on, COALESCE(status,'active'), id, created_at FROM classes;
        ",
        // v20 — AI run audit (provider-agnostic AI gateway). Every call through
        // api/lib/ai.php records who/what/which-provider for reproducibility +
        // quota, WITHOUT hoarding PII: we store a prompt HASH, not the prompt.
        // Additive; supports the free-by-default, premium-optional AI layer.
        20 => "
            CREATE TABLE ai_runs (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              use_case TEXT NOT NULL,
              provider TEXT,
              model TEXT,
              ok INTEGER NOT NULL DEFAULT 0,
              prompt_hash TEXT,
              input_tokens INTEGER DEFAULT 0,
              output_tokens INTEGER DEFAULT 0,
              error TEXT,
              reviewed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              reviewed_at TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_ai_runs_usecase ON ai_runs(use_case, id);
        ",
        // v21 — Class sessions (LMS L2: scheduling on cohorts). A class_session
        // is a scheduled meeting of a cohort — the first-class scheduling
        // primitive the cohort grouping was built to carry. NAMED class_sessions
        // (not sessions) because the auth-session table already owns `sessions`.
        // Additive; attendance re-homing + the scheduling UI follow in later,
        // separately-tested increments (dual-read discipline).
        21 => "
            CREATE TABLE class_sessions (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              cohort_id INTEGER NOT NULL REFERENCES cohorts(id) ON DELETE CASCADE,
              title TEXT,
              scheduled_at TEXT,
              duration_min INTEGER DEFAULT 60,
              kind TEXT NOT NULL DEFAULT 'class',
              location TEXT,
              status TEXT NOT NULL DEFAULT 'scheduled',
              notes TEXT,
              created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_class_sessions_cohort ON class_sessions(cohort_id, scheduled_at);
        ",
        // v22 — Session attendance (LMS L2). Attendance keyed to a class_session
        // (the cohort-scoped meeting), one record per (session, student). Same
        // status vocabulary as the legacy `attendance` table; runs ALONGSIDE it
        // (dual-read) — no cutover here. Additive.
        22 => "
            CREATE TABLE session_attendance (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              session_id INTEGER NOT NULL REFERENCES class_sessions(id) ON DELETE CASCADE,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              status TEXT NOT NULL CHECK(status IN ('present','absent','late','excused')),
              notes TEXT,
              marked_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at TEXT DEFAULT (datetime('now')),
              updated_at TEXT DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX idx_sess_att_unique ON session_attendance(session_id, student_id);
            CREATE INDEX idx_sess_att_student ON session_attendance(student_id);
        ",
        // v23 — Submissions & feedback (LMS L2). A student submits an artifact
        // (link/audio/video/pdf) against an assignment; the instructor leaves
        // feedback (1:1 with a submission, editable) whose outcome drives the
        // submission status. Resubmissions create new rows (history). Additive.
        23 => "
            CREATE TABLE submissions (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              assignment_id INTEGER NOT NULL REFERENCES assignments(id) ON DELETE CASCADE,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              artifact_url TEXT,
              artifact_kind TEXT NOT NULL DEFAULT 'link',
              note TEXT,
              status TEXT NOT NULL DEFAULT 'submitted',
              submitted_at TEXT DEFAULT (datetime('now')),
              created_at TEXT DEFAULT (datetime('now')),
              updated_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_submissions_assignment ON submissions(assignment_id, id);
            CREATE INDEX idx_submissions_student ON submissions(student_id, id);

            CREATE TABLE feedback (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              submission_id INTEGER NOT NULL UNIQUE REFERENCES submissions(id) ON DELETE CASCADE,
              instructor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
              rubric_json TEXT,
              comments TEXT,
              outcome TEXT,
              created_at TEXT DEFAULT (datetime('now')),
              updated_at TEXT DEFAULT (datetime('now'))
            );
        ",
        // v24 — Server-side application drafts (Application A1). Mirrors the
        // client's localStorage draft so an application can be resumed on ANY
        // device via a signed magic link. Keyed by the client's idempotency key.
        24 => "
            CREATE TABLE application_drafts (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              draft_key TEXT NOT NULL UNIQUE,
              email TEXT,
              payload TEXT,
              status TEXT NOT NULL DEFAULT 'draft',
              created_at TEXT DEFAULT (datetime('now')),
              updated_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_app_drafts_email ON application_drafts(email);
        ",
        // v25 — Automation spine (LMS L3). Append-only domain `events` + a due-able
        // `jobs` queue drained by a single cron (api/tools/run-jobs.php). The
        // foundation for reminders, auto-progression, digests, and notifications.
        25 => "
            CREATE TABLE events (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              type TEXT NOT NULL,
              entity TEXT,
              entity_id INTEGER,
              payload TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_events_type ON events(type, id);

            CREATE TABLE jobs (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              kind TEXT NOT NULL,
              payload TEXT,
              run_after TEXT,
              attempts INTEGER NOT NULL DEFAULT 0,
              status TEXT NOT NULL DEFAULT 'pending',
              last_error TEXT,
              created_at TEXT DEFAULT (datetime('now')),
              updated_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_jobs_due ON jobs(status, run_after);
        ",
        // v26 — Admin access tiers (RBAC). An admin user is 'super' (full
        // control) or 'manager' (operational only — no settings/users/pricing/
        // mail/migrate). NULL = treated as 'super' in code, and existing admins
        // are backfilled to 'super', so nobody loses access. Additive.
        26 => "
            ALTER TABLE users ADD COLUMN admin_tier TEXT;
            UPDATE users SET admin_tier = 'super' WHERE role = 'admin' AND (admin_tier IS NULL OR admin_tier = '');
        ",
        // v27 — Admissions pipeline (Application A2). An enforced state machine
        // over applications.status with an audit trail (application_events),
        // internal staff notes (application_notes), and an assignee. Drives the
        // applicant status portal (A1). Additive.
        27 => "
            ALTER TABLE applications ADD COLUMN assignee_id INTEGER;
            CREATE TABLE application_events (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
              from_status TEXT,
              to_status TEXT NOT NULL,
              actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
              reason TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_app_events_app ON application_events(application_id, id);
            CREATE TABLE application_notes (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
              author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
              note TEXT NOT NULL,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_app_notes_app ON application_notes(application_id, id);
        ",
        // v28 — Funnel analytics (Application A3). First-party, privacy-
        // respecting instrumentation: anonymous funnel-session id (sid, from
        // sessionStorage — NOT identity), an allowlisted event, the step, the
        // pathway, and a small no-PII meta blob. Powers drop-off measurement
        // per step and per pathway. Honours Do-Not-Track at the edge. Additive.
        28 => "
            CREATE TABLE funnel_events (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              sid TEXT,
              event TEXT NOT NULL,
              step INTEGER,
              pathway TEXT,
              meta TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_funnel_event ON funnel_events(event, created_at);
            CREATE INDEX idx_funnel_sid ON funnel_events(sid);
        ",
        // v29 — Notification centre (LMS L3). A per-user in-app inbox over the
        // automation spine: a typed, titled notification with an optional body
        // and deep link, and a read_at marker. Recipients are real users
        // (CASCADE on delete). Emitted at key moments (feedback, finalize,
        // assignment) and surfaced in the portals + admin header. Additive.
        29 => "
            CREATE TABLE notifications (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              type TEXT NOT NULL,
              title TEXT NOT NULL,
              body TEXT,
              url TEXT,
              read_at TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_notif_user ON notifications(user_id, read_at, id);
        ",
        // v30 — Studio leaderboard (S2). One row per (user, tool) holding their
        // best score + play count — the substrate for an OPT-IN cohort
        // leaderboard. Submission is explicit (the student chooses to post);
        // PII is minimised at read time (first name only). Additive.
        30 => "
            CREATE TABLE tool_scores (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              tool TEXT NOT NULL,
              best_score INTEGER NOT NULL DEFAULT 0,
              plays INTEGER NOT NULL DEFAULT 0,
              updated_at TEXT DEFAULT (datetime('now')),
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX idx_tool_scores_user_tool ON tool_scores(user_id, tool);
            CREATE INDEX idx_tool_scores_board ON tool_scores(tool, best_score);
        ",
        // v31 — Scholarship tier (#4). A nullable student_type on users separates
        // scholarship recipients from fee-paying students. NULL/'' = paying (the
        // default for every existing + new account); 'scholarship' = sponsored.
        // Drives invoice exemption + portal/admin reporting. Additive, no default
        // clause (MySQL TEXT can't carry one) — the code treats NULL as 'paying'.
        31 => "
            ALTER TABLE users ADD COLUMN student_type TEXT;
        ",
        // v32 — Internal wallet (#12). An append-only credit ledger: one signed
        // row per transaction (amount_cents), with the running balance_after for
        // audit. Balance = SUM(amount_cents). Admin-issued credits only, spendable
        // on the store only (no Paystack/Flutterwave top-up or withdrawal). Every
        // mutation is server-validated (a debit can't drive the balance negative).
        32 => "
            CREATE TABLE wallet_ledger (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              amount_cents INTEGER NOT NULL,
              reason TEXT NOT NULL,
              reference TEXT,
              note TEXT,
              balance_after_cents INTEGER NOT NULL,
              created_by INTEGER,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_wallet_user ON wallet_ledger(user_id, id);
        ",
        // v33 — Learning Readiness (portal brief #1, reframed per flow-guide §6).
        // A single advisory signal (ready | foundation | review) derived
        // server-side from data the apply form already collects (age + experience)
        // — NO new questions. It routes/flags for admissions and NEVER rejects.
        // Additive nullable column, no DEFAULT (MySQL TEXT can't carry one); code
        // treats NULL as 'unknown'.
        33 => "
            ALTER TABLE applications ADD COLUMN readiness_signal TEXT;
        ",
        // v34 — MAM Scholars Initiative (scholarship flow, flow-guide §2c). A
        // parallel, opt-in track separate from admissions: essay-based assessment
        // + its own status. Award still routes through admin set-student-type; the
        // committee decides. Advisory readiness_signal reuses mam_application_readiness.
        34 => "
            CREATE TABLE scholarship_applications (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              reference TEXT UNIQUE NOT NULL,
              application_reference TEXT,
              full_name TEXT NOT NULL,
              email TEXT NOT NULL,
              age_band TEXT,
              experience TEXT,
              financial_need TEXT,
              musical_journey TEXT,
              values_alignment TEXT,
              community_impact TEXT,
              commitment_ack INTEGER NOT NULL DEFAULT 0,
              readiness_signal TEXT,
              status TEXT NOT NULL DEFAULT 'submitted',
              ip TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_scholarship_email ON scholarship_applications(email);
            CREATE INDEX idx_scholarship_status ON scholarship_applications(status, id);
        ",
        // v35 — instructor curriculum proposals (portal upgrade P2 / curriculum
        // authoring). Instructors propose a week's module for a course/level they
        // teach; an admin approves (→ applied to `modules`) or rejects. Keeps the
        // canonical `modules` table admin-gated while opening authoring to faculty.
        35 => "
            CREATE TABLE module_proposals (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              course_id INTEGER NOT NULL,
              level_id INTEGER,
              week INTEGER NOT NULL,
              title TEXT NOT NULL,
              kind TEXT NOT NULL DEFAULT 'module',
              content TEXT,
              proposed_by INTEGER,
              status TEXT NOT NULL DEFAULT 'pending',
              review_note TEXT,
              reviewed_by INTEGER,
              reviewed_at TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_modprop_status ON module_proposals(status, id);
            CREATE INDEX idx_modprop_by ON module_proposals(proposed_by, id);
        ",
        // v36 — instructor (faculty) applications: the details+headshot form a
        // prospective instructor completes via their emailed invite link.
        36 => "
            CREATE TABLE instructor_applications (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              email TEXT NOT NULL,
              full_name TEXT,
              phone TEXT,
              disciplines TEXT,
              experience_years INTEGER,
              bio TEXT,
              links TEXT,
              cv_url TEXT,
              headshot_url TEXT,
              status TEXT NOT NULL DEFAULT 'submitted',
              ip TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_instrapp_email ON instructor_applications(email);
            CREATE INDEX idx_instrapp_status ON instructor_applications(status, id);
        ",
        // v37 — faculty invites become stateful: requests queue for admin
        // approval; an approved invite carries a single-use nonce (the form
        // link is never reusable) and expires after 7 days.
        37 => "
            CREATE TABLE faculty_invites (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              email TEXT NOT NULL,
              name TEXT,
              source TEXT NOT NULL DEFAULT 'contact',
              status TEXT NOT NULL DEFAULT 'requested',
              nonce TEXT,
              expires_at TEXT,
              invited_by INTEGER,
              invited_at TEXT,
              used_at TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_facinv_email ON faculty_invites(email);
            CREATE INDEX idx_facinv_status ON faculty_invites(status, id);
        ",
        // v38 — lifecycle automations: an admin-toggleable enable flag per rule
        // (see api/lib/automations.php). One row per fired/edited rule; unknown
        // rows fall back to the registry default, so this table is additive.
        38 => "
            CREATE TABLE automations (
              akey        TEXT PRIMARY KEY,
              enabled     INTEGER NOT NULL DEFAULT 1,
              params      TEXT,
              last_run_at TEXT,
              run_count   INTEGER NOT NULL DEFAULT 0,
              updated_at  TEXT DEFAULT (datetime('now'))
            );
        ",
        // Convergence (P2): let an announcement target a COHORT as well as a
        // legacy class, so a course/cohort-only student sees their instructor's
        // updates. class_id is already nullable; cohort_id is the parallel link.
        39 => "
            ALTER TABLE announcements ADD COLUMN cohort_id INTEGER REFERENCES cohorts(id) ON DELETE CASCADE;
            CREATE INDEX idx_announcements_cohort ON announcements(cohort_id);
        ",
        // Convergence (P2): let a learning resource attach to a COHORT as well as
        // a legacy class. class_resources.class_id was NOT NULL, so we rebuild the
        // table to make it nullable and add cohort_id. Safe with foreign_keys=ON:
        // class_resources is a leaf table (nothing references it), so DROP/RENAME
        // won't cascade or orphan any inbound reference.
        40 => "
            CREATE TABLE class_resources_new (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              class_id INTEGER REFERENCES classes(id) ON DELETE CASCADE,
              cohort_id INTEGER REFERENCES cohorts(id) ON DELETE CASCADE,
              title TEXT NOT NULL,
              url TEXT NOT NULL,
              type TEXT,
              posted_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at TEXT DEFAULT (datetime('now'))
            );
            INSERT INTO class_resources_new (id, class_id, title, url, type, posted_by, created_at)
              SELECT id, class_id, title, url, type, posted_by, created_at FROM class_resources;
            DROP TABLE class_resources;
            ALTER TABLE class_resources_new RENAME TO class_resources;
            CREATE INDEX idx_class_resources_cohort ON class_resources(cohort_id);
        ",
        // Enterprise curriculum: KTAMS as a first-class program + program_courses
        // join table linking each course to the program(s) it belongs to, with a
        // preferred level and display order. Seeded with the known associations
        // (KTAMS → drums/guitar/piano at precert; others → all active courses).
        41 => "
            INSERT OR IGNORE INTO programs (code, name, weeks, revision_week, exam_week, requires_core, certification, sort_order, active)
            VALUES ('ktams', 'KTAMS Young Musicians', 12, 11, 12, 0, 'participation', 0, 1);

            CREATE TABLE IF NOT EXISTS program_courses (
              program_id INTEGER NOT NULL REFERENCES programs(id) ON DELETE CASCADE,
              course_id  INTEGER NOT NULL REFERENCES courses(id)  ON DELETE CASCADE,
              level_code TEXT,
              sort_order INTEGER NOT NULL DEFAULT 0,
              PRIMARY KEY (program_id, course_id)
            );

            INSERT OR IGNORE INTO program_courses (program_id, course_id, level_code, sort_order)
              SELECT p.id, c.id, 'precert', c.sort_order
              FROM programs p, courses c
              WHERE p.code = 'ktams' AND c.code IN ('drums','guitar','piano');

            INSERT OR IGNORE INTO program_courses (program_id, course_id, sort_order)
              SELECT p.id, c.id, c.sort_order
              FROM programs p, courses c
              WHERE p.code IN ('crash','standard','certified','grand') AND c.active = 1;
        ",

        42 => "
            CREATE TABLE IF NOT EXISTS student_feedback (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              rating INTEGER NOT NULL CHECK(rating BETWEEN 1 AND 5),
              category TEXT NOT NULL DEFAULT 'general',
              subject TEXT,
              body TEXT NOT NULL,
              enrollment_id INTEGER REFERENCES enrollments(id) ON DELETE SET NULL,
              course_enrollment_id INTEGER REFERENCES course_enrollments(id) ON DELETE SET NULL,
              is_anonymous INTEGER NOT NULL DEFAULT 0,
              created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_sfeedback_student ON student_feedback(student_id);
            CREATE INDEX IF NOT EXISTS idx_sfeedback_created ON student_feedback(created_at);

            CREATE TABLE IF NOT EXISTS surveys (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              title TEXT NOT NULL,
              description TEXT,
              questions_json TEXT NOT NULL DEFAULT '[]',
              status TEXT NOT NULL DEFAULT 'draft',
              target_track TEXT,
              created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              closes_at TEXT,
              created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS survey_responses (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              survey_id INTEGER NOT NULL REFERENCES surveys(id) ON DELETE CASCADE,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              answers_json TEXT NOT NULL DEFAULT '{}',
              submitted_at TEXT NOT NULL DEFAULT (datetime('now')),
              UNIQUE(survey_id, student_id)
            );

            CREATE TABLE IF NOT EXISTS support_tickets (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              reference TEXT NOT NULL UNIQUE,
              category TEXT NOT NULL DEFAULT 'general',
              subject TEXT NOT NULL,
              body TEXT NOT NULL,
              status TEXT NOT NULL DEFAULT 'open',
              priority TEXT NOT NULL DEFAULT 'normal',
              assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
              resolved_at TEXT,
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_tickets_student ON support_tickets(student_id);
            CREATE INDEX IF NOT EXISTS idx_tickets_status ON support_tickets(status);

            CREATE TABLE IF NOT EXISTS ticket_messages (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              ticket_id INTEGER NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
              author_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              body TEXT NOT NULL,
              is_internal INTEGER NOT NULL DEFAULT 0,
              created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_ticket_msgs_ticket ON ticket_messages(ticket_id);
        ",

        // v43 — Lesson record system + shareable parent progress reports.
        // Lesson notes/topics/outcomes land on class_sessions (the existing
        // session row is already per-cohort, so instructor writes one record
        // per session). progress_report_tokens lets admin generate a
        // signed-but-public link for a parent to monitor a specific student's
        // progress without needing a login.
        43 => "
            ALTER TABLE class_sessions ADD COLUMN lesson_notes TEXT;
            ALTER TABLE class_sessions ADD COLUMN topics TEXT;
            ALTER TABLE class_sessions ADD COLUMN learning_outcomes TEXT;

            CREATE TABLE IF NOT EXISTS progress_report_tokens (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              token TEXT NOT NULL UNIQUE,
              label TEXT,
              scope TEXT NOT NULL DEFAULT 'all',
              expires_at TEXT,
              created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              accessed_count INTEGER NOT NULL DEFAULT 0,
              last_accessed_at TEXT,
              created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_prt_token ON progress_report_tokens(token);
            CREATE INDEX IF NOT EXISTS idx_prt_student ON progress_report_tokens(student_id);
        ",

        // v44 — Student enrollment references (parent portal access).
        // Each student receives a stable, human-readable enrollment ID in the
        // format MAM/YY/NNN (standard) or JE/MAM/YY/NNN (KTAMS program).
        // Parents can look up their child's progress by this ID at /parent,
        // without a login or a one-time token.
        44 => "
            ALTER TABLE users ADD COLUMN enrollment_ref TEXT;
            CREATE UNIQUE INDEX IF NOT EXISTS idx_users_enrollment_ref ON users(enrollment_ref);
        ",

        // v45 — Enterprise scheduling: waitlist, session audit trail, substitute
        // instructor, reschedule tracking, and structured cohort schedule fields.
        // class_waitlist:  ordered queue of students for full cohorts; promotes on
        //                  vacancy (auto-enrol).
        // session_history: append-only audit log of every field change on a session
        //                  (reschedule, sub-instructor assign, cancellation reason).
        // New class_sessions columns: substitute_id, rescheduled_from, cancellation_reason, room.
        // New cohorts columns: schedule_days, schedule_time, session_duration (structured
        //                      meeting pattern — replaces free-text meeting_pattern).
        45 => "
            CREATE TABLE IF NOT EXISTS class_waitlist (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              cohort_id INTEGER NOT NULL REFERENCES cohorts(id) ON DELETE CASCADE,
              position INTEGER NOT NULL,
              status TEXT NOT NULL DEFAULT 'waiting',
              notified_at TEXT,
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              updated_at TEXT NOT NULL DEFAULT (datetime('now')),
              UNIQUE(student_id, cohort_id)
            );
            CREATE INDEX IF NOT EXISTS idx_waitlist_cohort ON class_waitlist(cohort_id, position);
            CREATE INDEX IF NOT EXISTS idx_waitlist_student ON class_waitlist(student_id);

            CREATE TABLE IF NOT EXISTS session_history (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              session_id INTEGER NOT NULL REFERENCES class_sessions(id) ON DELETE CASCADE,
              field_changed TEXT NOT NULL,
              old_value TEXT,
              new_value TEXT,
              reason TEXT,
              changed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              changed_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_session_history_session ON session_history(session_id);

            ALTER TABLE class_sessions ADD COLUMN substitute_id INTEGER REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE class_sessions ADD COLUMN rescheduled_from INTEGER REFERENCES class_sessions(id) ON DELETE SET NULL;
            ALTER TABLE class_sessions ADD COLUMN cancellation_reason TEXT;
            ALTER TABLE class_sessions ADD COLUMN room TEXT;

            ALTER TABLE cohorts ADD COLUMN schedule_days TEXT;
            ALTER TABLE cohorts ADD COLUMN schedule_time TEXT;
            ALTER TABLE cohorts ADD COLUMN session_duration INTEGER DEFAULT 60;
        ",

        // v46 — Post-session progress reports + instructor fine system + form builder AI.
        // progress_reports: instructor-submitted per-student report after each held session.
        //   - email_sent flag prevents duplicate sends; deadline enforced by a 2-hr job.
        // instructor_fines: records a financial penalty when no report submitted in time.
        // surveys.kind: distinguishes general student surveys from progress report templates.
        46 => "
            ALTER TABLE surveys ADD COLUMN kind TEXT NOT NULL DEFAULT 'survey';

            CREATE TABLE IF NOT EXISTS progress_reports (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              session_id    INTEGER NOT NULL REFERENCES class_sessions(id) ON DELETE CASCADE,
              student_id    INTEGER NOT NULL REFERENCES users(id)          ON DELETE CASCADE,
              instructor_id INTEGER NOT NULL REFERENCES users(id)          ON DELETE CASCADE,
              form_id       INTEGER REFERENCES surveys(id) ON DELETE SET NULL,
              answers_json  TEXT NOT NULL DEFAULT '{}',
              narrative     TEXT,
              email_sent    INTEGER NOT NULL DEFAULT 0,
              email_sent_at TEXT,
              submitted_at  TEXT NOT NULL DEFAULT (datetime('now')),
              UNIQUE(session_id, student_id)
            );
            CREATE INDEX IF NOT EXISTS idx_pr_session    ON progress_reports(session_id);
            CREATE INDEX IF NOT EXISTS idx_pr_student    ON progress_reports(student_id);
            CREATE INDEX IF NOT EXISTS idx_pr_instructor ON progress_reports(instructor_id);

            CREATE TABLE IF NOT EXISTS instructor_fines (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              instructor_id INTEGER NOT NULL REFERENCES users(id)          ON DELETE CASCADE,
              session_id    INTEGER REFERENCES class_sessions(id)          ON DELETE SET NULL,
              reason        TEXT NOT NULL DEFAULT 'late_progress_report',
              amount_kobo   INTEGER NOT NULL DEFAULT 0,
              status        TEXT NOT NULL DEFAULT 'pending',
              created_at    TEXT NOT NULL DEFAULT (datetime('now')),
              resolved_at   TEXT,
              resolved_by   INTEGER REFERENCES users(id) ON DELETE SET NULL
            );
            CREATE INDEX IF NOT EXISTS idx_fines_instructor ON instructor_fines(instructor_id);
        ",

        // v47 — Fines resolution note: lets admin record why a fine was waived or resolved.
        47 => "
            ALTER TABLE instructor_fines ADD COLUMN resolution_note TEXT;
        ",
        // v48 — No-op on SQLite (it already built v42/v46 objects correctly).
        // Its purpose is to bump MAM_SCHEMA_TARGET so a MySQL install (which
        // migrates by re-exec'ing schema-mysql.sql when behind) re-applies the
        // file after the MySQL-8 DDL fixes in v9/v46 (TEXT literal DEFAULT and
        // `ADD COLUMN IF NOT EXISTS`, which had silently failed there and left
        // store_items / progress_reports / surveys.kind missing while the
        // version still read 47). Re-exec is idempotent, so it self-heals prod.
        48 => "SELECT 1;",

        // v49 — No-op on SQLite (level/expression/extra_courses/courses_label on
        // applications were already added by earlier SQLite migrations). This bump
        // triggers a re-exec of schema-mysql.sql on MySQL installs that are behind,
        // which adds the four ALTER TABLE statements that add those columns — they
        // were missing from MySQL, causing every application submission to 500.
        49 => "SELECT 1;",

        // v50 — No-op on SQLite. On MySQL, re-execs schema-mysql.sql which now
        // includes a v50 block with plain ADD COLUMN (no IF NOT EXISTS) for the
        // application columns plus class_sessions/cohorts/announcements/class_resources
        // columns. Fixes any MySQL 8 installs where v49's IF NOT EXISTS syntax
        // raised error 1064 and left columns absent while advancing schema_version.
        50 => "SELECT 1;",

        // v51 — idempotency_cache table (api/lib/idempotency.php).
        // Keyed by (scope, idem_key); entries expire after 24 h.
        51 => "CREATE TABLE IF NOT EXISTS idempotency_cache (
          idem_key      TEXT    NOT NULL,
          scope         TEXT    NOT NULL,
          response_json TEXT    NOT NULL,
          created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
          PRIMARY KEY (idem_key, scope)
        );",

        // v52 — summer_applications: separate table for the Summer Programme.
        // References use SUM- prefix to distinguish from regular MAM- applications.
        // No customer_id FK (summer applicants are not necessarily enrolled students).
        52 => "
            CREATE TABLE IF NOT EXISTS summer_applications (
              id                 INTEGER PRIMARY KEY AUTOINCREMENT,
              reference          TEXT    NOT NULL UNIQUE,
              first_name         TEXT    NOT NULL,
              last_name          TEXT    NOT NULL,
              email              TEXT    NOT NULL,
              phone              TEXT,
              dob                TEXT    NOT NULL,
              age_at_submission  INTEGER,
              sex                TEXT,
              country            TEXT,
              city               TEXT,
              guardian_name      TEXT,
              guardian_phone     TEXT,
              guardian_email     TEXT,
              discipline         TEXT,
              experience         TEXT,
              goals              TEXT,
              referrer           TEXT,
              policy_agreed      INTEGER NOT NULL DEFAULT 0,
              policy_signed_name TEXT,
              policy_signed_at   TEXT,
              session_year       INTEGER,
              fee_snapshot_usd   REAL,
              status             TEXT    NOT NULL DEFAULT 'submitted',
              notes              TEXT,
              source             TEXT,
              ip                 TEXT,
              user_agent         TEXT,
              created_at         TEXT    NOT NULL DEFAULT (datetime('now')),
              updated_at         TEXT    NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_summer_apps_email   ON summer_applications(email);
            CREATE INDEX IF NOT EXISTS idx_summer_apps_status  ON summer_applications(status, id);
            CREATE INDEX IF NOT EXISTS idx_summer_apps_created ON summer_applications(created_at);
        ",
        53 => "ALTER TABLE summer_applications ADD COLUMN headshot_url TEXT",
        54 => "ALTER TABLE classes ADD COLUMN meeting_link TEXT",

        // v55: promo_banners — admin-configurable promotional email banner with
        // optional date-range gating. Multiple banners can exist; only one is live
        // at a time (most recently started active banner within the window wins).
        55 => "
            CREATE TABLE IF NOT EXISTS promo_banners (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              title      TEXT NOT NULL,
              body       TEXT NOT NULL DEFAULT '',
              cta_label  TEXT NOT NULL DEFAULT '',
              cta_url    TEXT NOT NULL DEFAULT '',
              bg_color   TEXT NOT NULL DEFAULT '#FEF3C7',
              active     INTEGER NOT NULL DEFAULT 1,
              starts_at  TEXT,
              ends_at    TEXT,
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_promo_banners_active
              ON promo_banners(active, starts_at, ends_at);
        ",

        // v56: id_cards + card_scans — student/instructor ID cards with HMAC-signed
        // QR codes. card_scans logs every scan attempt (valid or not) for audit.
        56 => "
            CREATE TABLE IF NOT EXISTS id_cards (
              id             INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id        INTEGER REFERENCES users(id) ON DELETE CASCADE,
              summer_app_id  INTEGER REFERENCES summer_applications(id) ON DELETE CASCADE,
              role           TEXT NOT NULL DEFAULT 'student',
              serial         TEXT NOT NULL UNIQUE,
              version        INTEGER NOT NULL DEFAULT 1,
              status         TEXT NOT NULL DEFAULT 'active',
              session_code   TEXT NOT NULL DEFAULT '',
              photo_url      TEXT,
              issued_at      TEXT NOT NULL,
              printed_at     TEXT,
              revoked_at     TEXT,
              revoke_reason  TEXT,
              created_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at     TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_id_cards_user
              ON id_cards(user_id, status);
            CREATE INDEX IF NOT EXISTS idx_id_cards_summer
              ON id_cards(summer_app_id, status);
            CREATE INDEX IF NOT EXISTS idx_id_cards_serial
              ON id_cards(serial);

            CREATE TABLE IF NOT EXISTS card_scans (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              card_id     INTEGER REFERENCES id_cards(id) ON DELETE SET NULL,
              raw_serial  TEXT NOT NULL,
              session_id  INTEGER,
              class_id    INTEGER,
              scanned_by  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              result      TEXT NOT NULL,
              scanned_at  TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_card_scans_card
              ON card_scans(card_id);
            CREATE INDEX IF NOT EXISTS idx_card_scans_session
              ON card_scans(session_id);
        ",

        57 => "ALTER TABLE class_sessions ADD COLUMN meeting_link TEXT;",

        // v58: public-site promo mode for promo_banners — a time-limited discount
        // (banner + server-enforced % off tuition/store checkout). Reuses the
        // existing active/starts_at/ends_at window; `public` opts a banner into
        // the public site, `discount_percent` + `applies_to` drive the discount.
        58 => "
            ALTER TABLE promo_banners ADD COLUMN public INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE promo_banners ADD COLUMN discount_percent INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE promo_banners ADD COLUMN applies_to TEXT;
        ",
        // v59 — ID cards get a REAL expiry date.
        // The printed 'EXP.12/YY' was derived from the issue year and enforced
        // nowhere: an expired card kept scanning into attendance forever. Summer
        // cards in particular are meant to lapse a couple of months after the camp.
        // expires_at is computed at mint time from the admin's validity period
        // (card_validity_months / summer_card_validity_months) and checked on scan.
        // Backfill: existing rows get a date derived from their own issue date —
        // summer cards (summer_app_id set) 2 months, everything else 12 — so no card
        // is left NULL and treated as never-expiring.
        59 => "
            ALTER TABLE id_cards ADD COLUMN expires_at TEXT;
            UPDATE id_cards
               SET expires_at = datetime(issued_at, '+2 months')
             WHERE expires_at IS NULL AND summer_app_id IS NOT NULL;
            UPDATE id_cards
               SET expires_at = datetime(issued_at, '+12 months')
             WHERE expires_at IS NULL;
            CREATE INDEX IF NOT EXISTS idx_id_cards_expires ON id_cards(status, expires_at);
        ",
        /* Legacy attendance had no unique constraint, so the check-then-insert in
         * card-scan / mark-attendance / self-checkin could race into TWO 'present'
         * rows for one (enrolment, day) — inflating every attendance rate that
         * counts them. Dedup (keep the earliest row per key) then enforce the
         * uniqueness the code always assumed. */
        60 => "
            DELETE FROM attendance WHERE id NOT IN (
                SELECT MIN(id) FROM attendance GROUP BY enrollment_id, session_date
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_attendance_unique ON attendance(enrollment_id, session_date);
        ",
        /* v61 — money normalization (LMS 2.0 Part A). `payments.amount` meant
         * different things per currency (whole naira for NGN rows, dollars for
         * USD rows), so SUM(amount) across rows added naira to dollars and the
         * Revenue KPI/trend reported nonsense. Add an explicit, integer,
         * currency-safe representation alongside the legacy column (which stays
         * untouched and readable — no drop here; that is deferred to admin
         * sign-off):
         *   amount_minor     — charged amount in the row currency's minor unit
         *                      (NGN→kobo, USD→cents).
         *   amount_usd_cents — canonical USD cents, frozen at charge time; the
         *                      only cross-row-summable money figure.
         *   fx_rate_usd_ngn  — USD→NGN rate frozen at charge time.
         *   fx_backfilled    — 0 = captured live at charge time, 1 = derived
         *                      later by tools/backfill-money.php (an estimate).
         * instructor_fines (naira-denominated via amount_kobo) gets the same
         * canonical USD column + frozen rate so fines join the revenue model. */
        61 => "
            ALTER TABLE payments ADD COLUMN amount_minor INTEGER;
            ALTER TABLE payments ADD COLUMN amount_usd_cents INTEGER;
            ALTER TABLE payments ADD COLUMN fx_rate_usd_ngn REAL;
            ALTER TABLE payments ADD COLUMN fx_backfilled INTEGER NOT NULL DEFAULT 0;
            CREATE INDEX IF NOT EXISTS idx_pay_usd ON payments(status, amount_usd_cents);
            ALTER TABLE instructor_fines ADD COLUMN currency TEXT NOT NULL DEFAULT 'NGN';
            ALTER TABLE instructor_fines ADD COLUMN amount_usd_cents INTEGER;
            ALTER TABLE instructor_fines ADD COLUMN fx_rate_usd_ngn REAL;
        ",
        /* v62 — paid class rescheduling (LMS 2.0 Part C). A student requests to
         * move one or more of their cohort's sessions; the server prices the
         * request (free allowance → per-session/per-request fee), settles it via
         * wallet or provider, an instructor/admin approves, and the move is
         * applied through the existing session_history-audited reschedule writer.
         * Money is stored on the Part A model (canonical USD cents + frozen
         * rate). Everything is gated behind the reschedule_enabled setting
         * (default off), so this migration is inert until an admin turns it on. */
        62 => "
            CREATE TABLE reschedule_requests (
              id               INTEGER PRIMARY KEY AUTOINCREMENT,
              requester_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              cohort_id        INTEGER NOT NULL REFERENCES cohorts(id) ON DELETE CASCADE,
              status           TEXT NOT NULL DEFAULT 'pending_payment',
              reason           TEXT,
              session_count    INTEGER NOT NULL DEFAULT 0,
              fee_usd_cents    INTEGER NOT NULL DEFAULT 0,
              fee_currency     TEXT NOT NULL DEFAULT 'USD',
              fx_rate_usd_ngn  REAL,
              waived           INTEGER NOT NULL DEFAULT 0,
              waived_reason    TEXT,
              payment_ref      TEXT,
              payment_provider TEXT,
              paid_at          TEXT,
              decided_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
              decided_at       TEXT,
              decision_note    TEXT,
              applied_at       TEXT,
              expires_at       TEXT,
              created_at       TEXT NOT NULL DEFAULT (datetime('now')),
              updated_at       TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_resched_cohort  ON reschedule_requests(cohort_id, status);
            CREATE INDEX idx_resched_req      ON reschedule_requests(requester_id, status);
            CREATE INDEX idx_resched_expiry   ON reschedule_requests(status, expires_at);

            CREATE TABLE reschedule_items (
              id               INTEGER PRIMARY KEY AUTOINCREMENT,
              request_id       INTEGER NOT NULL REFERENCES reschedule_requests(id) ON DELETE CASCADE,
              session_id       INTEGER NOT NULL REFERENCES class_sessions(id) ON DELETE CASCADE,
              old_scheduled_at TEXT,
              new_scheduled_at TEXT NOT NULL,
              applied          INTEGER NOT NULL DEFAULT 0,
              created_at       TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_resched_item_req     ON reschedule_items(request_id);
            CREATE INDEX idx_resched_item_session ON reschedule_items(session_id);

            CREATE TABLE reschedule_events (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              request_id  INTEGER NOT NULL REFERENCES reschedule_requests(id) ON DELETE CASCADE,
              event       TEXT NOT NULL,
              actor_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
              detail      TEXT,
              created_at  TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_resched_event_req ON reschedule_events(request_id);

            INSERT OR IGNORE INTO settings (skey, svalue) VALUES
              ('reschedule_enabled','0'),
              ('reschedule_fee_usd','10'),
              ('reschedule_fee_mode','per_session'),
              ('reschedule_free_per_term','1'),
              ('reschedule_max_per_term','3'),
              ('reschedule_window_hours','24'),
              ('reschedule_payment_expiry_hours','24'),
              ('reschedule_approval_required','1');
        ",
        /* v63 — LMS 2.0 teaching tables (Part B), all additive/inert until used:
         *   assignment_groups + assignments.group_id — a group is the parent an
         *     author fans out into the existing per-student assignment rows.
         *   submission_files — many files per submission (submissions has a
         *     single artifact_url today).
         *   resource_versions — edit history for class_resources.
         *   lessons + lesson_progress — lesson units and per-student completion
         *     (unique per lesson+student). */
        63 => "
            CREATE TABLE assignment_groups (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              class_id     INTEGER REFERENCES classes(id) ON DELETE SET NULL,
              cohort_id    INTEGER REFERENCES cohorts(id) ON DELETE SET NULL,
              author_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
              rubric_id    INTEGER,
              title        TEXT NOT NULL,
              instructions TEXT,
              due_on       TEXT,
              created_at   TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_agroups_class  ON assignment_groups(class_id);
            CREATE INDEX idx_agroups_cohort ON assignment_groups(cohort_id);
            ALTER TABLE assignments ADD COLUMN group_id INTEGER REFERENCES assignment_groups(id) ON DELETE SET NULL;
            CREATE INDEX idx_assignments_group ON assignments(group_id);

            CREATE TABLE submission_files (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              submission_id INTEGER NOT NULL REFERENCES submissions(id) ON DELETE CASCADE,
              url           TEXT NOT NULL,
              kind          TEXT NOT NULL DEFAULT 'link',
              filename      TEXT,
              size_bytes    INTEGER,
              created_at    TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_subfiles_submission ON submission_files(submission_id);

            CREATE TABLE resource_versions (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              resource_id INTEGER NOT NULL REFERENCES class_resources(id) ON DELETE CASCADE,
              version     INTEGER NOT NULL DEFAULT 1,
              title       TEXT,
              url         TEXT NOT NULL,
              note        TEXT,
              created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at  TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_resver_resource ON resource_versions(resource_id, version);

            CREATE TABLE lessons (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              cohort_id   INTEGER REFERENCES cohorts(id) ON DELETE CASCADE,
              module_id   INTEGER,
              title       TEXT NOT NULL,
              body        TEXT,
              url         TEXT,
              sort_order  INTEGER NOT NULL DEFAULT 0,
              published   INTEGER NOT NULL DEFAULT 1,
              created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at  TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_lessons_cohort ON lessons(cohort_id, sort_order);

            CREATE TABLE lesson_progress (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              lesson_id    INTEGER NOT NULL REFERENCES lessons(id) ON DELETE CASCADE,
              student_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              status       TEXT NOT NULL DEFAULT 'in_progress',
              percent      INTEGER NOT NULL DEFAULT 0,
              completed_at TEXT,
              updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX idx_lesson_progress_unique ON lesson_progress(lesson_id, student_id);
        ",
        /* v64 — gradebook v2 (Part B): reusable rubrics scored per submission.
         *   rubrics + rubric_criteria (weighted, capped) and submission_reviews
         *   (a reviewer's per-criterion scores + computed total). Additive to the
         *   existing feedback flow. */
        64 => "
            CREATE TABLE rubrics (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              title      TEXT NOT NULL,
              cohort_id  INTEGER REFERENCES cohorts(id) ON DELETE SET NULL,
              created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              active     INTEGER NOT NULL DEFAULT 1,
              created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_rubrics_cohort ON rubrics(cohort_id, active);

            CREATE TABLE rubric_criteria (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              rubric_id  INTEGER NOT NULL REFERENCES rubrics(id) ON DELETE CASCADE,
              label      TEXT NOT NULL,
              max_points INTEGER NOT NULL DEFAULT 10,
              weight     REAL NOT NULL DEFAULT 1.0,
              sort_order INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX idx_rcriteria_rubric ON rubric_criteria(rubric_id, sort_order);

            CREATE TABLE submission_reviews (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              submission_id INTEGER NOT NULL REFERENCES submissions(id) ON DELETE CASCADE,
              rubric_id     INTEGER REFERENCES rubrics(id) ON DELETE SET NULL,
              reviewer_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              scores_json   TEXT,
              total_points  REAL,
              max_points    REAL,
              feedback      TEXT,
              created_at    TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_sreviews_submission ON submission_reviews(submission_id);
        ",
        /* v65 — scheduling core (Part D): instructor availability windows, a
         *   normalised rooms table (class_sessions keeps its free-text `room`;
         *   room_id is the additive FK), and calendar_tokens for a signed,
         *   read-only ICS feed of a user's sessions. */
        65 => "
            CREATE TABLE instructor_availability (
              id             INTEGER PRIMARY KEY AUTOINCREMENT,
              instructor_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              day_of_week    INTEGER NOT NULL,
              start_time     TEXT NOT NULL,
              end_time       TEXT NOT NULL,
              effective_from TEXT,
              effective_to   TEXT,
              active         INTEGER NOT NULL DEFAULT 1,
              created_at     TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_availability_instr ON instructor_availability(instructor_id, day_of_week, active);

            CREATE TABLE rooms (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              name       TEXT NOT NULL,
              capacity   INTEGER,
              location   TEXT,
              active     INTEGER NOT NULL DEFAULT 1,
              created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            ALTER TABLE class_sessions ADD COLUMN room_id INTEGER REFERENCES rooms(id) ON DELETE SET NULL;
            CREATE INDEX idx_class_sessions_room ON class_sessions(room_id);

            CREATE TABLE calendar_tokens (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              token        TEXT NOT NULL UNIQUE,
              scope        TEXT NOT NULL DEFAULT 'sessions',
              active       INTEGER NOT NULL DEFAULT 1,
              last_used_at TEXT,
              created_at   TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_caltokens_user ON calendar_tokens(user_id, active);
        ",
        /* v66 — NGG-style student check-in. session_attendance gains check-in
         * provenance (when, how, and — for an optional geofence — where), so a
         * student self check-in is distinguishable from an instructor mark and
         * a late arrival is recorded as such. All additive; the existing QR
         * self-check-in and instructor marking keep working unchanged. Settings
         * seed the self-check-in window, late grace, and (off by default)
         * geofence gate. */
        66 => "
            ALTER TABLE session_attendance ADD COLUMN checked_in_at TEXT;
            ALTER TABLE session_attendance ADD COLUMN method TEXT NOT NULL DEFAULT 'instructor';
            ALTER TABLE session_attendance ADD COLUMN checkin_lat REAL;
            ALTER TABLE session_attendance ADD COLUMN checkin_lng REAL;
            INSERT OR IGNORE INTO settings (skey, svalue) VALUES
              ('checkin_self_enabled','1'),
              ('checkin_open_before_min','30'),
              ('checkin_late_grace_min','10'),
              ('checkin_geofence_enabled','0'),
              ('checkin_geofence_lat',''),
              ('checkin_geofence_lng',''),
              ('checkin_geofence_radius_m','200'),
              ('program_total_weeks','12');
        ",
        /* v67 — accountability ledger (PDF §13-15, §37). Records punctuality
         * charges (student late arrival, instructor lateness, left-early) as an
         * auditable ledger on the Part A money model (canonical USD cents +
         * frozen rate), with a waiver/exception decision path. It is a RECORD
         * only — no wallet is auto-debited — and the auto-charge on late
         * check-in is gated behind late_charge_enabled (off by default), so
         * turning it on is a deliberate, policy-backed choice. */
        67 => "
            CREATE TABLE accountability_charges (
              id              INTEGER PRIMARY KEY AUTOINCREMENT,
              subject_type    TEXT NOT NULL DEFAULT 'student',
              subject_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              session_id      INTEGER REFERENCES class_sessions(id) ON DELETE SET NULL,
              reason          TEXT NOT NULL DEFAULT 'late_arrival',
              minutes         INTEGER NOT NULL DEFAULT 0,
              amount_usd_cents INTEGER NOT NULL DEFAULT 0,
              currency        TEXT NOT NULL DEFAULT 'USD',
              fx_rate_usd_ngn REAL,
              status          TEXT NOT NULL DEFAULT 'outstanding',
              note            TEXT,
              resolved_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
              resolved_at     TEXT,
              resolution_note TEXT,
              created_at      TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_acct_subject ON accountability_charges(subject_type, subject_id, status);
            CREATE INDEX idx_acct_session ON accountability_charges(session_id);
            INSERT OR IGNORE INTO settings (skey, svalue) VALUES
              ('late_charge_enabled','0'),
              ('late_charge_student_usd','1'),
              ('late_charge_instructor_usd','2');
        ",
        /* v68 — pre-class learning + teach-back (PDF §8-9, 18-19). The existing
         * `lessons` table becomes the week-module content unit (objectives,
         * prep instructions, resources, whether a teach-back is required), a
         * class_session can point at the module it delivers, and `teachbacks`
         * records the student's DECLARE step (evidence by link OR uploaded
         * media) plus the instructor's readiness decision. Preparation status
         * reuses lesson_progress (in_progress/completed = the 🟡/🟢 light). */
        68 => "
            ALTER TABLE lessons ADD COLUMN week_no INTEGER;
            ALTER TABLE lessons ADD COLUMN objectives TEXT;
            ALTER TABLE lessons ADD COLUMN prep_instructions TEXT;
            ALTER TABLE lessons ADD COLUMN resources_json TEXT;
            ALTER TABLE lessons ADD COLUMN teachback_required INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE class_sessions ADD COLUMN lesson_id INTEGER REFERENCES lessons(id) ON DELETE SET NULL;
            CREATE INDEX IF NOT EXISTS idx_class_sessions_lesson ON class_sessions(lesson_id);

            CREATE TABLE teachbacks (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              lesson_id     INTEGER NOT NULL REFERENCES lessons(id) ON DELETE CASCADE,
              student_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              cohort_id     INTEGER REFERENCES cohorts(id) ON DELETE SET NULL,
              kind          TEXT NOT NULL DEFAULT 'written',
              artifact_url  TEXT,
              body          TEXT,
              status        TEXT NOT NULL DEFAULT 'submitted',
              reviewer_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              decision      TEXT,
              understanding INTEGER,
              feedback      TEXT,
              created_at    TEXT NOT NULL DEFAULT (datetime('now')),
              reviewed_at   TEXT
            );
            CREATE UNIQUE INDEX idx_teachback_unique ON teachbacks(lesson_id, student_id);
            CREATE INDEX idx_teachback_queue ON teachbacks(status);
        ",
        /* v69 — module status + carry-forward (PDF §6-7, 29-30). Distinguishes
         * CURRICULUM (what) from SCHEDULE (when planned) from MASTERY (when
         * achieved): lesson_progress carries the scheduled/started/completed
         * week, a mastery %, attempts and a new due date, and its status uses
         * the §7 vocabulary (free text — no constraint change). A carry-forward
         * is an APPENDED event (module_carryforward) so no academic history is
         * ever deleted (§29). */
        69 => "
            ALTER TABLE lesson_progress ADD COLUMN mastery_pct REAL;
            ALTER TABLE lesson_progress ADD COLUMN week_scheduled INTEGER;
            ALTER TABLE lesson_progress ADD COLUMN week_started INTEGER;
            ALTER TABLE lesson_progress ADD COLUMN week_completed INTEGER;
            ALTER TABLE lesson_progress ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE lesson_progress ADD COLUMN due_at TEXT;

            CREATE TABLE module_carryforward (
              id                INTEGER PRIMARY KEY AUTOINCREMENT,
              lesson_id         INTEGER NOT NULL REFERENCES lessons(id) ON DELETE CASCADE,
              student_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              from_week         INTEGER,
              to_week           INTEGER,
              to_date           TEXT,
              reason            TEXT,
              previous_status   TEXT,
              previous_mastery  REAL,
              created_by        INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at        TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_carryfwd_student ON module_carryforward(student_id, lesson_id);
        ",
        /* v70 — instructor clock-in + online reconciliation (PDF §10-11, 14).
         * instructor_clockins records staff punctuality (a late clock-in feeds
         * the accountability ledger's $2). meeting_reconciliation reconciles the
         * portal clock-in against a video provider's join/leave (Google Meet CSV
         * import, or Jitsi) — the MAM clock-in stays the official record where
         * provider data can't be fetched (§11). class_sessions gains the
         * meeting provider. */
        70 => "
            CREATE TABLE instructor_clockins (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              session_id    INTEGER NOT NULL REFERENCES class_sessions(id) ON DELETE CASCADE,
              instructor_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              clock_in_at   TEXT,
              clock_out_at  TEXT,
              status        TEXT NOT NULL DEFAULT 'present',
              minutes_late  INTEGER NOT NULL DEFAULT 0,
              source        TEXT NOT NULL DEFAULT 'portal',
              created_at    TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX idx_clockin_unique ON instructor_clockins(session_id, instructor_id);

            CREATE TABLE meeting_reconciliation (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              session_id   INTEGER NOT NULL REFERENCES class_sessions(id) ON DELETE CASCADE,
              subject_type TEXT NOT NULL DEFAULT 'student',
              subject_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              display_name TEXT,
              join_at      TEXT,
              leave_at     TEXT,
              minutes      INTEGER,
              provider     TEXT NOT NULL DEFAULT 'meet',
              source       TEXT NOT NULL DEFAULT 'import',
              created_at   TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_meetrecon_session ON meeting_reconciliation(session_id);

            ALTER TABLE class_sessions ADD COLUMN meeting_provider TEXT;
            INSERT OR IGNORE INTO settings (skey, svalue) VALUES
              ('instructor_clockin_grace_min','5'),
              ('jitsi_domain','meet.jit.si'),
              ('meeting_default_provider','none');
        ",
        /* v71 — real parent accounts (PDF §26). A parent is a user with the
         * 'parent' role; parent_links is the many-to-many guardian↔student map.
         * users.role and user_roles.role carried a CHECK limited to
         * admin/instructor/student, so this widens both to add 'parent' (and
         * 'staff' for future use). A CHECK can only change via table rebuild;
         * `users` is referenced widely, so the rebuild runs @no-tx with
         * foreign_keys OFF (otherwise DROP TABLE would cascade-delete children).
         * The exact column set is copied verbatim so no data shifts. */
        71 => "/* @no-tx */
            PRAGMA foreign_keys=OFF;

            CREATE TABLE users_new (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              email TEXT UNIQUE NOT NULL,
              password_hash TEXT NOT NULL,
              role TEXT NOT NULL CHECK(role IN ('admin','instructor','student','parent','staff')),
              name TEXT,
              phone TEXT,
              avatar_url TEXT,
              status TEXT DEFAULT 'active',
              customer_id INTEGER REFERENCES customers(id) ON DELETE SET NULL,
              last_login TEXT,
              must_change_password INTEGER DEFAULT 0,
              created_at TEXT DEFAULT (datetime('now')),
              updated_at TEXT DEFAULT (datetime('now')),
              admin_tier TEXT,
              student_type TEXT,
              enrollment_ref TEXT
            );
            INSERT INTO users_new (id,email,password_hash,role,name,phone,avatar_url,status,customer_id,last_login,must_change_password,created_at,updated_at,admin_tier,student_type,enrollment_ref)
              SELECT id,email,password_hash,role,name,phone,avatar_url,status,customer_id,last_login,must_change_password,created_at,updated_at,admin_tier,student_type,enrollment_ref FROM users;
            DROP TABLE users;
            ALTER TABLE users_new RENAME TO users;
            CREATE INDEX idx_users_role ON users(role);
            CREATE INDEX idx_users_customer ON users(customer_id);
            CREATE UNIQUE INDEX idx_users_enrollment_ref ON users(enrollment_ref);

            CREATE TABLE user_roles_new (
              user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              role TEXT NOT NULL CHECK(role IN ('admin','instructor','student','parent','staff')),
              PRIMARY KEY (user_id, role)
            );
            INSERT INTO user_roles_new (user_id, role) SELECT user_id, role FROM user_roles;
            DROP TABLE user_roles;
            ALTER TABLE user_roles_new RENAME TO user_roles;

            CREATE TABLE parent_links (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              parent_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              student_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              relationship TEXT NOT NULL DEFAULT 'guardian',
              created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at   TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX idx_parent_link_unique ON parent_links(parent_id, student_id);
            CREATE INDEX idx_parent_link_parent  ON parent_links(parent_id);
            CREATE INDEX idx_parent_link_student ON parent_links(student_id);

            PRAGMA foreign_keys=ON;
        ",
        // v72 — teach-back multi-dimension assessment (§19): the instructor scores
        // six dimensions, not just Understanding. Additive nullable 0–5 columns.
        72 => "
            ALTER TABLE teachbacks ADD COLUMN accuracy    INTEGER;
            ALTER TABLE teachbacks ADD COLUMN vocabulary  INTEGER;
            ALTER TABLE teachbacks ADD COLUMN explanation INTEGER;
            ALTER TABLE teachbacks ADD COLUMN application INTEGER;
            ALTER TABLE teachbacks ADD COLUMN confidence  INTEGER;
        ",
        // v73 — timestamped audio/video feedback (Advanced Modernization §1.1):
        // markers pin a comment to a moment in a submitted recording.
        73 => "
            CREATE TABLE submission_markers (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              submission_id INTEGER NOT NULL REFERENCES submissions(id) ON DELETE CASCADE,
              author_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
              at_seconds    REAL NOT NULL DEFAULT 0,
              comment       TEXT NOT NULL,
              created_at    TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_submarkers_sub ON submission_markers(submission_id, at_seconds);
        ",
        // v74 — achievements (Advanced Modernization §3.1): warm, specific badges
        // tied to real progress, awarded once per (student, code).
        74 => "
            CREATE TABLE achievements (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              code       TEXT NOT NULL,
              title      TEXT NOT NULL,
              awarded_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX idx_ach_unique ON achievements(student_id, code);
        ",
        // v75 — consent & safeguarding (Advanced Modernization §8.4): one row per
        // student holding media/photo/data consent + a safeguarding contact,
        // default-deny, enforced across the product via mam_consent_allows().
        75 => "
            CREATE TABLE student_consents (
              student_id           INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
              media_consent        INTEGER NOT NULL DEFAULT 0,
              photo_consent        INTEGER NOT NULL DEFAULT 0,
              data_consent         INTEGER NOT NULL DEFAULT 0,
              safeguarding_contact TEXT,
              updated_by           INTEGER REFERENCES users(id) ON DELETE SET NULL,
              updated_at           TEXT NOT NULL DEFAULT (datetime('now'))
            );
        ",
        // v76 — repertoire → recital (Advanced Modernization §1.7): a living list
        // of pieces (learning/polished/performed) that assembles into a recital
        // programme. §1.3: assignments can carry a reference recording.
        76 => "
            CREATE TABLE repertoire (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              title      TEXT NOT NULL,
              composer   TEXT,
              status     TEXT NOT NULL DEFAULT 'learning',
              notes      TEXT,
              sort_order INTEGER NOT NULL DEFAULT 0,
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_rep_student ON repertoire(student_id, status);
            ALTER TABLE assignments ADD COLUMN reference_url TEXT;
            ALTER TABLE assignments ADD COLUMN reference_kind TEXT;
        ",
        // v77 — practice challenges (Advanced Modernization §3.3): a time-boxed
        // personal goal (N sessions or N minutes in a window), progress derived
        // from practice_logs.
        77 => "
            CREATE TABLE practice_challenges (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              title      TEXT NOT NULL,
              metric     TEXT NOT NULL DEFAULT 'sessions',
              target     INTEGER NOT NULL DEFAULT 1,
              starts_on  TEXT NOT NULL,
              ends_on    TEXT NOT NULL,
              created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_challenge_student ON practice_challenges(student_id, ends_on);
        ",
        // v78 — referral program (Advanced Modernization §6.3): a trackable code
        // per user + referral records that reward the referrer's wallet.
        78 => "
            CREATE TABLE referral_codes (
              user_id    INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
              code       TEXT NOT NULL UNIQUE,
              created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE referrals (
              id               INTEGER PRIMARY KEY AUTOINCREMENT,
              referrer_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              code             TEXT NOT NULL,
              referred_name    TEXT,
              referred_email   TEXT,
              referred_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
              status           TEXT NOT NULL DEFAULT 'pending',
              reward_cents     INTEGER NOT NULL DEFAULT 0,
              created_at       TEXT NOT NULL DEFAULT (datetime('now')),
              rewarded_at      TEXT
            );
            CREATE INDEX idx_referrals_referrer ON referrals(referrer_id);
            CREATE INDEX idx_referrals_status ON referrals(status);
        ",
        // v79 — opt-in effort leaderboards (Advanced Modernization §3.4): a
        // per-student toggle (off by default); appearing also requires data
        // consent, so minors are guardian-consented.
        79 => "
            ALTER TABLE users ADD COLUMN leaderboard_opt_in INTEGER DEFAULT 0;
        ",
        // v80 — The Recital Hall. A recital is a real event with a curated
        // running order, not a list of names: items carry a section, a duration
        // and a sort order so the programme can compute clock times, and a
        // status of their own so a piece can be proposed, accepted, declined or
        // withdrawn without deleting the record of it having been offered.
        80 => "
            CREATE TABLE IF NOT EXISTS recitals (
              id              INTEGER PRIMARY KEY AUTOINCREMENT,
              slug            TEXT NOT NULL UNIQUE,
              title           TEXT NOT NULL,
              subtitle        TEXT,
              venue           TEXT,
              starts_at       TEXT,
              doors_open_at   TEXT,
              interval_after  INTEGER,
              interval_min    INTEGER NOT NULL DEFAULT 15,
              status          TEXT NOT NULL DEFAULT 'draft',
              submissions_close_at TEXT,
              description     TEXT,
              programme_note  TEXT,
              created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at      TEXT NOT NULL DEFAULT (datetime('now')),
              updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_recitals_status ON recitals(status, starts_at);

            CREATE TABLE IF NOT EXISTS recital_items (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              recital_id    INTEGER NOT NULL REFERENCES recitals(id) ON DELETE CASCADE,
              student_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              repertoire_id INTEGER REFERENCES repertoire(id) ON DELETE SET NULL,
              submission_id INTEGER REFERENCES submissions(id) ON DELETE SET NULL,
              title         TEXT NOT NULL,
              composer      TEXT,
              arranger      TEXT,
              movement      TEXT,
              instrument    TEXT,
              duration_min  INTEGER NOT NULL DEFAULT 4,
              section       TEXT NOT NULL DEFAULT 'main',
              sort_order    INTEGER NOT NULL DEFAULT 0,
              status        TEXT NOT NULL DEFAULT 'proposed',
              note          TEXT,
              decided_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
              decided_at    TEXT,
              created_at    TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_ritems_recital ON recital_items(recital_id, section, sort_order);
            CREATE INDEX IF NOT EXISTS idx_ritems_student ON recital_items(student_id, status);
        ",
        /* v81 — the attendance clock (PDF §10, §12, §16, §39-41). @no-tx
         *
         * session_attendance is rebuilt for two reasons that can only be done by
         * rebuild in SQLite: its CHECK admitted four statuses where §12 defines
         * SEVEN, and a clock needs an OUT as well as an IN. It is a leaf table
         * (nothing references it), so the rebuild is safe with foreign_keys OFF
         * around the DROP — which is why this runs outside the transaction.
         *
         * New columns: `mode` (§16 onsite/online/hybrid — the academic record
         * stays unified across all three), `clocked_out_at`, `duration_min` and
         * `minutes_late`. Durations are STORED, not computed on read: a session's
         * scheduled time can be edited afterwards and the record of what actually
         * happened must not move with it.
         *
         * Also: `readiness_before_min` (§9's 15-minute readiness threshold, which
         * is advisory and distinct from `checkin_open_before_min`'s 30-minute
         * hard gate), and `accountability_exceptions` — §15's request→decision
         * flow, which the ledger previously lacked entirely (an admin could waive
         * a charge, but nobody could ASK).
         */
        81 => "
            -- @no-tx
            PRAGMA foreign_keys = OFF;

            CREATE TABLE session_attendance_new (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              session_id INTEGER NOT NULL REFERENCES class_sessions(id) ON DELETE CASCADE,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              status TEXT NOT NULL CHECK(status IN ('present','absent','late','excused','left_early','technical_failure','rescheduled')),
              mode TEXT NOT NULL DEFAULT 'onsite' CHECK(mode IN ('onsite','online','hybrid')),
              notes TEXT,
              marked_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              checked_in_at TEXT,
              clocked_out_at TEXT,
              duration_min INTEGER,
              minutes_late INTEGER NOT NULL DEFAULT 0,
              method TEXT NOT NULL DEFAULT 'instructor',
              checkin_lat REAL,
              checkin_lng REAL,
              created_at TEXT DEFAULT (datetime('now')),
              updated_at TEXT DEFAULT (datetime('now'))
            );
            INSERT INTO session_attendance_new
              (id, session_id, student_id, status, notes, marked_by, checked_in_at, method, checkin_lat, checkin_lng, created_at, updated_at)
              SELECT id, session_id, student_id, status, notes, marked_by, checked_in_at, method, checkin_lat, checkin_lng, created_at, updated_at
                FROM session_attendance;
            DROP TABLE session_attendance;
            ALTER TABLE session_attendance_new RENAME TO session_attendance;
            CREATE UNIQUE INDEX idx_sess_att_unique ON session_attendance(session_id, student_id);
            CREATE INDEX idx_sess_att_student ON session_attendance(student_id);

            ALTER TABLE class_sessions ADD COLUMN mode TEXT NOT NULL DEFAULT 'onsite';

            CREATE TABLE IF NOT EXISTS accountability_exceptions (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              charge_id     INTEGER NOT NULL REFERENCES accountability_charges(id) ON DELETE CASCADE,
              requested_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
              reason_code   TEXT NOT NULL,
              detail        TEXT,
              status        TEXT NOT NULL DEFAULT 'pending',
              decided_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
              decided_at    TEXT,
              decision_note TEXT,
              created_at    TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_acct_exc_charge ON accountability_exceptions(charge_id);
            CREATE INDEX IF NOT EXISTS idx_acct_exc_status ON accountability_exceptions(status, id);

            INSERT OR IGNORE INTO settings (skey, svalue) VALUES
              ('readiness_before_min','15'),
              ('mastery_score_enabled','1');

            PRAGMA foreign_keys = ON;
        ",

        /**
         * v82 — instructor vetting records + the safeguarding gate.
         *
         * Stores STATUS, TYPE, ISSUING BODY, REFERENCE, DATES and a pointer to
         * where the evidence is filed offline. Deliberately NOT the content of any
         * check and never offence data: under the NDPA this is sensitive personal
         * data about the instructor, and a scan of a police certificate sitting in
         * a shared-hosting database is a liability rather than a control. A
         * reference number plus a filing location prove diligence.
         *
         * UNIQUE(instructor_id, clearance_type) — re-running a check REPLACES the
         * record. The gate asks "is there a current one", and a history of
         * superseded certificate numbers is exactly the sensitive data we are
         * trying not to accumulate. The audit log keeps who-recorded-what-when.
         *
         * No `status` column on purpose: validity is DERIVED from expires_on at
         * read time, so a clearance that lapses at midnight reads as expired on
         * the next page load rather than the next cron run.
         */
        82 => "
            CREATE TABLE IF NOT EXISTS vetting_records (
              id             INTEGER PRIMARY KEY AUTOINCREMENT,
              instructor_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              clearance_type TEXT    NOT NULL,
              issuing_body   TEXT,
              reference_no   TEXT,
              issued_on      TEXT    NOT NULL,
              expires_on     TEXT,
              verified_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
              verified_at    TEXT,
              evidence_held  TEXT,
              created_at     TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX IF NOT EXISTS uq_vetting_instr_type ON vetting_records(instructor_id, clearance_type);
            CREATE INDEX IF NOT EXISTS idx_vetting_expiry ON vetting_records(expires_on);
        ",

        /**
         * v83 — the academic calendar as DATA.
         *
         * academic-session.php resolves TONI/MEDI/SUMMER/DOMI from constants plus a
         * JSON settings blob. That is fine for "which term is it" and useless for
         * everything that hangs off a term: a refund percentage, the withdrawal
         * outcome, an official enrolment count. Those need DATED, enforceable rows.
         *
         * `academic_terms` carries the four dates that drive money and outcomes —
         * add/drop, withdrawal, census — with CHECK constraints that keep them in a
         * sane order, because a calendar allowing withdrawal before add/drop
         * produces refund percentages nobody can defend.
         *
         * `term_refund_schedule` is a PUBLISHED table, not a formula buried in code:
         * a family is entitled to read the percentage before they commit, and an
         * auditor is entitled to see the one that applied on the day.
         *
         * `term_census` is an append-only SNAPSHOT. "Enrolment as of the census
         * date" must never change retroactively, so it is recorded once and read
         * thereafter — never recomputed from live tables.
         *
         * Note the table is NOT called `sessions`: that name is already the login
         * session store.
         */
        83 => "
            CREATE TABLE IF NOT EXISTS academic_terms (
              id                  INTEGER PRIMARY KEY AUTOINCREMENT,
              year_label          TEXT    NOT NULL,
              code                TEXT    NOT NULL,
              term_no             INTEGER NOT NULL DEFAULT 1,
              label               TEXT    NOT NULL,
              starts_on           TEXT    NOT NULL,
              ends_on             TEXT    NOT NULL,
              add_drop_deadline   TEXT,
              withdrawal_deadline TEXT,
              census_date         TEXT,
              teaching_weeks      INTEGER NOT NULL DEFAULT 12,
              timezone            TEXT    NOT NULL DEFAULT 'Africa/Lagos',
              status              TEXT    NOT NULL DEFAULT 'planned'
                                  CHECK(status IN ('planned','active','closed')),
              notes               TEXT,
              created_at          TEXT NOT NULL DEFAULT (datetime('now')),
              -- A term must not end before it starts, and every deadline must fall
              -- inside it and in the right order. Enforced here rather than in PHP
              -- because a bad row corrupts every refund computed against it.
              CHECK(ends_on >= starts_on),
              CHECK(add_drop_deadline   IS NULL OR (add_drop_deadline   >= starts_on AND add_drop_deadline   <= ends_on)),
              CHECK(withdrawal_deadline IS NULL OR (withdrawal_deadline >= starts_on AND withdrawal_deadline <= ends_on)),
              CHECK(census_date         IS NULL OR (census_date         >= starts_on AND census_date         <= ends_on)),
              CHECK(add_drop_deadline IS NULL OR withdrawal_deadline IS NULL
                    OR withdrawal_deadline >= add_drop_deadline)
            );
            CREATE UNIQUE INDEX IF NOT EXISTS uq_term_year_code ON academic_terms(year_label, code);
            CREATE INDEX IF NOT EXISTS idx_term_dates ON academic_terms(starts_on, ends_on);

            CREATE TABLE IF NOT EXISTS term_refund_schedule (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              term_id    INTEGER NOT NULL REFERENCES academic_terms(id) ON DELETE CASCADE,
              week_from  INTEGER NOT NULL,
              week_to    INTEGER NOT NULL,
              percent    INTEGER NOT NULL,
              CHECK(week_to >= week_from),
              CHECK(percent BETWEEN 0 AND 100)
            );
            CREATE UNIQUE INDEX IF NOT EXISTS uq_refund_term_week ON term_refund_schedule(term_id, week_from);

            CREATE TABLE IF NOT EXISTS term_closures (
              id       INTEGER PRIMARY KEY AUTOINCREMENT,
              term_id  INTEGER NOT NULL REFERENCES academic_terms(id) ON DELETE CASCADE,
              kind     TEXT    NOT NULL DEFAULT 'holiday'
                       CHECK(kind IN ('holiday','break','exam','makeup')),
              label    TEXT    NOT NULL,
              from_on  TEXT    NOT NULL,
              to_on    TEXT    NOT NULL,
              CHECK(to_on >= from_on)
            );
            CREATE INDEX IF NOT EXISTS idx_closure_term ON term_closures(term_id, from_on);

            CREATE TABLE IF NOT EXISTS term_census (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              term_id       INTEGER NOT NULL REFERENCES academic_terms(id) ON DELETE CASCADE,
              taken_on      TEXT    NOT NULL,
              enrolled      INTEGER NOT NULL DEFAULT 0,
              active_cohorts INTEGER NOT NULL DEFAULT 0,
              detail_json   TEXT,
              created_at    TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX IF NOT EXISTS uq_census_term ON term_census(term_id);
        ",

        // ── v84: the NDPA 2023 / GAID 2025 compliance layer ────────────────────
        // Five distinct legal artefacts, deliberately NOT one 'compliance' table:
        // a request log with a statutory clock, a breach register with a 72-hour
        // clock, a processor/DPA register, DPIA records, and the academy's
        // annotations over the code-side processing + retention registries.
        //
        // What is NOT here: the ROPA and the retention schedule themselves. Those
        // live in `api/lib/dataprotection.php` as code registries, because a
        // register that describes what the software does must be derived from the
        // software. Free text in a table drifts from the code the day a feature
        // ships, and a drifted register is worse than none — it is a false
        // assurance. `dp_register_notes` carries only what the academy adds on
        // top: a review date, a note, and a lengthened retention period.
        84 => "
            CREATE TABLE IF NOT EXISTS dp_requests (
              id              INTEGER PRIMARY KEY AUTOINCREMENT,
              kind            TEXT    NOT NULL
                              CHECK(kind IN ('access','erasure','rectification','portability','objection','restriction','withdraw_consent','snag')),
              subject_email   TEXT    NOT NULL,
              subject_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
              channel         TEXT    NOT NULL DEFAULT 'email',
              -- The clock runs from when the request was RECEIVED, not from when
              -- somebody got round to logging it. Backdating is the whole point.
              received_at     TEXT    NOT NULL,
              acknowledged_at TEXT,
              closed_at       TEXT,
              outcome         TEXT
                              CHECK(outcome IS NULL OR outcome IN ('fulfilled','partially_fulfilled','refused','withdrawn')),
              refusal_reason  TEXT,
              detail          TEXT,
              handled_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at      TEXT NOT NULL DEFAULT (datetime('now')),
              CHECK(acknowledged_at IS NULL OR acknowledged_at >= received_at),
              CHECK(closed_at       IS NULL OR closed_at       >= received_at),
              -- A closed request must say how it ended, and a refusal must say why.
              CHECK(closed_at IS NULL OR outcome IS NOT NULL),
              CHECK(outcome IS NULL OR outcome <> 'refused' OR refusal_reason IS NOT NULL)
            );
            CREATE INDEX IF NOT EXISTS idx_dpreq_open  ON dp_requests(closed_at, received_at);
            CREATE INDEX IF NOT EXISTS idx_dpreq_email ON dp_requests(subject_email);

            CREATE TABLE IF NOT EXISTS dp_breaches (
              id                  INTEGER PRIMARY KEY AUTOINCREMENT,
              reference           TEXT    NOT NULL,
              -- NDPA s.40(2) starts the 72 hours at AWARENESS, not at occurrence.
              -- Both are recorded because the gap between them is itself a finding.
              occurred_at         TEXT,
              discovered_at       TEXT    NOT NULL,
              nature              TEXT    NOT NULL,
              data_categories     TEXT    NOT NULL,
              subject_categories  TEXT,
              subjects_affected   INTEGER,
              risk_level          TEXT    NOT NULL DEFAULT 'unknown'
                                  CHECK(risk_level IN ('unknown','low','medium','high')),
              containment         TEXT,
              ndpc_notified_at    TEXT,
              ndpc_reference      TEXT,
              subjects_notified_at TEXT,
              no_notify_reason    TEXT,
              lessons             TEXT,
              status              TEXT    NOT NULL DEFAULT 'open'
                                  CHECK(status IN ('open','contained','closed')),
              recorded_by         INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at          TEXT NOT NULL DEFAULT (datetime('now')),
              CHECK(occurred_at IS NULL OR occurred_at <= discovered_at),
              -- A high-risk breach must either have told the subjects or say why not
              -- (s.40(3)). 'We decided not to' is a defensible answer; silence is not.
              CHECK(risk_level <> 'high' OR subjects_notified_at IS NOT NULL
                    OR no_notify_reason IS NOT NULL OR status <> 'closed')
            );
            CREATE UNIQUE INDEX IF NOT EXISTS uq_dpbreach_ref ON dp_breaches(reference);
            CREATE INDEX IF NOT EXISTS idx_dpbreach_clock ON dp_breaches(discovered_at, ndpc_notified_at);

            CREATE TABLE IF NOT EXISTS dp_processors (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              key_name      TEXT    NOT NULL,
              name          TEXT    NOT NULL,
              role          TEXT    NOT NULL DEFAULT 'processor'
                            CHECK(role IN ('processor','joint_controller','controller')),
              purpose       TEXT,
              country       TEXT,
              dpa_signed_on TEXT,
              dpa_reference TEXT,
              sub_processors TEXT,
              transfer_basis TEXT,
              review_due_on TEXT,
              status        TEXT    NOT NULL DEFAULT 'active'
                            CHECK(status IN ('active','suspended','retired')),
              notes         TEXT,
              updated_at    TEXT,
              created_at    TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX IF NOT EXISTS uq_dpproc_key ON dp_processors(key_name);

            CREATE TABLE IF NOT EXISTS dp_assessments (
              id             INTEGER PRIMARY KEY AUTOINCREMENT,
              title          TEXT    NOT NULL,
              scope          TEXT    NOT NULL,
              trigger_reason TEXT,
              necessity      TEXT,
              risks          TEXT,
              mitigations    TEXT,
              residual_risk  TEXT    NOT NULL DEFAULT 'unknown'
                             CHECK(residual_risk IN ('unknown','low','medium','high')),
              decision       TEXT    NOT NULL DEFAULT 'draft'
                             CHECK(decision IN ('draft','proceed','proceed_with_controls','do_not_proceed')),
              assessed_on    TEXT,
              assessed_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
              review_due_on  TEXT,
              consulted_ndpc INTEGER NOT NULL DEFAULT 0,
              created_at     TEXT NOT NULL DEFAULT (datetime('now'))
            );

            -- Annotations over the CODE registries. `ref_key` names an entry in
            -- mam_dp_purposes() or mam_dp_retention(); `keep_days` is meaningful
            -- only for kind='retention' and only ever LENGTHENS the shipped period
            -- (enforced in PHP — a shortened period would delete lawfully-held
            -- records on somebody's guess).
            CREATE TABLE IF NOT EXISTS dp_register_notes (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              kind        TEXT    NOT NULL CHECK(kind IN ('purpose','retention')),
              ref_key     TEXT    NOT NULL,
              keep_days   INTEGER,
              notes       TEXT,
              reviewed_on TEXT,
              reviewed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              updated_at  TEXT,
              CHECK(keep_days IS NULL OR keep_days >= 0)
            );
            CREATE UNIQUE INDEX IF NOT EXISTS uq_dpnote_ref ON dp_register_notes(kind, ref_key);
        ",

        /* v85 — the ratified termly report (guardian redesign §3.3).
         *
         * The repo already had `progress_reports`, but that is a DIFFERENT
         * artefact: the instructor's post-session report, due within two hours,
         * with no status, no board, and no per-competency rows. The design's
         * termly report is a published academic record with a band on its face,
         * and the whole point of it is that a family sees it only AFTER a board
         * has ratified it. A provisional mark a family can see and then have
         * overturned is worse than one that arrives a week later.
         *
         * Four things here are deliberate and easy to "simplify" into being wrong:
         *
         *   1. The BAND is stored, not the score. `raw_score` appears nowhere in
         *      this schema: a guardian-facing table with a column for the precise
         *      figure is an invitation to print it, and the mastery coverage floor
         *      exists precisely so a thin composite is never published as a
         *      comparable number.
         *
         *      ⚠️ Saying so is not enforcing it, and `band_high > band_low` did
         *      NOT enforce it — three wrong writes were MEASURED through it:
         *        • `band_low = 74.3` stored verbatim as a REAL. SQLite's INTEGER
         *          is an affinity, not a type: a value it cannot convert without
         *          loss is kept as given. So the one number this table exists to
         *          make unrepresentable was representable.
         *        • `(70, 71)` — a one-point "band", which is false precision
         *          wearing a band's clothes, exactly what the design refuses.
         *        • `(0, 100)` — a "band" spanning the whole scale, which says
         *          nothing while looking like a measurement.
         *      And it refused a write that is CORRECT: `mam_mastery_score()`
         *      floors the score to a ten-point band and clamps the top, so a
         *      student scoring 100 yields `(100, 100)` — `band_high > band_low`
         *      rejected the report of a student who got everything right.
         *
         *      The CHECK now states the real invariant: both bounds are whole
         *      numbers (`x = CAST(x AS INTEGER)`, which SQLite and MySQL spell
         *      the same way), and the band is either exactly MAM_MASTERY_BAND
         *      wide or clamped against the top of the scale. `mam_report_band()`
         *      in api/lib/reports.php restates it in PHP, because a database that
         *      already ran the loose version keeps it — `CREATE TABLE IF NOT
         *      EXISTS` does not revise a table that is already there.
         *   2. Coverage is on the face, so it is stored beside the band and NOT
         *      NULL. "Based on 8 of 11 parts — 72% of the weight" is part of the
         *      report, not a derivation somebody might forget to run.
         *   3. A criterion with no evidence is a ROW with level 'insufficient'
         *      and a NULL pct — never a zero, never absent. `pct IS NULL` is
         *      required for that level and forbidden for the others, so the
         *      distinction cannot be lost by a careless write.
         *   4. Ratification is a transition with a person and a time on it, and
         *      the CHECK enforces that a ratified row has both. Nothing else in
         *      the guardian API is allowed to read a report that lacks them.
         *
         * `mark_appeals` is the other half of the refusal rule: a report cannot
         * be ratified while an appeal against it is open. It is its own table
         * rather than a status on the report because an appeal outlives the
         * report's status — a family appeals a ratified mark too, and the design
         * gives them three months to do it.
         */
        85 => "
            CREATE TABLE IF NOT EXISTS termly_reports (
              id                INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              cohort_id         INTEGER REFERENCES cohorts(id) ON DELETE SET NULL,
              course_id         INTEGER REFERENCES courses(id) ON DELETE SET NULL,
              level_id          INTEGER REFERENCES levels(id) ON DELETE SET NULL,
              term_id           INTEGER REFERENCES academic_terms(id) ON DELETE SET NULL,
              -- The session label the family has on their receipt. Stored, not
              -- looked up: session names are renameable in Settings and a report
              -- must keep the name it was published under.
              term_label        TEXT    NOT NULL,
              -- draft: being written. moderating: with the sample. ratified:
              -- visible to the family. withdrawn: ratified then pulled, which is
              -- a real event and must not be spelled as a delete.
              status            TEXT    NOT NULL DEFAULT 'draft'
                                CHECK(status IN ('draft','moderating','ratified','withdrawn')),
              band_low          INTEGER,
              band_high         INTEGER,
              /* NOT NULL and NO DEFAULT, all three. `NOT NULL DEFAULT 0`
               * constrains nothing — the default satisfies it, so a writer that
               * simply forgot the coverage arguments succeeded and the report
               * face rendered its mandatory sentence as 'Based on 0 of 11 parts
               * — 0% of what counts'. Coverage prints ON the document; a report
               * that cannot state it is not a report. */
              coverage_evidenced  INTEGER NOT NULL,
              coverage_total      INTEGER NOT NULL,
              coverage_weight_pct INTEGER NOT NULL,
              instructor_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
              instructor_comment TEXT,
              attendance_summary TEXT,
              -- The board. `ratified_by` is the panel chair's user id; NULL until
              -- it happens, and a ratified row may not have it NULL.
              ratified_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
              ratified_at       TEXT,
              ratified_note     TEXT,
              withdrawn_at      TEXT,
              withdrawn_reason  TEXT,
              created_by        INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at        TEXT NOT NULL DEFAULT (datetime('now')),
              updated_at        TEXT,
              -- A band is a real ten-point band or it is absent entirely. Both
              -- bounds whole (an INTEGER column does NOT guarantee that in
              -- SQLite), and ten points wide unless clamped at the top of the
              -- scale — which is the only reason band_high may equal band_low.
              CHECK((band_low IS NULL AND band_high IS NULL)
                 OR (band_low  = CAST(band_low  AS INTEGER)
                 AND band_high = CAST(band_high AS INTEGER)
                 AND band_low >= 0 AND band_high <= 100
                 AND band_high >= band_low
                 AND band_high - band_low <= 9
                 AND (band_high - band_low = 9 OR band_high = 100))),
              CHECK(coverage_evidenced >= 0 AND coverage_total >= 0
                    AND coverage_evidenced <= coverage_total),
              -- Whole per cent, for the same affinity reason as the band: '72% of
              -- the weight' prints on the face of the report and 71.6 does not.
              CHECK(coverage_weight_pct = CAST(coverage_weight_pct AS INTEGER)
                    AND coverage_weight_pct >= 0 AND coverage_weight_pct <= 100),
              -- Ratified means a named person on a stated date. Both or neither.
              CHECK(status <> 'ratified' OR (ratified_by IS NOT NULL AND ratified_at IS NOT NULL)),
              CHECK(status <> 'withdrawn' OR withdrawn_at IS NOT NULL)
            );
            CREATE INDEX IF NOT EXISTS idx_treport_student ON termly_reports(student_id, status);
            CREATE INDEX IF NOT EXISTS idx_treport_cohort  ON termly_reports(cohort_id, status);
            /* One LIVE report per slot — a lookup index here, and the rule itself
             * in mam_report_slot_taken(). A UNIQUE index cannot state this rule
             * on either engine, and the three ways it got it wrong are worth
             * recording because each looks like a tightening:
             *
             *   • `UNIQUE(..., term_label, ...)` with no status term permanently
             *     consumes the slot. Withdrawing a ratified report keeps its row
             *     on purpose — pulling a published mark is an event, not a delete
             *     — so the corrected report that a upheld appeal exists to
             *     produce could never be written. SQLite could express the
             *     exclusion as a partial index; MySQL 8 has no partial index, so
             *     the two engines would then enforce different rules.
             *   • Keying on `term_label` keys the rule on a MUTABLE display
             *     string. Session names are renameable in Settings (which is why
             *     the label is snapshotted here at all), and after a rename the
             *     same term reads as a different slot — two ratified reports for
             *     one term, neither marked as superseding the other. The rule
             *     needs term_id when there is one and the label only as the
             *     fallback for an unauthored calendar, which is a CASE, not a
             *     column list.
             *   • `COALESCE(course_id, 0)` inside a UNIQUE index turns the
             *     `ON DELETE SET NULL` above into an abort: retiring a course
             *     collapses a course-scoped report onto a course-less one for the
             *     same term and the whole DELETE rolls back citing an index name
             *     the admin has never seen. Without COALESCE, SQL's NULLs-are-
             *     distinct rule makes the cascade harmless.
             */
            CREATE INDEX IF NOT EXISTS idx_treport_slot
              ON termly_reports(student_id, term_id, term_label, course_id, level_id, status);

            CREATE TABLE IF NOT EXISTS termly_report_criteria (
              id                 INTEGER PRIMARY KEY AUTOINCREMENT,
              report_id          INTEGER NOT NULL REFERENCES termly_reports(id) ON DELETE CASCADE,
              -- The competency this criterion belongs to, as a CODE, not a
              -- keyword match on the label. v1 matched criteria by string and
              -- silently reclassified anything an instructor renamed.
              competency_code    TEXT    NOT NULL,
              label              TEXT    NOT NULL,
              weight_pct         INTEGER NOT NULL DEFAULT 0,
              level              TEXT    NOT NULL
                                 CHECK(level IN ('strong','secure','working','insufficient')),
              -- NULL for 'insufficient' and only for it. A zero there would be a
              -- fail; there is no evidence, which is a different statement.
              pct                INTEGER,
              grade_label        TEXT,
              first_assessed_week INTEGER,
              note               TEXT,
              sort_order         INTEGER NOT NULL DEFAULT 0,
              CHECK(weight_pct = CAST(weight_pct AS INTEGER)
                    AND weight_pct >= 0 AND weight_pct <= 100),
              -- Whole per cent here too: the bar on the report is drawn from it,
              -- and a decimal is the false precision the band exists to refuse.
              CHECK(pct IS NULL
                    OR (pct = CAST(pct AS INTEGER) AND pct >= 0 AND pct <= 100)),
              CHECK((level =  'insufficient' AND pct IS NULL)
                 OR (level <> 'insufficient' AND pct IS NOT NULL))
            );
            CREATE INDEX IF NOT EXISTS idx_tcrit_report ON termly_report_criteria(report_id, sort_order);
            CREATE UNIQUE INDEX IF NOT EXISTS uq_tcrit_slot ON termly_report_criteria(report_id, competency_code);

            CREATE TABLE IF NOT EXISTS report_moderations (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              report_id    INTEGER NOT NULL REFERENCES termly_reports(id) ON DELETE CASCADE,
              -- A second marker looked at this criterion. The sample is the set
              -- of these rows; 'short sample' is a count, which is why the row
              -- is per-criterion and not per-report.
              competency_code TEXT NOT NULL,
              moderator_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
              agreed        INTEGER NOT NULL DEFAULT 1 CHECK(agreed IN (0,1)),
              moderator_level TEXT
                            CHECK(moderator_level IS NULL OR moderator_level IN ('strong','secure','working','insufficient')),
              note          TEXT,
              moderated_at  TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_rmod_report ON report_moderations(report_id);
            /* Plain UNIQUE, no COALESCE: SQL's NULLs-are-distinct rule is the
             * correct semantics here. Under `COALESCE(moderator_id, 0)` every
             * unattributed moderation collapsed onto one key, so a criterion
             * second-marked twice without the marker recorded stored ONE row —
             * and the sample size is a count of these rows, which is the gate
             * mam_report_ratify() refuses on. An index that undercounts the
             * sample makes the refusal fire on a report that was moderated
             * properly. It also made the ON DELETE SET NULL above abort once a
             * second moderator's row had already been nulled onto key 0. */
            CREATE UNIQUE INDEX IF NOT EXISTS uq_rmod_slot
              ON report_moderations(report_id, competency_code, moderator_id);

            CREATE TABLE IF NOT EXISTS mark_appeals (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              report_id     INTEGER REFERENCES termly_reports(id) ON DELETE CASCADE,
              student_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              -- Who raised it. A guardian, the student, or staff on their behalf.
              raised_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
              raised_role   TEXT    NOT NULL DEFAULT 'parent'
                            CHECK(raised_role IN ('parent','student','staff')),
              competency_code TEXT,
              reason        TEXT    NOT NULL,
              status        TEXT    NOT NULL DEFAULT 'open'
                            CHECK(status IN ('open','upheld','declined','withdrawn')),
              outcome_note  TEXT,
              decided_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
              opened_at     TEXT NOT NULL DEFAULT (datetime('now')),
              closed_at     TEXT,
              /* A closed appeal states its outcome — ANY closed appeal. The
               * rule was written as `status <> 'declined'`, which left an UPHELD
               * appeal free to close with no note: the family's appeal history
               * then showed a mark overturned and nothing saying why, the exact
               * silent gap the declined-side check was added to prevent. A
               * withdrawal states who withdrew it and when, for the same reason. */
              CHECK(status = 'open' OR (closed_at IS NOT NULL AND outcome_note IS NOT NULL)),
              CHECK(closed_at IS NULL OR closed_at >= opened_at)
            );
            CREATE INDEX IF NOT EXISTS idx_appeal_report ON mark_appeals(report_id, status);
            CREATE INDEX IF NOT EXISTS idx_appeal_open   ON mark_appeals(status, opened_at);
        ",

        /* v86 — the guardian ACCESS SCOPE (guardian redesign §3.2).
         *
         * The design gives a guardian one of three scopes, and says the boundary
         * must be enforced SERVER-SIDE, "not by hiding UI. Say it on the screen so
         * the boundary is understood rather than discovered." Until now every
         * linked guardian saw everything the parent routes returned, so the
         * distinction did not exist to enforce.
         *
         *   billing  — invoices and receipts. No marks, no attendance.
         *   standard — the above plus ratified reports, attendance, timetable.
         *   full     — the above plus consents, and can act for the child.
         *
         * `standard` is the DEFAULT, and is what every existing link becomes: a
         * migration that silently narrowed live guardians to billing-only would
         * take away access families already have, which is a worse failure than
         * the one being fixed. Widening to `full` is a deliberate admin act.
         *
         * ⚠️ What no scope reaches — and this is a refusal, not a gap — is
         * practice logs, teach-back recordings, and a teenager's messages with
         * their instructor. `full` is not "everything"; it is "everything the
         * academy has assessed, plus the ability to act". That boundary lives in
         * mam_parent_scope_allows(), which is why the scope is a column here and
         * a predicate there rather than a set of flags a caller assembles.
         *
         * The column is on parent_links, not users: one adult can be the billing
         * contact for a nephew and the full guardian of their own child.
         *
         * ⚠️ No CHECK on the value here, because SQLite cannot add one through
         * ALTER TABLE and rebuilding parent_links is not worth it. The MySQL
         * mirror does carry `chk_plink_scope`, so the two engines differ — which
         * means the authority is `mam_parent_scope_set()`, the only writer, and it
         * validates against MAM_PARENT_SCOPES. `mam_parent_scope()` also treats an
         * unrecognised stored value as the NARROWEST scope rather than trusting
         * it, so a hand-edited row cannot widen access.
         */
        86 => "
            ALTER TABLE parent_links ADD COLUMN access_scope TEXT NOT NULL DEFAULT 'standard';
            ALTER TABLE parent_links ADD COLUMN scope_set_by INTEGER REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE parent_links ADD COLUMN scope_set_at TEXT;
            CREATE INDEX IF NOT EXISTS idx_plink_scope ON parent_links(parent_id, access_scope);
            -- One link per guardian↔child pair. mam_parent_link() already checks
            -- before inserting, but the scope now hangs off this row, so a second
            -- row would mean two different answers to 'what may this guardian see'.
            CREATE UNIQUE INDEX IF NOT EXISTS uq_plink_pair ON parent_links(parent_id, student_id);
        ",

        /* v87 — the FEE INVOICE, and the four things that are not one discount
         * (guardian redesign §3.4).
         *
         * There was no invoice table in this repo at all. `invoicing.php` is the
         * APPLICATION processing-fee lifecycle — one charge, one applicant, a
         * reminder schedule — not a per-child statement of account. The design's
         * Fees screen needs line items, receipts, a refund rule, and the four
         * kinds of aid, none of which that file can express.
         *
         * ── Money is integer minor units + an ISO currency + a frozen rate ────
         * Never a float, and never a bare number whose meaning depends on a
         * sibling column — that ambiguity is the bug `payments.amount_usd_cents`
         * was added in v61 to end, and it was found by someone summing naira into
         * dollars and rendering the total with a naira sign.
         *
         * Each invoice carries `currency` + `*_minor` in that currency, plus
         * `fx_rate_usd_ngn` FROZEN at issue and `total_usd_cents` derived once.
         * The frozen rate is not decoration: it is what lets a May figure
         * reconcile in the following audit, and the design asks for it on the
         * face of the invoice. The rate a payment actually settles at is the
         * payment row's own, which is why the screen says the naira figure is an
         * estimate until it clears.
         *
         * ⚠️ Totals are STORED, computed once from the lines in the invoice's own
         * currency. Not recomputed on read — a family has a number; and NOT
         * summed from per-line currency conversions, because rounding eleven
         * lines individually and adding them gives a total a naira away from
         * converting the sum, and a one-naira mismatch reads to a parent as a
         * pricing error rather than as arithmetic.
         *
         * ── The four kinds of aid are four MECHANISMS, not four discounts ─────
         * This is the part that is easy to collapse and expensive to get wrong,
         * because it changes what the academy's books say happened:
         *
         *   waiver      — reduces the invoice LINE. Non-revenue: the academy never
         *                 billed it, so it must never appear as a cash entry.
         *                 Lives on `fee_invoice_lines.waiver_minor`.
         *   bursary     — needs-based GIFT AID. A credit against the invoice from
         *                 the aid pool. The line stands at full value and the
         *                 credit is a real transfer, because the money exists.
         *   scholarship — merit gift aid. Same mechanism, different criteria.
         *   sibling     — a DISCOUNT, applied AFTER aid and capped at the
         *                 published maximum. After, because a percentage of a
         *                 post-aid balance is smaller than a percentage of the
         *                 gross, and applying it first would hand the household
         *                 a discount on money the aid pool paid.
         *
         * The order is therefore fixed and stated: gross lines → waivers → aid
         * credits → sibling discount (capped) → total. `fee_invoices` stores each
         * stage so a family can be shown the arithmetic and an auditor can check
         * it, rather than one net figure nobody can take apart.
         *
         * ── Dunning, not disconnection ────────────────────────────────────────
         * `dunning_stage` records how far the reminders have gone (day 0, 3, 7)
         * and `grace_until` the 14-day window. There is deliberately NO column
         * that suspends anything: the design's refusal is that an access hold
         * never touches a child's academic record, a safeguarding file, or a
         * minor's report, and the way to keep a refusal is to leave the
         * mechanism unbuilt. The next step after the grace window is a
         * conversation, which is a task for a human and not a flag here.
         */
        87 => "
            CREATE TABLE IF NOT EXISTS fee_invoices (
              id             INTEGER PRIMARY KEY AUTOINCREMENT,
              public_ref     TEXT    NOT NULL,
              student_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              -- The billing contact. A guardian, and not necessarily the guardian
              -- with academic access — one adult can be the payer for a nephew
              -- and the full guardian of their own child.
              guardian_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
              term_id        INTEGER REFERENCES academic_terms(id) ON DELETE SET NULL,
              -- Snapshotted, like the report's: session names are renameable and
              -- the family has this one on their receipt.
              term_label     TEXT    NOT NULL,
              currency       TEXT    NOT NULL DEFAULT 'USD',
              status         TEXT    NOT NULL DEFAULT 'draft'
                             CHECK(status IN ('draft','issued','part_paid','paid','void')),
              issued_on      TEXT,
              due_on         TEXT,
              grace_until    TEXT,
              dunning_stage  INTEGER NOT NULL DEFAULT 0,
              dunning_last   TEXT,
              -- Frozen at issue. Display uses the live rate and says so; this is
              -- the number an audit reconciles against.
              fx_rate_usd_ngn REAL,
              -- The stages, in the order they apply. All in `currency` minor units.
              gross_minor    INTEGER NOT NULL DEFAULT 0,
              waiver_minor   INTEGER NOT NULL DEFAULT 0,
              aid_minor      INTEGER NOT NULL DEFAULT 0,
              sibling_minor  INTEGER NOT NULL DEFAULT 0,
              total_minor    INTEGER NOT NULL DEFAULT 0,
              paid_minor     INTEGER NOT NULL DEFAULT 0,
              -- The only cross-invoice summable figure, frozen with the rate.
              total_usd_cents INTEGER,
              note           TEXT,
              void_reason    TEXT,
              created_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at     TEXT NOT NULL DEFAULT (datetime('now')),
              updated_at     TEXT,
              -- Minor units are counts of the smallest coin. A negative one is not
              -- a refund, it is a data error; a refund is its own payment row.
              CHECK(gross_minor   >= 0 AND gross_minor   = CAST(gross_minor   AS INTEGER)),
              CHECK(waiver_minor  >= 0 AND waiver_minor  = CAST(waiver_minor  AS INTEGER)),
              CHECK(aid_minor     >= 0 AND aid_minor     = CAST(aid_minor     AS INTEGER)),
              CHECK(sibling_minor >= 0 AND sibling_minor = CAST(sibling_minor AS INTEGER)),
              CHECK(total_minor   >= 0 AND total_minor   = CAST(total_minor   AS INTEGER)),
              CHECK(paid_minor    >= 0 AND paid_minor    = CAST(paid_minor    AS INTEGER)),
              -- Nothing may reduce the bill below zero, and the stages must add up.
              CHECK(waiver_minor + aid_minor + sibling_minor <= gross_minor),
              CHECK(total_minor = gross_minor - waiver_minor - aid_minor - sibling_minor),
              CHECK(length(currency) = 3),
              /* An ISSUED invoice has a date and something to pay by. Draft and
               * VOID are both exempt: a draft raised against the wrong child is
               * voided rather than deleted (the row and its reason are the
               * record), and it never had an issue date to state. Writing this as
               * `status = 'draft' OR …` made that void a hard write failure. */
              CHECK(status IN ('draft','void') OR (issued_on IS NOT NULL AND due_on IS NOT NULL)),
              CHECK(status <> 'void' OR void_reason IS NOT NULL)
            );
            CREATE UNIQUE INDEX IF NOT EXISTS uq_feeinv_ref ON fee_invoices(public_ref);
            CREATE INDEX IF NOT EXISTS idx_feeinv_student  ON fee_invoices(student_id, status);
            CREATE INDEX IF NOT EXISTS idx_feeinv_guardian ON fee_invoices(guardian_id, status);
            CREATE INDEX IF NOT EXISTS idx_feeinv_due      ON fee_invoices(status, due_on);

            CREATE TABLE IF NOT EXISTS fee_invoice_lines (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              invoice_id    INTEGER NOT NULL REFERENCES fee_invoices(id) ON DELETE CASCADE,
              kind          TEXT    NOT NULL DEFAULT 'tuition'
                            CHECK(kind IN ('tuition','materials','exam','registration','recital','late','other')),
              label         TEXT    NOT NULL,
              enrollment_id INTEGER REFERENCES course_enrollments(id) ON DELETE SET NULL,
              cohort_id     INTEGER REFERENCES cohorts(id) ON DELETE SET NULL,
              qty           INTEGER NOT NULL DEFAULT 1,
              unit_minor    INTEGER NOT NULL DEFAULT 0,
              amount_minor  INTEGER NOT NULL DEFAULT 0,
              -- A WAIVER reduces the line and is never a cash entry. Kept on the
              -- line rather than netted into amount_minor so the invoice can show
              -- what was charged and what was waived, which is what a family and
              -- an auditor both need to see.
              waiver_minor  INTEGER NOT NULL DEFAULT 0,
              waiver_reason TEXT,
              sort_order    INTEGER NOT NULL DEFAULT 0,
              CHECK(qty > 0),
              CHECK(unit_minor   >= 0 AND unit_minor   = CAST(unit_minor   AS INTEGER)),
              CHECK(amount_minor >= 0 AND amount_minor = CAST(amount_minor AS INTEGER)),
              CHECK(waiver_minor >= 0 AND waiver_minor = CAST(waiver_minor AS INTEGER)),
              CHECK(waiver_minor <= amount_minor),
              CHECK(amount_minor = unit_minor * qty),
              CHECK(waiver_minor = 0 OR waiver_reason IS NOT NULL)
            );
            CREATE INDEX IF NOT EXISTS idx_feeline_invoice ON fee_invoice_lines(invoice_id, sort_order);

            /* The AWARD — the academy's decision that a student is entitled to
             * something. Separate from its application to any one invoice,
             * because an award spans a term and an invoice is a month. */
            CREATE TABLE IF NOT EXISTS fee_aid_awards (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              kind         TEXT    NOT NULL
                           CHECK(kind IN ('bursary','scholarship','waiver','sibling')),
              -- Either a percentage or a fixed amount, never both and never neither.
              pct          REAL,
              amount_minor INTEGER,
              currency     TEXT,
              term_id      INTEGER REFERENCES academic_terms(id) ON DELETE SET NULL,
              term_label   TEXT,
              basis        TEXT,
              status       TEXT    NOT NULL DEFAULT 'active'
                           CHECK(status IN ('active','spent','expired','revoked')),
              starts_on    TEXT,
              expires_on   TEXT,
              awarded_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              awarded_at   TEXT NOT NULL DEFAULT (datetime('now')),
              revoked_at   TEXT,
              revoked_reason TEXT,
              note         TEXT,
              CHECK((pct IS NOT NULL AND amount_minor IS NULL)
                 OR (pct IS NULL AND amount_minor IS NOT NULL)),
              CHECK(pct IS NULL OR (pct > 0 AND pct <= 100)),
              CHECK(amount_minor IS NULL OR (amount_minor > 0 AND amount_minor = CAST(amount_minor AS INTEGER))),
              CHECK(amount_minor IS NULL OR currency IS NOT NULL),
              CHECK(status <> 'revoked' OR (revoked_at IS NOT NULL AND revoked_reason IS NOT NULL))
            );
            CREATE INDEX IF NOT EXISTS idx_aid_student ON fee_aid_awards(student_id, status, kind);

            /* GIFT AID applied to one invoice — bursary and scholarship only.
             * A waiver never appears here (it is on the line, and is not money);
             * a sibling discount never appears here (it is a discount on the
             * post-aid balance, stored on the invoice). Keeping the three apart
             * is what makes 'how much aid did the academy actually pay out this
             * term' a question with an answer. */
            CREATE TABLE IF NOT EXISTS fee_invoice_credits (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              invoice_id   INTEGER NOT NULL REFERENCES fee_invoices(id) ON DELETE CASCADE,
              award_id     INTEGER REFERENCES fee_aid_awards(id) ON DELETE SET NULL,
              kind         TEXT    NOT NULL CHECK(kind IN ('bursary','scholarship')),
              amount_minor INTEGER NOT NULL,
              currency     TEXT    NOT NULL,
              note         TEXT,
              applied_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              applied_at   TEXT NOT NULL DEFAULT (datetime('now')),
              CHECK(amount_minor > 0 AND amount_minor = CAST(amount_minor AS INTEGER)),
              CHECK(length(currency) = 3)
            );
            CREATE INDEX IF NOT EXISTS idx_feecredit_invoice ON fee_invoice_credits(invoice_id);
            -- One application of an award per invoice: the design's Fees screen
            -- shows a single aid line, and applying one award twice is the
            -- commonest way a double-submitted form gives away the aid pool.
            CREATE UNIQUE INDEX IF NOT EXISTS uq_feecredit_award ON fee_invoice_credits(invoice_id, award_id);

            /* 'Pay in 3 instalments instead' — a button in the design with
             * nothing behind it. The plan does not change the total; it changes
             * when each part falls due, so the instalments must SUM to the
             * invoice total exactly and the remainder goes on the FIRST one
             * rather than being lost to rounding. */
            CREATE TABLE IF NOT EXISTS fee_payment_plans (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              invoice_id    INTEGER NOT NULL REFERENCES fee_invoices(id) ON DELETE CASCADE,
              instalments   INTEGER NOT NULL,
              status        TEXT    NOT NULL DEFAULT 'active'
                            CHECK(status IN ('active','completed','cancelled')),
              requested_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
              approved_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at    TEXT NOT NULL DEFAULT (datetime('now')),
              cancelled_at  TEXT,
              cancel_reason TEXT,
              CHECK(instalments >= 2 AND instalments <= 12),
              CHECK(status <> 'cancelled' OR (cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL))
            );
            -- One live plan per invoice. Two would make 'what is due next' ambiguous.
            CREATE INDEX IF NOT EXISTS idx_feeplan_invoice ON fee_payment_plans(invoice_id, status);

            CREATE TABLE IF NOT EXISTS fee_plan_instalments (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              plan_id      INTEGER NOT NULL REFERENCES fee_payment_plans(id) ON DELETE CASCADE,
              seq          INTEGER NOT NULL,
              due_on       TEXT    NOT NULL,
              amount_minor INTEGER NOT NULL,
              status       TEXT    NOT NULL DEFAULT 'pending'
                           CHECK(status IN ('pending','paid','waived')),
              paid_at      TEXT,
              payment_id   INTEGER REFERENCES payments(id) ON DELETE SET NULL,
              CHECK(seq >= 1),
              CHECK(amount_minor > 0 AND amount_minor = CAST(amount_minor AS INTEGER)),
              CHECK(status <> 'paid' OR paid_at IS NOT NULL)
            );
            CREATE UNIQUE INDEX IF NOT EXISTS uq_feeinst_seq ON fee_plan_instalments(plan_id, seq);
            CREATE INDEX IF NOT EXISTS idx_feeinst_due ON fee_plan_instalments(status, due_on);

            /* A bursary is assessed on a DECLARED INCOME BAND — never a written
             * case for hardship. The design is explicit, and the reason is that a
             * written case rewards the family best able to write one, asks a
             * parent to describe their circumstances to their child's school, and
             * gives an assessor room to be inconsistent. A band is a fact the
             * academy can apply a published table to.
             *
             * There is deliberately NO free-text hardship column. `note` is the
             * ASSESSOR's, not the applicant's, and is not shown to the family.
             *
             * `affects_admission` does not exist either, and must not: applying
             * never affects an admission decision, and the way to keep that true
             * is to leave admissions unable to see this table. */
            CREATE TABLE IF NOT EXISTS bursary_applications (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              guardian_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              term_id       INTEGER REFERENCES academic_terms(id) ON DELETE SET NULL,
              term_label    TEXT,
              income_band   TEXT    NOT NULL,
              household_size INTEGER,
              dependants_in_academy INTEGER NOT NULL DEFAULT 1,
              status        TEXT    NOT NULL DEFAULT 'submitted'
                            CHECK(status IN ('submitted','assessing','awarded','declined','withdrawn')),
              awarded_pct   REAL,
              award_id      INTEGER REFERENCES fee_aid_awards(id) ON DELETE SET NULL,
              decided_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
              decided_at    TEXT,
              outcome_note  TEXT,
              note          TEXT,
              created_at    TEXT NOT NULL DEFAULT (datetime('now')),
              CHECK(household_size IS NULL OR household_size >= 1),
              CHECK(dependants_in_academy >= 1),
              CHECK(awarded_pct IS NULL OR (awarded_pct > 0 AND awarded_pct <= 100)),
              -- A decision states its outcome and names who made it, whichever way
              -- it went. Same rule as a mark appeal, and for the same reason.
              CHECK(status IN ('submitted','assessing')
                 OR (decided_at IS NOT NULL AND outcome_note IS NOT NULL)),
              CHECK(status <> 'awarded' OR awarded_pct IS NOT NULL)
            );
            CREATE INDEX IF NOT EXISTS idx_bursary_student ON bursary_applications(student_id, status);
            CREATE INDEX IF NOT EXISTS idx_bursary_queue   ON bursary_applications(status, created_at);
        ",

        /* v88 — guardian↔staff THREADS, how the academy reaches a family, and the
         * make-up credit ledger (guardian redesign §3.5 and the Messages screen).
         *
         * ── Threads ───────────────────────────────────────────────────────────
         * The existing `messages` table is the PUBLIC CONTACT FORM — name, email,
         * phone, topic, body — with no participants and no notion of a reply. It
         * cannot be a thread, and bending it into one would put anonymous website
         * enquiries in the same table as a safeguarding conversation about a named
         * child.
         *
         * ⚠️ **A student is never a participant in a guardian thread.** That is
         * the refusal, not an omission: no guardian scope reaches a teenager's
         * messages with their instructor, and the cheapest way to breach that
         * would be to let a guardian be added to the student's own thread. The
         * two conversations are separate tables' worth of separate — this one is
         * `guardian_threads`, and the participant CHECK admits only the adult
         * roles. `mam_thread_add_participant()` refuses a student outright.
         *
         * `kind` is not cosmetic. It decides whether the channel preferences
         * below may suppress a notification, so it is on the thread and
         * constrained.
         *
         * ── Channel preferences, and what they cannot mute ────────────────────
         * WhatsApp / Email / SMS, per the design's Messages screen. The rule
         * beneath them is the important part: **anything about safeguarding or
         * money reaches the guardian on every channel the academy can use,
         * regardless of these toggles.** A family that has muted notifications
         * has muted reminders about practice, not a fee falling due or a concern
         * about their child.
         *
         * That is enforced in `mam_guardian_channels()`, not here, because it is a
         * rule about topics and this table holds preferences. What the schema does
         * is refuse to store a preference that claims otherwise: there is no
         * per-topic column, so there is nowhere to record "mute safeguarding".
         *
         * ── Make-up credits ───────────────────────────────────────────────────
         * `makeup.php` only ever SUGGESTED sessions; it held no credits, and the
         * guardian screens count them ("3 · 5", "credits left"). Six rules, five
         * of which are one column each and the sixth of which is a refusal:
         *
         *   • 24 hours' notice earns a credit  → `notice_hours` recorded, and the
         *     grant path checks it.
         *   • five a year                       → counted over the ALLOWANCE rows
         *     only, see below.
         *   • non-transferable                  → `student_id` is on the row and
         *     there is no transfer column. A credit is not a token.
         *   • they expire                       → `expires_on` NOT NULL. A credit
         *     with no expiry is a liability the academy cannot close a year on.
         *   • no cash in lieu                   → there is deliberately NO money
         *     column, no currency, and no link to `payments`. A credit buys a
         *     lesson or it lapses; making it refundable would turn every
         *     cancellation into a pricing negotiation.
         *   • **a teacher cancellation always credits and never counts against the
         *     five** → `counts_against_allowance = 0` for that reason, and the
         *     allowance query sums only the rows where it is 1. Charging a family
         *     an allowance slot because the academy cancelled is the single most
         *     resented thing a make-up policy can do.
         */
        88 => "
            CREATE TABLE IF NOT EXISTS guardian_threads (
              id             INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              subject        TEXT    NOT NULL,
              -- Decides whether the channel preferences may suppress a notice.
              kind           TEXT    NOT NULL DEFAULT 'general'
                             CHECK(kind IN ('general','academic','money','safeguarding','timetable')),
              status         TEXT    NOT NULL DEFAULT 'open'
                             CHECK(status IN ('open','closed')),
              opened_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at     TEXT NOT NULL DEFAULT (datetime('now')),
              last_message_at TEXT,
              closed_at      TEXT,
              CHECK(status = 'open' OR closed_at IS NOT NULL)
            );
            CREATE INDEX IF NOT EXISTS idx_gthread_student ON guardian_threads(student_id, status, last_message_at);

            CREATE TABLE IF NOT EXISTS guardian_thread_participants (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              thread_id  INTEGER NOT NULL REFERENCES guardian_threads(id) ON DELETE CASCADE,
              user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              -- No 'student'. A guardian thread has adults in it; a young person's
              -- conversation with their instructor is a different thing that no
              -- guardian scope reaches, and the cheapest breach of that would be
              -- adding a guardian to the student's own thread.
              role       TEXT    NOT NULL
                         CHECK(role IN ('guardian','instructor','office','admin')),
              last_read_at TEXT,
              added_at   TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX IF NOT EXISTS uq_gpart_pair ON guardian_thread_participants(thread_id, user_id);
            CREATE INDEX IF NOT EXISTS idx_gpart_user ON guardian_thread_participants(user_id);

            CREATE TABLE IF NOT EXISTS guardian_thread_messages (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              thread_id  INTEGER NOT NULL REFERENCES guardian_threads(id) ON DELETE CASCADE,
              author_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
              -- Who it read as. Kept because author_id goes NULL when a staff
              -- account is deleted and a family's thread must still make sense.
              author_name TEXT   NOT NULL,
              author_role TEXT   NOT NULL
                          CHECK(author_role IN ('guardian','instructor','office','admin')),
              body       TEXT    NOT NULL,
              created_at TEXT    NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_gmsg_thread ON guardian_thread_messages(thread_id, created_at);

            /* One row per guardian. No per-topic column, deliberately: there is
             * nowhere to record 'mute safeguarding', because that is not a
             * preference the academy offers. */
            CREATE TABLE IF NOT EXISTS guardian_channel_prefs (
              user_id    INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
              whatsapp   INTEGER NOT NULL DEFAULT 1 CHECK(whatsapp IN (0,1)),
              email      INTEGER NOT NULL DEFAULT 1 CHECK(email IN (0,1)),
              sms        INTEGER NOT NULL DEFAULT 0 CHECK(sms IN (0,1)),
              updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS makeup_credits (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              term_id      INTEGER REFERENCES academic_terms(id) ON DELETE SET NULL,
              -- The academy year the allowance is counted over. Stored, because
              -- 'five a year' has to mean the same five in December as in March.
              year_label   TEXT    NOT NULL,
              reason       TEXT    NOT NULL
                           CHECK(reason IN ('guardian_notice','teacher_cancellation','academy_closure','goodwill')),
              -- The session that was missed, when there was one.
              source_session_id INTEGER REFERENCES class_sessions(id) ON DELETE SET NULL,
              notice_hours REAL,
              /* 0 for a teacher cancellation and an academy closure: the family
               * did not spend anything, so it must not cost them an allowance
               * slot. The allowance query counts only the 1s. */
              counts_against_allowance INTEGER NOT NULL DEFAULT 1
                           CHECK(counts_against_allowance IN (0,1)),
              status       TEXT    NOT NULL DEFAULT 'available'
                           CHECK(status IN ('available','spent','expired','revoked')),
              -- NOT NULL: a credit with no expiry is a liability the academy can
              -- never close a year on.
              expires_on   TEXT    NOT NULL,
              spent_session_id INTEGER REFERENCES class_sessions(id) ON DELETE SET NULL,
              spent_at     TEXT,
              granted_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              granted_at   TEXT    NOT NULL DEFAULT (datetime('now')),
              revoked_reason TEXT,
              note         TEXT,
              CHECK(notice_hours IS NULL OR notice_hours >= 0),
              CHECK(status <> 'spent' OR spent_at IS NOT NULL),
              CHECK(status <> 'revoked' OR revoked_reason IS NOT NULL),
              /* Only a GUARDIAN cancellation may cost an allowance slot. Stated
               * here as well as in PHP because it is the rule most likely to be
               * lost to a caller passing the default — and the allowance limits
               * how often a FAMILY cancels, so anything the academy initiates
               * (its instructor unwell, its building shut, a goodwill gesture) is
               * free by construction rather than by remembering. */
              CHECK(reason = 'guardian_notice' OR counts_against_allowance = 0)
            );
            CREATE INDEX IF NOT EXISTS idx_makeup_student ON makeup_credits(student_id, status, expires_on);
            CREATE INDEX IF NOT EXISTS idx_makeup_year    ON makeup_credits(student_id, year_label, counts_against_allowance);
        ",

        /* v89 — No-op on SQLite, load-bearing on MySQL.
         *
         * `users.role` and `user_roles.role` were declared ENUM('admin',
         * 'instructor','student') in db/schema-mysql.sql. The application writes
         * 'parent' — every guardian account — so under the STRICT_ALL_TABLES
         * sql_mode mam_db() sets, creating a guardian on a MySQL host raised
         * 1265 "Data truncated for column 'role'" and the guardian portal could
         * not be used at all. SQLite's TEXT accepted it, so the whole guardian
         * layer was built and tested against an engine that could not reject it.
         *
         * VARCHAR, not a wider ENUM: the role vocabulary lives in code
         * (mam_user_roles / mam_require_role) and grows with the product, and an
         * ENUM makes every new role a schema migration on a live host. The
         * portable form is the one SQLite already had.
         *
         * MySQL applies this through the file re-exec; the SQLite side has
         * nothing to do, and bumping the target is what makes MySQL re-run.
         */
        89 => "SELECT 1;",

        /* v90 — the instructor application becomes an ASSESSMENT, not a note.
         *
         * v36 stored what a prospective instructor teaches in a single free-text
         * column: `disciplines TEXT` holding things like "Piano, Music Theory,
         * Voice". Nothing can join on that. The academy's teaching model is
         * `instructor_assignments(instructor_id, course_id, level_id)`, so an
         * admin approving an application had to re-key the candidate's subjects
         * by hand, and no query could answer "who can teach Diploma piano" —
         * which is the question a capacity-aware enrolment (gotcha #3) asks
         * every time a cohort needs a teacher.
         *
         * `instructor_application_subjects` is that matrix, stated once against
         * the same (course, level) tuple the assignment table uses, so approval
         * is a copy rather than a retyping.
         *
         * ── Why the referees table stores contacts and NOT check content ──────
         * `MAM_VETTING_REQUIRED` names `reference_1` and `reference_2`, and an
         * application is the natural moment to collect them. But gotcha #10 is
         * load-bearing: `vetting_records` deliberately holds no check CONTENT,
         * because a certificate scan in a shared-hosting database is a liability
         * rather than a control. So this table carries only who to ASK — name,
         * relationship, where they work, how to reach them. A verifier takes the
         * reference up and records the OUTCOME in `vetting_records`. An
         * applicant's own claim is never a clearance, and there is deliberately
         * nowhere here to type a certificate number or an offence.
         *
         * `safeguarding_ack` / `policy_ack` are the applicant's COMMITMENT (the
         * `policy_commitment` limb), which is a statement they make rather than
         * a check somebody performed — so it is legitimately self-reported, and
         * it is kept separate from the verified record for exactly that reason.
         *
         * ── MySQL notes ──────────────────────────────────────────────────────
         * Every added TEXT column is nullable with no DEFAULT: MySQL TEXT cannot
         * carry one (the `student_type` convention), so NULL is read as the
         * default in code. The INTEGER flags take DEFAULT 0, which is portable.
         */
        90 => "
            ALTER TABLE instructor_applications ADD COLUMN programmes TEXT;
            ALTER TABLE instructor_applications ADD COLUMN teaching_modes TEXT;
            ALTER TABLE instructor_applications ADD COLUMN location TEXT;
            ALTER TABLE instructor_applications ADD COLUMN qualifications TEXT;
            ALTER TABLE instructor_applications ADD COLUMN teaching_philosophy TEXT;
            ALTER TABLE instructor_applications ADD COLUMN availability TEXT;
            ALTER TABLE instructor_applications ADD COLUMN weekly_hours INTEGER;
            ALTER TABLE instructor_applications ADD COLUMN earliest_start TEXT;
            ALTER TABLE instructor_applications ADD COLUMN safeguarding_ack INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE instructor_applications ADD COLUMN policy_ack INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE instructor_applications ADD COLUMN readiness_signal TEXT;
            ALTER TABLE instructor_applications ADD COLUMN submitted_at TEXT;

            CREATE TABLE instructor_application_subjects (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              application_id INTEGER NOT NULL REFERENCES instructor_applications(id) ON DELETE CASCADE,
              course_id INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
              level_id INTEGER REFERENCES levels(id) ON DELETE SET NULL,
              years INTEGER,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_iasubj_app ON instructor_application_subjects(application_id);
            CREATE INDEX idx_iasubj_course ON instructor_application_subjects(course_id, level_id);

            CREATE TABLE instructor_application_referees (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              application_id INTEGER NOT NULL REFERENCES instructor_applications(id) ON DELETE CASCADE,
              name TEXT NOT NULL,
              relationship TEXT,
              organisation TEXT,
              email TEXT,
              phone TEXT,
              created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX idx_iaref_app ON instructor_application_referees(application_id);
        ",
        /* 91 — money the office RECEIVES against a fee invoice (fees.php,
           mam_fee_payment_record). Schema v87 built invoices, aid and plans, and
           nothing could mark any of it paid: `paid_minor` moved only in tests.
           A family pays the academy by bank transfer, cash or POS today, so the
           office records what arrived — one row per payment, never an edited
           total, so a statement can list each receipt and an auditor can add
           them up. A mistake is VOIDED with a reason, never deleted. */
        91 => "
            CREATE TABLE IF NOT EXISTS fee_payments (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              invoice_id    INTEGER NOT NULL REFERENCES fee_invoices(id) ON DELETE CASCADE,
              amount_minor  INTEGER NOT NULL,
              currency      TEXT    NOT NULL,
              method        TEXT    NOT NULL DEFAULT 'transfer'
                            CHECK(method IN ('transfer','cash','pos','card','other')),
              reference     TEXT,
              received_on   TEXT    NOT NULL,
              note          TEXT,
              status        TEXT    NOT NULL DEFAULT 'recorded'
                            CHECK(status IN ('recorded','void')),
              void_reason   TEXT,
              voided_at     TEXT,
              recorded_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at    TEXT NOT NULL DEFAULT (datetime('now')),
              CHECK(amount_minor > 0 AND amount_minor = CAST(amount_minor AS INTEGER)),
              CHECK(length(currency) = 3),
              CHECK(status <> 'void' OR void_reason IS NOT NULL)
            );
            CREATE INDEX IF NOT EXISTS idx_feepay_invoice ON fee_payments(invoice_id, status);
        ",
        /* v92 — a guardian pays a fee invoice ONLINE (fees.php mam_fee_online_start).
         * The gateway charge is a `payments` row like every other (purpose='fee'),
         * so verify and both webhooks settle it through the one shared path. These
         * three say what it was FOR: which invoice, how much of it in the invoice's
         * own currency (the naira charge is a conversion and must never be what is
         * credited), and who pressed Pay — the receipt goes to them. No FK on the
         * invoice: `payments` is the money ledger and outlives anything it paid. */
        92 => "
            ALTER TABLE payments ADD COLUMN fee_invoice_id INTEGER;
            ALTER TABLE payments ADD COLUMN fee_minor INTEGER;
            ALTER TABLE payments ADD COLUMN fee_payer_id INTEGER;
            CREATE INDEX IF NOT EXISTS idx_pay_fee_invoice ON payments(fee_invoice_id);
        ",
        /* v93 — the two halves of fee money that used to live in an inbox and an
         * audit log (fees.php, HANDOFF §4v; the rules are NGG's offline_payments
         * and unapplied_payments, which learnt them first).
         *
         * fee_transfer_claims: a guardian says "I paid by transfer" and attaches
         * the slip. The bank instructions already told families to EMAIL proof and
         * promised confirmation in 24 hours; nothing recorded either. The office
         * confirms by retyping the reference and amount from the bank statement.
         * The evidence hash is UNIQUE — one slip is evidence for one payment, ever.
         *
         * fee_online_unapplied: money an online charge took beyond what was still
         * owed when it cleared. It was a note and a "to refund" figure with no way
         * to say it had been dealt with; now somebody resolves it. */
        93 => "
            CREATE TABLE IF NOT EXISTS fee_transfer_claims (
              id              INTEGER PRIMARY KEY AUTOINCREMENT,
              invoice_id      INTEGER NOT NULL REFERENCES fee_invoices(id) ON DELETE CASCADE,
              parent_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,
              amount_minor    INTEGER NOT NULL,
              currency        TEXT    NOT NULL,
              payer_name      TEXT    NOT NULL,
              bank_name       TEXT    NOT NULL,
              reference       TEXT    NOT NULL,
              reference_key   TEXT    NOT NULL,
              paid_on         TEXT    NOT NULL,
              evidence_sha256 TEXT    NOT NULL UNIQUE,
              evidence_file   TEXT    NOT NULL,
              evidence_type   TEXT    NOT NULL,
              evidence_bytes  INTEGER NOT NULL,
              note            TEXT,
              status          TEXT    NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','verified','rejected')),
              reject_reason   TEXT,
              decided_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
              decided_at      TEXT,
              fee_payment_id  INTEGER REFERENCES fee_payments(id) ON DELETE SET NULL,
              created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
              CHECK(amount_minor > 0 AND amount_minor = CAST(amount_minor AS INTEGER)),
              CHECK(status <> 'rejected' OR reject_reason IS NOT NULL)
            );
            CREATE INDEX IF NOT EXISTS idx_feeclaim_invoice ON fee_transfer_claims(invoice_id, status);
            CREATE INDEX IF NOT EXISTS idx_feeclaim_refkey ON fee_transfer_claims(reference_key);
            CREATE TABLE IF NOT EXISTS fee_online_unapplied (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              reference   TEXT    NOT NULL UNIQUE,
              invoice_id  INTEGER REFERENCES fee_invoices(id) ON DELETE SET NULL,
              over_minor  INTEGER NOT NULL,
              currency    TEXT    NOT NULL,
              status      TEXT    NOT NULL DEFAULT 'open' CHECK(status IN ('open','refunded','applied','dismissed')),
              note        TEXT,
              applied_invoice_id INTEGER REFERENCES fee_invoices(id) ON DELETE SET NULL,
              resolved_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              resolved_at TEXT,
              created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
              CHECK(over_minor > 0 AND over_minor = CAST(over_minor AS INTEGER))
            );
        ",
        /* v94 — member records (members.php, HANDOFF §4w; the rules are NGG's).
         * member_status_log: every status change, with who, when and WHY. A
         * suspension used to be one UPDATE and an audit line with no reason, so
         * "why can this child not sign in" had no answer anywhere.
         * member_notes: staff-only notes on a PERSON. Notes existed only on an
         * application, so anything learnt after enrolment had nowhere to live.
         * Append-only, like the log: a note is a record of what was known when. */
        94 => "
            CREATE TABLE IF NOT EXISTS member_status_log (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              from_status TEXT,
              to_status   TEXT    NOT NULL,
              reason      TEXT,
              changed_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_mstatus_user ON member_status_log(user_id, id);
            CREATE TABLE IF NOT EXISTS member_notes (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              body       TEXT    NOT NULL,
              author_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at TEXT    NOT NULL DEFAULT (datetime('now')),
              CHECK(length(body) > 0)
            );
            CREATE INDEX IF NOT EXISTS idx_mnotes_user ON member_notes(user_id, id);
        ",
        /* v95 — one row per bulk run on the members roster (members.php). It is
         * NGG's "one audit event per run" and also where the per-row verdicts
         * live: the console redirects after a POST, and a run where 28 of 40 were
         * refused must be able to say which 28 and why on the screen it lands on. */
        95 => "
            CREATE TABLE IF NOT EXISTS member_bulk_runs (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              action       TEXT    NOT NULL,
              actor_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
              requested    INTEGER NOT NULL DEFAULT 0,
              applied      INTEGER NOT NULL DEFAULT 0,
              unchanged    INTEGER NOT NULL DEFAULT 0,
              refused      INTEGER NOT NULL DEFAULT 0,
              results_json TEXT    NOT NULL,
              created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
            );
        ",
        /* v96 — the guardian's EMAIL on a year-round application. The form
         * promised a guardian "their own portal account once you are enrolled"
         * and collected only a name and a phone, so no account could ever be
         * made and every under-16 reached the office's "no guardian" list. */
        96 => "ALTER TABLE applications ADD COLUMN parent_email TEXT;",
        /* v97 — lessons, ported from NGG's curriculum (api/lib/lessons.php).
         * A `modules` row (one course · level · week) becomes a LESSON: a draft
         * → published → retired life, a version, objectives and a pass mark,
         * with questions (module_checks), materials (module_materials) and one
         * progress row per student (module_progress). Before this nothing
         * recorded a student against the syllabus at all — per-student progress
         * existed only on cohort `lessons`, which nothing links to a module.
         * Existing rows default to `published` so no week a family can see today
         * disappears; a NEW lesson starts as a draft. */
        97 => "
            ALTER TABLE modules ADD COLUMN status TEXT NOT NULL DEFAULT 'published';
            ALTER TABLE modules ADD COLUMN summary TEXT;
            ALTER TABLE modules ADD COLUMN objectives TEXT;
            ALTER TABLE modules ADD COLUMN duration_mins INTEGER;
            ALTER TABLE modules ADD COLUMN pass_mark INTEGER NOT NULL DEFAULT 60;
            ALTER TABLE modules ADD COLUMN version INTEGER NOT NULL DEFAULT 1;
            ALTER TABLE modules ADD COLUMN published_at TEXT;
            ALTER TABLE modules ADD COLUMN updated_at TEXT;
            ALTER TABLE modules ADD COLUMN updated_by INTEGER;
            CREATE TABLE IF NOT EXISTS module_checks (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              module_id  INTEGER NOT NULL REFERENCES modules(id) ON DELETE CASCADE,
              kind       TEXT    NOT NULL,
              prompt     TEXT    NOT NULL,
              options    TEXT,
              answer     TEXT,
              whys       TEXT,
              rubric     TEXT,
              points     INTEGER NOT NULL DEFAULT 1,
              position   INTEGER NOT NULL DEFAULT 1,
              created_at TEXT    NOT NULL DEFAULT (datetime('now')),
              CHECK(kind IN ('mcq','order','short','task','reflection','evidence')),
              CHECK(points BETWEEN 0 AND 100),
              CHECK(length(prompt) > 0)
            );
            CREATE INDEX IF NOT EXISTS idx_mchecks_module ON module_checks(module_id, position);
            CREATE TABLE IF NOT EXISTS module_materials (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              module_id  INTEGER NOT NULL REFERENCES modules(id) ON DELETE CASCADE,
              kind       TEXT    NOT NULL DEFAULT 'link',
              title      TEXT    NOT NULL,
              url        TEXT    NOT NULL,
              guide      TEXT,
              position   INTEGER NOT NULL DEFAULT 1,
              status     TEXT    NOT NULL DEFAULT 'approved',
              created_at TEXT    NOT NULL DEFAULT (datetime('now')),
              CHECK(status IN ('approved','pending','rejected'))
            );
            CREATE INDEX IF NOT EXISTS idx_mmat_module ON module_materials(module_id, position);
            CREATE TABLE IF NOT EXISTS module_progress (
              id             INTEGER PRIMARY KEY AUTOINCREMENT,
              module_id      INTEGER NOT NULL REFERENCES modules(id) ON DELETE CASCADE,
              student_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              status         TEXT    NOT NULL DEFAULT 'started',
              module_version INTEGER NOT NULL DEFAULT 1,
              score          REAL,
              max_score      REAL,
              best_pct       REAL,
              attempts       INTEGER NOT NULL DEFAULT 0,
              answers        TEXT,
              marks          TEXT,
              feedback       TEXT,
              pending        INTEGER NOT NULL DEFAULT 0,
              marked_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
              confirmed_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              started_at     TEXT    NOT NULL DEFAULT (datetime('now')),
              completed_at   TEXT,
              reviewed_at    TEXT,
              updated_at     TEXT,
              UNIQUE(module_id, student_id),
              CHECK(status IN ('started','attempted','submitted','completed')),
              CHECK(pending IN (0,1))
            );
            CREATE INDEX IF NOT EXISTS idx_mprog_student ON module_progress(student_id);
            CREATE INDEX IF NOT EXISTS idx_mprog_pending ON module_progress(pending, module_id);
        ",
        /* v98 — lessons, phase 2 (api/lib/lessons-learn.php, api/lib/lesson-exams.php):
         * private notes and "saved for later", one-tap feedback on how a lesson
         * landed, where a student is placed on a course, timed exams and their
         * appeals, and the log that makes a deadline reminder or an overdue fine
         * happen once. Material review needs no table: module_materials already
         * carries a status (v97); v98 adds who sent it and why it was refused. */
        98 => "
            ALTER TABLE module_materials ADD COLUMN submitted_by INTEGER;
            ALTER TABLE module_materials ADD COLUMN reviewed_by INTEGER;
            ALTER TABLE module_materials ADD COLUMN reviewed_at TEXT;
            ALTER TABLE module_materials ADD COLUMN review_note TEXT;
            CREATE TABLE IF NOT EXISTS lesson_notes (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              module_id  INTEGER NOT NULL REFERENCES modules(id) ON DELETE CASCADE,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              note       TEXT,
              note_at    TEXT,
              saved      INTEGER NOT NULL DEFAULT 0,
              saved_at   TEXT,
              opened_at  TEXT,
              opens      INTEGER NOT NULL DEFAULT 0,
              UNIQUE(module_id, student_id),
              CHECK(saved IN (0,1))
            );
            CREATE TABLE IF NOT EXISTS lesson_feedback (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              module_id  INTEGER NOT NULL REFERENCES modules(id) ON DELETE CASCADE,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              feel       TEXT NOT NULL,
              body       TEXT,
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              updated_at TEXT,
              UNIQUE(module_id, student_id),
              CHECK(feel IN ('got','mostly','lost'))
            );
            CREATE TABLE IF NOT EXISTS lesson_placements (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              course_id  INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
              level_id   INTEGER NOT NULL REFERENCES levels(id) ON DELETE CASCADE,
              placed_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
              reason     TEXT,
              placed_at  TEXT NOT NULL DEFAULT (datetime('now')),
              UNIQUE(student_id, course_id)
            );
            CREATE TABLE IF NOT EXISTS lesson_sittings (
              id             INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              course_id      INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
              module_id      INTEGER REFERENCES modules(id) ON DELETE SET NULL,
              kind           TEXT NOT NULL DEFAULT 'session',
              items          TEXT NOT NULL,
              answers        TEXT,
              marks          TEXT,
              score          REAL,
              max_score      REAL,
              pct            REAL,
              band           TEXT,
              status         TEXT NOT NULL DEFAULT 'open',
              pending        INTEGER NOT NULL DEFAULT 0,
              time_limit_sec INTEGER NOT NULL,
              pledged_at     TEXT,
              started_at     TEXT NOT NULL DEFAULT (datetime('now')),
              deadline       TEXT NOT NULL,
              submitted_at   TEXT,
              marked_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
              appeal_text    TEXT,
              appealed_at    TEXT,
              appeal_outcome TEXT,
              appeal_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
              updated_at     TEXT,
              CHECK(kind IN ('session','trial')),
              CHECK(status IN ('open','submitted','marked')),
              CHECK(pending IN (0,1))
            );
            CREATE INDEX IF NOT EXISTS idx_sit_student ON lesson_sittings(student_id, kind, course_id);
            CREATE INDEX IF NOT EXISTS idx_sit_pending ON lesson_sittings(pending, status);
            CREATE TABLE IF NOT EXISTS lesson_deadline_log (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              module_id  INTEGER NOT NULL REFERENCES modules(id) ON DELETE CASCADE,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              kind       TEXT NOT NULL,
              period     TEXT NOT NULL,
              charge_id  INTEGER,
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              UNIQUE(module_id, student_id, kind, period),
              CHECK(kind IN ('reminder','fine'))
            );
            -- A lesson saved without a kind was stored as '' (fixed in lessons.php): it is a module.
            UPDATE modules SET kind = 'module' WHERE kind = '' OR kind IS NULL;
        ",
        99 => "
            CREATE TABLE IF NOT EXISTS media_objects (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              object_key    TEXT NOT NULL UNIQUE,
              owner_id      INTEGER REFERENCES users(id) ON DELETE SET NULL,
              purpose       TEXT NOT NULL,
              kind          TEXT NOT NULL,
              content_type  TEXT NOT NULL,
              bytes         INTEGER NOT NULL,
              provider      TEXT NOT NULL,
              status        TEXT NOT NULL DEFAULT 'pending',
              scope_type    TEXT,
              scope_id      INTEGER,
              original_name TEXT,
              meta          TEXT,
              created_at    TEXT NOT NULL,
              uploaded_at   TEXT,
              deleted_at    TEXT,
              CHECK(provider IN ('bucket','local')),
              CHECK(status IN ('pending','uploaded','deleted')),
              CHECK(kind IN ('video','audio','image','pdf','doc'))
            );
            CREATE INDEX IF NOT EXISTS idx_media_owner ON media_objects(owner_id, status);
            CREATE INDEX IF NOT EXISTS idx_media_scope ON media_objects(scope_type, scope_id);
            CREATE TABLE IF NOT EXISTS class_spaces (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              cohort_id    INTEGER UNIQUE REFERENCES cohorts(id) ON DELETE SET NULL,
              title        TEXT NOT NULL,
              about        TEXT,
              visibility   TEXT NOT NULL DEFAULT 'class',
              review_media INTEGER NOT NULL DEFAULT 1,
              students_comment INTEGER NOT NULL DEFAULT 1,
              status       TEXT NOT NULL DEFAULT 'open',
              created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at   TEXT NOT NULL DEFAULT (datetime('now')),
              updated_at   TEXT,
              CHECK(visibility IN ('class','private')),
              CHECK(review_media IN (0,1)),
              CHECK(students_comment IN (0,1)),
              CHECK(status IN ('open','archived'))
            );
            CREATE TABLE IF NOT EXISTS space_members (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              space_id   INTEGER NOT NULL REFERENCES class_spaces(id) ON DELETE CASCADE,
              user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              role       TEXT NOT NULL DEFAULT 'student',
              status     TEXT NOT NULL DEFAULT 'active',
              added_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              UNIQUE(space_id, user_id),
              CHECK(role IN ('student','teacher')),
              CHECK(status IN ('active','removed'))
            );
            CREATE INDEX IF NOT EXISTS idx_spm_user ON space_members(user_id, status);
            CREATE TABLE IF NOT EXISTS space_tasks (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              space_id    INTEGER NOT NULL REFERENCES class_spaces(id) ON DELETE CASCADE,
              title       TEXT NOT NULL,
              brief       TEXT,
              wants       TEXT NOT NULL DEFAULT 'video',
              share       TEXT NOT NULL DEFAULT 'class',
              points      INTEGER NOT NULL DEFAULT 10,
              due_at      TEXT,
              status      TEXT NOT NULL DEFAULT 'open',
              created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at  TEXT NOT NULL DEFAULT (datetime('now')),
              CHECK(wants IN ('video','audio','any')),
              CHECK(share IN ('class','teacher')),
              CHECK(points BETWEEN 1 AND 100),
              CHECK(status IN ('open','closed'))
            );
            CREATE TABLE IF NOT EXISTS space_posts (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              space_id    INTEGER NOT NULL REFERENCES class_spaces(id) ON DELETE CASCADE,
              author_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              kind        TEXT NOT NULL DEFAULT 'post',
              task_id     INTEGER REFERENCES space_tasks(id) ON DELETE SET NULL,
              body        TEXT,
              media_id    INTEGER REFERENCES media_objects(id) ON DELETE SET NULL,
              audience    TEXT NOT NULL DEFAULT 'class',
              status      TEXT NOT NULL DEFAULT 'visible',
              hold_reason TEXT,
              score       INTEGER,
              max_score   INTEGER,
              feedback    TEXT,
              reviewed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
              reviewed_at TEXT,
              created_at  TEXT NOT NULL DEFAULT (datetime('now')),
              edited_at   TEXT,
              CHECK(kind IN ('post','submission','notice')),
              CHECK(audience IN ('class','teacher')),
              CHECK(status IN ('visible','held','removed'))
            );
            CREATE INDEX IF NOT EXISTS idx_sp_space ON space_posts(space_id, status, id);
            CREATE INDEX IF NOT EXISTS idx_sp_task ON space_posts(task_id, author_id);
            CREATE TABLE IF NOT EXISTS space_comments (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              post_id     INTEGER NOT NULL REFERENCES space_posts(id) ON DELETE CASCADE,
              author_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              body        TEXT NOT NULL,
              at_sec      REAL,
              status      TEXT NOT NULL DEFAULT 'visible',
              hold_reason TEXT,
              created_at  TEXT NOT NULL DEFAULT (datetime('now')),
              CHECK(status IN ('visible','held','removed'))
            );
            CREATE INDEX IF NOT EXISTS idx_sc_post ON space_comments(post_id, status);
            CREATE TABLE IF NOT EXISTS space_reactions (
              post_id    INTEGER NOT NULL REFERENCES space_posts(id) ON DELETE CASCADE,
              user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              kind       TEXT NOT NULL,
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              PRIMARY KEY (post_id, user_id, kind),
              CHECK(kind IN ('clap','heart','star'))
            );
            CREATE TABLE IF NOT EXISTS space_reports (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              post_id     INTEGER REFERENCES space_posts(id) ON DELETE CASCADE,
              comment_id  INTEGER REFERENCES space_comments(id) ON DELETE CASCADE,
              reporter_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
              reason      TEXT NOT NULL,
              note        TEXT,
              status      TEXT NOT NULL DEFAULT 'open',
              outcome     TEXT,
              handled_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at  TEXT NOT NULL DEFAULT (datetime('now')),
              handled_at  TEXT,
              CHECK(reason IN ('unkind','unsafe','private_info','spam','other')),
              CHECK(status IN ('open','closed'))
            );
            CREATE TABLE IF NOT EXISTS safeguarding_concerns (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              raised_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              about_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
              source      TEXT NOT NULL DEFAULT 'person',
              ref_type    TEXT,
              ref_id      INTEGER,
              category    TEXT NOT NULL,
              urgency     TEXT NOT NULL DEFAULT 'normal',
              body        TEXT NOT NULL,
              status      TEXT NOT NULL DEFAULT 'open',
              assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
              outcome     TEXT,
              created_at  TEXT NOT NULL DEFAULT (datetime('now')),
              acknowledged_at TEXT,
              closed_at   TEXT,
              CHECK(source IN ('person','automatic')),
              CHECK(category IN ('wellbeing','conduct','contact','content','online_class','other')),
              CHECK(urgency IN ('normal','urgent')),
              CHECK(status IN ('open','assessing','referred','closed'))
            );
            CREATE INDEX IF NOT EXISTS idx_sgc_status ON safeguarding_concerns(status, urgency);
            CREATE TABLE IF NOT EXISTS safeguarding_notes (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              concern_id INTEGER NOT NULL REFERENCES safeguarding_concerns(id) ON DELETE CASCADE,
              author_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
              body       TEXT NOT NULL,
              created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        ",
        100 => "
            ALTER TABLE fee_invoices ADD COLUMN reviewed_by INTEGER;
            ALTER TABLE fee_invoices ADD COLUMN reviewed_at TEXT;
            ALTER TABLE fee_invoices ADD COLUMN review_note TEXT;
            ALTER TABLE fee_invoices ADD COLUMN review_sig TEXT;
            CREATE TABLE IF NOT EXISTS fee_invoice_queries (
              id                   INTEGER PRIMARY KEY AUTOINCREMENT,
              invoice_id           INTEGER NOT NULL REFERENCES fee_invoices(id) ON DELETE CASCADE,
              raised_by            INTEGER REFERENCES users(id) ON DELETE SET NULL,
              kind                 TEXT NOT NULL,
              message              TEXT NOT NULL,
              proposed_minor       INTEGER,
              proposed_instalments INTEGER,
              status               TEXT NOT NULL DEFAULT 'open',
              office_reply         TEXT,
              counter_minor        INTEGER,
              counter_instalments  INTEGER,
              decided_by           INTEGER REFERENCES users(id) ON DELETE SET NULL,
              decided_at           TEXT,
              family_response_at   TEXT,
              created_at           TEXT NOT NULL,
              CHECK(kind IN ('question','amount','plan')),
              CHECK(status IN ('open','answered','accepted','countered','declined','withdrawn'))
            );
            CREATE INDEX IF NOT EXISTS idx_fiq_invoice ON fee_invoice_queries(invoice_id, status);
            CREATE TABLE IF NOT EXISTS fee_discount_codes (
              id              INTEGER PRIMARY KEY AUTOINCREMENT,
              code            TEXT NOT NULL UNIQUE,
              label           TEXT NOT NULL,
              pct             REAL,
              amount_minor    INTEGER,
              currency        TEXT,
              max_uses        INTEGER,
              used            INTEGER NOT NULL DEFAULT 0,
              once_per_family INTEGER NOT NULL DEFAULT 1,
              starts_on       TEXT,
              ends_on         TEXT,
              active          INTEGER NOT NULL DEFAULT 1,
              created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at      TEXT NOT NULL,
              CHECK((pct IS NULL) <> (amount_minor IS NULL)),
              CHECK(active IN (0,1)),
              CHECK(once_per_family IN (0,1))
            );
            CREATE TABLE IF NOT EXISTS fee_discount_redemptions (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              code_id      INTEGER NOT NULL REFERENCES fee_discount_codes(id) ON DELETE CASCADE,
              invoice_id   INTEGER NOT NULL REFERENCES fee_invoices(id) ON DELETE CASCADE,
              amount_minor INTEGER NOT NULL,
              applied_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at   TEXT NOT NULL,
              UNIQUE(code_id, invoice_id)
            );
        ",
        101 => "
            CREATE TABLE IF NOT EXISTS live_events (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              session_id INTEGER NOT NULL REFERENCES class_sessions(id) ON DELETE CASCADE,
              user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
              kind       TEXT NOT NULL,
              at         TEXT NOT NULL,
              source     TEXT NOT NULL,
              idem       TEXT NOT NULL UNIQUE,
              raw        TEXT,
              CHECK(kind IN ('opened','joined','left','beat')),
              CHECK(source IN ('page','beat','webhook'))
            );
            CREATE INDEX IF NOT EXISTS idx_live_session ON live_events(session_id, user_id, at);
        ",
        102 => "
            ALTER TABLE module_checks ADD COLUMN difficulty INTEGER NOT NULL DEFAULT 3;
            CREATE TABLE IF NOT EXISTS check_calibration (
              check_id INTEGER PRIMARY KEY REFERENCES module_checks(id) ON DELETE CASCADE,
              b        REAL NOT NULL,
              answered INTEGER NOT NULL DEFAULT 0,
              correct  INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS student_ability (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              course_id  INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
              theta      REAL NOT NULL DEFAULT 0,
              answered   INTEGER NOT NULL DEFAULT 0,
              updated_at TEXT,
              UNIQUE(student_id, course_id)
            );
            CREATE TABLE IF NOT EXISTS mastery_snapshots (
              id           INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              week_of      TEXT NOT NULL,
              coverage_pct REAL NOT NULL DEFAULT 0,
              band_low     INTEGER,
              band_high    INTEGER,
              level_label  TEXT,
              components   TEXT,
              taken_at     TEXT NOT NULL,
              UNIQUE(student_id, week_of)
            );
            CREATE TABLE IF NOT EXISTS student_goals (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              kind        TEXT NOT NULL,
              title       TEXT NOT NULL,
              target      INTEGER,
              due_on      TEXT,
              status      TEXT NOT NULL DEFAULT 'active',
              set_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at  TEXT NOT NULL,
              updated_at  TEXT,
              achieved_at TEXT,
              CHECK(kind IN ('practice_week','lessons','handins','custom')),
              CHECK(status IN ('active','achieved','dropped'))
            );
            CREATE INDEX IF NOT EXISTS idx_goals_student ON student_goals(student_id, status);
        ",
        103 => "
            ALTER TABLE safeguarding_concerns ADD COLUMN escalated_at TEXT;
            CREATE TABLE IF NOT EXISTS incidents (
              id            INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              kind          TEXT NOT NULL,
              happened_at   TEXT NOT NULL,
              place         TEXT,
              what          TEXT NOT NULL,
              action_taken  TEXT,
              family_note   TEXT,
              status        TEXT NOT NULL DEFAULT 'open',
              outcome       TEXT,
              concern_id    INTEGER REFERENCES safeguarding_concerns(id) ON DELETE SET NULL,
              recorded_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
              told_at       TEXT,
              told_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
              told_channels TEXT,
              closed_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
              closed_at     TEXT,
              created_at    TEXT NOT NULL,
              CHECK(kind IN ('first_aid','health','behaviour','collection','lost_item','safeguarding','other')),
              CHECK(status IN ('open','closed'))
            );
            CREATE INDEX IF NOT EXISTS idx_incidents_student ON incidents(student_id, status);
        ",
        // v104 — authorised collectors and the sign-out record (safeguarding.php,
        // ported from NGG's member_collectors — and CHECKED at sign-out, which NGG never did).
        104 => "
            CREATE TABLE IF NOT EXISTS authorised_collectors (
              id          INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              name        TEXT NOT NULL,
              relation    TEXT,
              phone       TEXT,
              added_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
              created_at  TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_collectors_student ON authorised_collectors(student_id);
            CREATE TABLE IF NOT EXISTS collections (
              id             INTEGER PRIMARY KEY AUTOINCREMENT,
              student_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              session_id     INTEGER REFERENCES class_sessions(id) ON DELETE SET NULL,
              collector_id   INTEGER REFERENCES authorised_collectors(id) ON DELETE SET NULL,
              collector_name TEXT NOT NULL,
              verdict        TEXT NOT NULL,
              reason         TEXT,
              concern_id     INTEGER REFERENCES safeguarding_concerns(id) ON DELETE SET NULL,
              recorded_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
              collected_at   TEXT NOT NULL,
              CHECK(verdict IN ('authorised','not_listed','no_list','adult'))
            );
            CREATE INDEX IF NOT EXISTS idx_collections_student ON collections(student_id, collected_at);
            CREATE INDEX IF NOT EXISTS idx_collections_session ON collections(session_id);
        ",
        /* v105 — one collation for every MySQL table (db/schema-mysql.sql). SQLite
         * has no collations, so there is nothing to do here: the version moves so
         * both engines agree on the target. */
        105 => "SELECT 1;",
        // v106 — Paystack scenario guards (api/lib/payment-guards.php).
        // payment_attempts: one row per gateway reference opened for a payment, so
        //   a retry after closing the checkout gets a fresh reference (Paystack
        //   refuses one it has seen) and every attempt resolves to the ONE payment.
        // payment_events: what happened to a payment beyond settling (test/live
        //   mismatch, a refund, a dispute, money for a closed target), for the office.
        // payments.gateway_state: NULL in the normal case; refund_due, refund_pending,
        //   refunded, refund_failed, disputed, dispute_won, dispute_lost otherwise
        //   (MAM_PAY_GATEWAY_STATES; SQLite cannot add a CHECK by ALTER).
        106 => "
            ALTER TABLE payments ADD COLUMN gateway_state TEXT;
            CREATE TABLE IF NOT EXISTS payment_attempts (
              id                INTEGER PRIMARY KEY AUTOINCREMENT,
              payment_id        INTEGER NOT NULL REFERENCES payments(id) ON DELETE CASCADE,
              reference         TEXT    NOT NULL UNIQUE,
              authorization_url TEXT,
              access_code       TEXT,
              created_at        TEXT    NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_payatt_payment ON payment_attempts(payment_id, created_at);
            CREATE TABLE IF NOT EXISTS payment_events (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              payment_id INTEGER REFERENCES payments(id) ON DELETE SET NULL,
              reference  TEXT,
              kind       TEXT    NOT NULL,
              detail     TEXT,
              created_at TEXT    NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_payev_payment ON payment_events(payment_id, created_at);
            CREATE INDEX IF NOT EXISTS idx_pay_gateway_state ON payments(gateway_state);
        ",
    ];

    foreach ($migrations as $version => $sql) {
        if ($version <= $current) continue;
        // A migration tagged `@no-tx` runs OUTSIDE the wrapping transaction so it
        // can toggle `PRAGMA foreign_keys` (a no-op inside a transaction) — needed
        // for a safe table rebuild that widens a CHECK on a referenced table:
        // with FK enforcement on, DROP TABLE performs an implicit, cascading
        // DELETE. Such migrations manage foreign_keys OFF→ON themselves.
        if (strpos($sql, '@no-tx') !== false) {
            $db->exec($sql);
            $db->prepare('INSERT INTO schema_version (version) VALUES (?)')->execute([$version]);
            continue;
        }
        $db->beginTransaction();
        try {
            $db->exec($sql);
            $db->prepare('INSERT INTO schema_version (version) VALUES (?)')->execute([$version]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}

function mam_db_insert(string $table, array $data): int {
    $cols = array_keys($data);
    $placeholders = array_fill(0, count($cols), '?');
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
    $stmt = mam_db()->prepare($sql);
    $stmt->execute(array_values($data));
    return (int)mam_db()->lastInsertId();
}

/**
 * Cross-driver "upsert" (insert-or-update-on-duplicate-key).
 *
 * SQLite/Postgres speak `ON CONFLICT(col) DO UPDATE SET ...` while MySQL
 * speaks `ON DUPLICATE KEY UPDATE ...`. Hardcoding either one breaks the
 * other; since this app runs on SQLite (default) AND MySQL (production via
 * DB_DSN), every upsert must adapt to the live driver. The on-conflict SET
 * values are bound as parameters (not `excluded.`/`VALUES()`), so the caller
 * just passes the values it already has — portable and injection-safe.
 *
 *   $insert   column => value to insert
 *   $conflict unique/PK column(s) (named for SQLite; ignored by MySQL)
 *   $update   column => value to SET when the row already exists
 */
function mam_db_upsert(string $table, array $insert, array $conflict, array $update): void {
    $cols = array_keys($insert);
    $ph   = implode(',', array_fill(0, count($cols), '?'));
    $vals = array_values($insert);

    $set = [];
    foreach ($update as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; }
    $setSql = implode(', ', $set);

    $head = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . $ph . ') ';
    if (mam_db_driver() === 'mysql') {
        $sql = $head . 'ON DUPLICATE KEY UPDATE ' . $setSql;
    } else {
        $sql = $head . 'ON CONFLICT(' . implode(',', $conflict) . ') DO UPDATE SET ' . $setSql;
    }
    mam_db()->prepare($sql)->execute($vals);
}

function mam_db_update(string $table, array $data, array $where): int {
    $set = []; $vals = [];
    foreach ($data as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; }
    $whereParts = []; foreach ($where as $k => $v) { $whereParts[] = "$k = ?"; $vals[] = $v; }
    $sql = 'UPDATE ' . $table . ' SET ' . implode(',', $set) . ' WHERE ' . implode(' AND ', $whereParts);
    $stmt = mam_db()->prepare($sql);
    $stmt->execute($vals);
    return $stmt->rowCount();
}

function mam_db_row(string $sql, array $params = []): ?array {
    $stmt = mam_db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function mam_db_all(string $sql, array $params = []): array {
    $stmt = mam_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
