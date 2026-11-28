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

/**
 * The smallest number of GRADED results an instructor must have before their
 * attainment figures are shown comparatively at all.
 *
 * A value judgement, declared as one. Eight is not a statistical threshold —
 * no honest one exists here — it is the point below which the 95% interval on a
 * mean of rubric scores is so wide that showing the mean invites a false
 * comparison. Below it the figures are withheld and the roster size is shown
 * instead, which is the true statement about what we know.
 */
const MAM_ANALYTICS_MIN_ROSTER = 8;

/**
 * The sentence that must accompany any instructor comparison, wherever it is
 * rendered. It exists as a constant so the two surfaces that show this table
 * cannot drift into showing it without the caveat.
 */
const MAM_ANALYTICS_INSTRUCTOR_NOTICE =
    'Formative only — for spotting where support or training would help. '
  . 'These figures must not be used for pay, ranking or disciplinary decisions: '
  . 'on rosters this size the uncertainty on each average is wider than the '
  . 'differences between instructors, and attainment reflects the students and '
  . 'the timetable at least as much as the teaching.';

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
            'students'       => (int) ($co['enrollment_count'] ?? 0),
            'attendance_pct' => $attTot > 0 ? (int) round($attGd / $attTot * 100) : null,
            'avg_mastery'    => $mastery >= 0 ? (int) round($mastery) : null,
            'completion_pct' => $lpTot > 0 ? (int) round($lpDone / $lpTot * 100) : null,
            'at_risk'        => $atRisk,
        ];
    }
    return $out;
}

/**
 * Arrival analytics (Advanced Modernization §8.3): punctuality distribution
 * across an instructor's cohorts, from check-in times vs scheduled start.
 * Bucketed in PHP for cross-driver date maths.
 */
function mam_analytics2_arrival(int $instructorId, int $limit = 500): array {
    // Every class they have taught (teaching.php, 'history'): arrival patterns are about the past.
    [$hq, $hargs] = mam_teaching_cohort_sql('co.id', $instructorId, null, 'history');
    $rows = [];
    try {
        $rows = mam_db_all(
            "SELECT sa.checked_in_at, sa.status, cs.scheduled_at
               FROM session_attendance sa
               JOIN class_sessions cs ON cs.id = sa.session_id
               JOIN cohorts co ON co.id = cs.cohort_id
              WHERE $hq
              ORDER BY cs.scheduled_at DESC LIMIT " . max(1, $limit),
            $hargs
        );
    } catch (\Throwable $e) { return ['buckets' => [], 'checked_in' => 0, 'no_shows' => 0, 'punctual_pct' => null]; }
    $b = ['early' => 0, 'on_time' => 0, 'late' => 0, 'very_late' => 0]; $checked = 0; $noshow = 0;
    foreach ($rows as $r) {
        if (($r['status'] ?? '') === 'absent') { $noshow++; continue; }
        if (empty($r['checked_in_at']) || empty($r['scheduled_at'])) continue;
        $ci = strtotime((string) $r['checked_in_at']); $sc = (mam_session_ts((string) $r['scheduled_at']) ?? false);
        if ($ci === false || $sc === false) continue;
        $off = ($ci - $sc) / 60; $checked++;
        if ($off <= 0) $b['early']++; elseif ($off <= 5) $b['on_time']++; elseif ($off <= 15) $b['late']++; else $b['very_late']++;
    }
    return [
        'buckets'      => $b,
        'checked_in'   => $checked,
        'no_shows'     => $noshow,
        'punctual_pct' => $checked > 0 ? (int) round(($b['early'] + $b['on_time']) / $checked * 100) : null,
    ];
}

