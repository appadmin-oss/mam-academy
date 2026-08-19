<?php
/**
 * MAM Academy — the unified student academic record (PDF §43).
 * ─────────────────────────────────────────────────────────────
 * §43 asks for ONE record per student containing thirteen named sections. Every
 * one of those sections already existed somewhere in this codebase — that was
 * never the gap. The gap was that answering "show me everything about this
 * student" meant knowing which of a dozen libraries to call, and no two callers
 * assembled the same picture.
 *
 * This file is the assembly point, and it is deliberately READ-ONLY: it owns no
 * table and writes nothing. Each section delegates to the library that owns
 * that data, so the record cannot drift from the surfaces it summarises.
 *
 * Sections are returned in §43's own order and under §43's own names, so the
 * record can be read against the specification without translation.
 */
declare(strict_types=1);

/** The §43 section keys, in order. Exposed so a UI can render them generically. */
const MAM_RECORD_SECTIONS = [
    'personal_profile' => 'Personal profile',
    'curriculum'       => 'Curriculum',
    'preparation'      => 'Preparation',
    'teachback'        => 'Teach-back',
    'attendance'       => 'Attendance',
    'time'             => 'Time',
    'evidence'         => 'Evidence',
    'competency'       => 'Competency',
    'mastery'          => 'Mastery',
    'intervention'     => 'Intervention',
    'carry_forward'    => 'Carry-forward',
    'communication'    => 'Communication',
    'accountability'   => 'Accountability',
    'certification'    => 'Certification',
];

/**
 * The complete §43 record for a student. Throws `not_found` for an unknown or
 * non-student id rather than returning an empty shell, so a caller never
 * renders a blank record for a typo'd id.
 */
function mam_academic_record(int $studentId): array {
    $user = mam_db_row('SELECT id, name, email, role, status, avatar_url, student_type, created_at FROM users WHERE id = ?', [$studentId]);
    if (!$user || (string) $user['role'] !== 'student') throw new RuntimeException('not_found');

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'sections'     => MAM_RECORD_SECTIONS,

        'personal_profile' => [
            'id'         => (int) $user['id'],
            'name'       => $user['name'],
            'email'      => $user['email'],
            'status'     => $user['status'],
            'avatar_url' => $user['avatar_url'],
            'scholarship'=> function_exists('mam_user_is_scholarship') ? mam_user_is_scholarship($user) : null,
            'joined'     => $user['created_at'],
            'guardians'  => function_exists('mam_parent_guardians_of') ? mam_parent_guardians_of($studentId) : [],
        ],

        'curriculum'   => mam_record_curriculum($studentId),
        'preparation'  => mam_record_preparation($studentId),
        'teachback'    => mam_record_teachback($studentId),
        'attendance'   => mam_record_attendance($studentId),
        'time'         => mam_record_time($studentId),
        'evidence'     => mam_record_evidence($studentId),
        'competency'   => mam_record_competency($studentId),
        'mastery'      => function_exists('mam_mastery_score') ? mam_mastery_score_cached($studentId) : null,
        'intervention' => mam_record_intervention($studentId),
        'carry_forward'=> mam_record_carry_forward($studentId),
        'communication'=> mam_record_communication($studentId),
        'accountability'=> function_exists('mam_accountability_ledger') ? mam_accountability_ledger('student', $studentId, 100) : null,
        'certification'=> mam_record_certification($studentId),
    ];
}

/** Programme, instrument, level and modules — across BOTH enrolment models. */
function mam_record_curriculum(int $studentId): array {
    $courses = mam_db_all(
        "SELECT ce.id, ce.status, ce.created_at, c.code AS course_code, c.name AS course_title,
                lv.code AS level_code, co.title AS cohort_title, co.id AS cohort_id, p.name AS programme
           FROM course_enrollments ce
           LEFT JOIN courses  c  ON c.id  = ce.course_id
           LEFT JOIN levels   lv ON lv.id = ce.level_id
           LEFT JOIN cohorts  co ON co.id = ce.cohort_id
           LEFT JOIN programs p  ON p.id  = ce.program_id
          WHERE ce.student_id = ?
          ORDER BY ce.id DESC",
        [$studentId]
    );
    // Legacy classes too — a student can appear in one model and not the other
    // (see CLAUDE.md gotcha #2), and a record that shows only half is wrong.
    $classes = mam_db_all(
        "SELECT e.id, e.status, cl.title AS class_title, cl.id AS class_id
           FROM enrollments e JOIN classes cl ON cl.id = e.class_id
          WHERE e.student_id = ? ORDER BY e.id DESC",
        [$studentId]
    );
    return ['course_enrollments' => $courses, 'legacy_classes' => $classes];
}

