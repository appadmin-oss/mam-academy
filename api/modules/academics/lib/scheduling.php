<?php
/**
 * Scheduling from the admin console (Teaching → Timetable & rooms).
 *
 * Until 2026-11-23 only an instructor could put a session on the timetable
 * (api/routes/instructor/sessions.php); the console's timetable only read. This
 * is the office's half, built on the lms.php primitives rather than beside them:
 * mam_lms_create_session(), mam_lms_detect_conflicts(), mam_lms_reschedule_session()
 * and mam_lms_set_session_status() stay the one implementation of each write.
 *
 * Four rules, each easy to "simplify" away:
 *  - A weekly series is PLANNED before it is written. The plan is a pure read
 *    (mam_sched_plan_series) so the console can show it, and the write
 *    (mam_sched_create_series) re-plans rather than trusting what the form saw.
 *  - A holiday or a mid-term break removes the date; an exam window or a make-up
 *    week does not (classes may run in both). Every removed date is reported.
 *  - Re-running a series never duplicates: a slot this cohort already has a
 *    scheduled or held session at is "exists", not created again.
 *  - A clash refuses by default. The office can choose to create only the
 *    clash-free slots, or to create the clashing ones too; the choice is theirs,
 *    never silent.
 *
 * Times are the academy's wall clock (MAM_CALENDAR_TZ), stored as entered —
 * exactly what the instructor route has always stored.
 *
 * Cancelling credits each enrolled learner a free make-up (guardian-comms.php):
 * a teacher's cancellation is `teacher_cancellation`, a closure or the academy's
 * own decision is `academy_closure`. Neither spends a family's allowance, and
 * nothing here touches money.
 */
declare(strict_types=1);

if (!defined('MAM_SCHED_TZ')) define('MAM_SCHED_TZ', defined('MAM_CALENDAR_TZ') ? MAM_CALENDAR_TZ : 'Africa/Lagos');

/** Closure kinds that take a date off the timetable. Exam and make-up weeks don't. */
const MAM_SCHED_BLOCKING_CLOSURES = ['holiday', 'break'];

/** Session modes the console offers. Matches class_sessions.mode (v81). */
const MAM_SCHED_MODES = ['onsite', 'online', 'hybrid'];

/** Who cancelled → the make-up reason. A guardian's own notice is not offered here. */
const MAM_SCHED_CANCEL_CAUSES = [
    'teacher' => 'teacher_cancellation',
    'closure' => 'academy_closure',
    'academy' => 'academy_closure',
];

/** The blocking closure covering a date, or null. Looks the term up by the date itself. */
function mam_sched_closure_on(string $date): ?array {
    if (!function_exists('mam_calendar_term_on')) return null;
    try {
        $term = mam_calendar_term_on($date);
        if (!$term || empty($term['id'])) return null;
        $c = mam_db_row(
            'SELECT kind, label, from_on, to_on FROM term_closures
              WHERE term_id = ? AND from_on <= ? AND to_on >= ? AND kind IN (?, ?) LIMIT 1',
            [(int) $term['id'], $date, $date, MAM_SCHED_BLOCKING_CLOSURES[0], MAM_SCHED_BLOCKING_CLOSURES[1]]
        );
        return $c ?: null;
    } catch (Throwable $e) { return null; }
}

/** A room's display name by id ('' when none). The name is what conflict checks compare. */
function mam_sched_room_name(?int $roomId): string {
    if (!$roomId) return '';
    $r = mam_db_row('SELECT name FROM rooms WHERE id = ?', [$roomId]);
    return $r ? trim((string) $r['name']) : '';
}

