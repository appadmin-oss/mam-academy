<?php
/** Migrations — cold-start brings the SQLite schema to the current target + seeds. */
declare(strict_types=1);
t_group('migrations (cold start)');

$pdo = mam_db(); // first access runs all migrations inside a fresh temp DB
$v = (int)$pdo->query('SELECT IFNULL(MAX(version),0) FROM schema_version')->fetchColumn();
t_eq($v, MAM_SCHEMA_TARGET, 'schema migrated to the current target (v' . MAM_SCHEMA_TARGET . ')');

// v16 seeds.
foreach (['programs' => 5, 'levels' => 4, 'courses' => 12, 'assessment_types' => 5] as $tbl => $n) {
    t_eq((int)$pdo->query("SELECT COUNT(*) FROM $tbl")->fetchColumn(), $n, "$tbl seeded with $n rows");
}

// All v16–v45 tables exist and are queryable.
foreach (['user_roles', 'modules', 'instructor_assignments', 'course_enrollments', 'assessments', 'results', 'certificates', 'mail_log', 'enrolment_events', 'cohorts', 'ai_runs', 'class_sessions', 'session_attendance', 'submissions', 'feedback', 'application_drafts', 'events', 'jobs', 'application_events', 'application_notes', 'funnel_events', 'notifications', 'tool_scores', 'program_courses', 'student_feedback', 'surveys', 'survey_responses', 'support_tickets', 'ticket_messages', 'progress_report_tokens', 'class_waitlist', 'session_history'] as $tbl) {
    $ok = false;
    try { $pdo->query("SELECT COUNT(*) FROM $tbl"); $ok = true; } catch (Throwable $e) {}
    t_ok($ok, "table $tbl exists");
}

// v44 — enrollment_ref column on users
$ok = false;
try { $pdo->query("SELECT enrollment_ref FROM users LIMIT 0"); $ok = true; } catch (Throwable $e) {}
t_ok($ok, 'users.enrollment_ref column exists (v44)');

// v43 — lesson notes columns on class_sessions
foreach (['lesson_notes', 'topics', 'learning_outcomes'] as $col) {
    $ok = false;
    try { $pdo->query("SELECT $col FROM class_sessions LIMIT 0"); $ok = true; } catch (Throwable $e) {}
    t_ok($ok, "class_sessions.$col column exists (v43)");
}

// Configurable durations are data, not code (crash = 6 wk, exam wk 6).
$crash = $pdo->query("SELECT weeks, exam_week, certification FROM programs WHERE code='crash'")->fetch(PDO::FETCH_ASSOC);
t_eq((int)$crash['weeks'], 6, 'crash programme is 6 weeks');
t_eq((int)$crash['exam_week'], 6, 'crash exam in week 6');
t_eq($crash['certification'], 'participation', 'crash → participation certificate');

// v45 — enterprise scheduling columns
foreach (['substitute_id', 'rescheduled_from', 'cancellation_reason', 'room'] as $col) {
    $ok = false;
    try { $pdo->query("SELECT $col FROM class_sessions LIMIT 0"); $ok = true; } catch (Throwable $e) {}
    t_ok($ok, "class_sessions.$col column exists (v45)");
}
foreach (['schedule_days', 'schedule_time', 'session_duration'] as $col) {
    $ok = false;
    try { $pdo->query("SELECT $col FROM cohorts LIMIT 0"); $ok = true; } catch (Throwable $e) {}
    t_ok($ok, "cohorts.$col column exists (v45)");
}

// Idempotent: a second migrate pass leaves the version untouched.
mam_db_migrate($pdo);
t_eq((int)$pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn(), MAM_SCHEMA_TARGET, 're-running migrations stays at the target');
