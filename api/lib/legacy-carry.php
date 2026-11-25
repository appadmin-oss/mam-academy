<?php
/**
 * The old class model, carried into cohorts and then never read again.
 *
 * MAM ran two enrolment models side by side for its whole life (CLAUDE.md gotcha #2): the OLD
 * `classes` + `enrollments` + `attendance`, and the cohort model every new screen uses. Two
 * models meant every roster, register and report had to ask both and add them up, and an admin
 * had to know which one a student was in. The owner retired the old one (Nov 2026).
 *
 * Retired means carried, not deleted. Everything the old tables hold that the cohort model can
 * hold is copied across, and the old tables stay where they are, unread, so a mistake can be
 * re-run rather than restored. (Dropping them is not safe on SQLite anyway: with foreign keys
 * on, DROP TABLE classes deletes every announcement and resource that names a class.)
 *
 * What goes where:
 *   class           → its cohort (cohorts.legacy_class_id; created here if it has none)
 *   class's subject → the cohort's course, matched on the course code or name; a class whose
 *                     discipline names no course is LISTED, and an admin picks the course
 *   enrolment       → course_enrollments, keeping its state (active, paused → active;
 *                     completed; withdrawn), skipped where the student already has one
 *   attendance      → a held class_session per (cohort, date) + session_attendance,
 *                     method 'legacy', never overwriting a mark already there
 *   announcement, resource, homework → cohort_id set from the class
 *   meeting link    → the cohort's scheduled sessions that have none
 *
 * Idempotent: a second run finds everything already there and changes nothing.
 */
declare(strict_types=1);

/** Old enrolment states, as the cohort model says them. Anything else is left behind, and counted. */
const MAM_LEGACY_STATUS_MAP = [
    'active' => 'active', 'paused' => 'active', 'pending' => 'pending',
    'completed' => 'completed', 'withdrawn' => 'withdrawn', 'dropped' => 'withdrawn',
];

/** The course a class's discipline names, by code then name, ignoring case; null when none does. */
function mam_legacy_course_for(?string $discipline): ?int {
    $d = strtolower(trim((string) $discipline));
    if ($d === '') return null;
    foreach (['LOWER(code)', 'LOWER(name)'] as $col) {
        $r = mam_db_row("SELECT id FROM courses WHERE $col = ? ORDER BY active DESC, id LIMIT 1", [$d]);
        if ($r) return (int) $r['id'];
    }
    return null;
}

/** Old classes still to carry, and why each is waiting: no course yet, or simply not run. */
function mam_legacy_pending(): array {
    return mam_db_all(
        "SELECT c.id class_id, c.title, c.discipline, c.status, co.id cohort_id, co.course_id,
                (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = c.id) students,
                (SELECT COUNT(*) FROM attendance a JOIN enrollments e ON e.id = a.enrollment_id WHERE e.class_id = c.id) marks
           FROM classes c LEFT JOIN cohorts co ON co.legacy_class_id = c.id
          WHERE co.id IS NULL OR co.course_id IS NULL
             OR EXISTS (SELECT 1 FROM enrollments e WHERE e.class_id = c.id
                         AND NOT EXISTS (SELECT 1 FROM course_enrollments ce
                                          WHERE ce.student_id = e.student_id AND ce.course_id = co.course_id))
          ORDER BY c.id"
    );
}

/**
 * Carry the old class model into cohorts. `$courseFor` maps a class id to a course id an admin
 * chose, for classes whose discipline names no course. Returns what was carried and what waits.
 */