/** Pre-class preparation history, module by module. */
function mam_record_preparation(int $studentId): array {
    $rows = mam_db_all(
        "SELECT lp.*, l.title AS lesson_title, l.week_no
           FROM lesson_progress lp LEFT JOIN lessons l ON l.id = lp.lesson_id
          WHERE lp.student_id = ? ORDER BY l.week_no ASC, lp.id ASC",
        [$studentId]
    );
    $done = 0;
    foreach ($rows as $r) if ((int) ($r['percent'] ?? 0) >= 100) $done++;
    return [
        'modules'       => $rows,
        'total'         => count($rows),
        'completed'     => $done,
        'completion_pct'=> $rows ? round($done / count($rows) * 100, 1) : null,
    ];
}

/** The student's DECLARE step: every teach-back and how it was assessed. */
function mam_record_teachback(int $studentId): array {
    $rows = mam_db_all(
        "SELECT tb.*, l.title AS lesson_title, u.name AS reviewer_name
           FROM teachbacks tb
           LEFT JOIN lessons l ON l.id = tb.lesson_id
           LEFT JOIN users u   ON u.id = tb.reviewer_id
          WHERE tb.student_id = ? ORDER BY tb.id DESC",
        [$studentId]
    );
    $reviewed = 0;
    foreach ($rows as $r) if ((string) $r['status'] === 'reviewed') $reviewed++;
    return ['submissions' => $rows, 'total' => count($rows), 'reviewed' => $reviewed];
}

/** Attendance across all §16 modes, counted by every §12 status. */
function mam_record_attendance(int $studentId): array {
    $rows = mam_db_all(
        "SELECT sa.*, cs.title AS session_title, cs.scheduled_at, cs.duration_min AS scheduled_min
           FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id
          WHERE sa.student_id = ? ORDER BY cs.scheduled_at DESC LIMIT 300",
        [$studentId]
    );
    $byStatus = array_fill_keys(defined('MAM_ATTENDANCE_STATUSES') ? MAM_ATTENDANCE_STATUSES : ['present', 'absent', 'late', 'excused'], 0);
    $byMode   = ['onsite' => 0, 'online' => 0, 'hybrid' => 0];
    foreach ($rows as $r) {
        $s = (string) $r['status']; $m = (string) ($r['mode'] ?? 'onsite');
        if (isset($byStatus[$s])) $byStatus[$s]++;
        if (isset($byMode[$m]))   $byMode[$m]++;
    }
    $total = count($rows);
    $present = ($byStatus['present'] ?? 0) + ($byStatus['late'] ?? 0) + ($byStatus['left_early'] ?? 0);
    return [
        'sessions'   => $rows,
        'total'      => $total,
        'by_status'  => $byStatus,
        'by_mode'    => $byMode,
        'attendance_pct' => $total > 0 ? round($present / $total * 100, 1) : null,
    ];
}

/** §10's clock: instructional time actually recorded, not merely scheduled. */
function mam_record_time(int $studentId): array {
    $row = mam_db_row(
        "SELECT COUNT(*) AS clocked,
                SUM(CASE WHEN clocked_out_at IS NOT NULL THEN 1 ELSE 0 END) AS completed,
                COALESCE(SUM(duration_min),0) AS minutes,
                COALESCE(SUM(minutes_late),0) AS late_minutes
           FROM session_attendance WHERE student_id = ? AND checked_in_at IS NOT NULL",
        [$studentId]
    );
    $completed = (int) ($row['completed'] ?? 0);
    return [
        'sessions_clocked_in'  => (int) ($row['clocked'] ?? 0),
        'sessions_clocked_out' => $completed,
        'total_minutes'        => (int) ($row['minutes'] ?? 0),
        'avg_minutes'          => $completed > 0 ? round((int) $row['minutes'] / $completed, 1) : null,
        'total_late_minutes'   => (int) ($row['late_minutes'] ?? 0),
    ];
}

/** §22 evidence: submissions and the files attached to them. */
function mam_record_evidence(int $studentId): array {
    $rows = mam_db_all(
        "SELECT s.id, s.assignment_id, s.artifact_url, s.artifact_kind, s.note, s.status, s.submitted_at, a.title AS assignment_title
           FROM submissions s LEFT JOIN assignments a ON a.id = s.assignment_id
          WHERE s.student_id = ? ORDER BY s.id DESC LIMIT 200",
        [$studentId]
    );
    if ($rows) {
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $files = mam_db_all("SELECT * FROM submission_files WHERE submission_id IN ($ph) ORDER BY id ASC", $ids);
        $by = [];
        foreach ($files as $f) $by[(int) $f['submission_id']][] = $f;
        foreach ($rows as &$r) $r['files'] = $by[(int) $r['id']] ?? [];
        unset($r);
    }
    return ['submissions' => $rows, 'total' => count($rows)];
}