/** Normalise the series rule from a form or a test. Throws InvalidArgumentException. */
function mam_sched_rule(int $cohortId, array $in): array {
    $co = mam_db_row('SELECT id, title, instructor_id, starts_on, ends_on, session_duration, schedule_time FROM cohorts WHERE id = ?', [$cohortId]);
    if (!$co) throw new InvalidArgumentException('cohort_not_found');

    $days = $in['days'] ?? [];
    if (!is_array($days)) $days = [$days];
    $days = array_values(array_unique(array_filter(array_map('intval', $days), static fn($d) => $d >= 0 && $d <= 6)));
    sort($days);
    if (!$days) throw new InvalidArgumentException('pick_a_day');

    $time = (string) ($in['time'] ?? ($co['schedule_time'] ?? ''));
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) throw new InvalidArgumentException('bad_time');

    $dur = (int) ($in['duration'] ?? ($co['session_duration'] ?? 60));
    if ($dur < 15 || $dur > 480) throw new InvalidArgumentException('bad_duration');

    // Dates default to the cohort's own, then to the current term.
    $from = (string) ($in['from'] ?? '');
    $to   = (string) ($in['to'] ?? '');
    if ($from === '') $from = (string) ($co['starts_on'] ?? '');
    if ($to === '')   $to   = (string) ($co['ends_on'] ?? '');
    if (($from === '' || $to === '') && function_exists('mam_calendar_current_term')) {
        try {
            $t = mam_calendar_current_term();
            $t = $t['next'] ?? $t;
            if ($from === '' && !empty($t['starts_on'])) $from = (string) $t['starts_on'];
            if ($to === ''   && !empty($t['ends_on']))   $to   = (string) $t['ends_on'];
        } catch (Throwable $e) { /* fall through to the validation below */ }
    }
    $from = substr($from, 0, 10); $to = substr($to, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) throw new InvalidArgumentException('bad_dates');
    if ($to < $from) throw new InvalidArgumentException('to_before_from');
    if ((strtotime($to) - strtotime($from)) > 400 * 86400) throw new InvalidArgumentException('range_too_long');

    $mode = (string) ($in['mode'] ?? 'onsite');
    if (!in_array($mode, MAM_SCHED_MODES, true)) $mode = 'onsite';
    $roomId = (int) ($in['room_id'] ?? 0) ?: null;
    if ($roomId && !mam_db_row('SELECT id FROM rooms WHERE id = ?', [$roomId])) $roomId = null;
    if ($mode === 'online') $roomId = null;   // an online class occupies no room

    return [
        'cohort_id' => $cohortId, 'cohort_title' => (string) $co['title'],
        'instructor_id' => $co['instructor_id'] !== null ? (int) $co['instructor_id'] : 0,
        'days' => $days, 'time' => $time, 'duration' => $dur,
        'from' => $from, 'to' => $to, 'mode' => $mode,
        'room_id' => $roomId, 'room' => mam_sched_room_name($roomId),
    ];
}

/**
 * Conflicts for one proposed slot: the same teacher (owner, creator or
 * substitute); for an in-person slot, the same room; and — when the cohort is
 * given — any learner on it who is already in another class at that time.
 * Each row carries `kind` (teacher | room | learner).
 */
function mam_sched_conflicts(int $instructorId, string $at, int $dur, string $room, ?int $excludeId = null, int $cohortId = 0): array {
    $out = [];
    if ($instructorId > 0 || $room !== '') {
        foreach (mam_lms_detect_conflicts($instructorId, $at, $dur, $excludeId, $room !== '' ? $room : null) as $c) {
            $c['kind'] = ($room !== '' && (string) ($c['room'] ?? '') === $room) ? 'room' : 'teacher';
            $out[] = $c;
        }
    }
    if ($cohortId > 0) {
        $seen = array_column($out, 'id');
        foreach (mam_sched_learner_conflicts($cohortId, $at, $dur, $excludeId) as $c) if (!in_array($c['id'], $seen, true)) $out[] = $c;
    }
    return $out;
}

/** Sessions of OTHER cohorts that share an active learner with this one and overlap the slot. */
function mam_sched_learner_conflicts(int $cohortId, string $at, int $dur, ?int $excludeId = null): array {
    $end = date('Y-m-d H:i:s', strtotime($at) + $dur * 60);
    $endExpr = mam_db_driver() === 'mysql' ? 'DATE_ADD(cs.scheduled_at, INTERVAL cs.duration_min MINUTE)' : "datetime(cs.scheduled_at, '+' || cs.duration_min || ' minutes')";
    $rows = mam_db_all("SELECT cs.id, cs.title, cs.scheduled_at, cs.duration_min, cs.room, co.title AS cohort_title,
                               COUNT(DISTINCT mine.student_id) AS learners
                          FROM class_sessions cs
                          JOIN cohorts co ON co.id = cs.cohort_id
                          JOIN course_enrollments theirs ON theirs.cohort_id = cs.cohort_id AND theirs.status = 'active'
                          JOIN course_enrollments mine ON mine.student_id = theirs.student_id AND mine.cohort_id = ? AND mine.status = 'active'
                         WHERE cs.cohort_id != ? AND cs.status = 'scheduled' AND cs.scheduled_at < ? AND $endExpr > ? AND cs.id != ?
                         GROUP BY cs.id, cs.title, cs.scheduled_at, cs.duration_min, cs.room, co.title",
                       [$cohortId, $cohortId, $end, $at, (int) $excludeId]);
    foreach ($rows as &$r) $r['kind'] = 'learner';
    return $rows;
}

