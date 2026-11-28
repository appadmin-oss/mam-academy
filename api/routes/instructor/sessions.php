<?php
/**
 * /api/instructor/sessions  (cohort scheduling — LMS L2 + enterprise scheduling v45)
 *
 *   GET                              → {cohorts:[…]}
 *   GET ?cohort_id=N                 → {cohort, sessions:[…], fill_count}
 *   GET ?cohort_id=N&history=SESSION_ID → {history:[…]}
 *
 *   POST {action:'create',    cohort_id, title?, scheduled_at?, duration_min?, kind?, location?, room?}
 *   POST {action:'bulk_create', cohort_id, start_date, end_date, day_of_week, time_of_day?,
 *                               duration_min?, kind?, title_prefix?, location?, room?, skip_dates?}
 *   POST {action:'preview',   cohort_id, start_date, end_date, day_of_week, …}  → {dates:[…]}
 *   POST {action:'reschedule', session_id, scheduled_at, reason?}
 *   POST {action:'substitute', session_id, substitute_id (null to clear)}
 *   POST {action:'status',    session_id, status:'held'|'cancelled', reason?}
 *   POST {action:'schedule_update', cohort_id, schedule_days?, schedule_time?, session_duration?}
 *
 * Instructor/admin. Admins bypass cohort ownership; instructors must teach the cohort.
 */
declare(strict_types=1);

$u       = mam_require_role('instructor', 'admin');
$me      = (int)$u['id'];
$isAdmin = function_exists('mam_user_has_role') ? mam_user_has_role($u, 'admin') : ($u['role'] === 'admin');

$ownsCohort = function (int $cohortId) use ($me, $isAdmin): bool {
    return $isAdmin ? (mam_db_row('SELECT 1 FROM cohorts WHERE id = ?', [$cohortId]) !== null)
                    : mam_lms_instructor_owns_cohort($me, $cohortId);
};

$ownsSession = function (int $sessionId) use ($ownsCohort): bool {
    $sess = mam_lms_session($sessionId);
    return $sess && $ownsCohort((int)$sess['cohort_id']);
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $cohortId  = (int)($_GET['cohort_id'] ?? 0);
    $histSessId = (int)($_GET['history'] ?? 0);

    if ($cohortId <= 0) {
        mam_ok(['cohorts' => $isAdmin ? mam_lms_cohorts() : mam_lms_instructor_cohorts($me)]);
    }
    if (!$ownsCohort($cohortId)) mam_fail('forbidden', 403);

    if ($histSessId > 0) {
        $sess = mam_lms_session($histSessId);
        if (!$sess || (int)$sess['cohort_id'] !== $cohortId) mam_fail('session_not_found', 404);
        mam_ok(['history' => mam_lms_session_history($histSessId)]);
    }

    $sessions = mam_lms_cohort_sessions($cohortId);
    // Enrich with substitute name.
    $subIds = array_filter(array_unique(array_column($sessions, 'substitute_id')));
    $subMap = [];
    if (!empty($subIds)) {
        $ph = implode(',', array_fill(0, count($subIds), '?'));
        foreach (mam_db_all("SELECT id, name FROM users WHERE id IN ($ph)", array_values($subIds)) as $r) {
            $subMap[(int)$r['id']] = $r['name'];
        }
    }
    foreach ($sessions as &$s) {
        $s['substitute_name'] = isset($s['substitute_id']) && $s['substitute_id'] !== null
            ? ($subMap[(int)$s['substitute_id']] ?? null) : null;
    }
    unset($s);

    try {
        $fillCount = mam_lms_cohort_fill_count($cohortId);
        $waitlist  = $isAdmin ? mam_lms_waitlist_for_cohort($cohortId) : [];
    } catch (Throwable $_) {
        $fillCount = null;
        $waitlist  = [];
    }

    mam_ok([
        'cohort'     => mam_lms_cohort($cohortId),
        'sessions'   => $sessions,
        'fill_count' => $fillCount,
        'waitlist'   => $waitlist,
    ]);
}

$in     = mam_json_input();
$action = strtolower(trim((string)($in['action'] ?? 'create')));

