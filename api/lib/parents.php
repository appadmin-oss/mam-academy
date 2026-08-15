<?php
/**
 * MAM Academy — parent accounts + guardian portal (LMS 2.0, PDF §26).
 * ─────────────────────────────────────────────────────────────
 * A parent is a real user (role 'parent'); parent_links maps guardians to
 * students (many-to-many). Parents are provisioned by staff and see ONLY their
 * linked children. The child summary is the §26 weekly picture — preparation,
 * teach-back, attendance and mastery — assembled from the existing engines
 * (check-in, pre-class, grading) so there is one source of truth.
 */
declare(strict_types=1);

/** Create (or return) a parent user for an email; grants the 'parent' role.
 *  Returns ['user' => row, 'password' => tempOrNull]. */
function mam_parent_provision(string $email, ?string $name = null, ?string $phone = null): array {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('bad_email');
    $existing = mam_db_row('SELECT * FROM users WHERE email = ?', [$email]);
    if ($existing) {
        mam_user_ensure_role((int) $existing['id'], 'parent');
        return ['user' => $existing, 'password' => null];
    }
    $plain = bin2hex(random_bytes(5));
    $userId = mam_db_insert('users', [
        'email'         => $email,
        'password_hash' => password_hash($plain, PASSWORD_BCRYPT),
        'role'          => 'parent',
        'name'          => $name !== null && trim($name) !== '' ? trim($name) : 'Parent/Guardian',
        'phone'         => $phone,
        'status'        => 'active',
        'must_change_password' => 1,
    ]);
    mam_user_ensure_role($userId, 'parent');
    mam_audit('create', 'user', $userId, ['role' => 'parent']);
    return ['user' => mam_db_row('SELECT * FROM users WHERE id = ?', [$userId]), 'password' => $plain];
}

/** Link a guardian (by email) to a student. Provisions the parent if needed.
 *  Idempotent. Returns ['parent_id','student_id','password','created']. */
function mam_parent_link(string $parentEmail, int $studentId, string $relationship = 'guardian', ?int $by = null): array {
    if ($studentId <= 0) throw new InvalidArgumentException('bad_student');
    $stu = mam_db_row("SELECT id FROM users WHERE id = ?", [$studentId]);
    if (!$stu) throw new RuntimeException('student_not_found');
    $prov = mam_parent_provision($parentEmail);
    $parentId = (int) $prov['user']['id'];
    $exists = mam_db_row('SELECT id FROM parent_links WHERE parent_id = ? AND student_id = ?', [$parentId, $studentId]);
    $created = false;
    if (!$exists) {
        mam_db_insert('parent_links', ['parent_id' => $parentId, 'student_id' => $studentId, 'relationship' => $relationship ?: 'guardian', 'created_by' => $by]);
        $created = true;
        mam_audit('parent_link', 'user', $studentId, ['parent' => $parentId, 'by' => $by]);
    }
    return ['parent_id' => $parentId, 'student_id' => $studentId, 'password' => $prov['password'], 'created' => $created];
}

/** Remove a guardian↔student link. */
function mam_parent_unlink(int $parentId, int $studentId): void {
    mam_db()->prepare('DELETE FROM parent_links WHERE parent_id = ? AND student_id = ?')->execute([$parentId, $studentId]);
    mam_audit('parent_unlink', 'user', $studentId, ['parent' => $parentId]);
}

/** True if this parent is linked to this student. */
function mam_parent_can_view(int $parentId, int $studentId): bool {
    return (bool) mam_db_row('SELECT 1 FROM parent_links WHERE parent_id = ? AND student_id = ? LIMIT 1', [$parentId, $studentId]);
}

/** The students a parent is linked to, each with a compact weekly status. */
function mam_parent_children(int $parentId): array {
    $rows = mam_db_all(
        "SELECT u.id AS student_id, u.name AS student_name, u.enrollment_ref, pl.relationship
           FROM parent_links pl JOIN users u ON u.id = pl.student_id
          WHERE pl.parent_id = ? ORDER BY u.name",
        [$parentId]
    );
    foreach ($rows as &$r) $r['weekly'] = mam_parent_weekly_status((int) $r['student_id']);
    unset($r);
    return $rows;
}

