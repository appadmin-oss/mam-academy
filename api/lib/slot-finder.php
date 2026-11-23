<?php
/**
 * The slot finder: where should this class meet?
 *
 * mam_slot_score() is PURE — it reads nothing and writes nothing — so its
 * judgement is unit-tested and its ordering is deterministic. Everything it
 * weighs is gathered first by mam_slot_context():
 *   - the teacher: a clash on any date (with a turnaround buffer) rules a slot
 *     out; hours set in instructor_availability are a hard limit; the coarse
 *     'mon:morning' grid from their application is a preference;
 *   - rooms: the smallest free room that seats the class (online needs none);
 *   - learners: any enrolled learner already in another class then — from BOTH
 *     enrolment models (cohort sessions, and a legacy class's weekly
 *     `schedule` text) — costs heavily and is named;
 *   - siblings: a slot next to a brother's or sister's class is preferred, so a
 *     guardian makes one trip;
 *   - closures: holiday and break dates drop out (a slot with none left is out).
 * Every suggestion carries a sentence saying why.
 */
declare(strict_types=1);

const MAM_SLOT_BUFFER_MIN = 15;            // teacher turnaround / room changeover
const MAM_SLOT_SIBLING_GAP_MIN = 90;       // "next to" a sibling's class
const MAM_SLOT_DAY_NAMES = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];

/** Day part of a start time, in the application form's own words. */
function mam_slot_part(string $time): string {
    $h = (int) substr($time, 0, 2);
    return $h < 12 ? 'morning' : ($h < 17 ? 'afternoon' : 'evening');
}

/** Do [a1,a2) and [b1,b2) overlap once each is widened by $buf seconds? */
function mam_slot_overlaps(int $a1, int $a2, int $b1, int $b2, int $buf = 0): bool {
    return $a1 < $b2 + $buf && $b1 < $a2 + $buf;
}

/**
 * Score one candidate. Pure.
 * $cand: dow (0–6), time 'HH:MM', dur (min), dates ['Y-m-d', …].
 * $ctx:  see mam_slot_context(). Returns ok, score, room, dates, reasons, problems, sentence.
 */
