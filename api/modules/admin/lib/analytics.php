<?php
/**
 * Learning analytics (LMS L5) — read-only aggregates for the admin dashboard.
 *
 * Every query degrades gracefully: a DB behind on migrations (missing a table)
 * returns zero/empty rather than 500-ing the dashboard, mirroring the overview
 * KPIs. Nothing here writes. The at-risk model is a transparent, explainable
 * heuristic over signals we already capture (attendance, resubmissions,
 * assessment averages, practice recency) — not a black box.
 */
declare(strict_types=1);

/** Best-effort grouped count: returns [key => int]. Never throws. */
function mam_analytics_group(string $sql, array $args = []): array {
    try {
        $out = [];
        foreach (mam_db_all($sql, $args) as $r) {
            $vals = array_values($r);
            $out[(string)$vals[0]] = (int)$vals[1];
        }
        return $out;
    } catch (\Throwable $e) { return []; }
}

/** Headline learning aggregates: enrolment outcomes, retention, per-course
 *  pass rates, attendance + submission mix. */
function mam_analytics_overview(): array {
    $byStatus = mam_analytics_group("SELECT status, COUNT(*) FROM course_enrollments GROUP BY status");
    $completed = (int)($byStatus['completed'] ?? 0);
    $failed    = (int)($byStatus['failed'] ?? 0);
    $finalized = $completed + $failed;
    $total     = array_sum($byStatus);

    $byCourse = [];
    try {
        $rows = mam_db_all(
            "SELECT c.name AS course, c.code,
                    COUNT(ce.id) AS enrolled,
                    SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN ce.status='failed'    THEN 1 ELSE 0 END) AS failed,
                    AVG(r.total_score) AS avg_score
               FROM course_enrollments ce
               JOIN courses c      ON c.id = ce.course_id
               LEFT JOIN results r ON r.enrollment_id = ce.id
              GROUP BY c.id ORDER BY enrolled DESC LIMIT 20"
        );
        foreach ($rows as $r) {
            $fin = (int)$r['completed'] + (int)$r['failed'];
            $byCourse[] = [
                'course'    => (string)$r['course'],
                'code'      => (string)$r['code'],
                'enrolled'  => (int)$r['enrolled'],
                'completed' => (int)$r['completed'],
                'pass_rate' => $fin > 0 ? (int)round(100 * (int)$r['completed'] / $fin) : null,
                'avg_score' => $r['avg_score'] !== null ? (int)round((float)$r['avg_score']) : null,
            ];
        }
    } catch (\Throwable $e) { $byCourse = []; }

    $att = mam_analytics_group("SELECT status, COUNT(*) FROM session_attendance GROUP BY status");
    $attended = (int)($att['present'] ?? 0) + (int)($att['late'] ?? 0);
    $attTotal = array_sum($att);

    return [
        'enrolments'    => ['by_status' => $byStatus, 'total' => $total],
        'retention'     => [
            'completed'  => $completed,
            'failed'     => $failed,
            'finalized'  => $finalized,
            'pass_rate'  => $finalized > 0 ? (int)round(100 * $completed / $finalized) : null,
            'active'     => (int)($byStatus['active'] ?? 0),
        ],
        'by_course'     => $byCourse,
        'attendance'    => ['by_status' => $att, 'attended' => $attended, 'total' => $attTotal,
                            'rate' => $attTotal > 0 ? (int)round(100 * $attended / $attTotal) : null],
        'submissions'   => mam_analytics_group("SELECT status, COUNT(*) FROM submissions GROUP BY status"),
    ];
}

/**
 * At-risk students: a transparent heuristic over students with an ACTIVE
 * enrolment. A student is flagged with one or more human-readable reasons.
 * Returns [['student_id','name','reasons'=>[...], 'attendance_rate','avg_score']].
 */
function mam_analytics_at_risk(int $staleDays = 14, int $limit = 100): array {
    try {
        // One model: the old class enrolments and marks were carried into the
        // cohort tables (mam_legacy_carry()), so course_enrollments is everyone.
        $active = mam_db_all(
            "SELECT DISTINCT ce.student_id, u.name
               FROM course_enrollments ce JOIN users u ON u.id = ce.student_id
              WHERE ce.status = 'active'
              ORDER BY u.name, ce.student_id LIMIT " . max(1, min(500, $limit))
        );
        if (!$active) return [];

        // Aggregate signals keyed by student_id (a handful of grouped queries,
        // merged in PHP — far cheaper than N+1 per student).
        // Attendance (session_attendance — carried class marks included).
        $attMap = [];
        foreach (mam_db_all("SELECT student_id, SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) AS att, COUNT(*) AS tot FROM session_attendance GROUP BY student_id") as $r) {
            $attMap[(int)$r['student_id']] = ['att' => (int)$r['att'], 'tot' => (int)$r['tot']];
        }
        $subMap = [];
        foreach (mam_db_all("SELECT student_id, SUM(CASE WHEN status='needs_resubmission' THEN 1 ELSE 0 END) AS resub, SUM(CASE WHEN status='accepted' THEN 1 ELSE 0 END) AS accepted FROM submissions GROUP BY student_id") as $r) {
            $subMap[(int)$r['student_id']] = ['resub' => (int)$r['resub'], 'accepted' => (int)$r['accepted']];
        }
        $scoreMap = [];
        foreach (mam_db_all("SELECT ce.student_id, AVG(a.score * 100.0 / NULLIF(a.max_score,0)) AS avg_pct FROM assessments a JOIN course_enrollments ce ON ce.id = a.enrollment_id GROUP BY ce.student_id") as $r) {
            if ($r['avg_pct'] !== null) $scoreMap[(int)$r['student_id']] = (float)$r['avg_pct'];
        }
        $practiceMap = [];
        foreach (mam_db_all("SELECT student_id, MAX(logged_on) AS last FROM practice_logs GROUP BY student_id") as $r) {
            $practiceMap[(int)$r['student_id']] = (string)$r['last'];
        }

        $staleBefore = date('Y-m-d', time() - $staleDays * 86400);
        $out = [];
        foreach ($active as $s) {
            $sid = (int)$s['student_id'];
            $reasons = [];
            $a = $attMap[$sid] ?? null;
            $attRate = ($a && $a['tot'] >= 3) ? (int)round(100 * $a['att'] / $a['tot']) : null;
            if ($attRate !== null && $attRate < 60) $reasons[] = 'Low attendance (' . $attRate . '%)';

            $sub = $subMap[$sid] ?? null;
            if ($sub && $sub['resub'] >= 1 && $sub['accepted'] === 0) $reasons[] = 'Work awaiting resubmission';

            $avg = $scoreMap[$sid] ?? null;
            if ($avg !== null && $avg < 50) $reasons[] = 'Low assessment average (' . (int)round($avg) . '%)';

            $last = $practiceMap[$sid] ?? null;
            if ($last !== null && $last !== '' && $last < $staleBefore) $reasons[] = 'No practice logged since ' . $last;

            if ($reasons) {
                $out[] = [
                    'student_id'      => $sid,
                    'name'            => (string)($s['name'] ?? ('#' . $sid)),
                    'reasons'         => $reasons,
                    'attendance_rate' => $attRate,
                    'avg_score'       => $avg !== null ? (int)round($avg) : null,
                ];
            }
        }
        // Most reasons first (most at-risk on top).
        usort($out, function ($x, $y) { return count($y['reasons']) <=> count($x['reasons']); });
        return $out;
    } catch (\Throwable $e) { return []; }
}
