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

/**
 * The §12 attendance vocabulary. `present`/`late`/`absent`/`excused` are the
 * four the table has always carried; `left_early`, `technical_failure` and
 * `rescheduled` complete the spec and exist so an operator never has to record
 * one of them as an `absent` that misrepresents what happened.
 */
const MAM_ATTENDANCE_STATUSES = [
    'present', 'late', 'absent', 'excused', 'left_early', 'technical_failure', 'rescheduled',
];

/** The §16 attendance modes. All three share one unified academic record. */
const MAM_ATTENDANCE_MODES = ['onsite', 'online', 'hybrid'];

/** Human labels for the §12 statuses (one place, so UI and email agree). */
function mam_attendance_status_label(string $s): string {
    return [
        'present'           => 'Present',
        'late'              => 'Late',
        'absent'            => 'Absent',
        'excused'           => 'Excused',
        'left_early'        => 'Left early',
        'technical_failure' => 'Technical failure',
        'rescheduled'       => 'Rescheduled',
    ][$s] ?? ucfirst(str_replace('_', ' ', $s));
}

/** Resolved check-in configuration. */
function mam_checkin_config(): array {
    return [
        'self_enabled'     => (int) mam_setting_num('checkin_self_enabled', 1) === 1,
        'open_before_min'  => (int) mam_setting_num('checkin_open_before_min', 30),
        'ready_before_min' => (int) mam_setting_num('readiness_before_min', 15),
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
function mam_checkin_self(int $studentId, int $sessionId, ?float $lat = null, ?float $lng = null, string $mode = 'onsite'): array {
    if ($studentId <= 0 || $sessionId <= 0) throw new RuntimeException('invalid_request');
    if (!in_array($mode, MAM_ATTENDANCE_MODES, true)) throw new RuntimeException('invalid_mode');
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
    // Lateness is measured from the SCHEDULED start, not from the end of the
    // grace window — the grace decides whether it counts, not how late it was.
    $lateMin = $late ? max(1, (int) round(($now - $startTs) / 60)) : 0;
    $nowStr = date('Y-m-d H:i:s');
    try {
        mam_db_insert('session_attendance', [
            'session_id'    => $sessionId,
            'student_id'    => $studentId,
            'status'        => $status,
            'mode'          => $mode,
            'notes'         => 'Self check-in',
            'marked_by'     => $studentId,
            'checked_in_at' => $nowStr,
            'minutes_late'  => $lateMin,
            'method'        => 'self',
            'checkin_lat'   => $lat,
            'checkin_lng'   => $lng,
        ]);
        mam_audit('checkin_self', 'class_session', $sessionId, ['cohort_id' => (int) $sess['cohort_id'], 'status' => $status]);
        /* Punctuality accountability (PDF §13). Call it UNCONDITIONALLY: the
           enable gate now lives inside mam_accountability_charge(), and testing
           the setting here as well short-circuited the whole call — which meant
           that with charging off, no row was written at all and the lateness
           simply vanished. The function charges when the policy is on and
           RECORDS with no money owed when it is off, which is the honest
           outcome; a second gate out here defeats it. Idempotent per
           (student, session). */
        if ($late && function_exists('mam_accountability_charge')) {
            mam_accountability_charge('student', $studentId, $sessionId, 'late_arrival', $lateMin);
        }
    } catch (\Throwable $e) {
        // Lost the unique race — treat as idempotent.
        $cur = mam_db_row('SELECT status FROM session_attendance WHERE session_id = ? AND student_id = ?', [$sessionId, $studentId]);
        return ['status' => (string) ($cur['status'] ?? $status), 'already' => true, 'late' => (string) ($cur['status'] ?? '') === 'late'] + $meta;
    }
    if (function_exists('mam_notify')) {
        mam_notify($studentId, 'checkin', 'Checked in', 'You are marked ' . $status . ' for ' . (string) $sess['title'] . '.', null);
    }
    if (function_exists('mam_acadmail_attendance_confirmation')) {
        try { mam_acadmail_attendance_confirmation($studentId, $sessionId, $status); } catch (\Throwable $e) { /* best-effort */ }
    }
    return ['status' => $status, 'already' => false, 'late' => $late] + $meta;
}

/* ── §9 the preparation window, §10 the clock ────────────────────────────── */

/**
 * The §9 window around a session: when the portal opens (30 min before by
 * default), the latest recommended readiness point (15 min before), the start,
 * and the end. `state` is one of closed | open | readiness_passed | in_progress
 * | ended, and `message` is the sentence §9 asks the portal to show
 * prominently. Times are naive-local strings throughout, matching the rest of
 * the scheduling code — never a `Z`-suffixed instant.
 */
function mam_checkin_window(string $scheduledAt, int $durationMin = 60, ?int $nowTs = null): array {
    $cfg     = mam_checkin_config();
    $startTs = strtotime($scheduledAt);
    if ($startTs === false) throw new RuntimeException('session_unscheduled');
    $now     = $nowTs ?? time();
    $opensTs = $startTs - $cfg['open_before_min'] * 60;
    $readyTs = $startTs - $cfg['ready_before_min'] * 60;
    $endTs   = $startTs + max(1, $durationMin) * 60;

    if     ($now <  $opensTs) $state = 'closed';
    elseif ($now <  $readyTs) $state = 'open';
    elseif ($now <  $startTs) $state = 'readiness_passed';
    elseif ($now <  $endTs)   $state = 'in_progress';
    else                      $state = 'ended';

    $t = fn(int $ts) => date('g:i A', $ts);
    return [
        'opens_at'         => date('Y-m-d H:i:s', $opensTs),
        'ready_by'         => date('Y-m-d H:i:s', $readyTs),
        'starts_at'        => date('Y-m-d H:i:s', $startTs),
        'ends_at'          => date('Y-m-d H:i:s', $endTs),
        'open_before_min'  => $cfg['open_before_min'],
        'ready_before_min' => $cfg['ready_before_min'],
        'seconds_to_start' => $startTs - $now,
        'seconds_to_ready' => $readyTs - $now,
        'state'            => $state,
        'message'          => 'Your class begins at ' . $t($startTs)
                            . '. You are expected to be online and ready no later than ' . $t($readyTs) . '.',
    ];
}

/**
 * The §8 "YOUR NEXT CLASS" payload: the next scheduled session the student is
 * enrolled for, the module it delivers (topic + objectives + resources), the
 * §9 window, and the student's preparation and teach-back state — so the
 * dashboard can say what to do, not merely when to turn up.
 *
 * Preparation is reported with §8's own three-way vocabulary
 * (completed | partial | not_started) rather than a boolean, because "started
 * but unfinished" is the state the alert in §28's EMAIL 6 exists to catch.
 */
function mam_checkin_next_class(int $studentId): ?array {
    $cohorts = mam_checkin_student_cohorts($studentId);
    if (!$cohorts) return null;
    $ph = implode(',', array_fill(0, count($cohorts), '?'));
    $sess = mam_db_row(
        "SELECT cs.id, cs.cohort_id, cs.title, cs.scheduled_at, cs.duration_min, cs.lesson_id, cs.mode AS session_mode,
                co.title AS cohort_title
           FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id
          WHERE cs.cohort_id IN ($ph) AND cs.status = 'scheduled' AND cs.scheduled_at >= ?
          ORDER BY cs.scheduled_at ASC LIMIT 1",
        array_merge($cohorts, [date('Y-m-d H:i:s', time() - 3600)])
    );
    if (!$sess) return null;

    $lesson = null; $prep = 'not_started'; $teachback = null;
    if (!empty($sess['lesson_id'])) {
        $lesson = mam_db_row('SELECT * FROM lessons WHERE id = ?', [(int) $sess['lesson_id']]);
        $prog = mam_db_row(
            'SELECT status, percent FROM lesson_progress WHERE lesson_id = ? AND student_id = ?',
            [(int) $sess['lesson_id'], $studentId]
        );
        if ($prog) {
            $pct = (int) ($prog['percent'] ?? 0);
            $done = in_array((string) $prog['status'], ['completed', 'mastered', 'proficient', 'advanced', 'exempted'], true) || $pct >= 100;
            $prep = $done ? 'completed' : ($pct > 0 || (string) $prog['status'] !== 'not_started' ? 'partial' : 'not_started');
        }
        if (function_exists('mam_preclass_teachback_for')) {
            $tb = mam_preclass_teachback_for((int) $sess['lesson_id'], $studentId);
            $teachback = $tb ? ['status' => $tb['status'] ?? null, 'kind' => $tb['kind'] ?? null] : null;
        }
    }

    $att = mam_db_row(
        'SELECT status, mode, checked_in_at, clocked_out_at, minutes_late, duration_min FROM session_attendance WHERE session_id = ? AND student_id = ?',
        [(int) $sess['id'], $studentId]
    );

    return [
        'session_id'   => (int) $sess['id'],
        'title'        => $sess['title'],
        'cohort_title' => $sess['cohort_title'],
        'scheduled_at' => $sess['scheduled_at'],
        'duration_min' => (int) ($sess['duration_min'] ?? 60),
        'mode'         => (string) ($sess['session_mode'] ?? 'onsite'),
        'topic'        => $lesson['title'] ?? ($sess['title'] ?? null),
        'objectives'   => $lesson['objectives'] ?? null,
        'prep_instructions' => $lesson['prep_instructions'] ?? null,
        'resources'    => (isset($lesson['resources_json']) && function_exists('mam_preclass_resources'))
                            ? mam_preclass_resources($lesson['resources_json']) : [],
        'teachback_required' => (int) ($lesson['teachback_required'] ?? 0) === 1,
        'preparation'  => $prep,
        'teachback'    => $teachback,
        'window'       => mam_checkin_window((string) $sess['scheduled_at'], (int) ($sess['duration_min'] ?? 60)),
        'attendance'   => $att ?: null,
    ];
}

/**
 * §10 clock-out. Stamps the exit, stores the ACTUAL duration, and — when the
 * student leaves before the approved end — records §12's `left_early` rather
 * than leaving a `present` that overstates the session.
 *
 * The duration is stored, not derived on read: `class_sessions.scheduled_at`
 * can be edited afterwards and the record of what actually happened must not
 * move with it. Idempotent — a second clock-out returns the first.
 */
function mam_checkin_clock_out(int $studentId, int $sessionId, ?int $nowTs = null): array {
    if ($studentId <= 0 || $sessionId <= 0) throw new RuntimeException('invalid_request');
    $sess = mam_db_row('SELECT id, scheduled_at, duration_min FROM class_sessions WHERE id = ?', [$sessionId]);
    if (!$sess) throw new RuntimeException('session_not_found');
    $att = mam_db_row('SELECT * FROM session_attendance WHERE session_id = ? AND student_id = ?', [$sessionId, $studentId]);
    if (!$att) throw new RuntimeException('not_checked_in');
    if (!empty($att['clocked_out_at'])) {
        return ['already' => true, 'status' => (string) $att['status'],
                'clocked_out_at' => (string) $att['clocked_out_at'],
                'duration_min' => $att['duration_min'] === null ? null : (int) $att['duration_min']];
    }

    $now     = $nowTs ?? time();
    $inTs    = $att['checked_in_at'] ? strtotime((string) $att['checked_in_at']) : null;
    $startTs = strtotime((string) $sess['scheduled_at']);
    // Measure from the later of check-in and scheduled start: arriving early
    // and waiting is not instructional time.
    $fromTs  = max($inTs ?: $startTs, $startTs ?: $inTs);
    $duration = ($fromTs && $now > $fromTs) ? (int) round(($now - $fromTs) / 60) : 0;

    $endTs = $startTs !== false ? $startTs + max(1, (int) ($sess['duration_min'] ?? 60)) * 60 : null;
    // A five-minute tolerance: packing up in the last few minutes is not an
    // early departure, and recording it as one would make the status noise.
    $earlyBy = ($endTs !== null) ? (int) round(($endTs - $now) / 60) : 0;
    $status  = (string) $att['status'];
    if ($endTs !== null && $earlyBy > 5 && in_array($status, ['present', 'late'], true)) $status = 'left_early';

    mam_db_update('session_attendance', [
        'status'         => $status,
        'clocked_out_at' => date('Y-m-d H:i:s', $now),
        'duration_min'   => $duration,
        'updated_at'     => date('Y-m-d H:i:s'),
    ], ['id' => (int) $att['id']]);
    mam_audit('clock_out', 'class_session', $sessionId, ['student' => $studentId, 'duration_min' => $duration, 'status' => $status]);

    return ['already' => false, 'status' => $status, 'clocked_out_at' => date('Y-m-d H:i:s', $now),
            'duration_min' => $duration, 'early_by_min' => max(0, $earlyBy)];
}

/**
 * Admin/instructor correction of an attendance record to any §12 status, with
 * an optional §16 mode. Unlike the self check-in this is allowed to downgrade —
 * it is the human override, and every use is audited.
 */
function mam_checkin_set_status(int $sessionId, int $studentId, string $status, ?string $mode = null, ?int $by = null, ?string $note = null): array {
    if (!in_array($status, MAM_ATTENDANCE_STATUSES, true)) throw new InvalidArgumentException('invalid_status');
    if ($mode !== null && !in_array($mode, MAM_ATTENDANCE_MODES, true)) throw new InvalidArgumentException('invalid_mode');
    if (!mam_db_row('SELECT id FROM class_sessions WHERE id = ?', [$sessionId])) throw new RuntimeException('session_not_found');
    $now = date('Y-m-d H:i:s');
    $existing = mam_db_row('SELECT id, status FROM session_attendance WHERE session_id = ? AND student_id = ?', [$sessionId, $studentId]);
    $fields = ['status' => $status, 'marked_by' => $by, 'updated_at' => $now];
    if ($mode !== null) $fields['mode'] = $mode;
    if ($note !== null) $fields['notes'] = $note;
    if ($existing) mam_db_update('session_attendance', $fields, ['id' => (int) $existing['id']]);
    else mam_db_insert('session_attendance', array_merge(['session_id' => $sessionId, 'student_id' => $studentId, 'method' => 'instructor'], $fields));
    mam_audit('attendance_set', 'class_session', $sessionId, [
        'student' => $studentId, 'from' => $existing['status'] ?? null, 'to' => $status, 'mode' => $mode,
    ]);
    return mam_db_row('SELECT * FROM session_attendance WHERE session_id = ? AND student_id = ?', [$sessionId, $studentId]);
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
function mam_checkin_streak(int $studentId, int $grace = 0): int {
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
    // §3.2 — a streak "freeze": up to $grace missed sessions are tolerated
    // (skipped, not counted) before the streak breaks, so one missed day does
    // not erase weeks of consistency.
    $streak = 0; $skips = 0;
    foreach ($sessions as $s) {
        $att = mam_db_row('SELECT status FROM session_attendance WHERE session_id = ? AND student_id = ?', [(int) $s['id'], $studentId]);
        if ($att && in_array((string) $att['status'], ['present', 'late'], true)) { $streak++; continue; }
        if ($skips < $grace) { $skips++; continue; } // freeze absorbs this miss
        break; // first unprotected miss ends the streak
    }
    return $streak;
}

/**
 * Attendance rate (present+late over past sessions) as a percent, or NULL when
 * there is nothing to compute it from.
 *
 * ⚠️ It returned 0.0 in that case, and the difference is not cosmetic: a child
 * enrolled last week with no session yet was reported to their guardian as
 * **0% attendance behind a red light**, and the weekly email told the same
 * parent "Attendance: 0%". A student who has attended none of twelve sessions
 * and a student who has had none look identical in a float, and only one of
 * them is a problem.
 *
 * This is the same rule the rest of the system already follows — `null` at
 * level 0 in mastery.php means "not assessed", and upper-admin sorts a null
 * group last rather than first. The guardian surface was the one place it was
 * being flattened.
 */
function mam_checkin_attendance_rate(int $studentId): ?float {
    $cohorts = mam_checkin_student_cohorts($studentId);
    if (!$cohorts) return null;
    $ph  = implode(',', array_fill(0, count($cohorts), '?'));
    $now = date('Y-m-d H:i:s');
    $total = (int) (mam_db_row(
        "SELECT COUNT(*) c FROM class_sessions WHERE cohort_id IN ($ph) AND scheduled_at <= ? AND status IN ('scheduled','held')",
        array_merge($cohorts, [$now])
    )['c'] ?? 0);
    if ($total === 0) return null;
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
        'streak'           => mam_checkin_streak($studentId, 1), // §3.2 humane: one missed day is protected
        'streak_grace'     => 1,
        'lessons_completed'=> (int) (mam_db_row("SELECT COUNT(*) c FROM lesson_progress WHERE student_id = ? AND status = 'completed'", [$studentId])['c'] ?? 0),
        'attendance_rate'  => mam_checkin_attendance_rate($studentId),
        'next_session'     => $next ? ['id' => (int) $next['id'], 'title' => $next['title'], 'scheduled_at' => $next['scheduled_at'], 'cohort_title' => $next['cohort_title']] : null,
        'today'            => $todayState,
        'geofence'         => $cfg['geofence_enabled'],
        'accountability'   => function_exists('mam_accountability_outstanding_cents')
            ? ['outstanding_usd_cents' => mam_accountability_outstanding_cents('student', $studentId)]
            : null,
    ];
}
