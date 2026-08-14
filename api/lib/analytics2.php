<?php
/**
 * MAM Academy — LMS 2.0 academic analytics (PDF §32-35).
 * ─────────────────────────────────────────────────────────────
 * Read-only, academy-wide academic intelligence for the admin / upper-admin
 * surfaces: overall academic health, per-instructor performance, and a
 * real-time "today" snapshot. Every metric is defensive — a missing table or
 * column degrades to 0/empty rather than failing the dashboard.
 */
declare(strict_types=1);

/** SUM/AVG/COUNT scalar that degrades to a default on any error. */
function mam_analytics2_scalar(string $sql, array $args = [], float $default = 0.0): float {
    try { $v = mam_db_row($sql, $args); return $v ? (float) array_values($v)[0] : $default; }
    catch (\Throwable $e) { return $default; }
}

/**
 * Academy-wide academic health (§34): preparation, attendance, teach-back
 * completion, average mastery, module completion, and the count of students
 * needing intervention — each as a rounded percentage or count.
 */
function mam_analytics2_academy_health(): array {
    $now = date('Y-m-d H:i:s');

    // Attendance: present+late over marked session_attendance rows.
    $attTotal = mam_analytics2_scalar("SELECT COUNT(*) FROM session_attendance");
    $attGood  = mam_analytics2_scalar("SELECT COUNT(*) FROM session_attendance WHERE status IN ('present','late')");
    $attendance = $attTotal > 0 ? round($attGood / $attTotal * 100, 1) : 0.0;

    // Preparation: lesson_progress that reached at least in_progress vs all rows.
    $prepTotal = mam_analytics2_scalar("SELECT COUNT(*) FROM lesson_progress");
    $prepDone  = mam_analytics2_scalar("SELECT COUNT(*) FROM lesson_progress WHERE status = 'completed' OR percent >= 100");
    $preparation = $prepTotal > 0 ? round($prepDone / $prepTotal * 100, 1) : 0.0;

    // Teach-back completion: reviewed over all submitted+reviewed.
    $tbTotal = mam_analytics2_scalar("SELECT COUNT(*) FROM teachbacks");
    $tbDone  = mam_analytics2_scalar("SELECT COUNT(*) FROM teachbacks WHERE status = 'reviewed'");
    $teachback = $tbTotal > 0 ? round($tbDone / $tbTotal * 100, 1) : 0.0;

    // Average mastery: mean finalized result score.
    $avgMastery = round(mam_analytics2_scalar("SELECT AVG(total_score) FROM results WHERE total_score IS NOT NULL"), 1);

    // Module completion: terminal-mastery statuses over all module rows.
    $modTotal = mam_analytics2_scalar("SELECT COUNT(*) FROM lesson_progress");
    $modDone  = mam_analytics2_scalar("SELECT COUNT(*) FROM lesson_progress WHERE status IN ('completed','mastered','proficient','advanced','exempted')");
    $moduleCompletion = $modTotal > 0 ? round($modDone / $modTotal * 100, 1) : 0.0;

    // Intervention: distinct students carried forward / remedial / multi-attempt.
    $intervention = (int) mam_analytics2_scalar("SELECT COUNT(DISTINCT student_id) FROM lesson_progress WHERE status IN ('remedial','carried_forward') OR attempts >= 2");

    return [
        'students'          => (int) mam_analytics2_scalar("SELECT COUNT(DISTINCT student_id) FROM course_enrollments WHERE status IN ('active','completed')"),
        'attendance_pct'    => $attendance,
        'preparation_pct'   => $preparation,
        'teachback_pct'     => $teachback,
        'avg_mastery'       => $avgMastery,
        'module_completion_pct' => $moduleCompletion,
        'intervention'      => $intervention,
        'generated_at'      => $now,
    ];
}

/** Per-instructor performance (§35): students, average mastery, completion rate
 *  and intervention count across the cohorts they teach. */
function mam_analytics2_instructor_performance(): array {
    try {
        $rows = mam_db_all(
            "SELECT co.instructor_id AS instructor_id, u.name AS instructor_name,
                    COUNT(DISTINCT ce.student_id) AS students,
                    AVG(r.total_score) AS avg_mastery,
                    SUM(CASE WHEN r.outcome = 'pass' THEN 1 ELSE 0 END) AS passed,
                    SUM(CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END) AS graded
               FROM cohorts co
               JOIN users u ON u.id = co.instructor_id
               JOIN course_enrollments ce ON ce.cohort_id = co.id AND ce.status IN ('active','completed')
               LEFT JOIN results r ON r.enrollment_id = ce.id
              WHERE co.instructor_id IS NOT NULL
              GROUP BY co.instructor_id, u.name
              ORDER BY u.name"
        );
    } catch (\Throwable $e) { return []; }
    foreach ($rows as &$r) {
        $graded = (int) $r['graded'];
        $r['avg_mastery']  = $r['avg_mastery'] !== null ? round((float) $r['avg_mastery'], 1) : null;
        $r['completion_pct'] = $graded > 0 ? round((int) $r['passed'] / $graded * 100, 1) : null;
        $r['students']     = (int) $r['students'];
        $r['interventions'] = (int) mam_analytics2_scalar(
            "SELECT COUNT(DISTINCT lp.student_id) FROM lesson_progress lp
               JOIN lessons l ON l.id = lp.lesson_id
              WHERE l.cohort_id IN (SELECT id FROM cohorts WHERE instructor_id = ?)
                AND (lp.status IN ('remedial','carried_forward') OR lp.attempts >= 2)",
            [(int) $r['instructor_id']]
        );
        unset($r['passed'], $r['graded']);
    }
    unset($r);
    return $rows;
}

/** Real-time "today" snapshot (§33): attendance + preparation counts for the
 *  current day. */
function mam_analytics2_today(): array {
    $day = date('Y-m-d');
    $lo = $day . ' 00:00:00'; $hi = $day . ' 23:59:59';
    $present = (int) mam_analytics2_scalar(
        "SELECT COUNT(*) FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id
          WHERE sa.status = 'present' AND cs.scheduled_at BETWEEN ? AND ?", [$lo, $hi]);
    $late = (int) mam_analytics2_scalar(
        "SELECT COUNT(*) FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id
          WHERE sa.status = 'late' AND cs.scheduled_at BETWEEN ? AND ?", [$lo, $hi]);
    $absent = (int) mam_analytics2_scalar(
        "SELECT COUNT(*) FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id
          WHERE sa.status = 'absent' AND cs.scheduled_at BETWEEN ? AND ?", [$lo, $hi]);
    $sessions = (int) mam_analytics2_scalar(
        "SELECT COUNT(*) FROM class_sessions WHERE scheduled_at BETWEEN ? AND ?", [$lo, $hi]);
    return ['date' => $day, 'sessions_today' => $sessions, 'present' => $present, 'late' => $late, 'absent' => $absent];
}
