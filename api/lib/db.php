<?php
/**
 * SQLite database wrapper + schema migrator.
 * Lazy connection; safe to require multiple times.
 */
declare(strict_types=1);

function mam_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $path = MAM_STORAGE . '/mam.db';
    if (!is_dir(MAM_STORAGE)) {
        @mkdir(MAM_STORAGE, 0750, true);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    mam_db_migrate($pdo);
    return $pdo;
}

function mam_db_migrate(PDO $db): void {
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
