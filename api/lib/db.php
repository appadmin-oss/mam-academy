<?php
/**
 * SQLite database wrapper + schema migrator.
 * Lazy connection; safe to require multiple times.
 */
declare(strict_types=1);

/** Latest schema version. The single source of truth for the migrators, the
 *  admin overview banner and /api/diag — bump it together with a new
 *  migration in $migrations below + db/schema-mysql.sql. */
const MAM_SCHEMA_TARGET = 25;

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
        }
        // Migrations on non-SQLite drivers are applied manually from the
        // shipped schema file in db/. Skip auto-migrate here.
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
    try { $db->exec("CREATE TABLE IF NOT EXISTS schema_version (version INT PRIMARY KEY)"); } catch (Throwable $e) {}
    $current = 0;
    try { $current = (int)($db->query('SELECT IFNULL(MAX(version),0) FROM schema_version')->fetchColumn() ?: 0); } catch (Throwable $e) {}

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
            // key, 1091 = can't DROP — all expected when re-running idempotently.
            $expected = [1050, 1060, 1061, 1091, 1146];
            $code = (int)$e->errorInfo[1] ?? 0;
            if (!in_array($code, $expected, true)) {
                mam_log('warn', 'mysql_migration_stmt_failed', ['code' => $code, 'msg' => $e->getMessage(), 'stmt' => mb_substr($stmt, 0, 200)]);
            }
        }
    }
    $newVersion = 0;
    try { $newVersion = (int)$db->query('SELECT IFNULL(MAX(version),0) FROM schema_version')->fetchColumn(); } catch (Throwable $e) {}
    mam_log('info', 'mysql_migration_complete', ['from' => $current, 'to' => $newVersion, 'ran' => $ran, 'failed' => $failed]);
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
    ];

    foreach ($migrations as $version => $sql) {
        if ($version <= $current) continue;
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
