<?php
/**
 * POST /api/admin/heal
 *
 * On-demand self-heal for production MySQL installs.
 *
 * The MySQL migrator already runs criticalRepairs (column existence checks +
 * ALTER TABLE) and schema verification on EVERY connect, so a plain page load
 * after a failed deploy already auto-heals. This endpoint exposes the result
 * of that same check on-demand so an operator can confirm the schema is healthy
 * without reading raw log files, and trigger a re-check after a manual DB
 * intervention. Settings-cap required (same as the migrate endpoint).
 *
 * Response:
 *   { ok:true, driver, schema_version, target, missing:[], idempotency_purged:N }
 *
 * `missing` lists any table.column pairs that are absent from the DB — an
 * empty array means the schema is fully applied. The auto-repair already ran
 * on the connect that serviced this request, so any column that criticalRepairs
 * covers will have been added before this check even runs.
 */
declare(strict_types=1);

mam_require_cap('settings');

$driver = mam_db_driver();

if ($driver !== 'mysql') {
    $ver = (int)(mam_db()->query('SELECT IFNULL(MAX(version),0) FROM schema_version')->fetchColumn() ?: 0);
    mam_ok([
        'driver'         => $driver,
        'schema_version' => $ver,
        'target'         => MAM_SCHEMA_TARGET,
        'missing'        => [],
        'note'           => 'Self-heal column checks only apply to MySQL. SQLite auto-migrates fully on every connect.',
    ]);
}

// Purge stale idempotency cache entries (housekeeping; safe to run here).
$purged = mam_idempotency_purge();

// Re-run the column verification the migrator already ran on this connect.
// criticalRepairs (in mam_db_migrate_mysql) will have already fixed any missing
// columns from its list; this check catches anything outside that list.
$db = mam_db();
$ver = 0;
try { $ver = (int)($db->query('SELECT IFNULL(MAX(version),0) FROM schema_version')->fetchColumn() ?: 0); } catch (\Throwable $e) {}

$required = [
    'applications'   => ['booking_fee_paid_at','program','headshot_url','session','idempotency_key','level','expression','extra_courses','courses_label','assignee_id','program_track','readiness_signal'],
    'payments'       => ['purpose'],
    'users'          => ['admin_tier','student_type','enrollment_ref'],
    'course_enrollments' => ['cohort_id'],
    'store_items'    => ['available_locations'],
    'surveys'        => ['kind','questions_json'],
    'progress_reports' => ['answers_json','session_id'],
    'class_sessions' => ['substitute_id','room','lesson_notes','rescheduled_from','cancellation_reason'],
    'class_waitlist' => ['position'],
    'assessments'    => ['score'],
    'results'        => ['grade'],
    'cohorts'        => ['schedule_days','schedule_time','session_duration'],
    'announcements'  => ['cohort_id'],
    'class_resources'=> ['cohort_id'],
    'idempotency_cache' => ['idem_key','scope','response_json','created_at'],
];

$missing = [];
foreach ($required as $table => $cols) {
    try {
        $have = $db->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $db->quote($table)
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($cols as $c) {
            if (!in_array($c, $have, true)) $missing[] = "$table.$c";
        }
    } catch (\Throwable $e) {
        $missing[] = "$table (query failed: " . $e->getMessage() . ")";
    }
}

mam_audit('heal', 'schema', null, ['missing' => $missing, 'version' => $ver]);

mam_ok([
    'driver'              => $driver,
    'schema_version'      => $ver,
    'target'              => MAM_SCHEMA_TARGET,
    'healthy'             => empty($missing),
    'missing'             => $missing,
    'idempotency_purged'  => $purged,
    'note'                => empty($missing)
        ? 'Schema is fully applied. All required columns are present.'
        : count($missing) . ' column(s) missing — criticalRepairs should have fixed them on this connect; check api/logs for errors.',
]);
