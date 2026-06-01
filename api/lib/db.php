<?php
/**
 * SQLite database wrapper + schema migrator.
 * Lazy connection; safe to require multiple times.
 */
declare(strict_types=1);

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

    $target = 14;
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