function mam_slot_score(array $cand, array $ctx): array {
    $buf = (int) ($ctx['buffer'] ?? MAM_SLOT_BUFFER_MIN) * 60;
    $dur = (int) $cand['dur'] * 60;
    $score = 100; $ok = true; $reasons = []; $problems = [];
    $dates = []; $skipped = 0;
    foreach ($cand['dates'] as $d) {
        if (isset($ctx['closed'][$d])) { $skipped++; continue; }
        $dates[] = $d;
    }
    $intervals = array_map(fn($d) => [strtotime($d . ' ' . $cand['time'] . ':00'), strtotime($d . ' ' . $cand['time'] . ':00') + $dur], $dates);
    $room = null;
    // By reference: an arrow fn would capture these as they are NOW, not at return.
    $out = function () use (&$ok, &$score, &$room, &$dates, &$skipped, &$reasons, &$problems, $cand) {
        return ['ok' => $ok, 'score' => $score, 'room' => $room, 'dates' => $dates, 'skipped' => $skipped,
            'reasons' => $reasons, 'problems' => $problems, 'dow' => (int) $cand['dow'], 'time' => (string) $cand['time'], 'dur' => (int) $cand['dur']];
    };
    if (!$dates) { $ok = false; $problems[] = 'every date falls in a closure'; return mam_slot_sentence($out()); }
    if ($skipped) { $score -= $skipped; $reasons[] = $skipped . ' closed date' . ($skipped === 1 ? '' : 's') . ' skipped'; }

    // The teacher.
    $tBusy = 0;
    foreach ($intervals as [$s, $e]) foreach ($ctx['teacher_busy'] ?? [] as $b) if (mam_slot_overlaps($s, $e, (int) $b[0], (int) $b[1], $buf)) { $tBusy++; break; }
    if ($tBusy) { $ok = false; $problems[] = 'teacher busy on ' . $tBusy . ' date' . ($tBusy === 1 ? '' : 's'); }
    $win = $ctx['teacher_windows'] ?? null;
    if ($win) {
        $end = date('H:i', strtotime('2000-01-01 ' . $cand['time']) + $dur);
        $inside = false;
        foreach ($win as $w) if ((int) $w[0] === (int) $cand['dow'] && $cand['time'] >= $w[1] && $end <= $w[2]) { $inside = true; break; }
        if (!$inside) { $ok = false; $problems[] = 'outside the teacher\'s set hours'; }
    }
    $grid = $ctx['teacher_avail'] ?? null;
    if ($grid && !$tBusy) {
        $key = strtolower(MAM_SLOT_DAY_NAMES[(int) $cand['dow']]) . ':' . mam_slot_part((string) $cand['time']);
        if (!empty($grid[$key])) { $score += 10; $reasons[] = 'teacher free'; }
        else { $score -= 30; $problems[] = 'teacher did not list ' . $key . ' as free'; }
    } elseif (!$tBusy && $ok) {
        $reasons[] = 'teacher free';
    }

    // A room, unless the class is online.
    $room = null;
    if (($ctx['mode'] ?? 'onsite') !== 'online') {
        $size = (int) ($ctx['size'] ?? 0);
        $free = [];
        foreach ($ctx['rooms'] ?? [] as $r) {
            $clash = false;
            foreach ($intervals as [$s, $e]) { foreach ($r['busy'] ?? [] as $b) if (mam_slot_overlaps($s, $e, (int) $b[0], (int) $b[1], $buf)) { $clash = true; break 2; } }
            if (!$clash) $free[] = $r;
        }
        $fits = array_values(array_filter($free, fn($r) => $r['capacity'] === null || (int) $r['capacity'] >= $size));
        $byCap = fn($a, $b) => [(int) ($a['capacity'] ?? PHP_INT_MAX), (string) $a['name']] <=> [(int) ($b['capacity'] ?? PHP_INT_MAX), (string) $b['name']];
        if ($fits) {
            usort($fits, $byCap); $room = $fits[0];
            $reasons[] = $room['name'] . ($room['capacity'] !== null ? ' fits ' . (int) $room['capacity'] : ' is free');
        } elseif ($free) {
            usort($free, $byCap); $room = end($free);
            $score -= 40; $problems[] = 'largest free room (' . $room['name'] . ') seats ' . (int) $room['capacity'] . ' of ' . $size;
        } elseif (!empty($ctx['rooms'])) {
            $ok = false; $problems[] = 'no room free';
        } else {
            $score -= 5; $reasons[] = 'no rooms on file';
        }
    } else {
        $reasons[] = 'online, no room needed';
    }

    // Learners already elsewhere.
    $hit = [];
    foreach ($ctx['students'] ?? [] as $sid => $busy) {
        foreach ($intervals as [$s, $e]) { foreach ($busy as $b) if (mam_slot_overlaps($s, $e, (int) $b[0], (int) $b[1], 0)) { $hit[$sid] = true; break 2; } }
    }
    if ($hit) { $score -= 25 * count($hit); $problems[] = count($hit) . ' learner' . (count($hit) === 1 ? '' : 's') . ' already in another class then'; }
    elseif (!empty($ctx['students'])) { $reasons[] = 'no learner clashes'; }

    // Siblings next door.
    $near = 0; $gap = MAM_SLOT_SIBLING_GAP_MIN * 60;
    foreach ($intervals as [$s, $e]) foreach ($ctx['siblings'] ?? [] as $b) {
        if (!mam_slot_overlaps($s, $e, (int) $b[0], (int) $b[1], 0) && mam_slot_overlaps($s, $e, (int) $b[0], (int) $b[1], $gap)) $near++;
    }
    if ($near) { $score += min(24, 8 * $near); $reasons[] = 'next to a sibling\'s class'; }

    return mam_slot_sentence($out());
}

