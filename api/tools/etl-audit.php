<?php
/**
 * MAM Academy — ETL convergence audit (LMS 2.0) · CLI, READ-ONLY.
 * ─────────────────────────────────────────────────────────────
 * Profiles how far the app's legacy models have converged onto the LMS 2.0
 * canonical ones, WITHOUT writing anything. This is Phase 0 of the staged ETL
 * plan: extract + profile coverage, surface unmappable rows, and give a
 * go/no-go read before any backfill or cutover is considered.
 *
 * Dualities checked:
 *   money        payments.amount            → payments.amount_usd_cents  (v61)
 *   assignments  per-student rows           → assignment_groups           (v63)
 *   enrolment    enrollments (classes)      → course_enrollments (cohorts)
 *   scheduling   classes                    → cohorts
 *   attendance   attendance (enrollment)    → session_attendance (session)
 *
 * Every metric is defensive: a missing table/column degrades to "n/a" rather
 * than aborting the audit (deployments behind on migrations are the norm).
 *
 *   php api/tools/etl-audit.php            # human-readable report
 *   php api/tools/etl-audit.php --json     # machine-readable
 *
 * Exit: 0 always (read-only diagnostics; never fails a pipeline).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); echo "This tool is CLI-only.\n"; exit(1); }
require_once __DIR__ . '/../lib/bootstrap.php';

$asJson = in_array('--json', array_slice($argv, 1), true);

/** COUNT(*) helper that degrades to null when the table/column is absent. */
$count = function (string $sql, array $args = []): ?int {
    try { return (int) (mam_db_row($sql, $args)['c'] ?? 0); }
    catch (\Throwable $e) { return null; }
};
$pct = fn(?int $covered, ?int $total): ?float =>
    ($covered === null || $total === null) ? null : ($total > 0 ? round($covered / $total * 100, 1) : 100.0);

$report = [];

// ── money ─────────────────────────────────────────────────────────────────
$succeeded = $count("SELECT COUNT(*) c FROM payments WHERE status='succeeded'");
$moneyGap  = $count("SELECT COUNT(*) c FROM payments WHERE status='succeeded' AND amount_usd_cents IS NULL");
$report['money'] = [
    'canonical'      => 'payments.amount_usd_cents (v61)',
    'total'          => $succeeded,
    'uncovered'      => $moneyGap,
    'coverage_pct'   => ($succeeded === null || $moneyGap === null) ? null : $pct($succeeded - $moneyGap, $succeeded),
    'action'         => ($moneyGap ?? 0) > 0 ? 'run tools/backfill-money.php' : 'converged',
];

// ── assignments → groups ───────────────────────────────────────────────────
$asgTotal = $count("SELECT COUNT(*) c FROM assignments");
$asgGap   = $count("SELECT COUNT(*) c FROM assignments WHERE group_id IS NULL");
$report['assignments'] = [
    'canonical'    => 'assignment_groups (v63)',
    'total'        => $asgTotal,
    'uncovered'    => $asgGap,
    'coverage_pct' => ($asgTotal === null || $asgGap === null) ? null : $pct($asgTotal - $asgGap, $asgTotal),
    'action'       => ($asgGap ?? 0) > 0 ? 'run tools/backfill-assignment-groups.php' : 'converged',
];

// ── enrolment: legacy enrollments (classes) vs course_enrollments (cohorts) ──
$enrLegacy = $count("SELECT COUNT(*) c FROM enrollments");
$enrCanon  = $count("SELECT COUNT(*) c FROM course_enrollments");
$enrNoCohort = $count("SELECT COUNT(*) c FROM course_enrollments WHERE cohort_id IS NULL");
// Legacy students with NO canonical enrolment at all (need mapping).
$enrUnmigrated = $count(
    "SELECT COUNT(DISTINCT e.student_id) c FROM enrollments e
      WHERE e.student_id NOT IN (SELECT student_id FROM course_enrollments)"
);
$report['enrolment'] = [
    'canonical'          => 'course_enrollments.cohort_id',
    'legacy_rows'        => $enrLegacy,
    'canonical_rows'     => $enrCanon,
    'canonical_no_cohort'=> $enrNoCohort,
    'students_unmigrated'=> $enrUnmigrated,
    'action'             => (($enrNoCohort ?? 0) > 0 || ($enrUnmigrated ?? 0) > 0)
                              ? 'backfill class→cohort + cohort_id (Phase 1)' : 'converged',
];

// ── scheduling: classes vs cohorts ──────────────────────────────────────────
$report['scheduling'] = [
    'canonical'      => 'cohorts',
    'legacy_classes' => $count("SELECT COUNT(*) c FROM classes"),
    'cohorts'        => $count("SELECT COUNT(*) c FROM cohorts"),
    'sessions'       => $count("SELECT COUNT(*) c FROM class_sessions"),
    'sessions_no_cohort' => $count("SELECT COUNT(*) c FROM class_sessions WHERE cohort_id IS NULL"),
];

// ── attendance: legacy (enrollment/day) vs session_attendance (session) ──────
$attLegacy = $count("SELECT COUNT(*) c FROM attendance");
$attCanon  = $count("SELECT COUNT(*) c FROM session_attendance");
$report['attendance'] = [
    'canonical'    => 'session_attendance (v22)',
    'legacy_rows'  => $attLegacy,
    'canonical_rows' => $attCanon,
    'note'         => 'dual-read today; convergence keys legacy (enrollment_id, session_date) to a class_session',
    'action'       => ($attLegacy ?? 0) > ($attCanon ?? 0) ? 'backfill attendance→session_attendance (Phase 1)' : 'canonical ahead / converged',
];

$report['_meta'] = [
    'schema_version' => $count("SELECT MAX(version) c FROM schema_version"),
    'read_only'      => true,
    'phase'          => 'Phase 0 — profile only (no writes, no cutover)',
];

if ($asJson) { echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n"; exit(0); }

$fmt = fn($v) => $v === null ? 'n/a' : (is_float($v) ? number_format($v, 1) . '%' : (string) $v);
echo "── MAM ETL convergence audit (READ-ONLY, Phase 0) ──\n";
echo "schema_version: ", $fmt($report['_meta']['schema_version']), "\n\n";
foreach (['money','assignments','enrolment','scheduling','attendance'] as $section) {
    echo strtoupper($section), "\n";
    foreach ($report[$section] as $k => $v) {
        echo sprintf("  %-22s %s\n", $k, $fmt($v));
    }
    echo "\n";
}
echo "This is a profile only — no data was changed. See the staged ETL plan for phases 1–6.\n";
exit(0);
