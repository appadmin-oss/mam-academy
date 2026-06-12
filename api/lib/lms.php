<?php
/**
 * LMS — flexible course registration on top of the v16 academic model
 * (programs · levels · courses · course_enrollments · instructor_assignments).
 *
 * A student registers once, then adds courses over time from the dashboard.
 * Each add creates a course_enrollment that is either auto-activated or left
 * pending for an admin to approve + assign an instructor (admin policy via the
 * `lms_auto_approve` setting). Multi-instructor and multi-role are inherent:
 * enrollments reference courses, and instructor assignment is per-enrollment.
 */
declare(strict_types=1);

/** Public academic catalogue: active programmes, levels and courses (core first). */
function mam_lms_catalog(): array {
    return [
        'programs' => mam_db_all(
            'SELECT code, name, weeks, revision_week, exam_week, requires_core, certification
               FROM programs WHERE active = 1 ORDER BY sort_order'
        ),
        'levels' => mam_db_all('SELECT code, name, trinity_grades FROM levels ORDER BY sort_order'),
        'courses' => mam_db_all(
            "SELECT code, name, kind, description FROM courses WHERE active = 1
              ORDER BY CASE WHEN kind = 'core' THEN 0 ELSE 1 END, sort_order"
        ),
    ];
}

/** Whether new course enrolments skip the pending queue (admin setting). */
function mam_lms_auto_approve(): bool {
    return (int)mam_setting_num('lms_auto_approve', 0) === 1;
}

/** A student's course enrolments, with course/level/programme/instructor names
 *  and any finalized result. Newest first. */
function mam_lms_student_courses(int $studentId): array {
    return mam_db_all(
        "SELECT ce.id, ce.status, ce.created_at, ce.approved_at,
                c.code AS course_code, c.name AS course_name, c.kind AS course_kind,
                l.code AS level_code, l.name AS level_name,
                p.code AS program_code, p.name AS program_name,
                ins.name AS instructor_name,
                r.total_score, r.grade, r.outcome
           FROM course_enrollments ce
           JOIN courses c        ON c.id = ce.course_id
           LEFT JOIN levels l    ON l.id = ce.level_id
           LEFT JOIN programs p  ON p.id = ce.program_id
           LEFT JOIN users ins   ON ins.id = ce.instructor_id
           LEFT JOIN results r   ON r.enrollment_id = ce.id
          WHERE ce.student_id = ?
          ORDER BY ce.created_at DESC",
        [$studentId]
    );
}

/**
 * Add a course for a student. Resolves codes → ids, blocks duplicate
 * active/pending enrolments in the same course+level, and sets the status per
 * the auto-approve policy. Throws InvalidArgumentException('course_not_found')
 * or RuntimeException('already_enrolled'). Returns ['id','status'].
 */
function mam_lms_add_course(int $studentId, string $courseCode, ?string $levelCode = null, ?string $programCode = null, ?int $requestedInstructorId = null): array {
    $course = mam_db_row('SELECT id FROM courses WHERE code = ? AND active = 1', [$courseCode]);
    if (!$course) throw new InvalidArgumentException('course_not_found');

    $levelId = null;
    if ($levelCode) { $r = mam_db_row('SELECT id FROM levels WHERE code = ?', [$levelCode]); if ($r) $levelId = (int)$r['id']; }
    $programId = null;
    if ($programCode) { $r = mam_db_row('SELECT id FROM programs WHERE code = ?', [$programCode]); if ($r) $programId = (int)$r['id']; }

    // Block a duplicate active/pending enrolment in the same course+level.
    // NOTE: compare level_id as a bare column (not wrapped in IFNULL) so the
    // column's INTEGER affinity coerces the bound parameter — PDO binds array
    // params as TEXT under EMULATE_PREPARES=false, and a function-wrapped
    // column loses affinity, making `IFNULL(level_id,0) = '1'` never match.
    if ($levelId === null) {
        $dup = mam_db_row(
            "SELECT id FROM course_enrollments
              WHERE student_id = ? AND course_id = ? AND level_id IS NULL AND status IN ('pending','active')",
            [$studentId, (int)$course['id']]
        );
    } else {
        $dup = mam_db_row(
            "SELECT id FROM course_enrollments
              WHERE student_id = ? AND course_id = ? AND level_id = ? AND status IN ('pending','active')",
            [$studentId, (int)$course['id'], $levelId]
        );
    }
    if ($dup) throw new RuntimeException('already_enrolled');

    $auto = mam_lms_auto_approve();
    $id = mam_db_insert('course_enrollments', [
        'student_id'              => $studentId,
        'course_id'               => (int)$course['id'],
        'level_id'                => $levelId,
        'program_id'              => $programId,
        'requested_instructor_id' => $requestedInstructorId,
        'status'                  => $auto ? 'active' : 'pending',
        'approved_at'             => $auto ? date('Y-m-d H:i:s') : null,
    ]);
    mam_audit('lms_add_course', 'course_enrollment', $id, ['student' => $studentId, 'course' => $courseCode, 'auto' => $auto]);
    return ['id' => $id, 'status' => $auto ? 'active' : 'pending'];
}

/** Admin: approve a pending enrolment and (optionally) assign an instructor. */
function mam_lms_approve(int $enrollmentId, ?int $instructorId, int $adminId): void {
    $data = ['status' => 'active', 'approved_by' => $adminId, 'approved_at' => date('Y-m-d H:i:s')];
    if ($instructorId) $data['instructor_id'] = $instructorId;
    mam_db_update('course_enrollments', $data, ['id' => $enrollmentId]);
    mam_audit('lms_approve', 'course_enrollment', $enrollmentId, ['instructor' => $instructorId]);
}