/** At-risk students across an instructor's cohorts (name + cohort + reason bits). */
function mam_analytics2_instructor_at_risk(int $instructorId, int $limit = 30): array {
    // The classes they teach now (teaching.php).
    [$tq, $targs] = mam_teaching_cohort_sql('co.id', $instructorId);
    try {
        return mam_db_all(
            "SELECT DISTINCT u.id AS student_id, u.name, co.title AS cohort
               FROM lesson_progress lp
               JOIN lessons l  ON l.id = lp.lesson_id
               JOIN cohorts co ON co.id = l.cohort_id
               JOIN users u    ON u.id = lp.student_id
              WHERE $tq
                AND (lp.status IN ('remedial','carried_forward') OR lp.attempts >= 2)
              ORDER BY u.name LIMIT " . max(1, $limit),
            $targs
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
                    -- E[x²], so the variance (and hence the standard error of the
                    -- mean) can be computed without a second pass. Both SQLite
                    -- and MySQL evaluate this identically.
                    AVG(r.total_score * r.total_score) AS avg_sq,
                    SUM(CASE WHEN r.outcome = 'pass' THEN 1 ELSE 0 END) AS passed,
                    SUM(CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END) AS graded
               FROM cohorts co
               JOIN users u ON u.id = co.instructor_id
               JOIN course_enrollments ce ON ce.cohort_id = co.id AND ce.status IN ('active','completed')
               LEFT JOIN results r ON r.enrollment_id = ce.id
              WHERE co.instructor_id IS NOT NULL
              GROUP BY co.instructor_id, u.name
              -- Ordered by NAME, never by a metric. A table sorted by score is a
              -- ranking whatever the heading says, and ranking instructors on
              -- roster-sized samples is the practice that lost in Houston Fed. of
              -- Teachers v. HISD. Do not 'improve' this to ORDER BY avg_mastery.
              ORDER BY u.name"
        );
    } catch (\Throwable $e) { return []; }
    foreach ($rows as &$r) {
        $graded = (int) $r['graded'];
        $r['avg_mastery']  = $r['avg_mastery'] !== null ? round((float) $r['avg_mastery'], 1) : null;
        $r['completion_pct'] = $graded > 0 ? round((int) $r['passed'] / $graded * 100, 1) : null;
        $r['students']     = (int) $r['students'];

        /* ── Uncertainty, and the honesty that comes with it ─────────────────
           Comparing instructors on the mean attainment of ~20 students is
           value-added modelling. The American Statistical Association's 2014
           statement found teachers account for about 1–14% of the variability in
           test scores, most of the remainder being system-level; the AERA 2015
           statement warned against high-stakes use; and in Houston Federation of
           Teachers v. HISD (S.D. Tex. 2017) terminating teachers on opaque
           value-added scores was held to violate due process, a practice the
           district abandoned in settlement.

           None of that makes the numbers useless — it makes them formative. So
           they stay, and what was missing arrives with them: the sample size,
           a 95% interval on the mean, and suppression when the roster is too
           small for the comparison to mean anything.

           The interval is a genuine standard error of the mean
           (sd/√n, ±1.96), computed from E[x²] − E[x]². At n=8 on typical
           spreads it comes out ±10 points or worse, which is the argument: two
           instructors "five points apart" are indistinguishable. */
        $n = $graded;
        $ci = null;
        if ($n >= 2 && $r['avg_mastery'] !== null && $r['avg_sq'] !== null) {
            $mean = (float) $r['avg_mastery'];
            $var  = max(0.0, (float) $r['avg_sq'] - $mean * $mean);
            // Sample sd from the population sd (Bessel), then the SE of the mean.
            $sd   = sqrt($var * ($n / max(1, $n - 1)));
            $se   = $sd / sqrt($n);
            $half = round(1.96 * $se, 1);
            $ci = ['half_width' => $half,
                   'low'  => round(max(0.0, $mean - $half), 1),
                   'high' => round(min(100.0, $mean + $half), 1)];
        }
        $r['graded_n']   = $n;
        $r['confidence'] = $ci;
        /* Below the floor the comparative figures are withheld outright rather
           than shown with a caveat, for the same reason the Mastery Score
           withholds a thin composite: a number on screen gets compared. */
        $r['comparable'] = $n >= MAM_ANALYTICS_MIN_ROSTER;
        if (!$r['comparable']) {
            $r['avg_mastery_withheld'] = $r['avg_mastery'];
            $r['completion_withheld']  = $r['completion_pct'];
            $r['avg_mastery']   = null;
            $r['completion_pct'] = null;
        }
        $r['formative_only'] = true;   // see MAM_ANALYTICS_INSTRUCTOR_NOTICE
        [$iq, $iargs] = mam_teaching_cohort_sql('l.cohort_id', (int) $r['instructor_id']);   // teaching.php
        $r['interventions'] = (int) mam_analytics2_scalar(
            "SELECT COUNT(DISTINCT lp.student_id) FROM lesson_progress lp
               JOIN lessons l ON l.id = lp.lesson_id
              WHERE $iq
                AND (lp.status IN ('remedial','carried_forward') OR lp.attempts >= 2)",
            $iargs
        );
        unset($r['passed'], $r['graded'], $r['avg_sq']);
    }
    unset($r);
    return $rows;
}

/** Real-time "today" snapshot (§33): attendance + preparation counts for the
 *  current day. */
function mam_analytics2_today(): array {
    $day = mam_session_today(); // a Lagos day — scheduled_at is Lagos wall-clock
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