try {
    /* ── preview (dry-run) ── */
    if ($action === 'preview') {
        $cohortId = (int)($in['cohort_id'] ?? 0);
        if ($cohortId <= 0) mam_fail('validation', 422, ['fields' => ['cohort_id']]);
        if (!$ownsCohort($cohortId)) mam_fail('forbidden', 403);
        $rawDays = $in['day_of_week'] ?? [];
        if (!is_array($rawDays)) $rawDays = [$rawDays];
        $dates = mam_lms_bulk_sessions_preview([
            'start_date'   => (string)($in['start_date'] ?? ''),
            'end_date'     => (string)($in['end_date']   ?? ''),
            'day_of_week'  => $rawDays,
            'skip_dates'   => (array)($in['skip_dates'] ?? []),
        ]);
        mam_ok(['dates' => $dates, 'count' => count($dates)]);
    }

    /* ── bulk_create ── */
    if ($action === 'bulk_create') {
        $cohortId = (int)($in['cohort_id'] ?? 0);
        if ($cohortId <= 0) mam_fail('validation', 422, ['fields' => ['cohort_id']]);
        if (!$ownsCohort($cohortId)) mam_fail('forbidden', 403);
        $rawDays = $in['day_of_week'] ?? [];
        if (!is_array($rawDays)) $rawDays = [$rawDays];
        $res = mam_lms_bulk_create_sessions($cohortId, [
            'start_date'   => (string)($in['start_date']   ?? ''),
            'end_date'     => (string)($in['end_date']     ?? ''),
            'day_of_week'  => $rawDays,
            'time_of_day'  => (string)($in['time_of_day']  ?? '09:00'),
            'duration_min' => isset($in['duration_min']) ? (int)$in['duration_min'] : 60,
            'kind'         => (string)($in['kind']         ?? 'class'),
            'title_prefix' => (string)($in['title_prefix'] ?? ''),
            'location'     => (string)($in['location']     ?? ''),
            'room'         => (string)($in['room']         ?? ''),
            'skip_dates'   => (array)($in['skip_dates']    ?? []),
        ], $me);
        $ids = $res['session_ids'];
        mam_ok(['created' => true, 'count' => count($ids), 'session_ids' => $ids, 'conflicts' => $res['conflicts']]);
    }

    /* ── reschedule ── */
    if ($action === 'reschedule') {
        $sid    = (int)($in['session_id'] ?? 0);
        $newAt  = trim((string)($in['scheduled_at'] ?? ''));
        if ($sid < 1 || $newAt === '') mam_fail('validation', 422, ['fields' => ['session_id', 'scheduled_at']]);
        if (!$ownsSession($sid)) mam_fail('forbidden', 403);

        // Conflict check (warn only — admins can override, but the UI shows it).
        $sess = mam_lms_session($sid);
        $conflicts = [];
        if ($sess) {
            $co = mam_db_row('SELECT instructor_id FROM cohorts WHERE id = ?', [(int)$sess['cohort_id']]);
            $instructorId = $co && $co['instructor_id'] ? (int)$co['instructor_id'] : 0;
            $room = isset($sess['room']) && $sess['room'] !== '' ? (string)$sess['room'] : null;
            if ($instructorId > 0 || $room !== null) {
                $conflicts = mam_lms_detect_conflicts(
                    $instructorId,
                    $newAt,
                    (int)($sess['duration_min'] ?? 60),
                    $sid,
                    $room
                );
            }
        }
        $reason = isset($in['reason']) ? trim((string)$in['reason']) : null;
        mam_lms_reschedule_session($sid, $newAt, $reason, $me);
        mam_ok(['rescheduled' => true, 'conflicts' => $conflicts]);
    }

    /* ── substitute ── */
    if ($action === 'substitute') {
        $sid   = (int)($in['session_id'] ?? 0);
        $subId = isset($in['substitute_id']) && $in['substitute_id'] !== null && $in['substitute_id'] !== ''
            ? (int)$in['substitute_id'] : null;
        if ($sid < 1) mam_fail('validation', 422, ['fields' => ['session_id']]);
        if (!$ownsSession($sid)) mam_fail('forbidden', 403);
        if ($subId !== null && !mam_db_row("SELECT 1 FROM users WHERE id = ? AND role IN ('instructor','admin')", [$subId])) {
            mam_fail('substitute_not_found', 404);
        }
        mam_lms_set_session_substitute($sid, $subId, $me);
        mam_ok(['ok' => true]);
    }

    /* ── schedule_update (cohort structured schedule) ── */
    if ($action === 'schedule_update') {
        $cohortId = (int)($in['cohort_id'] ?? 0);
        if ($cohortId <= 0) mam_fail('validation', 422, ['fields' => ['cohort_id']]);
        if (!$ownsCohort($cohortId)) mam_fail('forbidden', 403);
        mam_lms_update_cohort_schedule($cohortId, [
            'schedule_days'    => $in['schedule_days']    ?? null,
            'schedule_time'    => $in['schedule_time']    ?? null,
            'session_duration' => $in['session_duration'] ?? null,
        ]);
        mam_ok(['ok' => true]);
    }

    /* ── set_meeting_link ── */
    if ($action === 'set_meeting_link') {
        $sid  = (int)($in['session_id'] ?? 0);
        $link = trim((string)($in['meeting_link'] ?? ''));
        if ($sid < 1) mam_fail('validation', 422, ['fields' => ['session_id']]);
        if (!$ownsSession($sid)) mam_fail('forbidden', 403);
        if ($link !== '' && !preg_match('#^https?://#i', $link)) mam_fail('invalid_url', 422, ['detail' => 'Meeting link must start with https://']);
        mam_db_update('class_sessions', ['meeting_link' => $link !== '' ? $link : null], ['id' => $sid]);
        mam_audit('set_meeting_link', 'class_session', $sid, ['link_set' => ($link !== '')]);
        mam_ok(['ok' => true]);
    }

    /* ── send_reminder (students only; admin route adds instructor) ── */
    if ($action === 'send_reminder') {
        $sid = (int)($in['session_id'] ?? 0);
        if ($sid < 1) mam_fail('validation', 422, ['fields' => ['session_id']]);
        $sess = mam_lms_session($sid);
        if (!$sess) mam_fail('session_not_found', 404);
        if (!$ownsCohort((int)$sess['cohort_id'])) mam_fail('forbidden', 403);

        $appUrl    = rtrim((string)mam_env('APP_URL', ''), '/');
        $loginUrl  = $appUrl . '/portal/login';
        $joinUrl   = !empty($sess['meeting_link']) ? $appUrl . '/session/' . $sid . '/join' : '';
        $title     = (string)($sess['title'] ?: 'your session');
        $whenLabel = !empty($sess['scheduled_at'])
            ? mam_session_fmt((string) $sess['scheduled_at'], 'l j M, g:i A T')
            : 'upcoming';

        $students = mam_db_all(
            "SELECT DISTINCT u.name, u.email FROM course_enrollments ce
               JOIN users u ON u.id = ce.student_id
              WHERE ce.cohort_id = ? AND ce.status IN ('active','completed')
                AND u.email IS NOT NULL AND u.email <> ''",
            [(int)$sess['cohort_id']]
        );
        $sent = 0;
        foreach ($students as $st) {
            mam_send(
                (string)$st['email'],
                'Reminder: ' . $title . ' is coming up',
                mam_email_session_reminder((string)($st['name'] ?? ''), $title, $whenLabel, $loginUrl, $joinUrl),
                'session_reminder_manual', true
            );
            $sent++;
        }
        mam_event('session.reminder_manual', 'class_session', $sid, ['sent_by' => $me, 'students' => $sent]);
        mam_audit('send_reminder', 'class_session', $sid, ['students' => $sent]);
        mam_ok(['sent' => $sent]);
    }

    /* ── status ── */
    if ($action === 'status') {
        $sid = (int)($in['session_id'] ?? 0);
        $sess = $sid > 0 ? mam_lms_session($sid) : null;
        if (!$sess) mam_fail('session_not_found', 404);
        if (!$ownsCohort((int)$sess['cohort_id'])) mam_fail('forbidden', 403);
        $to = strtolower(trim((string)($in['status'] ?? '')));
        if ($to === 'cancelled' && !empty($in['reason'])) {
            mam_db_update('class_sessions', ['cancellation_reason' => trim((string)$in['reason'])], ['id' => $sid]);
        }
        $t = mam_lms_set_session_status($sid, $to, $me);
        // Enqueue a 2-hour deadline check so that missing progress reports trigger a fine.
        if ($t['to'] === 'held') {
            mam_job_enqueue('progress_report_deadline', ['session_id' => $sid], date('Y-m-d H:i:s', strtotime('+2 hours')));
        }
        mam_ok(['ok' => true, 'status' => $t['to']]);
    }

    /* ── default: create ── */
    $cohortId = (int)($in['cohort_id'] ?? 0);
    if ($cohortId <= 0) mam_fail('validation', 422, ['fields' => ['cohort_id']]);
    if (!$ownsCohort($cohortId)) mam_fail('forbidden', 403);

    $scheduledAt = mam_lms_norm_datetime(isset($in['scheduled_at']) ? (string)$in['scheduled_at'] : null);
    $durMin      = isset($in['duration_min']) ? (int)$in['duration_min'] : 60;

    // Conflict check before single-session create (instructor + room).
    $conflicts = [];
    if ($scheduledAt) {
        $co = mam_db_row('SELECT instructor_id FROM cohorts WHERE id = ?', [$cohortId]);
        $instructorId = $co && $co['instructor_id'] ? (int)$co['instructor_id'] : 0;
        $room = isset($in['room']) && $in['room'] !== '' ? (string)$in['room'] : null;
        if ($instructorId > 0 || $room !== null) {
            $conflicts = mam_lms_detect_conflicts($instructorId, $scheduledAt, $durMin, null, $room);
        }
    }

    $id = mam_lms_create_session($cohortId, [
        'title'        => isset($in['title'])    ? (string)$in['title']    : null,
        'scheduled_at' => $scheduledAt,
        'duration_min' => $durMin,
        'kind'         => (string)($in['kind']   ?? 'class'),
        'location'     => isset($in['location']) ? (string)$in['location'] : null,
        'room'         => isset($in['room'])     ? (string)$in['room']     : null,
        'created_by'   => $me,
    ]);
    mam_ok(['created' => true, 'session_id' => $id, 'conflicts' => $conflicts]);

} catch (RuntimeException $e) {
    $msg = $e->getMessage();
    if (in_array($msg, ['cohort_not_found', 'session_not_found', 'substitute_not_found'], true)) mam_fail($msg, 404);
    if ($msg === 'already_enrolled') mam_fail($msg, 409, ['detail' => 'Student is already enrolled']);
    mam_fail('illegal_transition', 409, ['detail' => $msg]);
} catch (InvalidArgumentException $e) {
    mam_fail('validation', 422, ['detail' => $e->getMessage()]);
}