/** Everyone who can teach (primary role OR user_roles). For the assign dropdown. */
function mam_lms_instructors(): array {
    return mam_db_all(
        "SELECT id, name, email FROM users
          WHERE status = 'active' AND (role = 'instructor' OR role = 'admin'
                OR id IN (SELECT user_id FROM user_roles WHERE role = 'instructor'))
          ORDER BY name"
    );
}

/* ───────────────────────── Assessments & results ─────────────────────────
   Each course enrolment is graded across the weighted assessment_types
   (attendance/assignment/practical/continuous/final). The result is a
   weighted average of the per-type score-averages, normalised over whatever
   has been graded so far — so a partial gradebook shows a fair "current
   standing" and a complete one shows the final weighted mark. */

/** Pass mark (percent) — admin-editable. */
function mam_lms_pass_mark(): float { return mam_setting_num('lms_pass_mark', 50); }

/** Map a 0–100 percentage to a [letter, band]. */
function mam_lms_grade(float $pct): array {
    if ($pct >= 80) return ['A', 'distinction'];
    if ($pct >= 70) return ['B', 'merit'];
    if ($pct >= 60) return ['C', 'credit'];
    if ($pct >= 50) return ['D', 'pass'];
    return ['F', 'fail'];
}

/**
 * Recompute and persist an enrolment's result from its assessments.
 * Returns ['total','grade','outcome','band','graded_weight']; total is null
 * when nothing has been graded yet.
 */
function mam_lms_compute_result(int $enrollmentId, ?int $by = null): array {
    $rows = mam_db_all(
        "SELECT t.weight AS weight, AVG(a.score * 100.0 / a.max_score) AS avg_pct
           FROM assessments a JOIN assessment_types t ON t.id = a.type_id
          WHERE a.enrollment_id = ? GROUP BY t.id, t.weight",
        [$enrollmentId]
    );
    $wsum = 0.0; $acc = 0.0;
    foreach ($rows as $r) { $w = (float)$r['weight']; $wsum += $w; $acc += $w * (float)$r['avg_pct']; }
    if ($wsum <= 0) return ['total' => null, 'grade' => null, 'outcome' => null, 'band' => null, 'graded_weight' => 0];

    $total = round($acc / $wsum, 1);
    [$grade, $band] = mam_lms_grade($total);
    $outcome = $total >= mam_lms_pass_mark() ? 'pass' : 'fail';
    $now = date('Y-m-d H:i:s');
    mam_db_upsert(
        'results',
        ['enrollment_id' => $enrollmentId, 'total_score' => $total, 'grade' => $grade, 'outcome' => $outcome, 'finalized_by' => $by, 'finalized_at' => $now],
        ['enrollment_id'],
        ['total_score' => $total, 'grade' => $grade, 'outcome' => $outcome, 'finalized_by' => $by, 'finalized_at' => $now]
    );
    return ['total' => $total, 'grade' => $grade, 'outcome' => $outcome, 'band' => $band, 'graded_weight' => (int)round($wsum)];
}

/** Record one assessment score for an enrolment, then recompute its result.
 *  Throws InvalidArgumentException('type_not_found') on an unknown type code. */
function mam_lms_record_assessment(int $enrollmentId, string $typeCode, ?int $instructorId, float $score, float $maxScore = 100.0, ?int $week = null, ?string $notes = null): array {
    $type = mam_db_row('SELECT id FROM assessment_types WHERE code = ? AND active = 1', [$typeCode]);
    if (!$type) throw new InvalidArgumentException('type_not_found');
    if ($maxScore <= 0) $maxScore = 100.0;
    $score = max(0.0, min($score, $maxScore));
    mam_db_insert('assessments', [
        'enrollment_id' => $enrollmentId,
        'type_id'       => (int)$type['id'],
        'instructor_id' => $instructorId,
        'week'          => $week,
        'score'         => $score,
        'max_score'     => $maxScore,
        'notes'         => $notes,
    ]);
    mam_audit('lms_assess', 'course_enrollment', $enrollmentId, ['type' => $typeCode, 'score' => $score]);
    return mam_lms_compute_result($enrollmentId, $instructorId);
}

/** An instructor's active course enrolments with student/course + current result. */
function mam_lms_instructor_gradebook(int $instructorId): array {
    return mam_db_all(
        "SELECT ce.id, ce.status,
                su.name AS student_name, su.email AS student_email,
                c.name AS course_name, c.kind AS course_kind, l.name AS level_name,
                r.total_score, r.grade, r.outcome
           FROM course_enrollments ce
           JOIN users su       ON su.id = ce.student_id
           JOIN courses c      ON c.id = ce.course_id
           LEFT JOIN levels l  ON l.id = ce.level_id
           LEFT JOIN results r ON r.enrollment_id = ce.id
          WHERE ce.instructor_id = ? AND ce.status = 'active'
          ORDER BY su.name, c.name",
        [$instructorId]
    );
}

/** Every assessment recorded against an enrolment (newest first). */
function mam_lms_enrollment_assessments(int $enrollmentId): array {
    return mam_db_all(
        "SELECT a.id, a.score, a.max_score, a.week, a.notes, a.created_at,
                t.code AS type_code, t.name AS type_name, t.weight
           FROM assessments a LEFT JOIN assessment_types t ON t.id = a.type_id
          WHERE a.enrollment_id = ? ORDER BY a.created_at DESC",
        [$enrollmentId]
    );
}
