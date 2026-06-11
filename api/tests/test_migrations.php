<?php
/** Migrations — cold-start brings the SQLite schema to the current target + seeds. */
declare(strict_types=1);
t_group('migrations (cold start)');

$pdo = mam_db(); // first access runs v1..v16 inside a fresh temp DB
$v = (int)$pdo->query('SELECT IFNULL(MAX(version),0) FROM schema_version')->fetchColumn();
t_eq($v, 16, 'schema migrated to v16');

// v16 seeds.
foreach (['programs' => 4, 'levels' => 4, 'courses' => 12, 'assessment_types' => 5] as $tbl => $n) {
    t_eq((int)$pdo->query("SELECT COUNT(*) FROM $tbl")->fetchColumn(), $n, "$tbl seeded with $n rows");
}

// All v16 tables exist and are queryable.
foreach (['user_roles', 'modules', 'instructor_assignments', 'course_enrollments', 'assessments', 'results', 'certificates'] as $tbl) {
    $ok = false;
    try { $pdo->query("SELECT COUNT(*) FROM $tbl"); $ok = true; } catch (Throwable $e) {}
    t_ok($ok, "table $tbl exists");
}

// Configurable durations are data, not code (crash = 6 wk, exam wk 6).
$crash = $pdo->query("SELECT weeks, exam_week, certification FROM programs WHERE code='crash'")->fetch(PDO::FETCH_ASSOC);
t_eq((int)$crash['weeks'], 6, 'crash programme is 6 weeks');
t_eq((int)$crash['exam_week'], 6, 'crash exam in week 6');
t_eq($crash['certification'], 'participation', 'crash → participation certificate');

// Idempotent: a second migrate pass leaves the version untouched.
mam_db_migrate($pdo);
t_eq((int)$pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn(), 16, 're-running migrations stays at v16');
