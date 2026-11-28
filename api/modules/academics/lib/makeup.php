<?php
/**
 * MAM Academy — make-up class suggestions (Advanced Modernization §8.2).
 * ─────────────────────────────────────────────────────────────
 * Missed a session → propose eligible upcoming sessions (same course + level,
 * any cohort) the student could attend as a make-up. Turns an absence into a
 * resolved next step; booking rides the existing reschedule flow.
 */
declare(strict_types=1);

function mam_makeup_suggestions(int $studentId, int $limit = 5): array {
    $missed = mam_db_all(
        "SELECT cs.id, cs.title, cs.scheduled_at, co.course_id, co.level_id
           FROM session_attendance sa
           JOIN class_sessions cs ON cs.id = sa.session_id
           JOIN cohorts co ON co.id = cs.cohort_id
          WHERE sa.student_id = ? AND sa.status = 'absent'
          ORDER BY cs.scheduled_at DESC LIMIT 15",
        [$studentId]
    );
    if (!$missed) return [];
    $now = mam_session_now(); // compared with scheduled_at
    $out = [];
    foreach ($missed as $m) {
        if ($m['course_id'] === null) continue; // can't match a make-up without a course
        $opts = mam_db_all(
            "SELECT cs.id, cs.title, cs.scheduled_at, co.title AS cohort_title, iu.name AS instructor_name
               FROM class_sessions cs
               JOIN cohorts co ON co.id = cs.cohort_id
          LEFT JOIN users iu ON iu.id = co.instructor_id
              WHERE cs.scheduled_at > ? AND cs.status != 'cancelled'
                AND co.course_id = ? AND (co.level_id = ? OR ? IS NULL OR co.level_id IS NULL)
              ORDER BY cs.scheduled_at ASC LIMIT 3",
            [$now, $m['course_id'], $m['level_id'], $m['level_id']]
        );
        if ($opts) {
            $out[] = [
                'missed'  => ['id' => (int) $m['id'], 'title' => $m['title'], 'scheduled_at' => $m['scheduled_at']],
                'options' => $opts,
            ];
        }
        if (count($out) >= $limit) break;
    }
    return $out;
}
