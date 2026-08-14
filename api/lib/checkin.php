<?php
/**
 * MAM Academy — NGG-style student check-in (LMS 2.0).
 * ─────────────────────────────────────────────────────────────
 * Brings NextGenGen's member experience to mam's session model: a student
 * checks themselves in for the day's session, arriving after the grace window
 * is recorded as 'late' (NGG's present/late model), an optional geofence gates
 * the check-in to a location, and a private portal dashboard shows week-of-N,
 * XP, an attendance streak, and lessons completed.
 *
 * It writes the canonical session_attendance table (already present/late-aware)
 * and never downgrades an existing mark, so it composes with instructor marking
 * and the existing QR self-check-in. XP and streak are derived on read
 * (deterministic from attendance + lesson_progress), so there is no separate
 * points ledger to keep in sync. Times use PHP date()/strtotime(), never
 * datetime('now') inside a query.
 */
declare(strict_types=1);

/** Resolved check-in configuration. */
function mam_checkin_config(): array {
    return [
        'self_enabled'     => (int) mam_setting_num('checkin_self_enabled', 1) === 1,
        'open_before_min'  => (int) mam_setting_num('checkin_open_before_min', 30),
        'late_grace_min'   => (int) mam_setting_num('checkin_late_grace_min', 10),
        'geofence_enabled' => (int) mam_setting_num('checkin_geofence_enabled', 0) === 1,
        'geofence_lat'     => (float) mam_setting_num('checkin_geofence_lat', 0),
        'geofence_lng'     => (float) mam_setting_num('checkin_geofence_lng', 0),
        'geofence_radius_m'=> (int) mam_setting_num('checkin_geofence_radius_m', 200),
        'xp_present'       => (int) mam_setting_num('checkin_xp_present', 10),
        'xp_late'          => (int) mam_setting_num('checkin_xp_late', 5),
        'xp_lesson'        => (int) mam_setting_num('checkin_xp_lesson', 15),
        'total_weeks'      => (int) mam_setting_num('program_total_weeks', 12),
    ];
}

/** Cohort ids the student is actively enrolled in (active/completed). */
function mam_checkin_student_cohorts(int $studentId): array {
    $rows = mam_db_all(
        "SELECT DISTINCT cohort_id FROM course_enrollments
          WHERE student_id = ? AND cohort_id IS NOT NULL AND status IN ('active','completed')",
        [$studentId]
    );
    return array_map(fn($r) => (int) $r['cohort_id'], $rows);
}

/** Great-circle distance in metres between two lat/lng points (haversine). */
function mam_checkin_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** The student's session scheduled for a given day (default today), or null. */
function mam_checkin_session_on(int $studentId, ?string $day = null): ?array {
    $cohorts = mam_checkin_student_cohorts($studentId);
    if (!$cohorts) return null;
    $day = $day ?: date('Y-m-d');
    $ph  = implode(',', array_fill(0, count($cohorts), '?'));
    return mam_db_row(
        "SELECT cs.id, cs.cohort_id, cs.title, cs.scheduled_at, cs.duration_min, co.title AS cohort_title
           FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id
          WHERE cs.cohort_id IN ($ph) AND cs.status = 'scheduled'
            AND cs.scheduled_at >= ? AND cs.scheduled_at <= ?
          ORDER BY cs.scheduled_at ASC LIMIT 1",
        array_merge($cohorts, [$day . ' 00:00:00', $day . ' 23:59:59'])
    );
}

/**
 * Self check-in for a session. Enforces the enable toggle, enrolment, the
 * open window (opens open_before_min before start), and — when enabled — the
 * geofence. Marks 'present', or 'late' past the grace window. Idempotent: an
 * existing mark stands (never downgraded). Throws a RuntimeException whose
 * message is a stable error code. Returns the resulting state.
 */