/** §20 competency scoring: every rubric review, criterion by criterion. */
function mam_record_competency(int $studentId): array {
    $rows = mam_db_all(
        "SELECT sr.id, sr.rubric_id, sr.scores_json, sr.total_points, sr.max_points, sr.feedback, sr.created_at,
                r.title AS rubric_title, u.name AS reviewer_name, s.id AS submission_id
           FROM submission_reviews sr
           JOIN submissions s ON s.id = sr.submission_id
           LEFT JOIN rubrics r ON r.id = sr.rubric_id
           LEFT JOIN users u   ON u.id = sr.reviewer_id
          WHERE s.student_id = ? ORDER BY sr.id DESC LIMIT 100",
        [$studentId]
    );
    return [
        'reviews'    => $rows,
        'components' => function_exists('mam_mastery_components') ? array_values(mam_mastery_components($studentId)) : [],
    ];
}

/** The student's row from the §31 intervention list, or null if not flagged. */
function mam_record_intervention(int $studentId): ?array {
    if (!function_exists('mam_analytics2_intervention_list')) return null;
    foreach (mam_analytics2_intervention_list(500) as $row) {
        if ((int) $row['student_id'] === $studentId) return $row;
    }
    return null;
}

/** §29's extended-module history — appended, never overwritten. */
function mam_record_carry_forward(int $studentId): array {
    return mam_db_all(
        "SELECT cf.*, l.title AS lesson_title, u.name AS created_by_name
           FROM module_carryforward cf
           LEFT JOIN lessons l ON l.id = cf.lesson_id
           LEFT JOIN users u   ON u.id = cf.created_by
          WHERE cf.student_id = ? ORDER BY cf.id DESC",
        [$studentId]
    );
}

/**
 * Notifications and academic mail actually sent about this student. mail_log is
 * keyed by recipient address, not user id, so it is joined on the address the
 * account currently holds — a student who changed email keeps the notification
 * history either way, and the mail half is disclosed as address-scoped rather
 * than being quietly incomplete.
 */
function mam_record_communication(int $studentId): array {
    $email = (string) (mam_db_row('SELECT email FROM users WHERE id = ?', [$studentId])['email'] ?? '');
    return [
        'notifications' => mam_db_all(
            'SELECT id, type, title, body, created_at, read_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 50',
            [$studentId]
        ),
        'emails' => $email === '' ? [] : mam_db_all(
            "SELECT id, context, subject, status, created_at FROM mail_log
              WHERE to_email = ? ORDER BY id DESC LIMIT 50",
            [$email]
        ),
        'emails_scope' => 'Matched on the account\'s current email address.',
    ];
}

/** Certificates issued, and readiness for the ones that are not. */
function mam_record_certification(int $studentId): array {
    $certs = mam_db_all(
        "SELECT ct.reference, ct.kind, ct.title, ct.issued_at, p.name AS programme
           FROM certificates ct
           LEFT JOIN programs p ON p.id = ct.program_id
          WHERE ct.student_id = ? ORDER BY ct.id DESC",
        [$studentId]
    );
    $prep = mam_record_preparation($studentId);
    $mast = function_exists('mam_mastery_score') ? mam_mastery_score_cached($studentId) : null;
    // Readiness is advisory, not a gate: it says what the record currently
    // supports, and the academy still decides.
    $ready = $prep['completion_pct'] !== null && $prep['completion_pct'] >= 80
          && $mast !== null && $mast['score'] !== null && $mast['score'] >= 60;
    return [
        'certificates'  => $certs,
        'module_completion_pct' => $prep['completion_pct'],
        'mastery_score' => $mast['score'] ?? null,
        'ready'         => $ready,
        'note'          => $ready
            ? 'Module completion and mastery both meet the certification threshold.'
            /* Three different reasons this can be "not yet", and naming the right
               one saves an instructor going to look for the wrong thing. The
               third is new: below its declared coverage floor the Mastery Score
               is WITHHELD rather than reported, so there is no number to compare
               against 60 — and certifying a student on a composite the system
               itself will not publish would be exactly backwards. */
            : ($mast !== null && ($mast['sufficient'] ?? true) === false
                ? 'Not enough of the assessment has been covered yet to produce a Mastery Score ('
                  . (int) round((float) ($mast['coverage_pct'] ?? 0)) . '% of '
                  . (int) round((float) ($mast['min_coverage'] ?? 0)) . '% needed), so readiness cannot be judged. '
                  . 'Score more of the student\'s work first.'
                : 'Certification readiness needs 80% module completion and a Mastery Score of 60 or above.'),
    ];
}