function mam_legacy_carry(?int $actorId = null, array $courseFor = []): array {
    $out = ['cohorts' => 0, 'mapped' => 0, 'enrolments' => 0, 'sessions' => 0, 'marks' => 0,
            'linked' => 0, 'left' => 0, 'waiting' => []];
    $out['cohorts'] = mam_lms_backfill_cohorts();

    foreach (mam_db_all('SELECT co.id, co.course_id, co.legacy_class_id, co.instructor_id, co.title, c.discipline, c.meeting_link
                           FROM cohorts co JOIN classes c ON c.id = co.legacy_class_id') as $co) {
        $cid = (int) $co['id'];
        $classId = (int) $co['legacy_class_id'];

        // The class's meeting link goes onto the cohort's sessions still to come that have
        // none, so a family's bookmarked /class/{id}/join still opens the class.
        if (trim((string) ($co['meeting_link'] ?? '')) !== '') {
            $st = mam_db()->prepare("UPDATE class_sessions SET meeting_link = ?
                                      WHERE cohort_id = ? AND status = 'scheduled' AND COALESCE(meeting_link, '') = ''");
            $st->execute([trim((string) $co['meeting_link']), $cid]);
        }

        // Notices, files and homework follow the class whether or not it has a course yet.
        foreach (['announcements', 'class_resources', 'assignments'] as $t) {
            $st = mam_db()->prepare("UPDATE $t SET cohort_id = ? WHERE class_id = ? AND cohort_id IS NULL");
            $st->execute([$cid, $classId]);
            $out['linked'] += $st->rowCount();
        }

        $courseId = (int) ($co['course_id'] ?? 0);
        if ($courseId <= 0) {
            $chosen = (int) ($courseFor[$classId] ?? 0);
            $courseId = $chosen > 0 && mam_db_row('SELECT id FROM courses WHERE id = ?', [$chosen])
                ? $chosen : (int) (mam_legacy_course_for($co['discipline']) ?? 0);
            if ($courseId <= 0) {
                $out['waiting'][] = ['class_id' => $classId, 'cohort_id' => $cid, 'title' => (string) $co['title'],
                                     'discipline' => (string) $co['discipline']];
                continue;
            }
            mam_db_update('cohorts', ['course_id' => $courseId], ['id' => $cid]);
            $out['mapped']++;
        }

        // Enrolments, keeping their state. One per student and course: an existing enrolment
        // in the course wins and is linked to this cohort if it has none.
        $ceFor = [];
        foreach (mam_db_all('SELECT id, student_id, status, enrolled_at FROM enrollments WHERE class_id = ?', [$classId]) as $e) {
            $sid = (int) $e['student_id'];
            $status = MAM_LEGACY_STATUS_MAP[strtolower((string) $e['status'])] ?? null;
            $have = mam_db_row('SELECT id, cohort_id FROM course_enrollments WHERE student_id = ? AND course_id = ?
                                 ORDER BY (cohort_id = ?) DESC, id DESC LIMIT 1', [$sid, $courseId, $cid]);
            if ($have) {
                if (empty($have['cohort_id'])) mam_db_update('course_enrollments', ['cohort_id' => $cid], ['id' => (int) $have['id']]);
                $ceFor[(int) $e['id']] = $sid;
                continue;
            }
            if ($status === null) { $out['left']++; continue; }
            $id = mam_db_insert('course_enrollments', [
                'student_id' => $sid, 'course_id' => $courseId, 'cohort_id' => $cid,
                'instructor_id' => $co['instructor_id'] !== null ? (int) $co['instructor_id'] : null,
                'status' => $status, 'approved_by' => $actorId,
                'approved_at' => $status === 'pending' ? null : date('Y-m-d H:i:s'),
                'created_at' => $e['enrolled_at'] ?: date('Y-m-d H:i:s'),
            ]);
            mam_lms_log_event((int) $id, null, $status, $actorId, 'carried_from_class_' . $classId);
            $ceFor[(int) $e['id']] = $sid;
            $out['enrolments']++;
        }

        // Attendance: one held session per date the class met, then the marks. A mark already
        // on that session (a teacher's, say) is never overwritten.
        $sessions = [];
        foreach (mam_db_all('SELECT a.enrollment_id, a.session_date, a.status, a.notes, a.marked_by, a.created_at
                               FROM attendance a JOIN enrollments e ON e.id = a.enrollment_id
                              WHERE e.class_id = ? ORDER BY a.session_date, a.id', [$classId]) as $a) {
            $sid = $ceFor[(int) $a['enrollment_id']] ?? null;
            if ($sid === null) continue;
            $day = substr((string) $a['session_date'], 0, 10);
            if (!isset($sessions[$day])) {
                $s = mam_db_row('SELECT id FROM class_sessions WHERE cohort_id = ? AND SUBSTR(scheduled_at, 1, 10) = ? ORDER BY id LIMIT 1', [$cid, $day]);
                if (!$s) {
                    $sessions[$day] = (int) mam_db_insert('class_sessions', [
                        'cohort_id' => $cid, 'title' => (string) $co['title'], 'scheduled_at' => $day . ' 00:00:00',
                        'status' => 'held', 'notes' => 'Carried from the old class register.', 'created_by' => $actorId,
                    ]);
                    $out['sessions']++;
                } else {
                    $sessions[$day] = (int) $s['id'];
                }
            }
            if (mam_db_row('SELECT id FROM session_attendance WHERE session_id = ? AND student_id = ?', [$sessions[$day], $sid])) continue;
            mam_db_insert('session_attendance', [
                'session_id' => $sessions[$day], 'student_id' => $sid, 'status' => (string) $a['status'],
                'notes' => $a['notes'], 'marked_by' => $a['marked_by'], 'method' => 'legacy',
                'created_at' => $a['created_at'] ?: date('Y-m-d H:i:s'),
            ]);
            $out['marks']++;
        }
    }
    $did = $out['cohorts'] + $out['mapped'] + $out['enrolments'] + $out['marks'] + $out['linked'];
    if ($did) mam_audit('legacy_carry', 'cohort', null, array_diff_key($out, ['waiting' => 1]) + ['waiting' => count($out['waiting'])]);
    return $out;
}
