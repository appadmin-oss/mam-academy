<?php
/**
 * POST /api/admin/migrate
 *
 * Force-runs MySQL migrations (idempotent). For SQLite the migrator already
 * runs on every cold start, so this endpoint is a no-op there. Returns the
 * before/after schema_version so the operator can confirm the upgrade.
 */
declare(strict_types=1);

$db = mam_db();
$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

$before = 0;
try { $before = (int)$db->query('SELECT IFNULL(MAX(version),0) FROM schema_version')->fetchColumn(); } catch (Throwable $e) {}

if ($driver === 'mysql') {
    mam_db_migrate_mysql($db);
} else {
    mam_db_migrate($db);
}

$after = 0;
try { $after = (int)$db->query('SELECT IFNULL(MAX(version),0) FROM schema_version')->fetchColumn(); } catch (Throwable $e) {}

mam_audit('db_migrate', 'system', null, ['driver' => $driver, 'from' => $before, 'to' => $after]);
mam_ok(['driver' => $driver, 'before' => $before, 'after' => $after, 'target' => 15]);
