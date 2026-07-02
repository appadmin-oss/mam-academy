<?php
/**
 * POST /api/instructor/mark-attendance
 *
 * Single-enrolment body:
 *   { enrollment_id, session_date, status, notes? }
 *
 * Bulk body — mark every student in a class with one POST. Optional `overrides`
 * lets the instructor tag specific students with a different status (e.g. mark
 * the whole class 'present' and override 3 students as 'absent'):
 *   { class_id, session_date, default_status, overrides?: { <enrollment_id>: <status>, ... }, notes? }
 *
 * Either shape returns the count of attendance rows inserted/updated, and is
 * auth'd against the caller's instructor assignment (admins can mark any).
 * Submitting the same enrolment+date twice UPDATES the existing row instead of
 * inserting a duplicate, so re-submitting fixes typos rather than producing
 * shadow rows.
 */
declare(strict_types=1);

$u = mam_require_role('instructor', 'admin');
// Honour secondary-role admins (admin via user_roles with a non-admin primary
// role), consistent with the rest of the codebase.
$isAdmin = function_exists('mam_user_has_role') ? mam_user_has_role($u, 'admin') : ($u['role'] === 'admin');

$in = mam_json_input();
$date = mam_str($in, 'session_date', 20) ?: date('Y-m-d');
$notes = mam_str($in, 'notes', 500) ?: null;
$VALID = ['present', 'absent', 'late', 'excused'];

// ── Bulk path ───────────────────────────────────────────────────────
if (isset($in['class_id'])) {
    $classId = (int)$in['class_id'];
    $default = mam_str($in, 'default_status', 16);
    if (!in_array($default, $VALID, true)) mam_fail('validation', 422, ['fields' => ['default_status']]);

    $cls = mam_db_row('SELECT id, instructor_id FROM classes WHERE id = ?', [$classId]);
    if (!$cls) mam_fail('class_not_found', 404);
    if (!$isAdmin && (int)$cls['instructor_id'] !== (int)$u['id']) mam_fail('forbidden', 403);

    $rows = mam_db_all("SELECT id FROM enrollments WHERE class_id = ? AND status = 'active'", [$classId]);
    $overrides = is_array($in['overrides'] ?? null) ? $in['overrides'] : [];

    $count = 0;
    foreach ($rows as $r) {
        $eid = (int)$r['id'];
        $st  = isset($overrides[$eid]) && in_array($overrides[$eid], $VALID, true) ? $overrides[$eid] : $default;
        $existing = mam_db_row('SELECT id FROM attendance WHERE enrollment_id = ? AND session_date = ?', [$eid, $date]);
        if ($existing) {
            mam_db_update('attendance', ['status' => $st, 'notes' => $notes, 'marked_by' => (int)$u['id']], ['id' => (int)$existing['id']]);
        } else {
            mam_db_insert('attendance', [
                'enrollment_id' => $eid,
                'session_date'  => $date,
                'status'        => $st,
                'notes'         => $notes,
                'marked_by'     => (int)$u['id'],
            ]);
        }
        $count++;
    }
    mam_audit('mark_bulk', 'attendance', $classId, ['count' => $count, 'date' => $date, 'default' => $default, 'overrides' => array_keys($overrides)]);
    mam_ok(['count' => $count, 'class_id' => $classId, 'session_date' => $date]);
}

// ── Single-enrolment path ───────────────────────────────────────────
$enrollmentId = (int)($in['enrollment_id'] ?? 0);
$status = mam_str($in, 'status', 16);

if (!in_array($status, $VALID, true)) mam_fail('validation', 422, ['fields' => ['status']]);

// Admins can mark any enrolment; instructors only their own classes. (The old
// `? = "admin"` SQL literal broke under MySQL ANSI_QUOTES — do the check in PHP.)
$check = $isAdmin
    ? mam_db_row('SELECT e.id FROM enrollments e WHERE e.id = ?', [$enrollmentId])
    : mam_db_row('SELECT e.id FROM enrollments e JOIN classes c ON c.id = e.class_id WHERE e.id = ? AND c.instructor_id = ?', [$enrollmentId, (int)$u['id']]);
if (!$check) mam_fail('forbidden', 403);

$existing = mam_db_row('SELECT id FROM attendance WHERE enrollment_id = ? AND session_date = ?', [$enrollmentId, $date]);
if ($existing) {
    mam_db_update('attendance', ['status' => $status, 'notes' => $notes, 'marked_by' => (int)$u['id']], ['id' => (int)$existing['id']]);
    $id = (int)$existing['id'];
    mam_audit('update', 'attendance', $id, ['enrollment_id' => $enrollmentId, 'status' => $status]);
} else {
    $id = mam_db_insert('attendance', [
        'enrollment_id' => $enrollmentId,
        'session_date'  => $date,
        'status'        => $status,
        'notes'         => $notes,
        'marked_by'     => (int)$u['id'],
    ]);
    mam_audit('mark', 'attendance', $id, ['enrollment_id' => $enrollmentId, 'status' => $status]);
}
mam_ok(['attendance_id' => $id]);
