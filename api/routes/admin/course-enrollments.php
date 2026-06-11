<?php
/**
 * /api/admin/course-enrollments
 *   GET  — list enrolments (pending first) + the assignable instructor list.
 *   POST — approve a pending enrolment, optionally assigning an instructor.
 *          Body: { enrollment_id: int, instructor_id?: int }
 */
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $rows = mam_db_all(
        "SELECT ce.id, ce.status, ce.created_at, ce.requested_instructor_id,
                c.name AS course_name, c.kind AS course_kind,
                l.name AS level_name, p.name AS program_name,
                su.name AS student_name, su.email AS student_email,
                ins.name AS instructor_name
           FROM course_enrollments ce
           JOIN courses c       ON c.id = ce.course_id
           LEFT JOIN levels l   ON l.id = ce.level_id
           LEFT JOIN programs p ON p.id = ce.program_id
           JOIN users su        ON su.id = ce.student_id
           LEFT JOIN users ins  ON ins.id = ce.instructor_id
          ORDER BY (ce.status = 'pending') DESC, ce.created_at DESC
          LIMIT 500"
    );
    mam_ok(['enrollments' => $rows, 'instructors' => mam_lms_instructors()]);
}

$admin = mam_current_user();
$in    = mam_json_input();
$id    = (int)($in['enrollment_id'] ?? 0);
$instr = isset($in['instructor_id']) ? (int)$in['instructor_id'] : 0;
if ($id <= 0) mam_fail('validation', 422, ['fields' => ['enrollment_id']]);

$row = mam_db_row('SELECT id FROM course_enrollments WHERE id = ?', [$id]);
if (!$row) mam_fail('enrollment_not_found', 404);

mam_lms_approve($id, $instr ?: null, (int)($admin['id'] ?? 0));
mam_ok(['approved' => true]);