/**
 * Plan a weekly series without writing anything. Each slot is one of:
 *   ok       — will be created
 *   clash    — overlaps another session (`with` lists them)
 *   exists   — this cohort already has a live session at that time
 *   closed   — a holiday or break (`closure`)
 */
function mam_sched_plan_series(int $cohortId, array $in): array {
    $r = mam_sched_rule($cohortId, $in);
    $existing = [];
    foreach (mam_db_all("SELECT scheduled_at FROM class_sessions WHERE cohort_id = ? AND status IN ('scheduled','held') AND scheduled_at IS NOT NULL", [$cohortId]) as $row) {
        $existing[substr((string) $row['scheduled_at'], 0, 16)] = true;
    }
    $slots = [];
    $cur = strtotime($r['from'] . ' 12:00:00');
    $end = strtotime($r['to'] . ' 12:00:00');
    while ($cur <= $end && count($slots) < 200) {
        $date = date('Y-m-d', $cur);
        if (in_array((int) date('w', $cur), $r['days'], true)) {
            $at = $date . ' ' . $r['time'] . ':00';
            $slot = ['date' => $date, 'at' => $at, 'status' => 'ok', 'with' => [], 'closure' => null];
            if (isset($existing[substr($at, 0, 16)])) {
                $slot['status'] = 'exists';
            } elseif ($c = mam_sched_closure_on($date)) {
                $slot['status'] = 'closed'; $slot['closure'] = $c;
            } else {
                $hit = mam_sched_conflicts($r['instructor_id'], $at, $r['duration'], $r['room'], null, $cohortId);
                if ($hit) { $slot['status'] = 'clash'; $slot['with'] = $hit; }
            }
            $slots[] = $slot;
        }
        $cur = strtotime('+1 day', $cur);
    }
    $count = ['ok' => 0, 'clash' => 0, 'exists' => 0, 'closed' => 0];
    foreach ($slots as $s) $count[$s['status']]++;
    return ['rule' => $r, 'slots' => $slots, 'count' => $count];
}

/**
 * Write a series. $onClash: 'refuse' (create nothing if any slot clashes),
 * 'skip' (create the clash-free slots only) or 'create' (create them all).
 * Returns the plan plus `created` (ids) and `refused` (true when nothing was
 * written because of clashes).
 */