function mam_checkin_self(int $studentId, int $sessionId, ?float $lat = null, ?float $lng = null): array {
    if ($studentId <= 0 || $sessionId <= 0) throw new RuntimeException('invalid_request');
    $cfg = mam_checkin_config();
    if (!$cfg['self_enabled']) throw new RuntimeException('checkin_disabled');

    $sess = mam_db_row(
        "SELECT cs.id, cs.cohort_id, cs.title, cs.scheduled_at, cs.duration_min, co.title AS cohort_title
           FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id WHERE cs.id = ?",
        [$sessionId]
    );
    if (!$sess) throw new RuntimeException('session_not_found');

    $enrolled = mam_db_row(
        "SELECT 1 FROM course_enrollments WHERE cohort_id = ? AND student_id = ? AND status IN ('active','completed') LIMIT 1",
        [(int) $sess['cohort_id'], $studentId]
    );
    if (!$enrolled) throw new RuntimeException('not_enrolled');

    $now     = time();
    $startTs = strtotime((string) $sess['scheduled_at']);
    if ($startTs === false) throw new RuntimeException('session_unscheduled');
    if ($now < $startTs - $cfg['open_before_min'] * 60) throw new RuntimeException('checkin_not_open');
    // Closes at end-of-session + a generous late tail (2× duration or 2h).
    $duration = (int) ($sess['duration_min'] ?? 60);
    if ($now > $startTs + max($duration * 2, 120) * 60) throw new RuntimeException('checkin_closed');

    // Optional geofence.
    if ($cfg['geofence_enabled'] && $cfg['geofence_lat'] != 0.0 && $cfg['geofence_lng'] != 0.0) {
        if ($lat === null || $lng === null) throw new RuntimeException('location_required');
        $dist = mam_checkin_distance_m($cfg['geofence_lat'], $cfg['geofence_lng'], $lat, $lng);
        if ($dist > $cfg['geofence_radius_m']) throw new RuntimeException('out_of_range');
    }

    $meta = ['session_title' => $sess['title'], 'cohort_title' => $sess['cohort_title'], 'session_id' => $sessionId];

    $existing = mam_db_row('SELECT status FROM session_attendance WHERE session_id = ? AND student_id = ?', [$sessionId, $studentId]);
    if ($existing) {
        return ['status' => (string) $existing['status'], 'already' => true, 'late' => (string) $existing['status'] === 'late'] + $meta;
    }

    $late   = $now > $startTs + $cfg['late_grace_min'] * 60;
    $status = $late ? 'late' : 'present';
    $nowStr = date('Y-m-d H:i:s');
    try {
        mam_db_insert('session_attendance', [
            'session_id'    => $sessionId,
            'student_id'    => $studentId,
            'status'        => $status,
            'notes'         => 'Self check-in',
            'marked_by'     => $studentId,
            'checked_in_at' => $nowStr,
            'method'        => 'self',
            'checkin_lat'   => $lat,
            'checkin_lng'   => $lng,
        ]);
        mam_audit('checkin_self', 'class_session', $sessionId, ['cohort_id' => (int) $sess['cohort_id'], 'status' => $status]);
    } catch (\Throwable $e) {
        // Lost the unique race — treat as idempotent.
        $cur = mam_db_row('SELECT status FROM session_attendance WHERE session_id = ? AND student_id = ?', [$sessionId, $studentId]);
        return ['status' => (string) ($cur['status'] ?? $status), 'already' => true, 'late' => (string) ($cur['status'] ?? '') === 'late'] + $meta;
    }
    if (function_exists('mam_notify')) {
        mam_notify($studentId, 'checkin', 'Checked in', 'You are marked ' . $status . ' for ' . (string) $sess['title'] . '.', null);
    }
    return ['status' => $status, 'already' => false, 'late' => $late] + $meta;
}

/** Present + late counts and a derived XP total for a student. */
function mam_checkin_xp(int $studentId): int {
    $cfg = mam_checkin_config();
    $present = (int) (mam_db_row("SELECT COUNT(*) c FROM session_attendance WHERE student_id = ? AND status = 'present'", [$studentId])['c'] ?? 0);
    $late    = (int) (mam_db_row("SELECT COUNT(*) c FROM session_attendance WHERE student_id = ? AND status = 'late'", [$studentId])['c'] ?? 0);
    $lessons = (int) (mam_db_row("SELECT COUNT(*) c FROM lesson_progress WHERE student_id = ? AND status = 'completed'", [$studentId])['c'] ?? 0);
    return $present * $cfg['xp_present'] + $late * $cfg['xp_late'] + $lessons * $cfg['xp_lesson'];
}

/**
 * Consecutive most-recent PAST sessions (across the student's cohorts) the
 * student attended (present/late), counted back from the latest until the first
 * miss. Session-based rather than calendar-based, so it works whatever the
 * cadence.
 */
function mam_checkin_streak(int $studentId): int {
    $cohorts = mam_checkin_student_cohorts($studentId);
    if (!$cohorts) return 0;
    $ph  = implode(',', array_fill(0, count($cohorts), '?'));
    $now = date('Y-m-d H:i:s');
    $sessions = mam_db_all(
        "SELECT cs.id FROM class_sessions cs
          WHERE cs.cohort_id IN ($ph) AND cs.scheduled_at <= ? AND cs.status IN ('scheduled','held')
          ORDER BY cs.scheduled_at DESC LIMIT 200",
        array_merge($cohorts, [$now])
    );
    $streak = 0;
    foreach ($sessions as $s) {
        $att = mam_db_row('SELECT status FROM session_attendance WHERE session_id = ? AND student_id = ?', [(int) $s['id'], $studentId]);
        if ($att && in_array((string) $att['status'], ['present', 'late'], true)) { $streak++; continue; }
        break; // first miss (or unmarked past session) ends the streak
    }
    return $streak;
}

