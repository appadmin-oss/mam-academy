<?php
/**
 * /api/admin/schedule  — Academy-wide session calendar (admin only)
 *
 *   GET ?from=YYYY-MM-DD&to=YYYY-MM-DD  → {sessions:[…], cohorts:[…], from, to}
 *   GET (no params)                     → current week (Mon–Sun)
 *
 * Returns all class_sessions in the range enriched with cohort / instructor /
 * substitute names so the front-end calendar can render without extra calls.
 */
declare(strict_types=1);
mam_require_role('admin');

$today = date('Y-m-d');
$from  = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['from']) ? (string)$_GET['from'] : null;
$to    = isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['to'])   ? (string)$_GET['to']   : null;

if (!$from || !$to) {
    // Default: current week Mon–Sun.
    $dow  = (int)date('N'); // 1=Mon … 7=Sun
    $from = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days'));
    $to   = date('Y-m-d', strtotime('+' . (7 - $dow) . ' days'));
}

$sessions = mam_lms_schedule_range($from, $to);
$cohorts  = mam_lms_cohorts();

mam_ok([
    'from'     => $from,
    'to'       => $to,
    'sessions' => $sessions,
    'cohorts'  => $cohorts,
]);