function mam_slot_sentence(array $r): array {
    $head = MAM_SLOT_DAY_NAMES[$r['dow']] . ' ' . date('g:ia', strtotime('2000-01-01 ' . $r['time']));
    $parts = $r['ok'] ? array_merge($r['reasons'], $r['problems']) : $r['problems'];
    $r['sentence'] = $head . ': ' . ($parts ? implode(', ', $parts) : 'no conflicts') . '.';
    return $r;
}

/** Deterministic ranking: usable first, then score, then Monday→Sunday, then earlier. */
function mam_slot_rank(array $scored): array {
    usort($scored, function ($a, $b) {
        $da = $a['dow'] === 0 ? 7 : $a['dow']; $db = $b['dow'] === 0 ? 7 : $b['dow'];
        return [(int) !$a['ok'], -$a['score'], $da, $a['time'], $a['dates'][0] ?? ''] <=> [(int) !$b['ok'], -$b['score'], $db, $b['time'], $b['dates'][0] ?? ''];
    });
    return $scored;
}

/* ── Gathering the context (reads) ──────────────────────────────────────── */

/** Weekly busy intervals from a legacy class's free-text schedule ("Sat 10:00", "Saturdays 4pm"). */
function mam_slot_legacy_busy(string $schedule, string $from, string $to): array {
    $map = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
    if (!preg_match('/\b(sun|mon|tue|wed|thu|fri|sat)[a-z]*\b.*?(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $schedule, $m)) return [];
    $h = (int) $m[2]; $min = (int) ($m[3] ?? 0); $ap = strtolower($m[4] ?? '');
    if ($ap === 'pm' && $h < 12) $h += 12; if ($ap === 'am' && $h === 12) $h = 0;
    if ($h > 23) return [];
    $dow = $map[strtolower(substr($m[1], 0, 3))]; $out = [];
    for ($t = strtotime($from . ' 12:00'); $t <= strtotime($to . ' 12:00'); $t += 86400) {
        if ((int) date('w', $t) !== $dow) continue;
        $s = strtotime(date('Y-m-d', $t) . sprintf(' %02d:%02d:00', $h, $min));
        $out[] = [$s, $s + 3600];
    }
    return $out;
}

/** Everything mam_slot_score() needs for one cohort between two dates. */
function mam_slot_context(int $cohortId, string $from, string $to, array $opts = []): array {
    $co = mam_db_row('SELECT id, instructor_id, session_duration FROM cohorts WHERE id = ?', [$cohortId]);
    if (!$co) throw new InvalidArgumentException('cohort_not_found');
    $excl = (int) ($opts['exclude_session'] ?? 0);
    $range = [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
    $iv = fn(array $rows) => array_map(fn($r) => [strtotime((string) $r['scheduled_at']), strtotime((string) $r['scheduled_at']) + max(15, (int) ($r['duration_min'] ?: 60)) * 60, (string) ($r['label'] ?? '')], $rows);
    $live = "cs.status = 'scheduled' AND cs.scheduled_at >= ? AND cs.scheduled_at < ? AND cs.id != ?";

    $tid = (int) ($co['instructor_id'] ?? 0);
    $teacherBusy = $tid ? $iv(mam_db_all("SELECT cs.scheduled_at, cs.duration_min, co.title label FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id
                                           WHERE $live AND (co.instructor_id = ? OR cs.substitute_id = ?)", array_merge($range, [$excl, $tid, $tid]))) : [];
    $windows = null; $grid = null;
    if ($tid) {
        $w = mam_db_all('SELECT day_of_week, start_time, end_time FROM instructor_availability WHERE instructor_id = ? AND active = 1', [$tid]);
        if ($w) $windows = array_map(fn($r) => [(int) $r['day_of_week'], substr((string) $r['start_time'], 0, 5), substr((string) $r['end_time'], 0, 5)], $w);
        $app = mam_db_row('SELECT ia.availability FROM instructor_applications ia JOIN users u ON LOWER(u.email) = LOWER(ia.email)
                            WHERE u.id = ? AND ia.availability IS NOT NULL ORDER BY ia.id DESC LIMIT 1', [$tid]);
        $keys = $app ? json_decode((string) $app['availability'], true) : null;
        if (is_array($keys) && $keys) $grid = array_fill_keys(array_map('strval', $keys), true);
    }

    $rooms = [];
    foreach (mam_db_all('SELECT id, name, capacity FROM rooms WHERE active = 1 ORDER BY name') as $r) {
        $rooms[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'capacity' => $r['capacity'] !== null ? (int) $r['capacity'] : null,
            'busy' => $iv(mam_db_all("SELECT cs.scheduled_at, cs.duration_min FROM class_sessions cs WHERE $live AND COALESCE(cs.mode,'onsite') != 'online'
                                       AND (cs.room_id = ? OR (cs.room_id IS NULL AND cs.room = ?))", array_merge($range, [$excl, (int) $r['id'], (string) $r['name']])))];
    }

    $studentIds = array_map('intval', array_column(mam_db_all("SELECT DISTINCT student_id FROM course_enrollments WHERE cohort_id = ? AND status IN ('active','pending')", [$cohortId]), 'student_id'));
    $students = [];
    foreach ($studentIds as $sid) {
        $busy = $iv(mam_db_all("SELECT cs.scheduled_at, cs.duration_min FROM class_sessions cs
                                 WHERE $live AND cs.cohort_id != ? AND cs.cohort_id IN (SELECT cohort_id FROM course_enrollments WHERE student_id = ? AND status = 'active')",
                                array_merge($range, [$excl, $cohortId, $sid])));
        foreach (mam_db_all("SELECT c.schedule FROM enrollments e JOIN classes c ON c.id = e.class_id WHERE e.student_id = ? AND e.status = 'active' AND c.schedule IS NOT NULL", [$sid]) as $lc) {
            foreach (mam_slot_legacy_busy((string) $lc['schedule'], $from, $to) as $b) $busy[] = $b;
        }
        if ($busy) $students[$sid] = $busy;
    }

    $siblings = [];
    if ($studentIds) {
        $in = implode(',', array_fill(0, count($studentIds), '?'));
        $sibIds = array_map('intval', array_column(mam_db_all("SELECT DISTINCT p2.student_id FROM parent_links p1 JOIN parent_links p2 ON p2.parent_id = p1.parent_id
                                                                WHERE p1.student_id IN ($in) AND p2.student_id NOT IN ($in)", array_merge($studentIds, $studentIds)), 'student_id'));
        foreach ($sibIds as $sib) {
            foreach ($iv(mam_db_all("SELECT cs.scheduled_at, cs.duration_min FROM class_sessions cs WHERE $live
                                      AND cs.cohort_id IN (SELECT cohort_id FROM course_enrollments WHERE student_id = ? AND status = 'active')", array_merge($range, [$excl, $sib]))) as $b) $siblings[] = $b;
        }
    }

    $closed = [];
    for ($t = strtotime($from . ' 12:00'); $t <= strtotime($to . ' 12:00'); $t += 86400) {
        $d = date('Y-m-d', $t);
        if ($c = mam_sched_closure_on($d)) $closed[$d] = (string) $c['label'];
    }

    return ['size' => count($studentIds), 'mode' => (string) ($opts['mode'] ?? 'onsite'), 'buffer' => MAM_SLOT_BUFFER_MIN,
        'closed' => $closed, 'teacher_busy' => $teacherBusy, 'teacher_windows' => $windows, 'teacher_avail' => $grid,
        'rooms' => $rooms, 'students' => $students, 'siblings' => $siblings, 'duration' => (int) ($co['session_duration'] ?: 60)];
}

/** Candidate start times: on the hour, 08:00 until a class still ends by 21:00. */
function mam_slot_times(int $dur): array {
    $out = [];
    for ($m = 8 * 60; $m + $dur <= 21 * 60; $m += 60) $out[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    return $out;
}

/** The best weekly slots for a cohort over a date range. */
function mam_slot_suggest_series(int $cohortId, array $opts = []): array {
    $from = (string) ($opts['from'] ?? date('Y-m-d', strtotime('monday next week')));
    $to = (string) ($opts['to'] ?? date('Y-m-d', strtotime($from . ' +55 days')));
    // Judge on at most eight weeks: enough to see a pattern, cheap enough to run on a page load.
    $judgeTo = min($to, date('Y-m-d', strtotime($from . ' +55 days')));
    $ctx = mam_slot_context($cohortId, $from, $judgeTo, $opts);
    $dur = (int) ($opts['duration'] ?? 0) ?: $ctx['duration'];
    $days = $opts['days'] ?? [1, 2, 3, 4, 5, 6];
    $scored = [];
    foreach ($days as $dow) {
        $dates = [];
        for ($t = strtotime($from . ' 12:00'); $t <= strtotime($judgeTo . ' 12:00'); $t += 86400) if ((int) date('w', $t) === (int) $dow) $dates[] = date('Y-m-d', $t);
        if (!$dates) continue;
        foreach (mam_slot_times($dur) as $time) $scored[] = mam_slot_score(['dow' => (int) $dow, 'time' => $time, 'dur' => $dur, 'dates' => $dates], $ctx);
    }
    return array_slice(array_values(array_filter(mam_slot_rank($scored), fn($s) => $s['ok'])), 0, (int) ($opts['limit'] ?? 5));
}

/** The best one-off make-up slots for a session, in the two weeks after it (or after today). */
function mam_slot_suggest_makeup(int $sessionId, int $limit = 3): array {
    $s = mam_db_row('SELECT id, cohort_id, scheduled_at, duration_min, mode FROM class_sessions WHERE id = ?', [$sessionId]);
    if (!$s) return [];
    $start = max(strtotime(date('Y-m-d', strtotime((string) $s['scheduled_at']))), strtotime(date('Y-m-d'))) + 86400;
    $from = date('Y-m-d', $start); $to = date('Y-m-d', $start + 13 * 86400);
    $ctx = mam_slot_context((int) $s['cohort_id'], $from, $to, ['exclude_session' => $sessionId, 'mode' => (string) ($s['mode'] ?: 'onsite')]);
    $dur = (int) ($s['duration_min'] ?: 60);
    $scored = [];
    for ($t = $start; $t <= strtotime($to); $t += 86400) {
        $d = date('Y-m-d', $t); $dow = (int) date('w', $t);
        if ($dow === 0) continue;
        foreach (mam_slot_times($dur) as $time) {
            $x = mam_slot_score(['dow' => $dow, 'time' => $time, 'dur' => $dur, 'dates' => [$d]], $ctx);
            // Families already keep the class's own hour free: nearer it is better.
            $x['score'] -= intdiv(abs(strtotime('2000-01-01 ' . $time) - strtotime('2000-01-01 ' . substr((string) $s['scheduled_at'], 11, 5))), 3600);
            $scored[] = $x;
        }
    }
    $ranked = array_values(array_filter(mam_slot_rank($scored), fn($x) => $x['ok']));
    // At most one suggestion per day: three times on one Tuesday is one suggestion, not three.
    $seen = []; $out = [];
    foreach ($ranked as $x) { if (isset($seen[$x['dates'][0]])) continue; $seen[$x['dates'][0]] = true; $out[] = $x; if (count($out) >= $limit) break; }
    return $out;
}