/** A traffic-light label from a 0–100 value against simple bands. */
function mam_parent_light(float $pct): string {
    if ($pct >= 80) return 'green';
    if ($pct >= 50) return 'amber';
    return 'red';
}

/**
 * The §26 weekly status for a student: preparation, teach-back, attendance and
 * mastery traffic lights + headline numbers, drawn from the check-in / pre-class
 * / grading engines.
 */
function mam_parent_weekly_status(int $studentId): array {
    $attendance = function_exists('mam_checkin_attendance_rate') ? mam_checkin_attendance_rate($studentId) : 0.0;
    $streak     = function_exists('mam_checkin_streak') ? mam_checkin_streak($studentId) : 0;
    $gpa        = function_exists('mam_lms2_student_gpa') ? mam_lms2_student_gpa($studentId) : ['avg_score' => null];
    $mastery    = $gpa['avg_score'] !== null ? (float) $gpa['avg_score'] : 0.0;

    // Preparation + teach-back from the next class card, when there is one.
    $prep = null; $teachback = null;
    if (function_exists('mam_preclass_next')) {
        $next = mam_preclass_next($studentId);
        if ($next) {
            $prep = $next['prep'] ?? null;
            $teachback = $next['teachback'] ? ($next['teachback']['status'] ?? null) : null;
        }
    }
    return [
        'attendance_rate' => $attendance,
        'attendance'      => mam_parent_light($attendance),
        'streak'          => $streak,
        'mastery'         => $mastery,
        'mastery_light'   => mam_parent_light($mastery),
        'preparation'     => $prep,        // not_started / in_progress / completed
        'teachback'       => $teachback,   // null / submitted / reviewed
    ];
}

/**
 * Full child detail for the parent portal — the weekly status plus the shared
 * learner report (reused, PII kept since the guardian is authorised). Throws
 * 'forbidden' if the parent isn't linked to the student.
 */
function mam_parent_child_detail(int $parentId, int $studentId): array {
    if (!mam_parent_can_view($parentId, $studentId)) throw new RuntimeException('forbidden');
    $report = function_exists('mam_progress_report_data') ? mam_progress_report_data($studentId) : [];
    return [
        'student'   => $report['student'] ?? mam_db_row('SELECT id, name, enrollment_ref FROM users WHERE id = ?', [$studentId]),
        'weekly'    => mam_parent_weekly_status($studentId),
        'academic'  => $report['academic'] ?? null,
        'attendance'=> $report['attendance'] ?? null,
        'results'   => $report['results'] ?? [],
        'sessions'  => array_slice($report['sessions'] ?? [], 0, 10),
        'upcoming_sessions' => array_slice($report['upcoming_sessions'] ?? [], 0, 5),
        'reschedule_history'=> $report['reschedule_history'] ?? [],
        'announcements'     => array_slice($report['announcements'] ?? [], 0, 5),
        // §26 — instructor feedback on the child's work, so guardians see the
        // qualitative remarks, not only grades.
        'instructor_feedback' => mam_parent_instructor_feedback($studentId),
        // §3.1/§3.5 — badges earned and the gentle "wins" stream.
        'achievements' => function_exists('mam_ach_evaluate') ? mam_ach_evaluate($studentId) : [],
        'wins'         => function_exists('mam_wins_feed') ? mam_wins_feed($studentId, 12) : [],
        // §8.4 — the guardian's current consent settings for this child.
        'consent'      => function_exists('mam_consent_get') ? mam_consent_get($studentId) : null,
    ];
}

/** Recent instructor feedback comments on a student's submissions (§26). */
function mam_parent_instructor_feedback(int $studentId, int $limit = 10): array {
    try {
        return mam_db_all(
            "SELECT f.outcome, f.comments,
                    COALESCE(f.updated_at, f.created_at) AS at,
                    a.title AS assignment_title, iu.name AS instructor_name
               FROM feedback f
               JOIN submissions s ON s.id = f.submission_id
          LEFT JOIN assignments a ON a.id = s.assignment_id
          LEFT JOIN users iu ON iu.id = f.instructor_id
              WHERE s.student_id = ? AND f.comments IS NOT NULL AND TRIM(f.comments) <> ''
              ORDER BY COALESCE(f.updated_at, f.created_at) DESC
              LIMIT " . max(1, $limit),
            [$studentId]
        );
    } catch (\Throwable $e) {
        return [];
    }
}