function mam_sched_create_series(int $cohortId, array $in, int $actorId, string $onClash = 'refuse'): array {
    if (!in_array($onClash, ['refuse', 'skip', 'create'], true)) $onClash = 'refuse';
    $plan = mam_sched_plan_series($cohortId, $in);
    $r = $plan['rule'];
    $plan['created'] = []; $plan['refused'] = false;
    if ($plan['count']['clash'] > 0 && $onClash === 'refuse') { $plan['refused'] = true; return $plan; }

    $pdo = mam_db();
    $pdo->beginTransaction();
    try {
        foreach ($plan['slots'] as $s) {
            if ($s['status'] === 'ok' || ($s['status'] === 'clash' && $onClash === 'create')) {
                $id = mam_lms_create_session($cohortId, [
                    'scheduled_at' => $s['at'], 'duration_min' => $r['duration'],
                    'kind' => 'class', 'room' => $r['room'] !== '' ? $r['room'] : null, 'created_by' => $actorId,
                ]);
                mam_db_update('class_sessions', ['mode' => $r['mode'], 'room_id' => $r['room_id']], ['id' => $id]);
                $plan['created'][] = $id;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    // Remember the pattern on the cohort, so the next run and the portals read it.
    try {
        mam_lms_update_cohort_schedule($cohortId, [
            'schedule_days' => implode(',', $r['days']), 'schedule_time' => $r['time'], 'session_duration' => $r['duration'],
        ]);
    } catch (Throwable $e) { /* the sessions are the record; the pattern is a convenience */ }
    // Every online or hybrid session gets its link now — nobody pastes one.
    foreach ($plan['created'] as $id) { try { mam_meeting_ensure($id); } catch (\Throwable $e) { mam_log('warn', 'meeting_ensure_failed', ['session' => $id, 'err' => $e->getMessage()]); } }
    if ($plan['created']) $plan['told'] = mam_sched_notify_timetable($cohortId, $plan['created']);
    mam_audit('sched_series', 'cohort', $cohortId, [
        'created' => count($plan['created']), 'clash' => $plan['count']['clash'], 'closed' => $plan['count']['closed'],
        'exists' => $plan['count']['exists'], 'on_clash' => $onClash, 'by' => $actorId,
    ]);
    return $plan;
}

/** Add one session. Refuses on a clash unless $force. Returns ['id' => int|null, 'clashes' => [...]]. */
function mam_sched_add_session(int $cohortId, array $in, int $actorId, bool $force = false): array {
    $co = mam_db_row('SELECT id, instructor_id FROM cohorts WHERE id = ?', [$cohortId]);
    if (!$co) throw new InvalidArgumentException('cohort_not_found');
    $at = mam_sched_datetime((string) ($in['at'] ?? ''));
    $dur = (int) ($in['duration'] ?? 60);
    if ($dur < 15 || $dur > 480) throw new InvalidArgumentException('bad_duration');
    $mode = in_array((string) ($in['mode'] ?? ''), MAM_SCHED_MODES, true) ? (string) $in['mode'] : 'onsite';
    $roomId = $mode === 'online' ? null : ((int) ($in['room_id'] ?? 0) ?: null);
    $room = mam_sched_room_name($roomId);
    $kind = in_array((string) ($in['kind'] ?? ''), ['class', 'exam', 'revision', 'makeup'], true) ? (string) $in['kind'] : 'class';

    $clashes = mam_sched_conflicts((int) ($co['instructor_id'] ?? 0), $at, $dur, $room, null, $cohortId);
    if ($clashes && !$force) return ['id' => null, 'clashes' => $clashes];
    $id = mam_lms_create_session($cohortId, [
        'title' => trim((string) ($in['title'] ?? '')) !== '' ? mb_substr(trim((string) $in['title']), 0, 160) : null,
        'scheduled_at' => $at, 'duration_min' => $dur, 'kind' => $kind,
        'room' => $room !== '' ? $room : null, 'created_by' => $actorId,
    ]);
    mam_db_update('class_sessions', ['mode' => $mode, 'room_id' => $roomId], ['id' => $id]);
    try { mam_meeting_ensure($id); } catch (\Throwable $e) { mam_log('warn', 'meeting_ensure_failed', ['session' => $id, 'err' => $e->getMessage()]); }
    mam_audit('sched_session_add', 'class_session', $id, ['cohort' => $cohortId, 'forced' => $force && (bool) $clashes, 'by' => $actorId]);
    return ['id' => $id, 'clashes' => $clashes];
}

/** 'YYYY-MM-DDTHH:MM' (a datetime-local input) or 'YYYY-MM-DD HH:MM[:SS]' → 'YYYY-MM-DD HH:MM:00'. */
function mam_sched_datetime(string $v): string {
    $v = str_replace('T', ' ', trim($v));
    if (!preg_match('/^(\d{4}-\d{2}-\d{2}) ([01]\d|2[0-3]):([0-5]\d)(:\d{2})?$/', $v, $m)) throw new InvalidArgumentException('bad_datetime');
    return $m[1] . ' ' . $m[2] . ':' . $m[3] . ':00';
}

/**
 * Everyone a change to a session concerns: the learners actively enrolled on
 * its cohort, each one's linked guardians, the cohort's instructor and any
 * substitute. Deduplicated; a user who is two of these is told once.
 */
function mam_sched_audience(int $sessionId): array {
    $s = mam_db_row('SELECT cs.cohort_id, cs.substitute_id, co.instructor_id FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id WHERE cs.id = ?', [$sessionId]);
    if (!$s) return ['students' => [], 'guardians' => [], 'staff' => []];
    $students = array_map('intval', array_column(mam_db_all(
        "SELECT DISTINCT student_id FROM course_enrollments WHERE cohort_id = ? AND status = 'active'", [(int) $s['cohort_id']]), 'student_id'));
    $guardians = [];
    if ($students) {
        $in = implode(',', array_fill(0, count($students), '?'));
        $guardians = array_map('intval', array_column(mam_db_all("SELECT DISTINCT parent_id FROM parent_links WHERE student_id IN ($in)", $students), 'parent_id'));
    }
    $staff = [(int) ($s['instructor_id'] ?? 0), (int) ($s['substitute_id'] ?? 0)];
    // Everybody on the class's staff now (teaching.php), assistants included.
    foreach (mam_cohort_staff((int) $s['cohort_id']) as $st) if ($st['role'] !== 'cover') $staff[] = (int) $st['instructor_id'];
    $staff = array_values(array_unique(array_filter($staff)));
    return ['students' => $students, 'guardians' => array_values(array_diff($guardians, $students)), 'staff' => array_values(array_diff($staff, $students, $guardians))];
}

/**
 * Tell everyone a change concerns, on every channel the academy has:
 * in-app (always), email (when they have an address) and WhatsApp (a template,
 * only where Kapso is configured and the person opted in — kapso.php decides).
 * Best-effort throughout: a failed channel is logged and never undoes the change.
 * Returns ['told' => people reached in-app, 'emailed' => n, 'whatsapp' => n].
 */
function mam_sched_notify(int $sessionId, string $event, string $title, string $body, array $wa = []): array {
    $a = mam_sched_audience($sessionId);
    $out = ['told' => 0, 'emailed' => 0, 'whatsapp' => 0];
    $people = array_values(array_unique(array_merge($a['students'], $a['guardians'], $a['staff'])));
    if (!$people) return $out;
    $in = implode(',', array_fill(0, count($people), '?'));
    $users = [];
    foreach (mam_db_all("SELECT id, name, email, status FROM users WHERE id IN ($in)", $people) as $u) $users[(int) $u['id']] = $u;
    // Whose class is it, in each guardian's words: their own children on this cohort.
    $kids = [];
    if ($a['guardians'] && $a['students']) {
        $gi = implode(',', array_fill(0, count($a['guardians']), '?')); $si = implode(',', array_fill(0, count($a['students']), '?'));
        foreach (mam_db_all("SELECT pl.parent_id, u.name FROM parent_links pl JOIN users u ON u.id = pl.student_id
                              WHERE pl.parent_id IN ($gi) AND pl.student_id IN ($si) ORDER BY u.name", array_merge($a['guardians'], $a['students'])) as $k) {
            $kids[(int) $k['parent_id']][] = strtok((string) $k['name'], ' ');
        }
    }
    $links = function_exists('mam_cal_event_links') ? mam_db_row('SELECT cs.*, co.title AS cohort_title FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id WHERE cs.id = ?', [$sessionId]) : null;
    foreach ($people as $uid) {
        $u = $users[$uid] ?? null;
        if (!$u || ($u['status'] ?? '') !== 'active') continue;
        try { if (mam_notify($uid, 'session.' . $event, $title, $body) > 0) $out['told']++; } catch (\Throwable $e) {}
        if (!empty($u['email']) && function_exists('mam_send')) {
            try {
                $html = '<p>' . htmlspecialchars($title, ENT_QUOTES) . '</p><p>' . nl2br(htmlspecialchars($body, ENT_QUOTES)) . '</p>'
                    . ($links && $event !== 'cancelled' ? '<p><a href="' . htmlspecialchars(mam_cal_event_links($links)['google'], ENT_QUOTES) . '">Add to Google Calendar</a></p>' : '')
                    . '<p>If you subscribed to your MAM calendar, it has already updated.</p>';
                if (mam_send((string) $u['email'], $title, $html, 'schedule_' . $event)) $out['emailed']++;
            } catch (\Throwable $e) { mam_log('warn', 'sched_email_failed', ['user' => $uid, 'err' => $e->getMessage()]); }
        }
        if ($wa && function_exists('mam_kapso_send_template')) {
            $learner = in_array($uid, $a['guardians'], true) ? implode(' & ', $kids[$uid] ?? ['Your child'])
                     : (in_array($uid, $a['staff'], true) ? 'Your class' : strtok((string) $u['name'], ' '));
            $params = array_map(fn($v) => $v === '{learner}' ? $learner : $v, $wa['params']);
            if (mam_kapso_send_template($uid, $wa['template'], $params, $wa['idem'] . ':' . $uid, $sessionId) === 'sent') $out['whatsapp']++;
        }
    }
    return $out;
}

/** A new series: tell each learner's family (and the teacher) once, with the first date. */
function mam_sched_notify_timetable(int $cohortId, array $sessionIds): int {
    if (!$sessionIds) return 0;
    $first = mam_db_row('SELECT cs.id, cs.scheduled_at, co.title FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id WHERE cs.id IN ('
        . implode(',', array_map('intval', $sessionIds)) . ') ORDER BY cs.scheduled_at LIMIT 1');
    if (!$first) return 0;
    $when = mam_session_fmt((string) $first['scheduled_at'], 'D j M, H:i');
    $r = mam_sched_notify((int) $first['id'], 'timetable', 'Timetable ready: ' . $first['title'],
        count($sessionIds) . ' sessions, starting ' . $when . '. Add them to your calendar from your portal.',
        ['template' => 'timetable_new', 'params' => ['{learner}', (string) $first['title'], $when, (string) count($sessionIds)],
         'idem' => 'timetable:' . $cohortId . ':' . (int) $first['id'] . ':' . count($sessionIds)]);
    return $r['told'];
}

/** A scheduling refusal in words. Library codes stay out of an operator's view. */
function mam_sched_error_text(string $code): string {
    $m = [
        'cohort_not_found' => 'That class could not be found.', 'pick_a_day' => 'Pick at least one weekday.',
        'bad_time' => 'Give a start time like 16:00.', 'bad_duration' => 'A session runs between 15 minutes and 8 hours.',
        'bad_dates' => 'Give both a first and a last date (or set them on the class or the term).',
        'to_before_from' => 'The last date is before the first.', 'range_too_long' => 'A series can cover at most about a year.',
        'bad_datetime' => 'Give a date and a start time.', 'session_not_found' => 'That session could not be found.',
        'not_scheduled' => 'Only a session that is still scheduled can be changed — this one has been held or cancelled.',
        'reason_required' => 'Give a reason — it is what families and the teacher are told.',
        'bad_cause' => 'Say who is cancelling: the teacher, a closure or the academy.',
        'illegal_transition' => 'That session can no longer be cancelled.', 'bad_feed' => 'That calendar could not be issued.',
    ];
    return $m[$code] ?? 'That could not be saved. Nothing was changed.';
}

/** How a session reads in a message: "Piano A · Tue 14 Jan, 16:00". */
function mam_sched_label(array $s, ?string $at = null): string {
    $when = $at ?? (string) ($s['scheduled_at'] ?? '');
    $name = trim((string) ($s['title'] ?? '')) !== '' ? (string) $s['title'] : (string) ($s['cohort_title'] ?? 'Class');
    return $name . ($when !== '' ? ' · ' . date('D j M, H:i', strtotime($when)) : '');
}

/**
 * Reschedule a session, checking the instructor and the room at the new time.
 * Refuses on a clash unless $force; tells everyone concerned when it moves.
 */
function mam_sched_reschedule(int $sessionId, string $newAt, string $reason, int $actorId, bool $force = false): array {
    $sess = mam_lms_session($sessionId);
    if (!$sess) throw new InvalidArgumentException('session_not_found');
    if ((string) $sess['status'] !== 'scheduled') throw new InvalidArgumentException('not_scheduled');
    $reason = trim($reason);
    if ($reason === '') throw new InvalidArgumentException('reason_required');
    $at = mam_sched_datetime($newAt);
    $co = mam_db_row('SELECT instructor_id FROM cohorts WHERE id = ?', [(int) $sess['cohort_id']]);
    $room = trim((string) ($sess['room'] ?? ''));
    if ($room === '' && !empty($sess['room_id'])) $room = mam_sched_room_name((int) $sess['room_id']);
    if (($sess['mode'] ?? '') === 'online') $room = '';
    $clashes = mam_sched_conflicts((int) ($co['instructor_id'] ?? 0), $at, (int) ($sess['duration_min'] ?: 60), $room, $sessionId, (int) $sess['cohort_id']);
    if ($clashes && !$force) return ['moved' => false, 'clashes' => $clashes, 'told' => 0];

    $old = (string) $sess['scheduled_at'];
    mam_lms_reschedule_session($sessionId, $at, mb_substr($reason, 0, 500), $actorId);
    try { mam_meeting_moved($sessionId); } catch (\Throwable $e) { mam_log('warn', 'meeting_move_failed', ['session' => $sessionId, 'err' => $e->getMessage()]); }
    $oldTxt = $old !== '' ? date('D j M, H:i', strtotime($old)) : 'unscheduled';
    $n = mam_sched_notify($sessionId, 'rescheduled', 'Class moved: ' . mam_sched_label($sess, $at),
        'Was ' . $oldTxt . '. Reason: ' . $reason,
        ['template' => 'session_rescheduled', 'params' => ['{learner}', (string) ($sess['title'] ?: $sess['cohort_title']), $oldTxt, date('D j M, H:i', strtotime($at)), $reason],
         'idem' => 'resched:' . $sessionId . ':' . $at]);
    return ['moved' => true, 'clashes' => $clashes, 'told' => $n['told'], 'emailed' => $n['emailed'], 'whatsapp' => $n['whatsapp']];
}

/**
 * Cancel a session. $cause: teacher | closure | academy. Grants each actively
 * enrolled learner a free make-up credit (never twice for the same session) and
 * tells everyone concerned. Never touches money.
 */
function mam_sched_cancel(int $sessionId, string $cause, string $reason, int $actorId): array {
    if (!isset(MAM_SCHED_CANCEL_CAUSES[$cause])) throw new InvalidArgumentException('bad_cause');
    $reason = trim($reason);
    if ($reason === '') throw new InvalidArgumentException('reason_required');
    $sess = mam_lms_session($sessionId);
    if (!$sess) throw new InvalidArgumentException('session_not_found');
    if ((string) $sess['status'] !== 'scheduled') throw new InvalidArgumentException('not_scheduled');

    mam_lms_set_session_status($sessionId, 'cancelled', $actorId);
    mam_db_update('class_sessions', ['cancellation_reason' => mb_substr(ucfirst($cause) . ': ' . $reason, 0, 500)], ['id' => $sessionId]);

    $makeupReason = MAM_SCHED_CANCEL_CAUSES[$cause];
    $credited = 0; $failed = 0;
    $termId = null;
    try { $t = mam_calendar_term_on(substr((string) $sess['scheduled_at'], 0, 10)); $termId = $t['id'] ?? null; } catch (Throwable $e) {}
    foreach (mam_sched_audience($sessionId)['students'] as $sid) {
        if (mam_db_row('SELECT id FROM makeup_credits WHERE student_id = ? AND source_session_id = ?', [$sid, $sessionId])) continue;
        try {
            mam_makeup_grant($sid, $makeupReason, ['session_id' => $sessionId, 'term_id' => $termId,
                'note' => 'Class cancelled (' . $cause . '): ' . mb_substr($reason, 0, 200)], $actorId);
            $credited++;
        } catch (Throwable $e) {
            $failed++;
            mam_log('warn', 'sched_cancel_credit_failed', ['session' => $sessionId, 'student' => $sid, 'err' => $e->getMessage()]);
        }
    }
    try { mam_meeting_withdraw($sessionId); } catch (\Throwable $e) { mam_log('warn', 'meeting_withdraw_failed', ['session' => $sessionId, 'err' => $e->getMessage()]); }
    $n = mam_sched_notify($sessionId, 'cancelled', 'Class cancelled: ' . mam_sched_label($sess),
        $reason . ($credited ? ' A free make-up class has been credited.' : ''),
        ['template' => 'session_cancelled', 'params' => ['{learner}', (string) ($sess['title'] ?: $sess['cohort_title']), mam_session_fmt((string) $sess['scheduled_at'], 'D j M, H:i'), $reason],
         'idem' => 'cancel:' . $sessionId]);
    mam_audit('sched_session_cancel', 'class_session', $sessionId, ['cause' => $cause, 'credited' => $credited, 'by' => $actorId]);
    return ['credited' => $credited, 'failed' => $failed, 'told' => $n['told'], 'emailed' => $n['emailed'], 'whatsapp' => $n['whatsapp']];
}

/**
 * The console's Integrations card: each platform, whether it is working, and
 * the one thing it needs. Reads only; never shows a secret, only whether one is set.
 */
function mam_sched_integrations(): array {
    $pending = (int) (mam_db_row("SELECT COUNT(*) n FROM class_sessions WHERE status = 'scheduled' AND mode IN ('online','hybrid')
                                   AND (meeting_link IS NULL OR meeting_link = '') AND scheduled_at >= ?", [mam_session_now()])['n'] ?? 0);
    $jaas = function_exists('mam_live_jaas_ready') && mam_live_jaas_ready();
    return [
        'provider' => (string) mam_setting('meeting_link_provider', 'auto'),
        'jaas_ready' => $jaas, 'jaas_app_id' => (string) mam_setting('jaas_app_id', ''),
        'order' => mam_meeting_provider_order(),
        'pending_links' => $pending,
        'list' => [
            ['key' => 'feeds', 'name' => 'Calendar subscriptions (Google, Apple, Outlook)', 'ready' => true,
             'needs' => 'Nothing. Families subscribe from their portal; rooms and classes from this screen.'],
            ['key' => 'jaas', 'name' => 'Live classes (JaaS, 8x8)', 'ready' => $jaas,
             'needs' => $jaas ? 'Nothing more. Each person joins with their own short-lived token; staff moderate, a lobby when children are present.'
                              : 'Live classes are NOT set up: add the JaaS app ID, key ID and private key below (jaas.8x8.vc → API keys). Until then online sessions show a setup notice.'],
            ['key' => 'meet', 'name' => 'Google Meet', 'ready' => mam_meeting_meet_ready(),
             'needs' => 'google_meet_sa_json (service-account key with domain-wide delegation for calendar.events) and google_workspace_admin (the delegated user) — set on Meeting reconciliation.'],
            ['key' => 'zoom', 'name' => 'Zoom', 'ready' => mam_meeting_zoom_ready(),
             'needs' => 'A Server-to-Server OAuth app: ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID, ZOOM_CLIENT_SECRET (environment, or the fields below), scope meeting:write.'],
            ['key' => 'kapso', 'name' => 'WhatsApp (Kapso)', 'ready' => mam_kapso_ready(),
             'needs' => 'KAPSO_API_KEY (environment, or below) and the Phone number ID. Templates: docs/whatsapp-templates.md.'],
            ['key' => 'email', 'name' => 'Email', 'ready' => trim((string) mam_env('MAIL_HOST', '')) !== '',
             'needs' => 'MAIL_HOST / MAIL_USERNAME / MAIL_PASSWORD in api/.env (without them PHP mail() is tried). In-app notifications always work.'],
        ],
        'kapso' => ['phone_number_id' => (string) mam_setting('kapso_phone_number_id', ''), 'key_set' => mam_kapso_cfg()['api_key'] !== '',
                    'secret_set' => mam_kapso_cfg()['webhook_secret'] !== '', 'students' => mam_kapso_cfg()['students'],
                    'sent_7d' => (int) (mam_db_row("SELECT COUNT(*) n FROM whatsapp_outbound WHERE status IN ('sent','delivered','read') AND created_at >= ?", [date('Y-m-d H:i:s', time() - 7 * 86400)])['n'] ?? 0),
                    'failed_7d' => (int) (mam_db_row("SELECT COUNT(*) n FROM whatsapp_outbound WHERE status = 'failed' AND created_at >= ?", [date('Y-m-d H:i:s', time() - 7 * 86400)])['n'] ?? 0)],
        'zoom' => ['account_set' => mam_meeting_zoom_cfg()['account_id'] !== '', 'client_set' => mam_meeting_zoom_cfg()['client_id'] !== ''],
    ];
}