/** Attendance rate (present+late over past sessions) as a percent. */
function mam_checkin_attendance_rate(int $studentId): float {
    $cohorts = mam_checkin_student_cohorts($studentId);
    if (!$cohorts) return 0.0;
    $ph  = implode(',', array_fill(0, count($cohorts), '?'));
    $now = date('Y-m-d H:i:s');
    $total = (int) (mam_db_row(
        "SELECT COUNT(*) c FROM class_sessions WHERE cohort_id IN ($ph) AND scheduled_at <= ? AND status IN ('scheduled','held')",
        array_merge($cohorts, [$now])
    )['c'] ?? 0);
    if ($total === 0) return 0.0;
    $attended = (int) (mam_db_row(
        "SELECT COUNT(*) c FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id
          WHERE cs.cohort_id IN ($ph) AND sa.student_id = ? AND sa.status IN ('present','late') AND cs.scheduled_at <= ?",
        array_merge($cohorts, [$studentId, $now])
    )['c'] ?? 0);
    return round($attended / $total * 100, 1);
}

/**
 * The private NGG-style member dashboard payload for a student: week-of-N, XP,
 * streak, lessons completed, attendance rate, the next session, and today's
 * check-in state (including whether the check-in window is open right now).
 */
function mam_checkin_dashboard(int $studentId): array {
    $cfg = mam_checkin_config();
    $now = time();

    // Primary cohort = most recent active enrolment with a start date.
    $cohort = mam_db_row(
        "SELECT co.id, co.title, co.starts_on, co.ends_on
           FROM course_enrollments ce JOIN cohorts co ON co.id = ce.cohort_id
          WHERE ce.student_id = ? AND ce.status IN ('active','completed') AND ce.cohort_id IS NOT NULL
          ORDER BY co.starts_on DESC, co.id DESC LIMIT 1",
        [$studentId]
    );

    $totalWeeks = $cfg['total_weeks'];
    $week = 1;
    if ($cohort && $cohort['starts_on']) {
        $startTs = strtotime((string) $cohort['starts_on']);
        if ($startTs !== false) {
            $week = max(1, (int) floor(($now - $startTs) / (7 * 86400)) + 1);
            if ($cohort['ends_on']) {
                $endTs = strtotime((string) $cohort['ends_on']);
                if ($endTs !== false && $endTs > $startTs) $totalWeeks = max(1, (int) ceil(($endTs - $startTs) / (7 * 86400)));
            }
            $week = min($week, $totalWeeks);
        }
    }

    // Next upcoming session across cohorts.
    $cohorts = mam_checkin_student_cohorts($studentId);
    $next = null;
    if ($cohorts) {
        $ph = implode(',', array_fill(0, count($cohorts), '?'));
        $next = mam_db_row(
            "SELECT cs.id, cs.title, cs.scheduled_at, co.title AS cohort_title
               FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id
              WHERE cs.cohort_id IN ($ph) AND cs.status = 'scheduled' AND cs.scheduled_at >= ?
              ORDER BY cs.scheduled_at ASC LIMIT 1",
            array_merge($cohorts, [date('Y-m-d H:i:s')])
        );
    }

    // Today's session + check-in state.
    $today = mam_checkin_session_on($studentId);
    $todayState = null;
    if ($today) {
        $att = mam_db_row('SELECT status, checked_in_at, method FROM session_attendance WHERE session_id = ? AND student_id = ?', [(int) $today['id'], $studentId]);
        $startTs = strtotime((string) $today['scheduled_at']);
        $canNow = $startTs !== false
            && $now >= $startTs - $cfg['open_before_min'] * 60
            && $now <= $startTs + max((int) ($today['duration_min'] ?? 60) * 2, 120) * 60;
        $todayState = [
            'session_id'   => (int) $today['id'],
            'title'        => $today['title'],
            'scheduled_at' => $today['scheduled_at'],
            'checked_in'   => (bool) $att,
            'status'       => $att['status'] ?? null,
            'can_check_in' => $canNow && !$att && $cfg['self_enabled'],
        ];
    }

    return [
        'week'             => $week,
        'total_weeks'      => $totalWeeks,
        'cohort'           => $cohort ? ['id' => (int) $cohort['id'], 'title' => $cohort['title']] : null,
        'xp'               => mam_checkin_xp($studentId),
        'streak'           => mam_checkin_streak($studentId),
        'lessons_completed'=> (int) (mam_db_row("SELECT COUNT(*) c FROM lesson_progress WHERE student_id = ? AND status = 'completed'", [$studentId])['c'] ?? 0),
        'attendance_rate'  => mam_checkin_attendance_rate($studentId),
        'next_session'     => $next ? ['id' => (int) $next['id'], 'title' => $next['title'], 'scheduled_at' => $next['scheduled_at'], 'cohort_title' => $next['cohort_title']] : null,
        'today'            => $todayState,
        'geofence'         => $cfg['geofence_enabled'],
    ];
}
