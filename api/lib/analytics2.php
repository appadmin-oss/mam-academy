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

/**
 * Academic intervention list (§31) + absence escalation tiers (§38).
 *
 * Scores every student who shows a risk signal and assigns an intervention
 * LEVEL with human-readable reasons:
 *   Level 3 — Academic review    (3+ absences, or a remedial module, or 3+ carried forward)
 *   Level 2 — Academic intervention (2 absences, 1–2 carried forward, or 2+ multi-attempt modules)
 *   Level 1 — Instructor carry-forward (1 absence, any carry-forward, repeated lateness)
 * The highest triggered level wins. Escalation targets (§38) follow from the
 * level: L1→parent, L2→parent+instructor+admin, L3→parent+instructor+admin+review.
 * Returns rows sorted by level desc then absences desc.
 */
function mam_analytics2_intervention_list(int $limit = 100): array {
    $rows = mam_db_all(
        "SELECT u.id AS student_id, u.name, u.email,
                (SELECT COUNT(*) FROM session_attendance sa WHERE sa.student_id = u.id AND sa.status = 'absent') AS absences,
                (SELECT COUNT(*) FROM session_attendance sa WHERE sa.student_id = u.id AND sa.status = 'late')   AS lates,
                (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.student_id = u.id AND lp.status = 'carried_forward') AS carried,
                (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.student_id = u.id AND lp.status = 'remedial') AS remedial,
                (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.student_id = u.id AND lp.attempts >= 2) AS multi_attempt
           FROM users u
          WHERE u.role = 'student'"
    );
    $out = [];
    foreach ($rows as $r) {
        $absences = (int) $r['absences']; $lates = (int) $r['lates'];
        $carried  = (int) $r['carried'];  $remedial = (int) $r['remedial'];
        $multi    = (int) $r['multi_attempt'];
        $level = 0; $reasons = [];
        if ($absences >= 1)  { $level = max($level, 1); $reasons[] = $absences . ' absence' . ($absences === 1 ? '' : 's'); }
        if ($carried >= 1)   { $level = max($level, 1); $reasons[] = $carried . ' module' . ($carried === 1 ? '' : 's') . ' carried forward'; }
        if ($lates >= 3)     { $level = max($level, 1); $reasons[] = $lates . ' late arrivals'; }
        if ($absences == 2)  { $level = max($level, 2); }
        if ($carried >= 1 && $carried <= 2) { $level = max($level, 2); }
        if ($multi >= 2)     { $level = max($level, 2); $reasons[] = $multi . ' modules re-attempted'; }
        if ($absences >= 3)  { $level = max($level, 3); }
        if ($remedial >= 1)  { $level = max($level, 3); $reasons[] = $remedial . ' remedial module' . ($remedial === 1 ? '' : 's'); }
        if ($carried >= 3)   { $level = max($level, 3); }
        if ($level === 0) continue;
        $labels = [1 => 'Instructor carry-forward', 2 => 'Academic intervention', 3 => 'Academic review'];
        $escalate = [1 => 'Parent', 2 => 'Parent + Instructor + Admin', 3 => 'Parent + Instructor + Admin (review)'];
        $out[] = [
            'student_id' => (int) $r['student_id'],
            'name'       => $r['name'] ?: $r['email'],
            'level'      => $level,
            'level_label'=> $labels[$level],
            'escalation' => $escalate[$level],
            'absences'   => $absences,
            'lates'      => $lates,
            'carried'    => $carried,
            'remedial'   => $remedial,
            'reasons'    => $reasons ?: ['risk signals present'],
        ];
    }
    usort($out, fn($a, $b) => ($b['level'] <=> $a['level']) ?: ($b['absences'] <=> $a['absences']));
    return array_slice($out, 0, max(1, $limit));
}

/**
 * Instructor cohort analytics (Advanced Modernization §5.3): for each cohort
 * the instructor teaches — students, attendance %, average mastery, module
 * completion %, and the at-risk count. Teach to the data, not the anecdote.
 */
function mam_analytics2_instructor_cohorts(int $instructorId): array {
    $cohorts = function_exists('mam_lms_instructor_cohorts') ? mam_lms_instructor_cohorts($instructorId) : [];
    $out = [];
    foreach ($cohorts as $co) {
        $cid    = (int) $co['id'];
        $attTot = mam_analytics2_scalar("SELECT COUNT(*) FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id WHERE cs.cohort_id = ?", [$cid]);
        $attGd  = mam_analytics2_scalar("SELECT COUNT(*) FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id WHERE cs.cohort_id = ? AND sa.status IN ('present','late')", [$cid]);
        $lpTot  = mam_analytics2_scalar("SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id WHERE l.cohort_id = ?", [$cid]);
        $lpDone = mam_analytics2_scalar("SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id WHERE l.cohort_id = ? AND lp.status IN ('completed','mastered','proficient','advanced','exempted')", [$cid]);
        $mastery = mam_analytics2_scalar("SELECT AVG(lp.percent) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id WHERE l.cohort_id = ? AND lp.percent IS NOT NULL", [$cid], -1.0);
        $atRisk = (int) mam_analytics2_scalar("SELECT COUNT(DISTINCT lp.student_id) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id WHERE l.cohort_id = ? AND (lp.status IN ('remedial','carried_forward') OR lp.attempts >= 2)", [$cid]);
        $out[] = [
            'cohort_id'      => $cid,
            'title'          => $co['title'] ?: ('Cohort #' . $cid),
            'course_name'    => $co['course_name'] ?? null,
            'level_name'     => $co['level_name'] ?? null,
            'students'       => (int) (($co['enrollment_count'] ?? 0) ?: ($co['legacy_count'] ?? 0)),
            'attendance_pct' => $attTot > 0 ? (int) round($attGd / $attTot * 100) : null,
            'avg_mastery'    => $mastery >= 0 ? (int) round($mastery) : null,
            'completion_pct' => $lpTot > 0 ? (int) round($lpDone / $lpTot * 100) : null,
            'at_risk'        => $atRisk,
        ];
    }
    return $out;
}

/** At-risk students across an instructor's cohorts (name + cohort + reason bits). */
function mam_analytics2_instructor_at_risk(int $instructorId, int $limit = 30): array {
    try {
        return mam_db_all(
            "SELECT DISTINCT u.id AS student_id, u.name, co.title AS cohort
               FROM lesson_progress lp
               JOIN lessons l  ON l.id = lp.lesson_id
               JOIN cohorts co ON co.id = l.cohort_id
               JOIN users u    ON u.id = lp.student_id
              WHERE (co.instructor_id = ? OR co.id IN (SELECT cohort_id FROM course_enrollments WHERE instructor_id = ? AND cohort_id IS NOT NULL))
                AND (lp.status IN ('remedial','carried_forward') OR lp.attempts >= 2)
              ORDER BY u.name LIMIT " . max(1, $limit),
            [$instructorId, $instructorId]
        );
    } catch (\Throwable $e) { return []; }
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
